<?php
declare(strict_types=1);
use HMD\{App, Db, Fmt, Jobs, ListQuery, Ui, Validate};

$e = [Ui::class, 'e'];
$tab = ($_GET['tab'] ?? '') === 'health' ? 'health' : 'log';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'run_job' && isset(Jobs::ALL[$_POST['job'] ?? ''])) {
    $msg = Jobs::run($_POST['job']);
    Ui::flash(str_starts_with($msg, 'FAILED') ? 'bad' : 'ok', $_POST['job'] . ': ' . $msg);
    Ui::redirect('?r=audit&tab=health');
}
$nav = Ui::settingsTabs($tab === 'health' ? 'health' : 'audit');
if ($tab === 'log') {
    $f = ['q' => Validate::text((string)($_GET['q'] ?? ''), 60), 'actor' => in_array($_GET['actor'] ?? '', ['admin', 'rider', 'system'], true) ? $_GET['actor'] : '', 'from' => Ui::dateParam('from'), 'to' => Ui::dateParam('to')];
    $where = ['1=1'];
    $p = [];
    if ($f['q'] !== '') {
        $where[] = '(a.action LIKE ? OR a.actor_name LIKE ? OR a.entity_id = ?)';
        $l = '%' . addcslashes($f['q'], '%_\\') . '%';
        array_push($p, $l, $l, $f['q']);
    }
    if ($f['actor']) {
        $where[] = 'a.actor_type=?';
        $p[] = $f['actor'];
    }
    if ($f['from']) {
        $where[] = 'a.created_at>=?';
        $p[] = Fmt::istDayRangeUtc($f['from'])[0];
    }
    if ($f['to']) {
        $where[] = 'a.created_at<?';
        $p[] = Fmt::istDayRangeUtc($f['to'])[1];
    }
    $w = ' WHERE ' . implode(' AND ', $where);
    $q = ListQuery::from($_GET, ['a.created_at', 'a.action', 'a.actor_name'], 'a.created_at');
    $base = ['r' => 'audit'] + $f;
    if (($_GET['export'] ?? '') === 'csv') {
        $rows = Db::all("SELECT a.created_at, a.actor_type, a.actor_name, a.action, a.entity, a.entity_id, a.old_value, a.new_value, a.ip FROM hm_audit_log a $w ORDER BY {$q->sort} {$q->order} LIMIT 100000", $p);
        foreach ($rows as &$x) {
            $x['created_at'] = Fmt::dt($x['created_at']);
        }
        unset($x);
        Ui::csv('audit_log.csv', ['When (IST)', 'Actor type', 'Who', 'Action', 'Entity', 'ID', 'Old', 'New', 'IP'], $rows);
    }
    $total = (int)Db::val("SELECT COUNT(*) FROM hm_audit_log a $w", $p);
    $rows = Db::all("SELECT a.* FROM hm_audit_log a $w" . $q->sql(), $p);
    $tr = '';
    foreach ($rows as $a) {
        $chg = '';
        if ($a['old_value'] || $a['new_value']) {
            $chg = '<details><summary class="muted small">Details</summary><div class="small"><b>Before:</b> <code>' . $e(mb_strimwidth((string)$a['old_value'], 0, 600, '…')) . '</code><br><b>After:</b> <code>' . $e(mb_strimwidth((string)$a['new_value'], 0, 600, '…')) . '</code></div></details>';
        }
        $tr .= '<tr><td data-label="When" class="nowrap">' . Fmt::dt($a['created_at']) . '</td><td data-label="Who">' . $e($a['actor_name']) . ' <span class="eva-pill muted">' . $e($a['actor_type']) . '</span></td><td data-label="Action"><b>' . $e($a['action']) . '</b></td><td data-label="Record">' . $e(trim($a['entity'] . ' ' . $a['entity_id'])) . '</td><td data-label="Change">' . $chg . '</td><td data-label="IP" class="muted small">' . $e($a['ip']) . '</td></tr>';
    }
    $tr = $tr ?: '<tr><td colspan="6" class="eva-empty">No entries.</td></tr>';
    $th = static fn($l, $c) => Ui::th($l, $c, $q, $base);
    $body = '<div class="eva-card">' . $nav . Ui::presets('audit', $base) . '<form class="eva-filters" method="get"><input type="hidden" name="r" value="audit"><div><label>Search</label><input name="q" value="' . $e($f['q']) . '" placeholder="Action, person, record ID"></div><div><label>Who</label><select name="actor">' . Ui::options(['admin' => 'Staff', 'rider' => 'Riders', 'system' => 'System'], $f['actor'], 'Everyone') . '</select></div><div><label>From</label><input type="date" name="from" value="' . $e($f['from']) . '"></div><div><label>To</label><input type="date" name="to" value="' . $e($f['to']) . '"></div><button class="eva-btn primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="eva-btn" href="?r=audit">Reset</a><span class="count">' . $total . ' entries</span></form>'
        . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr>' . $th('When', 'a.created_at') . $th('Who', 'a.actor_name') . $th('Action', 'a.action') . '<th>Record</th><th>Change</th><th>IP</th></tr></thead><tbody>' . $tr . '</tbody></table></div>' . Ui::pager($total, $q, $base) . '</div>';
    Ui::page('Settings · Audit log', 'settings', $body, 'Who changed what and when. Entries cannot be edited or deleted.', '<a class="eva-btn" href="' . $e(Ui::url($base + ['export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export CSV</a>');
    return;
}
$checks = ['PHP version' => [PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')], 'MySQL / MariaDB' => [(string)Db::val('SELECT VERSION()'), true],
    'Database time zone' => [(string)Db::val('SELECT @@session.time_zone'), true], 'Storage writable' => [App::storage(), is_writable(App::storage('uploads'))],
    'Error log writable' => [App::storage('logs/error.log'), is_writable(App::storage('logs')) ], 'GD (photos)' => [extension_loaded('gd') ? 'loaded' : 'missing', extension_loaded('gd')],
    'cURL (SMS)' => [extension_loaded('curl') ? 'loaded' : 'missing', extension_loaded('curl')], 'HTTPS' => [(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'on' : 'off — the rider app needs HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')],
    'App version' => [App::VERSION, true], 'Photos stored' => [number_format((int)Db::val('SELECT COUNT(*) FROM hm_media')) . ' (' . round((int)Db::val('SELECT COALESCE(SUM(size_bytes),0) FROM hm_media') / 1048576, 1) . ' MB)', true],
    'GPS points stored' => [number_format((int)Db::val('SELECT COUNT(*) FROM hm_location')), true]];
$ch = '';
foreach ($checks as $k => [$v, $ok]) {
    $ch .= '<tr><td data-label="Check"><b>' . $e($k) . '</b></td><td data-label="Value">' . $e($v) . '</td><td data-label="Status"><span class="eva-pill ' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'Fix') . '</span></td></tr>';
}
$jobs = Db::all('SELECT * FROM hm_job_run ORDER BY job');
$jr = '';
foreach ($jobs as $j) {
    $late = $j['last_run_at'] === null || strtotime($j['last_run_at'] . ' UTC') < time() - 1800;
    $jr .= '<tr><td data-label="Job"><b>' . $e($j['job']) . '</b><div class="muted small">' . $e(Jobs::ALL[$j['job']] ?? 'Outbound messages') . '</div></td><td data-label="Last run">' . ($j['last_run_at'] ? Fmt::dt($j['last_run_at']) : 'Never') . '</td><td data-label="Next run">' . (isset(Jobs::ALL[$j['job']]) ? 'Every 5 min (cron)' : 'On demand') . '</td>'
        . '<td data-label="Result"><span class="eva-pill ' . ($j['last_status'] === 'fail' ? 'bad' : ($late && isset(Jobs::ALL[$j['job']]) ? 'warn' : 'ok')) . '">' . $e($j['last_status'] === 'fail' ? 'Failed' : ($late && isset(Jobs::ALL[$j['job']]) ? 'Not running' : 'OK')) . '</span><div class="muted small">' . $e($j['last_message']) . '</div></td><td data-label="Failures" class="num">' . (int)$j['failures'] . '</td>'
        . '<td>' . (isset(Jobs::ALL[$j['job']]) ? '<form method="post">' . Ui::csrfField() . '<input type="hidden" name="act" value="run_job"><input type="hidden" name="job" value="' . $e($j['job']) . '"><button class="eva-btn sm"><i class="fa-solid fa-play"></i> Run now</button></form>' : '') . '</td></tr>';
}
$logf = App::storage('logs/error.log');
$tail = is_file($logf) ? implode('', array_slice(file($logf) ?: [], -40)) : '';
$body = '<div class="eva-card">' . $nav . '<div class="eva-h"><h4><i class="fa-solid fa-heart-pulse"></i> Server</h4></div><div class="eva-table-wrap"><table class="eva-table stack"><tbody>' . $ch . '</tbody></table></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-clock"></i> Scheduled jobs</h4><span class="muted small">Cron command: <code>php ' . $e(App::root()) . '/cron.php</code> every 5 minutes</span></div><div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Job</th><th>Last run</th><th>Next run</th><th>Result</th><th class="num">Failures</th><th></th></tr></thead><tbody>' . $jr . '</tbody></table></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-bug"></i> Recent errors (last 40 lines)</h4></div>' . ($tail !== '' ? '<pre style="white-space:pre-wrap;font-size:12px;background:#f7f9fd;padding:10px;border-radius:8px;max-height:360px;overflow:auto">' . $e($tail) . '</pre>' : '<div class="eva-empty">No errors logged.</div>') . '</div>';
Ui::page('Settings · System health', 'settings', $body, 'Diagnostics for the server, cron jobs and errors.');
