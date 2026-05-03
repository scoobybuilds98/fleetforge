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
    'user_id'     => current_user_id(),   // null for cron/system
    'action'      => 'created',           // created|updated|deleted|status_changed|voided|...
    'module'      => 'leases',            // matches permission module name
    'entity_type' => 'lease',             // singular
    'entity_id'   => $leaseId,
    'description' => 'Lease CN-A3F9K2-2025 created for ABC Trucking',
    'old_values'  => json_encode($oldData),  // null for creates
    'new_values'  => json_encode($newData),  // null for deletes
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);
```

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
if ($existing['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'Record modified by another user. Refresh and try again.', 409);
}
```

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
