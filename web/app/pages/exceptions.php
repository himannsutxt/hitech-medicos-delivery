<?php
declare(strict_types=1);
use HMD\{Db, Fmt, ListQuery, Perm, Ui, Auth, Exceptions, Validate};

$u = Auth::user();
$e = [Ui::class, 'e'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Perm::has($u, 'exceptions.resolve')) {
    $note = Validate::text((string)($_POST['note'] ?? ''), 500);
    $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))), 0, 500);
    if (mb_strlen($note) < 3) {
        Ui::flash('bad', 'Write a short note on what you checked or did.');
    } else {
        $n = 0;
        foreach ($ids as $id) {
            $n += Exceptions::resolve($id, (int)$u['user_id'], $note) ? 1 : 0;
        }
        Ui::flash('ok', "$n exception(s) resolved.");
    }
    Ui::redirect((string)($_POST['back'] ?? '?r=exceptions'));
}
$f = ['status' => in_array($_GET['status'] ?? 'open', ['open', 'resolved', 'all'], true) ? ($_GET['status'] ?? 'open') : 'open',
    'severity' => in_array($_GET['severity'] ?? '', ['critical', 'high', 'medium', 'low'], true) ? $_GET['severity'] : '',
    'rule' => isset(Exceptions::RULES[$_GET['rule'] ?? '']) ? $_GET['rule'] : '',
    'rider' => ctype_digit((string)($_GET['rider'] ?? '')) ? (int)$_GET['rider'] : null, 'from' => Ui::dateParam('from'), 'to' => Ui::dateParam('to')];
$where = ['1=1'];
$p = [];
if ($f['status'] !== 'all') {
    $where[] = 'x.status=?';
    $p[] = $f['status'];
}
foreach (['severity' => 'x.severity', 'rule' => 'x.rule_code', 'rider' => 'x.rider_id'] as $k => $c) {
    if ($f[$k]) {
        $where[] = "$c=?";
        $p[] = $f[$k];
    }
}
if ($f['from']) {
    $where[] = 'x.created_at>=?';
    $p[] = Fmt::istDayRangeUtc($f['from'])[0];
}
if ($f['to']) {
    $where[] = 'x.created_at<?';
    $p[] = Fmt::istDayRangeUtc($f['to'])[1];
}
$w = ' WHERE ' . implode(' AND ', $where);
$q = ListQuery::from($_GET, ['x.created_at', 'x.severity', 'x.rule_code', 'r.name'], 'x.created_at');
$base = ['r' => 'exceptions'] + $f;
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Db::all("SELECT x.created_at, x.severity, x.rule_code, r.name, d.order_no, x.message, x.status, x.resolution_note FROM hm_exception x LEFT JOIN hm_rider r ON r.rider_id=x.rider_id LEFT JOIN hm_delivery d ON d.delivery_id=x.delivery_id $w ORDER BY {$q->sort} {$q->order}", $p);
    foreach ($rows as &$x) {
        $x['created_at'] = Fmt::dt($x['created_at']);
        $x['rule_code'] = Exceptions::RULES[$x['rule_code']] ?? $x['rule_code'];
    }
    unset($x);
    Ui::csv('exceptions.csv', ['Date (IST)', 'Severity', 'Type', 'Rider', 'Order', 'Message', 'Status', 'Resolution'], $rows);
}
$total = (int)Db::val("SELECT COUNT(*) FROM hm_exception x LEFT JOIN hm_rider r ON r.rider_id=x.rider_id $w", $p);
$rows = Db::all("SELECT x.*, r.name, d.order_no, u.name resolver FROM hm_exception x LEFT JOIN hm_rider r ON r.rider_id=x.rider_id LEFT JOIN hm_delivery d ON d.delivery_id=x.delivery_id LEFT JOIN hm_user u ON u.user_id=x.resolved_by $w" . $q->sql(), $p);
$tr = '';
foreach ($rows as $x) {
    $sev = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'muted'][$x['severity']];
    $tr .= '<tr><td>' . ($x['status'] === 'open' ? '<input type="checkbox" class="rowchk" value="' . (int)$x['exception_id'] . '" aria-label="Select">' : '') . '</td><td data-label="When">' . Fmt::dt($x['created_at']) . '</td><td data-label="Severity"><span class="eva-pill ' . $sev . '">' . ucfirst($x['severity']) . '</span></td>'
        . '<td data-label="Type">' . $e(Exceptions::RULES[$x['rule_code']] ?? $x['rule_code']) . '</td><td data-label="Details">' . $e($x['message']) . ($x['order_no'] ? ' <a href="?r=order&id=' . (int)$x['delivery_id'] . '">View order</a>' : '') . '</td>'
        . '<td data-label="Rider">' . ($x['name'] ? '<a href="?r=rider&id=' . (int)$x['rider_id'] . '">' . $e($x['name']) . '</a>' : '—') . '</td>'
        . '<td data-label="Status">' . ($x['status'] === 'open' ? '<span class="eva-pill warn">Open</span>' : '<span class="eva-pill ok">Resolved</span><div class="muted small">' . $e($x['resolver']) . ': ' . $e($x['resolution_note']) . '</div>') . '</td></tr>';
}
$tr = $tr ?: '<tr><td colspan="7" class="eva-empty">Nothing here. <a href="?r=exceptions">Reset filters</a></td></tr>';
$th = static fn($l, $c, $cls = '') => Ui::th($l, $c, $q, $base, $cls);
$bulk = Perm::has($u, 'exceptions.resolve') ? '<form id="bulkform" class="bulk" method="post">' . Ui::csrfField() . '<input type="hidden" name="ids" value=""><input type="hidden" name="back" value="' . $e(Ui::url($base)) . '"><b><span class="n">0</span> selected</b><input name="note" maxlength="500" style="min-width:260px" placeholder="What did you check or do?"><button class="eva-btn success sm"><i class="fa-solid fa-check"></i> Mark resolved</button></form>' : '';
$body = '<div class="eva-card">' . Ui::presets('exceptions', $base) . '<form class="eva-filters" method="get"><input type="hidden" name="r" value="exceptions">'
    . '<div><label>Status</label><select name="status">' . Ui::options(['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'], $f['status']) . '</select></div>'
    . '<div><label>Severity</label><select name="severity">' . Ui::options(['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'], $f['severity'], 'All') . '</select></div>'
    . '<div><label>Type</label><select name="rule">' . Ui::options(Exceptions::RULES, $f['rule'], 'All types') . '</select></div>'
    . '<div><label>Rider</label><select name="rider">' . Ui::riderOptions($f['rider']) . '</select></div><div><label>From</label><input type="date" name="from" value="' . $e($f['from']) . '"></div><div><label>To</label><input type="date" name="to" value="' . $e($f['to']) . '"></div>'
    . '<button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="eva-btn" href="?r=exceptions">Reset</a><span class="count">' . $total . ' results</span></form>' . $bulk
    . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th><input type="checkbox" id="eva-all" aria-label="Select all"></th>' . $th('When', 'x.created_at') . $th('Severity', 'x.severity') . $th('Type', 'x.rule_code') . '<th>Details</th>' . $th('Rider', 'r.name') . '<th>Status</th></tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base) . '</div>';
Ui::page('Exceptions', 'exceptions', $body, 'Automatic flags that need a person to check. Nothing is blocked automatically.', '<a class="eva-btn" href="' . $e(Ui::url($base + ['export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export CSV</a>');
