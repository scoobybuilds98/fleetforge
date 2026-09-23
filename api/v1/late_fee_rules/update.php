<?php
declare(strict_types=1);

/**
 * api/v1/late_fee_rules/update.php
 *
 * I19 — edit an existing late-fee rule's terms (type, value, grace days, cap,
 * active, or the customer "exempt" shortcut). The rule's SCOPE (global vs which
 * customer) is immutable — to move an override to another customer, delete it
 * and add a new one; this keeps the one-rule-per-scope guarantee trivially true
 * on edits and keeps every audit row about one customer.
 *
 * Already-issued late-fee invoices are untouched: each carries a
 * late_fee_rule_snapshot of the terms it was billed under.
 *
 * @method  POST
 * @body    JSON: id (required) + the same term fields as create.php
 * @auth    Session required; require_permission('settings','edit'); CSRF via bootstrap
 * @returns 200 rule | 404 NOT_FOUND | 422 VALIDATION_ERROR | 409 LOCKED
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
$id   = is_scalar($body['id'] ?? null) ? clean_positive_int($body['id']) : null;
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

lfr_lock();

$result = db_transaction(function () use ($id, $body): array {
    // FOR UPDATE: two editors saving the same rule serialise rather than interleave.
    $before = db_row("SELECT * FROM late_fee_rules WHERE id = ? FOR UPDATE", [$id]);
    if (!$before) {
        json_error('NOT_FOUND', 'Late fee rule not found (it may have been deleted).', 404);
    }

    $data = lfr_validate($body, $before['customer_id'] !== null);

    db_update('late_fee_rules', $data, 'id = ?', [$id]);

    $old = [
        'fee_type'       => $before['fee_type'],
        'fee_value'      => (string) $before['fee_value'],
        'grace_days'     => (int) $before['grace_days'],
        'max_fee_amount' => $before['max_fee_amount'] !== null ? (string) $before['max_fee_amount'] : null,
        'is_active'      => (int) $before['is_active'],
    ];
    $shaped = lfr_fetch($id);
    lfr_audit('update', $id, lfr_label($shaped), $old, $data, lfr_label($shaped) . ' updated');

    return $shaped;
});

lfr_unlock();

json_success($result);
