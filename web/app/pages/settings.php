<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Settings, Ui, Audit, Notify, Validate};

$tabs = ['general' => 'General', 'store' => 'Store location', 'tracking' => 'GPS & tracking', 'delivery' => 'Delivery & proof', 'cod' => 'COD', 'notify' => 'SMS / WhatsApp', 'data' => 'Data & backup'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
$e = [Ui::class, 'e'];
// field definitions: key => [label, type, help, extra]
$defs = [
    'general' => [
        'company_name' => ['Company name', 'text', 'Shown in the app, website and customer SMS.'],
        'support_mobile' => ['Support mobile', 'text', 'Riders see this number in the app for help.'],
        'alert_email' => ['Alert email', 'email', 'Critical exceptions and failed jobs are emailed here.'],
    ],
    'tracking' => [
        'gps_interval_moving' => ['GPS interval when moving (sec)', 'number', 'How often the app records location while riding. Default 15.', [5, 120]],
        'gps_interval_slow' => ['GPS interval when slow (sec)', 'number', 'Default 30.', [10, 300]],
        'gps_interval_stationary' => ['GPS interval when stopped (sec)', 'number', 'Saves battery when the rider is standing still. Default 180.', [30, 900]],
        'gps_min_accuracy' => ['Ignore GPS points worse than (m)', 'number', 'Points less accurate than this are not used for KM. Default 50.', [10, 500]],
        'gps_max_speed' => ['Ignore jumps faster than (km/h)', 'number', 'Removes GPS glitches. Default 120.', [40, 300]],
        'customer_geofence_m' => ['Customer arrival radius (m)', 'number', 'The app marks "arrived" automatically inside this distance. Default 150.', [30, 1000]],
        'away_flag_m' => ['Flag deliveries marked further than (m)', 'number', 'Delivered or failed further than this from the customer pin raises an exception. Default 300.', [50, 5000]],
        'manual_arrive_flag_m' => ['Flag manual arrival further than (m)', 'number', 'Default 500.', [50, 5000]],
        'gps_offline_minutes' => ['GPS offline alert after (min)', 'number', 'Default 5.', [2, 60]],
        'auto_end_duty_hours' => ['Stop tracking automatically after (hours)', 'number', 'If a rider forgets END DUTY. Default 12.', [4, 18]],
    ],
    'delivery' => [
        'max_attempts' => ['Max delivery attempts', 'number', 'After this many failed attempts the order goes to RTO. Default 3.', [1, 5]],
        'default_sla_hours' => ['Default delivery deadline (hours)', 'number', 'Used when an order has no deadline. Default 4.', [1, 72]],
        'otp_required' => ['Customer OTP', 'select', 'OTP needs SMS or WhatsApp to be set up. Without it, photo proof is used.', ['none' => 'Not used', 'cod_or_high' => 'COD and high-value orders', 'all' => 'All orders']],
        'otp_high_value' => ['High-value order from (₹)', 'number', 'Default 5000.', [0, 1000000]],
        'photo_on_delivery' => ['Photo needed on delivery', 'bool', 'If OTP is verified, the photo is optional.'],
        'photo_on_failure' => ['Photo needed on failed attempt', 'bool', 'Photo of the door/building as proof the rider visited.'],
        'require_scan_before_route' => ['Scan all parcels before route start', 'bool', 'Stops riders leaving with the wrong parcels.'],
    ],
    'cod' => [
        'cod_limit_default' => ['Default cash-in-hand limit (₹)', 'number', 'Used for new riders. Default 50,000.', [0, 1000000]],
        'block_duty_if_unsettled' => ['Block duty start if COD from an earlier day is unsettled', 'bool', 'Recommended.'],
    ],
    'notify' => [
        'sms_enabled' => ['Send SMS', 'bool', ''],
        'sms_url_template' => ['SMS API URL', 'text', 'Paste your provider\'s HTTPS send URL with placeholders {mobile} (91XXXXXXXXXX), {mobile10}, {message}, {sender}. Example: https://api.provider.in/send?apikey=KEY&sender={sender}&to={mobile}&msg={message}'],
        'sms_sender' => ['SMS sender ID', 'text', '6-letter DLT sender ID.'],
        'whatsapp_enabled' => ['Send WhatsApp', 'bool', 'If on, WhatsApp is tried first and SMS is the fallback.'],
        'whatsapp_url_template' => ['WhatsApp API URL', 'text', 'Same placeholders as SMS.'],
        'notify_customer_out' => ['Message customer when out for delivery', 'bool', 'Includes the OTP when OTP is required.'],
        'notify_customer_delivered' => ['Message customer when delivered', 'bool', ''],
    ],
    'data' => [
        'retention_enabled' => ['Automatically remove old data', 'bool', 'OFF by default. When on, the daily job removes raw GPS points and photos older than the days below. Delivery history, COD and summaries are always kept.'],
        'retention_gps_days' => ['Keep raw GPS points for (days)', 'number', 'Minimum 30. Default 90.', [30, 3650]],
        'retention_photo_days' => ['Keep delivery photos for (days)', 'number', 'Minimum 30. Default 180.', [30, 3650]],
        'retention_selfie_days' => ['Keep duty selfies for (days)', 'number', 'Minimum 7. Default 30.', [7, 3650]],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? 'save');
    if ($act === 'save' && isset($defs[$tab])) {
        $changed = [];
        foreach ($defs[$tab] as $k => $d) {
            $old = Settings::get($k);
            $val = match ($d[1]) {
                'bool' => !empty($_POST[$k]) ? '1' : '0',
                'number' => (string)max($d[3][0], min($d[3][1], (int)($_POST[$k] ?? $old))),
                'select' => isset($d[3][$_POST[$k] ?? '']) ? (string)$_POST[$k] : $old,
                'email' => ($_POST[$k] ?? '') === '' || filter_var($_POST[$k], FILTER_VALIDATE_EMAIL) ? (string)($_POST[$k] ?? '') : $old,
                default => Validate::text((string)($_POST[$k] ?? ''), 1000),
            };
            if (str_ends_with($k, '_url_template') && $val !== '' && !preg_match('#^https://#i', $val)) {
                Ui::flash('bad', $d[0] . ' must start with https://');
                continue;
            }
            if ($val !== $old) {
                Settings::set($k, $val);
                $changed[$k] = [str_contains($k, 'url_template') ? '(hidden)' : $old, str_contains($k, 'url_template') ? '(hidden)' : $val];
            }
        }
        if ($changed) {
            Audit::log('settings.update', 'settings', $tab, array_map(static fn($x) => $x[0], $changed), array_map(static fn($x) => $x[1], $changed));
        }
        Ui::flash('ok', $changed ? 'Settings saved.' : 'No changes.');
    } elseif ($act === 'test_sms') {
        $m = Validate::mobile($_POST['test_mobile'] ?? '');
        $ok = $m && Notify::customer($m, Settings::get('company_name') . ': test message from the delivery system.');
        Ui::flash($ok ? 'ok' : 'bad', $ok ? 'Test message sent. Check the phone.' : 'Could not send. Check the URL, that sending is switched on, and the error log under Audit & Health.');
    } elseif ($act === 'store') {
        $id = (int)($_POST['warehouse_id'] ?? 0);
        $row = ['name' => Validate::text($_POST['name'] ?? '', 100) ?: 'Main Store', 'address' => Validate::text($_POST['address'] ?? '', 255),
            'radius_m' => max(50, min(2000, (int)($_POST['radius_m'] ?? 200)))];
        if (Validate::latLng($_POST['lat'] ?? '', $_POST['lng'] ?? '')) {
            $row['lat'] = (float)$_POST['lat'];
            $row['lng'] = (float)$_POST['lng'];
        } else {
            Ui::flash('warn', 'Location not set: click the map to place the store pin.');
        }
        if ($id) {
            $old = Db::one('SELECT * FROM hm_warehouse WHERE warehouse_id=?', [$id]);
            Db::update('hm_warehouse', $row, 'warehouse_id=?', [$id]);
            Audit::log('warehouse.update', 'warehouse', $id, $old, $row);
        } else {
            $id = Db::insert('hm_warehouse', $row + ['status' => 1, 'created_at' => Fmt::nowUtc()]);
            Audit::log('warehouse.create', 'warehouse', $id, null, $row);
        }
        Ui::flash('ok', 'Store saved.');
        Ui::redirect('?r=settings&tab=store&wid=' . $id);
    } elseif ($act === 'backup') {
        $all = Settings::all();
        $data = ['app' => 'hitech-medicos-delivery', 'version' => \HMD\App::VERSION, 'exported_at' => gmdate('c'), 'settings' => $all, 'warehouses' => Db::all('SELECT name, address, lat, lng, radius_m, status FROM hm_warehouse')];
        Audit::log('settings.backup', 'settings');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="hmd_settings_' . date('d-m-Y') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($act === 'restore') {
        $f = $_FILES['file'] ?? null;
        $data = ($f && $f['error'] === UPLOAD_ERR_OK && $f['size'] < 200000) ? json_decode((string)file_get_contents($f['tmp_name']), true) : null;
        if (!is_array($data) || ($data['app'] ?? '') !== 'hitech-medicos-delivery' || !is_array($data['settings'] ?? null)) {
            Ui::flash('bad', 'This is not a settings backup file from this system.');
        } else {
            $n = 0;
            foreach ($data['settings'] as $k => $v) {
                if (array_key_exists($k, Settings::DEFAULTS) && is_scalar($v)) {
                    Settings::set($k, Validate::text((string)$v, 1000));
                    $n++;
                }
            }
            Audit::log('settings.restore', 'settings', null, null, ['keys' => $n]);
            Ui::flash('ok', "$n settings restored. Store locations were not changed.");
        }
    }
    Ui::redirect('?r=settings&tab=' . $tab);
}

$nav = '<div class="eva-tabs">';
foreach ($tabs as $k => $l) {
    $nav .= '<a class="' . ($tab === $k ? 'active' : '') . '" href="?r=settings&tab=' . $k . '">' . $l . '</a>';
}
$nav .= '</div>';
$body = '';
$head = '';
if ($tab === 'store') {
    $whs = Db::all('SELECT * FROM hm_warehouse ORDER BY warehouse_id');
    $wid = (int)($_GET['wid'] ?? ($whs[0]['warehouse_id'] ?? 0));
    $w = $wid ? (Db::one('SELECT * FROM hm_warehouse WHERE warehouse_id=?', [$wid]) ?? []) : [];
    if (isset($_GET['new'])) {
        $w = [];
    }
    $list = '';
    foreach ($whs as $x) {
        $list .= '<a class="eva-btn sm' . ((int)$x['warehouse_id'] === (int)($w['warehouse_id'] ?? 0) ? ' primary' : '') . '" href="?r=settings&tab=store&wid=' . (int)$x['warehouse_id'] . '">' . $e($x['name']) . '</a> ';
    }
    $c = isset($w['lat']) && $w['lat'] !== null ? [(float)$w['lat'], (float)$w['lng']] : [28.4595, 77.0266];
    $body = '<div class="eva-card">' . $nav . '<div style="margin-bottom:12px">' . $list . '<a class="eva-btn sm" href="?r=settings&tab=store&new=1"><i class="fa-solid fa-plus"></i> Add another store</a></div>'
        . '<form method="post" class="grid2e">' . Ui::csrfField() . '<input type="hidden" name="act" value="store"><input type="hidden" name="warehouse_id" value="' . (int)($w['warehouse_id'] ?? 0) . '"><div>'
        . '<div class="eva-field"><label class="req">Store name</label><div><input type="text" name="name" value="' . $e($w['name'] ?? '') . '" required></div></div>'
        . '<div class="eva-field"><label>Address</label><div><input type="text" name="address" value="' . $e($w['address'] ?? '') . '"></div></div>'
        . '<div class="eva-field"><label>Radius (m)</label><div><input type="number" name="radius_m" min="50" max="2000" value="' . (int)($w['radius_m'] ?? 200) . '"><div class="help">Riders starting duty outside this circle are flagged. Default 200.</div></div></div>'
        . '<div class="eva-field"><label class="req">Location</label><div><input type="text" id="lat" name="lat" placeholder="Latitude" value="' . $e($w['lat'] ?? '') . '"><input type="text" id="lng" name="lng" placeholder="Longitude" style="margin-top:6px" value="' . $e($w['lng'] ?? '') . '"><div class="help">Click the store on the map, or paste the coordinates from Google Maps.</div></div></div>'
        . '<button class="eva-btn primary" style="margin-top:12px"><i class="fa-solid fa-check"></i> Save store</button></div><div id="smap" class="map"></div></form></div>';
    $body .= '<script nonce="' . HMD_NONCE . '">(function(){var m=L.map("smap").setView(' . json_encode($c) . ',' . (isset($w['lat']) && $w['lat'] !== null ? 17 : 12) . ');L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19,attribution:"&copy; OpenStreetMap"}).addTo(m);
var la=document.getElementById("lat"),ln=document.getElementById("lng"),r=document.querySelector("[name=radius_m]"),mk=null,ci=null;function put(a,b){if(mk){m.removeLayer(mk);m.removeLayer(ci);}mk=L.marker([a,b]).addTo(m);ci=L.circle([a,b],{radius:+r.value||200,color:"#20b2c4"}).addTo(m);la.value=a.toFixed(7);ln.value=b.toFixed(7);}
if(la.value&&ln.value)put(+la.value,+ln.value);m.on("click",function(e){put(e.latlng.lat,e.latlng.lng);});r.addEventListener("change",function(){if(la.value)put(+la.value,+ln.value);});})();</script>';
    $head = Ui::leafletHead();
} else {
    $fields = '';
    foreach ($defs[$tab] as $k => $d) {
        $val = Settings::get($k);
        $input = match ($d[1]) {
            'bool' => '<label><input type="checkbox" name="' . $k . '" value="1"' . ($val === '1' ? ' checked' : '') . '> Yes</label>',
            'number' => '<input type="number" name="' . $k . '" min="' . $d[3][0] . '" max="' . $d[3][1] . '" value="' . $e($val) . '">',
            'select' => '<select name="' . $k . '">' . Ui::options($d[3], $val) . '</select>',
            'email' => '<input type="email" name="' . $k . '" value="' . $e($val) . '">',
            default => '<input type="text" name="' . $k . '" value="' . $e($val) . '" autocomplete="off">',
        };
        $fields .= '<div class="eva-field"><label>' . $e($d[0]) . '</label><div>' . $input . ($d[2] ? '<div class="help">' . $e($d[2]) . '</div>' : '') . '</div></div>';
    }
    $body = '<div class="eva-card">' . $nav . '<form method="post">' . Ui::csrfField() . '<input type="hidden" name="act" value="save">' . $fields . '<div style="margin-top:14px"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Save</button></div></form>';
    if ($tab === 'notify') {
        $body .= '<form method="post" style="margin-top:18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . Ui::csrfField() . '<input type="hidden" name="act" value="test_sms"><b>Test:</b><input class="inp" style="width:180px" name="test_mobile" placeholder="Your mobile number"><button class="eva-btn"><i class="fa-solid fa-paper-plane"></i> Send test message</button></form>';
    }
    if ($tab === 'data') {
        $body .= '<div class="eva-h" style="margin-top:20px"><h4><i class="fa-solid fa-floppy-disk"></i> Settings backup</h4></div><p class="muted">Download all settings and store locations as a file, or restore settings from an earlier backup. Orders, riders and history are not affected. For a full backup use the Plesk database backup.</p>'
            . '<div style="display:flex;gap:8px;flex-wrap:wrap"><form method="post">' . Ui::csrfField() . '<input type="hidden" name="act" value="backup"><button class="eva-btn"><i class="fa-solid fa-download"></i> Download settings backup</button></form>'
            . '<form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center">' . Ui::csrfField() . '<input type="hidden" name="act" value="restore"><input type="file" name="file" accept=".json" required><button class="eva-btn" data-confirm="Restore settings from this file?" data-confirm2="All settings on this page set will be replaced by the values in the file. Store locations, orders and riders are not changed. Proceed?"><i class="fa-solid fa-upload"></i> Restore</button></form></div>';
    }
    $body .= '</div>';
}
Ui::page('Settings', 'settings', $body, 'Every change is recorded in the audit log.', '', $head);
