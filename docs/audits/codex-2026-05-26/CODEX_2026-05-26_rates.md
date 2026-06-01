# FleetForge Audit — Rates

Date: 2026-05-26
Auditor: Codex
Scope: `app/admin/rates/`, `api/v1/rate_cards/`, `api/v1/customer_equipment_rates/`, rate lookup/amend helpers in `api/v1/leases/`, and customer rate override UI touchpoints. Production code only; skipped vendor/public/tests/db_migrations/docs.
Tables checked: `rate_cards`, `rate_card_items`, `customer_equipment_rates`, `customer_rate_history`, `equipment_templates`, `leases`.

## [CRITICAL] — UI-saved rate rows use template names but lease lookup searches categories
File: api/v1/leases/lookup_rates.php:95
Code:
```php
$equipmentType = $template['category'];
$today         = date('Y-m-d');

$customerRate = db_row(
    "SELECT daily_rate, weekly_rate, monthly_rate,
            mileage_rate, mileage_unit, currency
     FROM customer_equipment_rates
     WHERE customer_id = ?
       AND equipment_type = ?",
```
Why it's a problem: Lease creation resolves rates by `equipment_templates.category`, but the Rates UI and customer override UI save `equipment_type` using template names (`app/admin/rates/create.php:173`, `app/admin/rates/show.php:238`, `app/admin/customers/show.php:1091`). A rate card or customer override created through the admin UI will not match lookup unless a template name happens to equal the category enum, so lease rates silently fall through to template defaults or no rates. This can directly underbill or overbill new leases because the configured rate card/override is bypassed.
Proposed fix: Pick one canonical namespace, preferably template category, and enforce it at every writer and reader. Store category values from the UI/API, validate against `equipment_templates.category`, and migrate existing name-based rows.

## [MEDIUM] — Non-array `items` payload deletes all rate card items
File: api/v1/rate_cards/update.php:126
Code:
```php
$replaceItems      = array_key_exists('items', $body);
$itemsToInsert     = [];
$itemErrors        = [];

if ($replaceItems && is_array($body['items'])) {
    $seenTypes = [];
```
Why it's a problem: `items` being present sets `$replaceItems` even when the value is not an array. Validation is skipped for non-array values, but the transaction still executes the replace branch and deletes all existing `rate_card_items` with an empty insert list. A malformed client request can silently wipe a card's rates while returning success.
Proposed fix: If `items` exists and is not an array, return a validation error before the transaction. Only set the replacement flag after the payload shape is validated.

## [MEDIUM] — Default rate card can race into multiple defaults
File: api/v1/rate_cards/create.php:175
Code:
```php
if ($isDefault) {
    db_execute(
        "UPDATE rate_cards SET is_default = 0 WHERE is_default = 1 AND deleted_at IS NULL",
        []
    );
}

$id = db_insert('rate_cards', [
    'name'           => $name,
    'description'    => $description,
    'is_default'     => $isDefault,
```
Why it's a problem: The code clears existing defaults and inserts/updates the new default without a table/advisory lock or database uniqueness constraint. The canonical schema only has a non-unique `idx_default` index, so two concurrent default creates/updates can both commit as `is_default = 1`. Lease lookup orders by `rc.is_default DESC, rc.effective_from DESC`, so ambiguous defaults can make the selected rate card nondeterministic.
Proposed fix: Add a database-level invariant for a single active default, or lock the default-card set before changing it. Keep the clear-and-set operation inside that lock and handle duplicate/default conflicts as 409s.

## [MEDIUM] — Empty rate rows can shadow valid fallback rates
File: api/v1/rate_cards/create.php:114
Code:
```php
$rates = [
    'daily_rate'   => null,
    'weekly_rate'  => null,
    'monthly_rate' => null,
    'mileage_rate' => null,
];
$itemHadError = false;
foreach ($rateLabels as $field => $label) {
    if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) continue;
```
Why it's a problem: Rate card items and customer overrides can be saved with every monetary rate left `NULL`. `lookup_rates.php` treats the first matching customer/rate-card row as authoritative and returns it without checking whether any usable base rate exists, so an empty override/card item can suppress lower-priority template defaults. The UI also permits rows with only an equipment type selected.
Proposed fix: Require at least one usable base rate for any rate item or override, or make lookup skip rows that contain no billable rate values. Apply the same rule consistently to create/update/upsert paths.

## [MEDIUM] — Optimistic lock is checked before the write but not enforced by the write
File: api/v1/rate_cards/update.php:58
Code:
```php
$existing = db_row(
    "SELECT * FROM rate_cards WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
```
Why it's a problem: The row is read and compared outside the later transaction, and the final update uses only `WHERE id = ?`. A concurrent writer can update the same rate card after the check but before `db_update`, causing a lost update while the stale request still succeeds. The same read-then-write pattern appears in `api/v1/customer_equipment_rates/upsert.php:74` and `api/v1/customer_equipment_rates/upsert.php:210`.
Proposed fix: Enforce the token in the write itself (`WHERE id = ? AND updated_at = ?`) and require one affected row, or select the row `FOR UPDATE` inside the transaction before comparing and updating.

## [LOW] — Delete APIs ignore stale `updated_at` tokens sent by the UI
File: api/v1/customer_equipment_rates/delete.php:38
Code:
```php
$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Rate override ID is required.';
    json_validation_error($fields);
}

$rate = db_row(
    "SELECT id, customer_id, equipment_type,
```
Why it's a problem: The UI sends `updated_at` when deleting rate cards and customer overrides, but the delete endpoints only validate `id`. A user can delete a rate row that was changed after their page loaded, and the delete will succeed without a stale-data warning. This is lower severity than the update race because it requires delete permission and is an operator workflow issue, but it can still erase a newly edited rate.
Proposed fix: Require and compare `updated_at` on delete, or lock the row and reject deletes when the submitted token is stale. Keep the history/audit insert and delete in the same transaction after the stale check.

Summary: 1 CRITICAL / 4 MEDIUM / 1 LOW.
