<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ap-payments/update.php
 *
 * Update editable non-financial fields on an AP payment. Financial
 * fields (vendor_id, bank_account_id, amount, currency,
 * payment_method) are IMMUTABLE post-create — they would diverge the
 * payment row from the posted JE and its bill allocations.
 *
 * Editable: reference_number, check_number, payment_date, notes.
 * Immutable: vendor_id, bank_account_id, amount, currency,
 *            payment_method, status (status flips via void.php only).
 *
 * @method  POST
 * @body    id (required) + any subset of: reference_number, check_number,
 *          payment_date, notes
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id }
 *          404 if not found
 *          422 if status='void'
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 *
 * Note: acc_ap_payments has no updated_at column — D19 optimistic
 * lock is skipped. The status='void' guard provides the primary
 * defence against editing a reversed payment.
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
    $fields['id'] = 'AP payment ID is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$payment = db_row("SELECT * FROM acc_ap_payments WHERE id = ?", [$id]);
if (!$payment) {
    json_error('NOT_FOUND', 'AP payment not found.', 404);
}

if ($payment['status'] === 'void') {
    json_error(
        'IMMUTABLE_RECORD',
        'Cannot update a voided AP payment.',
        422,
        ['fields' => ['status' => 'Cannot update a voided AP payment.']]
    );
}

$updates = [];

if (array_key_exists('reference_number', $input)) {
    $updates['reference_number'] = clean_string($input['reference_number'], 100);
}

if (array_key_exists('check_number', $input)) {
    $updates['check_number'] = clean_string($input['check_number'], 50);
}

if (array_key_exists('payment_date', $input)) {
    $newDate = clean_date($input['payment_date']);
    if (!$newDate) {
        $fields['payment_date'] = 'Payment date is invalid.';
    } else {
        $updates['payment_date'] = $newDate;
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

db_transaction(function () use ($id, $updates, $payment) {
    db_update('acc_ap_payments', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'ap_payment',
        'entity_id'   => $id,
        'entity_label'=> $payment['payment_number'],
        'old_values'  => json_encode($payment),
        'new_values'  => json_encode($updates),
        'notes'       => "AP payment {$payment['payment_number']} updated",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
