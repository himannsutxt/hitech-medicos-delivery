<?php
declare(strict_types=1);
use HMD\{App, Db, Audit};

$id = (int)($_GET['id'] ?? 0);
$m = Db::one('SELECT * FROM hm_media WHERE media_id=?', [$id]);
$path = $m && $m['file_path'] !== '' ? realpath(App::storage('uploads/' . $m['file_path'])) : false;
$base = realpath(App::storage('uploads'));
if (!$m || !$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Photo not found or removed by the retention policy.');
}
header('Content-Type: ' . (in_array($m['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $m['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=600');
header("Content-Security-Policy: default-src 'none'");
readfile($path);
exit;
