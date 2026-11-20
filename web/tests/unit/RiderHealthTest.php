<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HMD\RiderHealth;

require_once __DIR__ . '/../../app/lib/autoload.php';

/** v1.2: why isn't a rider's live location coming? */
final class RiderHealthTest extends TestCase
{
    private function rider(array $o = []): array
    {
        $now = time();
        return $o + ['device_id' => 'dev', 'last_heartbeat_at' => gmdate('Y-m-d H:i:s', $now - 60), 'last_point_at' => gmdate('Y-m-d H:i:s', $now - 30),
            'gps_on' => 1, 'loc_permission' => 'always', 'battery_exempt' => 1, 'app_version' => '1.2.0', 'phone_pending' => 0, 'phone_gps_queue' => 3,
            'last_accuracy' => 12, 'last_battery' => 80, 'last_lat' => 28.4, 'live_status' => 'available'];
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    public function testHealthyOnDuty(): void
    {
        $this->assertSame([], RiderHealth::check($this->rider(), true, 5, '1.2.0'));
    }

    public function testNeverLoggedIn(): void
    {
        $c = $this->codes(RiderHealth::check($this->rider(['device_id' => null, 'last_heartbeat_at' => null, 'last_point_at' => null]), false, 5, '1.2.0'));
        $this->assertSame(['no_app'], $c);
    }

    public function testOffDutyIsNotAProblem(): void
    {
        $this->assertSame([], RiderHealth::check($this->rider(['last_point_at' => gmdate('Y-m-d H:i:s', time() - 7200)]), false, 5, '1.2.0'));
    }

    public function testEachBlockerIsReportedWithAFix(): void
    {
        $r = $this->rider(['gps_on' => 0, 'loc_permission' => 'whileInUse', 'battery_exempt' => 0, 'app_version' => '1.0.0', 'phone_pending' => 7,
            'last_point_at' => gmdate('Y-m-d H:i:s', time() - 900), 'last_accuracy' => 450, 'last_battery' => 9]);
        $issues = RiderHealth::check($r, true, 5, '1.2.0');
        $c = $this->codes($issues);
        foreach (['gps_off', 'permission', 'battery_saver', 'old_app', 'sync_backlog', 'no_recent_gps', 'poor_accuracy', 'low_battery'] as $want) {
            $this->assertContains($want, $c);
        }
        foreach ($issues as $i) {
            $this->assertNotEmpty($i['fix'], $i['code'] . ' needs a fix text');
            $this->assertContains($i['level'], ['bad', 'warn']);
        }
    }

    public function testAppNotRunning(): void
    {
        $r = $this->rider(['last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 1800), 'last_point_at' => gmdate('Y-m-d H:i:s', time() - 1800)]);
        $this->assertContains('app_silent', $this->codes(RiderHealth::check($r, true, 5, '1.2.0')));
    }
}
