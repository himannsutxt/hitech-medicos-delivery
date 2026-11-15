<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HMD\{Db, RiderApi, Delivery, Cod, Fmt, Settings, Summary};

require_once __DIR__ . '/../bootstrap_db.php';

final class RiderDayFlowTest extends TestCase
{
    private static int $riderId;
    private static array $ids = [];
    private string $device = 'device-AAA-111';

    public static function setUpBeforeClass(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.' . random_int(2, 250);
        hmd_test_reset();
        Db::run('UPDATE hm_warehouse SET lat=28.4595, lng=77.0266, radius_m=200');
        self::$riderId = Db::insert('hm_rider', ['employee_code' => 'DEL001', 'name' => 'Raj Kumar', 'mobile' => '9876543210', 'pin_hash' => password_hash('4321', PASSWORD_DEFAULT),
            'warehouse_id' => 1, 'capacity' => 30, 'cod_limit' => 50000, 'status' => 1, 'created_at' => Fmt::nowUtc()]);
        $base = ['address' => 'H.No 12, Sector 14, Gurgaon', 'items_count' => 1, 'priority' => 'normal'];
        self::$ids[] = Delivery::create($base + ['order_no' => 'HM1001', 'customer_name' => 'A', 'customer_mobile' => '9811111111', 'payment_type' => 'prepaid', 'order_value' => 499, 'cod_amount' => 0, 'cust_lat' => 28.4700, 'cust_lng' => 77.0300], 1);
        self::$ids[] = Delivery::create($base + ['order_no' => 'HM1002', 'customer_name' => 'B', 'customer_mobile' => '9811111112', 'payment_type' => 'cod', 'order_value' => 1250, 'cod_amount' => 1250, 'cust_lat' => 28.4800, 'cust_lng' => 77.0400], 1);
        self::$ids[] = Delivery::create($base + ['order_no' => 'HM1003', 'customer_name' => 'C', 'customer_mobile' => '9811111113', 'payment_type' => 'cod', 'order_value' => 800, 'cod_amount' => 800, 'cust_lat' => 28.4900, 'cust_lng' => 77.0500], 1);
        [$n, $err] = Delivery::assign(self::$ids, self::$riderId, 1);
        if ($n !== 3) {
            throw new RuntimeException('assign failed ' . implode(';', $err));
        }
    }

    private function api(string $method, string $path, array $body = [], ?string $tok = null, array $files = []): array
    {
        $body += ['device_id' => $this->device];
        return (new RiderApi($body, $files))->handle($method, $path, $tok);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function photo(string $tok, string $kind): int
    {
        $f = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(20, 20);
        imagejpeg($im, $f);
        $r = $this->api('POST', 'media', ['kind' => $kind, 'lat' => 28.47, 'lng' => 77.03], $tok, ['file' => ['tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f), 'name' => 'x.jpg']]);
        $this->assertTrue($r['ok'], json_encode($r));
        return (int)$r['media_id'];
    }

    public function testFullDay(): void
    {
        // Login
        $bad = $this->api('POST', 'auth/login', ['mobile' => '9876543210', 'pin' => '0000']);
        $this->assertSame('bad_login', $bad['error']);
        $login = $this->api('POST', 'auth/login', ['mobile' => '+91 98765 43210', 'pin' => '4321']);
        $this->assertTrue($login['ok'], json_encode($login));
        $tok = $login['access_token'];
        // Another phone cannot log in once the device is bound
        $other = (new RiderApi(['mobile' => '9876543210', 'pin' => '4321', 'device_id' => 'device-EVIL'], []))->handle('POST', 'auth/login', null);
        $this->assertSame('device_mismatch', $other['error']);
        // Token from this device does not work with a forged rider (no token)
        $this->assertSame('unauthorized', $this->api('GET', 'me', [], 'deadbeef')['error']);

        // Duty start inside geofence; repeat is idempotent
        $selfie = $this->photo($tok, 'selfie');
        $ds = $this->api('POST', 'duty/start', ['lat' => 28.4596, 'lng' => 77.0267, 'accuracy' => 8, 'battery' => 80, 'selfie_media_id' => $selfie, 'client_uuid' => $this->uuid()], $tok);
        $this->assertTrue($ds['ok']);
        $this->assertTrue($ds['in_geofence']);
        $this->assertTrue($this->api('POST', 'duty/start', ['lat' => 28.4596, 'lng' => 77.0267], $tok)['already']);

        // Route cannot start before scanning
        $this->assertSame('scan_pending', $this->api('POST', 'route/start', [], $tok)['error']);
        $this->assertSame('not_found', $this->api('POST', 'scan', ['code' => 'NOPE1'], $tok)['result']);
        foreach (['HM1001', 'HM1002', 'HM1003'] as $c) {
            $this->assertSame('loaded', $this->api('POST', 'scan', ['code' => $c], $tok)['result']);
        }
        $this->assertSame('already', $this->api('POST', 'scan', ['code' => 'HM1001'], $tok)['result']);
        $rs = $this->api('POST', 'route/start', ['lat' => 28.4596, 'lng' => 77.0267], $tok);
        $this->assertSame(3, $rs['out']);

        // GPS batch: duplicates ignored, bad points rejected
        $t0 = time() - 600;
        $pts = [];
        for ($i = 1; $i <= 20; $i++) {
            $pts[] = ['seq' => $i, 'lat' => 28.4595 + $i * 0.0005, 'lng' => 77.0266, 'accuracy' => 8, 'speed' => 8, 'battery' => 79, 'device_at' => gmdate('c', $t0 + $i * 30)];
        }
        $pts[] = $pts[0];                                   // duplicate
        $pts[] = ['seq' => 99, 'lat' => 'x', 'lng' => 1];    // garbage
        $loc = $this->api('POST', 'locations/batch', ['points' => $pts], $tok);
        $this->assertSame(20, $loc['stored']);
        $this->assertSame(1, $loc['rejected']);
        $again = $this->api('POST', 'locations/batch', ['points' => array_slice($pts, 0, 5)], $tok);
        $this->assertSame(0, $again['stored']);

        [$d1, $d2, $d3] = self::$ids;
        // Delivery 1 (prepaid): start, arrived, delivered with photo
        $this->assertSame('en_route', $this->api('POST', "deliveries/$d1/event", ['type' => 'start', 'lat' => 28.46, 'lng' => 77.027, 'client_uuid' => $this->uuid()], $tok)['delivery']['status']);
        $this->assertSame('arrived', $this->api('POST', "deliveries/$d1/event", ['type' => 'arrived', 'lat' => 28.4701, 'lng' => 77.0301, 'client_uuid' => $this->uuid()], $tok)['delivery']['status']);
        $noPhoto = $this->api('POST', "deliveries/$d1/event", ['type' => 'delivered', 'lat' => 28.4701, 'lng' => 77.0301], $tok);
        $this->assertSame('not_allowed', $noPhoto['error']);
        $pod = $this->photo($tok, 'pod');
        $ok1 = $this->api('POST', "deliveries/$d1/event", ['type' => 'delivered', 'lat' => 28.4701, 'lng' => 77.0301, 'photo_media_id' => $pod, 'client_uuid' => $this->uuid()], $tok);
        $this->assertSame('delivered', $ok1['delivery']['status']);

        // Delivery 2 (COD): wrong amount refused, then cash; replay returns duplicate and does not double-collect
        $this->api('POST', "deliveries/$d2/event", ['type' => 'start', 'lat' => 28.47, 'lng' => 77.03, 'client_uuid' => $this->uuid()], $tok);
        $pod2 = $this->photo($tok, 'pod');
        $wrong = $this->api('POST', "deliveries/$d2/event", ['type' => 'delivered', 'lat' => 28.4801, 'lng' => 77.0401, 'photo_media_id' => $pod2, 'payment_mode' => 'cash', 'amount' => 1200], $tok);
        $this->assertStringContainsString('1,250.00', $wrong['message']);
        $u = $this->uuid();
        $ok2 = $this->api('POST', "deliveries/$d2/event", ['type' => 'delivered', 'lat' => 28.4801, 'lng' => 77.0401, 'photo_media_id' => $pod2, 'payment_mode' => 'cash', 'amount' => 1250, 'client_uuid' => $u], $tok);
        $this->assertSame('delivered', $ok2['delivery']['status'], json_encode($ok2));   // en_route -> arrived implicitly -> delivered
        $dup = $this->api('POST', "deliveries/$d2/event", ['type' => 'delivered', 'lat' => 28.4801, 'lng' => 77.0401, 'photo_media_id' => $pod2, 'payment_mode' => 'cash', 'amount' => 1250, 'client_uuid' => $u], $tok);
        $this->assertTrue($dup['duplicate']);
        $this->assertSame(1, (int)Db::val("SELECT COUNT(*) FROM hm_cod_txn WHERE delivery_id=? AND type='collect'", [$d2]));
        // A delivered order cannot be delivered again
        $this->assertSame('not_allowed', $this->api('POST', "deliveries/$d2/event", ['type' => 'start', 'lat' => 28.47, 'lng' => 77.03], $tok)['error']);

        // Delivery 3 (COD): refused far from address -> RTO + flag
        $this->api('POST', "deliveries/$d3/event", ['type' => 'start', 'lat' => 28.47, 'lng' => 77.03, 'client_uuid' => $this->uuid()], $tok);
        $fp = $this->photo($tok, 'failed');
        $f = $this->api('POST', "deliveries/$d3/event", ['type' => 'failed', 'reason' => 'customer_refused', 'lat' => 28.4600, 'lng' => 77.0266, 'photo_media_id' => $fp, 'client_uuid' => $this->uuid()], $tok);
        $this->assertSame('rto', $f['delivery']['status'], json_encode($f));
        $this->assertSame(1, (int)Db::val("SELECT COUNT(*) FROM hm_exception WHERE rule_code='failed_away' AND delivery_id=?", [$d3]));

        // Cannot end duty while holding the RTO parcel, then COD
        $this->assertSame('parcels_pending', $this->api('POST', 'duty/end', [], $tok)['error']);
        $rs = Delivery::returnScan('HM1003', 1);
        $this->assertTrue($rs['ok']);
        $this->assertSame('returned', Delivery::get($d3)['status']);
        $this->assertSame('cod_pending', $this->api('POST', 'duty/end', [], $tok)['error']);
        $dep = $this->api('POST', 'cod/deposit', [], $tok);
        $this->assertSame(1250.0, $dep['expected']);
        [$okS, $msg] = Cod::settle((int)$dep['settlement_id'], 1000, '', 1);
        $this->assertFalse($okS);                                   // remark required for a difference
        [$okS, $msg] = Cod::settle((int)$dep['settlement_id'], 1000, 'Rider says 250 given as change', 1);
        $this->assertTrue($okS, $msg);
        $this->assertSame(250.0, Cod::shortPending(self::$riderId));
        $end = $this->api('POST', 'duty/end', ['lat' => 28.4595, 'lng' => 77.0266], $tok);
        $this->assertTrue($end['ok'], json_encode($end));
        $this->assertGreaterThan(1.0, $end['km']['km']);

        $sum = Summary::riderDay(self::$riderId, Fmt::todayIst());
        $this->assertSame(2, $sum['delivered']);
        $this->assertSame(1, $sum['refused']);
        $this->assertSame(3, $sum['assigned']);
        $this->assertSame(1250.0, $sum['cod_cash']);

        // Recover the shortage
        [$okR] = Cod::adjust(self::$riderId, 'recover', 250, 'Paid in cash next morning', 1);
        $this->assertTrue($okR);
        $this->assertSame(0.0, Cod::cashInHand(self::$riderId));

        // Next day: duty start is allowed (nothing unsettled)
        // Refresh token rotation: old refresh token cannot be reused
        $r1 = $this->api('POST', 'auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertTrue($r1['ok']);
        $r2 = $this->api('POST', 'auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertSame('unauthorized', $r2['error']);
        // ...and reuse revoked the whole family, including the new token
        $this->assertSame('unauthorized', $this->api('GET', 'me', [], $r1['access_token'])['error']);
    }

    public function testStateMachineGuardsAdminActions(): void
    {
        $id = Delivery::create(['order_no' => 'HM2001', 'customer_name' => 'D', 'customer_mobile' => '9811111119', 'address' => 'Sector 22 Gurgaon', 'payment_type' => 'prepaid', 'order_value' => 100, 'cod_amount' => 0, 'items_count' => 1, 'priority' => 'normal'], 1);
        $this->expectException(DomainException::class);
        Delivery::move(Delivery::get($id), 'delivered', 'admin', 1);
    }

    public function testRescheduleThenReturnScanBackToQueue(): void
    {
        $id = Delivery::create(['order_no' => 'HM3001', 'customer_name' => 'E', 'customer_mobile' => '9811111110', 'address' => 'Sector 23 Gurgaon', 'payment_type' => 'prepaid', 'order_value' => 100, 'cod_amount' => 0, 'items_count' => 1, 'priority' => 'normal'], 1);
        Delivery::assign([$id], self::$riderId, 1);
        foreach (['loaded', 'out', 'en_route', 'arrived', 'not_delivered'] as $s) {
            Delivery::move(Delivery::get($id), $s, 'admin', 1);
        }
        Delivery::move(Delivery::get($id), 'rescheduled', 'system', null, ['scheduled_for' => '2026-12-01']);
        $r = Delivery::returnScan('HM3001', 1);
        $this->assertTrue($r['ok']);
        $d = Delivery::get($id);
        $this->assertSame('ready', $d['status']);
        $this->assertNull($d['rider_id']);
        $this->assertSame('2026-12-01', $d['scheduled_for']);
    }
}
