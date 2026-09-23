<?php
declare(strict_types=1);

/**
 * api/v1/late_fee_rules/delete.php
 *
 * I19 — delete a late-fee rule. Hard delete: late_fee_rules has no deleted_at,
 * and nothing depends on the row after the fact — late-fee invoices snapshot
 * the rule terms (invoices.late_fee_rule_snapshot) and their
 * late_fee_rule_id is informational. The full old row is kept in audit_log.
 *
 * Deleting a customer override sends that customer back to the global rule;
 * deleting the global rule means customers without an override are no longer
 * charged late fees.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('settings','edit'); CSRF via bootstrap
 * @returns 200 { id, deleted: true } | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php, api/v1/late_fee_rules/_helpers.php
 * @session I19-LATE-FEE-RULES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

require_method('POST');
require_auth_api();
// WHY edit (not delete): settings.delete is super_admin-only; removing a rule is
// an ordinary configuration change, the same authority that created it.
require_permission('settings', 'edit');

$body = json_body();
$id   = is_scalar($body['id'] ?? null) ? clean_positive_int($body['id']) : null;
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

lfr_lock();

db_transaction(function () use ($id): void {
    $before = db_row("SELECT * FROM late_fee_rules WHERE id = ? FOR UPDATE", [$id]);
    if (!$before) {
        json_error('NOT_FOUND', 'Late fee rule not found (it may already be deleted).', 404);
    }
    $shaped = lfr_fetch($id); // before the DELETE — needed for the customer name in the label

    db_execute("DELETE FROM late_fee_rules WHERE id = ?", [$id]);

    lfr_audit('delete', $id, lfr_label($shaped), $before, null, lfr_label($shaped) . ' deleted');
});

lfr_unlock();

json_success(['id' => $id, 'deleted' => true]);
