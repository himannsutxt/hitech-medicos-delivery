<?php
declare(strict_types=1);
namespace HMD;

/** Page and action permissions. Roles store a JSON list of keys, or ["*"]. */
final class Perm
{
    public const KEYS = [
        'dashboard.view' => 'View dashboard and live map',
        'orders.view' => 'View orders', 'orders.edit' => 'Add, edit and import orders',
        'orders.assign' => 'Assign and reassign orders', 'orders.cancel' => 'Cancel orders',
        'orders.scan' => 'Warehouse load/return scans',
        'riders.view' => 'View riders', 'riders.edit' => 'Add, edit and disable riders',
        'cod.view' => 'View COD', 'cod.settle' => 'Accept COD settlements', 'cod.writeoff' => 'Write off COD shortages',
        'reports.view' => 'View and export reports',
        'exceptions.view' => 'View exceptions', 'exceptions.resolve' => 'Resolve exceptions',
        'gps.history' => 'View rider route history',
        'settings.edit' => 'Edit settings and warehouses',
        'users.edit' => 'Manage users and roles',
        'audit.view' => 'View audit log and diagnostics',
    ];

    public const PRESETS = [
        'Super Admin' => ['*'],
        'Delivery Manager' => ['dashboard.view', 'orders.view', 'orders.edit', 'orders.assign', 'orders.cancel', 'orders.scan', 'riders.view', 'riders.edit', 'cod.view', 'reports.view', 'exceptions.view', 'exceptions.resolve', 'gps.history'],
        'Dispatcher' => ['dashboard.view', 'orders.view', 'orders.edit', 'orders.assign', 'orders.scan', 'riders.view', 'exceptions.view', 'reports.view'],
        'Warehouse' => ['orders.view', 'orders.scan'],
        'Accounts' => ['cod.view', 'cod.settle', 'reports.view'],
        'Viewer' => ['dashboard.view', 'orders.view', 'riders.view', 'reports.view', 'exceptions.view'],
    ];

    public static function has(?array $user, string $key): bool
    {
        if (!$user) {
            return false;
        }
        $perms = $user['perms'] ?? [];
        return in_array('*', $perms, true) || in_array($key, $perms, true);
    }
}
