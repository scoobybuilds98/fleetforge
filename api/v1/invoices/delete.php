<?php
declare(strict_types=1);

/**
 * api/v1/invoices/delete.php
 *
 * Soft-delete a draft invoice. Non-draft invoices cannot be deleted (D12).
 * Sent/paid invoices must be voided instead. Gap-free numbering preserved (D15).
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('invoices','delete')
 * @returns 200 success | 422 IMMUTABLE_RECORD
 *
 * Decisions: D5 (soft-delete), D12 (immutability), D15 (gap-free numbers)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'delete');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$invoice = db_row(
    "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// Only draft invoices can be deleted — super_admin may delete any status
if ($invoice['status'] !== 'draft' && !is_super_admin()) {
    json_error('IMMUTABLE_RECORD', 'Only draft invoices can be deleted. Use void for sent invoices.', 422);
}

// FIX #19: audit_log moved inside transaction
db_transaction(function () use ($id, $invoice) {
    // Soft-delete the invoice
    db_update('invoices', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

    // Reverse denormalized counters (Trap 6)
    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
            [$invoice['total_amount'], $invoice['balance_due'], $invoice['lease_id']]
        );
    }
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
            [$invoice['balance_due'], $invoice['customer_id']]
        );
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'delete',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "Invoice {$invoice['invoice_number']} soft-deleted (was {$invoice['status']})",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
