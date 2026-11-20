<?php
declare(strict_types=1);
use HMD\{Db, Delivery, CsvImport, Ui, Auth, Audit, Fmt, Settings};

$u = Auth::user();
$id = (int)($_GET['id'] ?? 0);
$d = $id ? Delivery::get($id) : null;
if ($id && !$d) {
    Ui::flash('bad', 'Order not found.');
    Ui::redirect('?r=orders');
}
if ($d && !in_array($d['status'], ['ready', 'assigned'], true)) {
    Ui::flash('warn', 'This order is already with a rider or closed, so it cannot be edited.');
    Ui::redirect('?r=order&id=' . $id);
}
$v = $d ?? ['order_no' => '', 'customer_name' => '', 'customer_mobile' => '', 'address' => '', 'landmark' => '', 'pincode' => '', 'payment_type' => 'prepaid',
    'cod_amount' => '', 'order_value' => '', 'items_count' => 1, 'priority' => 'normal', 'notes' => '', 'is_cold_chain' => 0, 'cust_lat' => null, 'cust_lng' => null, 'sla_due_at' => null];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST;
    $raw['lat'] = $_POST['cust_lat'] ?? '';
    $raw['lng'] = $_POST['cust_lng'] ?? '';
    $raw['cold_chain'] = !empty($_POST['is_cold_chain']) ? '1' : '0';
    [$data, $errors] = CsvImport::row($raw);
    $dup = Db::val('SELECT delivery_id FROM hm_delivery WHERE order_no=? AND delivery_id<>?', [$data['order_no'], $id]);
    if ($dup) {
        $errors[] = 'Another order already uses this order number.';
    }
    $sla = (string)($_POST['sla_due'] ?? '');
    $slaUtc = null;
    if ($sla !== '') {
        try {
            $slaUtc = (new DateTimeImmutable($sla, new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $errors[] = 'Delivery deadline is not a valid date and time.';
        }
    }
    $data['sla_due_at'] = $slaUtc ?? ($d ? $d['sla_due_at'] : gmdate('Y-m-d H:i:s', time() + 3600 * max(1, Settings::int('default_sla_hours'))));
    if (!$errors) {
        if ($d) {
            $old = array_intersect_key($d, $data);
            Db::update('hm_delivery', $data + ['updated_at' => Fmt::nowUtc()], 'delivery_id=?', [$id]);
            Audit::log('order.edit', 'delivery', $id, $old, $data);
            Ui::flash('ok', 'Order updated.');
        } else {
            $id = Delivery::create($data, (int)$u['user_id']);
            Ui::flash('ok', "Order {$data['order_no']} added. Assign it to a rider from the Orders list.");
        }
        Ui::redirect(isset($_POST['add_another']) ? '?r=order_edit' : '?r=order&id=' . $id);
    }
    $v = array_merge($v, $data);
}
$e = [Ui::class, 'e'];
$slaVal = $v['sla_due_at'] ? (new DateTimeImmutable($v['sla_due_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d\TH:i') : '';
$wh = Db::one('SELECT lat, lng FROM hm_warehouse WHERE status=1 ORDER BY warehouse_id LIMIT 1');
$center = $v['cust_lat'] !== null ? [(float)$v['cust_lat'], (float)$v['cust_lng']] : (($wh && $wh['lat'] !== null) ? [(float)$wh['lat'], (float)$wh['lng']] : [28.4595, 77.0266]);
$err = $errors ? '<div class="eva-alert bad">Please fix: ' . $e(implode(' · ', $errors)) . '</div>' : '';
$fld = static fn($label, $html, $help = '', $req = false) => '<div class="eva-field"><label class="' . ($req ? 'req' : '') . '">' . $label . '</label><div>' . $html . ($help ? '<div class="help">' . $help . '</div>' : '') . '</div></div>';
$body = $err . '<form method="post" class="grid2"><div class="eva-card">' . Ui::csrfField()
    . '<div class="eva-h"><h4><i class="fa-solid fa-box"></i> Order details</h4></div>'
    . $fld('Order number', '<input type="text" name="order_no" maxlength="40" value="' . $e($v['order_no']) . '" required>', 'Your bill or invoice number. Letters, numbers and - / _ # only. Must be unique; it is what the rider scans.', true)
    . $fld('Customer name', '<input type="text" name="customer_name" maxlength="120" value="' . $e($v['customer_name']) . '" required>', '', true)
    . $fld('Customer mobile', '<input type="text" name="customer_mobile" maxlength="15" inputmode="tel" value="' . $e($v['customer_mobile']) . '" required>', '10-digit Indian mobile. OTP and delivery messages go here.', true)
    . $fld('Address', '<textarea name="address" maxlength="500" required>' . $e($v['address']) . '</textarea>', '', true)
    . $fld('Landmark', '<input type="text" name="landmark" maxlength="200" value="' . $e($v['landmark']) . '">')
    . $fld('Pincode', '<input type="text" name="pincode" maxlength="6" inputmode="numeric" value="' . $e($v['pincode']) . '">')
    . $fld('Payment', '<select name="payment_type">' . Ui::options(['prepaid' => 'Prepaid', 'cod' => 'Cash on delivery (COD)'], $v['payment_type']) . '</select>')
    . $fld('Order value (₹)', '<input type="number" step="0.01" min="0" name="order_value" value="' . $e($v['order_value']) . '">')
    . $fld('COD amount (₹)', '<input type="number" step="0.01" min="0" name="cod_amount" value="' . $e($v['cod_amount']) . '">', 'For COD orders. Leave empty to use the order value.')
    . $fld('Items', '<input type="number" min="1" max="999" name="items_count" value="' . $e($v['items_count']) . '">')
    . $fld('Priority', '<select name="priority">' . Ui::options(['normal' => 'Normal', 'priority' => 'Priority', 'urgent' => 'Urgent'], $v['priority']) . '</select>', 'Urgent and priority orders show first in the rider app.')
    . $fld('Keep cold', '<label><input type="checkbox" name="is_cold_chain" value="1"' . ($v['is_cold_chain'] ? ' checked' : '') . '> Cold-chain medicine (insulin, vaccines)</label>', 'Shown as a blue "Cold" tag to the rider.')
    . $fld('Deliver by', '<input type="datetime-local" name="sla_due" value="' . $e($slaVal) . '">', 'Leave empty to use the default of ' . Settings::int('default_sla_hours') . ' hours from now.')
    . $fld('Notes for rider', '<textarea name="notes" maxlength="500">' . $e($v['notes']) . '</textarea>', 'E.g. "Call before coming", "Collect old prescription".')
    . '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Save order</button>' . ($d ? '' : '<button class="eva-btn" name="add_another" value="1">Save and add another</button>') . '<a class="eva-btn" href="?r=orders">Cancel</a></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-location-dot"></i> Customer location (optional)</h4></div><p class="muted small">Click the map to drop a pin on the customer\'s door. This lets the app detect arrival and flag deliveries marked far away. If you skip it, the pin is learned from the first delivery.</p>'
    . '<div hidden style="margin-bottom:8px"><input class="inp" id="addr-search" placeholder="Search the address on Google Maps" style="max-width:none"' . Ui::t('Start typing an address or landmark and pick it from the list. The pin and empty address/pincode fields are filled in for you.') . '></div><div id="pmap" class="map sm"></div>' . Ui::mapNote() . '<div style="display:flex;gap:8px;margin-top:8px"><input class="inp" name="cust_lat" id="lat" placeholder="Latitude" value="' . $e($v['cust_lat']) . '"><input class="inp" name="cust_lng" id="lng" placeholder="Longitude" value="' . $e($v['cust_lng']) . '"><button type="button" class="eva-btn sm" id="clr">Clear</button></div></div></form>';
$js = '<script nonce="' . HMD_NONCE . '">HMMap.ready(function(){var m=HMMap.create("pmap",{center:[' . $center[0] . ',' . $center[1] . '],zoom:' . ($v['cust_lat'] !== null ? 16 : 12) . '}),mk=null,la=document.getElementById("lat"),ln=document.getElementById("lng");
function put(a,b,zoom){if(mk)mk.remove();mk=m.marker(a,b,{color:"#ec5b6f",size:10,title:"Customer location"});la.value=a.toFixed(7);ln.value=b.toFixed(7);if(zoom)m.view(a,b,17);}
if(la.value&&ln.value)put(parseFloat(la.value),parseFloat(ln.value));m.onClick(function(a,b){put(a,b);});
document.getElementById("clr").onclick=function(){if(mk)mk.remove();mk=null;la.value="";ln.value="";};
var addr=document.querySelector("textarea[name=address]"),srch=document.getElementById("addr-search");
if(HMMap.autocomplete(srch,function(p){put(p.lat,p.lng,true);if(!addr.value.trim())addr.value=p.address;var pc=document.querySelector("input[name=pincode]");if(p.pincode&&!pc.value)pc.value=p.pincode;})){srch.parentNode.hidden=false;}});</script>';
Ui::page($d ? 'Edit order ' . $d['order_no'] : 'Add order', 'orders', $body . $js, '', '', Ui::mapHead());
