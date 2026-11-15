<?php
declare(strict_types=1);
use HMD\{Db, Fmt, ListQuery, Ui, Summary, StateMachine};

$e = [Ui::class, 'e'];
$tab = in_array($_GET['tab'] ?? '', ['riders', 'failures', 'times'], true) ? $_GET['tab'] : 'riders';
$f = ['tab' => $tab, 'from' => Ui::dateParam('from', Fmt::todayIst()), 'to' => Ui::dateParam('to', Fmt::todayIst()), 'rider' => ctype_digit((string)($_GET['rider'] ?? '')) ? (int)$_GET['rider'] : null];
if ($f['from'] > $f['to']) {
    [$f['from'], $f['to']] = [$f['to'], $f['from']];
}
if ($tab === 'riders' && $f['to'] >= Fmt::todayIst() && $f['from'] <= Fmt::todayIst()) {
    foreach (Db::all("SELECT DISTINCT rider_id FROM hm_duty WHERE duty_date=?", [Fmt::todayIst()]) as $x) {
        Summary::riderDay((int)$x['rider_id'], Fmt::todayIst()); // keep today fresh
    }
}
$base = ['r' => 'reports'] + $f;
$tabs = '<div class="eva-tabs">';
foreach (['riders' => 'Rider daily report', 'times' => 'Delivery times', 'failures' => 'Failed deliveries'] as $k => $l) {
    $tabs .= '<a class="' . ($tab === $k ? 'active' : '') . '" href="' . $e(Ui::url(['tab' => $k] + $base)) . '">' . $l . '</a>';
}
$tabs .= '</div>';
$filters = '<form class="eva-filters" method="get"><input type="hidden" name="r" value="reports"><input type="hidden" name="tab" value="' . $tab . '"><div><label>From</label><input type="date" name="from" value="' . $e($f['from']) . '"></div><div><label>To</label><input type="date" name="to" value="' . $e($f['to']) . '"></div><div><label>Rider</label><select name="rider">' . Ui::riderOptions($f['rider']) . '</select></div><button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Show</button><a class="eva-btn" href="?r=reports&tab=' . $tab . '">Reset</a>';
$csv = ($_GET['export'] ?? '') === 'csv';

if ($tab === 'riders') {
    $where = ['s.summary_date BETWEEN ? AND ?'];
    $p = [$f['from'], $f['to']];
    if ($f['rider']) {
        $where[] = 's.rider_id=?';
        $p[] = $f['rider'];
    }
    $w = ' WHERE ' . implode(' AND ', $where);
    $q = ListQuery::from($_GET, ['s.summary_date', 'r.name', 's.delivered', 's.km', 's.duty_minutes', 's.avg_cycle_min', 's.cod_cash'], 's.summary_date');
    $sql = "SELECT s.*, r.name, r.employee_code FROM hm_daily_summary s JOIN hm_rider r ON r.rider_id=s.rider_id $w";
    if ($csv) {
        $rows = Db::all($sql . " ORDER BY {$q->sort} {$q->order}", $p);
        $out = [];
        foreach ($rows as $s) {
            $att = $s['delivered'] + $s['failed'] + $s['refused'] + $s['rescheduled'];
            $out[] = [date('d-m-Y', strtotime($s['summary_date'])), $s['name'], $s['employee_code'], Fmt::time($s['duty_start']), Fmt::time($s['duty_end']), Fmt::mins((int)$s['duty_minutes']), $s['assigned'], $s['delivered'], $s['failed'], $s['refused'], $s['rescheduled'], $att ? round($s['delivered'] / $att * 100, 1) : '', $s['km'], $s['avg_cycle_min'], $s['cod_cash'], $s['cod_upi'], $s['cod_deposited']];
        }
        Ui::csv('rider_report_' . $f['from'] . '_' . $f['to'] . '.csv', ['Date', 'Rider', 'Code', 'Duty start', 'Duty end', 'Duty time', 'Assigned', 'Delivered', 'Failed', 'Refused', 'Rescheduled', 'Success %', 'KM', 'Avg delivery (min)', 'COD cash', 'COD UPI', 'Deposited'], $out);
    }
    $total = (int)Db::val("SELECT COUNT(*) FROM hm_daily_summary s $w", $p);
    $rows = Db::all($sql . $q->sql(), $p);
    $tot = Db::one("SELECT SUM(delivered) d, SUM(failed+refused+rescheduled) f, SUM(km) km, SUM(cod_cash+cod_upi) cod FROM hm_daily_summary s $w", $p);
    $tr = '';
    foreach ($rows as $s) {
        $att = $s['delivered'] + $s['failed'] + $s['refused'] + $s['rescheduled'];
        $tr .= '<tr><td data-label="Date">' . date('d-m-Y', strtotime($s['summary_date'])) . '</td><td data-label="Rider"><a href="?r=rider&id=' . (int)$s['rider_id'] . '&date=' . $e($s['summary_date']) . '">' . $e($s['name']) . '</a></td>'
            . '<td data-label="Duty">' . Fmt::time($s['duty_start']) . '–' . Fmt::time($s['duty_end']) . '<div class="muted small">' . Fmt::mins((int)$s['duty_minutes']) . '</div></td>'
            . '<td data-label="Delivered" class="num">' . (int)$s['delivered'] . ' / ' . (int)$s['assigned'] . '</td><td data-label="Not delivered" class="num">' . ($s['failed'] + $s['refused'] + $s['rescheduled']) . '</td>'
            . '<td data-label="Success" class="num">' . ($att ? round($s['delivered'] / $att * 100) . '%' : '—') . '</td><td data-label="KM" class="num">' . number_format((float)$s['km'], 1) . '</td>'
            . '<td data-label="Avg delivery" class="num">' . ($s['avg_cycle_min'] !== null ? round((float)$s['avg_cycle_min']) . ' min' : '—') . '</td><td data-label="COD" class="num">' . Fmt::inr($s['cod_cash'] + $s['cod_upi']) . '</td></tr>';
    }
    $tr = $tr ?: '<tr><td colspan="9" class="eva-empty">No rider activity in this period.</td></tr>';
    $th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
    $table = '<div class="eva-kpis"><div class="eva-kpi green"><div class="ic"><i class="fa-solid fa-circle-check"></i></div><div><div class="v">' . (int)($tot['d'] ?? 0) . '</div><div class="l">Delivered</div></div></div><div class="eva-kpi red"><div class="ic"><i class="fa-solid fa-circle-xmark"></i></div><div><div class="v">' . (int)($tot['f'] ?? 0) . '</div><div class="l">Not delivered</div></div></div><div class="eva-kpi amber"><div class="ic"><i class="fa-solid fa-road"></i></div><div><div class="v">' . number_format((float)($tot['km'] ?? 0), 1) . ' km</div><div class="l">Travelled</div></div></div><div class="eva-kpi blue"><div class="ic"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="v">' . Fmt::inr($tot['cod'] ?? 0) . '</div><div class="l">COD collected</div></div></div></div>'
        . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr>' . $th('Date', 's.summary_date') . $th('Rider', 'r.name') . $th('Duty', 's.duty_minutes') . $th('Delivered', 's.delivered', 'num') . '<th class="num">Not delivered</th><th class="num">Success</th>' . $th('KM', 's.km', 'num') . $th('Avg delivery', 's.avg_cycle_min', 'num') . $th('COD', 's.cod_cash', 'num') . '</tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base);
} else {
    [$s0] = Fmt::istDayRangeUtc($f['from']);
    [, $e1] = Fmt::istDayRangeUtc($f['to']);
    $where = ['a.closed_at>=?', 'a.closed_at<?'];
    $p = [$s0, $e1];
    if ($tab === 'failures') {
        $where[] = "a.outcome<>'delivered'";
    }
    if ($f['rider']) {
        $where[] = 'a.rider_id=?';
        $p[] = $f['rider'];
    }
    $w = ' WHERE ' . implode(' AND ', $where);
    $q = ListQuery::from($_GET, ['a.closed_at', 'd.order_no', 'r.name', 'travel', 'handling', 'cycle', 'a.distance_from_address_m'], 'a.closed_at');
    $sql = "SELECT a.*, d.order_no, d.pincode, d.payment_type, r.name, TIMESTAMPDIFF(MINUTE,a.started_at,a.arrived_at) travel, TIMESTAMPDIFF(MINUTE,a.arrived_at,a.closed_at) handling, TIMESTAMPDIFF(MINUTE,a.started_at,a.closed_at) cycle
        FROM hm_attempt a JOIN hm_delivery d ON d.delivery_id=a.delivery_id JOIN hm_rider r ON r.rider_id=a.rider_id $w";
    if ($csv) {
        $rows = Db::all($sql . " ORDER BY {$q->sort} {$q->order}", $p);
        $out = array_map(static fn($a) => [Fmt::dt($a['closed_at']), $a['order_no'], $a['name'], $a['pincode'], $a['attempt_no'], ucfirst((string)$a['outcome']), StateMachine::FAIL_REASONS[$a['reason_code']] ?? '', $a['remark'], $a['travel'], $a['handling'], $a['cycle'], $a['distance_from_address_m'], $a['arrived_manual'] ? 'Yes' : 'No'], $rows);
        Ui::csv($tab . '_' . $f['from'] . '_' . $f['to'] . '.csv', ['Closed (IST)', 'Order', 'Rider', 'Pincode', 'Attempt', 'Result', 'Reason', 'Remark', 'Travel min', 'At customer min', 'Total min', 'Distance from address m', 'Manual arrival'], $out);
    }
    $total = (int)Db::val("SELECT COUNT(*) FROM hm_attempt a JOIN hm_delivery d ON d.delivery_id=a.delivery_id JOIN hm_rider r ON r.rider_id=a.rider_id $w", $p);
    $rows = Db::all($sql . $q->sql(), $p);
    $tr = '';
    foreach ($rows as $a) {
        $oc = ['delivered' => 'ok', 'rescheduled' => 'warn'][$a['outcome']] ?? 'bad';
        $tr .= '<tr><td data-label="Closed">' . Fmt::dt($a['closed_at']) . '</td><td data-label="Order"><a href="?r=order&id=' . (int)$a['delivery_id'] . '">' . $e($a['order_no']) . '</a></td><td data-label="Rider">' . $e($a['name']) . '</td>'
            . '<td data-label="Result"><span class="eva-pill ' . $oc . '">' . $e(ucfirst((string)$a['outcome'])) . '</span>' . ($a['reason_code'] ? '<div class="muted small">' . $e(StateMachine::FAIL_REASONS[$a['reason_code']] ?? $a['reason_code']) . '</div>' : '') . '</td>'
            . '<td data-label="Travel" class="num">' . Fmt::mins($a['travel'] !== null ? (int)$a['travel'] : null) . '</td><td data-label="At customer" class="num">' . Fmt::mins($a['handling'] !== null ? (int)$a['handling'] : null) . '</td><td data-label="Total" class="num">' . Fmt::mins($a['cycle'] !== null ? (int)$a['cycle'] : null) . '</td>'
            . '<td data-label="Distance" class="num">' . ($a['distance_from_address_m'] !== null ? (int)$a['distance_from_address_m'] . ' m' : '—') . '</td></tr>';
    }
    $tr = $tr ?: '<tr><td colspan="8" class="eva-empty">Nothing in this period.</td></tr>';
    $th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
    $reasons = '';
    if ($tab === 'failures') {
        foreach (Db::all("SELECT a.reason_code, COUNT(*) n FROM hm_attempt a $w GROUP BY a.reason_code ORDER BY n DESC", $p) as $x) {
            $reasons .= '<span class="eva-pill muted" style="margin:0 6px 6px 0">' . $e(StateMachine::FAIL_REASONS[$x['reason_code']] ?? 'Other') . ': ' . (int)$x['n'] . '</span>';
        }
        $reasons = $reasons ? '<div style="margin-bottom:10px">' . $reasons . '</div>' : '';
    }
    $table = $reasons . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr>' . $th('Closed', 'a.closed_at') . $th('Order', 'd.order_no') . $th('Rider', 'r.name') . '<th>Result</th>' . $th('Travel', 'travel', 'num') . $th('At customer', 'handling', 'num') . $th('Total', 'cycle', 'num') . $th('From address', 'a.distance_from_address_m', 'num') . '</tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base);
}
$filters .= '<span class="count">' . $total . ' rows</span></form>';
$body = '<div class="eva-card">' . $tabs . Ui::presets('reports', $base) . $filters . $table . '</div>';
Ui::page('Reports', 'reports', $body, 'All times in IST. Export uses the same filters.', '<a class="eva-btn" href="' . $e(Ui::url($base + ['export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export CSV</a>');
