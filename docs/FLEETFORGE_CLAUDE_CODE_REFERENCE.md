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
- D131 — extension: parity + invariants + doc freshness smokes all run pre-commit (S-BILLING-RATE-FIX 2026-05-06 added the parity + invariants pair; S-DOC-FRESHNESS-DISCIPLINE 2026-05-13 added the third smoke `tests/_smoke_doc_freshness.php` for canonical-doc existence + SESSION LOG cross-consistency + tool-call markup leak scan + IN-FLIGHT D136 discipline)
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
