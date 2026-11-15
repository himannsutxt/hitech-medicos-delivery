<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Ui};

$rows = Db::all("SELECT r.rider_id, r.name, r.live_status, r.last_seen_at, r.last_lat, r.last_lng, r.last_speed, r.last_battery,
      SUM(d.status='delivered' AND d.delivered_at >= ?) done, SUM(d.status IN ('assigned','loaded','out','en_route','arrived')) pending,
      (SELECT km_measured+km_estimated FROM hm_duty du WHERE du.rider_id=r.rider_id AND du.status='active' ORDER BY du.duty_id DESC LIMIT 1) km,
      (SELECT order_no FROM hm_delivery x WHERE x.rider_id=r.rider_id AND x.status IN ('en_route','arrived') LIMIT 1) current_order
    FROM hm_rider r LEFT JOIN hm_delivery d ON d.rider_id=r.rider_id AND d.deleted_at IS NULL
    WHERE r.deleted_at IS NULL AND r.status=1 AND r.live_status<>'off_duty' GROUP BY r.rider_id", [Fmt::istDayRangeUtc(Fmt::todayIst())[0]]);
$out = [];
foreach ($rows as $r) {
    $ago = $r['last_seen_at'] ? max(0, time() - strtotime($r['last_seen_at'] . ' UTC')) : null;
    $out[] = ['id' => (int)$r['rider_id'], 'name' => $r['name'], 'status' => Ui::liveLabel($r['live_status']), 'color' => Ui::liveColor($r['live_status']),
        'lat' => $r['last_lat'] !== null ? (float)$r['last_lat'] : null, 'lng' => $r['last_lng'] !== null ? (float)$r['last_lng'] : null,
        'speed' => $r['last_speed'] !== null ? (float)$r['last_speed'] : 0, 'battery' => $r['last_battery'] !== null ? $r['last_battery'] . '%' : '—',
        'km' => $r['km'] !== null ? round((float)$r['km'], 1) : 0, 'done' => (int)$r['done'], 'pending' => (int)$r['pending'], 'order' => $r['current_order'],
        'ago' => $ago === null ? 'never' : ($ago < 60 ? $ago . ' sec ago' : intdiv($ago, 60) . ' min ago')];
}
Ui::json(['ok' => true, 'riders' => $out]);
