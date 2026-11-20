<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HMD\{Db, Fmt, Tracking};

require_once __DIR__ . '/../bootstrap_db.php';

/** v1.1: the rider's live position must ignore GPS glitches, inaccurate and fake fixes. */
final class LivePositionTest extends TestCase
{
    private static int $rid;
    private static array $duty;

    public static function setUpBeforeClass(): void
    {
        hmd_test_reset();
        self::$rid = Db::insert('hm_rider', ['employee_code' => 'DEL001', 'name' => 'Live Test', 'mobile' => '9876500001', 'pin_hash' => 'x', 'capacity' => 30, 'cod_limit' => 50000, 'status' => 1, 'created_at' => Fmt::nowUtc()]);
        $id = Db::insert('hm_duty', ['rider_id' => self::$rid, 'duty_date' => Fmt::todayIst(), 'start_at' => Fmt::nowUtc(), 'status' => 'active']);
        self::$duty = Db::one('SELECT * FROM hm_duty WHERE duty_id=?', [$id]);
    }

    private function rider(): array
    {
        return Db::one('SELECT * FROM hm_rider WHERE rider_id=?', [self::$rid]);
    }

    private function pts(int $from, int $n, float $lat0, int $t0): array
    {
        $o = [];
        for ($i = 0; $i < $n; $i++) {
            $o[] = ['seq' => $from + $i, 'lat' => $lat0 + $i * 0.0003, 'lng' => 77.03, 'accuracy' => 8, 'device_at' => gmdate('c', $t0 + $i * 30)];
        }
        return $o;
    }

    public function testGlitchAtEndOfBatchIsIgnored(): void
    {
        $t0 = time() - 900;
        $batch = $this->pts(1, 10, 28.45, $t0);
        $batch[] = ['seq' => 11, 'lat' => 28.90, 'lng' => 77.50, 'accuracy' => 10, 'device_at' => gmdate('c', $t0 + 330)]; // 60 km jump in 30 s
        Tracking::ingest($this->rider(), self::$duty, $batch);
        $r = $this->rider();
        $this->assertEqualsWithDelta(28.4527, (float)$r['last_lat'], 0.0001, 'live position must stay on the last good fix');
    }

    public function testInaccurateAndMockFixesDoNotMoveRider(): void
    {
        $before = $this->rider();
        $t = time() - 200;
        Tracking::ingest($before, self::$duty, [
            ['seq' => 20, 'lat' => 28.4600, 'lng' => 77.03, 'accuracy' => 400, 'device_at' => gmdate('c', $t)],
            ['seq' => 21, 'lat' => 28.4601, 'lng' => 77.03, 'accuracy' => 5, 'is_mock' => true, 'device_at' => gmdate('c', $t + 30)],
        ]);
        $this->assertSame($before['last_lat'], $this->rider()['last_lat']);
    }

    public function testRealMovementUpdatesPosition(): void
    {
        $t0 = time() - 120;
        Tracking::ingest($this->rider(), self::$duty, $this->pts(30, 3, 28.4530, $t0));
        $this->assertEqualsWithDelta(28.4536, (float)$this->rider()['last_lat'], 0.0001);
    }

    public function testIndoorAccuracyStillMovesRider(): void
    {
        // Phones indoors often report 60-200 m. Live position must still update (KM stays strict).
        $t = time() - 20; // newer than the previous test's last fix
        Tracking::ingest($this->rider(), self::$duty, [['seq' => 40, 'lat' => 28.4545, 'lng' => 77.03, 'accuracy' => 140, 'device_at' => gmdate('c', $t)]]);
        $r = $this->rider();
        $this->assertEqualsWithDelta(28.4545, (float)$r['last_lat'], 0.0001);
        $this->assertEqualsWithDelta(140.0, (float)$r['last_accuracy'], 0.01);
        $this->assertNotNull($r['last_point_at']);
    }

    public function testMovementBetweenSyncBatchesIsAccepted(): void
    {
        // v1.1 bug: anchor used server time, so 400 m between two batches looked like a jump and was dropped.
        $r0 = $this->rider();
        $t = time() - 5;
        Tracking::ingest($r0, self::$duty, [['seq' => 50, 'lat' => (float)$r0['last_lat'] + 0.0036, 'lng' => 77.03, 'accuracy' => 12, 'device_at' => gmdate('c', $t)]]);
        $this->assertEqualsWithDelta((float)$r0['last_lat'] + 0.0036, (float)$this->rider()['last_lat'], 0.0001);
    }

    public function testHeartbeatStoresPhoneHealth(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.9.9.9';
        Db::update('hm_rider', ['device_id' => 'dev-hb', 'pin_hash' => password_hash('6482', PASSWORD_DEFAULT)], 'rider_id=?', [self::$rid]);
        $login = (new \HMD\RiderApi(['mobile' => '9876500001', 'pin' => '6482', 'device_id' => 'dev-hb'], []))->handle('POST', 'auth/login', null);
        $res = (new \HMD\RiderApi(['device_id' => 'dev-hb', 'gps_on' => true, 'permission' => 'whileInUse', 'background' => false, 'battery' => 55,
            'battery_exempt' => false, 'app_version' => '1.2.0', 'pending' => 4, 'gps_queue' => 120], []))->handle('POST', 'heartbeat', $login['access_token']);
        $this->assertTrue($res['ok']);
        $r = $this->rider();
        $this->assertSame('whileInUse', $r['loc_permission']);
        $this->assertSame(0, (int)$r['battery_exempt']);
        $this->assertSame('1.2.0', $r['app_version']);
        $this->assertSame(4, (int)$r['phone_pending']);
        $this->assertSame(120, (int)$r['phone_gps_queue']);
        $this->assertNotNull($r['last_heartbeat_at']);
        // On duty with "while in use" only: tracking will stop when the screen locks -> exception for the manager
        $this->assertSame(1, (int)Db::val("SELECT COUNT(*) FROM hm_exception WHERE rule_code='location_permission' AND rider_id=?", [self::$rid]));
    }

    public function testHeartbeatRejectsJunkValues(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.9.9.8';
        Db::update('hm_rider', ['device_id' => 'dev-hb2', 'pin_hash' => password_hash('6482', PASSWORD_DEFAULT), 'app_version' => '1.2.0', 'loc_permission' => 'always'], 'rider_id=?', [self::$rid]);
        $login = (new \HMD\RiderApi(['mobile' => '9876500001', 'pin' => '6482', 'device_id' => 'dev-hb2'], []))->handle('POST', 'auth/login', null);
        (new \HMD\RiderApi(['device_id' => 'dev-hb2', 'permission' => '<script>x</script>', 'app_version' => "1.2.0'<b>", 'pending' => -50, 'gps_queue' => 999999999999, 'battery' => 400], []))->handle('POST', 'heartbeat', $login['access_token']);
        $r = $this->rider();
        $this->assertSame('always', $r['loc_permission'], 'unknown permission value is ignored');
        $this->assertSame('1.2.0', $r['app_version'], 'malformed version is ignored');
        $this->assertSame(0, (int)$r['phone_pending']);
        $this->assertSame(10000000, (int)$r['phone_gps_queue']);
        $this->assertSame(100, (int)$r['last_battery']);
    }
}
