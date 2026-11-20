<?php
declare(strict_types=1);
namespace HMD;

/** Admin-configurable settings with defaults from the spec. */
final class Settings
{
    public const DEFAULTS = [
        // General
        'company_name' => 'Hitech Medicos',
        'support_mobile' => '',
        'alert_email' => '',
        // Maps (v1.1)
        'google_maps_key' => '', 'map_type' => 'roadmap', 'live_trail_minutes' => '30', 'live_show_offline' => '1',
        // Tracking
        'gps_interval_moving' => '15', 'gps_interval_slow' => '30', 'gps_interval_stationary' => '180',
        'gps_min_accuracy' => '50', 'gps_max_speed' => '120',
        'customer_geofence_m' => '150', 'away_flag_m' => '300', 'manual_arrive_flag_m' => '500',
        'idle_minutes' => '10', 'idle_alert_minutes' => '30', 'gps_offline_minutes' => '5',
        'auto_end_duty_hours' => '12',
        // Delivery
        'max_attempts' => '3', 'default_sla_hours' => '4',
        'otp_required' => 'cod_or_high', 'otp_high_value' => '5000',
        'photo_on_delivery' => '1', 'photo_on_failure' => '1',
        'require_scan_before_route' => '1',
        // COD
        'cod_limit_default' => '50000', 'block_duty_if_unsettled' => '1',
        // Notifications
        'sms_enabled' => '0', 'sms_url_template' => '', 'sms_sender' => '',
        'whatsapp_enabled' => '0', 'whatsapp_url_template' => '',
        'notify_customer_out' => '1', 'notify_customer_delivered' => '1',
        // Retention
        'retention_enabled' => '0', 'retention_gps_days' => '90', 'retention_photo_days' => '180', 'retention_selfie_days' => '30',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Db::all('SELECT skey, svalue FROM hm_setting');
            self::$cache = self::DEFAULTS;
            foreach ($rows as $r) {
                self::$cache[$r['skey']] = (string)$r['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $k): string
    {
        return self::all()[$k] ?? '';
    }

    public static function int(string $k): int
    {
        return (int)self::get($k);
    }

    public static function set(string $k, string $v): void
    {
        if (!array_key_exists($k, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting ' . $k);
        }
        Db::run('INSERT INTO hm_setting (skey, svalue, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)', [$k, $v, Fmt::nowUtc()]);
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
