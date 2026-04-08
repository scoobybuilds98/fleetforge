<?php
declare(strict_types=1);

/**
 * api/v1/invoices/void.php
 *
 * Void an invoice. Valid from draft or sent status. Paid/partially_paid
 * cannot be voided (requires credit note). Updates denormalized counters.
 *
 * @method  POST
 * @body    id (required), void_reason (required)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 409 INVALID_TRANSITION
 *
 * Decisions: D12 (immutability — void preserves the number, D15)
 * Spec ref: §6 Invoice state machine (draft → void, sent → void)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$voidReason = clean_string($body['void_reason'] ?? null, 1000);
if (!$voidReason) {
    json_error('VALIDATION_ERROR', 'void_reason is required.', 422);
}

$invoice = db_row(
    "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// State machine: only draft and sent can be voided
$voidable = ['draft', 'sent'];
if (!in_array($invoice['status'], $voidable)) {
    json_error('INVALID_TRANSITION', "Cannot void invoice with status '{$invoice['status']}'. Only draft or sent invoices can be voided.", 409);
}

// FIX #19: audit_log moved inside transaction
db_transaction(function () use ($id, $invoice, $voidReason) {
    $now = date('Y-m-d H:i:s');

    db_update('invoices', [
        'status'      => 'void',
        'voided_date' => date('Y-m-d'),
        'void_reason' => $voidReason,
        'voided_by'   => current_user_id(),
        'updated_by'  => current_user_id(),
    ], 'id = ?', [$id]);

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
        'action'       => 'status_change',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "Invoice {$invoice['invoice_number']} voided: {$voidReason}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE: Reverse the original invoice JE (if one exists)
    // WHY: Inside same transaction — reversal failure rolls back the void (A8, §16)
    \FleetForge\Accounting\AutoEntryBridge::onInvoiceVoided($id, current_user_id());

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'invoice.voided',
            title:      "Invoice {$invoice['invoice_number']} voided",
            message:    "Invoice {$invoice['invoice_number']} voided: {$voidReason}",
            entityType: 'invoice',
            entityId:   $id,
            url:        '/fleetforge/invoices/show?id=' . $id,
            severity:   'warning'
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF invoice.voided] ' . $e->getMessage());
    }
});

json_success(['id' => $id, 'status' => 'void']);
