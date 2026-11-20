<?php
declare(strict_types=1);
namespace HMD;

/** Shared HTML helpers for the admin website (Eva UI). */
final class Ui
{
    public const NAV = [
        ['dashboard', 'Dashboard', 'fa-gauge-high', 'dashboard.view'],
        ['live', 'Live Tracking', 'fa-location-crosshairs', 'dashboard.view'],
        ['orders', 'Orders', 'fa-box', 'orders.view'],
        ['scan', 'Warehouse Scan', 'fa-barcode', 'orders.scan'],
        ['riders', 'Riders', 'fa-motorcycle', 'riders.view'],
        ['cod', 'COD', 'fa-indian-rupee-sign', 'cod.view'],
        ['reports', 'Reports', 'fa-chart-column', 'reports.view'],
        ['exceptions', 'Exceptions', 'fa-triangle-exclamation', 'exceptions.view'],
        ['settings', 'Settings', 'fa-gear', 'settings.edit|audit.view'],
        ['users', 'Users', 'fa-user-shield', 'users.edit'],
        ['help', 'Help', 'fa-circle-question', null],
    ];

    public const NAV_TIPS = [
        'dashboard' => 'Today at a glance: KPIs, riders and things needing attention',
        'live' => 'Where every rider is right now, on a live map',
        'orders' => 'All delivery orders: add, import, assign and track',
        'scan' => 'Receive undelivered parcels back at the store',
        'riders' => 'Delivery staff: add riders, set PINs, see routes',
        'cod' => 'Cash collected by riders, deposits and shortages',
        'reports' => 'Daily rider report, delivery times and failures',
        'exceptions' => 'Automatic warnings that need someone to check',
        'settings' => 'Store location, maps, GPS, proof, SMS, data, audit log and system health',
        'users' => 'Office staff logins and what each role can do',
        'help' => 'Setup guide and common questions',
    ];

    /** Tabs shared by Settings, Audit log and System health. */
    public static function settingsTabs(string $active): string
    {
        $u = Auth::user();
        $tabs = [];
        if (Perm::has($u, 'settings.edit')) {
            foreach (['general' => 'General', 'store' => 'Store location', 'maps' => 'Maps', 'tracking' => 'GPS & tracking', 'delivery' => 'Delivery & proof', 'cod' => 'COD', 'notify' => 'SMS / WhatsApp', 'data' => 'Data & backup'] as $k => $l) {
                $tabs[$k] = [$l, '?r=settings&tab=' . $k];
            }
        }
        if (Perm::has($u, 'audit.view')) {
            $tabs['audit'] = ['Audit log', '?r=audit'];
            $tabs['health'] = ['System health', '?r=audit&tab=health'];
        }
        $h = '<div class="eva-tabs">';
        foreach ($tabs as $k => [$l, $href]) {
            $h .= '<a class="' . ($active === $k ? 'active' : '') . '" href="' . $href . '">' . self::e($l) . '</a>';
        }
        return $h . '</div>';
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Small (i) icon with an explanation; shows on hover (desktop) and tap/focus (phone). */
    public static function tip(string $text): string
    {
        return '<span class="tip" tabindex="0" role="note" aria-label="' . self::e($text) . '" data-tip="' . self::e($text) . '"><i class="fa-solid fa-circle-info"></i></span>';
    }

    /** title + aria-label attributes for buttons and links. */
    public static function t(string $text): string
    {
        return ' title="' . self::e($text) . '" aria-label="' . self::e($text) . '"';
    }

    public static function url(array $params): string
    {
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        return '?' . http_build_query($params);
    }

    public static function flash(?string $type = null, ?string $msg = null): string
    {
        if ($type !== null) {
            $_SESSION['flash'][] = [$type, $msg];
            return '';
        }
        $h = '';
        foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
            $h .= '<div class="eva-alert ' . self::e($t) . '">' . self::e($m) . '</div>';
        }
        unset($_SESSION['flash']);
        return $h;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(Auth::csrf()) . '">';
    }

    public static function pill(string $status): string
    {
        return '<span class="eva-pill ' . (StateMachine::PILL[$status] ?? 'muted') . '">' . self::e(StateMachine::label($status)) . '</span>';
    }

    public static function page(string $title, string $active, string $body, string $sub = '', string $actions = '', string $head = ''): void
    {
        $u = Auth::user();
        $open = (int)Db::val("SELECT COUNT(*) FROM hm_exception WHERE status='open'");
        $nav = '';
        foreach (self::NAV as [$key, $label, $icon, $perm]) {
            if ($perm !== null && !array_filter(explode('|', $perm), static fn($p) => Perm::has($u, $p))) {
                continue;
            }
            $href = $key;
            if ($key === 'settings' && !Perm::has($u, 'settings.edit')) {
                $href = 'audit'; // audit-only users land on the Audit log tab
            }
            $badge = ($key === 'exceptions' && $open > 0) ? ' <span class="badge">' . $open . '</span>' : '';
            $nav .= '<a href="?r=' . $href . '" class="' . ($active === $key ? 'active' : '') . '"' . self::t(self::NAV_TIPS[$key] ?? $label) . '><i class="fa-solid ' . $icon . '"></i> ' . self::e($label) . $badge . '</a>';
        }
        $company = self::e(Settings::get('company_name'));
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . ' · ' . $company . ' Delivery</title>'
            . '<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/vendor/fa/css/all.min.css">'
            . '<link rel="stylesheet" href="assets/eva.css?v=' . App::VERSION . '">' . $head . '</head><body>'
            . '<header class="hm-top"><a class="hm-brand" href="?r=dashboard"><span class="logo"><i class="fa-solid fa-truck-medical"></i></span><span>' . $company . ' Delivery<small>Rider tracking & dispatch</small></span></a>'
            . '<div class="hm-user"><i class="fa-regular fa-user"></i> <span class="name"><b>' . self::e($u['name'] ?? '') . '</b> · ' . self::e($u['role_name'] ?? '') . '</span>'
            . '<form method="post" action="?r=logout" style="margin:0">' . self::csrfField() . '<button class="eva-btn sm"' . self::t('End your session on this device') . '><i class="fa-solid fa-right-from-bracket"></i> Log out</button></form></div></header>'
            . '<div class="eva-wrap"><nav class="eva-nav">' . $nav . '<span class="ver">v' . App::VERSION . '</span></nav>'
            . '<div class="page-title"><div><h1>' . self::e($title) . '</h1>' . ($sub !== '' ? '<div class="sub">' . self::e($sub) . '</div>' : '') . '</div><div>' . Tips::apply($actions) . '</div></div>'
            . self::flash() . Tips::apply($body) . '</div><script src="assets/app.js?v=' . App::VERSION . '"></script></body></html>';
    }

    /** Sortable header cell. $base = current filter params (without sort/order/page). */
    public static function th(string $label, string $col, ListQuery $q, array $base, string $class = ''): string
    {
        $active = $q->sort === $col;
        $next = $active && $q->order === 'ASC' ? 'desc' : 'asc';
        $cls = 'sort' . ($active ? ' ' . strtolower($q->order) : '');
        return '<th class="' . $class . '"><a class="' . $cls . '" href="' . self::e(self::url($base + ['sort' => $col, 'order' => $next, 'limit' => $q->limit])) . '">' . self::e($label) . '</a></th>';
    }

    public static function pager(int $total, ListQuery $q, array $base): string
    {
        $pages = max(1, (int)ceil($total / $q->limit));
        $from = $total ? $q->offset() + 1 : 0;
        $to = min($total, $q->offset() + $q->limit);
        $keep = $base + ['sort' => $q->sort, 'order' => strtolower($q->order), 'limit' => $q->limit];
        $links = '';
        $win = array_unique(array_filter([1, $q->page - 2, $q->page - 1, $q->page, $q->page + 1, $q->page + 2, $pages], static fn($p) => $p >= 1 && $p <= $pages));
        sort($win);
        $prev = 0;
        foreach ($win as $p) {
            if ($p - $prev > 1) {
                $links .= '<span>…</span>';
            }
            $links .= $p === $q->page ? '<span class="cur">' . $p . '</span>' : '<a href="' . self::e(self::url($keep + ['page' => $p])) . '">' . $p . '</a>';
            $prev = $p;
        }
        $sel = '<select class="limit-sel" aria-label="Rows per page">';
        foreach ([25, 50, 100] as $l) {
            $sel .= '<option value="' . self::e(self::url(['limit' => $l] + $keep)) . '"' . ($l === $q->limit ? ' selected' : '') . '>' . $l . ' per page</option>';
        }
        $sel .= '</select>';
        return '<div class="eva-pager"><span>Showing ' . $from . '–' . $to . ' of ' . number_format($total) . '</span><div class="pages">' . $links . '</div>' . $sel . '</div>';
    }

    /** Streams a CSV download (UTF-8 BOM, formula-safe cells). */
    public static function csv(string $filename, array $headers, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, array_map([CsvImport::class, 'safeCell'], array_values($r)), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /** Saved filter presets for list pages. */
    public static function presets(string $page, array $base): string
    {
        $u = Auth::user();
        $rows = Db::all('SELECT preset_id, name, query FROM hm_filter_preset WHERE user_id=? AND page=? ORDER BY name', [$u['user_id'], $page]);
        $h = '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px"><span class="muted small"><i class="fa-regular fa-bookmark"></i> Saved views:</span>';
        foreach ($rows as $p) {
            $h .= '<a class="eva-btn sm" href="?' . self::e($p['query']) . '">' . self::e($p['name']) . '</a>'
                . '<form method="post" action="?r=preset_delete" style="display:inline">' . self::csrfField() . '<input type="hidden" name="id" value="' . (int)$p['preset_id'] . '"><input type="hidden" name="back" value="' . self::e($page) . '"><button class="eva-btn sm danger" title="Remove saved view" data-confirm="Remove this saved view?"><i class="fa-solid fa-xmark"></i></button></form>';
        }
        $h .= '<form method="post" action="?r=preset_save" style="display:inline-flex;gap:6px">' . self::csrfField()
            . '<input type="hidden" name="page" value="' . self::e($page) . '"><input type="hidden" name="query" value="' . self::e(http_build_query(['r' => $page] + array_filter($base, static fn($v) => $v !== '' && $v !== null))) . '">'
            . '<input class="inp" style="width:150px;height:30px" name="name" maxlength="60" placeholder="Name this view"><button class="eva-btn sm"><i class="fa-regular fa-floppy-disk"></i> Save view</button></form></div>';
        return $h;
    }

    public static function riderOptions(?int $selected = null, bool $any = true, string $anyLabel = 'All riders'): string
    {
        $h = $any ? '<option value="">' . self::e($anyLabel) . '</option>' : '';
        foreach (Db::all('SELECT rider_id, name, employee_code FROM hm_rider WHERE deleted_at IS NULL AND status=1 ORDER BY name') as $r) {
            $h .= '<option value="' . (int)$r['rider_id'] . '"' . ($selected === (int)$r['rider_id'] ? ' selected' : '') . '>' . self::e($r['name'] . ' (' . $r['employee_code'] . ')') . '</option>';
        }
        return $h;
    }

    public static function options(array $map, ?string $selected, string $anyLabel = ''): string
    {
        $h = $anyLabel !== '' ? '<option value="">' . self::e($anyLabel) . '</option>' : '';
        foreach ($map as $k => $v) {
            $h .= '<option value="' . self::e($k) . '"' . ((string)$selected === (string)$k ? ' selected' : '') . '>' . self::e($v) . '</option>';
        }
        return $h;
    }

    /** Map assets: Google Maps when a key is set, OpenStreetMap (Leaflet) otherwise and as fallback. */
    public static function mapHead(): string
    {
        $cfg = ['key' => Settings::get('google_maps_key'), 'type' => Settings::get('map_type') ?: 'roadmap'];
        return '<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">'
            . '<script src="assets/vendor/leaflet/leaflet.js"></script>'
            . '<script nonce="' . HMD_NONCE . '">window.HM_MAP=' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
            . '<script src="assets/hmmap.js?v=' . App::VERSION . '"></script>';
    }

    public static function leafletHead(): string
    {
        return self::mapHead();
    }

    /** Small note shown under a map when Google Maps could not load. */
    public static function mapNote(): string
    {
        return '<div class="hm-map-note muted small" hidden style="margin-top:6px"><i class="fa-solid fa-triangle-exclamation"></i> Google Maps could not load (check the API key in Settings → Maps). Showing OpenStreetMap instead.</div>';
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $q): never
    {
        header('Location: ' . (str_starts_with($q, '?') ? $q : '?' . $q));
        exit;
    }

    /** Validated date filter or default. */
    public static function dateParam(string $k, string $default = ''): string
    {
        $v = (string)($_GET[$k] ?? '');
        return Validate::date($v) ? $v : $default;
    }

    public static function liveColor(string $s): string
    {
        return ['travelling' => '#2f6fed', 'at_customer' => '#2f6fed', 'available' => '#1fb57a', 'at_warehouse' => '#20b2c4',
            'idle' => '#f0a23a', 'on_break' => '#f0a23a', 'returning' => '#8b63f0', 'gps_offline' => '#ec5b6f', 'off_duty' => '#9aa5b8'][$s] ?? '#9aa5b8';
    }

    /** Phone/app problems for a rider (see RiderHealth). */
    public static function health(array $r): array
    {
        return RiderHealth::check($r, ($r['live_status'] ?? 'off_duty') !== 'off_duty', max(1, Settings::int('gps_offline_minutes')));
    }

    /** Small pill for lists: OK / N warnings / N problems, with the problems in the tooltip. */
    public static function healthBadge(array $r): string
    {
        $is = self::health($r);
        if (!$is) {
            return '<span class="eva-pill ok"' . self::t('No problems found on the rider\'s phone.') . '><i class="fa-solid fa-circle-check"></i> OK</span>';
        }
        $bad = count(array_filter($is, static fn($x) => $x['level'] === 'bad'));
        $tip = implode(' • ', array_column($is, 'title'));
        return '<span class="eva-pill ' . ($bad ? 'bad' : 'warn') . '"' . self::t($tip) . '><i class="fa-solid fa-triangle-exclamation"></i> ' . count($is) . ' ' . (count($is) === 1 ? 'issue' : 'issues') . '</span>';
    }

    /** "App health" card for the rider page: problems with fixes, then the raw phone readings. */
    public static function healthPanel(array $r): string
    {
        $e = [self::class, 'e'];
        $ago = static function (?string $t): string {
            if (!$t) {
                return 'Never';
            }
            $s = max(0, time() - strtotime($t . ' UTC'));
            return ($s < 60 ? $s . ' sec ago' : ($s < 3600 ? intdiv($s, 60) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : Fmt::dt($t)))) . ' · ' . Fmt::dt($t);
        };
        $yn = static fn($v, $yes, $no) => $v === null ? '<span class="muted">Not reported yet</span>' : ((int)$v ? '<span class="eva-pill ok">' . $yes . '</span>' : '<span class="eva-pill bad">' . $no . '</span>');
        $perm = $r['loc_permission'] ?? null;
        $permTxt = $perm === null ? '<span class="muted">Not reported yet</span>' : ($perm === 'always' ? '<span class="eva-pill ok">Allow all the time</span>' : '<span class="eva-pill bad">' . $e(['whileInUse' => 'Only while using the app', 'denied' => 'Denied', 'deniedForever' => 'Denied (don\'t ask again)'][$perm] ?? $perm) . '</span>');
        $acc = $r['last_accuracy'] ?? null;
        $accTxt = $acc === null ? '—' : ((int)$acc . ' m ' . ((float)$acc <= 30 ? '<span class="eva-pill ok">Good</span>' : ((float)$acc <= 100 ? '<span class="eva-pill warn">Fair</span>' : '<span class="eva-pill bad">Poor</span>')));
        $issues = self::health($r);
        $list = '';
        foreach ($issues as $x) {
            $list .= '<div class="eva-alert ' . ($x['level'] === 'bad' ? 'bad' : 'warn') . '" style="font-weight:400"><b><i class="fa-solid fa-triangle-exclamation"></i> ' . $e($x['title']) . '</b><div class="small" style="margin-top:4px"><b>Fix:</b> ' . $e($x['fix']) . '</div></div>';
        }
        $list = $list ?: '<div class="eva-alert ok"><i class="fa-solid fa-circle-check"></i> No problems found. Location should arrive every few seconds while the rider is on duty.</div>';
        $kv = '<dl class="kv">'
            . '<dt' . self::t('When the phone last sent a GPS location to the server.') . '>Last GPS received</dt><dd>' . $e($ago($r['last_point_at'] ?? null)) . '</dd>'
            . '<dt' . self::t('How precise the last GPS fix was. Under 30 m is good; indoors it is often 60-200 m.') . '>GPS accuracy</dt><dd>' . $accTxt . '</dd>'
            . '<dt' . self::t('Android location permission for the app. It must be "Allow all the time" or tracking stops when the screen locks.') . '>Location permission</dt><dd>' . $permTxt . '</dd>'
            . '<dt' . self::t('Whether Location (GPS) is switched on in the phone.') . '>GPS on phone</dt><dd>' . $yn($r['gps_on'] ?? null, 'On', 'Off') . '</dd>'
            . '<dt' . self::t('Whether battery optimisation is turned off for the app. If not, Android may stop the app in the background.') . '>Battery saver</dt><dd>' . $yn($r['battery_exempt'] ?? null, 'Not restricted', 'Restricted') . '</dd>'
            . '<dt' . self::t('Phone battery level from the last update.') . '>Battery</dt><dd>' . ($r['last_battery'] !== null ? (int)$r['last_battery'] . '%' : '—') . '</dd>'
            . '<dt' . self::t('Version of the Hitech Delivery app on the rider\'s phone. Latest is ' . RiderHealth::LATEST_APP . '.') . '>App version</dt><dd>' . $e($r['app_version'] ?? '—') . '</dd>'
            . '<dt' . self::t('Actions and GPS points saved on the phone that have not reached the server yet (no internet or weak signal).') . '>Waiting on phone</dt><dd>' . (int)($r['phone_pending'] ?? 0) . ' actions · ' . (int)($r['phone_gps_queue'] ?? 0) . ' GPS points</dd>'
            . '<dt' . self::t('When the app last checked in with the server (every minute while on duty).') . '>App last checked in</dt><dd>' . $e($ago($r['last_heartbeat_at'] ?? null)) . '</dd>'
            . '</dl>';
        return '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-heart-pulse"></i> App health' . self::tip('Checks the rider\'s phone for anything that stops live location: permission, GPS, battery saver, internet and app version. Updates every minute while the rider is on duty.') . '</h4>' . self::healthBadge($r) . '</div>' . $list . $kv . '</div>';
    }

    public static function liveLabel(string $s): string
    {
        return ['travelling' => 'Delivering', 'at_customer' => 'At customer', 'available' => 'Available', 'at_warehouse' => 'At store',
            'idle' => 'Idle', 'on_break' => 'On break', 'returning' => 'Returning', 'gps_offline' => 'GPS offline', 'off_duty' => 'Off duty'][$s] ?? $s;
    }
}
