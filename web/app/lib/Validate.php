<?php
declare(strict_types=1);
namespace HMD;

final class Validate
{
    public static function mobile(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    public static function date(?string $s): bool
    {
        if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    public static function dmyToYmd(?string $s): ?string
    {
        if (!is_string($s) || !preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? "$m[3]-$m[2]-$m[1]" : null;
    }

    public static function latLng(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180
            && !((float)$lat == 0.0 && (float)$lng == 0.0);
    }

    public static function money(mixed $v): ?float
    {
        if ($v === '' || $v === null) {
            return null;
        }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000000) {
            return null;
        }
        return round((float)$v, 2);
    }

    public static function text(?string $s, int $max): string
    {
        $s = trim((string)$s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return mb_substr($s, 0, $max);
    }
}
