<?php
declare(strict_types=1);
namespace HMD;

/** Parses the order import CSV and validates every row for the preview screen. */
final class CsvImport
{
    public const REQUIRED = ['order_no', 'customer_name', 'customer_mobile', 'address'];
    public const OPTIONAL = ['pincode', 'landmark', 'payment_type', 'cod_amount', 'order_value', 'items_count', 'priority', 'notes', 'cold_chain', 'lat', 'lng'];
    public const MAX_ROWS = 2000;

    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? '';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$header) {
            return ['fatal' => 'The file is empty.', 'rows' => [], 'valid' => 0];
        }
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
        $missing = array_diff(self::REQUIRED, $header);
        if ($missing) {
            return ['fatal' => 'Missing required columns: ' . implode(', ', $missing), 'rows' => [], 'valid' => 0];
        }
        $rows = [];
        $valid = 0;
        $line = 1;
        $seen = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($r === [null] || implode('', $r) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                return ['fatal' => 'Too many rows. Maximum is ' . self::MAX_ROWS . ' per file.', 'rows' => [], 'valid' => 0];
            }
            $raw = [];
            foreach ($header as $i => $h) {
                $raw[$h] = isset($r[$i]) ? trim((string)$r[$i]) : '';
            }
            [$data, $errors] = self::row($raw);
            if ($data['order_no'] !== '') {
                if (isset($seen[$data['order_no']])) {
                    $errors[] = 'Duplicate order number in this file';
                }
                $seen[$data['order_no']] = true;
            }
            if (!$errors) {
                $valid++;
            }
            $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors];
        }
        fclose($fh);
        return ['fatal' => '', 'rows' => $rows, 'valid' => $valid];
    }

    /** Validates one order (used by CSV import and the manual add form). */
    public static function row(array $raw): array
    {
        $e = [];
        $d = [];
        $d['order_no'] = Validate::text($raw['order_no'] ?? '', 40);
        if ($d['order_no'] === '') {
            $e[] = 'Order number is required';
        } elseif (!preg_match('/^[A-Za-z0-9\-\/_#]+$/', $d['order_no'])) {
            $e[] = 'Order number may use letters, numbers and - / _ # only';
        }
        $d['customer_name'] = Validate::text($raw['customer_name'] ?? '', 120);
        if ($d['customer_name'] === '') {
            $e[] = 'Customer name is required';
        }
        $m = Validate::mobile($raw['customer_mobile'] ?? '');
        $d['customer_mobile'] = $m ?? Validate::text($raw['customer_mobile'] ?? '', 15);
        if ($m === null) {
            $e[] = 'Invalid mobile number';
        }
        $d['address'] = Validate::text($raw['address'] ?? '', 500);
        if (mb_strlen($d['address']) < 5) {
            $e[] = 'Address is too short';
        }
        $d['pincode'] = Validate::text($raw['pincode'] ?? '', 10);
        if ($d['pincode'] !== '' && !preg_match('/^\d{6}$/', $d['pincode'])) {
            $e[] = 'Pincode must be 6 digits';
        }
        $d['landmark'] = Validate::text($raw['landmark'] ?? '', 200);
        $d['notes'] = Validate::text($raw['notes'] ?? '', 500);
        $pt = strtolower($raw['payment_type'] ?? '') ?: 'prepaid';
        if (!in_array($pt, ['prepaid', 'cod'], true)) {
            $e[] = 'Payment type must be prepaid or cod';
            $pt = 'prepaid';
        }
        $d['payment_type'] = $pt;
        $ov = Validate::money($raw['order_value'] ?? '');
        if (($raw['order_value'] ?? '') !== '' && $ov === null) {
            $e[] = 'Order value is not a valid amount';
        }
        $d['order_value'] = $ov ?? 0.0;
        $cod = Validate::money($raw['cod_amount'] ?? '');
        if (($raw['cod_amount'] ?? '') !== '' && $cod === null) {
            $e[] = 'COD amount is not a valid amount';
        }
        if ($pt === 'cod') {
            $d['cod_amount'] = $cod ?? $d['order_value'];
            if ($d['cod_amount'] <= 0) {
                $e[] = 'COD order needs a COD amount or order value';
            }
        } else {
            $d['cod_amount'] = 0.0;
        }
        $ic = $raw['items_count'] ?? '';
        $d['items_count'] = ($ic === '' ? 1 : (ctype_digit($ic) ? max(1, min(999, (int)$ic)) : 1));
        $pr = strtolower($raw['priority'] ?? '') ?: 'normal';
        $d['priority'] = in_array($pr, ['normal', 'priority', 'urgent'], true) ? $pr : 'normal';
        $cc = strtolower((string)($raw['cold_chain'] ?? ''));
        $d['is_cold_chain'] = in_array($cc, ['1', 'yes', 'y', 'true'], true) ? 1 : 0;
        $d['cust_lat'] = null;
        $d['cust_lng'] = null;
        if (($raw['lat'] ?? '') !== '' || ($raw['lng'] ?? '') !== '') {
            if (Validate::latLng($raw['lat'] ?? '', $raw['lng'] ?? '')) {
                $d['cust_lat'] = (float)$raw['lat'];
                $d['cust_lng'] = (float)$raw['lng'];
            } else {
                $e[] = 'Latitude/longitude is invalid';
            }
        }
        return [$d, $e];
    }

    /** Prevents spreadsheet formula injection in exported cells. */
    public static function safeCell(mixed $v): string
    {
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }
}
