# FleetForge Audit — Leases

Date: 2026-05-26
Auditor: Codex
Scope: `app/admin/leases/`, `api/v1/leases/`, and canonical lease schema. Production code only; skipped vendor/public/tests/db_migrations/docs. Customer portal leases and accounting lease register are intentionally deferred to their scheduled audit phases.
Tables checked: `leases`, `lease_status_log`, `lease_amendments`, `lease_billing_periods`, `equipment_units`, `equipment_status_log`, `invoices`, `invoice_line_items`, `credit_notes`, `settings`.

## [CRITICAL] — Concurrent lease close can generate duplicate finalization work
File: api/v1/leases/close.php:627
Code:
```php
$lease = db_row(
    "SELECT l.id, l.status, l.contract_number, l.company_name_snapshot, l.customer_id,
            l.equipment_unit_id, l.unit_number_snapshot, l.mileage_at_start,
            l.mileage_rate, l.mileage_unit, l.estimated_mileage,
            l.start_date, l.end_date, l.last_billed_date, l.odometer_start_km,
            l.estimated_mileage_km, l.estimated_mileage_miles,
            l.mileage_rate_km, l.mileage_rate_miles, l.km_to_miles_conversion,
```
Why it's a problem: The close flow reads and validates `leases.status` without `FOR UPDATE`, then locks only the equipment unit later. Two close requests can both read `active`; after the first commits, the second can continue using the stale active lease snapshot and run the completed-state update, unit release logs, advance reconciliation, and final invoice generation again. That creates a concrete double-billing / duplicate-credit blast radius on the central lease lifecycle.
Proposed fix: Lock the lease row with `FOR UPDATE` before checking status and before locking the unit. Re-check state under that lock, or enforce `UPDATE leases ... WHERE id = ? AND status = 'active'` with one affected row before any invoice/refund work.

## [MEDIUM] — Active lease cancellation checks the wrong role key
File: api/v1/leases/cancel.php:71
Code:
```php
if ($lease['status'] === 'active') {
    $user = current_user();
    $role = $user['role'] ?? '';
    if (!in_array($role, ['super_admin', 'manager'])) {
        json_error('FORBIDDEN', 'Cancelling an active lease requires Manager role.', 403);
    }
```
Why it's a problem: Auth sessions store the role slug as `role_slug`, not `role`, so this gate denies legitimate managers/super_admins in the normal session shape. That makes the documented active-cancel state transition effectively unreachable through this endpoint while other manager gates use `role_slug` correctly. Operators may be forced into unsafe workarounds such as closing/reopening or direct data edits.
Proposed fix: Read `current_user()['role_slug']` for this role gate, matching `reopen.php` and the auth session payload. Add a regression check for manager and non-manager active-cancel behavior.

## [MEDIUM] — Close accepts return dates before lease start
File: api/v1/leases/close.php:57
Code:
```php
$actualReturnDate = clean_date($body['actual_return_date'] ?? null) ?? date('Y-m-d');
$mileageAtEnd     = clean_int($body['mileage_at_end'] ?? null);
$closeNotes       = clean_string($body['close_notes'] ?? null, 5000);

// ── S-MILEAGE-3 D-K: precharge_refund block ────────────────────
```
Why it's a problem: The API accepts any valid calendar date as `actual_return_date` and never checks it against `leases.start_date`. A crafted or buggy client can close an active lease with a return date before the lease began, which later causes the final-billing branch to skip partial-end generation when `periodStart > actualReturnDate`. This is a date-boundary bug with direct billing impact.
Proposed fix: Inside the close transaction after loading the lease, reject `actual_return_date < start_date`; also consider rejecting future return dates unless explicitly supported. Mirror the constraint in the close modal with a `min` date, but keep the API as source of truth.

## [MEDIUM] — Lease optimistic lock is checked before the transaction but not enforced by the write
File: api/v1/leases/update.php:68
Code:
```php
$existing = db_row(
    "SELECT id, status, contract_number, company_name_snapshot, updated_at,
            precharge_invoiced_at
     FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$existing) {
```
Why it's a problem: `updated_at` is compared before the transaction, but the final write is `db_update('leases', $data, 'id = ?', [$id])`. A concurrent writer can update the lease after the optimistic-lock check and before the write, causing a stale request to overwrite newer metadata/add-on/date fields. The stale-token invariant is therefore advisory rather than enforced.
Proposed fix: Enforce the token in the write predicate (`WHERE id = ? AND updated_at = ?`) and require exactly one affected row, or select the lease `FOR UPDATE` inside the transaction and compare the token after the lock is acquired.

## [MEDIUM] — Retroactive odometer update can race a lease close
File: api/v1/leases/update_odometer.php:80
Code:
```php
$lease = db_row(
    "SELECT id, contract_number, status, odometer_start_km FROM leases
      WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}
```
Why it's a problem: The endpoint checks `status` outside the later transaction and updates the lease without locking or a status predicate. If a close request completes between this read and the update, the odometer endpoint can still mutate a completed lease even though its own rule says only active/pending leases are editable. This can corrupt the starting odometer after final mileage and invoices have already been calculated.
Proposed fix: Move the lease read into the transaction with `FOR UPDATE`, then re-check status immediately before updating. Alternatively, update with `WHERE id = ? AND status IN ('active','pending')` and fail if no row is affected.

## [LOW] — Invalid date updates silently clear lease date fields
File: api/v1/leases/update.php:140
Code:
```php
if (array_key_exists('end_date', $body))
    $data['end_date'] = clean_date($body['end_date']);

if (array_key_exists('minimum_end_date', $body))
    $data['minimum_end_date'] = clean_date($body['minimum_end_date']);
```
Why it's a problem: `clean_date()` returns `null` for malformed dates, and this code assigns that `null` without recording a validation error. A typo like `2026-99-99` clears `end_date` or `minimum_end_date` instead of rejecting the request. This is a silent failure mode on lease period boundaries.
Proposed fix: When a date key is present and non-empty, reject it if `clean_date()` returns `null`. Only treat an explicit empty value as a request to clear the date.

Summary: 1 CRITICAL / 4 MEDIUM / 1 LOW.
