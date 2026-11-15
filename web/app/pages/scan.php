<?php
declare(strict_types=1);
use HMD\{Delivery, Ui, Auth, Db, Fmt, StateMachine};

$u = Auth::user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code !== '') {
        $r = Delivery::returnScan(mb_substr($code, 0, 60), (int)$u['user_id']);
        $_SESSION['scanlog'][] = [$r['ok'], $r['message'], gmdate('Y-m-d H:i:s')];
        $_SESSION['scanlog'] = array_slice($_SESSION['scanlog'], -20);
    }
    Ui::redirect('?r=scan');
}
$log = '';
foreach (array_reverse($_SESSION['scanlog'] ?? []) as [$ok, $msg, $t]) {
    $log .= '<div style="padding:7px 0;border-bottom:1px dashed #e4e9f2"><span class="eva-pill ' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'Check') . '</span> ' . Ui::e($msg) . ' <span class="muted small">' . Fmt::time($t) . '</span></div>';
}
$waiting = Db::all("SELECT d.order_no, d.status, r.name FROM hm_delivery d LEFT JOIN hm_rider r ON r.rider_id=d.rider_id WHERE d.deleted_at IS NULL AND d.status IN ('rescheduled','rto') ORDER BY r.name, d.order_no LIMIT 200");
$w = '';
foreach ($waiting as $x) {
    $w .= '<tr><td data-label="Order">' . Ui::e($x['order_no']) . '</td><td data-label="Status">' . Ui::pill($x['status']) . '</td><td data-label="Rider">' . Ui::e($x['name'] ?? '—') . '</td></tr>';
}
$w = $w ?: '<tr><td colspan="3" class="eva-empty">No parcels waiting to come back.</td></tr>';
$body = '<div class="grid2e"><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-barcode"></i> Return scan</h4></div>'
    . '<p class="muted">Scan (or type) the order number of each undelivered parcel the rider hands back. Rescheduled orders go back to Ready; RTO orders become Back in warehouse.</p>'
    . '<form method="post">' . Ui::csrfField() . '<input class="inp scanbox" name="code" autofocus autocomplete="off" placeholder="Scan order number…"><div style="margin-top:8px"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Receive parcel</button></div></form>'
    . '<div style="margin-top:14px">' . ($log ?: '<span class="muted">Scans you make appear here.</span>') . '</div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-boxes-stacked"></i> Still to come back (' . count($waiting) . ')</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Order</th><th>Status</th><th>Rider</th></tr></thead><tbody>' . $w . '</tbody></table></div></div></div>';
Ui::page('Warehouse scan', 'scan', $body, 'Riders scan parcels when loading in the app. Receive returned parcels here.');
