<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Ui, Audit, Delivery, StateMachine};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ui::redirect('?r=riders');
}
$id = (int)($_POST['id'] ?? 0);
$r = Db::one('SELECT * FROM hm_rider WHERE rider_id=?', [$id]);
if (!$r) {
    Ui::flash('bad', 'Rider not found.');
    Ui::redirect('?r=riders');
}
$a = (string)($_POST['action'] ?? '');
$held = (int)Db::val('SELECT COUNT(*) FROM hm_delivery WHERE rider_id=? AND deleted_at IS NULL AND status IN (' . Db::in(array_merge(['assigned'], StateMachine::WITH_RIDER)) . ')', array_merge([$id], ['assigned'], StateMachine::WITH_RIDER));
switch ($a) {
    case 'reset_pin':
        $pin = (string)($_POST['pin'] ?? '');
        if (!preg_match('/^\d{4}$/', $pin) || preg_match('/^(\d)\1{3}$|^1234$|^4321$/', $pin)) {
            Ui::flash('bad', 'Enter a 4-digit PIN that is not easy to guess.');
            break;
        }
        Db::update('hm_rider', ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null], 'rider_id=?', [$id]);
        Db::run('UPDATE hm_rider_token SET revoked=1 WHERE rider_id=?', [$id]);
        Audit::log('rider.reset_pin', 'rider', $id);
        Ui::flash('ok', 'PIN changed. The rider must log in again with the new PIN.');
        break;
    case 'reset_device':
        Db::update('hm_rider', ['device_id' => null, 'failed_logins' => 0, 'locked_until' => null], 'rider_id=?', [$id]);
        Db::run('UPDATE hm_rider_token SET revoked=1 WHERE rider_id=?', [$id]);
        Audit::log('rider.reset_device', 'rider', $id, ['device_id' => $r['device_id']], null);
        Ui::flash('ok', 'Phone registration cleared. The rider can now log in on a new phone.');
        break;
    case 'disable':
        if ($held) {
            Ui::flash('bad', "{$r['name']} still has $held order(s). Reassign or receive them first.");
            break;
        }
        Db::update('hm_rider', ['status' => 0], 'rider_id=?', [$id]);
        Db::run('UPDATE hm_rider_token SET revoked=1 WHERE rider_id=?', [$id]);
        Audit::log('rider.disable', 'rider', $id);
        Ui::flash('ok', "{$r['name']} disabled. They can no longer log in.");
        break;
    case 'enable':
        Db::update('hm_rider', ['status' => 1], 'rider_id=?', [$id]);
        Audit::log('rider.enable', 'rider', $id);
        Ui::flash('ok', "{$r['name']} enabled.");
        break;
    case 'delete':
        if ($held) {
            Ui::flash('bad', "{$r['name']} still has $held order(s). Reassign or receive them first.");
            break;
        }
        Db::update('hm_rider', ['deleted_at' => Fmt::nowUtc(), 'status' => 0], 'rider_id=?', [$id]);
        Db::run('UPDATE hm_rider_token SET revoked=1 WHERE rider_id=?', [$id]);
        Audit::log('rider.delete', 'rider', $id, ['name' => $r['name']], null);
        Ui::flash('ok', "{$r['name']} moved to Deleted. History is kept and you can restore them from the Deleted tab.");
        Ui::redirect('?r=riders');
    case 'restore':
        if (Db::val('SELECT 1 FROM hm_rider WHERE mobile=? AND rider_id<>? AND deleted_at IS NULL', [$r['mobile'], $id])) {
            Ui::flash('bad', 'Another active rider now uses this mobile number.');
            break;
        }
        Db::update('hm_rider', ['deleted_at' => null, 'status' => 1], 'rider_id=?', [$id]);
        Audit::log('rider.restore', 'rider', $id);
        Ui::flash('ok', "{$r['name']} restored.");
        break;
    default:
        Ui::flash('bad', 'Unknown action.');
}
Ui::redirect('?r=rider&id=' . $id);
