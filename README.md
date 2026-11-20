# Hitech Medicos Delivery

Rider tracking and delivery management: an admin **website** (PHP/MySQL) and a rider **Android app** (Flutter).

```
web/        Admin website + rider API (upload to your server)
app/        Android app source (built into an APK by GitHub Actions)
.github/    Build workflows (APK build, website tests)
```

## 1. Install the website (Plesk, about 10 minutes)
1. Plesk → Websites & Domains → add a subdomain, e.g. `delivery.hitechmedicos.in`, and turn on **SSL (Let's Encrypt)**. The app only connects over HTTPS.
2. Upload the contents of `web/` to the subdomain folder, e.g. `/delivery`.
3. Set the **Document root** of the subdomain to `/delivery/public`. Nothing outside `public/` is reachable from the web.
4. Plesk → Databases → create an empty MySQL database and user.
5. Open `https://delivery.hitechmedicos.in` → the setup wizard runs → fill in the database and your admin login.
6. Plesk → Scheduled Tasks → run every 5 minutes: `php /var/www/vhosts/<domain>/delivery/cron.php`
7. Log in → **Settings → Store location** → click your shop on the map → Save.
8. Optional: **Settings → SMS / WhatsApp** → paste your provider's send URL → Send test. Without it, the app uses photo proof instead of OTP.

PHP 8.1+ with pdo_mysql, gd, fileinfo, curl, mbstring. Tested on PHP 8.3 + MariaDB 10.11.

## Upgrading
Copy the new `web/` files over the old ones, keeping `web/app/config.php` and `web/storage/`. Then open the site once and check Settings → System health.

## Google Maps (optional, recommended)
Google Cloud Console → create a project → enable **Maps JavaScript API**, **Geocoding API**, **Places API** → Credentials → Create API key → restrict it to **HTTP referrers** = your website address, and to those 3 APIs. Paste it in **Settings → Maps**. Without a key, OpenStreetMap is used.

## 2. Get the Android app (APK)
1. Push this repository to GitHub (private).
2. Add two repository secrets (Settings → Secrets and variables → Actions):
   - `HMD_KEYSTORE_BASE64`: the base64 of `hitech-release.jks` (sent separately, keep it safe)
   - `HMD_KEYSTORE_PASSWORD`: its password
3. Actions → **Build Android APK** → Run workflow. When it finishes, download `HitechMedicosDelivery-APK` from the run.
4. Pushing a tag like `v1.0.0` also attaches the APK to a GitHub Release, which gives you a download link to share with riders.

Install on each rider phone (allow "Install unknown apps"), open it, enter `delivery.hitechmedicos.in`, then mobile + PIN.

## 3. First day
Riders → Add rider (set a 4-digit PIN) → Orders → add or import → tick orders → Assign.
Rider: START DUTY → scan parcels → START ROUTE → deliver → back at store: hand over undelivered parcels (Warehouse Scan) → DEPOSIT COD (confirm on COD page) → END DUTY.

## Tests
- Website: `cd web && phpunit tests` (needs a MySQL test database, see `tests/bootstrap_db.php`)
- App: `cd app && flutter test`
