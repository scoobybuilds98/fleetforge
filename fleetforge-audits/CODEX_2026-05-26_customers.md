# FleetForge Audit — Customers

Date: 2026-05-26  
Auditor: Codex (read-only)

Scope audited:
- `app/admin/customers/index.php`
- `app/admin/customers/show.php`
- `app/admin/customers/create.php`
- `app/admin/customers/edit.php`
- `api/v1/customers/index.php`
- `api/v1/customers/show.php`
- `api/v1/customers/create.php`
- `api/v1/customers/update.php`
- `api/v1/customers/delete.php`
- `api/v1/customers/kpis.php`
- `api/v1/customers/reenable_email.php`
- `api/v1/customers/notes/index.php`
- `api/v1/customers/notes/create.php`
- `api/v1/customer_equipment_rates/index.php`
- `api/v1/customer_equipment_rates/upsert.php`
- `api/v1/customer_equipment_rates/delete.php`
- Cross-cutting: `config/permissions.php`, customer email history calls from the customer profile
- Tables touched: `customers`, `customer_tags`, `customer_contacts`, `customer_notes`, `customer_equipment_rates`, `customer_rate_history`, `leases`, `reservations`, `invoices`, `audit_log`, `email_logs`, `email_attachments`

## [CRITICAL] — Customer APIs expose financial fields to roles without payment access
File: api/v1/customers/index.php:108
Code:
```php
        c.currency,
        c.active_lease_count,
        c.lease_count,
        c.outstanding_balance,
        c.total_revenue,
        c.account_credit_balance,
        c.is_related_party,
```
Why it's a problem: `config/permissions.php` gives dispatchers `customers:view` while denying `payments:view`, and the same role block states dispatchers are operational users with no financial amounts. The customer list/detail APIs and profile page still expose outstanding balance, total revenue, account credit, credit limit, discounts, and late-fee terms to anyone with `customers:view`.
Proposed fix: Gate monetary/commercial fields behind `can('payments','view')` or a dedicated financial permission. Return operational customer fields for dispatchers, but redact balances, revenue, credits, credit limits, discounts, and late-fee data when the caller lacks financial access.

## [MEDIUM] — Optimistic locking is checked before update but not enforced atomically
File: api/v1/customers/update.php:65
Code:
```php
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This customer was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This customer was modified by another user. Refresh and try again.']]);
}
...
db_update('customers', $data, 'id = ?', [$id]);
```
Why it's a problem: Two requests can both read the same `updated_at`, both pass the check, and then the later `UPDATE id = ?` overwrites the earlier one. The comment claims D19 protects against lost updates, but the actual predicate does not include `updated_at` or lock the row.
Proposed fix: Put the lock token in the write predicate (`WHERE id = ? AND updated_at = ?`) and treat `rowCount() === 0` as stale, or perform the read with `FOR UPDATE` inside the same transaction. The same non-atomic pattern appears in `api/v1/customer_equipment_rates/upsert.php`.

## [MEDIUM] — Customer delete checks active work before transaction without locking
File: api/v1/customers/delete.php:54
Code:
```php
$activeLeasesActual = db_count(
    "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
    [$id]
);
...
db_transaction(function () use ($id, $userId, $deletedAt, $customer): void {
    db_update(
```
Why it's a problem: The endpoint checks active leases and reservations before opening the delete transaction, then soft-deletes the customer later. A concurrent lease activation or reservation creation can slip in between the guard query and the delete, leaving live operational records attached to a deleted customer.
Proposed fix: Move the guard reads into the same transaction and lock the customer row plus relevant lease/reservation rows, or use an atomic conditional update that only deletes when no active child records exist.

## [MEDIUM] — Notes endpoints include soft-deleted customers
File: api/v1/customers/notes/create.php:50
Code:
```php
// ── Verify customer exists ─────────────────────────────────────
$customerExists = db_exists('customers', 'id = ?', [$customerId]);
if (!$customerExists) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}
```
Why it's a problem: Other customer endpoints consistently require `deleted_at IS NULL`, but notes list/create only checks `id = ?`. Any staff user with customer note permissions can list or add notes for a soft-deleted customer if they know the ID, which breaks the module's soft-delete boundary.
Proposed fix: Change both notes endpoints to require `id = ? AND deleted_at IS NULL` before listing or inserting notes.

## [LOW] — Nullable customer fields cannot be cleared once set
File: api/v1/customers/update.php:321
Code:
```php
foreach ($optionals as $col => $val) {
    if ($val !== null) {
        $data[$col] = $val;
    }
}
```
Why it's a problem: Fields such as `email`, `billing_email`, `credit_limit`, exemption numbers, dates, and notes parse an explicit empty value to `null`, then get skipped. The API returns success but leaves the old value in place, so users cannot clear stale customer data and the failure is silent.
Proposed fix: Track `array_key_exists()` per field when building `$data`; include explicit `null` values for fields the request intentionally sent, while still omitting fields absent from the request.

## [LOW] — Email re-enable role check reads a non-existent session key
File: api/v1/customers/reenable_email.php:30
Code:
```php
// Only managers and super_admins may re-enable
$user = current_user();
if (!in_array($user['role'] ?? '', ['manager', 'super_admin'], true)) {
    json_error('FORBIDDEN', 'Only managers and super admins can re-enable email.', 403);
}
```
Why it's a problem: `auth_login()` stores the role as `role_slug`, not `role`, so this check denies valid managers and super admins even after `require_permission('customers','edit')` passes. The endpoint is effectively unusable for the roles it claims to allow.
Proposed fix: Check `$user['role_slug']` or use `require_role()`/`can()` consistently with the rest of the auth layer.

Summary: 1 CRITICAL / 3 MEDIUM / 2 LOW.
