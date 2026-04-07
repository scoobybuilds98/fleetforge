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

$id = clean_int($_POST['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$bill = db_row("SELECT * FROM acc_bills WHERE id = ?", [$id]);
if (!$bill) json_error('NOT_FOUND', 'Bill not found.', 404);

if ($bill['status'] !== 'draft') {
    json_error('IMMUTABLE_RECORD', 'Only draft bills can be deleted. Use void for approved bills.', 422);
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
