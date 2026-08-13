<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_runs/decide.php
 *
 * S-BATCH-APPROVAL — approve or reject a pending batch run.
 *
 * Gated on invoices.approve, which is a DIFFERENT action from the
 * invoices.create used to submit — that separation is the entire point of
 * the workflow (dispatchers and read-only roles default to false; manager,
 * accountant and super_admin default to true).
 *
 * NOTE (D-PERM-EXPAND / can()-baked-at-login): 'approve' is a NEW action
 * key. can() reads the permission map baked into the session at LOGIN, so
 * users already signed in when this shipped resolve it to false until they
 * log in again. super_admin short-circuits to true and is unaffected.
 *
 * Only 'pending' runs are decidable — deciding an already-decided or
 * already-generated run is a 409, not a silent overwrite, so an approval
 * can't be flipped after invoices were created from it.
 *
 * @method  POST
 * @body    { id, decision: 'approve'|'reject', decision_note? }
 * @auth    Session required; require_permission('invoices','approve')
 * @returns 200 { id, reference, status, decided_by, decided_at }
 *          409 INVALID_TRANSITION
 *
 * @session S-BATCH-APPROVAL
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'approve');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$decision = clean_string($body['decision'] ?? null, 20);
if (!in_array($decision, ['approve', 'reject'], true)) {
    json_validation_error(['decision' => "decision must be 'approve' or 'reject'."]);
}

$decisionNote = clean_string($body['decision_note'] ?? null, 2000);
// A rejection without a reason is useless to the person who has to fix it.
if ($decision === 'reject' && !$decisionNote) {
    json_validation_error(['decision_note' => 'Give a reason so the submitter knows what to change.']);
}

$run = db_row("SELECT * FROM invoice_batch_runs WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$run) {
    json_error('NOT_FOUND', 'Batch run not found.', 404);
}
if ($run['status'] !== 'pending') {
    json_error(
        'INVALID_TRANSITION',
        "Batch run {$run['reference']} is '{$run['status']}' — only a pending run can be approved or rejected.",
        409
    );
}

$newStatus = $decision === 'approve' ? 'approved' : 'rejected';
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$now       = date('Y-m-d H:i:s');

// Guarded UPDATE: the status check above ran unlocked, so a concurrent
// decision could otherwise both pass. Matching on status='pending' makes
// the loser affect 0 rows.
$affected = db_execute(
    "UPDATE invoice_batch_runs
        SET status = ?, decision_note = ?, decided_by = ?, decided_at = ?, updated_at = NOW()
      WHERE id = ? AND status = 'pending'",
    [$newStatus, $decisionNote, $userId, $now, $id]
);
if ($affected === 0) {
    json_error('INVALID_TRANSITION', 'This run was already decided by someone else.', 409);
}

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $userName,
    'action'       => 'update',
    'module'       => 'invoices',
    'entity_type'  => 'batch_run',
    'entity_id'    => $id,
    'entity_label' => $run['reference'],
    'notes'        => "Batch run {$run['reference']} {$newStatus} by {$userName}"
                    . ($decisionNote ? ' — ' . $decisionNote : '') . '.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

try {
    \FleetForge\Notifications\NotificationService::notify(
        type:       'invoice.batch_run_' . $newStatus,
        title:      "Batch run {$run['reference']} {$newStatus}",
        message:    ($decisionNote ?: "Decided by {$userName}."),
        entityType: 'batch_run',
        entityId:   $id,
        url:        '/fleetforge/invoices/batch_run?id=' . $id
    );
} catch (\Throwable $e) {
    error_log('[NOTIF invoice.batch_run_decision] ' . $e->getMessage());
}

json_success([
    'id'         => $id,
    'reference'  => $run['reference'],
    'status'     => $newStatus,
    'decided_by' => $userName,
    'decided_at' => $now,
]);
