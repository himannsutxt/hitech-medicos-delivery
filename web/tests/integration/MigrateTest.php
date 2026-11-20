<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HMD\{Db, Migrate};

require_once __DIR__ . '/../bootstrap_db.php';

/** v1.2: copying new files over v1.1 must upgrade the database by itself, without losing data. */
final class MigrateTest extends TestCase
{
    public function testUpgradeFromV11AddsColumnsOnceAndKeepsData(): void
    {
        hmd_test_reset();
        Db::insert('hm_rider', ['employee_code' => 'DEL009', 'name' => 'Old Rider', 'mobile' => '9876500009', 'pin_hash' => 'x', 'capacity' => 30, 'cod_limit' => 50000, 'status' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        foreach (['app_version', 'loc_permission', 'last_fix_at'] as $c) {
            Db::pdo()->exec("ALTER TABLE hm_rider DROP COLUMN $c");
        }
        Db::run("UPDATE hm_setting SET svalue='1' WHERE skey='schema_version'");
        Migrate::run();
        $cols = array_column(Db::all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hm_rider'"), 'COLUMN_NAME');
        foreach (['app_version', 'loc_permission', 'last_fix_at', 'last_point_at'] as $c) {
            $this->assertContains($c, $cols);
        }
        $this->assertSame('2', (string)Db::val("SELECT svalue FROM hm_setting WHERE skey='schema_version'"));
        $this->assertSame('Old Rider', Db::val("SELECT name FROM hm_rider WHERE employee_code='DEL009'"));
        Migrate::run(); // second run is a no-op
        $this->assertSame(1, (int)Db::val("SELECT COUNT(*) FROM hm_audit_log WHERE action='db.migrate'"));
        hmd_test_reset();
    }
}
