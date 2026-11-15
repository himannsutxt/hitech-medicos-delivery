<?php
declare(strict_types=1);
/** Rider app API entry point: /api.php?p=<path> (or /api.php/<path>). */
require dirname(__DIR__) . '/app/lib/autoload.php';

use HMD\App;
use HMD\RiderApi;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
App::installErrorHandlers(true);

if (!App::installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_installed', 'message' => 'Server setup is not finished.']);
    exit;
}
date_default_timezone_set('UTC');

$path = (string)($_GET['p'] ?? ($_SERVER['PATH_INFO'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
$body = [];
if (str_starts_with($ctype, 'application/json')) {
    $raw = file_get_contents('php://input', false, null, 0, 2_000_001);
    if (strlen((string)$raw) > 2_000_000) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'too_large']);
        exit;
    }
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        $body = [];
    }
} else {
    $body = $_POST;
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$bearer = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? null);
if (isset($_SERVER['HTTP_X_DEVICE_ID']) && !isset($body['device_id'])) {
    $body['device_id'] = (string)$_SERVER['HTTP_X_DEVICE_ID'];
}

$res = (new RiderApi($body, $_FILES))->handle($method, $path, $bearer);
$http = $res['_http'] ?? 200;
unset($res['_http']);
http_response_code($http);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
