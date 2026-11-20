<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Ui, Auth, Perm, Summary, Cod, Geo, Settings};

$u = Auth::user();
$id = (int)($_GET['id'] ?? 0);
$r = Db::one('SELECT * FROM hm_rider WHERE rider_id=?', [$id]);
if (!$r) {
    Ui::flash('bad', 'Rider not found.');
    Ui::redirect('?r=riders');
}
$e = [Ui::class, 'e'];
$day = Ui::dateParam('date', Fmt::todayIst());
$s = Summary::riderDay($id, $day);
$cash = Cod::cashInHand($id);
$short = Cod::shortPending($id);
$kpi = static fn($cls, $icon, $v, $l) => '<div class="eva-kpi ' . $cls . '"><div class="ic"><i class="fa-solid ' . $icon . '"></i></div><div><div class="v">' . $v . '</div><div class="l">' . $e($l) . '</div></div></div>';
$attempted = $s['delivered'] + $s['failed'] + $s['refused'] + $s['rescheduled'];
$body = '<form class="eva-filters" method="get"><input type="hidden" name="r" value="rider"><input type="hidden" name="id" value="' . $id . '"><div><label>Day</label><input type="date" name="date" value="' . $e($day) . '" max="' . Fmt::todayIst() . '"></div><button class="eva-btn primary">Show</button></form>'
    . '<div class="eva-kpis">' . $kpi('blue', 'fa-clock', $s['duty_start'] ? Fmt::time($s['duty_start']) : '—', 'Duty start')
    . $kpi('slate', 'fa-hourglass-half', Fmt::mins($s['duty_minutes']), 'Duty time')
    . $kpi('green', 'fa-circle-check', $s['delivered'] . ' / ' . $s['assigned'], 'Delivered / assigned')
    . $kpi('red', 'fa-circle-xmark', $s['failed'] + $s['refused'] + $s['rescheduled'], 'Not delivered')
    . $kpi('purple', 'fa-percent', $attempted ? round($s['delivered'] / $attempted * 100) . '%' : '—', 'Success')
    . $kpi('amber', 'fa-road', number_format((float)$s['km'], 1) . ' km', 'Travelled')
    . $kpi('teal', 'fa-stopwatch', $s['avg_cycle_min'] !== null ? round((float)$s['avg_cycle_min']) . ' min' : '—', 'Avg delivery')
    . $kpi('red', 'fa-wallet', Fmt::inr($cash), 'Cash in hand now') . '</div>';

$dutyRows = Db::all('SELECT * FROM hm_duty WHERE rider_id=? AND start_at>=? AND start_at<? ORDER BY start_at', array_merge([$id], Fmt::istDayRangeUtc($day)));
$route = [];
if (Perm::has($u, 'gps.history')) {
    foreach ($dutyRows as $d) {
        $pts = Db::all('SELECT seq, lat, lng, accuracy, UNIX_TIMESTAMP(device_at) ts FROM hm_location WHERE duty_id=? ORDER BY device_at, seq LIMIT 5000', [$d['duty_id']]);
        $kept = Geo::filterPoints(array_map(static fn($p) => ['seq' => (int)$p['seq'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'accuracy' => (float)$p['accuracy'], 'ts' => (int)$p['ts']], $pts), (float)Settings::get('gps_min_accuracy'), (float)Settings::get('gps_max_speed'));
        foreach ($kept as $k) {
            $route[] = [round($k['lat'], 6), round($k['lng'], 6)];
        }
    }
    Db::insert('hm_audit_log', ['actor_type' => 'admin', 'actor_id' => $u['user_id'], 'actor_name' => $u['name'], 'action' => 'gps.view', 'entity' => 'rider', 'entity_id' => (string)$id, 'new_value' => json_encode(['date' => $day]), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'created_at' => Fmt::nowUtc()]);
}
$stops = Db::all("SELECT d.order_no, d.delivery_id, a.outcome, a.closed_at, e.lat, e.lng FROM hm_attempt a JOIN hm_delivery d ON d.delivery_id=a.delivery_id
    LEFT JOIN hm_event e ON e.event_id=(SELECT MAX(event_id) FROM hm_event WHERE delivery_id=a.delivery_id AND type='status' AND to_status IN ('delivered','not_delivered') AND server_at<=DATE_ADD(a.closed_at, INTERVAL 1 DAY))
    WHERE a.rider_id=? AND a.closed_at>=? AND a.closed_at<? ORDER BY a.closed_at", array_merge([$id], Fmt::istDayRangeUtc($day)));
$stopRows = '';
$stopPts = [];
foreach ($stops as $st) {
    $oc = ['delivered' => 'ok', 'rescheduled' => 'warn'][$st['outcome']] ?? 'bad';
    $stopRows .= '<tr><td data-label="Time">' . Fmt::time($st['closed_at']) . '</td><td data-label="Order"><a href="?r=order&id=' . (int)$st['delivery_id'] . '">' . $e($st['order_no']) . '</a></td><td data-label="Result"><span class="eva-pill ' . $oc . '">' . $e(ucfirst($st['outcome'])) . '</span></td></tr>';
    if ($st['lat'] !== null) {
        $stopPts[] = ['lat' => (float)$st['lat'], 'lng' => (float)$st['lng'], 'label' => $st['order_no'] . ' · ' . ucfirst($st['outcome']) . ' · ' . Fmt::time($st['closed_at']), 'ok' => $st['outcome'] === 'delivered'];
    }
}
$stopRows = $stopRows ?: '<tr><td colspan="3" class="eva-empty">No deliveries on this day.</td></tr>';
$info = '<dl class="kv"><dt>Employee code</dt><dd>' . $e($r['employee_code']) . '</dd><dt>Mobile</dt><dd>' . $e($r['mobile']) . '</dd><dt>Vehicle</dt><dd>' . $e(trim($r['vehicle_type'] . ' ' . $r['vehicle_number'])) . '</dd>'
    . '<dt>Licence</dt><dd>' . $e($r['licence_number'] ?: '—') . ($r['licence_expiry'] ? ' · expires ' . date('d-m-Y', strtotime($r['licence_expiry'])) : '') . '</dd>'
    . '<dt>Phone</dt><dd>' . ($r['device_id'] ? 'Registered' : 'Not registered yet') . '</dd><dt>COD short pending</dt><dd>' . Fmt::inr($short) . '</dd>'
    . '<dt>Status</dt><dd>' . ($r['deleted_at'] ? '<span class="eva-pill bad">Deleted</span>' : ((int)$r['status'] === 1 ? '<span class="eva-pill ok">Active</span>' : '<span class="eva-pill muted">Disabled</span>')) . '</dd></dl>';
$act = '';
if (Perm::has($u, 'riders.edit')) {
    $form = static fn($action, $label, $cls = '', $confirm = '', $confirm2 = '', $extra = '') => '<form method="post" action="?r=rider_action" style="display:inline-flex;gap:6px;margin:0 6px 6px 0">' . Ui::csrfField() . '<input type="hidden" name="id" value="' . (int)$r['rider_id'] . '">' . $extra . '<button class="eva-btn sm ' . $cls . '" name="action" value="' . $action . '"' . ($confirm ? ' data-confirm="' . $e($confirm) . '"' : '') . ($confirm2 ? ' data-confirm2="' . $e($confirm2) . '"' : '') . '>' . $label . '</button></form>';
    if ($r['deleted_at']) {
        $act .= $form('restore', '<i class="fa-solid fa-rotate-left"></i> Restore rider', 'success');
    } else {
        $act .= $form('reset_pin', '<i class="fa-solid fa-key"></i> Set new PIN', '', '', '', '<input class="inp" style="width:90px;height:30px" name="pin" inputmode="numeric" maxlength="4" placeholder="New PIN">');
        $act .= $form('reset_device', '<i class="fa-solid fa-mobile-screen"></i> Reset phone', '', 'Clear the registered phone so the rider can log in on a new one?');
        $act .= (int)$r['status'] === 1 ? $form('disable', '<i class="fa-solid fa-user-slash"></i> Disable', 'danger', 'Disable ' . $r['name'] . '? They will be logged out and cannot log in.') : $form('enable', '<i class="fa-solid fa-user-check"></i> Enable', 'success');
        $act .= $form('delete', '<i class="fa-solid fa-trash"></i> Delete', 'danger', 'Do you really want to delete rider ' . $r['name'] . '?', 'This will delete: rider ' . $r['name'] . ' (' . $r['employee_code'] . ', ' . $r['mobile'] . '). They will be logged out and hidden from lists. All delivery history, GPS and COD records are KEPT and the rider can be restored. Proceed?');
    }
}
$body .= '<div class="grid2"><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-route"></i> Route on ' . date('d-m-Y', strtotime($day)) . '</h4><span class="muted small">' . count($route) . ' GPS points · green = delivered, red = not delivered</span></div>'
    . (Perm::has($u, 'gps.history') ? '<div id="rmap" class="map"></div>' . Ui::mapNote() : '<div class="eva-empty">You do not have permission to view route history.</div>') . '</div>'
    . '<div><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-id-badge"></i> Profile</h4>' . (Perm::has($u, 'riders.edit') && !$r['deleted_at'] ? '<a class="more" href="?r=rider_edit&id=' . $id . '">Edit</a>' : '') . '</div>' . $info . '<div style="margin-top:12px">' . $act . '</div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-list-ol"></i> Stops</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Time</th><th>Order</th><th>Result</th></tr></thead><tbody>' . $stopRows . '</tbody></table></div></div></div></div>';
$js = Perm::has($u, 'gps.history') ? '<script nonce="' . HMD_NONCE . '">HMMap.ready(function(){var route=' . json_encode($route) . ',stops=' . json_encode($stopPts) . ';
var m=HMMap.create("rmap",{center:route.length?route[0]:(stops.length?[stops[0].lat,stops[0].lng]:[28.4595,77.0266]),zoom:12}),b=[];
if(route.length){m.line(route,{color:"#6b5cf0",weight:4});b=b.concat(route);m.marker(route[0][0],route[0][1],{color:"#20b2c4",size:7,title:"Start"});}
stops.forEach(function(s){m.marker(s.lat,s.lng,{color:s.ok?"#1fb57a":"#ec5b6f",size:8,title:s.label});b.push([s.lat,s.lng]);});
m.fit(b,16);});</script>' : '';
Ui::page($r['name'], 'riders', $body . $js, $r['employee_code'] . ' · ' . Ui::liveLabel($r['live_status']), '<a class="eva-btn" href="?r=riders">Back to riders</a>', Ui::mapHead());
