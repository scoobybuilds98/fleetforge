# FleetForge Audit — Equipment + Templates

Date: 2026-05-26  
Auditor: Codex (read-only)

Scope audited:
- `app/admin/equipment/index.php`
- `app/admin/equipment/show.php`
- `app/admin/equipment/create.php`
- `app/admin/equipment/edit.php`
- `app/admin/equipment/templates/index.php`
- `app/admin/equipment/templates/create.php`
- `app/admin/equipment/templates/edit.php`
- `api/v1/equipment/units/index.php`
- `api/v1/equipment/units/show.php`
- `api/v1/equipment/units/create.php`
- `api/v1/equipment/units/update.php`
- `api/v1/equipment/units/update_status.php`
- `api/v1/equipment/units/delete.php`
- `api/v1/equipment/templates/index.php`
- `api/v1/equipment/templates/show.php`
- `api/v1/equipment/templates/create.php`
- `api/v1/equipment/templates/update.php`
- `api/v1/equipment/templates/delete.php`
- Cross-cutting: `config/permissions.php`, schema checks in `FLEETFORGE_DATABASE_MASTER.sql`
- Tables touched: `equipment_units`, `equipment_templates`, `equipment_status_log`, `audit_log`, `leases`, `reservations`, `yards`, `acc_fixed_assets`

## [CRITICAL] — Equipment APIs expose revenue/cost/rate fields to roles without financial or rates access
File: api/v1/equipment/templates/index.php:95
Code:
```php
        t.default_daily_rate,
        t.default_weekly_rate,
        t.default_monthly_rate,
        t.default_mileage_rate,
        t.default_currency,
        t.default_mileage_unit,
```
Why it's a problem: `config/permissions.php` gives dispatchers `equipment:view` but `rates => NONE` and `payments => NONE`, with an explicit note that dispatcher access should not include financial amounts. The equipment template APIs expose default rates, and the unit APIs expose `total_revenue`, `total_maintenance_cost`, and `acquisition_cost` to anyone with `equipment:view`.
Proposed fix: Redact rate, revenue, maintenance-cost, and acquisition-cost fields unless the caller has `rates:view`, `payments:view`, or an explicit fleet-finance permission. Keep operational equipment identity/status fields available to dispatchers.

## [MEDIUM] — Manual status changes can make leased units available without closing the lease
File: api/v1/equipment/units/update_status.php:58
Code:
```php
const UNIT_STATUS_TRANSITIONS = [
    'available'      => ['reserved', 'maintenance', 'inactive'],
    'reserved'       => ['available'],
    'on_lease'       => ['available', 'maintenance'],
```
Why it's a problem: The endpoint removed manual `available -> on_lease` because that creates lease inconsistency, but it still allows manual `on_lease -> available` or `on_lease -> maintenance` without checking for an active lease. That can leave a live lease attached to a unit that dispatch sees as available, creating double-booking and billing/operational drift.
Proposed fix: Disallow manual transitions out of `on_lease` while an active lease exists, or require the lease close/cancel workflow to perform the status change. If maintenance during an active lease is valid, record it through a lease-aware workflow that preserves the active lease relationship.

## [MEDIUM] — Optimistic locking is checked before update but not enforced atomically
File: api/v1/equipment/units/update.php:63
Code:
```php
if (!optimistic_lock_matches($updatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This unit was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This unit was modified by another user. Refresh and try again.']]);
}
...
db_update('equipment_units', $updates, 'id = ?', [$id]);
```
Why it's a problem: Two requests can read the same `updated_at`, both pass the stale check, and the later `UPDATE id = ?` silently overwrites the earlier update. This is the same lost-update pattern found in Customers and also appears in `api/v1/equipment/templates/update.php`.
Proposed fix: Include the lock token in the write predicate (`WHERE id = ? AND updated_at = ?`) and treat zero affected rows as stale, or perform the read with `FOR UPDATE` inside the same transaction.

## [MEDIUM] — Template creation writes NULL to a NOT NULL mileage-rate column
File: api/v1/equipment/templates/create.php:132
Code:
```php
$dailyRate    = $checkNonNegDecimal($body['default_daily_rate']   ?? null, 'default_daily_rate',   'Daily rate');
$weeklyRate   = $checkNonNegDecimal($body['default_weekly_rate']  ?? null, 'default_weekly_rate',  'Weekly rate');
$monthlyRate  = $checkNonNegDecimal($body['default_monthly_rate'] ?? null, 'default_monthly_rate', 'Monthly rate');
$mileageRate  = $checkNonNegDecimal($body['default_mileage_rate'] ?? null, 'default_mileage_rate', 'Mileage rate');
```
Why it's a problem: `default_mileage_rate` is `NOT NULL DEFAULT '0.0000'` in `FLEETFORGE_DATABASE_MASTER.sql`, but this API treats it as optional and explicitly inserts `NULL` when omitted or blank. Creating a template without a mileage rate can fail with a database exception instead of using the documented zero default.
Proposed fix: Normalize blank/missing `default_mileage_rate` to `'0.0000'` before insert/update, or omit the column so the DB default applies. Mirror the same rule in template update.

## [MEDIUM] — Soft-deleted unit number reuse contradicts the canonical unique constraint
File: api/v1/equipment/units/create.php:106
Code:
```php
// FIX #25: exclude soft-deleted units so a deleted unit_number can be reused
if (db_exists('equipment_units', 'unit_number = ? AND deleted_at IS NULL', [$unitNumber])) {
    json_error('ALREADY_EXISTS', 'Unit number already exists.', 409,
```
Why it's a problem: The API intentionally allows reusing a soft-deleted `unit_number`, but the canonical schema has `UNIQUE KEY unit_number (unit_number)` with no `deleted_at` component. The pre-check can pass, then the insert/update fails at the database layer; VIN has the same global unique constraint and no friendly pre-check at all.
Proposed fix: Either preserve global uniqueness in the API checks, or change the schema to support soft-delete-aware uniqueness. Also add a VIN conflict check so duplicate VINs return a controlled validation error.

## [MEDIUM] — Equipment delete trusts status instead of checking active child records
File: api/v1/equipment/units/delete.php:39
Code:
```php
$unit = db_row(
    "SELECT id, unit_number, status, samsara_vehicle_id, samsara_entity_type
       FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
...
db_execute(
    "UPDATE equipment_units SET deleted_at = NOW(), updated_by = ? WHERE id = ?",
```
Why it's a problem: Delete is blocked only when the unit's denormalized `status` is `on_lease` or `reserved`. If status drift occurs, or a lease/reservation is created between the read and update, the endpoint can soft-delete a unit that still has active operational records.
Proposed fix: Inside one transaction, lock the unit row and directly check active leases and pending/confirmed reservation assignments before soft-delete. Prefer the child tables as the source of truth over the unit status column.

## [LOW] — Unit status log tab calls an endpoint that does not exist
File: app/admin/equipment/show.php:2114
Code:
```php
async loadStatusLog() {
    this.loadingLog = true;
    try {
        const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/status-log') ?>?unit_id=<?= $unitId ?>');
```
Why it's a problem: There is no `api/v1/equipment/units/status-log.php` or matching route file under `api/v1/equipment/units/`. The Status Log tab will fail silently and show no history even though `equipment_status_log` is populated.
Proposed fix: Add the missing read endpoint with `equipment:view` permission and `unit_id` validation, or update the frontend to call the actual canonical status-log endpoint if one exists elsewhere.

Summary: 1 CRITICAL / 5 MEDIUM / 1 LOW.
