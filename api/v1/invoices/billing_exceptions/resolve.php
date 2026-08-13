<?php
declare(strict_types=1);

/**
 * api/v1/invoices/billing_exceptions/resolve.php
 *
 * S-BILLING-EXCEPTIONS — clear a flagged lease off the review queue.
 *
 * Two distinct outcomes, kept separate on purpose:
 *   resolved — the underlying problem was fixed (rate added, lease
 *              reopened, billed by hand). Re-running the batch will now
 *              succeed, and generation auto-resolves it anyway.
 *   ignored  — a deliberate decision NOT to bill this lease/period. A
 *              note is REQUIRED, because "we chose not to bill this" is
 *              exactly the call someone will question at year-end and an
 *              unexplained dismissal is worse than no record at all.
 *
 * Resolving does NOT delete the row: the flag history (flagged_count,
 * last_flagged_at) is the audit trail for a lease that repeatedly fails
 * to bill, which is a signal worth keeping.
 *
 * @method  POST
 * @body    { id, action: 'resolve'|'ignore'|'reopen', note? }
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, status }
 *          422 note required for 'ignore'
 *
 * @session S-BILLING-EXCEPTIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$action = clean_string($body['action'] ?? null, 20);
if (!in_array($action, ['resolve', 'ignore', 'reopen'], true)) {
    json_validation_error(['action' => "action must be 'resolve', 'ignore' or 'reopen'."]);
}

$note = clean_string($body['note'] ?? null, 2000);
if ($action === 'ignore' && !$note) {
    json_validation_error(['note' => 'Say why this lease is being skipped — an unexplained dismissal is not an audit trail.']);
}

$ex = db_row(
    "SELECT e.*, l.contract_number
       FROM invoice_billing_exceptions e
       LEFT JOIN leases l ON l.id = e.lease_id
      WHERE e.id = ? AND e.deleted_at IS NULL",
    [$id]
);
if (!$ex) {
    json_error('NOT_FOUND', 'Flag not found.', 404);
}

$newStatus = match ($action) {
    'resolve' => 'resolved',
    'ignore'  => 'ignored',
    'reopen'  => 'open',
};

$userId   = current_user_id();
$userName = current_user()['name'] ?? 'System';

if ($newStatus === 'open') {
    db_execute(
        "UPDATE invoice_billing_exceptions
            SET status = 'open', resolution_note = NULL, resolved_by = NULL, resolved_at = NULL,
                updated_at = NOW()
          WHERE id = ?",
        [$id]
    );
} else {
    db_execute(
        "UPDATE invoice_billing_exceptions
            SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW(), updated_at = NOW()
          WHERE id = ?",
        [$newStatus, $note, $userId, $id]
    );
}

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $userName,
    'action'       => 'update',
    'module'       => 'invoices',
    'entity_type'  => 'billing_exception',
    'entity_id'    => $id,
    'entity_label' => (string) ($ex['contract_number'] ?? ('lease #' . $ex['lease_id'])),
    'notes'        => "Billing flag for lease #{$ex['lease_id']} ({$ex['period_start']}..{$ex['period_end']}) "
                    . "marked {$newStatus} by {$userName}" . ($note ? " — {$note}" : '') . '.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $id, 'status' => $newStatus]);
