# Changelog — Hitech Medicos Delivery

All notable changes. Dates in DD-MM-YYYY (IST).

## [1.1.0] — 20-11-2026 — Your feature list

### Built
- **Live Tracking page** (new top-menu item): full-screen map + rider list with place name, status, current order, speed, battery and last update; search, status filter and sort; click a rider to follow them with their recent path (trail length set in Settings → Maps); grey dots for the last known spot of offline / off-duty riders; refreshes every 10 s; works on phones
- **Google Maps on every map** (dashboard, Live Tracking, rider route, order page, order pin, store location) once a key is added in **Settings → Maps**; Google address search when adding an order and when setting the store; place names on Live Tracking. Without a key, or if Google fails to load, the free OpenStreetMap maps are used automatically
- **Audit log and System health moved into Settings** as tabs; removed from the top menu
- **Add Rider is now Name, Mobile, PIN only**; employee code (DEL001…) is created automatically; vehicle, licence, emergency number and limits are under "More details" on Edit
- **Tooltips everywhere**: every filter, column, KPI tile, setting and button has an (i) explanation (hover on desktop, tap on phones)

### Fixed
- A rider's live position could jump to a GPS glitch point; it now uses the same accuracy and jump filter as the KM calculation
- "Stop following" and other hidden buttons showed on phones; checkbox size in filter bars

### Upgrade from 1.0.0
Copy the new files over the old ones, but keep `app/config.php` and the `storage/` folder. No database changes.

### Pending
Everything listed under 1.0.0 "Pending", plus anything you add to docs/BACKLOG-v1.1.md for the next build.

## [1.0.0] — 14-11-2026 — Phase 1 (core)

### Built — Website (PHP 8.1+ / MySQL, Eva UI)
- Setup wizard (server checks, database, first Super Admin), cron script, `.htaccess` hardening
- Dashboard: live KPIs, live rider map (15 s refresh), riders table, "needs attention" list
- Orders: list with search, 7 filters, sort, pagination (25/50/100), saved views, CSV export
- Bulk actions: assign / reassign (reason required) / unassign / cancel (two confirmations) / export selected
- Add & edit order with map pin, cold-chain flag, priority, deliver-by time
- CSV import with preview, per-row errors, duplicate check, downloadable template
- Order page: full timeline, attempts with travel / at-customer / total time, distance from address, proof photos, event map
- Warehouse return scan (rescheduled → Ready, RTO → Back in warehouse) with "still to come back" list
- Riders: list (Active / Disabled / Deleted tabs), add/edit, licence expiry warnings, set PIN, reset phone, disable, soft delete with restore
- Rider page: day summary, filtered GPS route map, stops list
- COD: deposits awaiting confirmation, settle with shortage remark, rider balances, recover / write-off, ledger with filters + export
- Reports: rider daily report, delivery times, failed deliveries (reason breakdown) — all exportable
- Exceptions: 11 automatic rules, filters, bulk resolve with note
- Settings (7 tabs): general, store location map, GPS & tracking, delivery & proof, COD, SMS/WhatsApp (URL template + test), data retention (off by default) and settings backup/restore
- Users & roles: 6 preset roles, 18 page/action permissions, lockout after 5 wrong passwords
- Audit log (who/what/when, before/after, export) and System health (server checks, cron job status with Run now, error log)
- In-app Help (setup guide, FAQ, app server address)
- Indian defaults: IST, ₹ with Indian grouping, DD-MM-YYYY

### Built — Rider API
- Device-bound login with PIN lockout, 15-min access tokens, rotating refresh tokens with reuse detection
- Duty start/end (geofence check, selfie, blocks end with parcels or COD pending), parcel scan, route start
- Delivery events: start, arrived (auto or manual), delivered (OTP or photo, COD cash/UPI with UTR), failed (reason, photo, reschedule date)
- Idempotent writes (client_uuid) for offline sync; GPS batch ingest with duplicate protection; mock-GPS detection
- GPS filtering (accuracy, jumps, duplicates) and measured vs estimated KM

### Built — Android app (Flutter source + GitHub build)
- English / Hindi, server setup, login, home with KPIs and one main action at a time
- Background GPS (foreground service), adaptive interval, automatic arrival detection
- Offline outbox: every action and photo queued and synced in order
- Delivery list, order page (call, navigate), deliver sheet (OTP / photo / COD), can't-deliver sheet, barcode scan, COD deposit, profile with sync problems
- GitHub Actions workflow: analyze, unit tests, signed release APK

### Pending (Phase 2 / 3, from the spec)
- Breaks and idle-time sessions in the time breakdown
- Morning COD confirmation, customer tracking page, "3 stops away" alert, customer rating
- Delivery slots at checkout, same-day promise, reverse pickup, exchange delivery
- Route optimisation, auto-assign suggestions, rider score, incentives → HRMS payroll
- UPI payment webhooks (Razorpay/Paytm) instead of UTR entry, masked calling (Superfone)
- Document expiry reminders by WhatsApp, SOS button, doorstep video, failure heatmap, Ask Eva
