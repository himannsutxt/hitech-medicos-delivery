<?php
declare(strict_types=1);
namespace HMD;

/**
 * Brings an existing database up to date after files are copied over (no manual SQL).
 * Each step is idempotent: it checks before it changes anything, and only ever ADDS columns/indexes.
 */
final class Migrate
{
    public const CURRENT = 2;

    public static function run(): void
    {
        try {
            $v = (int)(Db::val("SELECT svalue FROM hm_setting WHERE skey='schema_version'") ?? 1);
        } catch (\Throwable) {
            return; // not installed yet
        }
        if ($v >= self::CURRENT) {
            return;
        }
        if ($v < 2) {
            self::addColumns('hm_rider', [
                'app_version' => 'VARCHAR(20) NULL', 'gps_on' => 'TINYINT NULL', 'loc_permission' => 'VARCHAR(20) NULL', 'battery_exempt' => 'TINYINT NULL',
                'phone_pending' => 'INT NULL', 'phone_gps_queue' => 'INT NULL', 'last_heartbeat_at' => 'DATETIME NULL', 'last_point_at' => 'DATETIME NULL',
                'last_accuracy' => 'DECIMAL(8,2) NULL', 'last_fix_at' => 'DATETIME NULL',
            ]);
        }
        Db::run('INSERT INTO hm_setting (skey, svalue, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)', ['schema_version', (string)self::CURRENT, Fmt::nowUtc()]);
        Settings::reset();
        Audit::log('db.migrate', 'schema', (string)self::CURRENT, ['version' => $v], ['version' => self::CURRENT], ['type' => 'system', 'id' => null, 'name' => 'Upgrade']);
    }

    private static function addColumns(string $table, array $cols): void
    {
        $have = array_column(Db::all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'COLUMN_NAME');
        foreach ($cols as $name => $def) {
            if (!preg_match('/^[a-z_]+$/', $name) || in_array($name, $have, true)) {
                continue;
            }
            Db::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$name` $def");
        }
    }
}
