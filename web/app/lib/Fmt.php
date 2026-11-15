<?php
declare(strict_types=1);
namespace HMD;

/** Indian display defaults: IST, DD-MM-YYYY, INR grouping. */
final class Fmt
{
    public static function inr(float|int|string|null $v, int $decimals = 2): string
    {
        $v = (float)$v;
        $neg = $v < 0;
        $s = number_format(abs($v), 2, '.', '');
        [$int, $dec] = explode('.', $s);
        if ($decimals === 0) {
            $int = (string)round(abs($v));
        }
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        return ($neg ? '-' : '') . '₹' . $int . ($decimals === 0 ? '' : '.' . $dec);
    }

    private static function ist(?string $utc): ?\DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(new \DateTimeZone('Asia/Kolkata'));
    }

    public static function dt(?string $utc): string
    {
        return ($d = self::ist($utc)) ? $d->format('d-m-Y H:i') : '';
    }

    public static function d(?string $utc): string
    {
        return ($d = self::ist($utc)) ? $d->format('d-m-Y') : '';
    }

    public static function time(?string $utc): string
    {
        return ($d = self::ist($utc)) ? $d->format('h:i A') : '';
    }

    public static function mins(?int $m): string
    {
        if ($m === null) {
            return '—';
        }
        $h = intdiv($m, 60);
        return $h > 0 ? sprintf('%dh %dm', $h, $m % 60) : sprintf('%dm', $m);
    }

    /** Start and end of an IST calendar day, as UTC strings for queries. */
    public static function istDayRangeUtc(string $ymd): array
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $s = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
        $e = $s->modify('+1 day');
        $u = new \DateTimeZone('UTC');
        return [$s->setTimezone($u)->format('Y-m-d H:i:s'), $e->setTimezone($u)->format('Y-m-d H:i:s')];
    }

    public static function todayIst(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
