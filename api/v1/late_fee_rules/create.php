<?php
declare(strict_types=1);

/**
 * api/v1/late_fee_rules/create.php
 *
 * I19 — create a late-fee rule: the global rule (customer_id omitted/null) or a
 * per-customer override. At most ONE global rule and ONE rule per customer may
 * exist — a duplicate is refused with a 422 telling the operator to edit the
 * existing rule instead (the billing lookup would otherwise silently pick the
 * newest, making the older row a confusing dead record).
 *
 * `exempt: true` on a customer rule stores the canonical exemption (active,
 * flat $0.00) — an INACTIVE rule would NOT exempt, because the lookup skips it
 * and falls through to the global rule.
 *
 * @method  POST
 * @body    JSON: customer_id (int|null), fee_type ('percentage'|'flat'),
 *          fee_value (percent 0–100 or dollars), grace_days (0–255),
 *          max_fee_amount (dollars|null), is_active (bool), exempt (bool)
 * @auth    Session required; require_permission('settings','edit'); CSRF via bootstrap
 * @returns 201 rule | 422 VALIDATION_ERROR / DUPLICATE_RULE | 409 LOCKED
 *
 * @depends api/bootstrap.php, api/v1/late_fee_rules/_helpers.php
 * @session I19-LATE-FEE-RULES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

// customer_id: blank/0/null = global rule.
$rawCustomer = $body['customer_id'] ?? null;
$customerId  = null;
if ($rawCustomer !== null && $rawCustomer !== '' && $rawCustomer !== 0 && $rawCustomer !== '0') {
    $customerId = is_scalar($rawCustomer) ? clean_positive_int($rawCustomer) : null;
    if ($customerId === null) {
        json_validation_error(['customer_id' => 'Pick a customer from the list.']);
    }
    // Only live customers can get a new override (a soft-deleted customer bills nothing new).
    $customer = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
    if (!$customer) {
        json_validation_error(['customer_id' => 'That customer was not found (it may have been deleted).']);
    }
}

$data = lfr_validate($body, $customerId !== null);

lfr_lock();

// Duplicate guard — `<=>` is NULL-safe so the same query covers the global rule.
$existing = db_row(
    "SELECT id FROM late_fee_rules WHERE customer_id <=> ? LIMIT 1",
    [$customerId]
);
if ($existing) {
    json_error(
        'DUPLICATE_RULE',
        $customerId === null
            ? 'A global late fee rule already exists — edit it instead of adding another.'
            : 'This customer already has a late fee rule — edit or delete that one instead.',
        422,
        ['fields' => ['customer_id' => 'Already has a rule.'], 'existing_id' => (int) $existing['id']]
    );
}

$newId = db_transaction(function () use ($customerId, $data): int {
    $id = db_insert('late_fee_rules', [
        'customer_id'    => $customerId,
        'fee_type'       => $data['fee_type'],
        'fee_value'      => $data['fee_value'],
        'grace_days'     => $data['grace_days'],
        'max_fee_amount' => $data['max_fee_amount'],
        'compound'       => 0, // WHY: dead column — late fees are one-shot per invoice (late_fee_applied latch)
        'is_active'      => $data['is_active'],
        'created_by'     => current_user_id(),
        'created_at'     => ff_now_utc(),
    ]);

    $shaped = lfr_fetch($id);
    lfr_audit('create', $id, lfr_label($shaped), null, $data, lfr_label($shaped) . ' created');
    return $id;
});

lfr_unlock();

json_success(lfr_fetch($newId), 201);
