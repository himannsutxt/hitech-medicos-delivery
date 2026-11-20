# Feature list for v1.1 — all 4 built in v1.1.0 (20-11-2026)

| # | Feature | Side | Priority | Notes |
|---|---|---|---|---|
| 1 | Live location of each rider | Website + App | Must-have | Dedicated Live Tracking page: all riders on one map + list with last location (place name), speed, battery, current order, last update; click a rider to follow on full map with last 30-min trail; also show last known location of off-duty / offline riders. Details to confirm. |
| 2 | Use Google Maps instead of OpenStreetMap | Website (all maps) + App (navigation already opens Google Maps) | Must-have | Replace map on Dashboard, Live Tracking, Rider route, Order page, Order pin, Store location with Google Maps JavaScript API. Needs a Google Maps API key (Maps JavaScript API; Geocoding API for place names and address search). Key entered in Settings, restricted to the website domain. |
| 3 | Move "Audit & Health" under Settings | Website | Must-have | Remove from top menu; show as tabs inside Settings (Audit log, System health). Same permission (audit.view) still controls access. |
| 4 | Simple Add Rider form: only Name, Mobile, PIN | Website | Must-have | Employee code auto-generated (DEL001, DEL002…). All other fields (emergency mobile, store, vehicle, licence, joined date, limits) removed from the add form; defaults applied. Optional extra fields only on Edit, collapsed under "More details". |
