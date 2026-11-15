<?php
declare(strict_types=1);
/** First-run setup wizard: database, first Super Admin, store location. */
use HMD\{App, Db, Install, Ui, Validate};

$err = '';
$ok = false;
$v = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => '', 'admin_name' => '', 'email' => '', 'password' => '', 'company' => 'Hitech Medicos'];
$storage = dirname(__DIR__, 2) . '/storage';
$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'GD image extension' => extension_loaded('gd'),
    'fileinfo extension' => extension_loaded('fileinfo'),
    'cURL extension' => extension_loaded('curl'),
    'storage/ folder writable' => is_writable($storage),
    'app/ folder writable (to save config.php)' => is_writable(dirname(__DIR__)),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    try {
        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Fix the server checks shown in red first.');
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid admin email.');
        }
        if ($p = Install::passwordProblem($v['password'])) {
            throw new RuntimeException($p);
        }
        $cfg = ['db' => ['host' => $v['host'], 'port' => (int)$v['port'], 'name' => $v['name'], 'user' => $v['user'], 'pass' => $v['pass']],
            'storage_path' => $storage, 'app_key' => bin2hex(random_bytes(32))];
        $pdo = Db::connect($cfg['db']);
        Db::set($pdo);
        App::setConfig($cfg);
        $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/install/schema.sql'));
        Install::seed($v['admin_name'] ?: 'Admin', $v['email'], $v['password']);
        \HMD\Settings::set('company_name', Validate::text($v['company'], 80) ?: 'Hitech Medicos');
        foreach (['uploads', 'logs', 'backups'] as $d) {
            if (!is_dir($storage . '/' . $d)) {
                mkdir($storage . '/' . $d, 0750, true);
            }
        }
        @file_put_contents($storage . '/.htaccess', "Require all denied\n");
        $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents(dirname(__DIR__) . '/config.php', $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write app/config.php.');
        }
        @chmod(dirname(__DIR__) . '/config.php', 0640);
        $ok = true;
    } catch (Throwable $e) {
        $err = $e instanceof PDOException ? 'Database connection failed: check host, name, user and password.' : $e->getMessage();
    }
}
$e = [Ui::class, 'e'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup · Hitech Medicos Delivery</title>
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/vendor/fa/css/all.min.css"><link rel="stylesheet" href="assets/eva.css"></head><body>
<div class="eva-wrap" style="max-width:820px"><div class="page-title"><div><h1><i class="fa-solid fa-truck-medical" style="color:#6b5cf0"></i> Hitech Medicos Delivery — Setup</h1><div class="sub">One-time setup. Takes about 2 minutes.</div></div></div>
<?php if ($ok): ?>
<div class="eva-alert ok">Setup complete. Next: add a cron job, set your store location, then add riders.</div>
<div class="eva-card"><ol class="help-list"><li>Plesk → Scheduled Tasks → run every 5 minutes: <code>php <?= $e(dirname(__DIR__, 2)) ?>/cron.php</code></li><li>Log in and open <b>Settings → Store location</b> to set the store's map pin.</li><li>Add riders under <b>Riders</b>, then add or import orders.</li></ol><a class="eva-btn primary" href="?r=login">Go to login</a></div>
<?php else: ?>
<?php if ($err): ?><div class="eva-alert bad"><?= $e($err) ?></div><?php endif; ?>
<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-server"></i> Server checks</h4></div>
<?php foreach ($checks as $k => $pass): ?><div><span class="eva-pill <?= $pass ? 'ok' : 'bad' ?>"><?= $pass ? 'OK' : 'Fix' ?></span> <?= $e($k) ?></div><?php endforeach; ?></div>
<form method="post" class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-database"></i> Database (create an empty MySQL database in Plesk first)</h4></div>
<div class="eva-field"><label class="req">Host</label><div><input type="text" name="host" value="<?= $e($v['host']) ?>"></div></div>
<div class="eva-field"><label>Port</label><div><input type="number" name="port" value="<?= $e($v['port']) ?>"></div></div>
<div class="eva-field"><label class="req">Database name</label><div><input type="text" name="name" value="<?= $e($v['name']) ?>" required></div></div>
<div class="eva-field"><label class="req">Database user</label><div><input type="text" name="user" value="<?= $e($v['user']) ?>" required></div></div>
<div class="eva-field"><label>Database password</label><div><input type="password" name="pass" value=""></div></div>
<div class="eva-h" style="margin-top:16px"><h4><i class="fa-solid fa-user-shield"></i> First Super Admin</h4></div>
<div class="eva-field"><label class="req">Company name</label><div><input type="text" name="company" value="<?= $e($v['company']) ?>"></div></div>
<div class="eva-field"><label>Your name</label><div><input type="text" name="admin_name" value="<?= $e($v['admin_name']) ?>"></div></div>
<div class="eva-field"><label class="req">Email</label><div><input type="email" name="email" value="<?= $e($v['email']) ?>" required></div></div>
<div class="eva-field"><label class="req">Password</label><div><input type="password" name="password" required><div class="help">At least 8 characters with letters and numbers.</div></div></div>
<div style="margin-top:14px"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Install</button></div></form>
<?php endif; ?></div></body></html>
