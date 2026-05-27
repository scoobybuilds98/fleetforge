# FLEETFORGE — CLAUDE CODE QUICK REFERENCE
**Read this file FIRST in every session. Then read FLEETFORGE_PROGRESS.md for your session assignment.**
**This file is ~500 lines. The spec is 3,841. This is your cheat sheet — the spec is the law.**

---

## ⚠️ BUILD STRATEGY — READ THIS BEFORE EVERY SESSION
Before listing what needs to be built, scan the actual files 
on disk and identify what already exists vs what is missing.
Only build what is missing.
**We are building everything locally first. AWS Lightsail does not exist yet.**

- All 150 sessions build and test on the local machine (Mac)
- Local PHP: Laravel Herd — site runs at `http://fleetforge.test`
- Local MySQL: Homebrew MySQL 8.0 — host 127.0.0.1, user root, pass fleetforge123, db fleetforge
- Local files: `STORAGE_DRIVER=local` — uploads go to `storage/` folder on disk, NOT S3
- Local email: SES keys are blank — Mailer writes to `logs/mail.log` instead of sending
- `APP_ENV=development` — session cookies are HTTP-safe (cookie_secure=0)

**What this means for your code:**
- Never hardcode `/var/www/fleetforge/` paths — use `dirname(__DIR__, N)` always
- Never hardcode `https://` in redirect or cookie logic — read from `APP_URL`
- Always check `FF_ENV` before enabling production-only behaviour (cookie_secure, HSTS, etc.)
- Build both drivers in StorageClient (local + S3) — but local is active now
- Build Mailer with SES — but add a dev fallback that logs to file when keys are blank
- The spec mentions AWS/Lightsail/S3/SES throughout — that is the production target.
  Build toward it, but don't require it to work locally.

**When we're ready to go live (after all sessions complete):**
1. Provision Lightsail — follow AWS SETUP GUIDE in FLEETFORGE_PROGRESS.md
2. Create S3 bucket + IAM user (15 min in AWS console)
3. Configure AWS SES sending domain (30 min)
4. `git pull` on server — code is identical, zero changes
5. Fill in production `.env` — flip APP_ENV, STORAGE_DRIVER, add AWS keys
6. Import local database to production
7. Done — no code changes required, only `.env` changes

---

## 0. LOCKED ARCHITECTURE DECISIONS — DO NOT DEVIATE

| # | Decision | Value |
|---|----------|-------|
| D7 | **Base URL path** | **`/fleetforge`** subpath. URLs are `yourdomain.com/fleetforge/auth/login`, `yourdomain.com/fleetforge/portal/`, `yourdomain.com/fleetforge/api/v1/...` |
| D8 | Server | Building locally first (Laravel Herd + MySQL via Homebrew). Lightsail provisioned later when ready for live URL. |
| D1 | Auth | Custom PHP (bcrypt/sessions) — NOT Auth0 |
| D9 | Storage | S3 via `StorageClient.php` abstraction. `STORAGE_DRIVER=local` for dev, `s3` for prod. |
| D10 | Email | AWS SES SMTP via `Mailer.php`. SES keys blank locally — Mailer logs to file in development. |
| D11 | Tax rates | **Looked up at invoice time** — TaxCalculator reads from tax_rates table. Never frozen on lease. |
| D23 | Invite token | **7 days** expiry. Resolves 3-way conflict in spec. Single-use, stored hashed. |
| D24 | Composer | `composer.json` has BOTH `mpdf/mpdf` AND `aws/aws-sdk-php`. Spec comment "mPDF only" is stale — do NOT remove AWS SDK. |
| D14 | Day counting | Inclusive: `(end - start) + 1`. Mar 10 to Mar 10 = 1 day. |
| D16 | Monetary math | bcmath only. Never float. Strings between functions. Scale=6 intermediate, 2 final. |
| D17 | Autoloading | PSR-4 via Composer. `FleetForge\\` → `lib/`. |
| D18 | Cross-currency | BLOCKED. Payment currency must match invoice currency. 422 CURRENCY_MISMATCH. |
| D19 | Optimistic lock | All update endpoints compare `updated_at`. 409 STALE_DATA if modified. |
| D20 | Row locking | FOR UPDATE on: lease create, lease close, payment allocate, credit apply. |
| D21 | Cron locks | MySQL `GET_LOCK()` on all write-heavy crons. |
| D22 | Tax exemption | `gst_exempt` and `pst_exempt` are independent booleans. |
| D130 | **Mid-session scope discipline — expansion AND contraction** (locked, extended 2026-05-11 via S-D130-EXTENSION) | When a session's scope expands beyond its locked D-* decisions during execution, Claude Code MUST (a) get explicit operator re-authorization before the expanded change, (b) document the expansion in the SESSION LOG with original-scope-vs-final-scope diff, (c) update or annotate the affected D-* decision rather than silently exceeding it. **Symmetric rule applies to scope CONTRACTION:** if mid-session it becomes clear an originally-scoped item is out of scope, already shipped, or being deferred, the contraction also requires explicit operator re-authorization + SESSION LOG diff capturing original-scope vs final-scope. Silent omission of scoped items is equally a discipline failure as silent addition. Origin (expansion): S-INVOICE-SHOW-RESPONSIVE mid-session app.css edit beyond D-A's page-scoped CSS rule. Origin (contraction): S-MILEAGE-RATE-ZERO-FIX C1 silently dropping smoke gate work + INV-27/INV-84 voids without operator re-authorization. Full body + carry-forward in `FLEETFORGE_PROGRESS.md` DECISIONS D130. (S-INVOICE-SHOW-RESPONSIVE follow-up + S-D130-EXTENSION) |
| D134 | **Rate-table `equipment_type` namespace** (locked 2026-05-07 via S-LOOKUP-RATES-NAMESPACE) | `equipment_type` columns in `customer_equipment_rates` and `rate_card_items` store `equipment_templates.category` (e.g., `'dry_van'`), NOT `equipment_templates.name` (e.g., `'53ft Dry Van'`). All writers, readers, and lookup queries align on category. Outlier was `api/v1/leases/lookup_rates.php` line 82 — fixed in commit 7a45534. Future code touching these tables must respect this contract. See D134 in `FLEETFORGE_PROGRESS.md` for full rationale + carry-forward. |
| D135 | **Mileage tier — three-configuration matrix** (locked 2026-05-07 via S-MILEAGE-ALLOWANCE-ZERO-FIX, clarifies D133) | InvoiceGenerator keys `$mileageBillingExpected` on `mileage_rate_km > 0` (operator's per-km billing intent), NOT on `estimated_mileage_km > 0`. Three valid configurations admitted: **(Model C)** `rate>0 + allowance>0` → bill only excess over allowance; **(Model B Lite)** `rate>0 + allowance=0` → bill every km from km 0 (allowance=0 interpreted as "no included km"); **(Disabled)** `rate=0 + allowance=0 + precharge_enabled=0` → no mileage billing. INVALID configurations rejected by I4 (intent without rate), I5 (positive distance against zero-rate lease), I6 (Model B Lite silent-skip). See `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` §13.8 "Three valid configurations (D135)" subsection for the configuration table + engine behaviors. Full body + carry-forward in `FLEETFORGE_PROGRESS.md` DECISIONS D135. (S-MILEAGE-ALLOWANCE-ZERO-FIX / D-A through D-F; promoted here via S-D135-REFERENCE-PROMOTE) |
| D136 | **Multi-agent discipline** (locked 2026-05-07 via S-MULTI-AGENT-DISCIPLINE-IMPL) | Only one write-mode Code Desktop session at a time should be active on `/Users/avi/Documents/fleetforge`. **Default mode:** single-agent serialization on main. Active session is registered in `FLEETFORGE_CURRENT_SESSIONS.md` with status `IN-FLIGHT` plus start timestamp, agent identifier (operator-supplied label such as `desktop-1`, `desktop-2`), and the file/path domains expected to be touched. Every session start runs a pre-flight read of CURRENT_SESSIONS.md and halts for operator direction if any other session is marked IN-FLIGHT with overlapping file domains. **Read-only exemption:** audits, surveys, decision-surfacing, and any session that does not commit to main are exempt from serialization. They register as `IN-FLIGHT-RO` and may run concurrently with a write-mode session. Multiple IN-FLIGHT-RO sessions may also run concurrently. **Branch isolation fallback:** when the operator explicitly opts into parallel write work, agents use `session/<S-LABEL>-<YYYYMMDD-HHMM>` branches with the same D131 smoke gate per branch. Merge to main is operator-approved and includes a post-merge smoke run. Both branch SESSION LOG entries document the parallel split. **Author identity caveat:** git's author field is uniform across all Code Desktop agents. Reconstructing parallelism after the fact requires the SESSION LOG narrative + commit timestamps. K-6 (S-MILEAGE-ALLOWANCE-ZERO-FIX C4, commits 764abf1 + ef050e7 same-session overlap) and S-ACCT-AUDIT (b74b947 mid-session injection, SESSION LOG entry backfilled in this same commit) on 2026-05-07 are the canonical incidents that triggered this discipline; pre-K-6 history shows zero parallel events in 177 commits. **Registration commit requirement (locked 2026-05-11 via S-D136-COMMIT-DISCIPLINE):** IN-FLIGHT registration in `FLEETFORGE_CURRENT_SESSIONS.md` must be COMMITTED to main before any subsequent operation (file edit, DB write, branch creation). Working-tree-only registration does not count as registered. The branch isolation fallback above addresses collision avoidance, not state-divergence avoidance — without the commit step, a session that registers IN-FLIGHT in working tree and crashes leaves the registration invisible to a fresh agent reading the file, and a downstream session can create a branch from working-tree carryover state that lands on main under false premise. Origin: S-ACCT-FIX-A1 (2026-05-07) registered IN-FLIGHT in working tree, crashed without committing; downstream session S-DOC-STATUS-RECONCILE-CLOSE drafted under false premise that registration had reached main, requiring a post-merge S-DOC-STATUS-RECONCILE-CLOSE-FIXUP. Pre-flight check Step 1.5 (CURRENT_SESSIONS.md `pre-flight check` section) verifies the registration is committed-clean before subsequent edits. **Operator commit rule (locked 2026-05-19 via S-DISCIPLINE-LOCK-B):** while any session is IN-FLIGHT in `FLEETFORGE_CURRENT_SESSIONS.md`, the operator must NOT push unrelated changes to main. Unrelated changes bundled into the same commit as an IN-FLIGHT session create traceability noise and make commit-ref backfills ambiguous — both S037-REC and S037-CRUD required split-commit disclosures because operator changes landed mid-flight (S037-REC: 50887dd S-PERM-USERS-ACCESS-WALL bundled 5 S037-REC files; S037-CRUD: 2b4b375 S-DASHBOARD-MOBILE-LAYOUT bundled 8 S037-CRUD API files). **Correct workflow:** Option A (preferred) — wait for the IN-FLIGHT session to SHIP, then push the operator change as its own clean commit. Option B (if urgent) — coordinate with Claude Code to pause the session, push the operator change, then resume with an explicit note in `CURRENT_SESSIONS.md` that the session was paused. **Never** `git add . && git push` while a session has uncommitted work in the working tree alongside operator changes. See `D-D136-OPERATOR-COMMIT` in `FLEETFORGE_PROGRESS.md` DECISIONS for the full lock. **Enforcement:** relies on agent discipline reading CURRENT_SESSIONS.md, same model as D131 (smoke gates) and D127 (parity). No automated locking, no git hooks (MVP — defer until soft lock proves insufficient). See D136 in `FLEETFORGE_PROGRESS.md` SESSION LOG for full S-MULTI-AGENT-DISCIPLINE-IMPL trace + S-D136-COMMIT-DISCIPLINE extension + S-DISCIPLINE-LOCK-B operator-commit extension. |
| K-14 | **Pre-deploy obligations checklist file** (locked 2026-05-12 via S-PREDEPLOY-CHECKLIST-CREATE) | Pre-deploy work items live in `FLEETFORGE_PREDEPLOY_CHECKLIST.md` — the canonical home for `.env` keys that need real prod values, asset-version bumps that don't carry through gitignored `.env`, AWS infrastructure provisioning (Lightsail, S3, SES, SNS, IAM, CloudWatch), DNS records, data-state preconditions (AR drift), smoke + verification procedures, rollback runbooks, and post-deploy monitoring setup. **Category separation discipline (K-14):** bug-shaped work goes to `FLEETFORGE_PROGRESS.md` → KNOWN ISSUES (`#NNN`); session-shaped work goes to `FLEETFORGE_CURRENT_SESSIONS.md` queue; pre-deploy work goes to `FLEETFORGE_PREDEPLOY_CHECKLIST.md`. Filing pre-deploy obligations under the wrong category is documentation divergence (K-12 class). Each item there carries Originating session + Surfaced into checklist + Detail (with "Original source:" pointer) + Action + Owner + Status. Initial backfill = 21 obligations originally scoped (1 operator-specified A1 FF_ASSET_VERSION + 1 operator-specified F1 AR drift + 19 pre-existing scattered across SPEC_FINAL.md / REFERENCE.md / runbooks / .env), expanded to 26 in the final file across 8 active categories (A,B,C,D,F,G,H,I) + 1 empty placeholder (E) + 1 References section (J). See K-14 in `FLEETFORGE_PROGRESS.md` KEY LEARNINGS for the discipline rule + S-PREDEPLOY-CHECKLIST-CREATE SESSION LOG row for the creation context + backfill inventory. |
| K-22 | **Planning-chat prompt drafting must trust on-disk canonical-file state over handoff-prompt narrative** (locked 2026-05-13 via S-K22-LOCK) | Before drafting build prompts that depend on factual claims — session status (QUEUED / IN-FLIGHT / SHIPPED), schema state, migration count, file directional conventions (SESSION LOG is ascending; Recent ship history is descending-within-date) — the planning chat MUST verify against the relevant canonical file directly, or include explicit pre-flight verification instructions for Code Desktop. The handoff-prompt narrative is a chat artifact subject to staleness; canonical-file state is authoritative. **Source incidents (all 2026-05-13):** S-CHECKLIST-DRIFT-FIX (directional + migrate-count drift surfaced mid-session); S-PROD-2-DOCS-RECONCILE (session-status drift — prompt arrived as 8-commit build but S-PROD-2 had SHIPPED 2026-05-02; operator authorized 3-commit docs-only reconcile); S-DOCS-REORG-RESIDUAL (working-tree residual from doc reorg surfaced at S-CHECKLIST-DRIFT-FIX close). 3-session cleanup cost ≈ 45 min Code Desktop capacity that would not have been needed had originating prompts verified before issuance. **Companion memory entry** at `/Users/avi/.claude/projects/-Users-avi-Documents-fleetforge/memory/feedback_trust_file_over_prompt.md` captures the Code-Desktop-side version; K-22 captures the planning-chat-side version. Both sides apply the same trust-file principle at different scopes. See K-22 in `FLEETFORGE_PROGRESS.md` KEY LEARNINGS for full body + source-incident citations. |
| D202 | **Production web server is nginx — not Apache** (locked 2026-05-16 via S-NGINX-PROD-CONFIG) | nginx only on Lightsail fleetforge-prod. Apache not installed. `.htaccess` inert in prod. SCRIPT_FILENAME hardcoded to `/var/www/fleetforge/public/index.php` in a `location /` block — **never** `$realpath_root$fastcgi_script_name` (resolves API requests to nonexistent paths under `public/` because API files live at `/var/www/fleetforge/api/`; was root cause of the 2026-05-16 initial-deploy AJAX 404 incident). Static assets via `location ^~ /fleetforge/assets/` alias. Config: `/etc/nginx/sites-enabled/fleetforge`. Canonical runbook with full working config + how-to-update + post-change verification: `docs/runbooks/nginx_config.md`. PREDEPLOY G7 captures the pre-deploy verification step. PHP-FPM: `unix:/var/run/php/php8.2-fpm.sock`. PHP 8.2. |

**Routing — base path `/fleetforge`:**
```php
// public/index.php — router (D7: /fleetforge subpath)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/fleetforge';

if (str_starts_with($path, $base . '/portal'))       → app/portal/
elseif (str_starts_with($path, $base . '/api'))      → api/
elseif (str_starts_with($path, $base . '/webhooks')) → webhooks/
else                                                  → app/admin/
```

---

## 1. THE SEVEN FILES (read order every session)

1. **This file** — patterns, signatures, traps (read first, every session)
2. **FLEETFORGE_PROGRESS.md** — find your session, read the contract
3. **FLEETFORGE_SPEC_FINAL.md** — read ONLY the sections relevant to your session
4. **FLEETFORGE_DATABASE_MASTER.sql** — sole schema source (reference as needed)
5. **FLEETFORGE_DESIGN_DETAILS.md** — exact CSS hex values, component specs (needed for any UI session)
6. **FLEETFORGE_ACCOUNTING_SPEC.md** — reference only until accounting sessions (S111+)
7. **composer.json** — do not modify without checking D24 first

Never start coding before reading files 1 and 2.

**Deployment-time companion (not in session read-order):**
- **FLEETFORGE_PREDEPLOY_CHECKLIST.md** — canonical pre-deploy obligations file. Items live in 9 categories (A asset cache, B prod .env keys, C DNS, D AWS infra, E data migrations, F accounting state, G smoke procedures, H rollback procedures, I post-deploy monitoring, J references). Read this AT cutover time and walk each ITEM by category. Session work that surfaces new pre-deploy obligations should file them here (not in KNOWN ISSUES or CURRENT_SESSIONS). Discipline locked as K-14 in PROGRESS.md → KEY LEARNINGS; cross-ref §0 LOCKED DECISIONS row above.

---

## 1A. LOCAL DEVELOPMENT ENVIRONMENT

**We build locally first. Lightsail is provisioned later when a live URL is needed.**

```
Local URL:      http://fleetforge.test     (via Laravel Herd)
PHP:            8.2 via Laravel Herd
MySQL:          8.0 via Homebrew
DB host:        127.0.0.1
DB port:        3306
DB user:        root
DB password:    fleetforge123
DB name:        fleetforge
Project folder: /Users/avi/Documents/fleetforge
```

**Local .env values (development):**
```
APP_ENV=development
APP_URL=http://fleetforge.test/fleetforge
APP_DEBUG=true
APP_TIMEZONE=America/Vancouver
STORAGE_DRIVER=local
AWS keys: leave blank — StorageClient uses local driver
SES keys: leave blank — Mailer logs to file in development
```

**⚠️ CRITICAL — session.cookie_secure must be environment-aware:**

`session.cookie_secure = 1` breaks login on HTTP. Local dev uses HTTP, not HTTPS.
`config/app.php` MUST set this dynamically — never hardcode 1:

```php
// In config/app.php — after loading .env:
ini_set('session.cookie_secure',   FF_ENV === 'production' ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime',  '28800');
```

Do NOT set `session.cookie_secure` in php.ini. Control it from code via FF_ENV.

**StorageClient local driver** stores files in `storage/uploads/` on disk.
Same interface as S3 driver — all code calls `StorageClient::upload()` regardless.

**Mailer in development** — when SES keys are blank, log emails to `logs/mail.log` instead of sending. Never throw a fatal error for missing mail credentials in development.

**Cron jobs** — do not set up crontab locally. Test cron scripts by running them manually:
```bash
php /Users/avi/Documents/fleetforge/cron/invoice_generate_monthly.php
```

**HTTPS-only features** (QR codes with https:// URLs, HSTS header) — build them with the production URL pattern. They'll reference `https://` but that's fine — it's just a string value from APP_URL.

---

## 1B. KEY CONVENTIONS — one-line rules

Quick-reference index of file-location and helper-naming rules. Detailed treatments live in §11 COMMON TRAPS where indicated.

- **Sidebar nav:** edit `config/navigation.php` (array source of truth), NOT `app/views/layout/sidebar.php` (path doesn't exist) or `includes/sidebar.php` (renderer only). See `FLEETFORGE_DESIGN_DETAILS.md` §3 for the array structure + §11 Trap 53/54/55/56/57 for the K-22 family this rule resolves.
- **Settings read:** `settings_get($key, $default)` — NOT `setting()` or `get_setting()`. See §11 Trap 54.
- **Settings write:** no global helper — use the `INSERT … ON DUPLICATE KEY UPDATE` idiom (reference: `api/v1/settings/brand.php::ff_settings_write` or `lib/QuickBooksClient::settings_write_qbo`).
- **D131 smoke paths:** `tests/_smoke_*.php` (NOT `bin/smoke/*.php` — that directory doesn't exist; only `bin/migrate.php` and `bin/deploy.sh` live in `bin/`). See §11 Trap 53.
- **Icons:** `'icon' => 'name'` resolves to `public/assets/icons/{name}.svg` — verify the file exists before using; missing icons silently placeholder, do not throw. See §11 Trap 55.
- **Current user name:** `current_user()['name'] ?? 'system'` — NOT `current_user_name()` (doesn't exist). Only `current_user_id()` has a dedicated shorthand. See §11 Trap 56.
- **audit_log.action ENUM:** use `'update'` for edits, NOT `'edit'` (not in ENUM); use `'bulk_action'` for imports, NOT `'import'`. See §11 Trap 10 + Trap 57.
- **Sentry:** IS installed (since S-PROD-2 2026-05-02). Call `\FleetForge\Observability\Sentry::captureException($e)` directly — no `class_exists` guard needed. For structured tags+extra per spec §13.5 use `\Sentry\withScope()` on top of the wrapper. See §11 Trap 58.
- **QBO Pusher convention (S-QBO-3, D-QBO-3-2):** Pusher classes follow `{EntityType}Pusher` naming (snake_case entity_type → PascalCase + 'Pusher' suffix; e.g. `credit_memo` → `CreditMemoPusher`). Operations map to methods: `create` → `pushCreate`, `update` → `pushUpdate`, `void` → `pushVoid`, `delete` → `pushDelete`. Signature: `public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array`. Dispatcher (`lib/QboPusherDispatcher.php`) checks two namespace candidates per lookup: `FleetForge\QboPushers\<Name>` (preferred) then `FleetForge\<Name>` (fallback). The first Pusher session (S-QBO-5 customers) picks the actual on-disk location; both work without dispatcher changes. Convention-based — no registry array to maintain.
- **QBO Pusher namespace (deferred until S-QBO-5/6):** `QboPusherDispatcher::dispatch()` tries `FleetForge\QboPushers\<Name>` first, then falls back to `FleetForge\<Name>`. The first Pusher session (S-QBO-5 or S-QBO-6) picks one of these two paths and the choice locks the convention for all subsequent Pushers. **Recommendation: use `FleetForge\QboPushers\<Name>`** (domain-grouped, matches `FleetForge\Accounting\*` / `FleetForge\GPS\*` / `FleetForge\Notifications\*` etc.); reserve top-level `FleetForge\<Name>` for facade/client classes only (`QuickBooksClient`, `QuickBooksSync` are the existing top-level outliers per D-QBO-CLIENT-LOCATION).
- **NotificationService routing — when permission seeding is incomplete:** `NotificationService::notify()` with `$specificUserIds=null` routes via `TYPE_TO_MODULE` → `user_permissions` JOIN. If `user_permissions` rows are missing for the relevant module (common during Phase QBO buildout before `S-PERM-QBO-SEED` ships — see D-QBO-3-PERM-GAP), pass `$specificUserIds` explicitly with resolved super_admin + role-specific user IDs. Precedent: `cron/qbo_token_refresh.php` (S-QBO-1), `cron/qbo_sync_worker.php` (S-QBO-3). Pattern documented in D-QBO-3-3.
- **OAuth callbacks (D-S-QBO-1-OAUTH-DOCS-LOCK):** must be auth-context-free, state verification via DB-backed token (NOT PHP session). Reason: cross-origin redirects (e.g. ngrok during local dev, or any redirect that lands on a different domain than the one that issued the session cookie) lose the session between init and callback — `$_SESSION` is empty, `require_auth()` fails, state lookup returns null. The state token IS the authentication proof, not the user session. See §11 Trap 59 + S-QBO-OAUTH-FIX (queued).
- **K-22 trap catalog discipline (D-TRAP-CATALOG-SWEEP):** when a new K-22 trap describes a wrong CODE pattern (function calls, column refs, ENUM values, type-name conventions), the docs commit cataloguing the trap MUST also `grep -rn '<wrong-pattern>' app/ lib/ api/ cron/ includes/ public/` and either patch the hits inline OR queue a remediation session OR document the deferral. Trap-without-remediation lets the bug stay in production code (cf. K-22 Trap #55 sat docs-only for 4 hours before re-biting during sandbox OAuth setup). Documentation-only traps (UI conventions, naming rules for future code, design-system patterns) are exempt — they have no "wrong code" surface to grep for. See §11 Trap 59 source + D-TRAP-CATALOG-SWEEP in PROGRESS.md DECISIONS.
- **DB helpers — single-row vs multi-row vs write:** `db_row($sql, $params)` for single-row fetch, `db_select($sql, $params)` for multi-row list, `db_execute($sql, $params)` for INSERT/UPDATE/DELETE returning affected rows, `db_insert($table, $data)` for named-column INSERT, `db_count($sql, $params)` for COUNT(*) aggregates, `db_transaction($closure)` for transactional blocks. **NEVER** `db_query_one` or `db_query` — those names don't exist in the project. See §11 Trap 61.
- **`acc_qbo_sync_queue` schema:** `status` default is `'queued'` (NOT `'pending'`); column is `next_retry_at` (NOT `next_attempt_at`); error fields are `error_message` (text) + `error_code` (varchar 50, categorical); the row-history columns are `enqueued_at` / `picked_up_at` / `completed_at` (NOT `last_attempted_at`). Full verified column list in §11 Trap 62.
- **`QuickBooksClient` entity ops (D-PUSHER-CONTRACT):** `createEntity('<entity>', $payload)` + `updateEntity('<entity>', $qboId, $syncToken, $payload, $opts)` — single typed entry points with entity-name string as first argument. **NEVER** invent per-entity wrappers (`createCustomer`/`updateVendor`/etc.) — they don't exist. Pass `sparse` via `$opts`, NOT inside `$data` (footgun: `$opts['sparse'] ?? false` overwrites a `$data['sparse']` key on the array merge). See §11 Trap 63.
- **Pusher class contract (D-PUSHER-CONTRACT, QUICKBOOKS_SPEC §6.8):** `pushCreate(int $entityId, ?array $payloadSnapshot = null): array` + `pushUpdate(int $entityId, ?array $payloadSnapshot = null): array` — separate public-static methods per operation, NOT a combined `::push($id, $op, $payload)`. Dispatcher `QboPusherDispatcher::OPERATION_METHODS` maps `'create'→'pushCreate'`, `'update'→'pushUpdate'`, etc. Shared logic via private `pushImpl()`. Reference impl: `lib/QboPushers/CustomerPusher.php` (S-QBO-6). See §11 Trap 64 + QUICKBOOKS_SPEC §6.8.
- **Enqueuer pattern (D-ENQUEUER-CONTRACT, QUICKBOOKS_SPEC §6.9):** `FleetForge\QboPushers\<Entity>Enqueuer::enqueue(int $ffId, string $operation): bool` — 4-step gate (master switch → sync_mode → operation allowlist → INSERT). Best-effort: `try/catch (\Throwable)` swallowed, NEVER throws into the parent FF API endpoint. Triggered from `api/v1/<entity>/create.php` + `update.php` AFTER the FF DB write succeeds, BEFORE the JSON success response. See QUICKBOOKS_SPEC §6.9. Reference impl: `lib/QboPushers/CustomerEnqueuer.php` (S-QBO-6).
- **Pusher demotion rule (D-PUSHER-DEMOTION-RULE):** if `pushUpdate` is called for an FF entity whose mapping has no `qbo_<entity>_id` (e.g. ff_only row from auto-match, never pushed), `pushImpl` demotes the operation to create — returns status `'created_from_update'`. From FF's perspective the entity was updated; from QBO's the entity doesn't yet exist. First push has to be a POST not a PUT. See QUICKBOOKS_SPEC §6.8 demotion rule paragraph.
- **QBO `QueryResponse` normalization (S-QBO-5-FIX-1):** `QuickBooksClient::query()` normalizes the 1-row-object case in-place before returning. Every uppercase-keyed field under `QueryResponse` is guaranteed to be an array after the call returns. Pusher and Puller authors should iterate `$response['QueryResponse']['Customer'] ?? []` directly — NO defensive single-row wrap. Re-wrapping post-normalization is INCORRECT (would re-wrap an already-arrayed payload of N>1 entities into a single-element wrapper around the array). See §11 Trap 60.
- **FF↔QBO naming-convention divergence:** FF schema is lowercase snake_case (`'current_asset'`, `'operating_expense'`, `'net'`, `'gross'`); QBO API is PascalCase, often plural (`'AccountsReceivable'`, `'OtherCurrentAssets'`, `'OtherCurrentLiabilities'`, `'Service'`). **Never assume parity** — every cross-system reference table maintains an EXPLICIT equivalence map (e.g. `AccountMatcher::SUBTYPE_EQUIVALENCE`). Any session-prompt SQL CASE or PHP conditional that literally compares an FF column value against a PascalCase string is an instant K-22 candidate — surface via AskUserQuestion per [[feedback_trust_file_over_prompt]] before writing code. See §11 Trap 65 + memory [[project_qbo_subtype_taxonomy]].
- **`ALTER TABLE ADD INDEX` ordering in DATABASE_MASTER:** MySQL appends new indexes at the END of the index/FK/CONSTRAINT block regardless of logical grouping. When a migration adds an index via ALTER, the matching edit to `FLEETFORGE_DATABASE_MASTER.sql` goes at the END of the indexes block (before CONSTRAINT lines), NOT inline with conceptually-related indexes. Run `php tests/_smoke_master_schema_parity.php` BEFORE committing the migration to catch ordering bugs deterministically — parity failure means the master ordering is wrong, not the migration. See §11 Trap 66.
- **ENUM introspection — always live, never roadmap/spec:** when iterating an ENUM domain in a Matcher/mapper, pull values from `INFORMATION_SCHEMA.COLUMNS.COLUMN_TYPE` at runtime; never trust roadmap or spec hypothetical lists. Roadmap docs are aspirational and may diverge substantially from live schema (S-QBO-10 found 7 of 17 `invoice_line_items.item_type` values different from `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9.4). Reference pattern: `ItemMatcher::ffItemTypes()` parses `INFORMATION_SCHEMA` ENUM definition. See §11 Trap 67.
- **Validator empty-category branches MUST throw (D-VALIDATOR-PER-SESSION):** per-session validator gates (`AccountValidator::assertReadyFor*Push()`) that find zero FF accounts tagged with a required category MUST throw `ChartOfAccountsIncompleteException` with an actionable add-and-tag message — NEVER silent-pass. Silent-pass tells the operator "you're ready" when in fact the category is unconfigured; downstream push fails later with a confusing QBO API error. Throw-at-validator surfaces the gap at the right layer with the right remediation. Reference: `AccountValidator::assertCategoriesReady` handles the empty-`$allRows` branch by formatting `"{cat} (no FF account tagged with this category — operator must add + tag one before push)"`. See §11 Trap 68.

---

## 2. FILE TEMPLATES — Copy-paste these exactly

### API endpoint template
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/bootstrap.php';
require_method('POST');                         // or 'GET'
require_auth_api();                             // returns 401 JSON if not authenticated
require_permission('leases', 'create');          // returns 403 JSON if not permitted

// Input
$customer_id = clean_int($_POST['customer_id'] ?? null);
if (!$customer_id) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

// Logic (inside transaction if writes)
db_transaction(function() use ($customer_id) {
    // ... your logic ...
    // For operations needing row lock:
    // $row = db_row("SELECT * FROM table WHERE id = ? FOR UPDATE", [$id]);
});

// Success
json_success(['id' => $newId], 201);
```

### Admin page template
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_auth();
require_permission('customers', 'view');

$pageTitle = 'Customers';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Page content here -->

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
```

### Cron job template
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/app.php';

// Advisory lock — prevents duplicate runs
$lock = db_row("SELECT GET_LOCK('ff_cron_name', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0); // Another instance running — exit silently
}

try {
    // ... cron logic ...

    // Log success to audit
    db_insert('audit_log', [
        'user_id' => null,
        'action' => 'cron_completed',
        'module' => 'system',
        'entity_type' => 'cron',
        'entity_id' => null,
        'description' => 'cron_name completed: X records processed',
        'ip_address' => '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log("[CRON cron_name] " . $e->getMessage());
    // Log failure to audit
    db_insert('audit_log', [
        'user_id' => null,
        'action' => 'cron_failed',
        'module' => 'system',
        'entity_type' => 'cron',
        'entity_id' => null,
        'description' => 'cron_name failed: ' . $e->getMessage(),
        'ip_address' => '127.0.0.1',
    ]);
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_name')", []);
}
```

---

## 3. HELPER FUNCTION SIGNATURES

### db.php
```
db_pdo(): PDO                                  // singleton, never call directly in modules
db_select(string $sql, array $params = []): array       // returns rows
db_row(string $sql, array $params = []): ?array         // one row or null
db_insert(string $table, array $data): int              // returns last insert ID
db_update(string $table, array $data, string $where, array $whereParams): int  // affected rows
db_execute(string $sql, array $params = []): int        // affected rows
db_count(string $sql, array $params = []): int          // single int count
db_exists(string $table, string $condition, array $params): bool
db_transaction(callable $fn): mixed                     // wraps in BEGIN/COMMIT/ROLLBACK
```

### functions.php
```
e(mixed $val): string                          // htmlspecialchars — ALL output goes through this
clean_string(?string $val, int $maxLen = 255): ?string   // trim, strip_tags, truncate
clean_int(mixed $val): ?int                     // returns int or null, rejects floats
clean_decimal(mixed $val): ?string              // returns string for bcmath, rejects formatted ($1,234)
clean_date(?string $val): ?string               // validates Y-m-d, rejects invalid dates
clean_email(?string $val): ?string              // filter_var FILTER_VALIDATE_EMAIL
format_currency(mixed $amount, string $symbol = '$'): string   // $1,234.56 or em dash
format_datetime(mixed $val, string $format = 'M j, Y g:i A'): string  // UTC→company tz
format_date(mixed $val): string                 // Y-m-d → 'Mar 17, 2025'
settings_get(string $key, mixed $default = null): mixed
generate_id(string $prefix, string $year): string       // e.g. INV-2025-00847
generate_random_code(int $length = 6): string           // A-Z0-9, no confusables
bcround(string $val, int $scale = 2): string           // bcmath rounding helper
```

### auth.php
```
require_auth(): void                   // redirect to login if no session
require_auth_api(): void               // return 401 JSON if no session
require_permission(string $module, string $action): void  // 403 if denied
require_role(string $role): void       // 403 if user is not this role
can(string $module, string $action): bool
current_user(): ?array                 // $_SESSION['ff_user'] or null
is_super_admin(): bool
current_user_id(): ?int
```

### bootstrap.php (API)
```
require_method(string $method): void           // 405 if wrong method
json_success(mixed $data, int $status = 200): never     // exits with JSON
json_error(string $code, string $message, int $status = 400): never
json_paginated(array $rows, int $total, int $page, int $perPage): never
```

---

## 4. STANDARD ERROR CODES

Use these consistently. Never invent new ones without checking here first.

### Auth / Session
| Code | HTTP | When |
|------|------|------|
| `UNAUTHORIZED` | 401 | No valid session |
| `FORBIDDEN` | 403 | Insufficient permissions |
| `ACCOUNT_LOCKED` | 423 | Brute force lockout active |
| `INVALID_CREDENTIALS` | 401 | Wrong email/password (never say which) |
| `TOKEN_EXPIRED` | 422 | Reset/invite token past expiry |
| `TOKEN_INVALID` | 422 | Reset/invite token not found |

### Validation
| Code | HTTP | When |
|------|------|------|
| `VALIDATION_ERROR` | 422 | Field-level errors (include `fields` object) |
| `MISSING_REQUIRED` | 422 | Required field not provided |

### Business Logic
| Code | HTTP | When |
|------|------|------|
| `NOT_FOUND` | 404 | Record doesn't exist (or soft-deleted) |
| `ALREADY_EXISTS` | 409 | Duplicate unique constraint (contract_number, etc.) |
| `UNIT_UNAVAILABLE` | 409 | Unit status is not 'available' |
| `LEASE_NOT_ACTIVE` | 409 | Lease must be 'active' for this operation |
| `INVALID_TRANSITION` | 409 | State machine violation |
| `STALE_DATA` | 409 | Optimistic lock — record modified by another user |
| `CURRENCY_MISMATCH` | 422 | Payment/credit currency ≠ invoice currency |
| `ALLOCATION_EXCEEDS_BALANCE` | 422 | Payment/credit amount > invoice balance_due |
| `CREDIT_EXCEEDS_BALANCE` | 422 | Credit application > invoice balance_due |
| `IMMUTABLE_RECORD` | 422 | Trying to edit a finalized financial record |
| `CUSTOMER_SUSPENDED` | 422 | Customer status blocks this operation |
| `CUSTOMER_CREDIT_HOLD` | 422 | Requires manager override |
| `HAS_ACTIVE_LEASES` | 422 | Cannot delete customer with active leases |
| `HAS_ACTIVE_UNITS` | 422 | Cannot delete template with active units |
| `INVOICE_VOID` | 422 | Cannot allocate payment to void invoice |
| `MILEAGE_DATA_ERROR` | 422 | End mileage < start mileage |
| `MILEAGE_REVIEW_REQUIRED` | 422 | Invoice has `mileage_review_status='pending'` — manager must approve / override / reject before send (S-LEASE-MILEAGE / D84). HARD gate, no role exemption. |
| `EMAIL_DISABLED` | 422 | Recipient's `customers.email_disabled=1` (hard-bounce or complaint flag from S-PROD-2 SES handler). Manager re-enable required via `api/v1/customers/reenable_email.php`. |

### System
| Code | HTTP | When |
|------|------|------|
| `SERVER_ERROR` | 500 | Uncaught exception (generic in production) |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP method |
| `CSRF_INVALID` | 403 | CSRF token missing or wrong |

---

## 5. SOFT DELETE — THE #1 BUG SOURCE

These 15 tables have `deleted_at DATETIME NULL`. Every SELECT must include `AND {t}.deleted_at IS NULL`:

```
users, customers, customer_notes, equipment_templates, equipment_units,
leases, damage_claims, invoices, maintenance_work_orders, documents,
vendors, credit_notes, reservations, rate_cards, payments
```

**The constant in db.php:**
```php
const SOFT_DELETE_TABLES = [
    'users', 'customers', 'customer_notes',
    'equipment_templates', 'equipment_units', 'leases',
    'damage_claims', 'invoices', 'maintenance_work_orders',
    'documents', 'vendors', 'credit_notes',
    'reservations', 'rate_cards', 'payments',
];
```

**Trap:** JOINs must include it on BOTH tables:
```sql
-- WRONG: leases deleted_at checked, customers not
SELECT l.* FROM leases l JOIN customers c ON c.id = l.customer_id
WHERE l.deleted_at IS NULL;

-- RIGHT: both tables checked
SELECT l.* FROM leases l JOIN customers c ON c.id = l.customer_id
WHERE l.deleted_at IS NULL AND c.deleted_at IS NULL;
```

---

## 6. STATE MACHINES — VALID TRANSITIONS ONLY

### Equipment: available → reserved, on_lease, maintenance, inactive | reserved → on_lease, available | on_lease → available, maintenance | maintenance → available, inactive | inactive → available | decommissioned → [TERMINAL]

### Lease: pending → active, cancelled | active → completed, cancelled | completed → active (reopen: Manager+reason) | cancelled → [TERMINAL]

### Invoice: draft → sent, void | sent → partially_paid, paid, overdue, void | partially_paid → paid, overdue, written_off | overdue → paid, partially_paid, written_off | paid → [TERMINAL] | void → [TERMINAL]

### Reservation: pending → confirmed, cancelled | confirmed → completed, cancelled | completed → confirmed (reverse) | cancelled → [TERMINAL]

### Work Order: open → in_progress, cancelled | in_progress → waiting_parts, completed | waiting_parts → in_progress | completed → [TERMINAL] | cancelled → [TERMINAL]

**Every transition:** (1) validate against above, (2) 409 INVALID_TRANSITION if not allowed, (3) write to status_log table, (4) audit_log entry.

---

## 7. AUDIT LOG — EVERY WRITE OPERATION

```php
db_insert('audit_log', [
    'user_id'      => current_user_id(),   // null for cron/system
    'user_name'    => current_user_name() ?? 'system',
    'action'       => 'create',            // see enum below — NOT freeform
    'module'       => 'leases',            // matches permission module name
    'entity_type'  => 'lease',             // singular
    'entity_id'    => $leaseId,
    'entity_label' => 'CN-A3F9K2-2025',    // human-readable identifier
    'old_values'   => json_encode($oldData),   // null for creates
    'new_values'   => json_encode($newData),   // null for deletes
    'notes'        => 'Lease CN-A3F9K2-2025 created for ABC Trucking',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);
```

`audit_log.action` is an **ENUM** (not free-text). Valid values:

`create | update | delete | restore | login | logout | export | status_change | view | bulk_action | payment_recorded | invoice_sent | invoice_voided | lease_closed | cron`

If you need a new action verb, ALTER the enum first (separate migration); never invent freeform values — the INSERT will throw "Data truncated for column 'action'". CLI runners (cron, migration runner) use `action='cron'` with the specific intent in `module` and `notes`.

---

## 8. MONETARY MATH — NEVER USE FLOAT

```php
// WRONG — float operators accumulate errors
$total = $rate * $days;

// RIGHT — bcmath with string types
$total = bcmul($rate, (string)$days, 6);  // 6 decimal intermediate
$rounded = bcround($total, 2);            // 2 decimal final

// Helper in functions.php:
function bcround(string $val, int $scale = 2): string {
    $half = '0.' . str_repeat('0', $scale) . '5';
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
}
```

**All billing class parameters and returns are strings.** ProRateCalculator, TaxCalculator, DiscountEngine, CreditEngine, LateFeeEngine — all accept `string` and return `string`.

---

## 9. CONCURRENCY PATTERNS

### Row lock (FOR UPDATE) — required for 4 operations:
```php
// 1. Lease creation — prevent double-lease
// 2. Lease close — prevent race with monthly cron
// 3. Payment allocation — prevent over-allocation
// 4. Credit note application — prevent over-application

db_transaction(function() use ($id) {
    $row = db_row("SELECT * FROM table WHERE id = ? FOR UPDATE", [$id]);
    if (/* invalid state */) json_error('CODE', 'message', 409);
    // Safe to modify
});
```

### Optimistic lock — required for all user-editable update endpoints:
```php
$existing = db_row("SELECT updated_at FROM customers WHERE id = ?", [$id]);
// Use the helper — raw string equality is fragile across DST shifts
// and PDO drivers that return trailing .000000 microseconds (S-PROD-1B / D73).
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA', 'Record modified by another user. Refresh and try again.', 409);
}
```

Helper signature: `optimistic_lock_matches(string $client, string $db): bool` (in `includes/db.php`). Normalizes both sides to `DateTimeImmutable::getTimestamp()` and compares Unix integers. Used at all 24 optimistic-lock callsites in `api/v1/` and `lib/Accounting/FixedAssetService.php`.

### Cron advisory lock — required for all write-heavy crons:
```php
$lock = db_row("SELECT GET_LOCK('ff_cron_name', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) exit(0);
// ... work ...
db_execute("SELECT RELEASE_LOCK('ff_cron_name')", []);
```

---

## 10. LIST ENDPOINT PATTERN (pagination + filters + sort)

```php
// Allowlisted sort columns (NEVER from user input directly)
$allowedSorts = ['created_at', 'company_name', 'status', 'outstanding_balance'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// Build WHERE dynamically from allowlisted filters
$where = ['c.deleted_at IS NULL'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[] = "MATCH(c.company_name, c.contact_name, c.email) AGAINST(? IN BOOLEAN MODE)";
    $params[] = preg_replace('/[+\-<>()~*\"@]/', '', $search); // strip boolean operators
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM customers c WHERE $whereSQL", $params);
$rows = db_select(
    "SELECT c.*, GROUP_CONCAT(ct.tag ORDER BY ct.tag) AS tags
     FROM customers c LEFT JOIN customer_tags ct ON ct.customer_id = c.id
     WHERE $whereSQL GROUP BY c.id ORDER BY c.$sort $dir LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
```

---

## 11. COMMON TRAPS (things that have burned every project like this)

### Trap 1: settings_get() before settings table exists
Sessions before Phase 14 may call `settings_get()`. It must handle a missing table gracefully:
```php
function settings_get(string $key, mixed $default = null): mixed {
    try {
        $row = db_row("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : $default;
    } catch (Throwable) {
        return $default; // table doesn't exist yet — silent fallback
    }
}
```

### Trap 2: dirname() depth wrong
The depth depends on where the file is. Count from the file to the project root:
```
api/v1/customers/index.php   → dirname(__DIR__, 3) gets project root
api/v1/customers/show.php    → dirname(__DIR__, 3)
app/admin/customers/index.php → dirname(__DIR__, 3)
app/admin/dashboard/home.php  → dirname(__DIR__, 3)
api/bootstrap.php             → dirname(__DIR__, 1)
includes/auth.php              → dirname(__DIR__, 1)
cron/some_job.php              → dirname(__DIR__, 1)
```

### Trap 3: CSRF on GET requests
GET requests (list, show, search) do NOT require CSRF validation. Only POST/PUT/DELETE/PATCH do. `api/bootstrap.php` must check the HTTP method before validating CSRF.

### Trap 4: json_error() must exit
`json_error()` MUST call `exit` or `die()`. If it doesn't, code continues executing after the error response, causing double-output or data corruption.

### Trap 5: File uploads — never trust the client
```php
// WRONG: trusting client-provided MIME
$type = $_FILES['doc']['type'];

// RIGHT: server-detected MIME from file content
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$type = finfo_file($finfo, $_FILES['doc']['tmp_name']);
finfo_close($finfo);

// Extension from MIME map, NOT from filename
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$ext = $extMap[$type] ?? null;
if (!$ext) json_error('INVALID_FILE_TYPE', 'Unsupported file type.', 422);

// Safe filename — never keep original
$safeName = "{$entityId}_{$docType}_" . time() . ".{$ext}";
```

### Trap 6: Denormalized counter not updated in same transaction
```php
// WRONG: separate queries, could desync
db_insert('invoices', $invoiceData);
db_execute("UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ?",
    [$amount, $customerId]);

// RIGHT: same transaction
db_transaction(function() use ($invoiceData, $amount, $customerId) {
    db_insert('invoices', $invoiceData);
    db_execute("UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ?",
        [$amount, $customerId]);
});
```

### Trap 7: Forgetting to strip file_path/pdf_path from API responses
The `documents.file_path` and `invoices.pdf_path` columns contain server filesystem paths. NEVER include them in API JSON responses. They reveal the server directory structure.

### Trap 8: Portal queries without customer_id filter
EVERY query in portal/ code MUST filter by the logged-in portal user's customer_id. Equipment queries must JOIN through leases (equipment_units has no customer_id column):
```php
$customerId = $_SESSION['ff_portal_user']['customer_id'];
// Equipment: join through active leases
$units = db_select(
    "SELECT eu.* FROM equipment_units eu
     JOIN leases l ON eu.id = l.equipment_unit_id
     WHERE l.customer_id = ? AND l.status = 'active'
     AND l.deleted_at IS NULL AND eu.deleted_at IS NULL",
    [$customerId]
);
```

### Trap 9: Invoice number generation not atomic
Invoice numbers MUST be gap-free and sequential. Use an atomic counter:
```php
db_transaction(function() use (&$invoiceNumber) {
    $year = date('Y');
    $key = "invoice.next_number.$year";
    // Lock the settings row
    $row = db_row("SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE", [$key]);
    $next = $row ? (int)$row['setting_value'] : 1;
    $invoiceNumber = sprintf("INV-%s-%05d", $year, $next);
    // Increment atomically
    if ($row) {
        db_execute("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$next + 1, $key]);
    } else {
        db_insert('settings', ['setting_key' => $key, 'setting_value' => $next + 1, 'setting_group' => 'invoices']);
    }
    // Create invoice with this number in the SAME transaction
    db_insert('invoices', ['invoice_number' => $invoiceNumber, /* ... */]);
});
```

### Trap 10: audit_log.action is an ENUM — invented values silently truncate
The `audit_log.action` column is an ENUM, not free text. Inserting an undefined value (e.g. `'login_db_error'`, `'migration_applied'`) throws "Data truncated for column 'action'" under MySQL strict mode, OR silently stores `''` in older configs. Pick from the existing enum (see §7) and put the specific intent in `module` + `notes`. If a new verb is genuinely needed, ALTER the enum first as a separate migration.

**Import convention (locked 2026-05-19 via S037-CRUD K-22 disclosure):** `audit_log.action` has NO `'import'` value. For any import or bulk-ingest endpoint (CSV import, QBO entity pull, COA re-import, etc.) use `action='bulk_action'` — NOT `'import'`. Convention applies to: `api/v1/accounting/accounts/import.php` (origin), all future QBO pull endpoints, any future CSV-ingest endpoint. See `D-IMPORT-AUDIT` in `FLEETFORGE_PROGRESS.md` DECISIONS for the full lock.

### Trap 11: every entry-point must call Sentry::init()
Every PHP entry-point that runs in production — pages, API endpoints, cron, CLI runners — MUST call `\FleetForge\Observability\Sentry::init()` before any business logic. Established S-PROD-2.

```php
require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();   // no-op when SENTRY_DSN is blank
```

`Sentry::init()` is idempotent (guarded by `private static bool $initialized`) so multiple calls in one request are fine. `before_send` strips bcrypt hashes, `ENC:` AES ciphertext, and 12 sensitive key names per D-B from S-PROD-2. In every catch block, call `Sentry::captureException($e)` before deciding the user-facing response.

### Trap 12: json_error() envelope shape is canonical
S-PROD-2 (audit #18) standardized 54 non-canonical error responses across `api/v1/ai/`. Every API error MUST go through `json_error()` so the response shape is `{ success: false, error: { code, message, fields? } }`. Frontend code (Alpine + Axios interceptors) reads `error.code` for branching — non-canonical shapes (`{error: true, message: …}`) silently break that contract.

```php
// CORRECT
json_error('STALE_DATA', 'Record modified by another user.', 409);

// WRONG — Alpine sees an unknown shape, falls back to generic error
echo json_encode(['error' => true, 'message' => 'Record modified.']);
```

`json_error()` lives in `api/bootstrap.php`; pages-side equivalent is the page-level exception handler installed by `_ff_session_start()`.

### ⚑ PRIMARY COLUMN-NAME SOURCE — read this before writing any column reference

Before writing ANY column reference in code or a session prompt, check
**`docs/FLEETFORGE_SCHEMA_QUICK_REF.md`** first. That file is auto-generated
from the live database's `information_schema` and lists every column on
every table in the order they exist on disk.

The spec files (`FLEETFORGE_SPEC_FINAL.md`, `FLEETFORGE_ACCOUNTING_SPEC.md`,
etc.) use idealized column names that often differ from on-disk reality —
that drift is the entire source of the K-22 trap catalog below. The
quick-ref shows what is actually there.

Regenerate after migrations: `php scripts/generate_schema_ref.php`.

The individual trap entries below (Traps 13+) remain because each one
captures a concrete recurring confusion (column-name *and* the wrong
spelling that keeps appearing in prompts). Use them as a fast scan; use
the quick-ref as the source of truth.

### Trap 13: `acc_accounts` uses `code` NOT `account_number`
The Chart of Accounts column is `acc_accounts.code` (varchar(20), UNIQUE KEY). It is NOT `account_number`. Multiple session prompts have referenced `account_number` and the resulting code fails at the PDO layer with "Unknown column 'account_number'". When writing seed UPDATEs, CSV import column mappings, or report queries that JOIN on the COA, always use `code`.

```sql
-- WRONG (column does not exist)
UPDATE acc_accounts SET lead_schedule_code = 'A-100' WHERE account_number = '1010';

-- RIGHT
UPDATE acc_accounts SET lead_schedule_code = 'A-100' WHERE code = '1010';
```

**Source:** K-22 catch surfaced in S037-CRUD (CSV-import column mapping) and re-confirmed in S-ACCT-WTB (lead-schedule seed file). Locked 2026-05-19.

### Trap 14: `acc_periods` uses `year` NOT `fiscal_year`
The accounting period year column is `acc_periods.year` (smallint unsigned). It is NOT `fiscal_year`. Several spec sections and session prompts have referenced `fiscal_year`; the term is correct as a CONCEPT but the COLUMN NAME on disk is `year`. When deriving PY-end dates from a period row, or filtering by year, use `year`.

```php
// WRONG (column does not exist)
$pyEnd = ((int) $period['fiscal_year'] - 1) . '-12-31';

// RIGHT
$pyEnd = ((int) $period['year'] - 1) . '-12-31';
```

The full `acc_periods` schema also uses `year` + `month` + `name` (e.g. "May 2026") + `start_date` + `end_date` + `status` ENUM(open|closed|locked) + `is_year_end` flag — there is no `fiscal_year_start` or `fiscal_year_end` column either.

**Source:** K-22 catch surfaced in S-ACCT-WTB pre-flight (working-trial-balance.php PY-end derivation). Locked 2026-05-19.

### Trap 15: accounting reports use `journal_entries` permission, NOT `financial_reports`
There is NO `financial_reports` permission module in the on-disk permission set. The accounting report endpoints (P&L, Balance Sheet, Cash Flow, Trial Balance, Asset Schedule, Working Trial Balance, Lead Schedule) all use `require_permission('journal_entries', 'view')` for read access and `require_permission('journal_entries', 'create')` for any record-creating endpoint (e.g. workpaper annotations). Spec sections and prompts sometimes refer to `financial_reports` as a logical permission area; on disk the module name is `journal_entries`.

```php
// WRONG (module does not exist — silently 403s)
require_permission('financial_reports');

// RIGHT (matches the pattern used by all existing reports)
require_permission('journal_entries', 'view');     // GET endpoints
require_permission('journal_entries', 'create');   // POST endpoints (annotations, etc.)
```

Repo-wide grep across `api/v1/accounting/reports/` confirms only `journal_entries` (and `accounts_payable` for the AP-specific aging report) appear. Any new accounting report endpoint should follow this convention.

**Source:** K-22 catch surfaced in S-ACCT-WTB (WTB v2 + lead-schedule + workpaper-annotations endpoints). Locked 2026-05-19.

### Trap 16: `acc_fixed_assets` uses `acquisition_cost` NOT `original_cost`
The fixed-asset cost-basis column is `acc_fixed_assets.acquisition_cost` (decimal(15,2), NOT NULL). It is NOT `original_cost`. The CCA spec and several session prompts reference `original_cost`; the term is correct as a CCA CONCEPT but the COLUMN NAME on disk is `acquisition_cost`. Use it when:

- Summing additions for CCA Schedule 8 (`SUM(acquisition_cost) WHERE cca_class_id = N`)
- Capping disposal proceeds at the original cost basis per CRA (`LEAST(d.proceeds, fa.acquisition_cost)`)
- Computing depreciable cost / NBV (`depreciable_cost = acquisition_cost - salvage_value`)

```sql
-- WRONG (column does not exist)
SELECT SUM(original_cost) FROM acc_fixed_assets WHERE cca_class_id = 5;

-- RIGHT
SELECT SUM(acquisition_cost) FROM acc_fixed_assets WHERE cca_class_id = 5;
```

**Source:** K-22 catch surfaced in S-ACCT-CCA-1 (CcaService::computeClass + pre-flight schema scan). Locked 2026-05-19.

### Trap 17: `acc_fixed_assets` uses `asset_class` NOT `asset_category`
The internal asset categorisation column is `acc_fixed_assets.asset_class` (ENUM('fleet_equipment','vehicles','office_equipment','leasehold_improvements','land','building','other')). It is NOT `asset_category`. This is the operational class — separate from the CRA tax class (`cra_class` varchar legacy + `cca_class_id` FK from S-ACCT-CCA-1). When writing ALTER TABLE with AFTER clauses, or admin-form filters, use `asset_class`.

```sql
-- WRONG (column does not exist — ALTER fails at the AFTER clause)
ALTER TABLE acc_fixed_assets ADD COLUMN cca_class_id INT UNSIGNED NULL AFTER asset_category;

-- RIGHT
ALTER TABLE acc_fixed_assets ADD COLUMN cca_class_id INT UNSIGNED NULL AFTER asset_class;
```

Three related-but-distinct fields all live on this table:
- `asset_class` — operational ENUM (what KIND of thing)
- `cra_class` — legacy varchar(20) free text (e.g. "10", "16") populated pre-S-ACCT-CCA-1
- `cca_class_id` — FK to `acc_cca_classes.id` (S-ACCT-CCA-1 onward)

`cra_class` + `cra_cca_rate` continue to feed the **depreciation engine**; `cca_class_id` feeds the **CCA Schedule 8 engine**. The two are intentionally separate concerns — do not collapse them.

**Source:** K-22 catch surfaced in S-ACCT-CCA-1 (migration AFTER clause). Locked 2026-05-19.

### Trap 18: `acc_fixed_assets` has NO `deleted_at` column — hard-delete only
The fixed-assets table does NOT participate in soft-delete (§5). It is hard-delete only. Disposal is tracked via `acc_fixed_assets.status = 'disposed'` + a row in `acc_asset_disposals` — NOT via `deleted_at`. When filtering "live assets" in CCA, terminal-loss, or any cross-join query, use the status flag:

```sql
-- WRONG (column does not exist — query 500s with "Unknown column 'deleted_at'")
SELECT COUNT(*) FROM acc_fixed_assets
WHERE cca_class_id = ? AND deleted_at IS NULL;

-- RIGHT (Terminal-loss check: class is "empty at year-end")
SELECT COUNT(*) FROM acc_fixed_assets
WHERE cca_class_id = ? AND status <> 'disposed';
```

Same applies to `acc_asset_disposals` — operational record, hard-delete only, no `deleted_at`. The SOFT_DELETE_TABLES list in §5 is authoritative; assume hard-delete unless a table is listed there.

**Source:** K-22 catch surfaced in S-ACCT-CCA-1 (CcaService Step 11 terminal-loss query). Locked 2026-05-19.

### Trap 19: GVWR is `equipment_units.weight_capacity_lbs` in POUNDS, not kg
There is no `gvwr_kg` or `gvwr` column anywhere. The closest field is `equipment_units.weight_capacity_lbs` (int unsigned, **pounds**). Several specs reference GVWR in kilograms (e.g. "Class 16 threshold = 11,788 kg GVWR"). When comparing on-disk weight against a CRA threshold expressed in kg, convert first:

```
11,788 kg × 2.20462 lb/kg = 25,988 lbs (≈ 25,990 lbs)
```

Use the lbs-side value in any comparison against `weight_capacity_lbs`:

```php
// WRONG — comparing kg threshold against a lbs column silently underflags
if ($equipmentUnit['weight_capacity_lbs'] > 11788) { /* will trigger far too often */ }

// RIGHT — compare lbs to lbs
$gvwrLbs = (int) $equipmentUnit['weight_capacity_lbs'];
if ($gvwrLbs > 25990) {
    // Class 16 (or Class 55 for ZEV) suggestion
}
```

The S-ACCT-CCA-1 GVWR validator (`CcaService::classifyGvwrWarning`) accepts both `$gvwrKg` and `$gvwrLbs` parameters so callers can pass either — but the live data path through `equipment_units` is always lbs.

**Source:** K-22 catch surfaced in S-ACCT-CCA-1 (Class 16 GVWR validator + pre-flight schema scan). Locked 2026-05-19.

### Trap 20: PREDEPLOY_CHECKLIST.md category F = Accounting, NOT category H
The pre-deploy checklist (`docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md`) uses these category letters on disk:

- **A** — Asset cache invalidation
- **B** — Production `.env` keys
- **C** — DNS
- **D** — AWS infrastructure
- **E** — Data migrations
- **F** — Accounting state (cron installs, account assignments, CCA backfills, etc.)
- **G** — Smoke + verification procedures
- **H** — Rollback procedures
- **I** — Post-deploy monitoring
- **J** — References

Several session prompts have referred to "category H — Accounting" — that is incorrect. H is RESERVED for rollback steps; placing an accounting backfill there is a category divergence (K-14 class). When filing PREDEPLOY items for new accounting features, use **F-** prefix:

```
ITEM F-CCA-1 | 2026-05-19 | F — Accounting | Assign CCA classes to all 20 existing fixed assets
  Originating session: S-ACCT-CCA-1
  Surfaced into checklist: S-ACCT-CCA-1
  Detail: ...
  Owner: Operator
  Status: PENDING
```

If a genuinely new category is needed, propose it explicitly in the session prompt + extend the category list above — do not silently invent one.

**Source:** K-22 catch surfaced in S-ACCT-CCA-1 (prompt said "H-CCA-1/2" — actually filed as F-CCA-1/2 on disk). Locked 2026-05-19.

### Trap 21: `acc_fixed_assets` uses `useful_life_years` NOT `useful_life_months`
The fixed-asset useful-life column is `acc_fixed_assets.useful_life_years` (decimal(5,2), NULL). It is NOT `useful_life_months`. Several specs reference useful life in months (a CRA-friendly granularity for AIIP / mid-year acquisitions); on disk the value is stored in YEARS as a decimal — 8.0, 10.5, 12.0 — and the existing depreciation engine (FixedAssetService::previewRun / calculateForPeriod) converts to a per-period factor at run time.

```sql
-- WRONG (column does not exist)
SELECT useful_life_months FROM acc_fixed_assets WHERE id = ?;

-- RIGHT
SELECT useful_life_years FROM acc_fixed_assets WHERE id = ?;
-- Convert to months only when the algorithm requires it:
--   $months = bcmul($asset['useful_life_years'], '12', 0);
```

Practical implication for betterment / componentization / remaining-life math: the engine already amortizes over the remaining portion of `useful_life_years × 12` via its per-period factor — no per-asset `useful_life_months` field needs to be added, and no manual month-conversion is required when calling `FixedAssetService::capitalize()` (the next depreciation run picks up the new depreciable_cost automatically).

**Source:** K-22 catch surfaced in S-ACCT-COMP (`capitalize()` spec referenced `useful_life_months` for the remaining-life recompute, but the on-disk column is years and the engine already handles month conversion). Locked 2026-05-19.

### Trap 22: `tax_rates` uses `province` NOT `province_code`
The Canadian-tax lookup table is `tax_rates`. The province column is `province` (varchar(100), NULLABLE — sometimes holds the 2-letter ISO code "BC", sometimes a full name "British Columbia"). It is NOT `province_code`. There is also no `country_code` — the country column is just `country` (ENUM('CA','US')).

```sql
-- WRONG (column does not exist)
SELECT * FROM tax_rates WHERE province_code = 'BC';

-- RIGHT — and prefer uppercase matching to be tolerant of full-name values
SELECT * FROM tax_rates WHERE UPPER(province) = 'BC' AND country = 'CA';
```

When taking province strings from `customers.province` or other free-text sources, normalize to the 2-letter ISO code before querying — see `PlaceOfSupplyService::normalizeProvince()` for the canonical name-to-code map (NT not NWT; QC accepts both with and without accent; etc.).

**Source:** K-22 catch surfaced in S-ACCT-POS (POS rules + TaxCalculator queries). Locked 2026-05-19.

### Trap 23: `tax_rates.effective_from` + `effective_to` already exist — grep before adding
Don't add `effective_from` / `effective_to` columns to `tax_rates` — they're already on disk (DATE, with `effective_to` NULL meaning "open / current"). Adding them again breaks the migration. Always grep `FLEETFORGE_DATABASE_MASTER.sql` for the columns you intend to add before writing an ALTER. This applies to any "is this column missing?" assumption — the spec may reference columns that were added in earlier sessions.

```sql
-- WRONG — duplicate ALTER fails
ALTER TABLE tax_rates ADD COLUMN effective_from DATE NULL;

-- RIGHT — pre-flight grep first; if present, only add the index (or whatever else is genuinely new)
ALTER TABLE tax_rates ADD INDEX idx_tr_effective (effective_from, effective_to);
```

For S-ACCT-POS the entire effective-date column ALTER was skipped — only the composite index was new.

**Source:** K-22 catch surfaced in S-ACCT-POS pre-flight. Locked 2026-05-19.

### Trap 24: `tax_rates` is WIDE-ROW — one row per province, not a normalized rates table
Each row in `tax_rates` carries SEPARATE columns for the three tax types: `gst_rate`, `pst_rate`, `hst_rate` (all decimal(8,6)). There is NO `tax_type` column and NO `rate` column. BC's row looks like `gst_rate=0.050000, pst_rate=0.070000, hst_rate=0.000000`. ON's row looks like `gst_rate=0.000000, pst_rate=0.000000, hst_rate=0.130000`. NS's current row (id #7) is `hst_rate=0.140000` effective 2025-04-01.

```sql
-- WRONG — assumes tall-row {tax_type, rate} normalized design
SELECT rate FROM tax_rates WHERE province = 'BC' AND tax_type = 'GST';

-- RIGHT — read the relevant rate column from the wide row
SELECT gst_rate, pst_rate, hst_rate FROM tax_rates WHERE province = 'BC';
```

Implication for new code that links to specific rates (e.g. `acc_place_of_supply_rules.applicable_tax_rate_ids` JSON array): the array stores a single `tax_rates.id` per province (one row holds all three taxes), not multiple ids per tax type. When you load the row, you get all three rate columns at once and apply the ones that are > 0.

**Source:** K-22 catch surfaced in S-ACCT-POS (POS rules seed + service design). Locked 2026-05-19.

### Trap 25: `customers` uses `company_name` NOT `name`
The customer display column is `customers.company_name` (varchar). It is NOT `customers.name` — that column does not exist. Related fields: `contact_name`, `billing_contact_name`. The `province` column does exist on customers (varchar(100), free text) and is the primary place-of-supply signal.

```sql
-- WRONG — column does not exist
SELECT id, name, province FROM customers WHERE id = ?;

-- RIGHT
SELECT id, company_name, province FROM customers WHERE id = ?;

-- For display lists where calling code expects "name":
SELECT id, company_name AS name, province FROM customers ORDER BY company_name;
```

**Source:** K-22 catch surfaced in S-ACCT-POS (PlaceOfSupplyService.resolve() customer lookup + Tax admin dropdown + smoke test). Locked 2026-05-19.

### Trap 26: `leases` has NO `ordinarily_located_province` column
The leases table does not carry a province column on disk. Place-of-Supply LONG_LEASE rule (per ASPE §23.6 / GST/HST place-of-supply rules) is meant to use the province where the leased asset is "ordinarily located," but with no on-disk column the rule **falls back to `customers.province`** instead. This fallback must be logged in any derivation trail so the auditor knows the data path.

If a future session adds the column, update the POS engine's LONG_LEASE branch to read `leases.ordinarily_located_province` first and only fall back when NULL.

```php
// CORRECT pattern in PlaceOfSupplyService::resolve()
case 'long_lease':
    // K-22: leases table has no ordinarily_located_province column on disk
    $resolved = self::normalizeProvince($customer['province']);
    $method = 'customer_province';
    $trail[] = 'leases table has no ordinarily_located_province column on disk';
    $trail[] = "fallback: customer billing province = '{$customer['province']}'";
    break;
```

**Source:** K-22 catch surfaced in S-ACCT-POS. Locked 2026-05-19.

### Trap 27: `equipment_units` has NO `province_code` column
The equipment_units table does not carry a registration-province column on disk. The Place-of-Supply SPECIFIED_MOTOR_VEHICLE rule (where the sale of a motor vehicle to a GST registrant follows the registration province) **falls back to `customers.province`** with the same logging pattern as Trap 26. Related columns that DO exist on equipment_units: `registration_expiry`, `registration_interval_days`, `registration_from_date`, `registration_document` (filename) — none of these capture which province issued the registration.

```php
// CORRECT pattern in PlaceOfSupplyService::resolve()
case 'specified_motor_vehicle':
    // K-22: equipment_units has no province_code column on disk
    $resolved = self::normalizeProvince($customer['province']);
    $method = 'customer_province';
    $trail[] = 'equipment_units table has no province_code column on disk';
    $trail[] = "fallback: customer billing province = '{$customer['province']}'";
    break;
```

If a future session adds the column (e.g. as part of a fleet-transfer or inter-provincial sale feature), update the POS engine to read it first and only fall back when NULL.

**Source:** K-22 catch surfaced in S-ACCT-POS. Locked 2026-05-19.

### Trap 28: `invoices` uses `invoice_date` NOT `issue_date`; has NO `bill_province`
Two related catches on the invoices table:

1. The primary date column is `invoice_date` (DATE). It is NOT `issue_date`. Related dates: `due_date`, `paid_date`, `sent_date`, `voided_date`, `late_fee_date`.

2. There is NO `bill_province` column. The applied tax-jurisdiction is encoded implicitly through `tax_gst_rate` / `tax_pst_rate` / `tax_hst_rate` / `tax_*_amount` columns at invoice time. To compare "applied vs derived province" for POS audits, join `customers.province` as the proxy for the value the TaxCalculator received when the invoice was generated.

```sql
-- WRONG
SELECT id, issue_date, bill_province FROM invoices WHERE issue_date BETWEEN ? AND ?;

-- RIGHT — invoice_date for the date, join customers.province for the implicit applied jurisdiction
SELECT i.id, i.invoice_number, i.invoice_date,
       i.tax_gst_rate, i.tax_pst_rate, i.tax_hst_rate, i.tax_total,
       c.province AS customer_province
  FROM invoices i
  LEFT JOIN customers c ON c.id = i.customer_id
 WHERE i.invoice_date BETWEEN ? AND ?
   AND i.deleted_at IS NULL;
```

If a future session needs explicit applied-province tracking on each invoice (e.g. for the POS audit to be unambiguous when a customer changes province between invoices), add a `bill_province` snapshot column at invoice generation time — until then, customer.province + the three tax-rate columns are the audit signal.

**Source:** K-22 catch surfaced in S-ACCT-POS (PlaceOfSupplyService::auditReport). Locked 2026-05-19.

### Trap 29: `acc_tax_filing_periods` uses `period_start`/`period_end` NOT `start_date`/`end_date`
The tax-filing-period date columns are `period_start` and `period_end` (DATE, NOT NULL). They are NOT `start_date`/`end_date` — those names are used by `acc_periods` (the accounting periods table), not by `acc_tax_filing_periods`. Two related-but-distinct tables with similar shape; double-check which one you're querying.

```sql
-- WRONG (columns do not exist on acc_tax_filing_periods)
SELECT id, start_date, end_date FROM acc_tax_filing_periods WHERE id = ?;

-- RIGHT
SELECT id, period_start, period_end, filing_due_date, tax_type, frequency, status
  FROM acc_tax_filing_periods WHERE id = ?;
```

The `tax_type` ENUM is small: `gst_hst|pst_bc|pst_sk|pst_mb`. The `frequency` ENUM matches the `accounting.gst_filing_frequency` setting: `monthly|quarterly|annually`. The `status` ENUM walks `open → calculated → filed → remitted`.

**Source:** K-22 catch surfaced in S-ACCT-GST34 (Gst34Service::compute date filtering). Locked 2026-05-19.

### Trap 30: `acc_tax_remittances` FK is `filing_period_id` NOT `tax_filing_period_id`
The link back to `acc_tax_filing_periods` is `acc_tax_remittances.filing_period_id` (int unsigned, NOT NULL). The prompt-implied `tax_filing_period_id` does not exist on disk. Same table also has NO `remittance_type` column — payment intent is implicit via `payment_method` ENUM (`online_banking|check|wire|other`); to sum "instalments + final remittances" against a period, just SUM all rows with `filing_period_id = ?`.

```sql
-- WRONG (column does not exist)
SELECT SUM(amount) FROM acc_tax_remittances
WHERE tax_filing_period_id = ? AND remittance_type = 'instalment';

-- RIGHT
SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
  FROM acc_tax_remittances
 WHERE filing_period_id = ?;
```

**Source:** K-22 catch surfaced in S-ACCT-GST34 (GST34 Line 110 query). Locked 2026-05-19.

### Trap 31: invoices store `gst_exempt_snapshot`/`pst_exempt_snapshot` NOT `gst_exempt`/`pst_exempt`
Tax-exempt status on invoices is SNAPSHOTTED at creation time into `invoices.gst_exempt_snapshot` and `invoices.pst_exempt_snapshot` (both tinyint(1)). The plain `gst_exempt`/`pst_exempt` columns do NOT exist on the invoices table — they exist on `customers` and are SNAPSHOTTED forward when an invoice is generated. This is intentional: an exemption status at invoice time must not retroactively change when a customer's flag flips later (CRA audit integrity).

```sql
-- WRONG (columns do not exist on invoices)
SELECT ... FROM invoices WHERE gst_exempt = 0 AND pst_exempt = 0;

-- RIGHT — exclude only fully-exempt invoices (BOTH exemptions set)
SELECT ...
  FROM invoices
 WHERE NOT (gst_exempt_snapshot = 1 AND pst_exempt_snapshot = 1)
   AND deleted_at IS NULL;
```

Related snapshot columns on invoices: `tax_exempt_snapshot`, `tax_exempt_number_snapshot`, `customer_name_snapshot`, `customer_email_snapshot` — all captured at invoice time per D5-class immutability rule.

**Source:** K-22 catch surfaced in S-ACCT-GST34 (Gst34Service Line 101 filter). Locked 2026-05-19.

### Trap 32: `vendors` has NO `business_number` column — surface as gap, don't throw
The CRA ITC documentation rule under ETA §169(4) requires the supplier's business number on invoices for purchases ≥ $30. On disk `vendors` only has `name` + `contact_name` — no `business_number` column. Any ITC documentation report that checks supplier-BN compliance should surface this as a **schema gap** rather than marking every bill as non-compliant for a field that can't exist.

Convention used by S-ACCT-GST34's `itcDocumentationReport()`:

```php
// Always check for missing fields per CRA tier (<$30, $30-149.99, ≥$150)
$missing = [];
if (empty($b['vendor_name']))    $missing[] = 'vendor_name';
if (empty($b['bill_date']))      $missing[] = 'bill_date';
// ... other tier-30+ checks ...

// Then add the known gap as a labeled note that is FILTERED OUT of the
// "non-compliant" count so the gap doesn't drown out real issues.
$missing[] = '[gap] vendor.business_number column absent on disk';

$realMissing = array_filter($missing, fn($m) => strpos($m, '[gap]') !== 0);
if (empty($realMissing)) {
    $compliantCount++;
} else {
    $nonCompliant[] = ['bill_id' => $b['id'], 'missing_fields' => $realMissing, 'known_gaps' => /* gaps */];
}

// Surface the gaps once at the report level:
return [
    /* ... */,
    'schema_gaps' => [
        'vendors.business_number column missing on disk — add via separate migration to fully enforce ETA §169(4) tier-30+.',
    ],
];
```

Same pattern applies to `acc_bills`'s missing buyer-company snapshot (CRA tier-150+ documentation). When a future session adds these columns, drop the `[gap]` marker and let the real check run.

**Source:** K-22 catch surfaced in S-ACCT-GST34 (Gst34Service::itcDocumentationReport). Locked 2026-05-19.

### Trap 33: MySQL 8: `GROUP_CONCAT(... LIMIT N)` is not portable — use two queries
The on-disk MySQL build does NOT support an inline `LIMIT N` clause inside `GROUP_CONCAT` (it's an 8.x-specific extension that isn't enabled by default). Queries that depend on this syntax fail at PDO prepare time with "Syntax error or access violation: 1064". Replace with the two-query aggregate-then-fetch pattern:

```sql
-- WRONG (fails on the on-disk MySQL build)
SELECT COALESCE(SUM(amount), 0) AS total,
       COUNT(*) AS n,
       GROUP_CONCAT(id ORDER BY id SEPARATOR ',' LIMIT 50) AS ids
  FROM invoices
 WHERE invoice_date BETWEEN ? AND ?;

-- RIGHT — two queries: aggregate first, then SELECT ... LIMIT for ids
SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
  FROM invoices WHERE invoice_date BETWEEN ? AND ?;

SELECT id FROM invoices
 WHERE invoice_date BETWEEN ? AND ?
 ORDER BY id LIMIT 50;
```

PHP side: combine via `implode(',', array_column($idRows, 'id'))` to produce the same comma-string the original GROUP_CONCAT would have. The cost of two roundtrips is negligible for these report-style queries.

Related: `ONLY_FULL_GROUP_BY` is enabled on the on-disk MySQL build — every non-aggregated column in a `SELECT` that uses `GROUP BY` must be listed in the `GROUP BY` clause. Joins that pull in extra display columns need explicit `GROUP BY a.id, a.col1, a.col2, b.col3` enumeration. Same trap class as Trap 33; same source session.

**Source:** K-22 catch surfaced in S-ACCT-GST34 (Gst34Service::compute Line 101 + applyItcRestrictions). Locked 2026-05-19.

### Trap 34: `invoice_line_items.item_type` ENUM has no `meals_entertainment` or `export` — stub, don't throw
The on-disk `invoice_line_items.item_type` ENUM values are: `base_rental | mileage_precharge | mileage_adjustment | mileage_credit | insurance | warranty | late_fee | early_return_credit | manual_adjustment | damage | discount | account_credit_applied | other | gps | mileage_usage | mileage_drawdown_credit | base_rental_reconciliation_credit`. There is NO `meals_entertainment` and NO `export` value. Any feature that depends on identifying these line types must **stub** the relevant restriction with a warning in the result, NOT throw or assume the values exist.

S-ACCT-GST34 example (M&E 50% ITC rule under ITA §67.1):

```php
// CCA-2 / GST34 pattern: surface the stub in the result's warnings array
public static function applyItcRestrictions(...): array {
    // Passenger-vehicle cap — supported (works against acc_fixed_assets.cca_class_id).
    // ... cap logic ...

    // M&E 50% rule: deferred until invoice_line_items.item_type ENUM (or
    // acc_bill_lines flag) supports identifying meals-and-entertainment.
    // No throw; the stub is documented in the service docblock + this trap.

    return ['adjusted_itc' => $adjusted, 'restrictions' => $restrictions];
}
```

When the ENUM is extended (or a `bill_lines.is_meals_entertainment` flag is added), drop the stub and wire the 50% reduction.

**Source:** K-22 catch surfaced in S-ACCT-GST34 (M&E ITC restriction stub + spec §23.7 zero-rated export gap). Locked 2026-05-19.

### Trap 35: `customers` has NO `gps_exempt` column — GPS is lease-level
There is no `customers.gps_exempt` or any per-customer GPS opt-out. GPS is configured on the LEASE row: `leases.gps_opt_in` (tinyint(1)) + `leases.gps_cost` (decimal(10,2)). This means GPS can vary contract-by-contract for the same customer — a customer can have one lease with GPS and one without.

The closest per-customer GPS-adjacent column is `customers.gps_revenue_presentation` (ENUM('net','gross'), added by S-ACCT-GPS) — but that's a PRESENTATION policy (ASPE 3400 agent vs principal), not an opt-out.

```sql
-- WRONG — column does not exist on customers
SELECT id FROM customers WHERE gps_exempt = 0;

-- RIGHT — check GPS at the lease level
SELECT l.id FROM leases l WHERE l.customer_id = ? AND l.gps_opt_in = 1;
```

ALTER-anchor implication: if you're adding a new per-customer GPS-related column (e.g. for some future per-customer override), don't anchor `AFTER gps_exempt`. The S-ACCT-GPS migration placed `gps_revenue_presentation` AFTER `billing_cycle` — that's the presentation-policy cluster on this table (next to `invoice_delivery`, `invoice_email`, `po_required`).

**Source:** K-22 catch surfaced in S-ACCT-GPS (`gps_revenue_presentation` migration's AFTER anchor). Locked 2026-05-19.

### Trap 36: `acc_fixed_assets` uses `depreciation_method` NOT `amortization_method`
The fixed-asset method column is `acc_fixed_assets.depreciation_method` (ENUM('straight_line','declining_balance','units_of_production','none')). It is NOT `amortization_method`. Some ASPE/CPA literature uses "amortization" generically for both tangible and intangible cost allocation, but on disk the column is named after the tangible-PP&E term. Any service computing per-asset method-driven policy (Note 2 PP&E policy paragraph, depreciation engine selection, asset detail UI) must read `depreciation_method`.

```sql
-- WRONG (column does not exist)
SELECT DISTINCT amortization_method FROM acc_fixed_assets;

-- RIGHT
SELECT DISTINCT depreciation_method FROM acc_fixed_assets;
```

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 2 PP&E policy paragraph reading DISTINCT methods from active assets). Locked 2026-05-19.

### Trap 37: `acc_depreciation_run_lines` per-period dep column is `depreciation` (not `amount`)
The per-asset-per-period depreciation amount on a run line is `acc_depreciation_run_lines.depreciation` (decimal(15,2)). It is NOT `amount` (no such column), NOT `depreciation_amount`, NOT `depr_amount`. Always grep the actual column on disk before any session touching depreciation run lines — sister columns `opening_nbv` and `closing_nbv` use NBV suffix but the dep column itself is unadorned.

```sql
-- WRONG (column does not exist)
SELECT SUM(rl.amount) FROM acc_depreciation_run_lines rl ...

-- RIGHT
SELECT SUM(rl.depreciation) FROM acc_depreciation_run_lines rl
  JOIN acc_depreciation_runs r ON r.id = rl.run_id
  JOIN acc_periods p ON p.id = rl.period_id
 WHERE p.year = ?
   AND r.status = 'posted'
 GROUP BY rl.asset_id;
```

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 3 PP&E continuity current-year depreciation aggregate). Locked 2026-05-19.

### Trap 38: `damage_claims.status` ENUM has `'resolved'`/`'written_off'` — NO `'settled'` value
The on-disk ENUM is `damage_claims.status` = `'reported','assessed','repair_ordered','invoiced','resolved','written_off'`. It does NOT contain `'settled'` — that's a different vocabulary (loans / lawsuits / insurance claims). Filters that try to exclude closed claims using `status != 'settled'` silently match every row because the value never appears; filters that try to find closed claims using `status = 'settled'` silently match nothing.

```sql
-- WRONG (the 'settled' value never appears in this ENUM)
SELECT * FROM damage_claims WHERE status = 'settled';
SELECT * FROM damage_claims WHERE status NOT IN ('settled','written_off');

-- RIGHT — closed claims are 'resolved' OR 'written_off'
SELECT * FROM damage_claims WHERE status IN ('resolved','written_off');
SELECT * FROM damage_claims WHERE status NOT IN ('resolved','written_off');
```

Practical implication for Note 7 Commitments & Contingencies (and any other "open claims" report): filter open claims with `status NOT IN ('resolved','written_off')`. The other four states (`reported`, `assessed`, `repair_ordered`, `invoiced`) are all in-flight and should appear in commitments.

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 7 open damage-claims filter). Locked 2026-05-19.

### Trap 39: `leases` has NO `classification` column — capital-lease classification is Phase D
The `leases` table has no `classification` column on disk. Lessor capital-lease classification (operating / sales_type / direct_financing per ASPE 3065.06–.10) is Phase D scope (S-ACCT-LESSOR-1 onwards) and the column will be ADDed at that time. As of today every lease in `leases` is implicitly an operating lease, and any disclosure note, balance-sheet presentation, or lessor-side report that asks "are there sales-type or direct-financing leases?" must defensively check for column existence OR hard-code the no-capital-leases answer for the current era.

```sql
-- WRONG (column does not exist)
SELECT * FROM leases WHERE classification IN ('sales_type','direct_financing');

-- RIGHT — guard with information_schema or pass through static stub
SELECT COUNT(*) AS has_col FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'leases' AND column_name = 'classification';
-- If has_col = 0 → emit "no sales-type or direct financing leases" stub.
```

Practical implication for §23.9 Note 9 (Net Investment in Lease): the auto-generated note always returns the "no sales-type or direct financing leases as at..." stub language until S-ACCT-LESSOR-1 lands. Any session adding the classification column should also update DisclosureService::generateNote9_NetInvestmentInLease to populate the actual net-investment schedule.

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 9 stub branch + spec §23.9 capital-lease-aware logic). Locked 2026-05-19.

### Trap 40: `vendors` uses `name` NOT `company_name` — opposite convention to `customers`
The vendor identity column is `vendors.name` (varchar(255), NOT NULL). It is NOT `company_name`. Customers, by contrast, use `customers.company_name` (see Trap 25) — the two tables have OPPOSITE naming conventions for the same conceptual field, which trips up generic "party" code that templates over both.

```sql
-- WRONG (column does not exist on vendors)
SELECT id, company_name FROM vendors WHERE is_related_party = 1;

-- RIGHT
SELECT id, name FROM vendors WHERE is_related_party = 1;
```

ALTER-anchor implication: when adding a per-vendor column whose customers counterpart anchors AFTER `company_name` (e.g. `is_related_party`), the vendors version anchors AFTER `name`. S-ACCT-DISC: `customers.is_related_party AFTER company_name` + `vendors.is_related_party AFTER name`.

```sql
-- vendors-side anchor
ALTER TABLE vendors ADD COLUMN is_related_party TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

-- customers-side anchor (note the column-name difference)
ALTER TABLE customers ADD COLUMN is_related_party TINYINT(1) NOT NULL DEFAULT 0 AFTER company_name;
```

Cross-reference: Trap 25 covers the customers-side rule (`company_name` NOT `name`). Always pair these — if you grep one and assume the other matches, you'll write a broken `JOIN ... USING (name)` or `UNION` query.

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 6 related-party purchases query + `is_related_party` ALTER anchor). Locked 2026-05-19.

### Trap 41: `invoices.total_amount` NOT `total` — D16 bcmath rule still applies
The invoice total column is `invoices.total_amount` (decimal(12,2), NOT NULL DEFAULT 0.00). It is NOT `total`. The table has FOUR money columns whose names all START with a noun: `subtotal`, `subtotal_after_discount`, `tax_total`, `total_amount`. The natural-language phrase "the invoice total" maps to `total_amount`, not the (absent) bare `total` column.

```sql
-- WRONG (column does not exist)
SELECT SUM(total) FROM invoices WHERE customer_id = ?;
SELECT SUM(total - amount_paid) AS ar FROM invoices WHERE status = 'sent';

-- RIGHT
SELECT SUM(total_amount) FROM invoices WHERE customer_id = ?;
SELECT SUM(total_amount - amount_paid) AS ar FROM invoices WHERE status IN ('sent','partially_paid','overdue');
```

Per D16, money-arithmetic in PHP must use bcmath strings — even though the DB returns decimal, never cast to float for sums beyond display.

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 6 related-party revenue + outstanding-AR aggregates). Locked 2026-05-19.

### Trap 42: `invoices.status` for AR queries — include `partially_paid` and `overdue`, not just `'sent'`
The invoice status ENUM is `'draft','sent','partially_paid','paid','overdue','void','written_off'`. An invoice is OUTSTANDING (has AR exposure) when its status is one of `sent`, `partially_paid`, or `overdue` — not just `'sent'`. Reports that filter `WHERE status = 'sent'` quietly miss every partially-paid and overdue invoice; reports that filter `WHERE status != 'paid'` miss the same OR include drafts and voids depending on framing.

```sql
-- WRONG (misses partially_paid + overdue)
SELECT SUM(total_amount - amount_paid) FROM invoices WHERE status = 'sent';

-- WRONG (includes drafts and voids)
SELECT SUM(total_amount - amount_paid) FROM invoices WHERE status != 'paid';

-- RIGHT — explicit active-AR set
SELECT SUM(total_amount - amount_paid)
  FROM invoices
 WHERE customer_id = ?
   AND status IN ('sent','partially_paid','overdue')
   AND invoice_date <= ?;
```

Practical implication for Note 6 related-party outstanding balances + statements + collections reports + AR aging: always express the active-AR set as the explicit 3-value IN-list `('sent','partially_paid','overdue')`. If you're rolling up to a per-customer balance (Note 6, statements), this is the only filter that reconciles to `customers.outstanding_balance` after the S-FIX-2 counter remediation.

**Source:** K-22 catch surfaced in S-ACCT-DISC (Note 6 outstanding-AR aggregate at year-end). Locked 2026-05-19.

### Trap 43: `acc_periods` uses `start_date`/`end_date` — NOT `period_start`/`period_end`
The fiscal-period table `acc_periods` uses `start_date` and `end_date` (both date NOT NULL). It does NOT use `period_start`/`period_end`. The opposite is true of `acc_tax_filing_periods`: that table DOES use `period_start`/`period_end` (Trap 29). Don't confuse the two — they look like the same shape but the column names are flipped.

```sql
-- WRONG (those columns don't exist on acc_periods)
SELECT id FROM acc_periods WHERE period_start <= ? AND period_end >= ?;

-- RIGHT
SELECT id FROM acc_periods WHERE start_date <= ? AND end_date >= ?;

-- For acc_tax_filing_periods (the OTHER table), the prompt-style
-- naming IS correct:
SELECT id FROM acc_tax_filing_periods WHERE period_start <= ? AND period_end >= ?;
```

Practical implication: any report query that joins through a period (depreciation runs, ledger queries, AJE workflow) must use `acc_periods.start_date`/`end_date`. Mixing up the two tables produces a "Unknown column" error from MySQL — at least it fails loudly, but it's a wasted CI cycle.

Cross-reference: Trap 14 (`acc_periods.year` not `fiscal_year`) covers the year-key naming on the same table; Trap 29 covers the `acc_tax_filing_periods` column naming. The three traps together fully fingerprint the period-table conventions.

**Source:** K-22 catch surfaced in S-ACCT-UNIT (UnitProfitabilityService pre-flight; spec prompt assumed period_start/end which is the tax-filing convention). Locked 2026-05-19.

### Trap 44: `equipment_units.status` ENUM has `'decommissioned'` — NO `'disposed'` value
The equipment-unit status ENUM is `'available','reserved','on_lease','maintenance','inactive','decommissioned'`. It does NOT contain `'disposed'` — that's the `acc_fixed_assets.status` vocabulary (`'active','fully_depreciated','disposed','impaired'`). The two tables track different lifecycle concerns: equipment_units is operational state, acc_fixed_assets is depreciation/accounting state.

```sql
-- WRONG (the 'disposed' value never appears on equipment_units)
SELECT id FROM equipment_units WHERE status = 'disposed';
SELECT id FROM equipment_units WHERE status != 'disposed';

-- RIGHT — active units (excluding decommissioned + inactive, also
-- check deleted_at IS NULL for soft-delete safety)
SELECT id FROM equipment_units
 WHERE status NOT IN ('decommissioned','inactive')
   AND deleted_at IS NULL;

-- If you want to filter the accounting-side disposal flag, use the
-- right table:
SELECT id FROM acc_fixed_assets WHERE status = 'disposed';
```

Practical implication for fleet KPI queries, per-unit P&L, and any other "active fleet" report: filter on `equipment_units.status NOT IN ('decommissioned','inactive') AND deleted_at IS NULL`. The companion fixed-asset side uses `acc_fixed_assets.status IN ('active','impaired')` for "in-service" assets (also includes `fully_depreciated` if the asset is still in service but fully amortized).

**Source:** K-22 catch surfaced in S-ACCT-UNIT (active-fleet filter in getFleetTotals + KPI age calculation). Locked 2026-05-19.

### Trap 45: `equipment_units` has NO `make`/`model` columns — they live on `equipment_templates`
The equipment-unit identity columns on disk are: `id`, `unit_number`, `vin`, `year`, `template_id`, `license_plate`. There is NO `make` or `model` column on `equipment_units` directly. Make and model are template-level attributes — pulled via the FK `equipment_units.template_id → equipment_templates.id` and then `template.brand` (NOT `template.make`) + `template.model`.

```sql
-- WRONG (no such columns on equipment_units)
SELECT unit_number, make, model FROM equipment_units;

-- RIGHT — join through template; template uses `brand` not `make`
SELECT u.unit_number, u.year, t.brand, t.model
  FROM equipment_units u
  LEFT JOIN equipment_templates t ON t.id = u.template_id;

-- Display label pattern used by per-unit P&L:
--   trim("{year} {brand} {model}")  → "2024 Volvo VNL"
-- Fall back to template.name when brand/model are NULL.
```

Practical implication for any per-unit display label (admin tables, PDF reports, CSV exports): join `equipment_units → equipment_templates` (LEFT JOIN — template_id is NOT NULL on the units schema, but LEFT JOIN is defensive against orphaned rows). The template also carries `category` (chassis/dry_van/reefer/container/flatbed/step_deck/lowboy/tanker/dump/other) and the default physical specs (length/height/width/weight_capacity/wheels/tires/axles) — most attributes that look like they'd be on the unit row actually live one hop away on the template.

Note the column-name flip: customers uses `company_name`, vendors uses `name`, equipment_templates uses `brand` (not `make`). Three different conventions for three different "identity" columns — always grep before assuming.

**Source:** K-22 catch surfaced in S-ACCT-UNIT (display_label construction in UnitProfitabilityService::getUnitPnl + admin unit-picker labeling). Locked 2026-05-19.

### Trap 46: `damage_claims.status` uses `'invoiced'` — NO `'billed_to_customer'` value
The damage-claims status ENUM is `'reported','assessed','repair_ordered','invoiced','resolved','written_off'`. It does NOT contain `'billed_to_customer'` — that's ASPE/CPA terminology that doesn't match the on-disk vocabulary. The operational state where the recovery invoice has been generated and sent to the customer is `'invoiced'`.

```sql
-- WRONG (the 'billed_to_customer' value never appears in this ENUM)
SELECT * FROM damage_claims WHERE status = 'billed_to_customer';
UPDATE damage_claims SET status = 'billed_to_customer' WHERE id = ?;

-- RIGHT — invoiced is the post-billing operational state
SELECT * FROM damage_claims WHERE status = 'invoiced';
UPDATE damage_claims SET status = 'invoiced' WHERE id = ?;
```

Practical implication for AutoEntryBridge wiring: `AutoEntryBridge::onDamageRecoveryBilled()` fires on the `'invoiced'` transition in `api/v1/damage_claims/update.php`. Anywhere else that needs to detect "recovery has been billed" should also match on `'invoiced'`, not on the ASPE-style `'billed_to_customer'` from spec narrative.

Allowed status transitions on disk (from damage_claims/update.php):
```
  reported       → assessed | written_off
  assessed       → repair_ordered | written_off
  repair_ordered → invoiced | resolved | written_off
  invoiced       → resolved | written_off
  resolved       → [TERMINAL]
  written_off    → [TERMINAL]
```

The `'invoiced' → 'written_off'` path is what triggers `AutoEntryBridge::onDamageWrittenOff()` (DR Bad Debt / CR AR for the invoice balance).

Cross-reference: Trap 38 covers the related `'resolved'/'written_off'` (NOT `'settled'`) catch on the same ENUM. The two damage-claims traps together fully fingerprint this ENUM's vocabulary.

**Source:** K-22 catch surfaced in S-ACCT-DMG pre-flight (AskUserQuestion resolved: 'invoiced' chosen as bridge trigger operationally equivalent to spec's 'billed_to_customer'). Locked 2026-05-19.

---

### Trap 47: `leases` has NO `term_months` column — derive or supply as input
The leases table stores `start_date` (NOT NULL) and `end_date` (nullable). It does NOT carry a single integer `term_months` (or `lease_term_months`, `term_length_months`, `duration_months`) column. Any session that needs lease duration in months MUST either (a) derive it via `TIMESTAMPDIFF(MONTH, start_date, end_date)` from the row at query time, or (b) accept it as an operator-supplied wizard / form input. Do NOT add a SELECT against a non-existent `term_months` column — the query will 1054 at runtime; do NOT silently assume it exists in service signatures.

```sql
-- WRONG (term_months / lease_term_months / duration_months do not exist on disk)
SELECT term_months FROM leases WHERE id = ?;
SELECT lease_term_months FROM leases WHERE id = ?;

-- RIGHT — derive from the two date columns that DO exist
SELECT TIMESTAMPDIFF(MONTH, start_date, end_date) AS term_months
FROM leases WHERE id = ?;

-- ALSO RIGHT — operator supplies term as a wizard input (preferred for ASPE 3065
-- classification per D-LESSOR-1-TERM, because end_date may represent intended
-- return, not contractual term)
$leaseTermMonths = clean_positive_int($body['lease_term_months'] ?? null);
```

Practical implication for ASPE 3065 Criterion B (lease term ≥ 75% of economic life): per D-LESSOR-1-TERM (locked 2026-05-19), `LeaseClassificationService::evaluateCriterionB(int $leaseTermMonths, int $economicLifeMonths)` accepts the term as an explicit input — not a derived value — because operator-supplied term avoids the ambiguity of `end_date` (which on some leases is the intended return date, not the contractual end). The `api/v1/accounting/leases/classify.php` endpoint validates `lease_term_months` as a required positive int.

**Source:** K-22 catch surfaced in S-ACCT-LESSOR-1 pre-flight (AskUserQuestion resolved: operator-supplied wizard input over `end_date - start_date` derivation per D-LESSOR-1-TERM). Locked 2026-05-19.

---

### Trap 48: `equipment_units` count ≠ `leases` count — never substitute one for the other
`equipment_units` (the fleet inventory) and `leases` (the per-contract rental records) are different tables with different lifecycles. At lock time the disk holds **238 equipment_units** but only **42 leases** (32 active + 10 soft-deleted) — a ~5.7× difference. A single unit can have many sequential leases over its lifetime, and many units are idle / on yard / decommissioned at any moment. Substituting one count for the other in session prompts, smoke logs, or migration backfill expectations causes both factual drift and real bugs (e.g. a migration that "backfills 238 rows on leases" only touches 42 rows, leaving 196 phantom expectations downstream).

```sql
-- The two tables, distinct and unrelated in cardinality:
SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL;   -- ≈ 238 units
SELECT COUNT(*) FROM leases          WHERE deleted_at IS NULL;   -- ≈ 32  active leases
SELECT COUNT(*) FROM leases;                                     -- 42 including soft-deleted
```

Practical implication for session prompts: a prompt that says "all N existing leases get classification='operating'" must be sanity-checked against `SELECT COUNT(*) FROM leases` at pre-flight, not assumed from a prior session's smoke output (e.g. S-ACCT-UNIT's "238 active units" referred to equipment_units, NOT leases). Per the `feedback_trust_file_over_prompt` rule, when prompt-baseline and disk disagree on row counts, trust the file and surface via AskUserQuestion.

**Source:** Context-bleed K-22 catch surfaced in S-ACCT-LESSOR-1 pre-flight (S-ACCT-UNIT smoke had reported "238 active units" referring to equipment_units; the LESSOR-1 prompt then carried "238 existing leases" into a leases-table claim). Operator chose trust-file (42) via AskUserQuestion. Locked 2026-05-19.

### Trap 49: `class="modal-backdrop"` ≠ `class="modal-overlay"` — backdrop has no flex centering

The `class="modal-backdrop"` rule (`public/assets/css/app.css:2968`) provides ONLY the dark blurred backdrop layer — `position:fixed; inset:0; background:rgba(0,0,0,0.70); backdrop-filter:blur(4px); z-index:0`. It has **NO** `display:flex; align-items:center; justify-content:center` properties — those live exclusively on `class="modal-overlay"` (`app.css:2958`). Setting `display:flex` inline on a `.modal-backdrop` element gives a flex container but `align-items` and `justify-content` default to `flex-start`, so the `.modal` child renders at the **top-left corner** of the viewport (clipped against the edges) instead of centered.

```html
<!-- ❌ WRONG — modal renders top-left, clipped against viewport edges -->
<div class="modal-backdrop"
     :style="open ? 'display:flex;' : 'display:none;'">
    <div class="modal">…</div>
</div>

<!-- ✅ RIGHT — modal centers via .modal-overlay's CSS (canonical pattern) -->
<div class="modal-overlay"
     x-show="open"
     x-cloak
     @click.self="cancel()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>…</div>
</div>
```

Always use `class="modal-overlay"` for the centering wrapper. Preserve the dark backdrop via inline `style` (the same `rgba` + `backdrop-filter` values `.modal-backdrop` would have applied) when needed. The mobile bottom-sheet pattern at `app.css:5973` (`.modal-overlay { align-items: flex-end; }` inside the `<768px` media query) still works correctly on `.modal-overlay`, so the canonical pattern automatically becomes a bottom-sheet on mobile.

**Recurrence count: 2** —
  (1) COMPLIANCE-FIX-1 (2026-05-19): compliance grid edit modal in `app/admin/compliance/index.php` snapped to top-left when opened.
  (2) S-PERM-EXPAND D' (2026-05-19): cell-level reason modal + group macro modal + reset-all confirmation modal in `app/admin/users/permissions.php` — three modals in one file, same root cause.

A future **S-MODAL-AUDIT** session (`G-MODAL-AUDIT` in `docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md`, scheduled before Phase E Accountants Portal) will grep all remaining `class="modal-backdrop"` occurrences across `app/`, `includes/`, and partials, and migrate each to the canonical pattern (or confirm it's a legitimate pure-backdrop usage without a `.modal` child — e.g. a full-page overlay spinner).

**Source:** Pattern surfaced via COMPLIANCE-FIX-1 (2026-05-19, compliance grid edit modal). Locked as Trap 49 in S-PERM-EXPAND D' (2026-05-19) after a second occurrence of the same bug across three modals in the permissions admin page. Recurrence count will be updated as further incidents surface during the S-MODAL-AUDIT session.

### Trap 50: permissions API response uses `slug` not `module` — match against `m.slug` when iterating

`GET /api/v1/users/permissions/index.php` returns `data.modules[]` where each entry has a **`slug`** field (e.g. `'journal_entries'`, `'chart_of_accounts'`, `'quickbooks'`) — NOT `module`. Client-side code that iterates the response and matches against `config/permission_groups.php` group definitions must use `m.slug === moduleName`, never `m.module === moduleName` (which returns `undefined` and silently breaks the lookup with no error).

```js
// ❌ WRONG — m.module is undefined; .find() returns nothing; status row stays empty
group.modules.forEach(moduleName => {
    const m = responseModules.find(x => x.module === moduleName);
    if (!m) return;
    ...
});

// ✅ RIGHT — matches the actual response shape
group.modules.forEach(moduleName => {
    const m = responseModules.find(x => x.slug === moduleName);
    if (!m) return;
    ...
});
```

The confusion source: `config/permission_groups.php` uses the **key name `'modules'`** (plural array of slug strings) for each group's member list, e.g. `['accounting' => ['modules' => ['chart_of_accounts', 'journal_entries', ...]]]`. So `group.modules` is the correct field on the **group** side. But on the per-module **response** side, the field that holds the slug string is `slug`, not `module`. The two fields (`group.modules` array vs `m.slug` scalar) live on different shapes — easy to misremember as "both use `module`".

Server-side reference (`api/v1/users/permissions/index.php`):

```php
$row = [
    'slug'        => $slug,                  // ← the field client must match against
    'label'       => $labels[$slug] ?? …,
    'actions'     => $moduleActions,         // per-module verb list
    'permissions' => [],                     // {action: {role, override, effective}}
];
```

**Source:** Caught in S-PERM-MACRO-STATUS (2026-05-19) when the session prompt's helper code template for the `groupStatus()` computation used `x.module === moduleName`. The actual response shape uses `slug`. Adjusted silently per `feedback_trust_file_over_prompt` (operator pre-authorized "adjust the status computation to match" in the STOP conditions). Locked as Trap 50 here so future client-side consumers of `/api/v1/users/permissions/index.php` don't repeat the same field-name mismatch.

### Trap 51: `LeaseClassificationService::runWizard()` — NOT `classify()` despite the endpoint name + spec language

The Phase D lease classification service exposes its main entry point as `runWizard($leaseId, $input, $userId)`, **NOT** `classify()`. This is easy to misremember because:

- The HTTP endpoint is `api/v1/accounting/leases/classify.php` — so muscle memory expects a `::classify()` method.
- `FLEETFORGE_ACCOUNTING_SPEC.md` §24.4 calls the workflow the "classification wizard" — natural shorthand "the classify thing."
- The service IS named `LeaseClassificationService` — reinforcing the `classify` instinct.

But the actual public surface is:

```php
namespace FleetForge\Accounting;

class LeaseClassificationService
{
    // ✅ Entry point — orchestrates criteria A/B/C + writes acc_lease_classifications
    public static function runWizard(int $leaseId, array $input, int $userId): array;

    // Lower-level evaluators (called by runWizard, also callable directly for preview)
    public static function evaluateCriterionA(array $input): bool;
    public static function evaluateCriterionB(int $leaseTermMonths, int $economicLifeMonths): bool;
    public static function evaluateCriterionC(string $monthlyPayment, int $termMonths,
                                              string $guaranteedResidual, string $discountRate,
                                              string $fairValue): bool;
    public static function determineClassification(array $criteria, bool $creditRiskNormal,
                                                   bool $costsEstimable, string $initialFairValue,
                                                   string $assetCarryingAmount): string;

    // Read helper
    public static function getClassification(int $leaseId): ?array;

    // ❌ NO ::classify() method exists.
}
```

When drafting bridge prompts, test scenarios, or follow-up sessions, use **`runWizard`** (the service method name), not "classify" (the workflow / endpoint / spec name). The endpoint file `api/v1/accounting/leases/classify.php` internally calls `LeaseClassificationService::runWizard(...)`.

**Source:** Surfaced in S-PHASE-D-INTEGRATION-TEST (2026-05-19) Part C service-interface audit: prompt expected `::classify` but reflection on the live class returned 6 public methods none of which were named `classify`. The semantic equivalent (`runWizard`) was present so the test continued; locked here as Trap 51 so future LESSOR-touching prompts don't repeat the name confusion.

### Trap 52: php-fpm opcache holds stale bytecode in local Herd dev after editing widely-included files

**Symptom**: a CLI smoke test passes (e.g. `php -r "function_exists('...')"` returns `true`, all unit tests green) but the live HTTP endpoint blows up with `Call to undefined function ...` (or similar parse/symbol errors). The error log shows the function being called from a line/file that the disk version clearly contains. Reloading the page doesn't help; restarting only the browser doesn't help. The only fixes are restarting Herd, sending `SIGUSR2` to the php-fpm master, or waiting for opcache's TTL to expire.

**Root cause**: PHP-FPM caches compiled bytecode of every included file via opcache. The Herd dev config typically has `opcache.validate_timestamps=0` (or a long `revalidate_freq`) for performance — so changing a file on disk does NOT invalidate the cached bytecode. The running worker processes keep executing the version they compiled at boot. New top-level function declarations, class definitions, namespace changes, etc. are invisible to live HTTP traffic until you force a reload.

**Most-affected file types** (anything included by many endpoints — the more an endpoint pre-loads it, the bigger the blast radius):
- `includes/auth.php`, `includes/db.php`, `includes/functions.php`, `includes/permission_registry.php` — universal includes
- `includes/header.php`, `includes/footer.php`, `includes/sidebar.php`, `includes/topbar.php`, `includes/partials/*.php` — page chrome
- `api/bootstrap.php` — every API endpoint
- `config/permissions.php`, `config/permission_actions.php`, `config/permission_groups.php`, `config/app.php`, `config/navigation.php` — config files included on every authenticated request

**Production impact: NONE.** Lightsail (mainlandrentals.com) runs php-fpm under systemd; every deploy that touches PHP runs `sudo systemctl reload php8.2-fpm` (or equivalent) which clears opcache by restarting the FPM master. The trap is local-dev-only on Herd. **DO NOT** add anything to the production deploy runbook for this — `F-CRONS-ACCT-1` and friends already trigger php-fpm restart as part of the standard deploy procedure.

**Fixes** (in order of preference):

1. **Automated (recommended)** — `.claude/settings.local.json` (gitignored) carries a `PostToolUse` hook that fires `SIGUSR2` at the php-fpm master whenever Claude Code edits a file in `includes/`, `api/bootstrap.php`, or `config/*.php`. Silent on success (one-line stderr only), no-op on non-Herd environments (pgrep returns nothing). Operators with their own dev environment can add the same hook to their local settings.

   Example hook command (already installed locally):
   ```bash
   FILE=$(jq -r '.tool_input.file_path // empty');
   case "$FILE" in
     */includes/*|*/api/bootstrap.php|*/config/*.php)
       PID=$(pgrep -f 'php-fpm: master' | head -1);
       [ -n "$PID" ] && kill -USR2 "$PID" 2>/dev/null
       && echo "[hook] php-fpm opcache reloaded after $FILE" >&2
       ;;
   esac
   ```

2. **Manual** — when the automation hasn't picked up the change (e.g. fresh Claude Code session before `/hooks` reload):
   ```bash
   kill -USR2 $(pgrep -f "php-fpm: master" | head -1)
   ```

3. **Nuclear** — Herd app → Quit → relaunch (or `herd restart` if that command exists in your install).

**Recurrence count: 1 known incident** — S-PERM-SESSION-REFRESH (2026-05-19, commit `c3684d4`) shipped with full CLI smoke green (`function_exists('_ff_check_permission_freshness')` returned YES, full test suite passed), but the user reported HTTP 500 "Call to undefined function" when trying to grant Alice Manager `journal_entries.edit` via the live admin UI. The CLI test path and the php-fpm path are two separate opcache scopes — CLI is fresh on every invocation, FPM is long-lived. The fix that turn was `kill -USR2 <fpm-master-pid>`; the durable fix locked here is the automated `.claude/settings.local.json` hook.

**Source:** Caught 2026-05-19 during S-PERM-SESSION-REFRESH post-ship sanity-check. Locked as Trap 52 + automated via `PostToolUse` hook. Future sessions touching the listed file types should observe the hook firing in stderr (look for `[hook] php-fpm opcache reloaded after ...`).

### Trap 53: smoke test location — `tests/_smoke_*.php`, NOT `bin/smoke/*.php`

The D131 pre-commit gate's smoke scripts live under `tests/` with the `_smoke_` filename prefix, NOT under `bin/smoke/`. There is no `bin/smoke/` directory in the repository — `bin/` contains only `migrate.php` and `deploy.sh`.

```
✅ Right                                          ❌ Wrong
php tests/_smoke_master_schema_parity.php         php bin/smoke/master_schema_parity.php
php tests/_smoke_billing_invariants.php           php bin/smoke/billing_invariants.php
php tests/_smoke_samsara_distance.php             php bin/smoke/samsara_distance.php
php tests/_smoke_model_b_lifecycle.php            php bin/smoke/model_b_lifecycle.php
php tests/_smoke_doc_freshness.php                php bin/smoke/doc_freshness.php
php tests/_smoke_qbo_client.php                   php bin/smoke/qbo_client.php
php tests/_smoke_qbo_queue.php                    php bin/smoke/qbo_queue.php
php tests/_smoke_qbo_admin_ui.php                 php bin/smoke/qbo_admin_ui.php
php bin/migrate.php --verify                      (this one IS in bin/ — the only exception)
```

As of S-QBO-4 (2026-05-20) the gate is **8 smokes + 1 migrate verify = 9 checks total**. The 8th smoke `_smoke_qbo_admin_ui.php` was added by S-QBO-4 to cover the QBO admin UI surface (4 admin pages + 9 backing API endpoints lint clean + permission gates + nav structure + empty-state reachability + synthetic-data reachability with self-cleaning + mutation-endpoint POST/CSRF + dashboard_metrics JSON shape). Expected growth: S-QBO-N sessions will likely add `_smoke_qbo_drift_invariants.php` and similar as the integration matures.

The leading underscore on the filenames (`_smoke_*`) is intentional — it sorts these test files together at the top of `tests/` directory listings without colliding with the other `tests/_integration/`, `tests/_interaction/`, `tests/_regression/` subdirectories.

**Source:** Caught silently in S-QBO-1 (2026-05-20) pre-commit gate when the session prompt's D131 command list pointed at the non-existent `bin/smoke/` paths. Resolved per `feedback_trust_file_over_prompt` by running the smokes from `tests/_smoke_*.php` instead. Locked here so future D131-touching prompts use the correct paths from the start.

### Trap 54: settings helper — `settings_get()`, NOT `setting()` / `get_setting()`

The settings table read helper is named `settings_get(string $key, mixed $default = null): mixed`, defined in `includes/functions.php` (~line 295). There is NO `setting()` function and NO `get_setting()` function — using either produces a fatal `Call to undefined function` at runtime.

```php
✅ Right                                  ❌ Wrong
$env = settings_get('quickbooks.environment', 'sandbox');
$env = setting('quickbooks.environment');        // undefined function
$env = get_setting('quickbooks.environment');    // undefined function
```

For writes, the canonical pattern is the `INSERT … ON DUPLICATE KEY UPDATE` idiom (see `api/v1/settings/brand.php::ff_settings_write` for the reference implementation, or `lib/QuickBooksClient::settings_write_qbo` for the QBO-namespaced helper). There is no global `settings_set()` function — every consumer either inlines the upsert SQL or wraps it in a module-local helper. Verify the helper name at the use site before invoking; grep for `INSERT INTO.*settings` to find existing write idioms.

**Source:** Caught silently in S-QBO-1 (2026-05-20) when the session prompt's reference grep used `function setting\b\|function get_setting`. Actual helper is `settings_get`. Resolved per `feedback_trust_file_over_prompt`. Locked here so future settings-touching prompts use the correct name from the start. Companion: `legal_config('dot.path')` is the analogous read helper for the legal-config blob (see `includes/functions.php` ~line 326).

### Trap 55: icon name — `clipboard-document-list`, NOT bare `clipboard-document`

The icon library at `public/assets/icons/` does NOT contain a file named `clipboard-document.svg` — the closest existing icons are `clipboard-document-check.svg`, `clipboard-document-list.svg`, and the unrelated `document-text.svg` / `list-bullet.svg`. Referencing `'clipboard-document'` in `config/navigation.php` (or any other consumer of `heroicon()`) silently renders an `icon-missing` placeholder span instead of the actual SVG.

```
✅ Right                            ❌ Wrong
'icon' => 'clipboard-document-list' 'icon' => 'clipboard-document'
'icon' => 'clipboard-document-check'
```

**General rule, applies to every icon reference** (not just clipboard variants): before using an icon name in code, verify the file exists with `ls public/assets/icons/{name}.svg`. The `heroicon()` helper in `includes/sidebar.php` ~line 16 deliberately renders a placeholder rather than throwing so a missing icon doesn't 500 the page — which means missing icons are easy to ship by accident if not caught at write time.

**Source:** Caught silently in S-QBO-1 (2026-05-20) when the session prompt referenced `clipboard-document` for the Sync Log nav item. PHP lint passed, page rendered, but the icon slot would have been an empty placeholder. Fixed to `clipboard-document-list` before commit. Locked here.

### Trap 56: current-user name access — `current_user()['name']`, NOT `current_user_name()`

There is NO `current_user_name()` helper function. The canonical pattern is to call `current_user()` (returns the in-session user array or `null` if not authenticated) and then index for the field:

```php
✅ Right                                          ❌ Wrong
$name = current_user()['name'] ?? 'system';      $name = current_user_name();   // undefined fn
```

The `?? 'system'` fallback handles the unauthenticated / cron-context case where `current_user()` returns `null`. Other commonly accessed fields use the same pattern:

```php
$userId    = current_user_id();              // there IS a current_user_id() shorthand for this one specifically
$userEmail = current_user()['email'] ?? null;
$userRole  = current_user()['role_slug'] ?? null;
```

Only `current_user_id()` has a dedicated shorthand (see `includes/auth.php` ~line 188) — every other field goes through the array indexer. The shorthand exists because user_id is by far the most common field consumed by audit_log inserts.

**Source:** Caught silently in S-QBO-1 (2026-05-20) when the OAuth callback file initially tried `current_user_name()`. Fixed to `current_user()['name'] ?? 'system'` before commit. Locked here so future audit_log-writing prompts don't repeat the name confusion.

### Trap 57: audit_log.action ENUM — `'update'`, NOT `'edit'`

`audit_log.action` is a strict ENUM with no `'edit'` value. The full list of allowed values (from the DESCRIBE on the live schema):

```
'create' | 'update' | 'delete' | 'restore' | 'login' | 'logout' | 'export' |
'status_change' | 'view' | 'bulk_action' | 'payment_recorded' | 'invoice_sent' |
'invoice_voided' | 'lease_closed' | 'cron'
```

```php
✅ Right                                  ❌ Wrong
db_insert('audit_log', [                  db_insert('audit_log', [
    'action' => 'update',                     'action' => 'edit',     // silently truncates
    ...                                       ...
]);                                       ]);
```

MySQL silently truncates invalid ENUM values to empty string (in strict mode it throws — but the default mode is permissive on this install per Trap 10). Either way the audit row ends up unqueryable by action. **Always verify the action you intend to log is in the list above before INSERT** — companion confirmed: `'bulk_action'` (not `'import'`) per the existing convention locked by D-IMPORT-AUDIT.

For modification operations (updating an entity, changing a credential, flipping a setting), the canonical value is `'update'`. The semantic "edit" exists in user-facing language and permission-vocabulary contexts (`can('module', 'edit')`) — but it does NOT exist in the audit_log ENUM.

**Source:** Caught silently in S-QBO-1 (2026-05-20) — the session prompt's OAuth callback spec said "action='edit'" for failure-branch audit rows, but `audit_log.action` ENUM does not contain that value. Fixed to `'update'` before commit. Locked here as the companion-trap to Trap 10 (which already covered the general "ENUM truncates invented values" rule + the `'bulk_action'` / `'import'` convention). Always run `DESCRIBE audit_log` (or check the master file) before any new audit insert that uses a non-standard action value.

### Trap 58: Sentry IS installed — use `\FleetForge\Observability\Sentry::captureException()`, NOT class_exists guards

The project Sentry wrapper at `lib/Observability/Sentry.php` (NAMESPACE `FleetForge\Observability`) is fully installed and used in production. Composer declares `sentry/sentry: ^4.25`, locked at `4.25.0` in `composer.lock`. The wrapper is a 195-line surface with three methods (`init()`, `captureException()`, `captureMessage()`) plus a `before_send` PII scrubber redacting bcrypt hashes (`$2y$` / `$2b$` prefix), FleetForge AES ciphertext (`ENC:` prefix), and 12 sensitive key patterns (password, mfa_secret, Authorization, csrf_token, etc.). Used by 10+ crons (`backup_db.php`, `gps_mileage_sync.php`, `health_scores.php`, `samsara_sync.php`, `risk_scores.php`, `ai_anomaly_scan.php`, `ai_fleet_brief.php`, `archive_old_data.php`, `late_fee_apply.php`, `promise_to_pay_check.php`) and by `lib/QuickBooksClient::captureSentry()` for structured §13.5 reporting.

```php
✅ Right
\FleetForge\Observability\Sentry::captureException($e);

// For structured tags + extra per spec §13.5 (QBO integration pattern):
\Sentry\withScope(function ($scope) use ($tags, $extra, $e) {
    foreach ($tags as $k => $v) { $scope->setTag((string) $k, (string) $v); }
    foreach ($extra as $k => $v) { $scope->setExtra((string) $k, $v); }
    \Sentry\captureException($e);
});

❌ Wrong
if (class_exists('\Sentry\Sentry') && function_exists('\Sentry\captureException')) {
    // defensive guard against pending Sentry — but Sentry is NOT pending,
    // it's been installed since S-PROD-2 (2026-05-02).
    \Sentry\captureException($e);
}
```

**Note on DSN configuration**: the local dev environment has no `SENTRY_DSN` configured, so `Sentry::init()` short-circuits to no-op on every entry-point's `init()` call (verified at `lib/Observability/Sentry.php:55-60`). This is intentional and lets smokes pass without an external dependency. Production DSN setup is a separate operator task tracked in FLEETFORGE_PREDEPLOY_CHECKLIST.md. Code that calls `captureException` does NOT need to check whether init succeeded — the wrapper's own static `$initialized` flag short-circuits subsequent calls when DSN was blank.

**Layering pattern** (both layers are advisory — failures must never propagate):
1. **Primary** — call `\FleetForge\Observability\Sentry::captureException($e)`. Handles init guard + DSN check + PII scrub. Wrap in try/catch + `error_log()` on failure so a Sentry SDK crash never breaks the request.
2. **Optional structured** — if you need to attach spec-§13.5-style tags/extra (specific to QBO integration so far, may grow), call `\Sentry\withScope(...)` directly. Guard on `function_exists('\Sentry\withScope')` since the raw SDK API surface may shift between major versions.

**Source:** Caught silently in S-QBO-2 (2026-05-20) — the session prompt assumed Sentry was pending and instructed wrapping calls in `class_exists` guards. Verified Sentry already installed via composer.json grep + `lib/Observability/Sentry.php` existence + 10+ production cron consumers. Resolved per [[feedback_trust_file_over_prompt]]. Locked here so future S-QBO-N prompts (and any module that catches exceptions) skip the defensive guards and call the wrapper directly.

### Trap 59: OAuth callback under ngrok — cross-origin session cookies break `$_SESSION` and `require_auth()`

**Wrong**: assuming session-based OAuth state (`$_SESSION['qbo_oauth_state']`) and `require_auth()` session checks will work when the OAuth callback arrives via a DIFFERENT ORIGIN than the browser session was established on.

**Right**: for OAuth flows tested locally with ngrok (or any cross-origin redirect scenario), the entire callback handler must be **auth-context-free** and state verification must use **DB-backed tokens**, NOT PHP session storage. Browser session cookies are per-origin. If the user is logged in on `fleetforge.test` but the callback URL points at `ngrok-free.dev`, no session cookie is sent on the callback request → `$_SESSION` is empty → both state verification AND `require_auth()` fail. Worse: `base_url()` in flash-redirect helpers may generate `fleetforge.test` URLs even when responding to a request that came in via `ngrok-free.dev`, causing the post-callback redirect to switch origins entirely (browser sees a 302 to a different domain, lands on whatever it's authenticated to there — usually `/dashboard`).

```php
❌ Wrong (per-origin session cookie + session-backed state):
session_start();
$_SESSION['qbo_oauth_state'] = bin2hex(random_bytes(32));   // init.php
// ... user redirected to Intuit, then back to callback.php via ngrok ...
$expected = $_SESSION['qbo_oauth_state'] ?? '';             // EMPTY — different origin
require_auth();                                              // FAILS — empty session

✅ Right (DB-backed state token, auth-context-free callback):
// init.php — STILL requires auth (operator initiating the flow)
$stateToken = bin2hex(random_bytes(32));
db_insert('acc_oauth_states', [
    'state_token' => $stateToken,
    'provider'    => 'quickbooks',
    'expires_at'  => date('Y-m-d H:i:s', time() + 600),  // 10-min TTL
]);
// redirect with $stateToken in the URL

// callback.php — NO require_auth(), NO require_permission()
// State token IS the auth proof
$row = db_row("SELECT id, expires_at, used_at FROM acc_oauth_states
                WHERE state_token = ? AND provider = 'quickbooks'", [$state]);
if (!$row || $row['used_at'] || strtotime($row['expires_at']) < time()) {
    /* reject */
}
db_execute("UPDATE acc_oauth_states SET used_at = NOW() WHERE id = ?", [$row['id']]);
// proceed with token exchange — current_user_id() may be null; that's fine
```

**Detected**: 2026-05-20 during S-QBO-1 sandbox OAuth setup. Resolved by **temporary bypass** of `require_auth()` + `require_permission()` + state check in `callback.php` to complete OAuth (the only way to bridge the cross-origin gap with session-backed state). Tokens stored successfully; bypass reverted post-setup via the hotfix commit `a0a4a7a`. **Proper fix tracked as S-QBO-OAUTH-FIX** (DB-backed state via new `acc_oauth_states` table + auth-context-free callback). Until that ships, sandbox OAuth setup via ngrok requires the same temporary-bypass dance.

**Constitutive principle (lock this — applies to every OAuth flow, not just QBO)**: **OAuth callbacks are public endpoints; the state token IS the authentication proof, NOT the user session.** Companion patterns to watch for: future OAuth-flavored integrations (Stripe webhooks, Auth0 callbacks, Plaid, etc.) must avoid the session-backed-state pattern from day one. If you write `$_SESSION['x_oauth_state']` anywhere, you have just inherited this trap.

**Source**: Hotfix commit `a0a4a7a` (2026-05-20) + S-QBO-OAUTH-FIX queue entry + D-S-QBO-1-CALLBACK-HOTFIX in PROGRESS.md DECISIONS. Locked here as the architectural lesson so the next OAuth integration starts with DB-backed state instead of re-discovering the trap.

### Trap 60: QBO `QueryResponse` returns object-not-array for 1-row collections

The Intuit QBO REST API serializes `QueryResponse.<EntityType>` as a bare object when exactly ONE entity matches, and as an array of objects when two or more match. Zero matches: the key is absent entirely.

This is asymmetric and undocumented in Intuit's own docs. Code that does `foreach ($response['QueryResponse']['Customer'] as $c)` works fine on pages with multiple customers but iterates over OBJECT KEYS (`DisplayName`, `Id`, `SyncToken`, …) on a page with exactly one — silently producing nonsense results.

```php
❌ Wrong (works for N≥2, breaks for N=1)
foreach ($response['QueryResponse']['Customer'] as $customer) {
    $name = $customer['DisplayName']; // breaks at N=1: $customer is a string
}

✅ Right (since S-QBO-5-FIX-1)
// QuickBooksClient::query() normalizes the 1-row case in-place.
// Every uppercase-keyed field under QueryResponse is guaranteed to be
// an array after query() returns.
foreach ($response['QueryResponse']['Customer'] ?? [] as $customer) {
    $name = $customer['DisplayName']; // always works
}
```

Applies to every QBO entity type: Customer, Vendor, Invoice, Payment, CreditMemo, Bill, JournalEntry, BankAccount, Item, TaxCode, etc. The normalization is centralized in `QuickBooksClient::query()` so Pusher / Puller authors never need to defensively wrap; in fact, doing so post-normalization is **incorrect** (it would re-wrap already-arrayed data into a single-element wrapper around the array — turning N customers into 1 "customer" that's actually a list).

The coercion is exposed as `QuickBooksClient::normalizeQueryResponse(array $response): array` (public static) so offline smokes (`tests/_smoke_qbo_client.php` C7) can exercise it without going through the cURL boundary — same pattern as the existing `_testClassify` accessor used by the classifyError smoke.

**Heuristic**: entity collections are uppercase-PascalCase (Customer, Vendor, …); metadata fields are camelCase (startPosition, maxResults, totalCount). The normalizer walks `QueryResponse`, skips keys whose first character is not uppercase, and wraps any qualifying bare-object value into a single-element array.

**Source**: K-22 catch surfaced during S-QBO-5 live sandbox verification — CustomerPuller initially had a per-call defensive wrap (`if (!empty($batch) && !isset($batch[0])) $batch = [$batch];`). Recognized as a pattern that would replicate across every future Pusher (Vendor, Invoice, Payment, Bill, …) if left per-call instead of centralized at the HTTP boundary. Locked 2026-05-21 via S-QBO-5-FIX-1 + D-QBO-5-FIX-1-1/-2/-3 in PROGRESS.md DECISIONS.

### Trap 61: DB helper nomenclature — single-row vs multi-row vs write

**Wrong**: `db_query_one($sql, $params)` for single-row fetch (or `db_query($sql)` as a generic helper).

**Right**: the project exports three distinct helpers from `includes/db.php`, each scoped to one kind of operation:

```php
db_row($sql, $params)     // single-row fetch, returns assoc array or null
db_select($sql, $params)  // multi-row fetch, returns array of assoc arrays (empty array on no rows)
db_execute($sql, $params) // INSERT/UPDATE/DELETE, returns int affected row count
db_insert($table, $data)  // INSERT helper with named columns
db_count($sql, $params)   // single-int aggregate (e.g. COUNT(*))
db_transaction($closure)  // transactional wrapper
```

```php
❌ Wrong (function doesn't exist; PHP raises Error: Call to undefined function)
$row = db_query_one("SELECT * FROM customers WHERE id = ?", [$id]);

✅ Right (project-canonical helpers, exported by includes/db.php)
$row = db_row("SELECT * FROM customers WHERE id = ?", [$id]);
```

Applies to every prompt that constructs SQL for FleetForge code. The `db_query_one` name does not exist in the project — referencing it in a prompt produces silent K-22 errors that surface as fatal undefined-function errors at runtime.

**Source**: Same trap hit twice in consecutive sessions — S-QBO-5 (`CustomerMatcher::matchAll` used `db_query` in the prompt pseudocode) + S-QBO-6 (`CustomerPusher::pushImpl` used `db_query_one`). Resolved silently both times per [[feedback_trust_file_over_prompt]] but the pattern is durable enough to catalogue. Locked 2026-05-21 via D-PUSHER-PATTERNS-DOCS-LOCK. See also §1B Key Conventions.

### Trap 62: `acc_qbo_sync_queue` schema — column names + status default

**Wrong** assumptions from prior prompts: `status` default = `'pending'`; column `next_attempt_at`; column `last_attempted_at`; column `last_error`.

**Right** (verified against `db_migrations/202605202100_S-QBO-3.sql` and `FLEETFORGE_DATABASE_MASTER.sql`):

| Column | Type | Notes |
|---|---|---|
| `id` | `int unsigned AUTO_INCREMENT` | PK |
| `entity_type` | `ENUM('customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code')` | NOT NULL |
| `entity_id` | `int unsigned` | NOT NULL |
| `operation` | `ENUM('create','update','void','delete')` | NOT NULL |
| `status` | `ENUM('queued','processing','completed','failed','skipped')` | NOT NULL DEFAULT **`'queued'`** (not `'pending'`) |
| `priority` | `tinyint` | NOT NULL DEFAULT 5 |
| `retry_count` | `tinyint` | NOT NULL DEFAULT 0 |
| `max_retries` | `tinyint` | NOT NULL DEFAULT 5 |
| `next_retry_at` | `datetime` | NULL — name is `next_retry_at`, NOT `next_attempt_at` |
| `error_message` | `text` | NULL — full error string |
| `error_code` | `varchar(50)` | NULL — categorical (e.g. `'pusher_not_implemented'`, `'qbo_stale_object'`) |
| `enqueued_at` | `datetime` | NOT NULL DEFAULT CURRENT_TIMESTAMP — the column that's effectively the "created_at" |
| `picked_up_at` | `datetime` | NULL — worker sets when claiming the row |
| `completed_at` | `datetime` | NULL — worker sets when status transitions to completed/failed/skipped |
| `worker_id` | `varchar(50)` | NULL — identifies which worker process picked up the row |
| `payload_snapshot` | `json` | NULL — optional snapshot of FF entity state at enqueue time |

```php
❌ Wrong (would produce silent SQL errors on default-value handling + missing columns)
db_insert('acc_qbo_sync_queue', [
    'entity_type'      => 'customer',
    'entity_id'        => $id,
    'operation'        => 'create',
    'status'           => 'pending',          // ENUM doesn't include 'pending'
    'next_attempt_at'  => date('Y-m-d H:i:s'), // column doesn't exist
    'last_error'       => null,                // column doesn't exist
]);

✅ Right (matches verified schema)
db_insert('acc_qbo_sync_queue', [
    'entity_type'  => 'customer',
    'entity_id'    => $id,
    'operation'    => 'create',
    'status'       => 'queued',     // ENUM default — can also omit to let DB default fire
    'priority'     => 100,
    'retry_count'  => 0,
    'max_retries'  => 3,
    // enqueued_at fires from DB CURRENT_TIMESTAMP — don't pass
    // next_retry_at stays NULL on initial enqueue; worker computes on retry
]);
```

Applies to every Enqueuer + Pusher session (S-QBO-7 vendors, S-QBO-8 items, S-QBO-9 accounts, S-QBO-11 invoices, S-QBO-13 payments, etc.) AND to the queue-management UI endpoints (S-QBO-4 sync_queue_list/retry/clear). Reference the canonical column list above before writing any new INSERT/UPDATE against this table.

**Source**: K-22 catch during S-QBO-6 build — prompt's CustomerEnqueuer pseudocode used `status='pending'` + `next_attempt_at`; verified against actuals + corrected silently before commit. Locked 2026-05-21 via D-PUSHER-PATTERNS-DOCS-LOCK.

### Trap 63: `QuickBooksClient` entity operations — `createEntity/updateEntity`, not per-entity wrappers

**Wrong**: `$client->createCustomer($payload)` / `$client->updateCustomer($payload)` / `$client->createVendor(...)` / per-entity-named methods.

**Right**: single typed entry points with entity-name string as first argument — same shape for every QBO entity type.

```php
public function createEntity(string $type, array $data, array $opts = []): array
public function updateEntity(string $type, string $id, string $syncToken, array $data, array $opts = []): array
public function getEntity(string $type, string $id, array $opts = []): array
```

`$type` accepts the QBO entity name (case-insensitive — `'customer'`, `'Customer'`, `'CUSTOMER'` all work; client lowercases for the URL path). Per-entity wrappers do NOT exist on the client and must not be invented by future Pushers — the typed API stays compact + the entity-type stays explicit at every call site, which the sync_log writer (per spec §6.5) needs for the `entity_type` column.

```php
❌ Wrong (function doesn't exist on QuickBooksClient — PHP raises Error)
$response = $client->createCustomer($qboPayload);
$response = $client->updateCustomer($qboPayload);

✅ Right (canonical surface, S-QBO-2 shipped)
$response = $client->createEntity('customer', $qboPayload);
$response = $client->updateEntity('customer', $qboId, $syncToken, $qboPayload);
// For sparse updates, pass ['sparse' => true] via $opts, NOT inside $data —
// the implementation merges Id+SyncToken+sparse from $opts on top of $data.
$response = $client->updateEntity('customer', $qboId, $syncToken, ['Active' => false], ['sparse' => true]);
```

Applies to every Pusher session + any one-off entity-lifecycle script (e.g. deactivation, bulk-resync). The `sparse` parameter has its own footgun — see Trap #63a-equivalent in `lib/QuickBooksClient.php::updateEntity` source comments; passing `sparse=true` inside `$data` gets overwritten by the `$opts['sparse'] ?? false` merge and the resulting `sparse=false` triggers QBO "No name provided" errors on minimal payloads.

**Source**: S-QBO-6 CustomerPusher initial draft used `createCustomer`/`updateCustomer`; verified against actual `lib/QuickBooksClient.php` shipped in S-QBO-2 (methods at lines 357 + 374). Same `sparse-via-$opts` footgun bit the S-QBO-6 live verification cleanup script. Locked 2026-05-21 via D-PUSHER-PATTERNS-DOCS-LOCK.

### Trap 64: `QboPusherDispatcher` contract — `pushCreate` + `pushUpdate`, not combined `::push($id, $op, …)`

**Wrong**: a single combined method `public static function push(int $id, string $operation, ?array $payload = null): array` that switches on `$operation` internally.

**Right**: separate public-static methods per operation, with the dispatcher's `OPERATION_METHODS` map routing each operation to its dedicated method:

```php
// lib/QboPusherDispatcher.php (S-QBO-3, line 69)
private const OPERATION_METHODS = [
    'create' => 'pushCreate',
    'update' => 'pushUpdate',
    'void'   => 'pushVoid',
    'delete' => 'pushDelete',
];
```

Every Pusher class MUST implement at least `pushCreate` + `pushUpdate` as separate public static methods. Optional `pushVoid` / `pushDelete` for entity types that support them (customers do NOT delete-enqueue per D-QBO-6-1; invoices DO void-enqueue per spec §8.4).

```php
❌ Wrong (dispatcher throws PusherNotImplementedException at runtime — method doesn't exist)
class CustomerPusher {
    public static function push(int $id, string $operation, ?array $payload = null): array {
        if ($operation === 'create') { /* ... */ }
        if ($operation === 'update') { /* ... */ }
    }
}

✅ Right (matches dispatcher OPERATION_METHODS contract)
class CustomerPusher {
    public static function pushCreate(int $id, ?array $payload = null): array {
        return self::pushImpl($id, 'create', $payload);
    }
    public static function pushUpdate(int $id, ?array $payload = null): array {
        return self::pushImpl($id, 'update', $payload);
    }
    private static function pushImpl(int $id, string $operation, ?array $payload): array {
        // shared logic: sync_mode gate, FF entity load, mapping lookup,
        // idempotency check, payload build, HTTP call, mapping upsert.
    }
}
```

Method signature: `(int $entityId, ?array $payloadSnapshot = null): array`. The `?array` second parameter is the optional `payload_snapshot` from `acc_qbo_sync_queue` — the worker passes it along; CustomerPusher (the reference impl) ignores it because it loads fresh state from the FF DB, but future Pushers may use it for at-enqueue-time payload preservation.

Applies to every Pusher session — vendors (S-QBO-7), items (S-QBO-8), accounts (S-QBO-9), invoices (S-QBO-11 + S-QBO-12 for void), payments (S-QBO-13), bills (S-QBO-15+), journal entries (S-QBO-21), etc.

**Source**: S-QBO-6 CustomerPusher initial draft used a combined `::push($id, $op, $payload)` signature; verified against `lib/QboPusherDispatcher.php` `OPERATION_METHODS` constant + the dispatch invocation `$className::$methodName($entityId, $payloadSnapshot)` (line 127). Refactored to `pushCreate` + `pushUpdate` + shared private `pushImpl()` per D-QBO-3-2 + D-PUSHER-CONTRACT (new). Locked 2026-05-21 via D-PUSHER-PATTERNS-DOCS-LOCK. See also `FLEETFORGE_QUICKBOOKS_SPEC.md` §6.8 Pusher Integration Contract.

### Trap 65: FF lowercase snake_case vs QBO PascalCase plural — never assume naming parity

**Wrong**: assuming a single naming convention spans FF schema and QBO API responses. Comparing FF.acct_subtype string against literal `'AccountsReceivable'` / `'AccountsPayable'` / `'UndepositedFunds'` (PascalCase) in an `IF`-condition — that branch never fires because FF stores `'current_asset'` / `'current_liability'` (lowercase snake_case).

**Right**: every cross-system reference table maintains an EXPLICIT equivalence map translating FF values (lowercase snake_case) → QBO values (PascalCase, plural-when-applicable). Conditional logic looks up via the map; never compares literals across the boundary.

```php
❌ Wrong (FF subtype literally never equals 'AccountsReceivable')
if ($ffAccount['account_subtype'] === 'AccountsReceivable') {
    $category = 'ar_clearing';
}

✅ Right (explicit map; FF key → QBO compat list)
// lib/QboPushers/AccountMatcher.php::SUBTYPE_EQUIVALENCE
public const SUBTYPE_EQUIVALENCE = [
    'current_asset'       => ['AccountsReceivable', 'OtherCurrentAssets', 'PrepaidExpenses', 'Inventory', 'UndepositedFunds', ...],
    'current_liability'   => ['AccountsPayable', 'CreditCard', 'OtherCurrentLiabilities', ...],
    'long_term_liability' => ['OtherLongTermLiabilities', 'NotesPayable', 'LongTermDebt', ...],
    ...
];
```

**Specific concrete divergences observed:**
- `acc_accounts.account_subtype` values: `'current_asset'`, `'fixed_asset'`, `'current_liability'`, `'long_term_liability'`, `'operating_expense'`, `'cost_of_revenue'`, `'equity'`, `'revenue'`, `'other'` (all lowercase snake_case, 9 distinct).
- `acc_qbo_account_map.qbo_account_subtype` values (QBO API responses): `'AccountsReceivable'`, `'AccountsPayable'`, `'OtherCurrentAssets'` (PLURAL), `'OtherCurrentLiabilities'` (PLURAL), `'OtherLongTermLiabilities'` (PLURAL), `'CreditCard'`, `'PrepaidExpenses'`, `'AccumulatedDepreciation'`, etc. — PascalCase, plural for the `OtherCurrent*`/`OtherLongTerm*` family.
- `customers.gps_revenue_presentation`: `'net'`, `'gross'` (lowercase).
- QBO `Item.Type`: `'Service'`, `'Inventory'`, `'NonInventory'` (PascalCase, no plural).
- `tax_rates.province`: `'BC'`, `'ON'`, `'QC'` (uppercase 2-char codes); QBO TaxCode names: `'NON'`, `'GST/HST ON'`, etc. (free-form strings — no structural relation to FF province codes).

**Heuristic for spotting the bug at session-prompt time**: any session-prompt SQL CASE WHEN or PHP conditional that literally compares an FF column value against a PascalCase string is an instant K-22 candidate. Resolve via AskUserQuestion before writing code per [[feedback_trust_file_over_prompt]].

**Detected**: S-QBO-8 (account subtypes), S-QBO-9 (tax code naming), S-QBO-10 (Item Type field), S-QBO-MATCHER-GREEDY-FIX (SUBTYPE_EQUIVALENCE constant), S-QBO-VALIDATOR-SCOPE-SPLIT (critical_category heuristic) — same pattern surfaced 5 sessions in a row. Every future cross-system reference data session MUST state explicitly: "FF uses snake_case; QBO uses PascalCase plural; maintain explicit equivalence map; never assume parity." Locked 2026-05-24 via D-PHASE-QBO-4-DOCS-LOCK + memory [[project_qbo_subtype_taxonomy]].

### Trap 66: MySQL ALTER appends new indexes at END of definition; DATABASE_MASTER inline ordering breaks parity

**Wrong**: when a migration adds a new index via `ALTER TABLE ADD INDEX`, editing `FLEETFORGE_DATABASE_MASTER.sql` to insert the new index inline with logically-related indexes (e.g. placing `idx_critical_category` next to the existing `idx_critical`).

**Right**: MySQL's `ALTER TABLE ADD INDEX` deterministically appends the new index at the END of the index/FK/CONSTRAINT block in the live table definition, regardless of logical grouping. `DATABASE_MASTER.sql` must mirror this append order or `tests/_smoke_master_schema_parity.php` fails.

```sql
-- Migration: ALTER TABLE acc_qbo_account_map ADD INDEX idx_critical_category (critical_category, mapping_status);
-- Live MySQL position (post-ALTER):

PRIMARY KEY (`id`),
UNIQUE KEY `uq_ff_account` (`ff_account_id`),
UNIQUE KEY `uq_qbo_account` (`qbo_account_id`),
KEY `idx_status` (`mapping_status`),
KEY `idx_critical` (`is_critical`, `mapping_status`),
KEY `idx_acct_type` (`qbo_account_type`),
KEY `idx_last_synced` (`last_synced_at`),
KEY `fk_qbo_acct_map_user` (`created_by_user_id`),
KEY `idx_critical_category` (`critical_category`, `mapping_status`),   -- ← APPENDED AT END
CONSTRAINT `fk_qbo_acct_map_ff` FOREIGN KEY (`ff_account_id`) REFERENCES `acc_accounts` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_qbo_acct_map_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
```

**Wrong vs right in DATABASE_MASTER**:

```sql
❌ Wrong (inline-with-related grouping — parity smoke fails)
KEY `idx_critical` (`is_critical`, `mapping_status`),
KEY `idx_critical_category` (`critical_category`, `mapping_status`),  ← inline next to idx_critical
KEY `idx_acct_type` (`qbo_account_type`),

✅ Right (append-at-end matching ALTER behavior)
KEY `idx_critical` (`is_critical`, `mapping_status`),
KEY `idx_acct_type` (`qbo_account_type`),
KEY `idx_last_synced` (`last_synced_at`),
KEY `fk_qbo_acct_map_user` (`created_by_user_id`),
KEY `idx_critical_category` (`critical_category`, `mapping_status`),  ← end of indexes, before CONSTRAINTs
CONSTRAINT `fk_qbo_acct_map_ff` FOREIGN KEY ...
```

**Pre-commit verification discipline**: run `php tests/_smoke_master_schema_parity.php` BEFORE committing the migration. If it fails, the master ordering is wrong (not the migration). Note this only applies to ALTER-added indexes — for fresh `CREATE TABLE` migrations the index ordering in DATABASE_MASTER can be whatever the migration declares (live DB and master will both follow the migration's order).

**Detected**: S-QBO-VALIDATOR-SCOPE-SPLIT live verification — first parity-smoke run failed with `+ KEY idx_critical_category` / `- KEY idx_critical_category` (same line, different positions). Resolved by moving DATABASE_MASTER's `idx_critical_category` to end-of-indexes. Locked 2026-05-24 via D-PHASE-QBO-4-DOCS-LOCK.

### Trap 67: ENUM values may not match roadmap/spec hypothetical lists — always introspect

**Wrong**: trusting roadmap-document or spec-document hypothetical lists of ENUM values when writing matcher/mapper code. Roadmap drafts are aspirational; the spec may carry hypothetical values that were never implemented or were renamed during build-out.

**Right**: schema introspection BEFORE any code that iterates over an ENUM domain. Source of truth is `INFORMATION_SCHEMA.COLUMNS.COLUMN_TYPE` at runtime (or a `SHOW COLUMNS` query — both return the live ENUM definition).

```php
✅ Right — ItemMatcher::ffItemTypes() pattern, replicate for any ENUM-iterating Matcher
public static function ffItemTypes(): array
{
    $col = db_row(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'invoice_line_items'
            AND COLUMN_NAME  = 'item_type'"
    );
    // COLUMN_TYPE looks like: enum('base_rental','mileage_precharge', ...)
    preg_match("/^enum\((.+)\)$/i", $col['COLUMN_TYPE'], $m);
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $vm);
    return $vm[1] ?? [];  // live ENUM values, never hardcoded
}
```

**Concrete divergence observed**: `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9.4 listed hypothetical `invoice_line_items.item_type` values including `'mileage_overage'`, `'damage_recovery'`, `'early_termination_fee'`, `'setup_fee'`, `'delivery_fee'`, `'tax_adjustment'`, `'prepayment'`. Live ENUM had **none** of these — actual 17 values: `base_rental`, `base_rental_reconciliation_credit`, `gps`, `mileage_precharge`, `mileage_adjustment`, `mileage_credit`, `mileage_usage`, `mileage_drawdown_credit`, `damage`, `late_fee`, `early_return_credit`, `insurance`, `warranty`, `manual_adjustment`, `discount`, `account_credit_applied`, `other`. **7 of 17 values different** from the roadmap.

**Remediation pattern**: every Matcher that iterates an ENUM domain MUST pull from `INFORMATION_SCHEMA` at runtime (matches the `ItemMatcher::ffItemTypes()` pattern from S-QBO-10). Hardcoded lists go stale silently — the next ENUM addition (likely via a future S-* session) won't surface in UI unless someone manually bumps the constant. INFORMATION_SCHEMA introspection automatically picks up additions.

**Detected**: S-QBO-10 ship — roadmap divergence forced rewriting `ItemMatcher::DISPLAY_NAMES` from the prompt's hypothetical list to the live ENUM. The 7-of-17-mismatch ratio is high enough that any future "iterate this ENUM" prompt MUST introspect rather than trust the prompt's literal list. Locked 2026-05-24 via D-PHASE-QBO-4-DOCS-LOCK.

### Trap 68: Validator empty-category branches MUST throw, never silent-pass

**Wrong**: a per-session validator method that finds zero FF accounts tagged with a required category and silently returns "ok" (no throw). Example: `AccountValidator::assertReadyForPaymentPush()` requires `undeposited_funds` category; no FF account is tagged with that category in v1 chart; method returns silently and downstream payment push code uses the unsatisfied category → fails later with an unrelated error in QBO API call.

**Right**: throw `ChartOfAccountsIncompleteException` with an actionable add-and-tag message. The exception message MUST name (a) the missing category, (b) the specific remediation action ("add an FF account, tag with `critical_category=X`, then re-run").

```php
✅ Right (see lib/QboPushers/AccountValidator.php::assertCategoriesReady)
foreach ($blocking as $cat) {
    $rows = $unmappedByCat[$cat] ?? [];
    $allRows = $allCriticalByCat[$cat] ?? [];
    if (empty($allRows)) {
        // ZERO FF accounts tagged with this category — throw with actionable
        // message instead of silent-passing.
        $parts[] = "{$cat} (no FF account tagged with this category — operator must add + tag one before push)";
        continue;
    }
    // ... normal-path message with mapped-vs-unmapped breakdown
}
```

**Why silent-pass is dangerous**: a category with zero tagged FF accounts is an UNCONFIGURED state, not a SATISFIED one. Silent-pass tells the operator "you're ready" when in fact they're not — the push will fail later (often at runtime in an unrelated code path, with a confusing QBO API error). Throw-at-validator surfaces the gap at the right layer, with the right remediation.

**Specific case**: S-QBO-VALIDATOR-SCOPE-SPLIT's `undeposited_funds` category. FF v1 chart has no account fitting QBO's Undeposited Funds concept (QBO has it as a built-in; FF doesn't replicate). `assertReadyForPaymentPush()` requires `['ar_clearing', 'undeposited_funds']`. With no FF UF account, the gate correctly throws — the operator's pre-payment-push checklist surfaces the gap. AskUserQuestion at session pre-flight resolved this between three options (throw / silent-skip / defer-method-stub); operator chose throw per [[feedback_trust_file_over_prompt]].

**Detected**: S-QBO-VALIDATOR-SCOPE-SPLIT decision point (resolved via AskUserQuestion 2). Locked 2026-05-24 via D-PHASE-QBO-4-DOCS-LOCK. Applies to every future per-session validator gate: empty-category state must throw, never pass.

---

### Trap 69: MySQL ALTER COLUMN — COMMENT must precede AFTER positional clause

**Wrong**: `ALTER TABLE t ADD COLUMN col ENUM(...) NOT NULL DEFAULT 'X' AFTER other_col COMMENT 'docs'` — MySQL parse error 1064 at the `COMMENT` keyword. The column definition (NOT NULL / DEFAULT / COMMENT / CHARACTER SET / COLLATE) and the position specifier (AFTER / FIRST) are syntactically distinct: column_definition comes first, position specifier comes last.

**Right**:

```sql
✅ Right (column_definition ends with COMMENT; AFTER is the trailing position specifier)
ALTER TABLE `vendors`
  ADD COLUMN `currency` ENUM('CAD','USD')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'CAD'
    COMMENT 'Vendor billing currency (mirrors customers.currency)'
    AFTER `notes`;

❌ Wrong (AFTER appears mid-definition; MySQL errors at COMMENT)
ALTER TABLE `vendors`
  ADD COLUMN `currency` ENUM('CAD','USD')
    NOT NULL DEFAULT 'CAD'
    AFTER `notes`
    COMMENT 'Vendor billing currency';
-- ERROR 1064 (42000): syntax error near 'COMMENT'
```

**Why it's a trap**: the MODIFY COLUMN syntax (used for ENUM extensions in past migrations like S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE) doesn't have a position specifier, so the column_definition can end with COMMENT without ambiguity. Authors copy-pasting from MODIFY to ADD COLUMN naturally trail COMMENT after the last definition clause and forget AFTER must come last. The error message points at COMMENT not the misplaced AFTER, which obscures the cause.

**Recovery**: MySQL DDL auto-commits but **parse errors do not leave partial state** — the column is not partially added. Verify via `SHOW COLUMNS FROM t LIKE 'col'` returning 0 rows post-failure, then fix the SQL ordering and re-run.

**Detected**: S-VENDOR-CURRENCY-COLUMN 2026-05-27 — initial migration attempt failed at parse time; verified no partial state via SHOW COLUMNS; reordered COMMENT before AFTER; second attempt applied successfully (67→68/0/0).

**Applies to**: every future migration adding a column with both COMMENT and positional placement. MODIFY COLUMN is unaffected (no positional clause possible).

---

## 12. PERMISSION MATRIX (quick reference)

```
V=view C=create E=edit D=delete S=settings  —=no access

                 super_admin  manager  dispatcher  accountant  read_only
customers        VCEDS        VCED     VC          V           V
equipment        VCEDS        VCED     VCE         V           V
leases           VCEDS        VCED     VCE         V           V
reservations     VCEDS        VCED     VCED        V           V
invoices         VCEDS        VCED     V           VCED        V
payments         VCEDS        VCED     —           VCED        V
rates            VCEDS        VCE      —           V           V
maintenance      VCEDS        VCED     VCE         V           V
compliance       VCEDS        VCED     VE          V           V
reports          VCEDS        VCE      —           VCE         V
analytics        VCEDS        VCE      —           V           V
users            VCEDS        V        —           —           —
settings         VCEDS        V        —           —           —
audit            VCEDS        V        —           V           V
```

**Dispatcher note:** Can VIEW invoices (status/dates) but NOT see financial amounts. The API must strip dollar fields when `can('payments', 'view')` is false.

---

## 13. BILLING ENGINE QUICK REFERENCE

### Day counting: inclusive = (end - start) + 1
### Pro-rate formula decides method by day count:
- 0 days → $0
- 1–5 days → days × daily_rate
- 6–7 days → weekly_rate
- 8–29 days → min(weekly_math, monthly_rate) [capped]
- 30+ days → monthly_math

### Invoice 1 = on lease ACTIVATION (not creation)
### Full months = flat monthly_rate (not formula)
### Final invoice: if return = last day of month AND draft exists → append mileage lines, don't void
### Mileage rate = 0 → skip mileage lines entirely
### Adjustment = 0 → no mileage line on final invoice

---

## 13.4 MILEAGE BILLING — Model B is current (S-MILEAGE-2B SHIPPED 2026-05-12)

(Established 2026-05-04 as Model C transitional via **S-MILEAGE-MODEL-AUDIT** + **S-MILEAGE-FIX-0**. Replaced wholesale by Model B: **S-MILEAGE-1** (precharge schema, 2026-05-04), **S-MILEAGE-2A** (Invoice 1 precharge emit + activation balance init, 2026-05-12), **S-MILEAGE-2B** (Invoice 2+ drawdown emit + Model C plumbing retirement, 2026-05-12), **S-MILEAGE-3** (close + cash/credit refund picker + priorExcessKm safeguard retirement + lease_close_adjustments DROP + I9 invariant, 2026-05-13), **S-MILEAGE-3-FIX-0** (refund dispatch ordering K-21 fix + T1 9/9 PASS, 2026-05-13), **S-MILEAGE-5** (20-scenario hermetic lifecycle smoke + I10 invariant, 2026-05-13). `FLEETFORGE_ACCOUNTING_SPEC.md` updates deferred to **S-MILEAGE-3-ACCT-SPEC** follow-up session per D-I (A) — CPA-blocked on 5 enumerated questions.)

**S-MILEAGE-5 hermetic test coverage (SHIPPED 2026-05-13):** [tests/_smoke_model_b_lifecycle.php](tests/_smoke_model_b_lifecycle.php) covers the full Model B engine surface in 20 scenarios across all three D135 config-matrix paths — activation balance init, Invoice 1 mileage_precharge emit, send-time stamp, drawdown math at three balance/charge boundary shapes, multi-invoice exhaustion + bypass-after-zero, three close-refund cases per K-21 (residual=0 skip; residual>0 credit branch D170; residual>0 cash intent-only D169), mark-settled happy path + state-machine 409s + 422 validators via inline-predicate mirrors of close.php + mark_refund_settled.php, $0.01 bcmath precision edge, Model B Lite + Disabled mileage, K-18 Samsara fallback discipline, FIX_RESET fixture-mode warning capture, and an end-to-end I10 cross-check (S20) validating the K-21 post-drawdown identity. Each scenario hermetic via BEGIN/ROLLBACK; synthetic equipment_units inserted inline when samsara_vehicle_id state matters. I10 invariant (`tests/_smoke_billing_invariants.php`) closes K-21's "future-I10 candidate" forward-pointer with credit + cash branch coverage; refund amount must equal preserved post-drawdown precharge_balance (D182).

**The shipped pipeline implements Model B** (lease-level precharge_balance with drawdown on each subsequent invoice; see §13.4.1 for schema). Historical Model C narrative below preserved for audit trail of pre-2B behavior.

### What runs today (Model B post-S-MILEAGE-2B)

| Stage | Code path | What it does |
|-------|-----------|--------------|
| Lease activation | [api/v1/leases/activate.php](api/v1/leases/activate.php) | Captures `odometer_start_km` via Samsara Path B AND initializes `precharge_balance = precharge_amount` when `precharge_enabled = 1` (D137 / S-MILEAGE-2A C2). |
| Invoice 1 generation | [InvoiceGenerator::createFromLease](lib/Billing/InvoiceGenerator.php:98) | Emits `mileage_precharge` line at the flat operator-set `lease.precharge_amount` when the 3-clause gate fires (D138: lifecycle + (b) cross-invoice uniqueness + billing_type exclusion). |
| Invoice 1 send | [api/v1/invoices/send.php](api/v1/invoices/send.php) | Stamps `precharge_invoiced_at = NOW()` (D140); activates D113 PRECHARGE_LOCKED 409 on the lease. |
| Invoice 2..N generation | [InvoiceGenerator::createFromLease](lib/Billing/InvoiceGenerator.php:98) drawdown emit block | Emits `mileage_usage` (per-km usage) + optional `mileage_drawdown_credit` per the drawdown math (D148; POSITIVE amount + is_credit=1 K-16 convention per D166). UPDATES `precharge_balance -= drawdown_amount` in same transaction; audit_log entity_type=`lease_precharge_balance_drawdown`. Samsara fallback via [SamsaraClient::getDistanceForPeriod](lib/GPS/SamsaraClient.php:1245) when caller doesn't pre-populate distance AND lease's equipment_unit has samsara_vehicle_id. |
| Lease close | [api/v1/leases/close.php](api/v1/leases/close.php) | **S-MILEAGE-3 SHIPPED 2026-05-13**: cash/credit refund dispatch when `precharge_enabled=1 AND precharge_balance > 0`. Credit branch (D-C/D170) creates `credit_notes` row with `source='precharge_refund'` + stamps `precharge_refund_settled_at=NOW()` at close-commit. Cash branch (D-B(i)/D169) stamps method='cash' with settled_at=NULL — deferred-settle via [api/v1/leases/mark_refund_settled.php](api/v1/leases/mark_refund_settled.php) when operator confirms physical disbursement. State machine 409 PRECHARGE_REFUND_LOCKED (D-D/D171). priorExcessKm transitional safeguard + Model C `lease_close_adjustments` table retired in C5 migration `202605121925`. |
| Mark cash refund settled | [api/v1/leases/mark_refund_settled.php](api/v1/leases/mark_refund_settled.php) | **NEW S-MILEAGE-3 endpoint**: stamps `precharge_refund_settled_at=NOW()` when operator confirms cash refund physically disbursed. FOR UPDATE on lease (D20); validates status='completed' + method='cash' + settled_at IS NULL (idempotent via 409 PRECHARGE_REFUND_ALREADY_SETTLED). audit_log entity_type='lease_precharge_refund_settled' (D-L/D179). |

### Model C pipeline RETIRED in S-MILEAGE-2B C4-C5 (2026-05-12)

Historical narrative for audit trail (Model C ran 2026-05-04 → 2026-05-12):

| ~~Stage~~ | ~~Code path~~ | What it did (retired) |
|---|---|---|
| Monthly invoice excess gate | ~~InvoiceGenerator excess block at lines 438-486~~ | Computed `excess_distance_km` per period vs `monthly_allowance_km`. Set `mileage_review_status='pending'` when excess > 0. **DELETED in C3 commit a24cb49.** |
| Manager review endpoint | ~~api/v1/invoices/review_mileage.php (316 lines)~~ | Manager approves/overrides/rejects. Approve added `mileage_adjustment` line item. HARD send gate in send.php:57-63 — no role bypass. **DELETED in C5 commit 6ed9529.** |
| Lease close mileage adjustment | ~~api/v1/leases/close.php `lease_close_adjustments`~~ | Optional close_adjustment block. **RETIRED S-MILEAGE-3 C5 (2026-05-13)**: lease_close_adjustments table DROPPED in migration `202605121925_S-MILEAGE-3_close_adjustments_drop.sql`; backup table `lease_close_adjustments_backup_S_MILEAGE_3` captured 0 rows per K-15. close.php close_adjustment processing block (~250 LOC) + priorExcessKm safeguard from S-MILEAGE-FIX-0 (D98) retired in same commit. |

Backup of Model C invoice column values preserved in `invoices_model_c_backup_S_MILEAGE_2B` table (D107 capture-all snapshot of all 45 invoice rows pre-DROP; forensic-only).

### Q9 transitional safeguard (S-MILEAGE-FIX-0, 2026-05-04)

**Pre-fix bug:** close-time excess calc did NOT subtract kilometres already billed via per-period excess. Canonical 3mo / 3000km / 1100-900-1300 example double-billed $150 of the M3 overage.

**Fix:** at the top of the close transaction in [api/v1/leases/close.php](api/v1/leases/close.php), compute

```sql
SELECT COALESCE(SUM(excess_distance_km), 0) AS prior_excess
FROM invoices
WHERE lease_id = ? AND deleted_at IS NULL AND status != 'void'
```

and subtract from rawOverageKm in BOTH:
- the legacy partial-end overage block at lines 794-819 (with km→lease-unit conversion via `km_to_miles_conversion` for miles leases per D100)
- the S-LEASE-MILEAGE close_adjustment block at lines 976+ (per D98)

The cron-generated full_month draft for the closing month is in the sum automatically since it's a non-void invoice on the lease — D101 subsumed.

**Inverse case (D99):** when prior monthly excess > raw lease overage, customer was over-billed during the lease. Close-time charge auto-clamps to 0; UI renders a yellow warning banner via the `closeReconciliation` Alpine getter in [app/admin/leases/show.php](app/admin/leases/show.php). Manager handles via manual credit_note — NO auto-correction.

**D-E regression safeguard:** every close with `priorExcessKm > 0 AND decision != 'waived'` writes an `audit_log` row (`entity_type='lease_close_with_prior_excess'`, action='update'); WARNING-level Sentry for inverse case. Operators have a paper trail of every close that touched the seam.

### What replaced this in S-MILEAGE-1 → S-MILEAGE-2B (now SHIPPED)

**Model B (drawdown balance + close-refund) — SHIPPED 2026-05-13:** Invoice 1 carries a `mileage_precharge` line for the **user-set** `leases.precharge_amount` (NOT derived from `estimated_mileage_km × rate` — operator picks the upfront commitment, not the system). `leases.precharge_balance` is the running drawdown — initialized = `precharge_amount` at activation (D137), decremented per invoice via the drawdown emit (D148). Once balance hits zero, monthly invoices bill mileage straight at per-km rate via the `mileage_usage` line only (no `mileage_drawdown_credit` emitted). At close (S-MILEAGE-3 SHIPPED 2026-05-13), balance > 0 → refund residue per `precharge_refund_method` ('credit' → credit_notes row with source='precharge_refund' + settled_at at close-commit per D-C/D170; 'cash' → method='cash' with settled_at=NULL deferred to operator-confirmed disbursement via mark_refund_settled.php endpoint per D-B(i)/D169). State machine immutable post-close (D-D/D171 → 409 PRECHARGE_REFUND_LOCKED). `precharge_balance` retains historical at-close value post-refund (NOT zeroed per D182 — preserves audit trail; I9 invariant keys on this).

Retired in S-MILEAGE-2B C4-C5 (2026-05-12):
- ✓ The `excess_distance_km` / `excess_charge_amount` / `mileage_review_status` / `mileage_override_amount` / `mileage_reviewed_at` / `mileage_reviewed_by_user_id` / `mileage_review_notes` columns on invoices — DROPPED in migration `202605120907_S-MILEAGE-2B_model_c_retirement.sql`
- ✓ `Mileage::periodExcess` helper — DELETED (D154 + D167; zero callers post-C3)
- ✓ The HARD send gate in send.php:57-63 — DELETED (D155)
- ✓ `api/v1/invoices/review_mileage.php` endpoint (316 lines) — DELETED (D155)
- ✓ Mileage Review card in app/admin/invoices/show.php (~157 lines) — DELETED + replaced by Drawdown Reconciliation panel (D158)

Retired in S-MILEAGE-3 C5 (2026-05-13):
- ✓ The `lease_close_adjustments` table — DROPPED in migration `202605121925_S-MILEAGE-3_close_adjustments_drop.sql` (D174); backup table `lease_close_adjustments_backup_S_MILEAGE_3` captured 0 rows per K-15
- ✓ The `priorExcessKm` transitional safeguard in close.php (D98) — entire ~340 LOC retirement: SELECT, both subtraction blocks, audit_log workaround paths, response payload (D173/D-F)
- ✓ closeReconciliation Alpine getter + Mileage Reconciliation panel in app/admin/leases/show.php (~240 LOC) — DELETED (D-F + D-G)
- ✓ Latent SUM(excess_distance_km) query in api/v1/leases/show.php (would have failed silently post-S-MILEAGE-2B C4 DROP) — caught + cleaned per K-15 READ-coverage extension

Pending future sessions:
- `Mileage::monthlyAllowance` helper — ✓ DELETED 2026-05-13 via S-PORTAL-MILEAGE-MODEL-B (D154 + D167 final retirement; tombstone comment at lib/Billing/Mileage.php replaces the method body; zero callers post-deletion confirmed via repo-wide grep)
- FLEETFORGE_ACCOUNTING_SPEC.md updates — DEFERRED to S-MILEAGE-3-ACCT-SPEC follow-up per D-I (A) / D176 (CPA-blocked on 5 enumerated questions)

### Mileage line-item types — schema enum is the source of truth

```
'mileage_precharge'        → Invoice 1 upfront precharge (active; S-MILEAGE-2A D139)
'mileage_usage'            → per-km usage charge on Invoice 2..N (active; S-MILEAGE-2B D148)
'mileage_drawdown_credit'  → precharge balance applied as credit (active; S-MILEAGE-2B D148;
                              POSITIVE amount + is_credit=1 per K-16 convention D166)
'mileage_adjustment'       → CLOSED CATEGORY — historical Model C per-period review approve flow
                              (preserved for audit trail on INV-91 + INV-92; no new emissions
                              post-S-MILEAGE-2B C5 endpoint retirement)
'mileage_credit'           → CLOSED CATEGORY — historical Model C close-time underage refund
                              (zero production rows at 2B ship; lease_close_adjustments table
                              retires in S-MILEAGE-3)
```

Anything else (`mileage_charge`, `mileage_overage`, etc.) is NOT in `invoice_line_items.item_type` and will silently fall through to `default => 'badge-neutral'` in match arms. See D104. When adding a new mileage line-item type, update the schema enum AND the badge match in `app/admin/invoices/show.php` AND the `$mileageItemTypes` array at show.php:284 AND the `$isMileage` detection at show.php:1857.

### See also

- `/tmp/fleetforge_mileage_model_audit.md` — full audit (Q1–Q10 with file:line evidence)
- D81–D86 (S-LEASE-MILEAGE Model C lock-in)
- D98–D105 (S-MILEAGE-FIX-0 transitional safeguards)
- D106–D115 (S-MILEAGE-1 Model B precharge schema lock-in — see §13.4.1)
- KNOWN ISSUE #99 — Q9 fixed status
- KNOWN ISSUE #100 — `lease_billing_periods` precharge column cleanup (deferred)

---

## 13.4.1 MODEL B PRECHARGE — Lease-Level Schema (S-MILEAGE-1)

(Established **S-MILEAGE-1**, 2026-05-04. Phase 1 of three. Activation logic is **S-MILEAGE-2**, close/refund is **S-MILEAGE-3**.)

This section documents the schema landed by S-MILEAGE-1 and the lifecycle ownership of each new column. **No invoice-generation, monthly-cron, or close logic touches these columns yet** — all writers/readers in this session are at lease create/edit/show only.

### The 6 new columns on `leases` (D106 / D-A)

| Column | Type | Lifecycle owner | Notes |
|--------|------|-----------------|-------|
| `precharge_enabled` | `TINYINT(1) NOT NULL DEFAULT 0` | User (lease create/edit) | Off by default. Existing leases stay opted out — no backfill. |
| `precharge_amount` | `DECIMAL(12,2) NULL` | User (lease create/edit) | Required (>0) when enabled. NULL when disabled. **User-set, not derived.** |
| `precharge_balance` | `DECIMAL(12,2) NULL` | ✓ S-MILEAGE-2A SHIPPED 2026-05-12 (activation) + ✓ S-MILEAGE-2B SHIPPED 2026-05-12 (drawdown) | Initialized = `precharge_amount` on lease activation (`api/v1/leases/activate.php` per D137). Decremented per invoice on Invoice 2..N generation via the drawdown emit (`InvoiceGenerator.php` per D148). NULL until activation. |
| `precharge_invoiced_at` | `DATETIME NULL` | ✓ S-MILEAGE-2A SHIPPED 2026-05-12 (Invoice 1 send) | Stamps when the precharge line was billed on Invoice 1 (`api/v1/invoices/send.php` per D140). **Lock signal:** non-NULL freezes `precharge_enabled` + `precharge_amount` (D113 PRECHARGE_LOCKED 409 in `update.php`) AND prevents future invoice generation from emitting another `mileage_precharge` line (D138 lifecycle gate). |
| `precharge_refund_method` | `ENUM('cash','credit') NULL` | ✓ S-MILEAGE-3 SHIPPED 2026-05-13 (lease close) | Operator picks at close when `precharge_balance > 0` (D-A/D168 picker; default 'credit'). State machine immutable post-set per D-D/D171 → 409 PRECHARGE_REFUND_LOCKED. |
| `precharge_refund_settled_at` | `DATETIME NULL` | ✓ S-MILEAGE-3 SHIPPED 2026-05-13 (D-E/D172) | Stamps at close-commit for credit branch (credit issuance IS settlement); stamps later for cash branch via `api/v1/leases/mark_refund_settled.php` (D-B(i)/D169 deferred-settle) when operator confirms physical disbursement. NULL on cash branch = intent only. |

### CHECK constraint (D109 / D-D)

```sql
CONSTRAINT chk_leases_precharge_amount CHECK (
    (precharge_enabled = 0)
    OR
    (precharge_amount IS NOT NULL AND precharge_amount > 0)
)
```

Enforced at the DB layer (MySQL 8.0). App-level validation in `api/v1/leases/create.php` + `update.php` mirrors the rule with the user-friendly message *"Precharge amount is required when precharge is enabled."* Belt-and-suspenders: CHECK protects against direct-SQL writes; app validation provides the form-error UX.

### Immutability after billing (D113 / D-H)

Once `precharge_invoiced_at IS NOT NULL`, neither `precharge_enabled` nor `precharge_amount` may change. `api/v1/leases/update.php` returns `409 PRECHARGE_LOCKED` with explicit guidance:

```json
{
  "success": false,
  "error": {
    "code": "PRECHARGE_LOCKED",
    "message": "Cannot change precharge settings after Invoice 1 has billed the precharge. Void/recreate the lease to restructure."
  }
}
```

The PRECHARGE_LOCKED 409 is a SEPARATE gate from the optimistic-lock STALE_DATA 409 (D19) — distinct error codes for client differentiation. STALE_DATA → "reload and re-edit"; PRECHARGE_LOCKED → "void/recreate to restructure". Both can fire on the same request.

### API field whitelist

`api/v1/leases/create.php` + `update.php` accept ONLY `precharge_enabled` (0/1) and `precharge_amount` (decimal > 0) from clients. The other 4 columns are lifecycle-managed server-side:

- `precharge_balance` → set by S-MILEAGE-2 activation, mutated by S-MILEAGE-2 invoice generation
- `precharge_invoiced_at` → set by S-MILEAGE-2 when Invoice 1 sends
- `precharge_refund_method`, `precharge_refund_settled_at` → set by S-MILEAGE-3 close/refund

A client that PATCHes one of the lifecycle columns gets a silent ignore (the field isn't in `$allowedFields`).

### Backup of dropped Model A columns (D107 / D-B)

Migration `db_migrations/202605040316_S-MILEAGE-1_precharge_schema.sql` dropped `leases.mileage_precharge_amount` + `leases.mileage_precharge_invoiced` after snapshotting all 34 rows into:

```sql
CREATE TABLE leases_precharge_backup_S_MILEAGE_1 (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id                    INT UNSIGNED NOT NULL,
    mileage_precharge_amount    DECIMAL(12,2) NULL,
    mileage_precharge_invoiced  TINYINT(1) NULL,
    snapshot_taken_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lease (lease_id)
);
```

Forensic-only — only consulted if it turns out an unreviewed seed/fixture writer populated these rows in production. The audit said no such writer exists; backup is cheap insurance.

### What S-MILEAGE-2 must do

1. ✓ **Activation** (`api/v1/leases/activate.php`): when `precharge_enabled=1`, set `precharge_balance = precharge_amount` inside the activation transaction. **Shipped S-MILEAGE-2A C2 (commit 253b294) — D137.**
2. ✓ **Invoice 1 generation** (`InvoiceGenerator::createFromLease`): when `precharge_enabled=1` AND this is the activation invoice (no prior invoices for the lease), add a `mileage_precharge` line item for the full `precharge_amount`. **Shipped S-MILEAGE-2A C3 (commit e1918df) — D138 + D139.** The "no prior invoices" condition tightened to a 3-clause gate: lifecycle `precharge_invoiced_at IS NULL` + (b) cross-invoice uniqueness (NOT EXISTS prior non-void `mileage_precharge` line on this lease) + billing_type exclusion (regular invoice types only). The (b) clause prevents duplicate emission across advance-batches.
3. ✓ **Invoice 1 send** (`api/v1/invoices/send.php`): stamp `precharge_invoiced_at = NOW()` on the lease when sending Invoice 1. **Shipped S-MILEAGE-2A C4 (commit c8e459a) — D140.** Plus the D-D clarification: PRECHARGE_ALREADY_BILLED 409 fires when sending a DIFFERENT invoice that carries a duplicate `mileage_precharge` line after the stamp already landed (defense-in-depth backstop with C3's emission gate).
4. ✓ **Subsequent invoice generation** (`InvoiceGenerator::createFromLease`): replaced the S-LEASE-MILEAGE per-period excess block (lines 438-486) with the balance-drawdown block per D148. Each invoice computes `period_charge = period_distance × mileage_rate`. If `precharge_balance > 0`: emits `mileage_usage` + `mileage_drawdown_credit` lines (POSITIVE amount + is_credit=1 K-16 convention per D166); decrements `precharge_balance` by `min(period_charge, precharge_balance)`. If `precharge_balance == 0`: emits just the `mileage_usage` line. **Shipped S-MILEAGE-2B C3 (commit a24cb49) — D148.** Samsara fallback via `getDistanceForPeriod` per D149 (silent-bug fix on samsara_vehicle_id JOIN landed in C3.5 commit 64b37cb — see K-18).
5. ✓ **Retire Model C plumbing** (completed across S-MILEAGE-2B + S-MILEAGE-3):
   - ✓ Removed 7 Model C columns from invoices (excess_distance_km, excess_charge_amount, mileage_review_status, mileage_override_amount, mileage_reviewed_at, mileage_reviewed_by_user_id, mileage_review_notes) + residual idx_mileage_review index (S-MILEAGE-2B C4 D153 — migration `202605120907`)
   - ✓ Retired `Mileage::periodExcess` helper (S-MILEAGE-2B C4 D154 + D167); `Mileage::monthlyAllowance` ✓ DELETED 2026-05-13 via S-PORTAL-MILEAGE-MODEL-B (D154 + D167 final retirement; tombstone at lib/Billing/Mileage.php; last caller app/portal/leases/view.php:80 removed in same session as the Model C allowance card → Model B precharge/usage card flip)
   - ✓ Retired HARD send gate in send.php:57-63 (S-MILEAGE-2B C5 D155)
   - ✓ Retired `api/v1/invoices/review_mileage.php` endpoint (S-MILEAGE-2B C5 D155)
   - ✓ Retired show.php Mileage Review card + Alpine modal state (S-MILEAGE-2B C5/C6 D158 — replaced by Drawdown Reconciliation panel)
   - ✓ Retired priorExcessKm transitional safeguard at close.php (D98) — ~340 LOC deletion across SELECT, subtraction blocks, audit_log workaround paths, response payload field (S-MILEAGE-3 C5 D173/D-F)
   - ✓ Retired `lease_close_adjustments` table — DROPPED in migration `202605121925`; backup table `lease_close_adjustments_backup_S_MILEAGE_3` captured 0 rows (S-MILEAGE-3 C5 D174/D-G)
   - ✓ Retired closeReconciliation Alpine getter + Mileage Reconciliation panel in app/admin/leases/show.php (~240 LOC; S-MILEAGE-3 C5)
   - ✓ Wrote `precharge_refund_method` + `precharge_refund_settled_at` columns at close per state machine (S-MILEAGE-3 C3 D-D/D171)
   - ✓ `Mileage::monthlyAllowance` final deletion + customer portal app/portal/leases/view.php Model C → Model B refactor (S-PORTAL-MILEAGE-MODEL-B SHIPPED 2026-05-13; D154 + D167 discharged).
   - **Pending future sessions:** FLEETFORGE_ACCOUNTING_SPEC.md updates (S-MILEAGE-3-ACCT-SPEC owns; CPA-blocked).

### S-MILEAGE-3 — SHIPPED 2026-05-13 (D-A through D-N)

Implemented the Model B close-refund lifecycle. Surface summary:

1. **Close path** (`api/v1/leases/close.php`): when `precharge_enabled=1 AND precharge_balance > 0`, dispatches a refund per the operator-selected method received in the `precharge_refund` request body block. Cash branch (D169) stamps method='cash' with settled_at=NULL; credit branch (D170) inserts a credit_notes row with source='precharge_refund' + stamps settled_at=NOW() at close-commit. State machine 409 PRECHARGE_REFUND_LOCKED + required-block 422 PRECHARGE_REFUND_REQUIRED.
2. **Mark cash refund settled** (`api/v1/leases/mark_refund_settled.php`, NEW endpoint): stamps `precharge_refund_settled_at=NOW()` for cash-branch leases when operator confirms physical disbursement. FOR UPDATE on lease (D20); 422 INVALID_LEASE_STATUS / METHOD_MISMATCH; 409 ALREADY_SETTLED (idempotent).
3. **Close UI** (close modal at app/admin/leases/show.php): Precharge Refund picker with cash/credit radio + default 'credit' + manager-notes textarea; "Mark Refund Settled" btn-warning on lease show page precharge row (conditional render).
4. **Smoke I9 invariant** (D181): closed leases with residual balance must have non-NULL method + (for credit) non-NULL settled_at. Cash-branch NULL settled_at NOT a violation by design (D-B(i) defer-settle).
5. **Retirements**: priorExcessKm safeguard + lease_close_adjustments table + closeReconciliation Alpine getter all retired in C5 (D-F + D-G).

Pending: T1 visual walk per D-M (8-step walk; K-19 persistent dev user) — ✓ COMPLETED in S-MILEAGE-3-FIX-0 T1 (9/9 PASS, 2026-05-13). S-MILEAGE-3-ACCT-SPEC follow-up for accounting JE spec text (CPA-blocked).

**Hermetic test coverage closed in S-MILEAGE-5 (2026-05-13):** [tests/_smoke_model_b_lifecycle.php](tests/_smoke_model_b_lifecycle.php) 20-scenario lifecycle smoke (S01–S20) plus I10 invariant added to [tests/_smoke_billing_invariants.php](tests/_smoke_billing_invariants.php) (credit + cash branch refund amount identity vs preserved precharge_balance per D182). K-21 "future-I10 candidate" forward-pointer resolved. Model B mileage refactor arc closed pending S-MILEAGE-3-ACCT-SPEC.
4. **Retire `lease_close_adjustments`** table — Model B has no close-time excess concept.

### Form UX (S-MILEAGE-1)

Toggle widget: native `<input type="checkbox" role="switch">` (NOT `ff-segment-control` — that's the binary unit picker pattern; a feature flag is semantically a switch). Located inside the existing "Mileage & allowance" card on both `app/admin/leases/create.php` and `edit.php`, after the conversion-factor collapsible and before the closing card div (D111 / D-F).

The conditional currency input is revealed via Alpine `x-show` (NOT `x-if`) so the bound value persists across toggle off→on within the same session. The amount field carries the `$` prefix using the existing `input-group-prefix` pattern.

On the edit form, the Alpine state has a `prechargeFrozen` flag set from the server-rendered `precharge_invoiced_at IS NOT NULL` check — when true, the toggle becomes `:disabled`, the amount becomes `:readonly`, and a "Locked — Invoice 1 has already billed this precharge" hint appears. This UX-only freeze mirrors the server-side 409 PRECHARGE_LOCKED guard.

### `estimated_mileage` in Model B

Per D110 / D-E: `leases.estimated_mileage` is **informational-only** under Model B (forecasting, customer reference). It does NOT drive billing math. Helper text on both lease forms reads: *"Estimated total mileage for the lease (informational; billing is per-km on actual usage)."* The DB column stays (rename = downstream churn for no benefit).

### See also

- `/tmp/fleetforge_mileage_model_audit.md` — full audit (Q1–Q10) — Avi's Model B intent is documented in §3 invoice-by-invoice trace
- §13.4 above — Model C transitional state (still load-bearing until S-MILEAGE-2/3 retire it)
- D106–D115 — full lock-in for S-MILEAGE-1 decisions
- KNOWN ISSUE #99 — Q9 priorExcessKm safeguard (delete when Model C retired)
- KNOWN ISSUE #100 — `lease_billing_periods` precharge cleanup (deferred to S-MILEAGE-1B)

---

## 13.5 MIGRATIONS — `bin/migrate.php` is the only sanctioned runner

(Established **S-MIGRATIONS-RUNNER**, 2026-05-04. Resolves S-COMPLETENESS-CHECK Finding B.)

### Filename convention

| Style | When | Example |
|-------|------|---------|
| `<SESSION-ID>_<desc>.sql` | The 5 historical files only — DO NOT create new files in this style | `S-PROD-2_ses_bounce_handler.sql` |
| `YYYYMMDDHHMM_<SESSION-ID>_<desc>.sql` | Every new migration. UTC timestamp. Sorts chronologically. | `202605041430_S-FIX-INVOICELINES_typo.sql` |

Filenames must match `^[A-Za-z0-9_\-]+\.sql$`. Anything with spaces, quotes, or shell metacharacters is rejected at scan time.

### CLI usage

```sh
php bin/migrate.php             # dry-run (default — mutates nothing)
php bin/migrate.php --apply     # apply pending migrations
php bin/migrate.php --verify    # recompute every stored checksum
php bin/migrate.php --status    # terse counts; always exit 0
php bin/migrate.php --backfill  # one-time bootstrap; refuses if non-empty
```

Exit codes: 0 ok / 1 error / 2 bad args / 3 lock contention / 4 checksum drift.

### What the runner does

1. Acquires `GET_LOCK('ff_migrations', 0)` (timeout 0 — fail fast, like our `ff_cron_*` pattern per D21).
2. Scans `db_migrations/` for `*.sql` files matching the safe regex.
3. SHA-256s each file; diffs against `schema_migrations` rows.
4. Reports drift on already-applied files whose current SHA-256 differs from stored. Refuses `--apply` until drift is resolved.
5. Applies pending files in filename-asc order by **shelling out to the `mysql` binary** via `proc_open` (NOT PDO multiquery — `S-LEASE-MILEAGE_schema.sql` uses `DELIMITER //` for stored procedures, which PDO does not understand).
6. Records each successful apply in `schema_migrations` (checksum + applied_by + execution_ms) and writes an `audit_log` row (`action='cron'`, `module='migrations'`).

### Drift policy (D92)

If a migration file is edited after being applied, SHA-256 will mismatch and the runner will refuse `--apply` with exit 4.

**Two recovery paths:**

- **Edit was a typo / accidental:** revert so SHA-256 matches stored.
- **Edit was intentional and the DB already reflects it:** manually `UPDATE schema_migrations SET checksum = '<new sha256>' WHERE filename = '<file>'`.

**Going forward, prefer creating a NEW migration over editing an applied one.**

### Failure handling (D93)

MySQL DDL auto-commits — partial failures cannot be rolled back inside a transaction.

- Runner stops at the failed file, prints `ERROR <code>` from stderr, does NOT record in `schema_migrations`, exits 1.
- Operator decides: roll-forward (fix the file, write idempotent guards, re-run) OR restore from `cron/backup_db.php` artifact.
- Migration files SHOULD be idempotent (`IF NOT EXISTS`, `INFORMATION_SCHEMA` guards) so re-running after a partial failure is safe.

### Backfill (one-time only — D97)

`--backfill` records the 5 named historical files (`Runner::HISTORICAL_FILES`) as already-applied with `applied_at = filemtime()` and `applied_by = 'backfill:<whoami>'`. Refuses to run if `schema_migrations` has any row. The 5 files are NOT re-executed — backfill is a record-only operation.

After this session, do not run `--backfill` again. If you ever need to re-bootstrap (lost dev DB), the safer path is to restore from a backup or run `--apply` against an empty DB and let the runner apply every file fresh.

### `schema_migrations` columns

| Column | Type | Notes |
|--------|------|-------|
| `version` | `VARCHAR(100) UNIQUE` | filename without `.sql` |
| `filename` | `VARCHAR(255)` | full filename |
| `checksum` | `CHAR(64) NULL` | SHA-256 hex |
| `applied_at` | `DATETIME` | `NOW()` for `--apply`; file mtime for `--backfill` |
| `applied_by` | `VARCHAR(100)` | `<whoami>` for `--apply`; `backfill:<whoami>` for `--backfill` |
| `execution_ms` | `INT UNSIGNED NULL` | wall-clock ms; 0 for `--backfill` |

### See also

- `db_migrations/README.md` — full runbook (filename rules, runner usage, failure handling)
- `lib/Migrations/Runner.php` — implementation
- D88-D97 in `FLEETFORGE_PROGRESS.md` — locked decisions

---

## 13.6 SAMSARA — Historical Distance Queries (S-MILEAGE-1B)

(Established **S-MILEAGE-1B**, 2026-05-04. Phase 1 of the Samsara
billing-grade integration. S-MILEAGE-2 wires this method into
`InvoiceGenerator::createFromLease`.)

### When to use which method

| Need | Method | Reason |
|------|--------|--------|
| Distance traveled by unit X between date A and date B (billing) | `SamsaraClient::getDistanceForPeriod` (NEW) | Wider-window bookend strategy avoids "no readings" failures from off-by-an-hour cron timing; structured failure shape with reason codes; bcmath precision; warning conditions surface "successful-but-suspect" data to the user. |
| Latest odometer reading right now (lease close auto-populate) | `SamsaraClient::getOdometerReading`, `getVehicleOdometer` | Single-shot live snapshot. Returns `int` (km) or `0.0` on failure. |
| Live GPS location for the tracking map | `SamsaraClient::getVehicleStats`, `getTrailerStats`, `getEntityStats` | Returns full normalized stats object with GPS + odometer + battery. |
| Bulk fleet snapshot for the 5-min sync cron | `SamsaraClient::getVehicleLocations`, `getAllTrailerStats` | Paginated bulk endpoints. |
| Manual lease-close mileage estimate (legacy, predates S-MILEAGE-1B) | `SamsaraClient::getMileageForLease` | Snapshot-delta approach with no wider-window fallback; drifts on cron timing. **Use `getDistanceForPeriod` instead going forward.** |

### Method signature

```php
public function getDistanceForPeriod(
    string             $samsaraVehicleId,
    \DateTimeImmutable $startUtc,    // any TZ; normalized to UTC internally
    \DateTimeImmutable $endUtc,      // any TZ; normalized to UTC internally
    string             $unit = 'km'  // 'km' or 'miles'
): array
```

### Success return shape

```php
[
    'distance'         => '1234.56',                    // bcmath string, never float
    'unit'             => 'km',                          // echo of input
    'source'           => 'obd' | 'gps',                 // type used (D117)
    'first_reading_at' => '2026-04-01T07:00:00.000Z',    // ISO 8601 UTC, bookendLow
    'last_reading_at'  => '2026-04-30T18:42:00.000Z',    // ISO 8601 UTC, bookendHigh
    'reading_count'    => 47,                            // total in-window samples
    'warnings'         => [],                            // see below
    'queried_at'       => '2026-05-01T12:00:00Z',        // when the call was made
]
```

### Failure return shape

```php
[
    'distance'   => null,
    'unit'       => 'km',
    'source'     => 'unavailable',
    'reason'     => 'no_readings_in_period' | 'unit_not_in_samsara' | 'api_error' | 'gateway_offline' | 'period_too_long',
    'detail'     => 'human-readable explanation, includes period if relevant',
    'queried_at' => '2026-05-01T12:00:00Z',
]
```

**Callers MUST switch on `$result['distance'] === null` to detect failure** — the method never throws for an operational failure.

### Wider-window bookend strategy (D-A)

Queries Samsara over `[startUtc - 24h, endUtc + 24h]`. From the resulting reading set:
- `bookendLow` = the LAST reading at or before `startUtc`
- `bookendHigh` = the FIRST reading at or after `endUtc`
- If no bracketing reading exists for a side, falls back to the EARLIEST or LATEST reading inside the wider window.
- `distance = bookendHigh - bookendLow` (clamped to 0 with `gateway_reset_detected` warning if negative).

Only fails with `reason='no_readings_in_period'` when the entire wider window has zero readings of the preferred type.

### Distance type preference (D-B)

Requests `obdOdometerMeters,gpsDistanceMeters` in one `types=` call. **`gpsOdometerMeters` is intentionally NOT requested** per the locked S-MILEAGE-1B decision. Preference order:

1. `obdOdometerMeters` present → use it (truck case, `source='obd'`)
2. else `gpsDistanceMeters` present → use it (trailer case, `source='gps'`)
3. else fail with `no_readings_in_period`

### Advisory warnings (D-F)

Populated in `warnings[]` alongside a successful distance — the method does NOT fail on these. Caller decides whether to surface to the user (S-MILEAGE-2 invoice form will show them as a yellow info banner; S-MILEAGE-3 close UI will require manager confirmation when any are present).

| Warning | Trigger | Distance behavior |
|---------|---------|-------------------|
| `gateway_reset_detected` | last < first reading | clamped to 0 |
| `reading_outside_period` | bookend(s) pulled from the ±24h fallback window | unchanged |
| `sparse_readings` | <2 readings strictly inside `[startUtc, endUtc]` | unchanged |
| `large_gap_detected` | any consecutive in-period gap > 6 hours | unchanged |

### bcmath precision (D-C)

```php
// km:    meters / 1000
$distance = bcdiv($meters, '1000', 2);

// miles: meters / 1609.344
$distance = bcdiv($meters, '1609.344', 2);
```

The `distance` field is ALWAYS a string. Never `(float)$result['distance']` — pass it straight into `bcmul($distance, $rate, …)` for billing math. Float-leak artifacts (`1234.5600000000001`) surface in invoice subtotals as 1¢ reconciliation drift.

### Pagination + retry (D-E, D-H, D-I)

- Pagination: cursor-based (`pagination.endCursor`), hard cap at 50 iterations, `reason='api_error'` on cap exceeded.
- Retry: single retry on cURL error / HTTP 429 / 5xx with 2-second backoff. 429 honors `Retry-After` header capped at 30s. No retry on 4xx.
- Auth: constructor reads `samsara.api_token` (settings) → `gps.samsara_api_key` (legacy settings) → `SAMSARA_API_TOKEN` (env) → `GPS_SAMSARA_API_KEY` (legacy env). Operators can migrate to the new prefix at their own pace.

### Hermetic fixture mode (D-G)

For tests that must NOT hit live Samsara, flip the settings flag:

```sql
UPDATE settings SET value = '1' WHERE `key` = 'samsara.fixture_mode';
```

When set to `'1'`, `getDistanceForPeriod` dispatches to `\FleetForge\Samsara\FixtureProvider::getDistanceForPeriod()` instead of hitting Samsara. The fixture provider returns the EXACT same shape as production (including bcmath conversion path) — callers cannot tell the difference.

Production must NEVER silently run in fixture mode. The row defaults to `'0'`, is visible in the Settings UI, and the dispatch is gated on a strict string comparison. Smoke tests snapshot+restore the value automatically.

**6 canned scenarios** keyed by `samsaraVehicleId`:

| Vehicle ID  | Scenario | Distance | Source | Warnings |
|-------------|----------|----------|--------|----------|
| `FIX_STD`   | Standard 30-day, daily readings | 1234.56 km | gps | none |
| `FIX_PARKED`| Parked unit, same first/last reading | 0.00 km | gps | none |
| `FIX_NONE`  | No readings even in wider window | failure | unavailable | reason=no_readings_in_period |
| `FIX_RESET` | Gateway reset (last < first) | 0.00 km (clamped) | gps | gateway_reset_detected |
| `FIX_SPARSE`| 1 in-period reading; bookends from wider window | 500.00 km | gps | reading_outside_period, sparse_readings |
| `FIX_GAP`   | 7-day gap mid-period | 2300.00 km | gps | large_gap_detected |

Plus an implicit 7th case: any unknown vehicleId falls through to `reason='unit_not_in_samsara'` so adversarial / typo'd inputs degrade safely (a misspelled fixture name will fail loudly, not silently match the wrong scenario).

### Smoke test

```sh
php tests/_smoke_samsara_distance.php
# → 16/16 passed in 0.0s
# (or "14/16 passed (FAILED: T# name, ...) in X.Ys" — grep-able for CI)
```

16 stress tests across two groups: **T1-T13** Samsara distance + fixture-mode coverage from S-MILEAGE-1B (T13 added in S-MILEAGE-1B-FOLLOWUP for FIX_GAP `large_gap_detected` warning). Each T1-T13 PASS/FAIL line carries the actual `distance` field value as a string for float-leak inspection. T7 specifically asserts the bcmath miles return value has no trailing-nines pattern. **T14-T16** S-MILEAGE-2A surface tests added via ADD-not-REPLACE (preserves T8/T10/T12 placeholders as 2B carry-forward per D141): T14 = `precharge_invoice_emit` (BEGIN/ROLLBACK-isolated synthesize precharge lease + `InvoiceGenerator::createFromLease` + assert `mileage_precharge` line with locked D139 shape + per-line tax computed); T15 = `precharge_amount_check` (three malformed shapes via direct `db_insert` hit `chk_leases_precharge_amount` CHECK); T16 = `dispatch_path_fixture_vs_http` (source-inspection on `SamsaraClient.php` confirms strict `fixture_mode === '1'` dispatch gate + production HTTP loop).

T8 (period_too_long real exercise) and T10 (structured failure shape real exercise) updated in **S-MILEAGE-2B C7 (D162)** from source-inspection placeholders to real fixture-mode coverage. T8 supplies a 101-day range to `FixtureProvider::getDistanceForPeriod` and asserts `reason='period_too_long'` + detail mentions "90-day cap" (FixtureProvider honors the cap per `lib/Samsara/FixtureProvider.php:57-70`). T10 supplies an inverted range (end < start) and asserts `reason='api_error'` + structured failure shape with `detail` / `queried_at` / `source` keys present. Production HTTP pagination cap (maxPages=50 + cap-exceeded message) + malformed-JSON branches stay source-inspectable via T8-INSP / T10-INSP cross-checks (these branches require real HTTP and can't be exercised through fixtures). T12 (fixture flag dispatch) verifies via the `SAMSARA_HISTORY_FIXTURE` log line that fixture-mode dispatch executes when configured.

### See also

- `lib/GPS/SamsaraClient.php::getDistanceForPeriod` — implementation
- `lib/Samsara/FixtureProvider.php` — hermetic test data
- `tests/_smoke_samsara_distance.php` — 13 stress tests
- `db_migrations/202605040430_S-MILEAGE-1B_samsara_fixture_setting.sql` — settings row
- D116–D125 in `FLEETFORGE_PROGRESS.md` — locked decisions
- S-MILEAGE-1B KEY LEARNINGS — fixture-mode design notes + caveats

---

## 13.7 SCHEMA DOCUMENTATION DISCIPLINE — `FLEETFORGE_DATABASE_MASTER.sql` parity

(Established **S-DATABASE-MASTER-RECONCILE** 2026-05-03 / D87. Hardened **S-DATABASE-MASTER-RECONCILE-2** 2026-05-04 after the S-MILEAGE-1 in-place-Edit drift.)

### The rule

Every Claude Code session that mutates the schema (CREATE/ALTER/DROP TABLE, ADD/DROP COLUMN, ADD/DROP CONSTRAINT, INDEX changes, etc.) MUST update `FLEETFORGE_DATABASE_MASTER.sql` in the same session. The master file is the canonical artifact loaded at AWS production cutover — drift between master and live = broken cutover.

### Post-session verification (UPDATED — grep alone is insufficient)

The original D87 rule said: post-session, run `grep -c <new_column_or_table_name> FLEETFORGE_DATABASE_MASTER.sql` and confirm ≥1 hit. **This is no longer sufficient on its own** — see "the in-place-Edit gotcha" below for why.

**New rule** (S-DATABASE-MASTER-RECONCILE-2):

```sh
php tests/_smoke_master_schema_parity.php
# → "PARITY OK — master matches live DB (0 lines of cosmetic noise filtered) in 4.3s"
# (or "PARITY FAIL — N substantive drift lines (see /tmp/...)")
```

Exit 0 = master matches live. Exit 1 = drift detected, smoke test prints the substantive diff lines and the path to the full diff file. Run this at the end of every schema-touching session AND at the start of any session that's about to touch the schema (catch-up before adding new drift).

The smoke test is hermetic vs the dev DB only (no live Samsara, no S3, no external services). It does:
1. `mysqldump` live DB schema (no data) with the agreed flags.
2. Drop+create scratch DB `fleetforge_master_validate_2`, load the master, dump it the same way.
3. Normalize away cosmetic noise (`AUTO_INCREMENT` counters, redundant `CHARACTER SET utf8mb4` re-emission, dump headers).
4. Diff. Substantive lines (non-noise +/- lines) → drift detected.

Runs in ~5 seconds. Use `--print-full-diff` to see the entire normalized diff (not just substantive lines). Use `--keep-scratch-db` to inspect `fleetforge_master_validate_2` after the test.

### The in-place-Edit gotcha (S-MILEAGE-1 drift root cause)

When ADDING new columns to a table via `Edit` on `FLEETFORGE_DATABASE_MASTER.sql`, **append the new columns at the end of the column list** (just before the `PRIMARY KEY` / index/constraint declarations). Do NOT slot them into the position of a dropped column you're replacing — that's where the in-place str_replace pattern naturally lands you, but it doesn't match what `ALTER TABLE … ADD COLUMN` does in the live DB.

**Example of the wrong pattern (S-MILEAGE-1 Commit 1)**:

```php
// Edit replaced the dropped Model A columns with the new precharge columns
// at the SAME position (lines 2182-2187). But ALTER TABLE ADD COLUMN
// (without BEFORE/AFTER) appends to the end → live DB had them at
// positions 78-83, master had them at 38-43. The grep verification
// passed (columns existed), but ordinal positions diverged.
Edit(
    old: "  `mileage_precharge_amount` decimal...,\n  `mileage_precharge_invoiced` tinyint...,",
    new: "  `precharge_enabled` ...,\n  `precharge_amount` ...,\n  ... 4 more"
);
```

**Correct pattern**: two separate edits — one to remove the dropped columns at their original position, a second to append the new columns at the end of the column list (just before `PRIMARY KEY`):

```php
// Edit 1 — remove dropped columns
Edit(
    old: "  `mileage_precharge_amount` decimal...,\n  `mileage_precharge_invoiced` tinyint...,\n  `tax_exempt` ...",
    new: "  `tax_exempt` ..."
);

// Edit 2 — append new columns at end of column list
Edit(
    old: "  `total_distance_km` decimal(10,2) DEFAULT NULL ...,\n  PRIMARY KEY (`id`),",
    new: "  `total_distance_km` decimal(10,2) DEFAULT NULL ...,\n  `precharge_enabled` ...,\n  ... 5 more,\n  PRIMARY KEY (`id`),"
);
```

This matches the ordinal positions that `ALTER TABLE ADD COLUMN` produces in the live DB. The parity smoke test catches the wrong pattern; the grep doesn't.

### Escalation procedure

Drift detected by the smoke test:

- **Single-table column-position drift** (e.g. one session's columns slotted in the wrong position) — fix with two surgical Edits as in the example above. Do NOT regenerate the entire master file. Re-run the smoke test to confirm exit 0. Single commit, ship.
- **Multiple-table drift OR missing columns / constraints** — likely a session shipped without updating the master at all. Run a full regen (`S-DATABASE-MASTER-RECONCILE-N` pattern from the original 2026-05-03 reconcile commit `a54ad7f`). Backup the old master to `.bak` first.
- **Mojibake in COMMENT clauses after a re-dump** — load command needs `--default-character-set=utf8mb4`. The smoke test uses this flag automatically; ad-hoc loads should match.

### See also

- `tests/_smoke_master_schema_parity.php` — the parity check
- D87 in `FLEETFORGE_PROGRESS.md` — original same-session-update rule
- D126–D127 in `FLEETFORGE_PROGRESS.md` — locked discipline updates (S-DATABASE-MASTER-RECONCILE-2)
- D131 — extension: parity + invariants + doc freshness + qbo client + qbo queue + qbo admin UI + oauth state + qbo customer mapping + qbo customer push + qbo vendor mapping + qbo vendor push + settings roundtrip + settings form keys + settings endpoints smokes all run pre-commit (S-BILLING-RATE-FIX 2026-05-06 added the parity + invariants pair; S-DOC-FRESHNESS-DISCIPLINE 2026-05-13 added `tests/_smoke_doc_freshness.php`; **S-DOC-FRESHNESS-EXPAND 2026-05-27 extended the smoke from 17 to 24 sub-checks** with CLASS 5/6/7/8/9 cross-validating canonical-doc completeness — recent SESSION LOG ↔ CURRENT_SESSIONS presence, recent QBO-relevant sessions ↔ QUICKBOOKS_PROGRESS presence, D-* attribution links advisory, QUICKBOOKS_PROGRESS "Next session up" freshness, K-22 Trap #50+ attribution; D131 smoke-file count unchanged at 22; D-DOC-FRESHNESS-EXPAND-1 locks the rule set + cutoffs; **S-QBO-18 2026-05-27 added `tests/_smoke_qbo_bill_push.php`** — 20 sub-checks for the FF→QBO bill push flow (acc_qbo_bill_map schema shape + BillPusher/BillEnqueuer class surfaces with RESULT_BASE const, buildQboPayload happy-path with multi_currency='1' emitting VendorRef + DocNumber + CurrencyRef + ExchangeRate + AccountBasedExpenseLineDetail per line + TxnTaxDetail.TotalTax + PrivateNote JSON, multi_currency='0' CurrencyRef omitted, DocNumber fallback to bill_number when vendor_bill_number empty per D-QBO-18-7, throws on missing vendor mapping / missing per-line account mapping / no lines, pushImpl all 6 outcome paths — skipped_by_mode + skipped_unmapped_void + skipped_voided + draft-failed_preflight + already_mapped + ff_not_found, pushUpdate stub returns unsupported_in_session pointing to S-QBO-19, BillEnqueuer gate-0 rejects missing/draft bill + gate-3 rejects 'update' op + happy-path queue insert; self-cleaning via id=999990+ sentinels for bills+vendors+accounts+mappings + settings snapshot/restore for sync_enabled/sync_mode.bill/multi_currency_enabled/tax_override_code_id/connection_status). **D131 gate bumped 22→23** for the new qbo_bill_push smoke file; S-QBO-2 2026-05-20 added `tests/_smoke_qbo_client.php`; S-QBO-3 2026-05-20 added `tests/_smoke_qbo_queue.php`; S-QBO-4 2026-05-20 added `tests/_smoke_qbo_admin_ui.php` for the QBO admin UI surface — 4 pages + 9 API endpoints lint + permission gates + nav structure + empty/synthetic reachability + CSRF/POST verification + dashboard_metrics JSON shape, self-cleaning; S-QBO-OAUTH-FIX 2026-05-20 added `tests/_smoke_oauth_state.php` — 9 sub-checks covering acc_oauth_states table shape + StateManager class surface + generate→verifyAndConsume round-trip + single-use + expiry + provider-mismatch + tamper + cleanup + callback.php auth-context-free structural assertion, self-cleaning; S-QBO-5 2026-05-20 added `tests/_smoke_qbo_customer_mapping.php` — 12 sub-checks covering acc_qbo_customer_map shape + CustomerPuller/CustomerMatcher surfaces + normalizeName collapses corporate suffixes + findBestMatch cascade (exact/Levenshtein/null) + UNIQUE NULL semantics + 4 endpoint linting + customers.php page lint + nav has 7 children incl. Customers (count bumped to 7 in S-QBO-7) + normalize() against representative QBO JSON, self-cleaning via sentinel mapping ids; S-QBO-6 2026-05-21 added `tests/_smoke_qbo_customer_push.php` — 10 sub-checks covering CustomerPusher + CustomerEnqueuer class surfaces + buildQboPayload full/minimal cases + pushCreate sync-mode gate + soft-delete skip + idempotency on already-mapped + Enqueuer master kill / mode kill / happy-path queue insert, self-cleaning via id=999990+ sentinels + settings save+restore; S-QBO-7 2026-05-21 added `tests/_smoke_qbo_vendor_mapping.php` — 12 sub-checks mirroring the customer mapping smoke for vendors (acc_qbo_vendor_map shape with 21 cols + qbo_balance ABSENT + qbo_given_name/qbo_family_name/qbo_v4v_status PRESENT, VendorPuller/VendorMatcher surfaces, vendor-flavored matcher uses vendors.name not company_name) + `tests/_smoke_qbo_vendor_push.php` — 10 sub-checks mirroring the customer push smoke for vendors (VendorPusher + VendorEnqueuer surfaces, buildQboPayload with contact_name split per D-QBO-7-3 + Country='CA' default + no postal_code, single-name contact → GivenName only, gating + idempotency + queue happy-path with entity_type='vendor', self-cleaning via id=999990+ sentinels + vendor_type='other' on synthetic inserts to satisfy NOT NULL ENUM); S-QBO-8 2026-05-21 added `tests/_smoke_qbo_account_mapping.php` — 17 sub-checks for the chart of accounts mapping flow (acc_qbo_account_map shape with 22 cols + is_critical/critical_reason + idx_critical lookup index, AccountPuller/AccountMatcher/AccountValidator class surfaces, normalizeAccountName collapses casing+punctuation variants, exact_code wins over name match, type-compatibility gating prevents cross-type matches per D-QBO-8-3, ChartOfAccountsIncompleteException extends QuickBooksException, markCriticalAccounts flags live AR account, unmappedCritical returns the 7 live bridge accounts identified in pre-flight (codes 1030/1050/1060/2010/2030/2040/4122), assertReadyForInvoicePush throws+passes correctly, 4 endpoint linting, accounts.php Alpine factory + view gate, nav has 8 children incl. Accounts; defensive self-cleaning via 'TEST-SMOKE-A-*' qbo_account_id prefix + post-test markCriticalAccounts re-run — later bumped to 19 sub-checks in S-QBO-8 follow-up F1 fix adding C18 link-preserves-is_critical + C19 unlink-moves-flag); S-QBO-9 2026-05-21 added `tests/_smoke_qbo_tax_code_mapping.php` — 14 sub-checks for the tax code mapping flow (acc_qbo_tax_code_map shape with 22 cols + is_override_target NULLABLE + uq_ff_tax_rate/uq_qbo_tax_code/uq_override_target indexes verified, TaxCodePuller/TaxCodeMatcher class surfaces, identifyOverrideTarget('NON') case-insensitive + whitespace-tolerant per D-QBO-9-2, exact_name cascade case-insensitive, normalizeName collapses GST/HST variants, **uq_override_target UNIQUE-on-NULLABLE behavior verified** — multiple NULLs allowed + single 1 enforced + second-'1' UPDATE rejected with duplicate-key error confirming the D-QBO-9-2 design works as intended, set_override_target transactional clear-then-set flips flag correctly without UNIQUE violation, 4 endpoint linting, tax_codes.php Alpine factory + view gate, nav has 9 children incl. Tax Codes; defensive self-cleaning via 'TEST-SMOKE-T-*' qbo_tax_code_id prefix + override-target snapshot/restore + settings.quickbooks.tax_override_code_id snapshot/restore so D-CPA-5 invariant holds across runs); S-QBO-10 2026-05-21 added `tests/_smoke_qbo_item_mapping.php` — 18 sub-checks for the item / product mapping flow (acc_qbo_item_map shape with 24 cols + uq_ff_item_type_variant multi-column UNIQUE + uq_qbo_item + idx_status/idx_ff_item_type/idx_last_synced indexes verified, ItemPuller/ItemMatcher/ItemCreator class surfaces, **ffItemTypes() introspects INFORMATION_SCHEMA — not hardcoded — and yields 18 (item_type, variant) tuples for the 17 ENUM values + 2 GPS variants** per D-QBO-10-2, displayNameFor mapping correctness for representative tuples incl. GPS net/gross variants and Rental Reconciliation Credit per D-QBO-10-1, findBestMatch exact_name cascade + null-when-no-signal, **uq_ff_item_type_variant accepts (gps,NULL)+(gps,'net')+(gps,'gross') coexistence + duplicate-variant rejection** confirming multi-column UNIQUE works for variant routing, matchAll emits mapped + qbo_only decisions correctly, ItemPuller::normalize HTTP-free (pure normalization), **resolveIncomeAccount happy-path returns mapped revenue + throws ChartOfAccountsIncompleteException when no revenue mapped** with snapshot/restore around the test mutation, 5 endpoint linting (pull+auto_match+save_mapping+create_qbo_item+list), items.php Alpine factory + view gate, nav has 10 children incl. Items between Tax Codes and Settings, ItemPuller::normalize against representative QBO Item JSON, UI_CATEGORIES covers all 17 ENUM values exactly once; self-cleaning via 'X-SMOKE-GPS' sentinel ff_item_type + post-test deletion + revenue-mapping backup/restore loop to leave acc_qbo_account_map untouched after run); S-QBO-11 2026-05-24 added `tests/_smoke_qbo_invoice_push.php` — 29 sub-checks for the FF→QBO invoice push flow (acc_qbo_invoice_map shape with 18 cols + 5 indexes + FK to invoices ON DELETE CASCADE, all 5 lib class surfaces (InvoicePusher / InvoiceEnqueuer / InvoiceLineBuilder / InvoiceTaxOverride / InvoicePreflightGate), php -l clean on all 5 files, InvoiceTaxOverride bcmath sum + throw on empty + line-level structure, InvoiceLineBuilder emits Lines in sort_order + resolves QBO Item + GPS net/gross variant per customer.gps_revenue_presentation + throws on unmapped item_type + throws on period_independent invoice with recon_credit line (D-QBO-11-5 integrity guard — compiles but never triggers until invoices.engine_version column ships), InvoicePreflightGate 4 reason variants (validator failure / customer-unmapped / tax_override missing / connection disconnected), InvoicePusher skipped_by_mode + skipped_voided + failed_preflight (mapping table updated) + idempotency already_mapped on existing qbo_invoice_id, InvoiceEnqueuer sync_enabled=0 → false + 'update' op → false (S-QBO-12 deferred), PrivateNote JSON includes ff_invoice_id+engine_version+ff_tax_breakdown+audit + omits audit when columns NULL + engine_version='unknown' fallback, buildQboPayload CAD omits CurrencyRef/ExchangeRate + USD with exchange_rate_to_cad=1.35 emits both; self-cleaning via id=999990+ sentinels for invoices+customers+mappings + settings snapshot/restore for connection_status/sync_enabled/sync_mode.invoice/tax_override_code_id); S-QBO-11-POSTVERIFY-FIXES 2026-05-25 added `tests/_smoke_settings_roundtrip.php` — 95 sub-checks for settings persistence round-trip (save+restore pattern across all settings groups including QBO / notifications / billing / cron / security / AI / currency; boolean unchecked semantics; secret masking — placeholder never overwrites real secret, empty string never overwrites, real new secret does overwrite; currency form validation — markup 0-20% accepted, outside rejected; snapshot restored at end), `tests/_smoke_settings_form_keys.php` — 24 sub-checks for per-form-keys selective-save semantics (sidecar field preserved when sibling group saves — ai.briefing_recipient_roles NOT clobbered by Budget Alert save; AI Core form with _form_keys preserves sidecar fields across 3 different sibling groups; backward-compat path: no _form_keys = full-group save fires correctly for notifications.smtp_host), `tests/_smoke_settings_endpoints.php` — 22 sub-checks for settings-adjacent API endpoints (system tab: cron run endpoint + cron toggle; intelligence tab: ai/test-connection, intelligence/briefing_history, intelligence/token_analytics, intelligence/ai_request_log, intelligence/briefing_audit_log, intelligence/brief_content, intelligence/test_briefing, intelligence/set_user_preferences, intelligence/set_opt_in, intelligence/set_snooze; email template list; all verified http=200 with correct response shape); **D131 gate bumped 19→22** (settings_roundtrip 95/95 + settings_form_keys 24/24 + settings_endpoints 22/22 all PASS)
- KNOWN ISSUE #100 — `lease_billing_periods` precharge cleanup (still open; next discipline target)
- Original Phase 2 reconcile: commit `a54ad7f` for the full-regen procedure

---

## 13.8 BILLING RATE-TIER COMPLETENESS — D132 invariant

Origin: 2026-05-06 audit of INV-2026-00086 traced a $0 `base_rental` line on a 27-day partial period to a 4-step chain — `equipment_templates.default_weekly_rate=NULL` → JS form `?? ''` collapse → submit handler omits empty strings → API `clean_decimal(null)` ternary coerces to `'0.00'`. ProRateCalculator's 8–29 day weekly path then computed `(full_weeks × 0) + (remaining × 0/7) = 0` and the "exceeds monthly?" cap evaluated `0 > monthly` as false (since monthly > 0), returning $0 with method='weekly'. Five draft invoices were affected; none had been sent.

### The invariant (D132)

When `billing_cycle='monthly'` and any one of (`daily_rate`, `weekly_rate`, `monthly_rate`) is `> 0`, **all three** must be `> 0`. The form, the API, and the billing engine all enforce this; the smoke test catches latent violations.

### Three-layer defence

```
        ┌────────────────────────┐
        │  app/admin/leases/     │  Layer 1 (form)
        │  create.php            │  - Derive weekly = monthly/4.33 when null pre-fill
        │                        │  - Submit always sends daily/weekly/monthly ('0' for blank)
        └───────────┬────────────┘
                    │ POST
        ┌───────────▼────────────┐
        │  api/v1/leases/        │  Layer 2 (API — primary defence)
        │  create.php            │  - require array_key_exists for the 3 keys
        │                        │  - zero-with-siblings → 422 with field-level error
        └───────────┬────────────┘
                    │ db_insert
        ┌───────────▼────────────┐
        │  leases table          │
        └───────────┬────────────┘
                    │ generate invoice (cron, manual)
        ┌───────────▼────────────────────────────────────┐
        │  lib/Billing/InvoiceGenerator::generate()       │  Layer 3a (engine seatbelt)
        │   ├─► full_month shortcut (line ~148)          │   Throws BillingRateException
        │   │     direct monthly_rate; defensive check   │   if rentalAmount <= 0 unless
        │   │     before line item insert                │   billing_type in
        │   │                                            │   {mileage_only, adjustment,
        │   └─► ProRateCalculator::calculate()           │    credit_note}
        │         assertNonZero() at every branch:        │
        │         - daily   (1-5 days)                    │
        │         - weekly  (6-7 days)                    │
        │         - weekly  (8-29 days)                   │
        │         - weekly_capped (8-29 days, math>monthly)│
        │         - monthly (30+ days)                    │
        │         the only legitimate $0 is method='none' │
        │         when days <= 0                          │
        └───────────┬────────────────────────────────────┘
                    │ runs on every D131 gate
        ┌───────────▼────────────┐
        │  tests/_smoke_         │  Layer 4 (post-hoc invariant)
        │  billing_invariants.php│  - I1: no unjustified zero-base draft
        │  (D131 gate)           │  - I2: no lease rate-tier hole
        │                        │  - I3: no template rate-tier hole
        └────────────────────────┘
```

Each layer covers what the others miss. The 2026-05-06 audit found the bug specifically because all four were absent — a fix at any one layer would have prevented it.

### Code anchors

- **Form derivation:** [app/admin/leases/create.php](app/admin/leases/create.php) inside `_lookupRates()` — the `parseFloat(monthly_rate)/4.33` pre-fill.
- **Form submit coercion:** same file, the rate-fields `'0'` send-instead-of-omit block in `submit()`.
- **API validation:** [api/v1/leases/create.php](api/v1/leases/create.php) — the rate-tier-completeness block right after the existing "at least one rate must be positive" check.
- **Engine throw — full_month bypass:** [lib/Billing/InvoiceGenerator.php](lib/Billing/InvoiceGenerator.php) — the `$zeroAllowed` exemption + throw, just before the `base_rental` line item insert.
- **Engine throw — ProRate paths:** [lib/Billing/ProRateCalculator.php](lib/Billing/ProRateCalculator.php) `assertNonZero()` private method called from every non-trivial `return` site.
- **Exception class:** [lib/Billing/BillingRateException.php](lib/Billing/BillingRateException.php) — `FleetForge\Billing\BillingRateException` extends `\RuntimeException`.
- **Smoke invariants:** [tests/_smoke_billing_invariants.php](tests/_smoke_billing_invariants.php).
- **Stress coverage:** [tests/_stress_billing_rate_exception.php](tests/_stress_billing_rate_exception.php) — 11/11 cases (5 throw, 5 success, 1 legit zero).
- **Backfill / void script:** [scripts/billing_rate_fix_2026_05_06.php](scripts/billing_rate_fix_2026_05_06.php) — one-shot remediation with --dry-run/--execute, idempotent.

### Adding a new caller that writes lease rates

If a future session adds an endpoint or cron that writes `daily_rate` / `weekly_rate` / `monthly_rate` to the `leases` table:

1. **Use `api/v1/leases/create.php` if at all possible** — its validation already enforces D132 and audit_log captures the write.
2. **If a new write site is unavoidable**, mirror the rate-tier-completeness check before the INSERT/UPDATE. Layer 3 (the engine throws) is a backstop, not a substitute — bad data sitting in the table indefinitely defeats the purpose.
3. **Run `tests/_smoke_billing_invariants.php` after the change** — exit 0 confirms no new latent violation slipped through.
4. **Cancel/soft-delete any test artifacts** with a structured `cancel_reason` / `void_reason` that names the session, so a future audit can distinguish test pollution from real data.

### Adding a new billing path that calls ProRateCalculator

Default behaviour: any `days > 0` call that would produce $0 throws `BillingRateException`. Catch it explicitly in your caller, log to error log + Sentry as ERROR with lease + period context, and refuse to write the invoice. Do NOT silently fall back to a different rate tier — that would mask the upstream defect. The current callers (`InvoiceGenerator::generate()`, cron paths) treat the exception as a hard fail; new callers must do the same.

### Mileage tier extension (D133, S-MILEAGE-RATE-VALIDATION)

D132 (extended via S-MILEAGE-RATE-VALIDATION): rate-tier completeness now covers the mileage tier in addition to daily/weekly/monthly rental tiers. A lease with `estimated_mileage_km > 0` OR `precharge_enabled = 1` OR `period_distance_km > 0` on any related invoice MUST have `mileage_rate_km > 0` (and `mileage_rate_miles` consistent if `mileage_unit = miles`). Smoke test (`tests/_smoke_billing_invariants.php`) catches violations as I4 (lease-side) + I5 (invoice-side).

The same three-layer defence applies, parallel to D132:

- **Layer 2 (API validator):** [api/v1/leases/create.php](api/v1/leases/create.php) — D-A block right after the precharge parsing. Trigger fires on any intent signal (any of the three estimated_mileage columns > 0 OR `precharge_enabled = 1`); required-positive on any of the three rate columns. HTTP 422 with field-level error keyed to `mileage_rate_km`.
- **Layer 3a (engine HARD throw):** [lib/Billing/InvoiceGenerator.php](lib/Billing/InvoiceGenerator.php) per-period excess block — throws `BillingRateException` with `method='mileage_excess'` when `estimated_mileage_km > 0` and `mileage_rate_km = 0`. Mirrors the full_month base_rental throw structure.
- **Layer 3b (engine SOFT WARNING):** same block — when `period_distance_km > 0` but allowance AND rate are both 0 (no rate tier configured), emits `Sentry::captureMessage(..., 'warning')` + an `audit_log` row with `[FLEETFORGE_BILLING_WARNING]` prefix. Sentry call wrapped in `try/catch` — observability MUST NOT block billing (mirrors SamsaraClient pattern at [lib/GPS/SamsaraClient.php:1583-1592](lib/GPS/SamsaraClient.php:1583)). Soft signal because "no rate tier" can be legitimate operator intent.
- **Layer 4 (smoke invariant):** I4 + I5 in [tests/_smoke_billing_invariants.php](tests/_smoke_billing_invariants.php), run on every D131 gate.
- **Form-side (Layer 1 coercion):** [app/admin/leases/create.php](app/admin/leases/create.php) `rateFields` array extended from 3 to 6 entries — `mileage_rate` + `mileage_rate_km` + `mileage_rate_miles` join the existing daily/weekly/monthly. Blank inputs become `'0'` before POST so the API can apply D133 instead of seeing a missing key.

S-MILEAGE-RATE-ZERO-FIX (data side) backfilled the live state to a clean baseline in commit bc4db87; D133 prevents regression at the lease creation and invoice generation layers from this point forward.

### Three valid configurations (D135, S-MILEAGE-ALLOWANCE-ZERO-FIX, 2026-05-07 — clarifies D132/D133)

The S-MILEAGE-ALLOWANCE-ZERO-FIX engine fix at [lib/Billing/InvoiceGenerator.php](lib/Billing/InvoiceGenerator.php) restructured `$mileageBillingExpected` so it keys on `mileage_rate_km > 0` (operator's per-km billing intent) rather than `estimated_mileage_km > 0`. The lease tier now admits three valid configurations:

| Config | Shape | Engine behavior |
|---|---|---|
| **Model C** | `mileage_rate_km > 0` AND `estimated_mileage_km > 0` | Bill only the excess over the period allowance (existing behavior; e.g. allowance=2000 km/mo, distance=3000 → excess=1000 km × rate). |
| **Model B Lite** | `mileage_rate_km > 0` AND `estimated_mileage_km = 0` | Bill every km from km 0 (allowance=0 interpreted as "no included km"; `Mileage::periodExcess(distance, 0, rate)` → `excess=distance`, `charge=distance × rate`). |
| **Disabled** | `mileage_rate_km = 0` AND `estimated_mileage_km = 0` AND `precharge_enabled = 0` | No mileage billing on this lease. D-C SOFT WARNING fires if `period_distance_km > 0` is recorded against this shape (Sentry + audit_log) but billing continues. |

Pre-fix bug class: leases in the Model B Lite shape silent-skipped because the old gate required `estimated_mileage_km > 0` to enter the calc block. INV-2026-00090 / lease 52 (MTTS-GJEMC7-2026, distance=507.04 km, rate=$0.18, allowance=0) was the operator-flagged case — engine produced `excess_charge_amount=$0` and `mileage_review_status='not_required'` despite recorded distance. KNOWN ISSUE #103 in PROGRESS.md tracks the trace.

INVALID configurations (rejected by the I4/I5/I6 invariants):

- **I4** — `(estimated_mileage_km > 0 OR precharge_enabled = 1)` AND `mileage_rate_km = 0` (intent signal without rate; rejected at lease creation by D133 API validator + Layer 3a HARD throw at the engine).
- **I5** — any draft/sent invoice with `period_distance_km > 0` against a `mileage_rate_km = 0` lease (positive distance against zero-rate lease).
- **I6** (added in S-MILEAGE-ALLOWANCE-ZERO-FIX C2) — any draft/sent invoice on a Model B Lite lease with `period_distance_km > 0`, `mileage_review_status = 'not_required'`, and no mileage line item. Catches the silent-skip bug class regardless of how it was introduced (engine bug, manual SQL, fixture). Scope is deliberately narrow to the Model B Lite shape — Model C silent-skip would require excess vs allowance disambiguation that the engine itself is the source of truth for; I6 doesn't second-guess the engine on Model C.

Full Model B (precharge balance + drawdown) supersedes Model B Lite once S-MILEAGE-2A/2B/3 ships — Model B Lite is the transitional behavior that the engine fix delivered without the precharge schema work.

### See also

- D131 in `FLEETFORGE_PROGRESS.md` — smoke-gate-with-invariants discipline
- D132 in `FLEETFORGE_PROGRESS.md` — rate-tier validation discipline (rental tiers)
- D133 in `FLEETFORGE_PROGRESS.md` — rate-tier validation discipline (mileage tier extension)
- D135 in `FLEETFORGE_PROGRESS.md` — three-configuration matrix (Model C / Model B Lite / Disabled), promoted to §0 index in REFERENCE.md
- S-BILLING-RATE-FIX session entry in `FLEETFORGE_PROGRESS.md` — full trace of the 2026-05-06 audit + the four-layer fix
- S-MILEAGE-RATE-ZERO-FIX session entry — data-side backfill closing the historical zero-rate hole
- S-MILEAGE-RATE-VALIDATION session entry — code-side trio (engine + API + smoke) that locked D133
- S-MILEAGE-ALLOWANCE-ZERO-FIX session entry — engine guard fix (Model B Lite) + I6 invariant + INV-2026-00090 regen
- `THE LAW` in §13 above — the day-count branches the engine is now defending

---

## 14. SESSION CHECKLIST — Do this at the END of every session

1. Mark touched items ✅ or 🔄 in FLEETFORGE_PROGRESS.md
2. Add a row to SESSION LOG table
3. Log any decisions or deviations
4. Add any bugs to KNOWN ISSUES
5. Update NEXT SESSION STARTS WITH instruction
6. Run the stop condition tests from your session contract
7. Commit with message: `Session SXX — [one-line summary]`

---

*This file: ~500 lines. The spec: 3,841 lines. Read this first, every time.*
