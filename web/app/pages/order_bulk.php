<?php
declare(strict_types=1);
use HMD\{Db, Delivery, Fmt, Perm, StateMachine, Ui, Auth, Audit, Validate};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ui::redirect('?r=orders');
}
$u = Auth::user();
$back = (string)($_POST['back'] ?? '?r=orders');
if (!str_starts_with($back, '?r=orders')) {
    $back = '?r=orders';
}
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
$ids = array_slice($ids, 0, 500);
$action = (string)($_POST['action'] ?? '');
if (!$ids) {
    Ui::flash('bad', 'Select at least one order.');
    Ui::redirect($back);
}
switch ($action) {
    case 'assign':
        if (!Perm::has($u, 'orders.assign')) {
            break;
        }
        $rid = (int)($_POST['rider_id'] ?? 0);
        $reason = Validate::text((string)($_POST['reason'] ?? ''), 200);
        if (!$rid) {
            Ui::flash('bad', 'Choose a rider to assign to.');
            Ui::redirect($back);
        }
        $re = (int)Db::val("SELECT COUNT(*) FROM hm_delivery WHERE delivery_id IN (" . Db::in($ids) . ") AND status='assigned' AND rider_id<>?", array_merge($ids, [$rid]));
        if ($re > 0 && mb_strlen($reason) < 3) {
            Ui::flash('bad', "$re of these orders already have another rider. Write a reason for reassigning.");
            Ui::redirect($back);
        }
        $rider = Db::one('SELECT name, capacity FROM hm_rider WHERE rider_id=?', [$rid]);
        [$n, $errs] = Delivery::assign($ids, $rid, (int)$u['user_id'], $reason);
        $load = Delivery::activeLoad($rid);
        if ($n) {
            Ui::flash('ok', "$n order(s) assigned to {$rider['name']}.");
        }
        if ($rider && $load > (int)$rider['capacity']) {
            Ui::flash('warn', "{$rider['name']} now has $load active orders, above the capacity of {$rider['capacity']}.");
        }
        foreach (array_slice($errs, 0, 5) as $e) {
            Ui::flash('bad', $e);
        }
        Ui::redirect($back);
    case 'unassign':
        if (!Perm::has($u, 'orders.assign')) {
            break;
        }
        $n = 0;
        foreach ($ids as $id) {
            $d = Delivery::get($id);
            if ($d && $d['status'] === 'assigned') {
                Delivery::move($d, 'ready', 'admin', (int)$u['user_id'], ['rider_id' => null]);
                Audit::log('order.unassign', 'delivery', $id, ['rider_id' => $d['rider_id']], null);
                $n++;
            }
        }
        Ui::flash($n ? 'ok' : 'warn', $n ? "$n order(s) moved back to Ready." : 'Only Assigned (not yet scanned) orders can be unassigned.');
        Ui::redirect($back);
    case 'cancel':
        if (!Perm::has($u, 'orders.cancel')) {
            break;
        }
        $n = 0;
        $skip = [];
        foreach ($ids as $id) {
            $d = Delivery::get($id);
            if ($d && StateMachine::can($d['status'], 'cancelled')) {
                Delivery::move($d, 'cancelled', 'admin', (int)$u['user_id']);
                Audit::log('order.cancel', 'delivery', $id, ['status' => $d['status']], ['status' => 'cancelled']);
                $n++;
            } elseif ($d) {
                $skip[] = $d['order_no'];
            }
        }
        Ui::flash('ok', "$n order(s) cancelled.");
        if ($skip) {
            Ui::flash('warn', 'Not cancelled (already out or closed): ' . implode(', ', array_slice($skip, 0, 10)));
        }
        Ui::redirect($back);
    case 'export':
        $rows = Db::all('SELECT d.order_no, d.customer_name, d.customer_mobile, d.address, d.pincode, d.payment_type, d.cod_amount, d.status, r.name, d.created_at FROM hm_delivery d LEFT JOIN hm_rider r ON r.rider_id=d.rider_id WHERE d.delivery_id IN (' . Db::in($ids) . ') ORDER BY d.order_no', $ids);
        foreach ($rows as &$r) {
            $r['status'] = StateMachine::label($r['status']);
            $r['created_at'] = Fmt::dt($r['created_at']);
        }
        unset($r);
        Ui::csv('selected_orders.csv', ['Order no', 'Customer', 'Mobile', 'Address', 'Pincode', 'Payment', 'COD amount', 'Status', 'Rider', 'Created (IST)'], $rows);
}
Ui::flash('bad', 'You do not have permission for that action.');
Ui::redirect($back);
