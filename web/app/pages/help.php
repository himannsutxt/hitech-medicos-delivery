<?php
declare(strict_types=1);
use HMD\{App, Ui};

$e = [Ui::class, 'e'];
$api = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/api.php';
$body = '<div class="grid2e"><div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-rocket"></i> Setup guide (first day)</h4></div><ol class="help-list">'
    . '<li><b>Store location:</b> Settings → Store location. Search or click your shop on the map and save.</li>'
    . '<li><b>Google Maps (recommended):</b> Settings → Maps. Paste a Google Maps API key (Maps JavaScript, Geocoding and Places APIs, restricted to this website). Without a key, free OpenStreetMap maps are used.</li>'
    . '<li><b>Cron job:</b> in Plesk → Scheduled Tasks add <code>php ' . $e(App::root()) . '/cron.php</code> every 5 minutes. Check it under Settings → System health.</li>'
    . '<li><b>SMS (optional):</b> Settings → SMS / WhatsApp. Paste your provider URL and send a test. Without SMS the app uses photo proof instead of OTP.</li>'
    . '<li><b>Riders:</b> Riders → Add rider. Set a 4-digit PIN.</li>'
    . '<li><b>Install the app</b> on each rider\'s phone (APK). On first open, enter the server address below, then mobile + PIN. The phone gets locked to that rider.</li>'
    . '<li><b>Orders:</b> add one by one, or Orders → Import CSV (download the template first).</li>'
    . '<li><b>Assign:</b> Orders → tick orders → choose rider → Assign.</li>'
    . '<li><b>Rider day:</b> START DUTY at the store → scan parcels → START ROUTE → deliver → come back → hand over undelivered parcels (Warehouse Scan) → Deposit COD (COD page) → END DUTY.</li></ol>'
    . '<div class="eva-alert info">Server address for the app: <code>' . $e($api) . '</code></div></div>'
    . '<div class="eva-card"><div class="eva-h"><h4><i class="fa-solid fa-circle-question"></i> Common questions</h4></div><dl class="kv" style="grid-template-columns:1fr">'
    . '<dt>Where do I see riders live?</dt><dd>Live Tracking in the top menu. Click a rider to follow them with their recent path. Locations come only from the rider app while on duty.</dd>'
    . '<dt>A rider\'s live location is not coming. What do I check?</dt><dd>Open the rider (Riders → name) and look at the <b>App health</b> card. It shows the last GPS received and lists each problem with its fix. The usual ones: Location permission not set to "Allow all the time", GPS switched off, battery saver restricting the app (on Xiaomi/Redmi/Vivo/Oppo also turn on Autostart), no internet, or an old app version. The Riders list filter "App health: Only with problems" shows everyone affected.</dd>'
    . '<dt>What do the (i) icons do?</dt><dd>Hover (or tap on a phone) to see what a field, filter, column or button does.</dd>'
    . '<dt>A rider got a new phone</dt><dd>Riders → open the rider → Reset phone. They can then log in on the new phone.</dd>'
    . '<dt>Rider forgot PIN / account locked</dt><dd>Open the rider → type a new PIN → Set new PIN.</dd>'
    . '<dt>Why is an order flagged "delivered away from address"?</dt><dd>The phone was more than the set distance from the customer pin when the rider tapped Delivered. Check the proof photo and call the customer.</dd>'
    . '<dt>Rider cannot end duty</dt><dd>Parcels are still with them (receive them on Warehouse Scan) or COD cash is not deposited (confirm it on the COD page).</dd>'
    . '<dt>KM looks low</dt><dd>GPS was off or the phone killed the app. Check exceptions for "GPS offline" and make sure battery saver is off for the app.</dd>'
    . '<dt>Where is the data?</dt><dd>In your own MySQL database on your server. Photos are in the storage folder, outside the website.</dd></dl></div></div>';
Ui::page('Help', 'help', $body, 'Version ' . App::VERSION);
