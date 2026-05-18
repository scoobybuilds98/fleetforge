<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/delete.php
 *
 * Hard-delete a vendor credit. acc_vendor_credits has no deleted_at
 * column.
 *
 * Guards:
 *   - If any acc_vendor_credit_applications exist for this credit,
 *     deletion is blocked with 422 — applications represent applied
 *     funds and deleting the credit would orphan them (FK is
 *     ON DELETE CASCADE which would silently destroy the apply rows).
 *   - status='void' is allowed (already reversed; safe to remove).
 *   - status='fully_used' / 'partially_used' implies applications
 *     exist, so the application-count guard catches them first.
 *
 * The original posted JE is NOT auto-reversed. Operators should void
 * the credit first (which reverses the JE) before deleting. This
 * keeps the audit trail and ledger correct.
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('accounts_payable','delete')
 * @returns 200 { id }
 *          404 if not found
 *          422 if applications exist
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'delete');

$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'Vendor credit ID is required.', 422);
}

$credit = db_row("SELECT * FROM acc_vendor_credits WHERE id = ?", [$id]);
if (!$credit) {
    json_error('NOT_FOUND', 'Vendor credit not found.', 404);
}

// WHY: applications hold the apply-to-bill history. Deleting the credit
// would CASCADE-destroy them (per FK on acc_vendor_credit_applications)
// and unwind the AP balance silently. Block unless count=0.
$applicationCount = db_count(
    "SELECT COUNT(*) FROM acc_vendor_credit_applications WHERE vendor_credit_id = ?",
    [$id]
);

if ($applicationCount > 0 && $credit['status'] !== 'void') {
    json_error(
        'HAS_APPLICATIONS',
        "Cannot delete a vendor credit with {$applicationCount} existing application(s). Void the credit first to reverse applications.",
        422,
        ['fields' => ['_general' => "Cannot delete a vendor credit with {$applicationCount} existing application(s)."]]
    );
}

db_transaction(function () use ($id, $credit) {
    db_execute("DELETE FROM acc_vendor_credits WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'delete',
        'module'      => 'accounting',
        'entity_type' => 'vendor_credit',
        'entity_id'   => $id,
        'entity_label'=> $credit['credit_number'],
        'old_values'  => json_encode($credit),
        'notes'       => "Vendor credit {$credit['credit_number']} deleted (status was '{$credit['status']}')",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
