<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/delete.php
 *
 * Soft-delete a damage claim (sets deleted_at = NOW()).
 *
 * Only 'reported' and 'assessed' claims may be deleted — once a claim
 * has progressed to repair_ordered or beyond it affects linked records
 * (work orders, invoices) and must be written_off via status transition,
 * not deleted.
 *
 * Photos are NOT deleted here — they remain for audit trail purposes.
 * Storage files are orphaned deliberately (recoverable, audit trail).
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { success: true }
 *
 * Decisions: D5 (soft delete)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

// -----------------------------------------------------------------------
// 1. Load claim
// -----------------------------------------------------------------------
$body    = json_body();
$claimId = clean_int($body['id'] ?? null);
if (!$claimId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$claim = db_row(
    "SELECT id, claim_number, status FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
    [$claimId]
);
if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// Only allow deletion of early-stage claims
$deletableStatuses = ['reported', 'assessed'];
if (!in_array($claim['status'], $deletableStatuses, true)) {
    json_error(
        'INVALID_TRANSITION',
        "Claims in '{$claim['status']}' status cannot be deleted. Use 'written_off' status transition instead.",
        422
    );
}

// -----------------------------------------------------------------------
// 2. Soft-delete + audit
// -----------------------------------------------------------------------
db_transaction(function () use ($claimId, $claim) {
    db_execute(
        "UPDATE damage_claims SET deleted_at = NOW() WHERE id = ?",
        [$claimId]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'delete',
        'module'       => 'maintenance',
        'entity_type'  => 'damage_claim',
        'entity_id'    => $claimId,
        'entity_label' => $claim['claim_number'],
        'notes'        => "Damage claim {$claim['claim_number']} soft-deleted (was '{$claim['status']}').",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['deleted' => true]);
