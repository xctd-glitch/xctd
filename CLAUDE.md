# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Bank Receipt Extractor (`bayu/bank-receipt-webapp`) — a plain PHP 8.3 / MySQL webapp (no framework, no router, no build step) for browser-side multi-bank OCR of Indonesian bank transfer receipts, registered-sender validation, payment calculation, and weekly obligation tracking. See [README.md](README.md) for full user-facing/version-history detail and [SECURITY-NOTE.txt](SECURITY-NOTE.txt) for the security rationale behind specific past fixes — both are release notes, not aspirational docs, and are worth reading before touching security-sensitive code.

## Commands

There is no build step, bundler, test suite, or lint config in this repo (`composer.json` declares no `scripts`, no dev dependencies; no `phpunit.xml`/`phpstan.neon`/`.php-cs-fixer.php` exist).

```bash
composer install --no-dev --optimize-autoloader   # only dependency management step; no packages beyond PHP ext requirements
php -l path/to/file.php                            # syntax-check a changed file; there is no automated way to run this repo-wide except per-file
```

Verification for a change is manual: read the affected entry point end-to-end (see Architecture below), `php -l` every changed file, and reason about the request/response flow yourself — there is nothing to `run`.

### Local install / smoke test

The app has no dev server config beyond plain PHP behind Apache. To smoke test: point a PHP 8.3 + `mod_rewrite` vhost at the repo root, provision an empty MySQL/MariaDB database, and open `/install/` (see `install/index.php` + `src/Installer.php`, which writes `config/private.php` and `config/installed.lock` and imports `sql/schema.sql`).

## Architecture

### Every page is a self-contained entry point, not MVC

There is no router and no shared controller layer. Each top-level PHP file (`index.php`, `login.php`, `logout.php`, `senders.php`, `users.php`, `statistics.php`, `install/index.php`, and everything under `api/`) independently:

1. `require`s `src/Autoload.php` first (this is mandatory — it registers the `App\` PSR-4-ish autoloader *and* pins the production error posture: `display_errors=0`, errors go to the log only, never the response body).
2. Calls `Security::startSession()`, generates a per-request CSP nonce, and calls `Security::sendHeaders($nonce)` (strict CSP, no `unsafe-inline`, HSTS on HTTPS, `Cache-Control: no-store` so Cloudflare/CDNs cannot inject scripts into authenticated HTML).
3. Enforces auth inline (`Auth::requireLogin()` / `Auth::requireAdmin()`), loads `config/app.php` (which just returns `config/private.php` — the real, untracked secrets file; `config/private.example.php` is the template), connects via `Database::connect()`, and does its own request handling (GET render vs POST mutate) in the same file.
4. Renders HTML by dropping out of PHP into inline markup/CSS in the same file — there is no template engine. Business logic, validation, and presentation are interleaved per page by design; don't try to extract a templating layer as a "cleanup" unless asked.

`src/` holds the only reusable/testable logic (`App\` namespace, PSR-4 via `composer.json` and the custom autoloader in `src/Autoload.php` — Composer's own autoloader is not actually used at runtime). Everything else is a procedural entry point.

### OCR is client-side; PHP is the trust boundary

Tesseract.js (loaded from a CDN in `index.php`, driven by `assets/app.php`) does OCR **in the browser**. The server never runs OCR and requires no `proc_open`/Tesseract binary. The browser POSTs only the recognized text (`ocr_text`) plus CSRF; `BankReceiptParser` (server-side, `src/BankReceiptParser.php`; `MandiriReceiptParser` is a back-compat wrapper around it) is the sole authority that turns that text into a `ReceiptData` (sender name, source-account last 4, amount, reference, date, time) via a large set of label-driven regexes tuned for Indonesian bank receipt layouts (Mandiri/Livin, BCA, BRI, BNI, etc.).

### Sender identity: SUBID (alias) is the real key, name is not

Registered senders live in `team_members`. `alias` (user-facing "SUBID") is globally unique and is the authoritative identity; `display_name` may repeat across rows. `TeamRepository::findActiveSender()` resolves an OCR'd sender name in this order: exact active `normalized_alias` match → single active `normalized_name` match → if multiple active rows share that name, a `selected_sender_id` is required and is re-validated server-side against the OCR-derived name (the client cannot pick an unrelated sender).

The pre-upload disambiguation flow is a second network round trip: the browser first POSTs OCR text to `api/sender-options.php` (admin-only, CSRF-protected) to get eligible SUBID options *before* the image itself is ever sent to the server; only after a SUBID is chosen (or auto-resolved) does the real image upload to `index.php` happen. This exists so ambiguous sender names never leak an unrelated SUBID's data and so raw receipt bytes aren't uploaded until identity is settled.

### Payment calculation and weekly obligations

- `PaymentCalculator`: team `XCTD` → `intdiv(amount, 3)` with half-up rounding on the remainder; team `MNX` → amount unchanged. Both are whole-rupiah, no fractional currency.
- `WeeklyObligationService` is the carry-forward engine: weeks run Monday–Sunday; `sync()` (idempotent, called on nearly every page/API load) backfills missing `weekly_payment_obligations` rows for every active sender since their `tracking_start_week`, ages unpaid-and-past weeks from `pending` to `unpaid`, and then allocates unconsumed `payment_transactions` to the **oldest unpaid week first** (FIFO) per sender via `allocatePayments()`. There is deliberately no fixed weekly target amount — "debt" is tracked as a count of outstanding weeks, not a rupiah figure. Read `README.md`'s "Weekly payment carry-forward" section before changing any of this; the rules around disabling/re-enabling senders and blocking deletion while obligations are outstanding are load-bearing business rules, not incidental behavior.
- A MySQL advisory lock (`GET_LOCK('receipt-subid-<id>', ...)`) around the save path in `index.php`, plus a re-check of `WeeklyObligationService::canAcceptPayment()` after acquiring it, prevents two concurrent devices from paying an already-fully-settled SUBID.

### Duplicate/idempotency handling

`payment_transactions` has unique constraints on both `image_sha256` and `reference_no`. `TransactionRepository::save()` runs in a DB transaction; callers catch duplicate-key exceptions via `TransactionRepository::isDuplicateKeyException()` and re-fetch the existing row instead of erroring, so re-submitting the same receipt image is a no-op that reports "Receipt already saved" rather than a 500 or a second row.

### Realtime updates without a framework

`api/transactions.php` is a cursor-based polling endpoint (`after_id`, capped page size) that `assets/app.php` (jQuery) polls with an adaptive interval (faster while the tab is visible, slower when hidden) and merges into the DOM without reloading. A same-origin `BroadcastChannel` (`bank-receipt-live-v1`) additionally syncs multiple tabs on the same device instantly after a save. There is no websocket/SSE layer.

### `assets/*.php` and `sw.php` are not static assets

`assets/app.php`, `assets/pwa.php`, `assets/jquery.php`, `assets/senders.php`, `assets/statistics.php`, and `sw.php` are PHP scripts that emit JS with explicit `Content-Type`/caching headers, not `.js` files. This is intentional (see `SECURITY-NOTE.txt`/README): the production archive intentionally ships no standalone `.js` file to reduce AV/heuristic false positives, and serving through PHP lets these responses share the same header/caching policy as the rest of the app. Don't "simplify" these into static files.

### PWA manifest routing

The manifest is not a static `.webmanifest` file. `index.php` checks `PwaManifest::isRequest()` (`?asset=app-meta`) **before** session/auth/DB init and, if matched, streams `config/pwa-manifest.json` directly and exits — `config/` itself is denied by `.htaccess`, so this route is the only way to reach that JSON. Legacy `pwa.php`/`manifest.php`/`manifest.webmanifest` routes mentioned in old changelog entries no longer exist; don't recreate them.

### Auth, CSRF, and error posture

- Session-based auth only; two roles, `admin` (full write) and `user` (read-only, enforced server-side per entry point via `Auth::requireAdmin()`, not just hidden in the UI).
- Every state-changing POST validates a per-session CSRF token (`Security::validateCsrf`) via `hash_equals`.
- Sign-in throttling is two-layered: a cheap session-counter check, then an authoritative, durable check in `LoginThrottle` (backed by the `login_attempts` table, keyed by packed source IP, 20 failures / 15 min) that a client can't reset by dropping cookies. It's per-IP, deliberately never per-username (see the comment in `src/LoginThrottle.php` for why).
- All five response boundaries that classify exceptions for user-facing messages (`api/sender-options.php`, `api/senders.php`, `index.php`, `install/index.php`, `users.php`) must keep excluding `PDOException` from the "safe to display" set — it extends `RuntimeException`, and displaying it leaks SQL error text. If you add a new boundary doing this kind of exception classification, follow the same exclusion.

### Config

`config/private.php` (untracked, real secrets) is returned wholesale by `config/app.php`; `config/private.example.php` is the checked-in template shape (`db`, `app.timezone`, `ocr`, `upload`, `security`, `realtime` keys). `config/installed.lock` marks a completed install and gates `install/index.php`. `security.setup_key` is written by the installer but read by no code path — known-inert, not a bug to "fix" by wiring it up unless asked.

### Database

Four tables, in `sql/schema.sql` (the single consolidated schema — per-version `sql/migrate_*.sql` scripts are no longer shipped as of v1.10; see README's upgrade section if you need pre-v1.10 migration behavior): `users`, `team_members`, `payment_transactions`, `login_attempts`, plus `weekly_payment_obligations`. All access is PDO with `ATTR_EMULATE_PREPARES => false`, `MYSQL_ATTR_MULTI_STATEMENTS => false`, `ERRMODE_EXCEPTION` (set once in `Database::connect()`).
