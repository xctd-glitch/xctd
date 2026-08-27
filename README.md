# Bank Receipt Extractor v1.10.0

Production PHP 8.3+ / MySQL application for browser-side multi-bank OCR, registered sender/team validation, realtime final output, reporting, and weekly payment obligation carry-forward.

## Core behavior

- Multi-bank browser OCR with Tesseract.js.
- Server-side parser, sender/SUBID lookup, team resolution, calculation, and SQL persistence.
- Admin: full write access. User: read-only dashboard/reporting.
- Unknown or disabled sender identities are rejected before insert.
- SUBID is mandatory and unique. Sender names may repeat; exact SUBID match has priority, while an ambiguous duplicate sender-name match is rejected.
- XCTD final = original amount / 3, normalized half-up to whole rupiah.
- MNX final = original amount, whole rupiah.
- Monetary display uses `IDR x,xxx,xxx`.
- jQuery AJAX, BroadcastChannel, and incremental SQL polling avoid page reloads.
- Full PWA icon set from the provided application icon: favicon, Apple touch, 72/96/128/144/152/192/384/512, and maskable 192/512.
- Scanner-safe production archive contains no standalone `.js` members.

## Weekly payment carry-forward

Each **active registered sender** has one payment obligation per Monday-Sunday week.

- Current open week starts as `pending`.
- If the week closes without an allocated receipt, the obligation becomes `unpaid`.
- `unpaid` obligations remain outstanding and carry forward indefinitely.
- A later receipt settles the **oldest unpaid week first** (FIFO).
- If no backlog exists, the receipt settles the current week's pending obligation.
- Extra receipts do not prepay future weeks.
- Disabling a sender stops new weekly obligations; existing unpaid obligations remain.
- Reactivating a sender starts tracking again from the current week, so disabled gaps are not backfilled.
- Deleting a sender is blocked while pending/unpaid weekly obligations exist.

The application does not invent a monetary debt amount because no fixed weekly target amount exists. Carry-forward is therefore tracked as **outstanding weekly obligations / missed weeks**.

Existing senders upgraded to v1.8.x start weekly tracking from the Monday of the migration week; the migration intentionally creates no retroactive historical debt.

## Setting

Admin layout:

`Sender | SUBID | Location | Team | Add`

Registered rows:

`Sender | SUBID | Location | Team | Status | Save | Delete`

## Statistics

Available to admin and read-only users:

- Week / month / year movement vs previous period.
- Current-month XCTD vs MNX comparison.
- Team share diagram.
- 12-month XCTD/MNX trend chart.
- Monthly reporting table with native hide/show, closed by default.
- Dashboard current-week financial total.
- Dashboard weekly payment status and carry-forward count per sender.

## Fresh installation

1. Upload/extract the package to the cPanel document root.
2. Create an empty MySQL/MariaDB database and database user.
3. Open `/install/` over HTTPS.
4. Complete DB and initial admin setup.
5. Login and add senders under Setting before processing receipts.

Server-side Tesseract and `proc_open()` are not required.

## Upgrade from pre-v1.10 installs

As of v1.10 the per-version migration scripts (`sql/migrate_*.sql`) are no longer shipped. `sql/schema.sql` is the single consolidated schema used by the installer on a fresh database.

For an existing installation created before v1.10, either provision a fresh database and run the installer, or restore the per-version migration scripts from the repository history and run them in version order.

## Production permissions

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 config/private.php 2>/dev/null || true
chmod 600 config/installed.lock 2>/dev/null || true
chmod 700 storage/uploads 2>/dev/null || true
```

## Duplicate receipt hardening

Receipt image SHA-256 uniqueness remains enforced by MySQL. Re-uploading the same image is idempotent: no second transaction is inserted and the UI shows `Receipt already saved`.


## v1.8.2 CSP / Cloudflare / manifest hardening

- Dynamic application responses send `Cache-Control: no-store, no-transform, max-age=0` so Cloudflare does not automatically inject analytics or JavaScript detection code into authenticated application HTML. This preserves the strict application CSP instead of allowing third-party injected inline scripts.
- `script-src` now explicitly includes the per-request CSP nonce while keeping `unsafe-inline` disabled.


## v1.8.4 alias-first sender identity

- Final output columns are `SUBID | Team | Final | Receipt date`; sender name and source-account last4 remain internal and are no longer exposed in Final output.
- Sender names may be registered more than once. SUBID is mandatory and remains globally unique.
- OCR identity resolution checks exact active SUBID first. A sender-name match is accepted only when exactly one active record uses that name; duplicate-name ambiguity is rejected.
- `payment_transactions.team_member_id` binds each payment to the exact sender record so weekly carry-forward cannot mix duplicate sender names.
- Existing v1.8.0-v1.8.3 installs follow the pre-v1.10 upgrade path above; the final consolidated schema has sender-name uniqueness removed and transactions bind via `team_member_id`.

## v1.8.3 manifest routing hardening

- The browser-facing PWA manifest is served by the already-allowed application entry point: `index.php?asset=app-meta`.
- The manifest response exits before session start, authentication, database connection, or dashboard logic.
- Canonical manifest JSON is stored at `config/pwa-manifest.json`, which is not directly web-accessible because `/config` is denied by `.htaccess`.
- Legacy root `pwa.php`, `manifest.php`, and `manifest.webmanifest` endpoints are not used.


## v1.8.5 duplicate-sender SUBID selection

- Browser OCR runs before any receipt image is uploaded to the server.
- The browser sends only OCR text plus CSRF to `api/sender-options.php` to resolve active sender records.
- When one sender name maps to multiple active SUBIDs, an inline SUBID picker is shown and the image remains client-side until the admin selects one.
- The final upload includes `selected_sender_id`; the server reparses OCR and revalidates that the selected record belongs to the detected sender, so the client cannot switch to an unrelated sender record.
- A unique sender/SUBID match proceeds automatically. No database migration is required from v1.8.4.


## v1.9.0 location and weekly dashboard presentation

- Senders & Teams requires `Location` on new/updated sender records. SUBID remains globally unique while sender names may repeat.
- Weekly payment status columns are `Sender | SUBID | Location | Team | This week | Carry`.
- Weekly periods are Monday through Sunday. On Monday, completed prior-week rows without arrears are replaced by the new current-week state; unpaid prior weeks remain as carry-forward until paid. FIFO settlement remains unchanged.
- Final output is presentation-only `SUBID | Team | Final | Receipt date`; sender name and source-account data remain internal.
- Monthly reporting is collapsible and closed by default.
- Existing installs upgrading to v1.10 follow the pre-v1.10 upgrade path above; the final schema requires Location on sender records.


## v1.9.1 SUBID selection lock

- User-facing `Alias` terminology is renamed to `SUBID`. Existing database columns named `alias`, `normalized_alias`, and `sender_alias` are intentionally retained for upgrade compatibility; no SQL migration is required.
- A SUBID whose current-week obligation is already paid and has no carry-forward is removed from the pre-upload chooser and cannot be submitted again.
- A SUBID with outstanding carry remains selectable so older unpaid weeks can still be settled FIFO.
- Final save uses a MySQL advisory lock per sender record plus authoritative weekly eligibility re-check, preventing concurrent devices from creating a second payment after the SUBID is fully settled.


## v1.9.2 slim-fit UI
Presentation-only density pass: compact cards, tabs, forms, tables, weekly status, reporting, login, and installer. No schema or transaction-flow change.

## v1.9.3 harmonized top header

The `.top` header is now visually consistent across Dashboard, Statistics / Reporting, Senders & Teams, and Users: same compact grid alignment, icon size, title/subtitle density, gap, and bottom spacing. No business logic or database change.


## v1.9.4 equal-width navigation tabs

Admin navigation tabs now use equal-width flex distribution so Dashboard, Statistics / Reporting, Senders & Teams, and Users occupy identical widths regardless of label length. This is presentation-only.

## v1.9.5 full icon refresh

- All PWA and webapp branding icons now derive from the user-provided 512x512 piggy-bank artwork.
- Standard 72/96/128/144/152/192/384/512 icons preserve the uploaded artwork; `icon-512.png` is the exact source file.
- Maskable 192/512 variants use an opaque white background and safe-zone inset so Android launchers can crop without cutting the artwork.
- Apple touch icon uses an opaque white background for predictable iOS rendering.
- `favicon.ico`, shortcut icon, header/login brand icon references, manifest icon entries, and service-worker precache all resolve to the refreshed icon set.
- Service-worker cache is bumped to `bank-receipt-pwa-v1.9.5` so stale icons are evicted after activation.

## v1.9.6 full PWA install parity

- The Install-app affordance (`data-pwa-install`) previously existed only on Dashboard and Login. It is now present on Statistics / Reporting, Senders & Teams, and Users as well, so the install prompt can be triggered from every authenticated page, not just the entry points.
- `apple-mobile-web-app-status-bar-style` is now set consistently on all application pages.
- Presentation/PWA-affordance only: no route, endpoint, authentication, OCR, SUBID, weekly ledger, reporting, or database change. Service-worker cache is bumped to `bank-receipt-pwa-v1.9.6` to ship the updated worker script; the precached asset list is unchanged.

## v1.9.7 error-disclosure and encoding fixes

- Database errors are no longer echoed to the browser. `PDOException` extends `RuntimeException`, so every "user-safe exception" classifier previously treated a raw SQL error as safe and returned its text (SQLSTATE codes, table, column, and constraint names) in the response body. `api/sender-options.php`, `api/senders.php`, `index.php`, `install/index.php`, and `users.php` now exclude `PDOException` and fall back to their generic message. Curated `RuntimeException` / `InvalidArgumentException` messages are unchanged.
- `.htaccess` now denies the extensionless `error_log` file that cPanel writes. The previous `FilesMatch` matched only `.log`, so `/error_log`, `/api/error_log`, and `/install/error_log` were publicly readable. `vendor/` is denied as well; it is never required at runtime.
- `login.php` is valid UTF-8 again. It carried GBK-encoded bytes, one of which rendered the page title as `Sign in ¡¤ Bank Receipt Extractor` in a document declaring `charset=UTF-8`.
- Restored two explanatory lines dropped during the v1.9.2 density pass: the SUBID chooser states that a SUBID must be chosen before the image is uploaded, and the weekly panel states that a receipt settles the oldest unpaid week first.
- `WeeklyObligationService::ensureObligation()` prepares its `INSERT IGNORE` once per instance instead of once per sender per tracked week. Same SQL, same parameters, same semantics.
- No route, endpoint, authentication, OCR, SUBID resolution, weekly ledger, reporting, or database schema change. No migration is required from v1.9.6.

## v1.9.8 durable sign-in throttling

- Failed sign-in attempts were counted in `$_SESSION` only, so discarding the session cookie reset the counter and left brute-force attempts effectively unlimited. Attempts are now recorded server-side per source address in a new `login_attempts` table, which a client cannot reset.
- The limit is 20 failures per address in a 15-minute sliding window; a blocked address gets `HTTP 429` with a `Retry-After` header until the window clears. The check runs before credentials are verified, so a blocked address cannot confirm a valid password. A successful sign-in clears that address's history, and rows are pruned automatically after an hour.
- The existing 5-failure session counter is unchanged and still runs first as the cheap path; the database limit is the authoritative one.
- Counting is deliberately per-address and not per-username. A username-keyed lockout would let an attacker lock the real administrator out on demand; the attempted username is recorded for forensics only.
- New optional config key `security.trusted_proxy_header`. Behind a reverse proxy or CDN every request carries the proxy's address, so all clients would otherwise share one bucket. Set it to `'CF-Connecting-IP'` behind Cloudflare. It defaults to `null` because trusting a client-settable header would reinstate the bypass.
- `login.php` now uses LF line endings like every other page in the project.

The consolidated `sql/schema.sql` creates `login_attempts` on fresh installs. No existing table, column, index, route, endpoint, or business rule is changed, and no data is migrated.

## v1.9.9 production error posture

- `src/Autoload.php`, which every entry point requires first, now sets `display_errors=0`, `display_startup_errors=0`, `log_errors=1` and `error_reporting(E_ALL)`. The application previously inherited whatever `php.ini` provided; shared hosts frequently ship `display_errors=On`, which renders PHP warnings into the response, leaking absolute filesystem paths and injecting markup into pages whose strict CSP and JSON contracts assume the body is exactly what the application wrote. Diagnostics are unchanged in content - they now go only to the server error log.
- `.htaccess` and `storage/uploads/.htaccess` now express their deny rules for both Apache generations, guarded by `<IfModule mod_authz_core.c>`. `storage/uploads/.htaccess` previously used only the Apache 2.2 `Deny from all`, which on an Apache 2.4 server without `mod_access_compat` is an unrecognized directive and makes the directory answer `500` instead of `403`.
- No route, endpoint, authentication, OCR, SUBID resolution, weekly ledger, reporting, or database change. No migration is required from v1.9.8.

Known, unchanged: `security.setup_key` is written by the installer but is not read anywhere in the application. It is inert configuration kept for backward compatibility and is scheduled for removal in a future release.

## v1.10.0 navigation labels and brand icon

- The navigation tab and page title `Statistics / Reporting` is now `Statistics`, and `Senders & Teams` is now `Setting`, on every page that renders the tab strip.
- The `.brand-icon` header image no longer draws a `1px` border, in the base rule and in every density override on all four pages.
- Presentation only. Routes are unchanged: the tabs still point at `statistics.php` and `senders.php`, and no file was renamed. No endpoint, authentication, OCR, SUBID resolution, weekly ledger, reporting, or database change, and no migration is required from v1.9.9.

The rejection message raised by `TeamRepository` still reads `Sender is not registered in Senders & Teams.` It is unchanged deliberately, because it is a business-rule message rather than a navigation label. Rename it separately if the wording should follow the tab.

