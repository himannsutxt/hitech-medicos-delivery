<?php
declare(strict_types=1);
use HMD\{Db, Fmt, ListQuery, Perm, Ui, Auth, Validate, Ledger};

$u = Auth::user();
$f = ['q' => Validate::text((string)($_GET['q'] ?? ''), 60), 'state' => in_array($_GET['state'] ?? '', ['active', 'disabled', 'deleted'], true) ? $_GET['state'] : 'active',
    'live' => (string)($_GET['live'] ?? '')];
$where = [];
$p = [];
$where[] = match ($f['state']) { 'deleted' => 'r.deleted_at IS NOT NULL', 'disabled' => 'r.deleted_at IS NULL AND r.status=0', default => 'r.deleted_at IS NULL AND r.status=1' };
if ($f['q'] !== '') {
    $where[] = '(r.name LIKE ? OR r.mobile LIKE ? OR r.employee_code LIKE ? OR r.vehicle_number LIKE ?)';
    $l = '%' . addcslashes($f['q'], '%_\\') . '%';
    array_push($p, $l, $l, $l, $l);
}
$lives = ['off_duty' => 'Off duty', 'at_warehouse' => 'At store', 'travelling' => 'Delivering', 'at_customer' => 'At customer', 'available' => 'Available', 'gps_offline' => 'GPS offline'];
if (isset($lives[$f['live']])) {
    $where[] = 'r.live_status=?';
    $p[] = $f['live'];
} else {
    $f['live'] = '';
}
$w = ' WHERE ' . implode(' AND ', $where);
$q = ListQuery::from($_GET, ['r.name', 'r.employee_code', 'r.live_status', 'r.last_seen_at', 'r.licence_expiry', 'r.created_at'], 'r.name', 'ASC');
$base = ['r' => 'riders'] + $f;
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Db::all("SELECT r.employee_code, r.name, r.mobile, r.vehicle_type, r.vehicle_number, r.licence_number, r.licence_expiry, r.capacity, r.cod_limit, r.live_status, r.joined_on FROM hm_rider r $w ORDER BY {$q->sort} {$q->order}", $p);
    Ui::csv('riders.csv', ['Employee code', 'Name', 'Mobile', 'Vehicle type', 'Vehicle no', 'Licence no', 'Licence expiry', 'Capacity', 'COD limit', 'Status', 'Joined'], $rows);
}
$total = (int)Db::val("SELECT COUNT(*) FROM hm_rider r $w", $p);
$rows = Db::all("SELECT r.* FROM hm_rider r $w" . $q->sql(), $p);
$tr = '';
foreach ($rows as $r) {
    $cash = Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$r['rider_id']]));
    $exp = $r['licence_expiry'] ? strtotime($r['licence_expiry']) : null;
    $expPill = $exp === null ? '<span class="muted">—</span>' : ($exp < time() ? '<span class="eva-pill bad">Expired ' . date('d-m-Y', $exp) . '</span>' : ($exp < time() + 30 * 86400 ? '<span class="eva-pill warn">' . date('d-m-Y', $exp) . '</span>' : date('d-m-Y', $exp)));
    $tr .= '<tr><td data-label="Rider"><a href="?r=rider&id=' . (int)$r['rider_id'] . '"><b>' . Ui::e($r['name']) . '</b></a><div class="muted small">' . Ui::e($r['employee_code']) . '</div></td>'
        . '<td data-label="Mobile">' . Ui::e($r['mobile']) . '</td><td data-label="Vehicle">' . Ui::e(trim(($r['vehicle_type'] ?? '') . ' ' . ($r['vehicle_number'] ?? ''))) . '</td>'
        . '<td data-label="Live"><span class="dot-live" style="background:' . Ui::liveColor($r['live_status']) . '"></span>' . Ui::e(Ui::liveLabel($r['live_status'])) . '</td>'
        . '<td data-label="Cash in hand" class="num">' . ($cash > 0 ? '<b>' . Fmt::inr($cash) . '</b>' : Fmt::inr(0)) . '</td>'
        . '<td data-label="Licence expiry">' . $expPill . '</td><td data-label="Phone">' . ($r['device_id'] ? '<span class="eva-pill ok">Registered</span>' : '<span class="eva-pill muted">Not yet</span>') . '</td>'
        . '<td data-label="Last seen">' . ($r['last_seen_at'] ? Fmt::dt($r['last_seen_at']) : '—') . '</td>'
        . '<td>' . (Perm::has($u, 'riders.edit') && !$r['deleted_at'] ? '<a class="eva-btn sm" href="?r=rider_edit&id=' . (int)$r['rider_id'] . '"><i class="fa-solid fa-pen"></i> Edit</a>' : '') . '</td></tr>';
}
$tr = $tr ?: '<tr><td colspan="9" class="eva-empty">No riders here. <a href="?r=riders">Clear filters</a></td></tr>';
$th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
$tabs = '<div class="eva-tabs">';
foreach (['active' => 'Active', 'disabled' => 'Disabled', 'deleted' => 'Deleted (restorable)'] as $k => $l) {
    $tabs .= '<a href="?r=riders&state=' . $k . '" class="' . ($f['state'] === $k ? 'active' : '') . '">' . $l . '</a>';
}
$tabs .= '</div>';
$body = '<div class="eva-card">' . $tabs . Ui::presets('riders', $base) . '<form class="eva-filters" method="get"><input type="hidden" name="r" value="riders"><input type="hidden" name="state" value="' . Ui::e($f['state']) . '">'
    . '<div><label>Search</label><input name="q" value="' . Ui::e($f['q']) . '" placeholder="Name, mobile, code, vehicle"></div>'
    . '<div><label>Live status</label><select name="live">' . Ui::options($lives, $f['live'], 'Any') . '</select></div>'
    . '<button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="eva-btn" href="?r=riders">Reset</a><span class="count">' . $total . ' riders</span></form>'
    . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr>' . $th('Rider', 'r.name') . '<th>Mobile</th><th>Vehicle</th>' . $th('Live', 'r.live_status') . '<th class="num">Cash in hand</th>' . $th('Licence expiry', 'r.licence_expiry') . '<th>Phone</th>' . $th('Last seen', 'r.last_seen_at') . '<th></th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
    . Ui::pager($total, $q, $base) . '</div>';
$actions = '<a class="eva-btn" href="' . Ui::e(Ui::url($base + ['export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export CSV</a> ' . (Perm::has($u, 'riders.edit') ? '<a class="eva-btn primary" href="?r=rider_edit"><i class="fa-solid fa-user-plus"></i> Add rider</a>' : '');
Ui::page('Riders', 'riders', $body, 'Delivery staff who use the Android app.', $actions);
