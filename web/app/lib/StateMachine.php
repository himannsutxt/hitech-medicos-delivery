<?php
declare(strict_types=1);
namespace HMD;

/** Delivery status rules. The API and admin may only move along these arrows. */
final class StateMachine
{
    public const LABELS = [
        'ready' => 'Ready for delivery', 'assigned' => 'Assigned', 'loaded' => 'Loaded',
        'out' => 'Out for delivery', 'en_route' => 'En route', 'arrived' => 'Arrived',
        'delivered' => 'Delivered', 'not_delivered' => 'Not delivered', 'rescheduled' => 'Rescheduled',
        'rto' => 'RTO', 'returned' => 'Back in warehouse', 'cancelled' => 'Cancelled',
    ];

    public const PILL = [
        'ready' => 'muted', 'assigned' => 'info', 'loaded' => 'info', 'out' => 'purple', 'en_route' => 'purple',
        'arrived' => 'purple', 'delivered' => 'ok', 'not_delivered' => 'warn', 'rescheduled' => 'warn',
        'rto' => 'bad', 'returned' => 'muted', 'cancelled' => 'muted',
    ];

    private const T = [
        'ready'         => ['assigned', 'cancelled'],
        'assigned'      => ['loaded', 'ready', 'cancelled'],
        'loaded'        => ['out', 'ready', 'cancelled'],
        'out'           => ['en_route', 'ready'],
        'en_route'      => ['arrived', 'not_delivered'],
        'arrived'       => ['delivered', 'not_delivered'],
        'not_delivered' => ['rescheduled', 'rto'],
        'rescheduled'   => ['ready'],
        'rto'           => ['returned'],
        'delivered'     => [],
        'returned'      => [],
        'cancelled'     => [],
    ];

    /** Statuses in which the parcel is physically with the rider. */
    public const WITH_RIDER = ['loaded', 'out', 'en_route', 'arrived', 'not_delivered', 'rescheduled', 'rto'];

    public const FAIL_REASONS = [
        'customer_unavailable' => 'Customer unavailable at address',
        'not_reachable'        => 'Customer phone not reachable',
        'customer_refused'     => 'Customer refused',
        'reschedule_request'   => 'Customer asked to reschedule',
        'wrong_address'        => 'Wrong or incomplete address',
        'cash_not_ready'       => 'COD cash not ready',
        'customer_cancelled'   => 'Customer cancelled the order',
        'prescription_missing' => 'Prescription not available',
        'area_inaccessible'    => 'Area not accessible',
        'other'                => 'Other',
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::T[$from] ?? [], true);
    }

    public static function afterFailure(string $reason, int $attemptNo, int $maxAttempts): string
    {
        if (in_array($reason, ['customer_refused', 'customer_cancelled'], true) || $attemptNo >= $maxAttempts) {
            return 'rto';
        }
        return 'rescheduled';
    }

    public static function label(string $s): string
    {
        return self::LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }
}
