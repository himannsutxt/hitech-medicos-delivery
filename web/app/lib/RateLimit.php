<?php
declare(strict_types=1);
namespace HMD;

final class RateLimit
{
    /** Returns false when the key has exceeded $max hits in $windowSec. */
    public static function hit(string $key, int $max, int $windowSec): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $now = time();
        $r = Db::one('SELECT hits, window_start FROM hm_rate_limit WHERE rkey=?', [$key]);
        if (!$r || strtotime($r['window_start'] . ' UTC') + $windowSec < $now) {
            Db::run('REPLACE INTO hm_rate_limit (rkey, hits, window_start) VALUES (?,1,?)', [$key, gmdate('Y-m-d H:i:s', $now)]);
            return true;
        }
        Db::run('UPDATE hm_rate_limit SET hits=hits+1 WHERE rkey=?', [$key]);
        return (int)$r['hits'] + 1 <= $max;
    }
}
