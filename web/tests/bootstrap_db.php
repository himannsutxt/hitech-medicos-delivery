<?php
declare(strict_types=1);
define('HMD_TESTING', true);
require_once __DIR__ . '/../app/lib/autoload.php';

use HMD\App;
use HMD\Db;

function hmd_test_reset(): void
{
    $cfg = ['db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'hmd_test', 'user' => 'hmd', 'pass' => 'hmdpass'],
        'storage_path' => sys_get_temp_dir() . '/hmd_storage', 'app_key' => 'test'];
    @mkdir($cfg['storage_path'] . '/logs', 0777, true);
    @mkdir($cfg['storage_path'] . '/uploads', 0777, true);
    App::setConfig($cfg);
    $pdo = Db::connect($cfg['db']);
    Db::set($pdo);
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    $pdo->exec(file_get_contents(__DIR__ . '/../install/schema.sql'));
    \HMD\Settings::reset();
    \HMD\Install::seed('Admin', 'admin@hitechmedicos.in', 'Admin@12345');
}
