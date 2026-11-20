<?php
declare(strict_types=1);
namespace HMD;

/** GPS ingest, KM computation and live rider status. */
final class Tracking
{
    /** Stores a batch of points; duplicates (same duty+seq) are ignored. Returns count stored. */
    public static function ingest(array $rider, array $duty, array $points): array
    {
        $stored = 0;
        $rejected = 0;
        $latest = null;
        $cands = [];
        $mock = false;
        foreach (array_slice($points, 0, 500) as $p) {
            if (!is_array($p) || !isset($p['seq'], $p['lat'], $p['lng']) || !Validate::latLng($p['lat'], $p['lng']) || !is_numeric($p['seq'])) {
                $rejected++;
                continue;
            }
            $deviceAt = self::deviceTime($p['device_at'] ?? null);
            $n = Db::run('INSERT IGNORE INTO hm_location (duty_id, rider_id, seq, lat, lng, accuracy, speed, heading, battery, is_mock, delivery_id, device_at, server_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $duty['duty_id'], $rider['rider_id'], (int)$p['seq'], (float)$p['lat'], (float)$p['lng'],
                isset($p['accuracy']) && is_numeric($p['accuracy']) ? min(99999, (float)$p['accuracy']) : null,
                isset($p['speed']) && is_numeric($p['speed']) ? max(0, min(999, (float)$p['speed'])) : null,
                isset($p['heading']) && is_numeric($p['heading']) ? fmod((float)$p['heading'], 360) : null,
                isset($p['battery']) && is_numeric($p['battery']) ? max(0, min(100, (int)$p['battery'])) : null,
                !empty($p['is_mock']) ? 1 : 0,
                isset($p['delivery_id']) && is_numeric($p['delivery_id']) ? (int)$p['delivery_id'] : null,
                $deviceAt, Fmt::nowUtc(),
            ]);
            $stored += $n;
            if (!empty($p['is_mock'])) {
                $mock = true;
            }
            if (empty($p['is_mock'])) {
                $cands[] = ['seq' => (int)$p['seq'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
                    'accuracy' => isset($p['accuracy']) && is_numeric($p['accuracy']) ? (float)$p['accuracy'] : 0.0,
                    'ts' => strtotime($deviceAt . ' UTC'), 'speed' => $p['speed'] ?? null, 'battery' => $p['battery'] ?? null, 'device_at' => $deviceAt];
            }
        }
        // Live position = newest fix that survives the same filter used for KM (accuracy, glitch jumps),
        // starting from the rider's last known position so a glitch can't move them across the city.
        if ($cands) {
            $anchor = [];
            if ($rider['last_lat'] !== null && $rider['last_seen_at'] !== null) {
                $anchor[] = ['seq' => PHP_INT_MIN, 'lat' => (float)$rider['last_lat'], 'lng' => (float)$rider['last_lng'], 'accuracy' => 0.0, 'ts' => min(strtotime($rider['last_seen_at'] . ' UTC'), min(array_column($cands, 'ts')) - 1), 'anchor' => true];
            }
            $kept = Geo::filterPoints(array_merge($anchor, $cands), (float)Settings::get('gps_min_accuracy'), (float)Settings::get('gps_max_speed'));
            $last = end($kept);
            if ($last && empty($last['anchor'])) {
                $latest = $last;
            }
        }
        if (!$latest) {
            Db::update('hm_rider', ['last_seen_at' => Fmt::nowUtc()], 'rider_id=?', [$rider['rider_id']]);
        }
        if ($latest) {
            Db::update('hm_rider', [
                'last_lat' => $latest['lat'], 'last_lng' => $latest['lng'],
                'last_speed' => is_numeric($latest['speed']) ? round((float)$latest['speed'] * 3.6, 1) : null,
                'last_battery' => is_numeric($latest['battery']) ? (int)$latest['battery'] : null,
                'last_seen_at' => Fmt::nowUtc(),
            ], 'rider_id=?', [$rider['rider_id']]);
        }
        if ($mock) {
            Exceptions::raise('mock_location', 'critical', (int)$rider['rider_id'], null, "Fake GPS (mock location) detected on {$rider['name']}'s phone.");
        }
        return ['stored' => $stored, 'rejected' => $rejected];
    }

    /** Converts an ISO-8601 device time to UTC; falls back to server time if missing or >2 min off. */
    public static function deviceTime(mixed $iso): string
    {
        $now = time();
        if (is_string($iso) && $iso !== '') {
            try {
                $t = (new \DateTimeImmutable($iso))->getTimestamp();
                // Offline points can be old, but never in the future and never older than 48h.
                if ($t <= $now + 120 && $t >= $now - 172800) {
                    return gmdate('Y-m-d H:i:s', $t);
                }
            } catch (\Throwable) {
            }
        }
        return gmdate('Y-m-d H:i:s', $now);
    }

    /** Recomputes measured and estimated KM for a duty from stored points. */
    public static function recomputeKm(int $dutyId): array
    {
        $rows = Db::all('SELECT seq, lat, lng, accuracy, UNIX_TIMESTAMP(device_at) AS ts FROM hm_location WHERE duty_id=? ORDER BY device_at, seq', [$dutyId]);
        $pts = array_map(static fn($r) => ['seq' => (int)$r['seq'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'accuracy' => (float)($r['accuracy'] ?? 0), 'ts' => (int)$r['ts']], $rows);
        $kept = Geo::filterPoints($pts, (float)Settings::get('gps_min_accuracy'), (float)Settings::get('gps_max_speed'));
        $km = Geo::pathKm($kept);
        $est = Geo::gapKm($kept);
        Db::update('hm_duty', ['km_measured' => $km, 'km_estimated' => $est], 'duty_id=?', [$dutyId]);
        return ['km' => $km, 'estimated' => $est, 'points' => count($pts), 'kept' => count($kept)];
    }

    public static function setLive(int $riderId, string $status): void
    {
        Db::update('hm_rider', ['live_status' => $status], 'rider_id=?', [$riderId]);
    }

    public static function activeDuty(int $riderId): ?array
    {
        return Db::one("SELECT * FROM hm_duty WHERE rider_id=? AND status='active' ORDER BY duty_id DESC LIMIT 1", [$riderId]);
    }

    public static function warehouseCheck(?int $warehouseId, float $lat, float $lng): ?bool
    {
        $w = $warehouseId ? Db::one('SELECT * FROM hm_warehouse WHERE warehouse_id=?', [$warehouseId]) : Db::one('SELECT * FROM hm_warehouse WHERE status=1 ORDER BY warehouse_id LIMIT 1');
        if (!$w || $w['lat'] === null) {
            return null;
        }
        return Geo::inside($lat, $lng, (float)$w['lat'], (float)$w['lng'], (float)$w['radius_m']);
    }
}
