<?php
declare(strict_types=1);
namespace HMD;

/** Distance, geofence and GPS-point filtering. Pure functions, no DB. */
final class Geo
{
    private const R = 6371000.0;

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::R * asin(min(1.0, sqrt($a)));
    }

    public static function inside(float $lat, float $lng, float $cLat, float $cLng, float $radiusM): bool
    {
        return self::distanceM($lat, $lng, $cLat, $cLng) <= $radiusM;
    }

    /**
     * Filters raw points. Each point: lat, lng, accuracy, ts (unix), seq.
     * Drops duplicates, inaccurate points and unconfirmed jumps.
     * A jump confirmed by the next good point is kept and marked gap=true
     * (its segment is not counted as measured distance).
     */
    public static function filterPoints(array $points, float $maxAccuracyM = 50, float $maxSpeedKmh = 120): array
    {
        usort($points, static fn($a, $b) => [$a['ts'], $a['seq']] <=> [$b['ts'], $b['seq']]);
        $clean = [];
        $seen = [];
        foreach ($points as $p) {
            if (isset($seen[$p['seq']])) {
                continue;
            }
            $seen[$p['seq']] = true;
            if (($p['accuracy'] ?? 0) > $maxAccuracyM) {
                continue;
            }
            $clean[] = $p;
        }
        $kept = [];
        $n = count($clean);
        for ($i = 0; $i < $n; $i++) {
            $p = $clean[$i];
            if (!$kept) {
                $kept[] = $p;
                continue;
            }
            $last = $kept[count($kept) - 1];
            if (self::speedKmh($last, $p) <= $maxSpeedKmh) {
                $kept[] = $p;
                continue;
            }
            $next = $clean[$i + 1] ?? null;
            if ($next !== null && self::speedKmh($p, $next) <= $maxSpeedKmh
                && self::distanceM($p['lat'], $p['lng'], $next['lat'], $next['lng']) < self::distanceM($last['lat'], $last['lng'], $next['lat'], $next['lng'])) {
                $p['gap'] = true;
                $kept[] = $p;
            }
        }
        return $kept;
    }

    public static function speedKmh(array $a, array $b): float
    {
        $dt = abs($b['ts'] - $a['ts']);
        $d = self::distanceM((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng']);
        if ($dt <= 0) {
            return $d < 5 ? 0.0 : INF;
        }
        return $d / $dt * 3.6;
    }

    /** Sum of measured KM, skipping segments that end in a gap point. */
    public static function pathKm(array $kept): float
    {
        $m = 0.0;
        for ($i = 1, $n = count($kept); $i < $n; $i++) {
            if (!empty($kept[$i]['gap'])) {
                continue;
            }
            $m += self::distanceM((float)$kept[$i - 1]['lat'], (float)$kept[$i - 1]['lng'], (float)$kept[$i]['lat'], (float)$kept[$i]['lng']);
        }
        return round($m / 1000, 3);
    }

    /** Straight-line KM across gap segments, multiplied by a road factor, reported as "estimated". */
    public static function gapKm(array $kept, float $roadFactor = 1.3): float
    {
        $m = 0.0;
        for ($i = 1, $n = count($kept); $i < $n; $i++) {
            if (!empty($kept[$i]['gap'])) {
                $m += self::distanceM((float)$kept[$i - 1]['lat'], (float)$kept[$i - 1]['lng'], (float)$kept[$i]['lat'], (float)$kept[$i]['lng']);
            }
        }
        return round($m * $roadFactor / 1000, 3);
    }
}
