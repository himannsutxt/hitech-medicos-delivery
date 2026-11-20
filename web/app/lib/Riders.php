<?php
declare(strict_types=1);
namespace HMD;

final class Riders
{
    /** Next employee code in the DEL001, DEL002… series (ignores codes that don't follow it). */
    public static function nextCode(array $existing, string $prefix = 'DEL'): string
    {
        $max = 0;
        foreach ($existing as $c) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', (string)$c, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function generateCode(): string
    {
        return self::nextCode(array_column(Db::all("SELECT employee_code FROM hm_rider WHERE employee_code LIKE 'DEL%'"), 'employee_code'));
    }
}
