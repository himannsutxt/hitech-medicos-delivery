<?php
declare(strict_types=1);
use HMD\{CsvImport, Db, Delivery, Fmt, Ui, Auth, Audit, Settings};

$u = Auth::user();
if (($_GET['template'] ?? '') === '1') {
    Ui::csv('order_import_template.csv', array_merge(CsvImport::REQUIRED, CsvImport::OPTIONAL), [
        ['HM1001', 'Ravi Sharma', '9876543210', 'H.No 12, Sector 14, Gurgaon', '122001', 'Near Sector 14 market', 'cod', '1250', '1250', '2', 'normal', 'Call before coming', 'no', '', ''],
        ['HM1002', 'Asha Verma', '9811111111', 'Flat 3B, DLF Phase 3, Gurgaon', '122002', '', 'prepaid', '', '499', '1', 'urgent', '', 'yes', '28.4900', '77.0900'],
    ]);
}
$preview = null;
$body = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2_000_000) {
        Ui::flash('bad', 'Choose a CSV file under 2 MB.');
        Ui::redirect('?r=import');
    }
    $text = (string)file_get_contents($f['tmp_name']);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $preview = CsvImport::parse($text);
    if ($preview['fatal'] === '') {
        // flag order numbers already in the system
        $nos = array_filter(array_map(static fn($r) => $r['data']['order_no'], $preview['rows']));
        $exist = $nos ? array_column(Db::all('SELECT order_no FROM hm_delivery WHERE order_no IN (' . Db::in($nos) . ')', array_values($nos)), 'order_no') : [];
        $preview['valid'] = 0;
        foreach ($preview['rows'] as &$r) {
            if (in_array($r['data']['order_no'], $exist, true)) {
                $r['errors'][] = 'Order number already exists';
            }
            if (!$r['errors']) {
                $preview['valid']++;
            }
        }
        unset($r);
        $token = bin2hex(random_bytes(8));
        $_SESSION['import'][$token] = array_values(array_map(static fn($r) => $r['data'], array_filter($preview['rows'], static fn($r) => !$r['errors'])));
        $_SESSION['import'] = array_slice($_SESSION['import'], -3, null, true);
        $preview['token'] = $token;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    $token = (string)($_POST['token'] ?? '');
    $rows = $_SESSION['import'][$token] ?? null;
    unset($_SESSION['import'][$token]);
    if (!$rows) {
        Ui::flash('bad', 'This preview expired. Upload the file again.');
        Ui::redirect('?r=import');
    }
    $n = 0;
    $skipped = 0;
    $sla = gmdate('Y-m-d H:i:s', time() + 3600 * max(1, Settings::int('default_sla_hours')));
    Db::tx(function () use ($rows, $u, $sla, &$n, &$skipped) {
        foreach ($rows as $data) {
            if (Db::val('SELECT 1 FROM hm_delivery WHERE order_no=?', [$data['order_no']])) {
                $skipped++;
                continue;
            }
            Delivery::create($data + ['sla_due_at' => $sla], (int)$u['user_id']);
            $n++;
        }
    });
    Audit::log('order.import', 'delivery', null, null, ['imported' => $n, 'skipped' => $skipped]);
    Ui::flash('ok', "$n order(s) imported." . ($skipped ? " $skipped skipped because the order number already existed." : ''));
    Ui::redirect('?r=orders&status=ready');
}

$e = [Ui::class, 'e'];
$body .= '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-file-csv"></i> 1. Upload a CSV file</h4><a class="eva-btn" href="?r=import&template=1"><i class="fa-solid fa-download"></i> Download template</a></div>'
    . '<p class="muted">Required columns: <code>' . implode('</code>, <code>', CsvImport::REQUIRED) . '</code>. Optional: <code>' . implode('</code>, <code>', CsvImport::OPTIONAL) . '</code>. Save from Excel as "CSV UTF-8". Up to ' . CsvImport::MAX_ROWS . ' rows per file. You will see a preview before anything is saved.</p>'
    . '<form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . Ui::csrfField() . '<input type="hidden" name="step" value="upload"><input type="file" name="csv" accept=".csv,text/csv" required><button class="eva-btn primary"><i class="fa-solid fa-eye"></i> Preview</button></form></div>';
if ($preview) {
    if ($preview['fatal'] !== '') {
        $body .= '<div class="eva-alert bad">' . $e($preview['fatal']) . '</div>';
    } else {
        $bad = count($preview['rows']) - $preview['valid'];
        $tr = '';
        foreach ($preview['rows'] as $r) {
            $d = $r['data'];
            $tr .= '<tr class="' . ($r['errors'] ? 'err' : '') . '"><td data-label="Line">' . (int)$r['line'] . '</td><td data-label="Order">' . $e($d['order_no']) . '</td><td data-label="Customer">' . $e($d['customer_name']) . '<div class="muted small">' . $e($d['customer_mobile']) . '</div></td>'
                . '<td data-label="Address">' . $e(mb_strimwidth($d['address'], 0, 60, '…')) . '</td><td data-label="Payment">' . ($d['payment_type'] === 'cod' ? 'COD ' . Fmt::inr($d['cod_amount']) : 'Prepaid') . '</td><td data-label="Priority">' . $e(ucfirst($d['priority'])) . '</td>'
                . '<td data-label="Check">' . ($r['errors'] ? '<span class="eva-pill bad">' . $e(implode('; ', $r['errors'])) . '</span>' : '<span class="eva-pill ok">OK</span>') . '</td></tr>';
        }
        $body .= '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-list-check"></i> 2. Check the preview</h4><span><span class="eva-pill ok">' . $preview['valid'] . ' ready</span> ' . ($bad ? '<span class="eva-pill bad">' . $bad . ' with problems (will be skipped)</span>' : '') . '</span></div>'
            . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Line</th><th>Order</th><th>Customer</th><th>Address</th><th>Payment</th><th>Priority</th><th>Check</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
            . ($preview['valid'] ? '<form method="post" style="margin-top:12px">' . Ui::csrfField() . '<input type="hidden" name="step" value="confirm"><input type="hidden" name="token" value="' . $e($preview['token']) . '"><button class="eva-btn success"><i class="fa-solid fa-check"></i> Import ' . $preview['valid'] . ' order(s)</button> <a class="eva-btn" href="?r=import">Start again</a></form>' : '<p class="eva-alert warn" style="margin-top:12px">No valid rows to import. Fix the file and upload again.</p>')
            . '</div>';
    }
}
Ui::page('Import orders', 'orders', $body, 'Add many orders at once from Excel.');
