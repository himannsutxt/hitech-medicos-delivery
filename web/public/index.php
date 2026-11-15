<?php
declare(strict_types=1);
/** Admin website front controller: index.php?r=<page> */
require dirname(__DIR__) . '/app/lib/autoload.php';

use HMD\{App, Auth, Perm, Ui, Db};

date_default_timezone_set('UTC');
$nonce = base64_encode(random_bytes(16));
define('HMD_NONCE', $nonce);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: https://*.tile.openstreetmap.org; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'");
App::installErrorHandlers();

if (!App::installed()) {
    require dirname(__DIR__) . '/app/pages/install.php';
    exit;
}
Auth::startSession();

$routes = [
    // route => [file, permission (null = any logged-in user), allow GET?]
    'login' => ['login.php', false],
    'logout' => ['logout.php', null],
    'dashboard' => ['dashboard.php', 'dashboard.view'],
    'live.json' => ['live_json.php', 'dashboard.view'],
    'orders' => ['orders.php', 'orders.view'],
    'order' => ['order_view.php', 'orders.view'],
    'order_edit' => ['order_edit.php', 'orders.edit'],
    'order_bulk' => ['order_bulk.php', 'orders.view'],
    'import' => ['import.php', 'orders.edit'],
    'scan' => ['scan.php', 'orders.scan'],
    'riders' => ['riders.php', 'riders.view'],
    'rider' => ['rider_view.php', 'riders.view'],
    'rider_edit' => ['rider_edit.php', 'riders.edit'],
    'rider_action' => ['rider_action.php', 'riders.edit'],
    'cod' => ['cod.php', 'cod.view'],
    'cod_action' => ['cod_action.php', 'cod.settle'],
    'reports' => ['reports.php', 'reports.view'],
    'exceptions' => ['exceptions.php', 'exceptions.view'],
    'settings' => ['settings.php', 'settings.edit'],
    'users' => ['users.php', 'users.edit'],
    'audit' => ['audit.php', 'audit.view'],
    'media' => ['media.php', 'orders.view'],
    'preset_save' => ['preset.php', null],
    'preset_delete' => ['preset.php', null],
    'help' => ['help.php', null],
];
$r = (string)($_GET['r'] ?? 'dashboard');
if (!isset($routes[$r])) {
    http_response_code(404);
    $r = 'dashboard';
}
[$file, $perm] = $routes[$r];

if ($perm !== false && !Auth::user()) {
    if ($r === 'live.json') {
        Ui::json(['ok' => false, 'error' => 'login'], 401);
    }
    Ui::redirect('?r=login');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::checkCsrf($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    if (Auth::user()) {
        Ui::flash('bad', 'Your session form expired. Please try again.');
        Ui::redirect('?r=' . urlencode($r === 'logout' ? 'dashboard' : 'dashboard'));
    }
    Ui::redirect('?r=login&expired=1');
}
if (is_string($perm) && !Perm::has(Auth::user(), $perm)) {
    http_response_code(403);
    Ui::page('No access', '', '<div class="eva-card"><div class="eva-empty"><i class="fa-solid fa-lock" style="font-size:26px"></i><p>You do not have permission to open this page. Ask a Super Admin to change your role.</p><a class="eva-btn" href="?r=help">Help</a></div></div>');
    exit;
}
require dirname(__DIR__) . '/app/pages/' . $file;
