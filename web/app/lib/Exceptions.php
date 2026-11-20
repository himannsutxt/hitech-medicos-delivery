<?php
declare(strict_types=1);
namespace HMD;

final class Exceptions
{
    public const RULES = [
        'outside_warehouse' => 'Duty started outside warehouse',
        'delivered_away' => 'Delivered away from address',
        'failed_away' => 'Failed attempt away from address',
        'manual_arrival_far' => 'Arrival marked manually, far from address',
        'mock_location' => 'Fake GPS detected',
        'gps_offline' => 'GPS offline on duty',
        'cod_short' => 'COD short at settlement',
        'cod_limit' => 'Cash in hand above limit',
        'duty_auto_ended' => 'Duty auto-ended (forgot END DUTY)',
        'sla_breached' => 'Delivery SLA breached',
        'no_call_before_fail' => 'Failed without calling customer',
        'location_permission' => 'Location not allowed "all the time"',
    ];

    /** Raises an exception unless the same open one already exists for this rider/order. */
    public static function raise(string $rule, string $severity, ?int $riderId, ?int $deliveryId, string $message): ?int
    {
        $dup = Db::val("SELECT exception_id FROM hm_exception WHERE rule_code=? AND status='open' AND rider_id <=> ? AND delivery_id <=> ? LIMIT 1", [$rule, $riderId, $deliveryId]);
        if ($dup) {
            return null;
        }
        $id = Db::insert('hm_exception', [
            'rule_code' => $rule, 'severity' => $severity, 'rider_id' => $riderId, 'delivery_id' => $deliveryId,
            'message' => mb_substr($message, 0, 500), 'status' => 'open', 'created_at' => Fmt::nowUtc(),
        ]);
        if (in_array($severity, ['critical', 'high'], true)) {
            Notify::admin('[' . strtoupper($severity) . '] ' . (self::RULES[$rule] ?? $rule), $message);
        }
        return $id;
    }

    public static function resolve(int $id, int $userId, string $note): bool
    {
        $n = Db::update('hm_exception', ['status' => 'resolved', 'resolved_by' => $userId, 'resolution_note' => mb_substr($note, 0, 500), 'resolved_at' => Fmt::nowUtc()], "exception_id=? AND status='open'", [$id]);
        if ($n) {
            Audit::log('exception.resolve', 'exception', $id, null, ['note' => $note]);
        }
        return $n === 1;
    }
}
