<?php
declare(strict_types=1);
use HMD\{Cod, Ui, Auth, Perm, Validate};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ui::redirect('?r=cod');
}
$u = Auth::user();
$a = (string)($_POST['action'] ?? '');
if ($a === 'settle') {
    $amt = Validate::money($_POST['received'] ?? '');
    if ($amt === null) {
        Ui::flash('bad', 'Enter the cash amount received.');
    } else {
        [$ok, $msg] = Cod::settle((int)($_POST['id'] ?? 0), $amt, Validate::text((string)($_POST['remark'] ?? ''), 200), (int)$u['user_id']);
        Ui::flash($ok ? 'ok' : 'bad', $msg);
    }
} elseif ($a === 'recover' || $a === 'writeoff') {
    if ($a === 'writeoff' && !Perm::has($u, 'cod.writeoff')) {
        Ui::flash('bad', 'Only users with write-off permission can do this.');
    } else {
        $amt = Validate::money($_POST['amount'] ?? '');
        [$ok, $msg] = $amt === null ? [false, 'Enter an amount.'] : Cod::adjust((int)($_POST['rider_id'] ?? 0), $a, $amt, Validate::text((string)($_POST['note'] ?? ''), 100), (int)$u['user_id']);
        Ui::flash($ok ? 'ok' : 'bad', $msg);
    }
}
Ui::redirect('?r=cod');
