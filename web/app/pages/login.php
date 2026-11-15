<?php
declare(strict_types=1);
use HMD\{Auth, Ui, Settings};

if (Auth::user()) {
    Ui::redirect('?r=dashboard');
}
$err = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $err = Auth::login($email, (string)($_POST['password'] ?? '')) ?? '';
    if ($err === '') {
        Ui::redirect('?r=dashboard');
    }
}
$company = Ui::e(Settings::get('company_name'));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log in · <?= $company ?> Delivery</title>
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/vendor/fa/css/all.min.css"><link rel="stylesheet" href="assets/eva.css"></head>
<body class="login-page"><form class="login-card" method="post" action="?r=login" autocomplete="on">
<div class="hm-brand"><span class="logo"><i class="fa-solid fa-truck-medical"></i></span><span><?= $company ?> Delivery<small>Admin panel</small></span></div>
<h2>Log in</h2><p>Use your staff email and password.</p>
<?php if (isset($_GET['expired'])): ?><div class="eva-alert warn">Your session expired. Please log in again.</div><?php endif; ?>
<?php if ($err): ?><div class="eva-alert bad"><?= Ui::e($err) ?></div><?php endif; ?>
<?= Ui::csrfField() ?>
<input type="email" name="email" placeholder="Email" value="<?= Ui::e($email) ?>" required autofocus>
<input type="password" name="password" placeholder="Password" required>
<button class="eva-btn primary"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
<p class="small" style="margin-top:14px">Forgot password? Ask your Super Admin to reset it under Users.</p>
</form></body></html>
