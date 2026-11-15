<?php
declare(strict_types=1);
use HMD\{Db, Fmt, ListQuery, Perm, Ui, Auth, Ledger};

$u = Auth::user();
$canSettle = Perm::has($u, 'cod.settle');
$canWrite = Perm::has($u, 'cod.writeoff');
$e = [Ui::class, 'e'];
// Pending settlements
$pend = Db::all("SELECT s.*, r.name, r.employee_code FROM hm_settlement s JOIN hm_rider r ON r.rider_id=s.rider_id WHERE s.status='requested' ORDER BY s.requested_at");
$pr = '';
foreach ($pend as $s) {
    $cash = Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$s['rider_id']]));
    $pr .= '<tr><td data-label="Rider"><b>' . $e($s['name']) . '</b><div class="muted small">' . $e($s['employee_code']) . '</div></td><td data-label="Requested">' . Fmt::dt($s['requested_at']) . '</td><td data-label="Expected cash" class="num"><b>' . Fmt::inr($cash) . '</b></td>'
        . '<td>' . ($canSettle ? '<form method="post" action="?r=cod_action" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' . Ui::csrfField() . '<input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="' . (int)$s['settlement_id'] . '">'
            . '<input class="inp" style="width:120px;height:32px" type="number" step="0.01" min="0" name="received" value="' . number_format($cash, 2, '.', '') . '" aria-label="Cash received"><input class="inp" style="width:220px;height:32px" name="remark" maxlength="200" placeholder="Remark (needed if different)">'
            . '<button class="eva-btn success sm" data-confirm="Confirm you counted the cash and the amount is correct?"><i class="fa-solid fa-check"></i> Confirm received</button></form>' : '<span class="muted">Waiting for accounts</span>') . '</td></tr>';
}
$pr = $pr ?: '<tr><td colspan="4" class="eva-empty">No deposits waiting. Riders tap "Deposit COD" in the app when they return.</td></tr>';
// Rider balances
$bal = Db::all("SELECT r.rider_id, r.name, r.employee_code, r.cod_limit,
      SUM(CASE WHEN t.type='collect' AND t.mode='cash' THEN t.amount WHEN t.type IN ('deposit','recover','writeoff') THEN -t.amount ELSE 0 END) cash,
      SUM(CASE WHEN t.type='short' THEN t.amount WHEN t.type IN ('recover','writeoff') THEN -t.amount ELSE 0 END) short
    FROM hm_rider r JOIN hm_cod_txn t ON t.rider_id=r.rider_id GROUP BY r.rider_id HAVING ABS(cash)>0.009 OR ABS(short)>0.009 ORDER BY cash DESC");
$br = '';
foreach ($bal as $b) {
    $over = (float)$b['cash'] > (float)$b['cod_limit'];
    $adj = ($canSettle && (float)$b['short'] > 0.009) ? '<form method="post" action="?r=cod_action" style="display:flex;gap:6px;flex-wrap:wrap">' . Ui::csrfField() . '<input type="hidden" name="rider_id" value="' . (int)$b['rider_id'] . '">'
        . '<input class="inp" style="width:100px;height:30px" type="number" step="0.01" min="0" name="amount" placeholder="Amount"><input class="inp" style="width:170px;height:30px" name="note" maxlength="100" placeholder="Reference / reason">'
        . '<button class="eva-btn sm" name="action" value="recover">Recovered</button>' . ($canWrite ? '<button class="eva-btn sm danger" name="action" value="writeoff" data-confirm="Write off this shortage?" data-confirm2="The amount will be removed from the rider\'s pending shortage and recorded as a write-off in the audit log. Proceed?">Write off</button>' : '') . '</form>' : '';
    $br .= '<tr><td data-label="Rider"><b>' . $e($b['name']) . '</b><div class="muted small">' . $e($b['employee_code']) . '</div></td><td data-label="Cash in hand" class="num">' . ($over ? '<span class="eva-pill bad">' . Fmt::inr($b['cash']) . '</span>' : Fmt::inr($b['cash'])) . '</td><td data-label="Short pending" class="num">' . ((float)$b['short'] > 0.009 ? '<span class="eva-pill warn">' . Fmt::inr($b['short']) . '</span>' : '—') . '</td><td>' . $adj . '</td></tr>';
}
$br = $br ?: '<tr><td colspan="4" class="eva-empty">All riders are settled.</td></tr>';
// Transactions
$f = ['rider' => ctype_digit((string)($_GET['rider'] ?? '')) ? (int)$_GET['rider'] : null, 'type' => in_array($_GET['type'] ?? '', ['collect', 'deposit', 'short', 'recover', 'writeoff'], true) ? $_GET['type'] : '',
    'mode' => in_array($_GET['mode'] ?? '', ['cash', 'upi'], true) ? $_GET['mode'] : '', 'from' => Ui::dateParam('from', Fmt::todayIst()), 'to' => Ui::dateParam('to', Fmt::todayIst())];
$where = ['t.created_at>=?', 't.created_at<?'];
$p = [Fmt::istDayRangeUtc($f['from'])[0], Fmt::istDayRangeUtc($f['to'])[1]];
foreach (['rider' => 't.rider_id', 'type' => 't.type', 'mode' => 't.mode'] as $k => $col) {
    if ($f[$k]) {
        $where[] = "$col=?";
        $p[] = $f[$k];
    }
}
$w = ' WHERE ' . implode(' AND ', $where);
$q = ListQuery::from($_GET, ['t.created_at', 't.amount', 'r.name', 't.type'], 't.created_at');
$base = ['r' => 'cod'] + $f;
$sum = Db::one("SELECT SUM(CASE WHEN t.type='collect' AND t.mode='cash' THEN t.amount ELSE 0 END) cash, SUM(CASE WHEN t.type='collect' AND t.mode='upi' THEN t.amount ELSE 0 END) upi, SUM(CASE WHEN t.type='deposit' THEN t.amount ELSE 0 END) dep FROM hm_cod_txn t LEFT JOIN hm_rider r ON r.rider_id=t.rider_id $w", $p);
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Db::all("SELECT t.created_at, r.name, d.order_no, t.type, t.mode, t.amount, t.reference FROM hm_cod_txn t LEFT JOIN hm_rider r ON r.rider_id=t.rider_id LEFT JOIN hm_delivery d ON d.delivery_id=t.delivery_id $w ORDER BY {$q->sort} {$q->order}", $p);
    foreach ($rows as &$x) {
        $x['created_at'] = Fmt::dt($x['created_at']);
    }
    unset($x);
    Ui::csv('cod_' . $f['from'] . '_' . $f['to'] . '.csv', ['Date (IST)', 'Rider', 'Order', 'Type', 'Mode', 'Amount', 'Reference'], $rows);
}
$total = (int)Db::val("SELECT COUNT(*) FROM hm_cod_txn t LEFT JOIN hm_rider r ON r.rider_id=t.rider_id $w", $p);
$rows = Db::all("SELECT t.*, r.name, d.order_no FROM hm_cod_txn t LEFT JOIN hm_rider r ON r.rider_id=t.rider_id LEFT JOIN hm_delivery d ON d.delivery_id=t.delivery_id $w" . $q->sql(), $p);
$tr = '';
$tl = ['collect' => ['Collected', 'info'], 'deposit' => ['Deposited', 'ok'], 'short' => ['Short', 'bad'], 'recover' => ['Recovered', 'ok'], 'writeoff' => ['Written off', 'warn']];
foreach ($rows as $t) {
    [$lab, $cls] = $tl[$t['type']] ?? [$t['type'], 'muted'];
    $tr .= '<tr><td data-label="Date">' . Fmt::dt($t['created_at']) . '</td><td data-label="Rider">' . $e($t['name']) . '</td><td data-label="Order">' . ($t['order_no'] ? '<a href="?r=order&id=' . (int)$t['delivery_id'] . '">' . $e($t['order_no']) . '</a>' : '—') . '</td><td data-label="Type"><span class="eva-pill ' . $cls . '">' . $lab . '</span></td><td data-label="Mode">' . $e(strtoupper((string)$t['mode'])) . '</td><td data-label="Amount" class="num">' . Fmt::inr($t['amount']) . '</td><td data-label="Reference">' . $e($t['reference'] ?? '') . '</td></tr>';
}
$tr = $tr ?: '<tr><td colspan="7" class="eva-empty">No COD entries for these filters. <a href="?r=cod">Reset</a></td></tr>';
$th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
$body = '<div class="eva-kpis"><div class="eva-kpi green"><div class="ic"><i class="fa-solid fa-money-bill"></i></div><div><div class="v">' . Fmt::inr($sum['cash'] ?? 0) . '</div><div class="l">Cash collected</div></div></div>'
    . '<div class="eva-kpi blue"><div class="ic"><i class="fa-solid fa-qrcode"></i></div><div><div class="v">' . Fmt::inr($sum['upi'] ?? 0) . '</div><div class="l">UPI collected</div></div></div>'
    . '<div class="eva-kpi teal"><div class="ic"><i class="fa-solid fa-building-columns"></i></div><div><div class="v">' . Fmt::inr($sum['dep'] ?? 0) . '</div><div class="l">Deposited</div></div></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-hand-holding-dollar"></i> Deposits waiting for confirmation</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Rider</th><th>Requested</th><th class="num">Expected cash</th><th>Confirm</th></tr></thead><tbody>' . $pr . '</tbody></table></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-wallet"></i> Rider balances</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Rider</th><th class="num">Cash in hand</th><th class="num">Short pending</th><th>Adjust shortage</th></tr></thead><tbody>' . $br . '</tbody></table></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-list"></i> COD ledger</h4><a class="eva-btn" href="' . $e(Ui::url($base + ['export' => 'csv', 'sort' => $q->sort, 'order' => strtolower($q->order)])) . '"><i class="fa-solid fa-download"></i> Export CSV</a></div>'
    . Ui::presets('cod', $base) . '<form class="eva-filters" method="get"><input type="hidden" name="r" value="cod"><div><label>From</label><input type="date" name="from" value="' . $e($f['from']) . '"></div><div><label>To</label><input type="date" name="to" value="' . $e($f['to']) . '"></div>'
    . '<div><label>Rider</label><select name="rider">' . Ui::riderOptions($f['rider']) . '</select></div><div><label>Type</label><select name="type">' . Ui::options(array_map(static fn($x) => $x[0], $tl), $f['type'], 'All') . '</select></div>'
    . '<div><label>Mode</label><select name="mode">' . Ui::options(['cash' => 'Cash', 'upi' => 'UPI'], $f['mode'], 'All') . '</select></div><button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="eva-btn" href="?r=cod">Reset</a><span class="count">' . $total . ' entries</span></form>'
    . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr>' . $th('Date', 't.created_at') . $th('Rider', 'r.name') . '<th>Order</th>' . $th('Type', 't.type') . '<th>Mode</th>' . $th('Amount', 't.amount', 'num') . '<th>Reference</th></tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base) . '</div>';
Ui::page('COD', 'cod', $body, 'Cash collected by riders, deposits and shortages.');
