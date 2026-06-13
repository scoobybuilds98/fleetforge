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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id = clean_int($input['id'] ?? null);
$matchedType = clean_string($input['matched_type'] ?? null);
$matchedId = clean_int($input['matched_id'] ?? null);

if (!$id) $fields['id'] = 'Transaction ID is required.';

$validMatchedTypes = ['payment', 'ap_payment', 'journal_entry', 'bank_transfer', 'other'];
if (!$matchedType) {
    $fields['matched_type'] = 'Please select what to match to.';
} elseif (!in_array($matchedType, $validMatchedTypes, true)) {
    $fields['matched_type'] = 'Match type must be payment, AP payment, journal entry, bank transfer, or other.';
}

// MEDIUM [01b]: matched_id is required when matching to a concrete record. Only
// 'bank_transfer'/'other' may be matched without one. (Was optional for all
// types → a 'payment' match could be saved pointing at nothing.)
$idRequiredTypes = ['payment', 'ap_payment', 'journal_entry'];
if (in_array($matchedType, $idRequiredTypes, true) && !$matchedId) {
    $fields['matched_id'] = 'Please select the specific record to match to.';
}

if ($fields) {
    json_validation_error($fields);
}

$txn = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
if (!$txn) {
    json_error('NOT_FOUND', 'Bank transaction not found.', 404, [
        'fields' => ['id' => 'Bank transaction not found.'],
    ]);
}
if ($txn['status'] === 'matched') {
    json_validation_error(
        ['id' => 'Transaction is already matched. Unmatch first.'],
        'Transaction is already matched. Unmatch first.'
    );
}

// Validate the matched record exists
if ($matchedId) {
    $valid = match ($matchedType) {
        'payment'       => db_row("SELECT id FROM payments WHERE id = ? AND deleted_at IS NULL", [$matchedId]),
        'ap_payment'    => db_row("SELECT id FROM acc_ap_payments WHERE id = ?", [$matchedId]),
        'journal_entry' => db_row("SELECT id FROM acc_journal_entries WHERE id = ?", [$matchedId]),
        default         => ['id' => $matchedId],
    };
    if (!$valid) {
        json_error('NOT_FOUND', 'Matched record not found.', 404, [
            'fields' => ['matched_id' => 'Matched record not found.'],
        ]);
    }

    // MEDIUM [01b]: double-match guard. A given payment / AP payment / journal
    // entry must reconcile to at most ONE bank transaction — otherwise the same
    // cash event is reconciled twice. Reject if another matched txn already
    // points at this (type, id).
    if (in_array($matchedType, $idRequiredTypes, true)) {
        $dupe = db_row(
            "SELECT id FROM acc_bank_transactions
              WHERE matched_type = ? AND matched_id = ? AND status = 'matched' AND id != ?",
            [$matchedType, $matchedId, $id]
        );
        if ($dupe) {
            json_error('ALREADY_MATCHED',
                "That {$matchedType} is already matched to bank transaction #{$dupe['id']}. Unmatch it first.",
                409, ['fields' => ['matched_id' => "Already matched to bank transaction #{$dupe['id']}."]]);
        }
    }
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
