<?php
declare(strict_types=1);
use HMD\{Db, Fmt, ListQuery, Perm, StateMachine, Ui, Auth, Validate};

$u = Auth::user();
$f = [
    'q' => Validate::text((string)($_GET['q'] ?? ''), 60),
    'status' => (string)($_GET['status'] ?? ''),
    'rider' => ctype_digit((string)($_GET['rider'] ?? '')) ? (int)$_GET['rider'] : null,
    'payment' => in_array($_GET['payment'] ?? '', ['cod', 'prepaid'], true) ? $_GET['payment'] : '',
    'priority' => in_array($_GET['priority'] ?? '', ['normal', 'priority', 'urgent'], true) ? $_GET['priority'] : '',
    'from' => Ui::dateParam('from'), 'to' => Ui::dateParam('to'),
];
$groups = ['open' => 'All open', 'with_rider' => 'With riders now', 'failed' => 'Failed / RTO'];
$where = ['d.deleted_at IS NULL'];
$p = [];
if ($f['q'] !== '') {
    $where[] = '(d.order_no LIKE ? OR d.customer_name LIKE ? OR d.customer_mobile LIKE ? OR d.pincode = ?)';
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    array_push($p, $like, $like, $like, $f['q']);
}
if (isset(StateMachine::LABELS[$f['status']])) {
    $where[] = 'd.status = ?';
    $p[] = $f['status'];
} elseif ($f['status'] === 'open') {
    $where[] = "d.status NOT IN ('delivered','returned','cancelled')";
} elseif ($f['status'] === 'with_rider') {
    $where[] = 'd.status IN (' . Db::in(StateMachine::WITH_RIDER) . ')';
    array_push($p, ...StateMachine::WITH_RIDER);
} elseif ($f['status'] === 'failed') {
    $where[] = "d.status IN ('not_delivered','rescheduled','rto')";
} else {
    $f['status'] = '';
}
if ($f['rider']) {
    $where[] = 'd.rider_id = ?';
    $p[] = $f['rider'];
}
if ($f['payment']) {
    $where[] = 'd.payment_type = ?';
    $p[] = $f['payment'];
}
if ($f['priority']) {
    $where[] = 'd.priority = ?';
    $p[] = $f['priority'];
}
if ($f['from']) {
    $where[] = 'd.created_at >= ?';
    $p[] = Fmt::istDayRangeUtc($f['from'])[0];
}
if ($f['to']) {
    $where[] = 'd.created_at < ?';
    $p[] = Fmt::istDayRangeUtc($f['to'])[1];
}
$w = ' WHERE ' . implode(' AND ', $where);
$sorts = ['d.created_at', 'd.order_no', 'd.customer_name', 'd.status', 'd.cod_amount', 'r.name', 'd.attempt_count', 'd.delivered_at', 'd.priority'];
$q = ListQuery::from($_GET, $sorts, 'd.created_at');
$base = ['r' => 'orders'] + $f;

if (($_GET['export'] ?? '') === 'csv') {
    $rows = Db::all("SELECT d.order_no, d.customer_name, d.customer_mobile, d.address, d.pincode, d.payment_type, d.cod_amount, d.order_value, d.priority, d.status, r.name rider, d.attempt_count, d.created_at, d.delivered_at
        FROM hm_delivery d LEFT JOIN hm_rider r ON r.rider_id=d.rider_id $w ORDER BY {$q->sort} {$q->order} LIMIT 50000", $p);
    $gen = (static function () use ($rows) {
        foreach ($rows as $r) {
            $r['status'] = StateMachine::label($r['status']);
            $r['created_at'] = Fmt::dt($r['created_at']);
            $r['delivered_at'] = Fmt::dt($r['delivered_at']);
            yield $r;
        }
    })();
    Ui::csv('orders_' . date('d-m-Y') . '.csv', ['Order no', 'Customer', 'Mobile', 'Address', 'Pincode', 'Payment', 'COD amount', 'Order value', 'Priority', 'Status', 'Rider', 'Attempts', 'Created (IST)', 'Delivered (IST)'], $gen);
}

$total = (int)Db::val("SELECT COUNT(*) FROM hm_delivery d LEFT JOIN hm_rider r ON r.rider_id=d.rider_id $w", $p);
$rows = Db::all("SELECT d.*, r.name rider_name FROM hm_delivery d LEFT JOIN hm_rider r ON r.rider_id=d.rider_id $w" . $q->sql(), $p);

$statusOpts = '<option value="">All statuses</option><optgroup label="Groups">' . Ui::options($groups, $f['status']) . '</optgroup><optgroup label="Status">' . Ui::options(StateMachine::LABELS, $f['status']) . '</optgroup>';
$filters = '<form class="eva-filters" method="get"><input type="hidden" name="r" value="orders"><input type="hidden" name="sort" value="' . Ui::e($q->sort) . '"><input type="hidden" name="order" value="' . strtolower($q->order) . '"><input type="hidden" name="limit" value="' . $q->limit . '">'
    . '<div><label>Search</label><input name="q" value="' . Ui::e($f['q']) . '" placeholder="Order no, name, mobile, pincode"></div>'
    . '<div><label>Status</label><select name="status">' . $statusOpts . '</select></div>'
    . '<div><label>Rider</label><select name="rider">' . Ui::riderOptions($f['rider']) . '</select></div>'
    . '<div><label>Payment</label><select name="payment">' . Ui::options(['cod' => 'COD', 'prepaid' => 'Prepaid'], $f['payment'], 'All') . '</select></div>'
    . '<div><label>Priority</label><select name="priority">' . Ui::options(['urgent' => 'Urgent', 'priority' => 'Priority', 'normal' => 'Normal'], $f['priority'], 'All') . '</select></div>'
    . '<div><label>Created from</label><input type="date" name="from" value="' . Ui::e($f['from']) . '"></div>'
    . '<div><label>To</label><input type="date" name="to" value="' . Ui::e($f['to']) . '"></div>'
    . '<button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="eva-btn" href="?r=orders">Reset</a>'
    . '<span class="count">' . number_format($total) . ' results</span></form>';

$canAssign = Perm::has($u, 'orders.assign');
$canCancel = Perm::has($u, 'orders.cancel');
$bulk = '<form id="bulkform" class="bulk" method="post" action="?r=order_bulk">' . Ui::csrfField() . '<input type="hidden" name="ids" value=""><input type="hidden" name="back" value="' . Ui::e(Ui::url($base + ['sort' => $q->sort, 'order' => strtolower($q->order), 'page' => $q->page, 'limit' => $q->limit])) . '">'
    . '<b><span class="n">0</span> selected</b>'
    . ($canAssign ? '<select name="rider_id">' . Ui::riderOptions(null, true, 'Choose rider…') . '</select><input name="reason" maxlength="200" placeholder="Reason (needed for reassign)"><button class="eva-btn primary sm" name="action" value="assign"><i class="fa-solid fa-user-check"></i> Assign</button><button class="eva-btn sm" name="action" value="unassign" data-confirm="Remove the rider from the selected orders? They go back to Ready.">Unassign</button>' : '')
    . '<button class="eva-btn sm" name="action" value="export"><i class="fa-solid fa-download"></i> Export selected</button>'
    . ($canCancel ? '<button class="eva-btn danger sm" name="action" value="cancel" data-confirm="Do you really want to cancel the selected orders?" data-confirm2="Selected orders that are Ready, Assigned or Loaded will be marked Cancelled (records are kept). Proceed?"><i class="fa-solid fa-ban"></i> Cancel</button>' : '')
    . '</form>';

$th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
$tr = '';
foreach ($rows as $d) {
    $prio = $d['priority'] === 'urgent' ? ' <span class="eva-pill bad">Urgent</span>' : ($d['priority'] === 'priority' ? ' <span class="eva-pill warn">Priority</span>' : '');
    $tr .= '<tr><td><input type="checkbox" class="rowchk" value="' . (int)$d['delivery_id'] . '" aria-label="Select"></td>'
        . '<td data-label="Order"><a href="?r=order&id=' . (int)$d['delivery_id'] . '"><b>' . Ui::e($d['order_no']) . '</b></a>' . $prio . ($d['is_cold_chain'] ? ' <span class="eva-pill info" title="Keep cold">Cold</span>' : '') . '</td>'
        . '<td data-label="Customer">' . Ui::e($d['customer_name']) . '<div class="muted small">' . Ui::e($d['customer_mobile']) . ' · ' . Ui::e($d['pincode']) . '</div></td>'
        . '<td data-label="Payment">' . ($d['payment_type'] === 'cod' ? '<span class="eva-pill warn">COD</span> ' . Fmt::inr($d['cod_amount']) : '<span class="eva-pill ok">Prepaid</span>') . '</td>'
        . '<td data-label="Status">' . Ui::pill($d['status']) . ($d['scheduled_for'] ? '<div class="muted small">For ' . Ui::e(date('d-m-Y', strtotime($d['scheduled_for']))) . '</div>' : '') . '</td>'
        . '<td data-label="Rider">' . ($d['rider_name'] ? Ui::e($d['rider_name']) : '<span class="muted">—</span>') . '</td>'
        . '<td data-label="Attempts" class="num">' . (int)$d['attempt_count'] . '</td>'
        . '<td data-label="Created" class="nowrap">' . Fmt::dt($d['created_at']) . '</td>'
        . '<td data-label="Delivered" class="nowrap">' . Fmt::dt($d['delivered_at']) . '</td></tr>';
}
if (!$rows) {
    $tr = '<tr><td colspan="9" class="eva-empty">No orders match these filters. <a href="?r=orders">Clear filters</a></td></tr>';
}
$body = '<div class="eva-card">' . Ui::presets('orders', $base + ['sort' => $q->sort, 'order' => strtolower($q->order)]) . $filters . $bulk
    . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th><input type="checkbox" id="eva-all" aria-label="Select all"></th>'
    . $th('Order', 'd.order_no') . $th('Customer', 'd.customer_name') . $th('Payment', 'd.cod_amount') . $th('Status', 'd.status') . $th('Rider', 'r.name') . $th('Attempts', 'd.attempt_count', 'num') . $th('Created', 'd.created_at') . $th('Delivered', 'd.delivered_at')
    . '</tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base) . '</div>';
$actions = '<a class="eva-btn" href="' . Ui::e(Ui::url($base + ['sort' => $q->sort, 'order' => strtolower($q->order), 'export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export CSV</a> '
    . (Perm::has($u, 'orders.edit') ? '<a class="eva-btn" href="?r=import"><i class="fa-solid fa-file-import"></i> Import CSV</a> <a class="eva-btn primary" href="?r=order_edit"><i class="fa-solid fa-plus"></i> Add order</a>' : '');
Ui::page('Orders', 'orders', $body, 'Select orders to assign them to a rider in one go.', $actions);
