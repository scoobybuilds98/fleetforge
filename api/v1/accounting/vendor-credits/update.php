<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/update.php
 *
 * Update editable non-financial fields on a vendor credit. Financial
 * fields (vendor_id, amount, currency) are IMMUTABLE post-create — they
 * would diverge the credit row from the posted JE.
 *
 * Editable: credit_date, reference_number (stored in reason or notes
 * per existing schema — see note below), notes, reason.
 * Immutable: vendor_id, amount, currency, status (status flips via
 * apply.php and void flow, not generic update).
 *
 * @method  POST
 * @body    id (required) + any subset of: credit_date, reason, notes
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id }
 *          404 if not found
 *          422 if status='fully_used' or 'void'
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 *
 * Note: acc_vendor_credits has no updated_at column — D19 optimistic
 * lock is skipped here. The status guard provides the primary
 * defence against editing a credit that has had applications posted.
 *
 * Schema note: there is no separate reference_number column on
 * acc_vendor_credits — the `reason` (varchar(500)) field doubles as a
 * free-text descriptor. We expose `reason` for editing.
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id = clean_int($input['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Vendor credit ID is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$credit = db_row("SELECT * FROM acc_vendor_credits WHERE id = ?", [$id]);
if (!$credit) {
    json_error('NOT_FOUND', 'Vendor credit not found.', 404);
}

// Status guard — cannot edit a fully-used or voided credit.
// Partially-used is allowed because the editable fields here are purely
// descriptive (date, reason, notes) and do not affect any posted JE.
if (in_array($credit['status'], ['fully_used', 'void'], true)) {
    json_error(
        'IMMUTABLE_RECORD',
        "Cannot update a vendor credit with status '{$credit['status']}'.",
        422,
        ['fields' => ['status' => "Cannot update a vendor credit with status '{$credit['status']}'."]]
    );
}

$updates = [];

if (array_key_exists('credit_date', $input)) {
    $newDate = clean_date($input['credit_date']);
    if (!$newDate) {
        $fields['credit_date'] = 'Credit date is invalid.';
    } else {
        $updates['credit_date'] = $newDate;
    }
}

if (array_key_exists('reason', $input)) {
    $newReason = clean_string($input['reason'], 500);
    if (!$newReason) {
        $fields['reason'] = 'Reason cannot be empty.';
    } else {
        $updates['reason'] = $newReason;
    }
}

if (array_key_exists('notes', $input)) {
    $updates['notes'] = clean_string($input['notes'], 2000);
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error(['_general' => 'No editable fields provided.']);
}

db_transaction(function () use ($id, $updates, $credit) {
    db_update('acc_vendor_credits', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'vendor_credit',
        'entity_id'   => $id,
        'entity_label'=> $credit['credit_number'],
        'old_values'  => json_encode($credit),
        'new_values'  => json_encode($updates),
        'notes'       => "Vendor credit {$credit['credit_number']} updated",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
