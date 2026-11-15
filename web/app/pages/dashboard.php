<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Summary, Ui, Exceptions};

$k = Summary::kpisToday();
$kpi = static fn($cls, $icon, $v, $l) => '<div class="eva-kpi ' . $cls . '"><div class="ic"><i class="fa-solid ' . $icon . '"></i></div><div><div class="v" data-k="' . Ui::e($l) . '">' . $v . '</div><div class="l">' . Ui::e($l) . '</div></div></div>';
$body = '<div class="eva-kpis">'
    . $kpi('teal', 'fa-motorcycle', $k['riders_active'], 'Riders on duty')
    . $kpi('slate', 'fa-inbox', $k['ready'], 'Ready to assign')
    . $kpi('purple', 'fa-route', $k['in_transit'], 'Out for delivery')
    . $kpi('green', 'fa-circle-check', $k['delivered'], 'Delivered today')
    . $kpi('red', 'fa-circle-xmark', $k['failed'], 'Failed today')
    . $kpi('blue', 'fa-percent', $k['success_rate'] === null ? '—' : $k['success_rate'] . '%', 'Success rate')
    . $kpi('amber', 'fa-road', $k['km'] . ' km', 'Travelled today')
    . $kpi('green', 'fa-indian-rupee-sign', Fmt::inr($k['cod_collected'], 0), 'COD collected today')
    . $kpi('red', 'fa-wallet', Fmt::inr($k['cod_outstanding'], 0), 'Cash with riders')
    . '</div>';

$riders = Db::all("SELECT r.rider_id, r.name, r.live_status, r.last_seen_at, r.last_lat, r.last_lng, r.last_speed, r.last_battery,
      SUM(d.status='delivered' AND d.delivered_at >= ?) done,
      SUM(d.status IN ('assigned','loaded','out','en_route','arrived')) pending,
      (SELECT km_measured+km_estimated FROM hm_duty du WHERE du.rider_id=r.rider_id AND du.status='active' ORDER BY du.duty_id DESC LIMIT 1) km,
      (SELECT order_no FROM hm_delivery x WHERE x.rider_id=r.rider_id AND x.status IN ('en_route','arrived') LIMIT 1) current_order
    FROM hm_rider r LEFT JOIN hm_delivery d ON d.rider_id=r.rider_id AND d.deleted_at IS NULL
    WHERE r.deleted_at IS NULL AND r.status=1 GROUP BY r.rider_id ORDER BY FIELD(r.live_status,'off_duty'), r.name", [Fmt::istDayRangeUtc(Fmt::todayIst())[0]]);
$rows = '';
foreach ($riders as $r) {
    $ago = $r['last_seen_at'] ? max(0, time() - strtotime($r['last_seen_at'] . ' UTC')) : null;
    $agoTxt = $ago === null ? 'Never' : ($ago < 60 ? $ago . ' sec ago' : ($ago < 3600 ? intdiv($ago, 60) . ' min ago' : Fmt::dt($r['last_seen_at'])));
    $rows .= '<tr><td data-label="Rider"><a href="?r=rider&id=' . (int)$r['rider_id'] . '"><b>' . Ui::e($r['name']) . '</b></a></td>'
        . '<td data-label="Status"><span class="dot-live" style="background:' . Ui::liveColor($r['live_status']) . '"></span>' . Ui::e(Ui::liveLabel($r['live_status'])) . '</td>'
        . '<td data-label="Current order">' . ($r['current_order'] ? Ui::e($r['current_order']) : '<span class="muted">—</span>') . '</td>'
        . '<td data-label="Done / pending" class="num">' . (int)$r['done'] . ' / ' . (int)$r['pending'] . '</td>'
        . '<td data-label="KM today" class="num">' . ($r['km'] !== null ? number_format((float)$r['km'], 1) : '—') . '</td>'
        . '<td data-label="Battery" class="num">' . ($r['last_battery'] !== null ? (int)$r['last_battery'] . '%' : '—') . '</td>'
        . '<td data-label="Last GPS">' . Ui::e($agoTxt) . '</td></tr>';
}
if (!$riders) {
    $rows = '<tr><td colspan="7" class="eva-empty">No riders yet. <a href="?r=rider_edit">Add your first rider</a></td></tr>';
}
$exc = Db::all("SELECT e.*, r.name rider FROM hm_exception e LEFT JOIN hm_rider r ON r.rider_id=e.rider_id WHERE e.status='open' ORDER BY FIELD(e.severity,'critical','high','medium','low'), e.created_at DESC LIMIT 6");
$ex = '';
foreach ($exc as $e) {
    $sev = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'muted'][$e['severity']] ?? 'muted';
    $ex .= '<div style="padding:8px 0;border-bottom:1px dashed #e4e9f2"><span class="eva-pill ' . $sev . '">' . Ui::e(ucfirst($e['severity'])) . '</span> ' . Ui::e($e['message']) . '<div class="muted small">' . Fmt::dt($e['created_at']) . '</div></div>';
}
$ex = $ex ?: '<div class="eva-empty"><i class="fa-regular fa-face-smile"></i> No open exceptions.</div>';
$wh = Db::one('SELECT lat, lng, name FROM hm_warehouse WHERE status=1 ORDER BY warehouse_id LIMIT 1');
$center = ($wh && $wh['lat'] !== null) ? [(float)$wh['lat'], (float)$wh['lng']] : [28.4595, 77.0266];

$body .= '<div class="grid2"><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-map-location-dot"></i> Live map</h4><span class="muted small">Refreshes every 15 seconds · <span id="upd"></span></span></div><div id="map" class="map"></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-triangle-exclamation"></i> Needs attention</h4><a class="more" href="?r=exceptions">All exceptions</a></div>' . $ex . '</div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-motorcycle"></i> Riders today</h4><a class="more" href="?r=orders&status=ready">Assign ready orders →</a></div>'
    . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Rider</th><th>Status</th><th>Current order</th><th class="num">Done / pending</th><th class="num">KM today</th><th class="num">Battery</th><th>Last GPS</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';

$js = '<script nonce="' . HMD_NONCE . '">
(function(){var map=L.map("map").setView([' . $center[0] . ',' . $center[1] . '],12);
L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19,attribution:"&copy; OpenStreetMap"}).addTo(map);
' . ($wh && $wh['lat'] !== null ? 'L.circle([' . $center[0] . ',' . $center[1] . '],{radius:200,color:"#20b2c4",weight:1}).addTo(map).bindTooltip(' . json_encode($wh['name']) . ');' : '') . '
var layer=L.layerGroup().addTo(map),first=true;
function esc(s){return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];});}
function load(){fetch("?r=live.json",{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;layer.clearLayers();var b=[];
d.riders.forEach(function(r){if(r.lat===null)return;var m=L.circleMarker([r.lat,r.lng],{radius:9,color:"#fff",weight:2,fillColor:r.color,fillOpacity:1}).addTo(layer);
m.bindPopup("<b>"+esc(r.name)+"</b><br>"+esc(r.status)+(r.order?"<br>Order: "+esc(r.order):"")+"<br>Speed: "+esc(r.speed)+" km/h<br>KM today: "+esc(r.km)+"<br>Delivered: "+esc(r.done)+" · Pending: "+esc(r.pending)+"<br>Battery: "+esc(r.battery)+"<br>Last update: "+esc(r.ago));b.push([r.lat,r.lng]);});
if(first&&b.length){map.fitBounds(b,{padding:[40,40],maxZoom:14});first=false;}document.getElementById("upd").textContent="updated "+new Date().toLocaleTimeString("en-IN");}).catch(function(){});}
load();setInterval(load,15000);})();</script>';
Ui::page('Dashboard', 'dashboard', $body . $js, 'Today, ' . date('d-m-Y', time() + 19800) . ' (IST)', '<a class="eva-btn primary" href="?r=order_edit"><i class="fa-solid fa-plus"></i> Add order</a> <a class="eva-btn" href="?r=import"><i class="fa-solid fa-file-import"></i> Import CSV</a>', Ui::leafletHead());
