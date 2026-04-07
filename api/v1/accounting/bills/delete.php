<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/delete.php
 *
 * Delete a draft AP bill. Only draft bills can be deleted.
 * Approved/paid/void bills cannot be deleted — use void instead.
 * Hard delete since acc_bills has no deleted_at column.
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('accounts_payable','delete')
 * @returns 200 { deleted: true }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'delete');

// VALID-2: accept both JSON and form-encoded payloads.
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Bill ID is required.']);
}

$bill = db_row("SELECT * FROM acc_bills WHERE id = ?", [$id]);
if (!$bill) {
    json_validation_error(['id' => 'Bill not found.'], 'Bill not found.');
}

if ($bill['status'] !== 'draft') {
    json_error('IMMUTABLE_RECORD',
        'Only draft bills can be deleted. Use void for approved bills.', 422,
        ['fields' => ['id' => "Cannot delete a bill in status '{$bill['status']}'. Use void instead."]]);
}

db_transaction(function () use ($id, $bill) {
    // Delete line items first (FK cascade would handle this, but be explicit)
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id = ?", [$id]);
    db_execute("DELETE FROM acc_bills WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'delete',
        'module'      => 'accounting',
        'entity_type' => 'ap_bill',
        'entity_id'   => $id,
        'notes'       => "Bill {$bill['bill_number']} deleted (was draft)",
        'old_values'  => json_encode(['status' => 'draft', 'total_amount' => $bill['total_amount']]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['deleted' => true]);
