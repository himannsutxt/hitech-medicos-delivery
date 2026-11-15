<?php
declare(strict_types=1);
use HMD\{Db, Delivery, Fmt, Perm, StateMachine, Ui, Auth};

$u = Auth::user();
$id = (int)($_GET['id'] ?? 0);
$d = Delivery::get($id);
if (!$d) {
    Ui::flash('bad', 'Order not found.');
    Ui::redirect('?r=orders');
}
$e = [Ui::class, 'e'];
$rider = $d['rider_id'] ? Db::one('SELECT rider_id, name, mobile FROM hm_rider WHERE rider_id=?', [$d['rider_id']]) : null;
$tl = Delivery::timeline($id);
$items = '';
$pts = [];
foreach ($tl as $t) {
    $meta = $t['meta'] ? (json_decode($t['meta'], true) ?: []) : [];
    $txt = match ($t['type']) {
        'created' => 'Order created',
        'status' => 'Status: ' . StateMachine::label((string)$t['to_status']),
        'reassigned' => 'Reassigned' . (!empty($meta['reason']) ? ' — reason: ' . $meta['reason'] : ''),
        'call' => 'Rider called customer' . (!empty($meta['duration_s']) ? ' (' . (int)$meta['duration_s'] . ' s)' : ''),
        default => ucfirst(str_replace('_', ' ', $t['type'])),
    };
    $extra = [];
    if (isset($meta['distance_m']) && $meta['distance_m'] !== null) {
        $extra[] = (int)$meta['distance_m'] . ' m from address';
    }
    if (!empty($meta['reason'])) {
        $extra[] = StateMachine::FAIL_REASONS[$meta['reason']] ?? $meta['reason'];
    }
    if (!empty($meta['remark'])) {
        $extra[] = '"' . $meta['remark'] . '"';
    }
    if (!empty($meta['manual'])) {
        $extra[] = 'arrival marked manually';
    }
    if (!empty($meta['otp'])) {
        $extra[] = 'OTP verified';
    }
    if (!empty($meta['payment_mode'])) {
        $extra[] = 'paid by ' . strtoupper($meta['payment_mode']);
    }
    if (!empty($meta['return_scan'])) {
        $extra[] = 'warehouse return scan';
    }
    $items .= '<li><span class="t">' . Fmt::dt($t['server_at']) . '</span><span class="dot"></span><div><b>' . $e($txt) . '</b><div class="muted small">by ' . $e($t['actor_name'] ?? ucfirst($t['actor_type'])) . ($extra ? ' · ' . $e(implode(' · ', $extra)) : '') . '</div></div></li>';
    if ($t['lat'] !== null) {
        $pts[] = ['lat' => (float)$t['lat'], 'lng' => (float)$t['lng'], 'label' => $txt . ' ' . Fmt::time($t['server_at'])];
    }
}
$atts = Db::all('SELECT a.*, r.name FROM hm_attempt a JOIN hm_rider r ON r.rider_id=a.rider_id WHERE a.delivery_id=? ORDER BY a.attempt_no', [$id]);
$arows = '';
foreach ($atts as $a) {
    $cycle = ($a['started_at'] && $a['closed_at']) ? (int)round((strtotime($a['closed_at']) - strtotime($a['started_at'])) / 60) : null;
    $travel = ($a['started_at'] && $a['arrived_at']) ? (int)round((strtotime($a['arrived_at']) - strtotime($a['started_at'])) / 60) : null;
    $handle = ($a['arrived_at'] && $a['closed_at']) ? (int)round((strtotime($a['closed_at']) - strtotime($a['arrived_at'])) / 60) : null;
    $proofs = Db::all('SELECT p.type, p.media_id, p.otp_verified FROM hm_proof p WHERE p.attempt_id=?', [$a['attempt_id']]);
    $ph = '';
    foreach ($proofs as $p) {
        $ph .= $p['type'] === 'photo' && $p['media_id'] ? '<a href="?r=media&id=' . (int)$p['media_id'] . '" target="_blank" rel="noopener"><img src="?r=media&id=' . (int)$p['media_id'] . '" alt="Proof photo" loading="lazy"></a>' : '<span class="eva-pill ok">OTP verified</span>';
    }
    $oc = ['delivered' => 'ok', 'failed' => 'bad', 'refused' => 'bad', 'rescheduled' => 'warn'][$a['outcome']] ?? 'muted';
    $arows .= '<tr><td data-label="#">' . (int)$a['attempt_no'] . '</td><td data-label="Rider">' . $e($a['name']) . '</td><td data-label="Result"><span class="eva-pill ' . $oc . '">' . $e(ucfirst($a['outcome'] ?? 'In progress')) . '</span>'
        . ($a['reason_code'] ? '<div class="muted small">' . $e(StateMachine::FAIL_REASONS[$a['reason_code']] ?? $a['reason_code']) . '</div>' : '') . '</td>'
        . '<td data-label="Travel" class="num">' . Fmt::mins($travel) . '</td><td data-label="At customer" class="num">' . Fmt::mins($handle) . '</td><td data-label="Total" class="num">' . Fmt::mins($cycle) . '</td>'
        . '<td data-label="Distance">' . ($a['distance_from_address_m'] !== null ? (int)$a['distance_from_address_m'] . ' m' : '—') . ($a['arrived_manual'] ? ' <span class="eva-pill warn">Manual</span>' : '') . '</td>'
        . '<td data-label="Proof"><div class="proofs">' . ($ph ?: '<span class="muted">—</span>') . '</div></td></tr>';
}
$arows = $arows ?: '<tr><td colspan="8" class="eva-empty">No delivery attempts yet.</td></tr>';
$cod = Db::all('SELECT * FROM hm_cod_txn WHERE delivery_id=?', [$id]);
$codTxt = '';
foreach ($cod as $c) {
    $codTxt .= Fmt::inr($c['amount']) . ' by ' . strtoupper((string)$c['mode']) . ($c['reference'] ? ' (UTR ' . $e($c['reference']) . ')' : '') . ' · ' . Fmt::dt($c['created_at']) . '<br>';
}
$kv = '<dl class="kv"><dt>Status</dt><dd>' . Ui::pill($d['status']) . '</dd><dt>Customer</dt><dd>' . $e($d['customer_name']) . ' · <a href="tel:' . $e($d['customer_mobile']) . '">' . $e($d['customer_mobile']) . '</a></dd>'
    . '<dt>Address</dt><dd>' . $e($d['address']) . ($d['landmark'] ? '<div class="muted small">Landmark: ' . $e($d['landmark']) . '</div>' : '') . '</dd><dt>Pincode</dt><dd>' . $e($d['pincode'] ?: '—') . '</dd>'
    . '<dt>Payment</dt><dd>' . ($d['payment_type'] === 'cod' ? 'COD ' . Fmt::inr($d['cod_amount']) : 'Prepaid') . ' · Value ' . Fmt::inr($d['order_value']) . '</dd>'
    . ($codTxt ? '<dt>Collected</dt><dd>' . $codTxt . '</dd>' : '')
    . '<dt>Rider</dt><dd>' . ($rider ? '<a href="?r=rider&id=' . (int)$rider['rider_id'] . '">' . $e($rider['name']) . '</a> · ' . $e($rider['mobile']) : '—') . '</dd>'
    . '<dt>Priority</dt><dd>' . $e(ucfirst($d['priority'])) . ($d['is_cold_chain'] ? ' · <span class="eva-pill info">Keep cold</span>' : '') . '</dd>'
    . '<dt>Deliver by</dt><dd>' . ($d['sla_due_at'] ? Fmt::dt($d['sla_due_at']) . (($d['sla_due_at'] < Fmt::nowUtc() && !in_array($d['status'], ['delivered', 'cancelled', 'returned'], true)) ? ' <span class="eva-pill bad">Late</span>' : '') : '—') . '</dd>'
    . '<dt>Attempts</dt><dd>' . (int)$d['attempt_count'] . '</dd>'
    . ($d['notes'] ? '<dt>Notes</dt><dd>' . $e($d['notes']) . '</dd>' : '')
    . '<dt>Created</dt><dd>' . Fmt::dt($d['created_at']) . '</dd>' . ($d['delivered_at'] ? '<dt>Delivered</dt><dd>' . Fmt::dt($d['delivered_at']) . '</dd>' : '') . '</dl>';
$actions = '';
if (Perm::has($u, 'orders.edit') && in_array($d['status'], ['ready', 'assigned'], true)) {
    $actions .= '<a class="eva-btn" href="?r=order_edit&id=' . $id . '"><i class="fa-solid fa-pen"></i> Edit</a> ';
}
if (Perm::has($u, 'orders.cancel') && StateMachine::can($d['status'], 'cancelled')) {
    $actions .= '<form method="post" action="?r=order_bulk" style="display:inline">' . Ui::csrfField() . '<input type="hidden" name="ids" value="' . $id . '"><input type="hidden" name="back" value="?r=orders"><button class="eva-btn danger" name="action" value="cancel" data-confirm="Do you really want to cancel order ' . $e($d['order_no']) . '?" data-confirm2="The order will be marked Cancelled. The record and its history are kept. Proceed?"><i class="fa-solid fa-ban"></i> Cancel order</button></form>';
}
$mapCard = $pts || $d['cust_lat'] !== null ? '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-map"></i> Where events happened</h4><span class="muted small">Red pin = customer address</span></div><div id="omap" class="map sm"></div></div>' : '';
$body = '<div class="grid2"><div><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-clock-rotate-left"></i> Timeline</h4></div><ul class="timeline">' . ($items ?: '<li>No events.</li>') . '</ul></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-person-walking-arrow-right"></i> Delivery attempts</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>#</th><th>Rider</th><th>Result</th><th class="num">Travel</th><th class="num">At customer</th><th class="num">Total</th><th>Distance from address</th><th>Proof</th></tr></thead><tbody>' . $arows . '</tbody></table></div></div></div>'
    . '<div><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-circle-info"></i> Details</h4></div>' . $kv . '</div>' . $mapCard . '</div></div>';
$js = $mapCard ? '<script nonce="' . HMD_NONCE . '">(function(){var pts=' . json_encode($pts) . ',c=' . json_encode($d['cust_lat'] !== null ? [(float)$d['cust_lat'], (float)$d['cust_lng']] : null) . ';
var m=L.map("omap");L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19,attribution:"&copy; OpenStreetMap"}).addTo(m);var b=[];
if(c){L.circleMarker(c,{radius:10,color:"#ec5b6f",fillOpacity:.6}).addTo(m).bindTooltip("Customer address");b.push(c);}
pts.forEach(function(p){L.circleMarker([p.lat,p.lng],{radius:6,color:"#6b5cf0",fillOpacity:.8}).addTo(m).bindTooltip(p.label);b.push([p.lat,p.lng]);});
if(b.length>1)m.fitBounds(b,{padding:[30,30],maxZoom:17});else m.setView(b[0],16);})();</script>' : '';
Ui::page('Order ' . $d['order_no'], 'orders', $body . $js, '', $actions . ' <a class="eva-btn" href="?r=orders">Back to orders</a>', $mapCard ? Ui::leafletHead() : '');
