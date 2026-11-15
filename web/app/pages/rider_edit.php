<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Ui, Validate, Audit, Settings};

$id = (int)($_GET['id'] ?? 0);
$r = $id ? Db::one('SELECT * FROM hm_rider WHERE rider_id=? AND deleted_at IS NULL', [$id]) : null;
if ($id && !$r) {
    Ui::flash('bad', 'Rider not found.');
    Ui::redirect('?r=riders');
}
$v = $r ?? ['employee_code' => '', 'name' => '', 'mobile' => '', 'emergency_mobile' => '', 'warehouse_id' => null, 'vehicle_type' => 'Bike', 'vehicle_number' => '',
    'licence_number' => '', 'licence_expiry' => '', 'capacity' => 30, 'cod_limit' => Settings::get('cod_limit_default'), 'joined_on' => ''];
$errors = [];
$newPin = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = [
        'employee_code' => strtoupper(Validate::text($_POST['employee_code'] ?? '', 30)),
        'name' => Validate::text($_POST['name'] ?? '', 100),
        'mobile' => Validate::mobile($_POST['mobile'] ?? ''),
        'emergency_mobile' => ($_POST['emergency_mobile'] ?? '') === '' ? null : Validate::mobile($_POST['emergency_mobile']),
        'warehouse_id' => ctype_digit((string)($_POST['warehouse_id'] ?? '')) ? (int)$_POST['warehouse_id'] : null,
        'vehicle_type' => Validate::text($_POST['vehicle_type'] ?? '', 30),
        'vehicle_number' => strtoupper(preg_replace('/\s+/', '', Validate::text($_POST['vehicle_number'] ?? '', 20))),
        'licence_number' => strtoupper(Validate::text($_POST['licence_number'] ?? '', 30)),
        'licence_expiry' => Validate::date($_POST['licence_expiry'] ?? '') ? $_POST['licence_expiry'] : null,
        'capacity' => max(1, min(200, (int)($_POST['capacity'] ?? 30))),
        'cod_limit' => Validate::money($_POST['cod_limit'] ?? '') ?? 50000,
        'joined_on' => Validate::date($_POST['joined_on'] ?? '') ? $_POST['joined_on'] : null,
    ];
    if (!preg_match('/^[A-Z0-9\-]{2,30}$/', $in['employee_code'])) {
        $errors[] = 'Employee code: 2–30 letters, numbers or dashes (e.g. DEL001).';
    }
    if ($in['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if (!$in['mobile']) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    }
    if (($_POST['emergency_mobile'] ?? '') !== '' && !$in['emergency_mobile']) {
        $errors[] = 'Emergency mobile is not valid.';
    }
    if ($in['mobile'] && Db::val('SELECT rider_id FROM hm_rider WHERE mobile=? AND rider_id<>?', [$in['mobile'], $id])) {
        $errors[] = 'Another rider already uses this mobile number.';
    }
    if (Db::val('SELECT rider_id FROM hm_rider WHERE employee_code=? AND rider_id<>?', [$in['employee_code'], $id])) {
        $errors[] = 'Another rider already uses this employee code.';
    }
    $pin = (string)($_POST['pin'] ?? '');
    if (!$r && !preg_match('/^\d{4}$/', $pin)) {
        $errors[] = 'Set a 4-digit PIN for the rider.';
    }
    if ($pin !== '' && preg_match('/^(\d)\1{3}$|^1234$|^4321$/', $pin)) {
        $errors[] = 'PIN is too easy to guess. Avoid 1111, 1234 and similar.';
    }
    if (!$errors) {
        if ($r) {
            $old = array_intersect_key($r, $in);
            Db::update('hm_rider', $in, 'rider_id=?', [$id]);
            Audit::log('rider.edit', 'rider', $id, $old, $in);
            Ui::flash('ok', 'Rider updated.');
        } else {
            $id = Db::insert('hm_rider', $in + ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'status' => 1, 'created_at' => Fmt::nowUtc()]);
            Audit::log('rider.create', 'rider', $id, null, $in);
            Ui::flash('ok', "Rider added. Tell {$in['name']} to install the app and log in with mobile {$in['mobile']} and the PIN you set.");
        }
        Ui::redirect('?r=rider&id=' . $id);
    }
    $v = array_merge($v, $in, ['mobile' => $_POST['mobile'] ?? '']);
}
$e = [Ui::class, 'e'];
$wh = [];
foreach (Db::all('SELECT warehouse_id, name FROM hm_warehouse WHERE status=1') as $w) {
    $wh[$w['warehouse_id']] = $w['name'];
}
$fld = static fn($label, $html, $help = '', $req = false) => '<div class="eva-field"><label class="' . ($req ? 'req' : '') . '">' . $label . '</label><div>' . $html . ($help ? '<div class="help">' . $help . '</div>' : '') . '</div></div>';
$body = ($errors ? '<div class="eva-alert bad">Please fix: ' . $e(implode(' · ', $errors)) . '</div>' : '') . '<form method="post" class="eva-card">' . Ui::csrfField()
    . '<div class="eva-h"><h4><i class="fa-solid fa-id-card"></i> Rider</h4></div>'
    . $fld('Employee code', '<input type="text" name="employee_code" value="' . $e($v['employee_code']) . '" required>', 'Use the same code as in HRMS, e.g. DEL001.', true)
    . $fld('Full name', '<input type="text" name="name" value="' . $e($v['name']) . '" required>', '', true)
    . $fld('Mobile', '<input type="text" name="mobile" inputmode="tel" value="' . $e($v['mobile']) . '" required>', 'The rider logs in to the app with this number.', true)
    . ($r ? '' : $fld('App PIN', '<input type="password" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" required>', '4 digits. Share it with the rider privately. You can reset it later.', true))
    . $fld('Emergency mobile', '<input type="text" name="emergency_mobile" inputmode="tel" value="' . $e($v['emergency_mobile']) . '">')
    . $fld('Store / warehouse', '<select name="warehouse_id">' . Ui::options($wh, $v['warehouse_id'] !== null ? (string)$v['warehouse_id'] : null, 'Default store') . '</select>', 'Duty start is checked against this location.')
    . '<div class="eva-h" style="margin-top:16px"><h4><i class="fa-solid fa-motorcycle"></i> Vehicle and documents</h4></div>'
    . $fld('Vehicle type', '<select name="vehicle_type">' . Ui::options(['Bike' => 'Bike', 'Scooter' => 'Scooter', 'E-bike' => 'E-bike', 'Cycle' => 'Cycle', 'Van' => 'Van'], $v['vehicle_type']) . '</select>')
    . $fld('Vehicle number', '<input type="text" name="vehicle_number" value="' . $e($v['vehicle_number']) . '" placeholder="HR26AB1234">')
    . $fld('Driving licence no.', '<input type="text" name="licence_number" value="' . $e($v['licence_number']) . '">')
    . $fld('Licence expiry', '<input type="date" name="licence_expiry" value="' . $e($v['licence_expiry']) . '">', 'The rider list warns 30 days before expiry.')
    . $fld('Joined on', '<input type="date" name="joined_on" value="' . $e($v['joined_on']) . '">')
    . '<div class="eva-h" style="margin-top:16px"><h4><i class="fa-solid fa-sliders"></i> Limits</h4></div>'
    . $fld('Max orders at a time', '<input type="number" name="capacity" min="1" max="200" value="' . $e($v['capacity']) . '">', 'You get a warning when assigning more than this.')
    . $fld('Cash-in-hand limit (₹)', '<input type="number" name="cod_limit" min="0" step="100" value="' . $e($v['cod_limit']) . '">', 'An exception is raised when the rider carries more COD cash than this.')
    . '<div style="margin-top:14px"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Save rider</button> <a class="eva-btn" href="?r=riders">Cancel</a></div></form>';
Ui::page($r ? 'Edit rider: ' . $r['name'] : 'Add rider', 'riders', $body);
