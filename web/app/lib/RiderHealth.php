<?php
declare(strict_types=1);
namespace HMD;

/**
 * Explains why a rider's live location may not be arriving, with the fix for each problem.
 * Pure function over the rider row (heartbeat + GPS fields) so it is easy to test.
 */
final class RiderHealth
{
    public const LATEST_APP = '1.2.0';

    /** @return list<array{code:string, level:string, title:string, fix:string}> */
    public static function check(array $r, bool $onDuty, int $offlineMinutes = 5, string $latestApp = self::LATEST_APP): array
    {
        $ago = static fn(?string $t) => $t ? max(0, time() - strtotime($t . ' UTC')) : null;
        $i = [];
        $add = static function (string $code, string $level, string $title, string $fix) use (&$i): void {
            $i[] = ['code' => $code, 'level' => $level, 'title' => $title, 'fix' => $fix];
        };
        if (empty($r['device_id']) && empty($r['last_heartbeat_at'])) {
            $add('no_app', 'warn', 'The rider has not logged in to the app yet.', 'Install the APK on the rider\'s phone, enter the server address and log in with their mobile and PIN.');
            return $i;
        }
        $hb = $ago($r['last_heartbeat_at'] ?? null);
        $pt = $ago($r['last_point_at'] ?? null);
        if ($onDuty) {
            if ($hb === null || $hb > 900) {
                $add('app_silent', 'bad', 'The app has not contacted the server for ' . ($hb === null ? 'a long time' : intdiv($hb, 60) . ' minutes') . '.', 'The phone may be off, without internet, or the app was closed by the phone. Call the rider: open the app, check mobile data, and turn off battery saver for the app.');
            }
            if ($pt === null || $pt > $offlineMinutes * 60 * 2) {
                $add('no_recent_gps', 'bad', 'No location received ' . ($pt === null ? 'yet on this duty.' : 'for ' . intdiv($pt, 60) . ' minutes.'), 'Check that GPS is on and Location is "Allow all the time" for Hitech Delivery. Ask the rider to open the app once.');
            }
        }
        if (isset($r['gps_on']) && $r['gps_on'] !== null && (int)$r['gps_on'] === 0) {
            $add('gps_off', 'bad', 'GPS (Location) is switched off on the phone.', 'Swipe down from the top of the phone and turn on Location.');
        }
        if (!empty($r['loc_permission']) && $r['loc_permission'] !== 'always') {
            $add('permission', 'bad', $r['loc_permission'] === 'whileInUse' ? 'Location is allowed only while the app is open, so tracking stops when the screen locks.' : 'Location permission is denied for the app.', 'Phone Settings → Apps → Hitech Delivery → Permissions → Location → "Allow all the time".');
        }
        if (isset($r['battery_exempt']) && $r['battery_exempt'] !== null && (int)$r['battery_exempt'] === 0) {
            $add('battery_saver', 'warn', 'Battery saver can stop the app in the background.', 'Phone Settings → Apps → Hitech Delivery → Battery → "Unrestricted" (Xiaomi: also turn on Autostart).');
        }
        if (!empty($r['app_version']) && version_compare((string)$r['app_version'], $latestApp, '<')) {
            $add('old_app', 'warn', 'Old app version ' . $r['app_version'] . ' (latest ' . $latestApp . ').', 'Install the latest APK on the rider\'s phone. Their login and data are kept.');
        }
        if ((int)($r['phone_pending'] ?? 0) > 3) {
            $add('sync_backlog', 'warn', (int)$r['phone_pending'] . ' actions are waiting on the phone to be sent.', 'The phone has no internet or weak signal. Ask the rider to check mobile data, then tap Sync now in the app\'s Profile.');
        }
        if ($onDuty && isset($r['last_accuracy']) && $r['last_accuracy'] !== null && (float)$r['last_accuracy'] > 250) {
            $add('poor_accuracy', 'warn', 'GPS accuracy is poor (' . (int)$r['last_accuracy'] . ' m).', 'Usually indoors or in a basement. It improves outdoors. Phone Settings → Location → turn on "Google Location Accuracy".');
        }
        if ($onDuty && isset($r['last_battery']) && $r['last_battery'] !== null && (int)$r['last_battery'] <= 15) {
            $add('low_battery', 'warn', 'Phone battery is low (' . (int)$r['last_battery'] . '%).', 'The phone may switch off soon and tracking will stop. Ask the rider to charge it.');
        }
        return $i;
    }
}
