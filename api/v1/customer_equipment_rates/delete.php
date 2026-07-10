<?php
declare(strict_types=1);

/**
 * api/v1/customer_equipment_rates/delete.php
 *
 * @deprecated S-RATES-CONSOLIDATE — customer pricing moved onto rate cards;
 *   no UI calls this endpoint. Kept for reversibility until a cleanup migration.
 *
 * Hard-delete a customer equipment rate override.
 *
 * Business rules:
 *   - customer_equipment_rates has no deleted_at — hard delete.
 *   - Records in customer_rate_history remain (FK ON DELETE SET NULL on created_by,
 *     not on the rate itself — history is always preserved).
 *   - Writes a 'deleted' entry to customer_rate_history before deleting.
 *   - Audit log: action='delete'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('rates','delete')
 * @returns 200 { id, deleted: true }
 *          404 NOT_FOUND
 *
 * Decisions: D7, §7 (audit log)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';


// S-AUDIT-BILLING-ENGINE-1 #24: DEPRECATED — customer rate overrides were folded
// into customer-specific rate cards (S-RATES-CONSOLIDATE); nothing reads this
// table anymore. A 200-OK write here silently priced NOTHING (the operator
// believed a rate was set). Hard 410 until the cleanup migration drops the
// table; use customer rate cards (Rates → New Card → customer) instead.
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
json_error('GONE',
    'Customer equipment rate overrides are deprecated — this write would have no pricing effect. Use a customer-specific rate card instead (Rates → New Card).',
    410);

require_method('POST');
require_auth_api();
require_permission('rates', 'delete');

// -----------------------------------------------------------------------
// 1. Parse body + resolve record
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Rate override ID is required.';
    json_validation_error($fields);
}

$rate = db_row(
    "SELECT id, customer_id, equipment_type,
            daily_rate, weekly_rate, monthly_rate,
            mileage_rate, mileage_unit, currency
     FROM customer_equipment_rates WHERE id = ?",
    [$id]
);
if (!$rate) {
    json_error('NOT_FOUND', 'Rate override not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Hard delete + history + audit log in one transaction
// -----------------------------------------------------------------------
db_transaction(function() use ($id, $rate) {
    // Write deletion to customer_rate_history before deleting the record
    db_insert('customer_rate_history', [
        'customer_id'    => $rate['customer_id'],
        'equipment_type' => $rate['equipment_type'],
        'daily_rate'     => $rate['daily_rate'],
        'weekly_rate'    => $rate['weekly_rate'],
        'monthly_rate'   => $rate['monthly_rate'],
        'mileage_rate'   => $rate['mileage_rate'],
        'mileage_unit'   => $rate['mileage_unit'],
        'currency'       => $rate['currency'],
        'change_type'    => 'deleted',
        'change_source'  => 'manual',
        'change_notes'   => 'Rate override deleted by user.',
        'created_by'     => current_user_id(),
    ]);

    // Hard delete — no deleted_at column on this table
    db_execute("DELETE FROM customer_equipment_rates WHERE id = ?", [$id]);

    // §7 audit log
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'rates',
        'entity_type'  => 'customer_equipment_rate',
        'entity_id'    => $id,
        'entity_label' => "Customer #{$rate['customer_id']} — {$rate['equipment_type']}",
        'old_values'   => json_encode([
            'daily_rate'   => $rate['daily_rate'],
            'weekly_rate'  => $rate['weekly_rate'],
            'monthly_rate' => $rate['monthly_rate'],
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
