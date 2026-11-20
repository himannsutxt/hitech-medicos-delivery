<?php
declare(strict_types=1);
namespace HMD;

final class Install
{
    /** Creates roles, the first Super Admin and a default warehouse. Safe to run once. */
    public static function seed(string $name, string $email, string $password): void
    {
        $now = Fmt::nowUtc();
        foreach (Perm::PRESETS as $role => $perms) {
            Db::run('INSERT IGNORE INTO hm_role (name, permissions, created_at) VALUES (?,?,?)', [$role, json_encode($perms), $now]);
        }
        $rid = (int)Db::val('SELECT role_id FROM hm_role WHERE name="Super Admin"');
        if (!Db::val('SELECT 1 FROM hm_user WHERE email=?', [strtolower($email)])) {
            Db::insert('hm_user', ['role_id' => $rid, 'name' => $name, 'email' => strtolower($email), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 1, 'created_at' => $now]);
        }
        if (!Db::val('SELECT 1 FROM hm_warehouse')) {
            Db::insert('hm_warehouse', ['name' => 'Main Store', 'radius_m' => 200, 'status' => 1, 'created_at' => $now]);
        }
        Db::run('INSERT IGNORE INTO hm_setting (skey, svalue, updated_at) VALUES ("schema_version", ?, ?)', [(string)Migrate::CURRENT, $now]);
        foreach (['daily_summary', 'gps_offline_check', 'auto_end_duty', 'retention_cleanup', 'notify'] as $j) {
            Db::run('INSERT IGNORE INTO hm_job_run (job, failures) VALUES (?,0)', [$j]);
        }
    }

    /** Validates a password for admin users. Returns error text or null. */
    public static function passwordProblem(string $p): ?string
    {
        if (strlen($p) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            return 'Password must contain letters and numbers.';
        }
        return null;
    }
}
