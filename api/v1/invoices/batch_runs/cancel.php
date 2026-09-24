<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_runs/cancel.php
 *
 * S-BILLING-MODULE — withdraw a PENDING batch run (status → 'cancelled').
 *
 * The 'cancelled' status existed on invoice_batch_runs from S-BATCH-APPROVAL
 * but nothing could set it, so a run submitted by mistake sat pending until
 * someone rejected it (which reads as a judgement on the figures). Cancel is
 * the honest outcome: nothing is billed, the run stays on record with the
 * reason, and it can be resubmitted from its page as a new run.
 *
 * Who: the submitter (withdrawing their own run, needs invoices:create) or
 * anyone who can approve (invoices:approve). Only from 'pending' — an
 * approved run is generated or left; a decided run keeps its decision.
 *
 * @method  POST
 * @body    { id, reason }
 * @auth    Session required; invoices:create (own run) or invoices:approve
 * @returns 200 { id, reference, status: 'cancelled' }
 *          409 INVALID_TRANSITION (not pending)
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'view');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
$reason = clean_string($body['reason'] ?? null, 2000);
if ($reason === null || trim($reason) === '') {
    json_validation_error(['reason' => 'Say why the run is being cancelled.']);
}

$run = db_row("SELECT * FROM invoice_batch_runs WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$run) {
    json_error('NOT_FOUND', 'Batch run not found.', 404);
}

$userId  = current_user_id();
$isOwner = (int) ($run['submitted_by'] ?? 0) === (int) $userId;
if (!can('invoices', 'approve') && !($isOwner && can('invoices', 'create'))) {
    json_error('FORBIDDEN', 'Only the person who submitted this run, or an approver, can cancel it.', 403);
}
if ($run['status'] !== 'pending') {
    json_error(
        'INVALID_TRANSITION',
        "Batch run {$run['reference']} is '{$run['status']}' — only a pending run can be cancelled.",
        409
    );
}

$affected = db_execute(
    "UPDATE invoice_batch_runs
        SET status = 'cancelled', decision_note = ?, decided_by = ?, decided_at = ?, updated_at = NOW()
      WHERE id = ? AND status = 'pending'",
    ['Cancelled: ' . $reason, $userId, ff_now_utc(), $id]
);
if ($affected === 0) {
    json_error('INVALID_TRANSITION', 'This run was already decided by someone else.', 409);
}

$userName = current_user()['name'] ?? 'System';
db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $userName,
    'action'       => 'status_change',
    'module'       => 'invoices',
    'entity_type'  => 'batch_run',
    'entity_id'    => $id,
    'entity_label' => $run['reference'],
    'old_values'   => json_encode(['status' => 'pending']),
    'new_values'   => json_encode(['status' => 'cancelled']),
    'notes'        => "Batch run {$run['reference']} cancelled by {$userName}: {$reason}",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// S-ATTENTION-INBOX: a cancelled run no longer needs approval — close its
// Needs attention item now (approve/reject close it via their notification).
\FleetForge\Attention\AttentionService::recheck('batch_run_approval', (int) $id);

json_success(['id' => $id, 'reference' => $run['reference'], 'status' => 'cancelled']);
