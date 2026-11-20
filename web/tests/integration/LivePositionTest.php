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
}
