<?php
declare(strict_types=1);
namespace HMD;

/**
 * Delivery lifecycle. Every status change goes through move(), which checks the
 * state machine, writes the event log and keeps timestamps consistent.
 */
final class Delivery
{
    public static function get(int $id): ?array
    {
        return Db::one('SELECT * FROM hm_delivery WHERE delivery_id=? AND deleted_at IS NULL', [$id]);
    }

    public static function create(array $d, ?int $userId): int
    {
        $now = Fmt::nowUtc();
        $hours = max(1, Settings::int('default_sla_hours'));
        $row = $d + [
            'status' => 'ready', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            'sla_due_at' => null,
        ];
        unset($row['delivery_id']);
        $id = Db::insert('hm_delivery', $row);
        self::event($id, 'created', null, 'ready', 'admin', $userId);
        Audit::log('order.create', 'delivery', $id, null, ['order_no' => $d['order_no']]);
        return $id;
    }

    /** Move a delivery to a new status. Throws DomainException when not allowed. */
    public static function move(array $del, string $to, string $actorType, ?int $actorId, array $extra = [], ?array $geo = null, ?string $deviceAt = null, array $meta = []): array
    {
        $from = $del['status'];
        if (!StateMachine::can($from, $to)) {
            throw new \DomainException("Order {$del['order_no']} cannot move from " . StateMachine::label($from) . ' to ' . StateMachine::label($to) . '.');
        }
        $now = Fmt::nowUtc();
        $set = ['status' => $to, 'updated_at' => $now] + $extra;
        match ($to) {
            'assigned' => $set += ['assigned_at' => $now],
            'loaded' => $set += ['loaded_at' => $now],
            'out' => $set += ['out_at' => $now],
            'delivered' => $set += ['delivered_at' => $now, 'closed_at' => $now],
            'returned', 'cancelled' => $set += ['closed_at' => $now],
            'ready' => $set += ['rider_id' => $set['rider_id'] ?? null, 'sequence' => 0],
            default => null,
        };
        // Optimistic guard: only update if status is still what we read.
        $n = Db::update('hm_delivery', $set, 'delivery_id=? AND status=?', [$del['delivery_id'], $from]);
        if ($n !== 1) {
            throw new \DomainException('This order was changed by someone else. Refresh and try again.');
        }
        self::event((int)$del['delivery_id'], 'status', $from, $to, $actorType, $actorId, $geo, $deviceAt, $meta, $del['rider_id'] ?? null);
        return self::get((int)$del['delivery_id']);
    }

    public static function event(int $deliveryId, string $type, ?string $from, ?string $to, string $actorType, ?int $actorId, ?array $geo = null, ?string $deviceAt = null, array $meta = [], mixed $riderId = null, ?int $dutyId = null, ?string $clientUuid = null): void
    {
        Db::insert('hm_event', [
            'client_uuid' => $clientUuid, 'delivery_id' => $deliveryId, 'duty_id' => $dutyId,
            'rider_id' => $riderId !== null ? (int)$riderId : ($actorType === 'rider' ? $actorId : null),
            'type' => $type, 'from_status' => $from, 'to_status' => $to, 'actor_type' => $actorType, 'actor_id' => $actorId,
            'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null, 'device_at' => $deviceAt,
            'server_at' => Fmt::nowUtc(), 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** Assign (or reassign) a set of deliveries to a rider. Returns [assigned, errors[]]. */
    public static function assign(array $ids, int $riderId, int $userId, string $reason = ''): array
    {
        $rider = Db::one('SELECT * FROM hm_rider WHERE rider_id=? AND status=1 AND deleted_at IS NULL', [$riderId]);
        if (!$rider) {
            return [0, ['Rider not found or disabled.']];
        }
        $ok = 0;
        $errors = [];
        $seq = (int)Db::val("SELECT COALESCE(MAX(sequence),0) FROM hm_delivery WHERE rider_id=? AND status IN ('assigned','loaded','out')", [$riderId]);
        foreach ($ids as $id) {
            $d = self::get((int)$id);
            if (!$d) {
                $errors[] = "Order #$id not found.";
                continue;
            }
            try {
                if ($d['status'] === 'assigned') {
                    if ((int)$d['rider_id'] === $riderId) {
                        continue;
                    }
                    $old = $d['rider_id'];
                    Db::update('hm_delivery', ['rider_id' => $riderId, 'sequence' => ++$seq, 'assigned_at' => Fmt::nowUtc(), 'updated_at' => Fmt::nowUtc()], 'delivery_id=?', [$d['delivery_id']]);
                    self::event((int)$d['delivery_id'], 'reassigned', 'assigned', 'assigned', 'admin', $userId, null, null, ['from_rider' => $old, 'to_rider' => $riderId, 'reason' => $reason]);
                    Audit::log('order.reassign', 'delivery', $d['delivery_id'], ['rider_id' => $old], ['rider_id' => $riderId, 'reason' => $reason]);
                } else {
                    self::move($d, 'assigned', 'admin', $userId, ['rider_id' => $riderId, 'sequence' => ++$seq], null, null, ['rider_id' => $riderId]);
                    Audit::log('order.assign', 'delivery', $d['delivery_id'], null, ['rider_id' => $riderId]);
                }
                $ok++;
            } catch (\DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return [$ok, $errors];
    }

    public static function activeLoad(int $riderId): int
    {
        return (int)Db::val("SELECT COUNT(*) FROM hm_delivery WHERE rider_id=? AND deleted_at IS NULL AND status IN ('assigned','loaded','out','en_route','arrived')", [$riderId]);
    }

    /** Warehouse return scan: parcel physically back. */
    public static function returnScan(string $orderNo, int $userId): array
    {
        $d = Db::one('SELECT * FROM hm_delivery WHERE order_no=? AND deleted_at IS NULL', [trim($orderNo)]);
        if (!$d) {
            return ['ok' => false, 'message' => 'No order found with number ' . $orderNo];
        }
        $map = ['rescheduled' => 'ready', 'rto' => 'returned', 'out' => 'ready', 'loaded' => 'ready'];
        if (!isset($map[$d['status']])) {
            return ['ok' => false, 'message' => "Order {$d['order_no']} is " . StateMachine::label($d['status']) . ' and cannot be return-scanned.'];
        }
        $to = $map[$d['status']];
        $extra = $to === 'ready' ? ['rider_id' => null] : [];
        $d2 = self::move($d, $to, 'admin', $userId, $extra, null, null, ['return_scan' => true, 'rider_id' => $d['rider_id']]);
        Audit::log('order.return_scan', 'delivery', $d['delivery_id'], ['status' => $d['status']], ['status' => $to]);
        return ['ok' => true, 'message' => "Order {$d['order_no']}: received back, now " . StateMachine::label($d2['status']) . '.'];
    }

    /** Whether a delivered event needs an OTP for this order. */
    public static function otpRequired(array $d): bool
    {
        $mode = Settings::get('otp_required');
        if ($mode === 'none' || Settings::get('sms_enabled') !== '1') {
            return false;
        }
        if ($mode === 'all') {
            return true;
        }
        return $d['payment_type'] === 'cod' || (float)$d['order_value'] >= (float)Settings::get('otp_high_value');
    }

    public static function issueOtp(array $d): ?string
    {
        $otp = (string)random_int(1000, 9999);
        Db::update('hm_delivery', ['otp_hash' => hash('sha256', $d['delivery_id'] . ':' . $otp), 'otp_tries' => 0], 'delivery_id=?', [$d['delivery_id']]);
        return $otp;
    }

    public static function checkOtp(array $d, string $otp): bool
    {
        return $d['otp_hash'] !== null && hash_equals($d['otp_hash'], hash('sha256', $d['delivery_id'] . ':' . $otp));
    }

    /** Timeline rows for the order page. */
    public static function timeline(int $id): array
    {
        return Db::all('SELECT e.*, COALESCE(u.name, r.name) AS actor_name FROM hm_event e
            LEFT JOIN hm_user u ON e.actor_type="admin" AND u.user_id=e.actor_id
            LEFT JOIN hm_rider r ON e.actor_type="rider" AND r.rider_id=e.actor_id
            WHERE e.delivery_id=? ORDER BY e.server_at, e.event_id', [$id]);
    }
}
