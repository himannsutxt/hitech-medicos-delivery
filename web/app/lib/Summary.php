<?php
declare(strict_types=1);
namespace HMD;

/** Daily rider summaries and dashboard KPIs, always computed from the event log. */
final class Summary
{
    public static function riderDay(int $riderId, string $ymd): array
    {
        [$s, $e] = Fmt::istDayRangeUtc($ymd);
        $duties = Db::all('SELECT * FROM hm_duty WHERE rider_id=? AND start_at>=? AND start_at<? ORDER BY start_at', [$riderId, $s, $e]);
        $dutyMin = 0;
        $km = 0.0;
        foreach ($duties as $d) {
            $end = $d['end_at'] ?? Fmt::nowUtc();
            $dutyMin += max(0, (int)round((strtotime($end . ' UTC') - strtotime($d['start_at'] . ' UTC')) / 60));
            $km += (float)$d['km_measured'] + (float)$d['km_estimated'];
        }
        $att = Db::one("SELECT COUNT(*) attempts,
              SUM(outcome='delivered') delivered, SUM(outcome='failed') failed, SUM(outcome='refused') refused,
              SUM(outcome='rescheduled') rescheduled,
              AVG(CASE WHEN outcome='delivered' AND started_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, closed_at)/60 END) avg_cycle
            FROM hm_attempt WHERE rider_id=? AND closed_at>=? AND closed_at<?", [$riderId, $s, $e]) ?? [];
        $assigned = (int)Db::val("SELECT COUNT(DISTINCT delivery_id) FROM hm_event WHERE type IN ('status','reassigned') AND to_status='assigned' AND server_at>=? AND server_at<? AND (JSON_EXTRACT(meta,'$.rider_id')=? OR JSON_EXTRACT(meta,'$.to_rider')=?)", [$s, $e, $riderId, $riderId]);
        $cod = Db::one("SELECT SUM(CASE WHEN type='collect' AND mode='cash' THEN amount ELSE 0 END) cash,
              SUM(CASE WHEN type='collect' AND mode='upi' THEN amount ELSE 0 END) upi,
              SUM(CASE WHEN type IN ('deposit','recover') THEN amount ELSE 0 END) dep
            FROM hm_cod_txn WHERE rider_id=? AND created_at>=? AND created_at<?", [$riderId, $s, $e]) ?? [];
        $row = [
            'rider_id' => $riderId, 'summary_date' => $ymd,
            'duty_start' => $duties[0]['start_at'] ?? null, 'duty_end' => $duties ? end($duties)['end_at'] : null,
            'duty_minutes' => $dutyMin, 'assigned' => $assigned,
            'delivered' => (int)($att['delivered'] ?? 0), 'failed' => (int)($att['failed'] ?? 0), 'refused' => (int)($att['refused'] ?? 0),
            'rescheduled' => (int)($att['rescheduled'] ?? 0), 'attempts' => (int)($att['attempts'] ?? 0),
            'km' => round($km, 3), 'avg_cycle_min' => $att['avg_cycle'] !== null ? round((float)$att['avg_cycle'], 2) : null,
            'cod_cash' => (float)($cod['cash'] ?? 0), 'cod_upi' => (float)($cod['upi'] ?? 0), 'cod_deposited' => (float)($cod['dep'] ?? 0),
            'updated_at' => Fmt::nowUtc(),
        ];
        $cols = array_keys($row);
        Db::run('REPLACE INTO hm_daily_summary (' . implode(',', $cols) . ') VALUES (' . Db::in($cols) . ')', array_values($row));
        return $row;
    }

    public static function kpisToday(): array
    {
        [$s, $e] = Fmt::istDayRangeUtc(Fmt::todayIst());
        $k = Db::one("SELECT
            SUM(status IN ('assigned','loaded','out','en_route','arrived')) active,
            SUM(status='ready') ready,
            SUM(status IN ('out','en_route','arrived')) in_transit,
            SUM(status='delivered' AND delivered_at>=? AND delivered_at<?) delivered,
            SUM(status IN ('rescheduled','rto','not_delivered')) failed_open
          FROM hm_delivery WHERE deleted_at IS NULL", [$s, $e]) ?? [];
        $a = Db::one("SELECT SUM(outcome='delivered') d, SUM(outcome IN ('failed','refused','rescheduled')) f, COUNT(*) n FROM hm_attempt WHERE closed_at>=? AND closed_at<?", [$s, $e]) ?? [];
        $km = (float)Db::val('SELECT COALESCE(SUM(km_measured+km_estimated),0) FROM hm_duty WHERE start_at>=? AND start_at<?', [$s, $e]);
        $riders = (int)Db::val("SELECT COUNT(*) FROM hm_duty WHERE status='active'");
        $codCollected = (float)Db::val("SELECT COALESCE(SUM(amount),0) FROM hm_cod_txn WHERE type='collect' AND created_at>=? AND created_at<?", [$s, $e]);
        $outstanding = (float)Db::val("SELECT COALESCE(SUM(CASE WHEN type='collect' AND mode='cash' THEN amount WHEN type IN ('deposit','recover','writeoff') THEN -amount ELSE 0 END),0) FROM hm_cod_txn");
        $exceptions = (int)Db::val("SELECT COUNT(*) FROM hm_exception WHERE status='open'");
        $d = (int)($a['d'] ?? 0);
        $f = (int)($a['f'] ?? 0);
        return [
            'riders_active' => $riders, 'ready' => (int)($k['ready'] ?? 0), 'active' => (int)($k['active'] ?? 0),
            'in_transit' => (int)($k['in_transit'] ?? 0), 'delivered' => (int)($k['delivered'] ?? 0),
            'failed' => $f, 'success_rate' => ($d + $f) > 0 ? round($d / ($d + $f) * 100, 1) : null,
            'km' => round($km, 1), 'cod_collected' => $codCollected, 'cod_outstanding' => max(0, $outstanding),
            'exceptions' => $exceptions,
        ];
    }
}
