<?php
declare(strict_types=1);
use HMD\{Auth, Audit, Db, Fmt, Geo, Perm, Settings, Ui};

$u = Auth::user();
$offlineMin = max(1, Settings::int('gps_offline_minutes'));
$all = ($_GET['all'] ?? '') === '1' && Settings::get('live_show_offline') === '1';
$rows = Db::all("SELECT r.rider_id, r.name, r.employee_code, r.mobile, r.live_status, r.last_seen_at, r.last_lat, r.last_lng, r.last_speed, r.last_battery,
      SUM(d.status='delivered' AND d.delivered_at >= ?) done, SUM(d.status IN ('assigned','loaded','out','en_route','arrived')) pending,
      (SELECT km_measured+km_estimated FROM hm_duty du WHERE du.rider_id=r.rider_id AND du.status='active' ORDER BY du.duty_id DESC LIMIT 1) km,
      (SELECT CONCAT(x.delivery_id,'|',x.order_no,'|',x.customer_name) FROM hm_delivery x WHERE x.rider_id=r.rider_id AND x.status IN ('en_route','arrived') AND x.deleted_at IS NULL LIMIT 1) current_order
    FROM hm_rider r LEFT JOIN hm_delivery d ON d.rider_id=r.rider_id AND d.deleted_at IS NULL
    WHERE r.deleted_at IS NULL AND r.status=1" . ($all ? '' : " AND r.live_status<>'off_duty'") . ' GROUP BY r.rider_id ORDER BY r.name', [Fmt::istDayRangeUtc(Fmt::todayIst())[0]]);
$out = [];
foreach ($rows as $r) {
    $ago = $r['last_seen_at'] ? max(0, time() - strtotime($r['last_seen_at'] . ' UTC')) : null;
    $stale = $ago === null || $ago > $offlineMin * 60;
    $offDuty = $r['live_status'] === 'off_duty';
    $status = $offDuty ? 'off_duty' : ($stale ? 'gps_offline' : $r['live_status']);
    $cur = $r['current_order'] ? explode('|', $r['current_order'], 3) : null;
    $out[] = ['id' => (int)$r['rider_id'], 'name' => $r['name'], 'code' => $r['employee_code'], 'mobile' => $r['mobile'],
        'status_key' => $status, 'status' => Ui::liveLabel($status), 'color' => Ui::liveColor($status), 'offline' => $offDuty || $stale,
        'lat' => $r['last_lat'] !== null ? (float)$r['last_lat'] : null, 'lng' => $r['last_lng'] !== null ? (float)$r['last_lng'] : null,
        'speed' => $r['last_speed'] !== null ? round((float)$r['last_speed']) : 0, 'battery' => $r['last_battery'] !== null ? (int)$r['last_battery'] : null,
        'km' => $r['km'] !== null ? round((float)$r['km'], 1) : 0, 'done' => (int)$r['done'], 'pending' => (int)$r['pending'],
        'order' => $cur ? $cur[1] : null, 'order_id' => $cur ? (int)$cur[0] : null, 'customer' => $cur ? $cur[2] : null,
        'ago_s' => $ago, 'ago' => $ago === null ? 'never' : ($ago < 60 ? $ago . ' sec ago' : ($ago < 3600 ? intdiv($ago, 60) . ' min ago' : ($ago < 86400 ? intdiv($ago, 3600) . ' h ago' : Fmt::dt($r['last_seen_at'])))),
        'seen' => $r['last_seen_at'] ? Fmt::dt($r['last_seen_at']) : null];
}
$res = ['ok' => true, 'riders' => $out, 'offline_minutes' => $offlineMin, 'server_time' => Fmt::dt(Fmt::nowUtc())];

// Trail of one followed rider (route history permission needed)
$follow = (int)($_GET['follow'] ?? 0);
if ($follow > 0) {
    if (!Perm::has($u, 'gps.history')) {
        $res['trail'] = [];
        $res['trail_note'] = 'You do not have permission to see route history.';
    } else {
        $mins = max(5, min(240, Settings::int('live_trail_minutes')));
        $pts = Db::all('SELECT seq, lat, lng, accuracy, UNIX_TIMESTAMP(device_at) ts FROM hm_location WHERE rider_id=? AND device_at >= ? ORDER BY device_at, seq LIMIT 3000',
            [$follow, gmdate('Y-m-d H:i:s', time() - $mins * 60)]);
        $kept = Geo::filterPoints(array_map(static fn($p) => ['seq' => (int)$p['seq'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'accuracy' => (float)$p['accuracy'], 'ts' => (int)$p['ts']], $pts),
            (float)Settings::get('gps_min_accuracy'), (float)Settings::get('gps_max_speed'));
        $res['trail'] = array_map(static fn($k) => [round($k['lat'], 6), round($k['lng'], 6)], $kept);
        $res['trail_minutes'] = $mins;
        if (($_GET['start'] ?? '') === '1') {
            Audit::log('gps.follow', 'rider', $follow, null, ['minutes' => $mins]); // who watched whom, for privacy records
        }
    }
}
Ui::json($res);
