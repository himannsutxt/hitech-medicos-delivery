<?php
// Seeds demo data into the installed DB by driving the real API (CLI only).
declare(strict_types=1);
define('HMD_TESTING', true);
require __DIR__ . '/../app/lib/autoload.php';
use HMD\{Db, Delivery, RiderApi, Fmt, Cod, Settings};
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.9';
Db::run("UPDATE hm_warehouse SET name='Hitech Medicos, Sector 14', address='SCO 12, Sector 14 Market, Gurugram', lat=28.4720, lng=77.0430, radius_m=200");
$riders = [['DEL001', 'Raj Kumar', '9876543210'], ['DEL002', 'Amit Singh', '9876543211'], ['DEL003', 'Sonu Yadav', '9876543212']];
$rid = [];
foreach ($riders as [$c, $n, $m]) {
    $rid[] = Db::insert('hm_rider', ['employee_code' => $c, 'name' => $n, 'mobile' => $m, 'pin_hash' => password_hash('2580', PASSWORD_DEFAULT), 'warehouse_id' => 1, 'vehicle_type' => 'Bike', 'vehicle_number' => 'HR26AB' . rand(1000, 9999),
        'licence_number' => 'HR26' . rand(10000000, 99999999), 'licence_expiry' => date('Y-m-d', strtotime($c === 'DEL003' ? '+12 days' : '+2 years')), 'capacity' => 25, 'cod_limit' => 20000, 'status' => 1, 'joined_on' => '2026-06-01', 'created_at' => Fmt::nowUtc()]);
}
$names = ['Ravi Sharma', 'Asha Verma', 'Neha Gupta', 'Vikas Malik', 'Pooja Arora', 'Sanjay Jain', 'Meena Rao', 'Karan Mehta', 'Deepak Kumar', 'Ritu Singh', 'Anil Kapoor', 'Sunita Devi'];
$sectors = [[14, 28.4705, 77.0465], [15, 28.4590, 77.0400], [17, 28.4650, 77.0560], [22, 28.5060, 77.0650], [23, 28.5110, 77.0560], [31, 28.4515, 77.0560], [40, 28.4480, 77.0560], [45, 28.4420, 77.0700], [46, 28.4380, 77.0610], [56, 28.4210, 77.1020], [57, 28.4180, 77.0790], [9, 28.4630, 77.0170]];
$ids = [];
foreach ($names as $i => $n) {
    [$sec, $la, $lo] = $sectors[$i];
    $cod = $i % 3 !== 0;
    $val = [349, 1250, 799, 2150, 560, 1890, 420, 3100, 999, 640, 1480, 275][$i];
    $ids[] = Delivery::create(['order_no' => 'HM' . (24101 + $i), 'customer_name' => $n, 'customer_mobile' => '98' . str_pad((string)(11111110 + $i), 8, '0'), 'address' => 'House ' . (10 + $i) . ', Sector ' . $sec . ', Gurugram',
        'landmark' => $i % 2 ? 'Near park' : '', 'pincode' => '1220' . str_pad((string)($i % 9 + 1), 2, '0', STR_PAD_LEFT), 'cust_lat' => $la, 'cust_lng' => $lo, 'items_count' => 1 + $i % 3, 'order_value' => $val,
        'payment_type' => $cod ? 'cod' : 'prepaid', 'cod_amount' => $cod ? $val : 0, 'priority' => $i === 2 ? 'urgent' : ($i === 5 ? 'priority' : 'normal'), 'is_cold_chain' => $i === 4 ? 1 : 0,
        'notes' => $i === 0 ? 'Call before coming' : null, 'sla_due_at' => gmdate('Y-m-d H:i:s', time() + 3600 * (2 + $i % 4))], 1);
}
for ($i = 0; $i < 6; $i++) { // unassigned ready orders
    Delivery::create(['order_no' => 'HM' . (24201 + $i), 'customer_name' => 'Customer ' . ($i + 1), 'customer_mobile' => '97' . str_pad((string)(22222220 + $i), 8, '0'), 'address' => 'Flat ' . (100 + $i) . ', DLF Phase ' . (1 + $i % 4) . ', Gurugram',
        'pincode' => '122002', 'items_count' => 1, 'order_value' => 500 + $i * 120, 'payment_type' => $i % 2 ? 'cod' : 'prepaid', 'cod_amount' => $i % 2 ? 500 + $i * 120 : 0, 'priority' => 'normal'], 1);
}
Delivery::assign(array_slice($ids, 0, 5), $rid[0], 1);
Delivery::assign(array_slice($ids, 5, 4), $rid[1], 1);
Delivery::assign(array_slice($ids, 9, 3), $rid[2], 1);
function api($b, $m, $p, $tok = null, $files = []) { return (new RiderApi($b, $files))->handle($m, $p, $tok); }
function uuid() { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
function photo($tok, $dev, $kind, $lat, $lng) {
    $f = tempnam(sys_get_temp_dir(), 'img'); $im = imagecreatetruecolor(320, 240); imagefill($im, 0, 0, imagecolorallocate($im, 230, 236, 245));
    imagefilledrectangle($im, 90, 60, 230, 180, imagecolorallocate($im, 196, 160, 110)); imagestring($im, 5, 100, 110, 'PARCEL', imagecolorallocate($im, 60, 40, 20)); imagejpeg($im, $f, 80);
    $r = api(['kind' => $kind, 'lat' => $lat, 'lng' => $lng, 'device_id' => $dev], 'POST', 'media', $tok, ['file' => ['tmp_name' => $f, 'error' => 0, 'size' => filesize($f)]]);
    return $r['media_id'];
}
$wh = [28.4720, 77.0430];
foreach ([0, 1, 2] as $k) {
    $dev = 'demo-device-' . $k;
    $mob = $riders[$k][2];
    $login = api(['mobile' => $mob, 'pin' => '2580', 'device_id' => $dev], 'POST', 'auth/login');
    $tok = $login['access_token'];
    $sel = photo($tok, $dev, 'selfie', $wh[0], $wh[1]);
    api(['lat' => $wh[0] + 0.0003 * ($k === 2 ? 30 : 1), 'lng' => $wh[1], 'accuracy' => 8, 'battery' => 92 - $k * 7, 'selfie_media_id' => $sel, 'device_id' => $dev, 'client_uuid' => uuid()], 'POST', 'duty/start', $tok);
    Db::run("UPDATE hm_duty SET start_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE rider_id=? AND status='active'", [200 - $k * 20, $rid[$k]]);
    $mine = Db::all("SELECT * FROM hm_delivery WHERE rider_id=? ORDER BY sequence", [$rid[$k]]);
    foreach ($mine as $d) { api(['code' => $d['order_no'], 'device_id' => $dev], 'POST', 'scan', $tok); }
    api(['lat' => $wh[0], 'lng' => $wh[1], 'device_id' => $dev], 'POST', 'route/start', $tok);
    // GPS path through each stop
    $seq = 0; $t = time() - (180 - $k * 20) * 60; $pts = []; $cur = $wh;
    $stops = array_map(fn($d) => [(float)$d['cust_lat'], (float)$d['cust_lng']], $mine);
    $todo = $k === 0 ? 4 : ($k === 1 ? 3 : 1);
    foreach (array_slice($stops, 0, $todo + 1) as $s) {
        for ($j = 1; $j <= 12; $j++) {
            $lat = $cur[0] + ($s[0] - $cur[0]) * $j / 12 + (mt_rand(-5, 5) / 100000); $lng = $cur[1] + ($s[1] - $cur[1]) * $j / 12 + (mt_rand(-5, 5) / 100000);
            $t += 30; $pts[] = ['seq' => ++$seq, 'lat' => $lat, 'lng' => $lng, 'accuracy' => mt_rand(5, 15), 'speed' => 7.5, 'battery' => 90 - $seq % 20, 'device_at' => gmdate('c', $t)];
        }
        $cur = $s;
    }
    if ($k === 1) { $pts[] = ['seq' => ++$seq, 'lat' => 28.9, 'lng' => 77.5, 'accuracy' => 10, 'device_at' => gmdate('c', $t + 10)]; } // glitch
    api(['points' => $pts, 'device_id' => $dev], 'POST', 'locations/batch', $tok);
    foreach (array_slice($mine, 0, $todo) as $n => $d) {
        api(['type' => 'start', 'lat' => $cur[0], 'lng' => $cur[1], 'device_id' => $dev, 'client_uuid' => uuid()], 'POST', "deliveries/{$d['delivery_id']}/event", $tok);
        Db::run("UPDATE hm_attempt SET started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE delivery_id=?", [150 - $n * 30, $d['delivery_id']]);
        api(['type' => 'arrived', 'lat' => (float)$d['cust_lat'] + 0.0002, 'lng' => (float)$d['cust_lng'], 'device_id' => $dev, 'client_uuid' => uuid()], 'POST', "deliveries/{$d['delivery_id']}/event", $tok);
        Db::run("UPDATE hm_attempt SET arrived_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE delivery_id=?", [132 - $n * 30, $d['delivery_id']]);
        $fail = ($k === 0 && $n === 3) || ($k === 1 && $n === 2);
        if ($fail) {
            $ph = photo($tok, $dev, 'failed', (float)$d['cust_lat'], (float)$d['cust_lng']);
            api(['type' => 'call', 'device_id' => $dev, 'client_uuid' => uuid(), 'duration_s' => 0], 'POST', "deliveries/{$d['delivery_id']}/call", $tok);
            $res = api(['type' => 'failed', 'reason' => $k === 0 ? 'customer_unavailable' : 'customer_refused', 'lat' => (float)$d['cust_lat'] + ($k === 1 ? 0.01 : 0.0002), 'lng' => (float)$d['cust_lng'], 'photo_media_id' => $ph, 'device_id' => $dev, 'client_uuid' => uuid()], 'POST', "deliveries/{$d['delivery_id']}/event", $tok);
        } else {
            $ph = photo($tok, $dev, 'pod', (float)$d['cust_lat'], (float)$d['cust_lng']);
            $b = ['type' => 'delivered', 'lat' => (float)$d['cust_lat'] + 0.0002, 'lng' => (float)$d['cust_lng'], 'photo_media_id' => $ph, 'received_by' => 'Self', 'device_id' => $dev, 'client_uuid' => uuid()];
            if ($d['payment_type'] === 'cod') { $b += ['payment_mode' => $n % 2 ? 'upi' : 'cash', 'amount' => (float)$d['cod_amount'], 'upi_ref' => 'UTR' . rand(100000000, 999999999)]; }
            $res = api($b, 'POST', "deliveries/{$d['delivery_id']}/event", $tok);
        }
        if (empty($res['ok'])) { echo "Event failed: " . json_encode($res) . "\n"; }
        Db::run("UPDATE hm_attempt SET closed_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE delivery_id=?", [124 - $n * 30, $d['delivery_id']]);
    }
    if ($k === 2) { Db::run("UPDATE hm_rider SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 9 MINUTE) WHERE rider_id=?", [$rid[$k]]); }
}
// Rider 2 deposits cash (short by 100)
$tok = api(['mobile' => $riders[1][2], 'pin' => '2580', 'device_id' => 'demo-device-1'], 'POST', 'auth/login')['access_token'];
$dep = api(['device_id' => 'demo-device-1'], 'POST', 'cod/deposit', $tok);
\HMD\Jobs::runAll();
echo "Seeded. Deposit request: " . json_encode($dep) . "\n";
