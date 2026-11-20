<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Ui, Validate, Audit, Settings, Riders};

$id = (int)($_GET['id'] ?? 0);
$r = $id ? Db::one('SELECT * FROM hm_rider WHERE rider_id=? AND deleted_at IS NULL', [$id]) : null;
if ($id && !$r) {
    Ui::flash('bad', 'Rider not found.');
    Ui::redirect('?r=riders');
}
$v = $r ?? ['name' => '', 'mobile' => ''];
$errors = [];
$weakPin = static fn(string $p): bool => (bool)preg_match('/^(\d)\1{3}$|^1234$|^4321$|^0000$|^1212$/', $p);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = Validate::text($_POST['name'] ?? '', 100);
    $mobile = Validate::mobile($_POST['mobile'] ?? '');
    if ($name === '') {
        $errors[] = 'Enter the rider\'s name.';
    }
    if (!$mobile) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    } elseif (Db::val('SELECT rider_id FROM hm_rider WHERE mobile=? AND rider_id<>?', [$mobile, $id])) {
        $errors[] = 'Another rider already uses this mobile number.';
    }

    if (!$r) {
        // ---- Add: only name, mobile, PIN. Everything else gets defaults. ----
        $pin = (string)($_POST['pin'] ?? '');
        if (!preg_match('/^\d{4}$/', $pin)) {
            $errors[] = 'Set a 4-digit PIN.';
        } elseif ($weakPin($pin)) {
            $errors[] = 'PIN is too easy to guess. Avoid 1111, 1234 and similar.';
        }
        if (!$errors) {
            $wh = Db::val('SELECT warehouse_id FROM hm_warehouse WHERE status=1 ORDER BY warehouse_id LIMIT 1');
            $row = ['employee_code' => Riders::generateCode(), 'name' => $name, 'mobile' => $mobile, 'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
                'warehouse_id' => $wh !== null ? (int)$wh : null, 'capacity' => 30, 'cod_limit' => (float)Settings::get('cod_limit_default'),
                'status' => 1, 'joined_on' => Fmt::todayIst(), 'created_at' => Fmt::nowUtc()];
            $id = Db::insert('hm_rider', $row);
            unset($row['pin_hash']);
            Audit::log('rider.create', 'rider', $id, null, $row);
            Ui::flash('ok', "Rider {$name} added ({$row['employee_code']}). They log in to the app with mobile {$mobile} and the PIN you set.");
            Ui::redirect(isset($_POST['add_another']) ? '?r=rider_edit' : '?r=rider&id=' . $id);
        }
        $v = ['name' => $name, 'mobile' => (string)($_POST['mobile'] ?? '')];
    } else {
        // ---- Edit: name, mobile + optional "More details" ----
        $in = [
            'name' => $name, 'mobile' => $mobile,
            'emergency_mobile' => ($_POST['emergency_mobile'] ?? '') === '' ? null : Validate::mobile($_POST['emergency_mobile']),
            'warehouse_id' => ctype_digit((string)($_POST['warehouse_id'] ?? '')) ? (int)$_POST['warehouse_id'] : null,
            'vehicle_type' => Validate::text($_POST['vehicle_type'] ?? '', 30) ?: null,
            'vehicle_number' => strtoupper(preg_replace('/\s+/', '', Validate::text($_POST['vehicle_number'] ?? '', 20))) ?: null,
            'licence_number' => strtoupper(Validate::text($_POST['licence_number'] ?? '', 30)) ?: null,
            'licence_expiry' => Validate::date($_POST['licence_expiry'] ?? '') ? $_POST['licence_expiry'] : null,
            'capacity' => max(1, min(200, (int)($_POST['capacity'] ?? 30))),
            'cod_limit' => Validate::money($_POST['cod_limit'] ?? '') ?? (float)$r['cod_limit'],
            'joined_on' => Validate::date($_POST['joined_on'] ?? '') ? $_POST['joined_on'] : null,
        ];
        if (($_POST['emergency_mobile'] ?? '') !== '' && !$in['emergency_mobile']) {
            $errors[] = 'Emergency mobile is not valid.';
        }
        if (!$errors) {
            $old = array_intersect_key($r, $in);
            Db::update('hm_rider', $in, 'rider_id=?', [$id]);
            Audit::log('rider.edit', 'rider', $id, $old, $in);
            Ui::flash('ok', 'Rider updated.');
            Ui::redirect('?r=rider&id=' . $id);
        }
        $v = array_merge($r, $in, ['mobile' => (string)($_POST['mobile'] ?? '')]);
    }
}

$e = [Ui::class, 'e'];
$fld = static fn(string $label, string $html, string $tip, bool $req = false) => '<div class="eva-field"><label class="' . ($req ? 'req' : '') . '">' . $label . Ui::tip($tip) . '</label><div>' . $html . '</div></div>';
$err = $errors ? '<div class="eva-alert bad">Please fix: ' . $e(implode(' · ', $errors)) . '</div>' : '';

if (!$r) {
    $body = $err . '<form method="post" class="eva-card" style="max-width:720px">' . Ui::csrfField()
        . '<div class="eva-h"><h4><i class="fa-solid fa-user-plus"></i> New rider</h4></div>'
        . $fld('Name', '<input type="text" name="name" value="' . $e($v['name']) . '" required autofocus>', 'Rider\'s full name, as you want to see it on the dashboard and reports.', true)
        . $fld('Mobile', '<input type="text" name="mobile" inputmode="tel" maxlength="14" value="' . $e($v['mobile']) . '" required placeholder="10-digit mobile">', 'The rider logs in to the app with this number. Must be unique.', true)
        . $fld('PIN', '<input type="password" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" required autocomplete="new-password" placeholder="4 digits">', '4-digit PIN for the app. Share it with the rider privately. You can change it later from the rider\'s page.', true)
        . '<p class="muted small" style="margin:12px 0 0">An employee code (like DEL001) is created automatically. Vehicle, licence and limits can be added later from Edit.</p>'
        . '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="eva-btn primary"' . Ui::t('Save the rider and open their page') . '><i class="fa-solid fa-check"></i> Add rider</button>'
        . '<button class="eva-btn" name="add_another" value="1"' . Ui::t('Save and open a blank form for the next rider') . '>Add and add another</button>'
        . '<a class="eva-btn" href="?r=riders"' . Ui::t('Go back without saving') . '>Cancel</a></div></form>';
    Ui::page('Add rider', 'riders', $body);
    return;
}

$wh = [];
foreach (Db::all('SELECT warehouse_id, name FROM hm_warehouse WHERE status=1') as $w) {
    $wh[$w['warehouse_id']] = $w['name'];
}
$body = $err . '<form method="post" class="eva-card" style="max-width:820px">' . Ui::csrfField()
    . '<div class="eva-h"><h4><i class="fa-solid fa-id-card"></i> ' . $e($r['employee_code']) . '</h4></div>'
    . $fld('Name', '<input type="text" name="name" value="' . $e($v['name']) . '" required>', 'Rider\'s full name.', true)
    . $fld('Mobile', '<input type="text" name="mobile" inputmode="tel" maxlength="14" value="' . $e($v['mobile']) . '" required>', 'Login number for the app. Changing it means the rider logs in with the new number.', true)
    . '<details class="more"' . (($r['vehicle_number'] || $r['licence_number'] || $r['emergency_mobile']) ? ' open' : '') . '><summary>More details (optional)</summary>'
    . $fld('Emergency mobile', '<input type="text" name="emergency_mobile" inputmode="tel" value="' . $e($v['emergency_mobile']) . '">', 'Family or friend to call in an emergency.')
    . $fld('Store', '<select name="warehouse_id">' . Ui::options($wh, $v['warehouse_id'] !== null ? (string)$v['warehouse_id'] : null, 'Default store') . '</select>', 'Duty start is checked against this store\'s location.')
    . $fld('Vehicle type', '<select name="vehicle_type">' . Ui::options(['' => '—', 'Bike' => 'Bike', 'Scooter' => 'Scooter', 'E-bike' => 'E-bike', 'Cycle' => 'Cycle', 'Van' => 'Van'], (string)$v['vehicle_type']) . '</select>', 'What the rider uses for deliveries.')
    . $fld('Vehicle number', '<input type="text" name="vehicle_number" value="' . $e($v['vehicle_number']) . '" placeholder="HR26AB1234">', 'Registration number, without spaces.')
    . $fld('Driving licence no.', '<input type="text" name="licence_number" value="' . $e($v['licence_number']) . '">', 'For your records.')
    . $fld('Licence expiry', '<input type="date" name="licence_expiry" value="' . $e($v['licence_expiry']) . '">', 'The rider list warns 30 days before this date.')
    . $fld('Joined on', '<input type="date" name="joined_on" value="' . $e($v['joined_on']) . '">', 'Date the rider started.')
    . $fld('Max orders at a time', '<input type="number" name="capacity" min="1" max="200" value="' . $e($v['capacity']) . '">', 'You get a warning when assigning more active orders than this. Default 30.')
    . $fld('Cash-in-hand limit (₹)', '<input type="number" name="cod_limit" min="0" step="100" value="' . $e($v['cod_limit']) . '">', 'An exception is raised when the rider carries more COD cash than this.')
    . '</details>'
    . '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><button class="eva-btn primary"' . Ui::t('Save changes') . '><i class="fa-solid fa-check"></i> Save</button><a class="eva-btn" href="?r=rider&id=' . $id . '"' . Ui::t('Go back without saving') . '>Cancel</a></div>'
    . '<p class="muted small" style="margin-top:10px">To change the PIN or allow a new phone, use the buttons on the rider\'s page.</p></form>';
Ui::page('Edit rider: ' . $r['name'], 'riders', $body);
