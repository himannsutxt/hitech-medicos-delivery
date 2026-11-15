<?php
declare(strict_types=1);
namespace HMD;

/** Scheduled jobs. Run cron.php every 5 minutes. Each job records last run and failures. */
final class Jobs
{
    public const ALL = [
        'gps_offline_check' => 'Flags riders on duty with no GPS for the set minutes',
        'auto_end_duty' => 'Ends duties left open longer than the set hours',
        'sla_check' => 'Flags orders past their SLA time',
        'daily_summary' => 'Rebuilds today\'s and yesterday\'s rider summaries',
        'retention_cleanup' => 'Removes old GPS points and photos (only if enabled in Settings)',
    ];

    public static function runAll(): array
    {
        $out = [];
        foreach (array_keys(self::ALL) as $j) {
            $out[$j] = self::run($j);
        }
        return $out;
    }

    public static function run(string $job): string
    {
        try {
            $msg = match ($job) {
                'gps_offline_check' => self::gpsOffline(),
                'auto_end_duty' => self::autoEnd(),
                'sla_check' => self::sla(),
                'daily_summary' => self::summaries(),
                'retention_cleanup' => self::retention(),
                default => throw new \InvalidArgumentException('Unknown job'),
            };
            Db::run('INSERT INTO hm_job_run (job, last_run_at, last_status, last_message, failures) VALUES (?,?,?,?,0)
                ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at), last_status=VALUES(last_status), last_message=VALUES(last_message)', [$job, Fmt::nowUtc(), 'ok', mb_substr($msg, 0, 500)]);
            return $msg;
        } catch (\Throwable $e) {
            App::log("Job $job failed: " . $e->getMessage());
            Db::run('INSERT INTO hm_job_run (job, last_run_at, last_status, last_message, failures) VALUES (?,?,?,?,1)
                ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at), last_status=VALUES(last_status), last_message=VALUES(last_message), failures=failures+1', [$job, Fmt::nowUtc(), 'fail', mb_substr($e->getMessage(), 0, 500)]);
            Notify::admin("Job $job failed", $e->getMessage());
            return 'FAILED: ' . $e->getMessage();
        }
    }

    private static function gpsOffline(): string
    {
        $mins = max(1, Settings::int('gps_offline_minutes'));
        $rows = Db::all("SELECT r.rider_id, r.name, r.last_seen_at FROM hm_duty d JOIN hm_rider r ON r.rider_id=d.rider_id
            WHERE d.status='active' AND (r.last_seen_at IS NULL OR r.last_seen_at < ?)", [gmdate('Y-m-d H:i:s', time() - $mins * 60)]);
        foreach ($rows as $r) {
            Tracking::setLive((int)$r['rider_id'], 'gps_offline');
            Exceptions::raise('gps_offline', 'medium', (int)$r['rider_id'], null, "{$r['name']}: no GPS update for more than $mins minutes while on duty.");
        }
        return count($rows) . ' rider(s) offline';
    }

    private static function autoEnd(): string
    {
        $h = max(1, Settings::int('auto_end_duty_hours'));
        $rows = Db::all("SELECT d.*, r.name FROM hm_duty d JOIN hm_rider r ON r.rider_id=d.rider_id WHERE d.status='active' AND d.start_at < ?", [gmdate('Y-m-d H:i:s', time() - $h * 3600)]);
        foreach ($rows as $d) {
            Db::update('hm_duty', ['status' => 'auto_ended', 'end_at' => Fmt::nowUtc()], 'duty_id=?', [$d['duty_id']]);
            Tracking::recomputeKm((int)$d['duty_id']);
            Tracking::setLive((int)$d['rider_id'], 'off_duty');
            Exceptions::raise('duty_auto_ended', 'medium', (int)$d['rider_id'], null, "{$d['name']}'s duty was ended automatically after $h hours. Tracking stopped.");
        }
        return count($rows) . ' duty(ies) auto-ended';
    }

    private static function sla(): string
    {
        $rows = Db::all("SELECT delivery_id, order_no, rider_id FROM hm_delivery WHERE deleted_at IS NULL AND sla_due_at IS NOT NULL AND sla_due_at < ?
            AND status IN ('ready','assigned','loaded','out','en_route','arrived')", [Fmt::nowUtc()]);
        foreach ($rows as $r) {
            Exceptions::raise('sla_breached', 'low', $r['rider_id'] !== null ? (int)$r['rider_id'] : null, (int)$r['delivery_id'], "Order {$r['order_no']} has passed its SLA time.");
        }
        return count($rows) . ' order(s) past SLA';
    }

    private static function summaries(): string
    {
        $today = Fmt::todayIst();
        $yday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $n = 0;
        foreach ([$yday, $today] as $day) {
            [$s, $e] = Fmt::istDayRangeUtc($day);
            $ids = Db::all('SELECT DISTINCT rider_id FROM hm_duty WHERE start_at>=? AND start_at<? UNION SELECT DISTINCT rider_id FROM hm_attempt WHERE closed_at>=? AND closed_at<?', [$s, $e, $s, $e]);
            foreach ($ids as $r) {
                Summary::riderDay((int)$r['rider_id'], $day);
                $n++;
            }
        }
        // Keep KM fresh for active duties
        foreach (Db::all("SELECT duty_id FROM hm_duty WHERE status='active'") as $d) {
            Tracking::recomputeKm((int)$d['duty_id']);
        }
        return "$n summary row(s) updated";
    }

    private static function retention(): string
    {
        if (Settings::get('retention_enabled') !== '1') {
            return 'Retention cleanup is turned off in Settings; nothing removed.';
        }
        $gpsDays = max(30, Settings::int('retention_gps_days'));
        $cut = gmdate('Y-m-d H:i:s', time() - $gpsDays * 86400);
        $gps = Db::run('DELETE FROM hm_location WHERE device_at < ? LIMIT 50000', [$cut]);
        $removed = 0;
        foreach (['selfie' => max(7, Settings::int('retention_selfie_days')), 'pod' => max(30, Settings::int('retention_photo_days')), 'failed' => max(30, Settings::int('retention_photo_days'))] as $kind => $days) {
            $old = Db::all('SELECT media_id, file_path FROM hm_media WHERE kind=? AND created_at < ? LIMIT 2000', [$kind, gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
            foreach ($old as $m) {
                $f = App::storage('uploads/' . $m['file_path']);
                if (is_file($f)) {
                    @unlink($f);
                }
                Db::run("UPDATE hm_media SET file_path='' , size_bytes=0 WHERE media_id=?", [$m['media_id']]);
                $removed++;
            }
        }
        Audit::log('retention.cleanup', null, null, null, ['gps_rows' => $gps, 'photos' => $removed], ['type' => 'system', 'id' => null, 'name' => 'Cron']);
        return "$gps GPS point(s) and $removed photo(s) removed";
    }
}
