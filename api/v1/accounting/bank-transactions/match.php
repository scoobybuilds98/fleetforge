<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transactions/match.php
 *
 * Match a bank transaction to an existing record (payment, AP payment, JE).
 * User reviews auto-match suggestions and confirms or picks manually.
 *
 * @method  POST
 * @body    id, matched_type (payment|ap_payment|journal_entry|bank_transfer|other),
 *          matched_id
 * @auth    Session required; require_permission('bank_accounts','edit')
 * @returns 200 updated transaction
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'edit');

$id = clean_int($_POST['id'] ?? null);
$matchedType = clean_string($_POST['matched_type'] ?? null);
$matchedId = clean_int($_POST['matched_id'] ?? null);

if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);
if (!$matchedType || !in_array($matchedType, ['payment', 'ap_payment', 'journal_entry', 'bank_transfer', 'other'])) {
    json_error('VALIDATION_ERROR', 'matched_type is invalid.', 422);
}

$txn = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
if (!$txn) json_error('NOT_FOUND', 'Bank transaction not found.', 404);
if ($txn['status'] === 'matched') {
    json_error('VALIDATION_ERROR', 'Transaction is already matched. Unmatch first.', 422);
}

// Validate the matched record exists
if ($matchedId) {
    $valid = match ($matchedType) {
        'payment'      => db_row("SELECT id FROM payments WHERE id = ? AND deleted_at IS NULL", [$matchedId]),
        'ap_payment'   => db_row("SELECT id FROM acc_ap_payments WHERE id = ?", [$matchedId]),
        'journal_entry'=> db_row("SELECT id FROM acc_journal_entries WHERE id = ?", [$matchedId]),
        default        => ['id' => $matchedId],
    };
    if (!$valid) json_error('NOT_FOUND', 'Matched record not found.', 404);
}

$userId = current_user_id();

db_transaction(function () use ($id, $matchedType, $matchedId, $userId, $txn) {
    db_update('acc_bank_transactions', [
        'status'       => 'matched',
        'matched_type' => $matchedType,
        'matched_id'   => $matchedId,
        'matched_at'   => date('Y-m-d H:i:s'),
        'matched_by'   => $userId,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'bank_transaction',
        'entity_id'   => $id,
        'notes'       => "Bank transaction matched to {$matchedType} #{$matchedId}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
json_success($updated);
