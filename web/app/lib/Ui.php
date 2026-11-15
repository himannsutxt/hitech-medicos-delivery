<?php
declare(strict_types=1);
namespace HMD;

/** Shared HTML helpers for the admin website (Eva UI). */
final class Ui
{
    public const NAV = [
        ['dashboard', 'Dashboard', 'fa-gauge-high', 'dashboard.view'],
        ['orders', 'Orders', 'fa-box', 'orders.view'],
        ['scan', 'Warehouse Scan', 'fa-barcode', 'orders.scan'],
        ['riders', 'Riders', 'fa-motorcycle', 'riders.view'],
        ['cod', 'COD', 'fa-indian-rupee-sign', 'cod.view'],
        ['reports', 'Reports', 'fa-chart-column', 'reports.view'],
        ['exceptions', 'Exceptions', 'fa-triangle-exclamation', 'exceptions.view'],
        ['settings', 'Settings', 'fa-gear', 'settings.edit'],
        ['users', 'Users', 'fa-user-shield', 'users.edit'],
        ['audit', 'Audit & Health', 'fa-clipboard-list', 'audit.view'],
        ['help', 'Help', 'fa-circle-question', null],
    ];

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            if ($perm !== null && !Perm::has($u, $perm)) {
                continue;
            }
            $badge = ($key === 'exceptions' && $open > 0) ? ' <span class="badge">' . $open . '</span>' : '';
            $nav .= '<a href="?r=' . $key . '" class="' . ($active === $key ? 'active' : '') . '"><i class="fa-solid ' . $icon . '"></i> ' . self::e($label) . $badge . '</a>';
        }
        $company = self::e(Settings::get('company_name'));
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . ' · ' . $company . ' Delivery</title>'
            . '<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/vendor/fa/css/all.min.css">'
            . '<link rel="stylesheet" href="assets/eva.css?v=' . App::VERSION . '">' . $head . '</head><body>'
            . '<header class="hm-top"><a class="hm-brand" href="?r=dashboard"><span class="logo"><i class="fa-solid fa-truck-medical"></i></span><span>' . $company . ' Delivery<small>Rider tracking & dispatch</small></span></a>'
            . '<div class="hm-user"><i class="fa-regular fa-user"></i> <span class="name"><b>' . self::e($u['name'] ?? '') . '</b> · ' . self::e($u['role_name'] ?? '') . '</span>'
            . '<form method="post" action="?r=logout" style="margin:0">' . self::csrfField() . '<button class="eva-btn sm"><i class="fa-solid fa-right-from-bracket"></i> Log out</button></form></div></header>'
            . '<div class="eva-wrap"><nav class="eva-nav">' . $nav . '<span class="ver">v' . App::VERSION . '</span></nav>'
            . '<div class="page-title"><div><h1>' . self::e($title) . '</h1>' . ($sub !== '' ? '<div class="sub">' . self::e($sub) . '</div>' : '') . '</div><div>' . $actions . '</div></div>'
            . self::flash() . $body . '</div><script src="assets/app.js?v=' . App::VERSION . '"></script></body></html>';
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

    public static function leafletHead(): string
    {
        return '<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">'
            . '<script src="assets/vendor/leaflet/leaflet.js"></script>';
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

    public static function liveLabel(string $s): string
    {
        return ['travelling' => 'Delivering', 'at_customer' => 'At customer', 'available' => 'Available', 'at_warehouse' => 'At store',
            'idle' => 'Idle', 'on_break' => 'On break', 'returning' => 'Returning', 'gps_offline' => 'GPS offline', 'off_duty' => 'Off duty'][$s] ?? $s;
    }
}
