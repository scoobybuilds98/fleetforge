<?php
declare(strict_types=1);

/**
 * api/v1/invoices/send.php
 *
 * Transition invoice from draft → sent. Freezes all financial fields (D12).
 * Invoice send vs email delivery are separate (PASS-15:E3): this endpoint
 * always succeeds (DB write). Email may fail separately.
 *
 * @method  POST
 * @body    id (required), sent_to_email (optional override)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 422 INVALID_TRANSITION
 *
 * Decisions: D12 (immutability after send)
 * Spec ref: §6 Invoice state machine (draft → sent)
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

$invoice = db_row(
    "SELECT id, status, invoice_number, customer_email_snapshot,
            customer_id, company_name_snapshot, total_amount, balance_due,
            lease_id, due_date, mileage_review_status
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// State machine: only draft → sent is valid
if ($invoice['status'] !== 'draft') {
    json_error('INVALID_TRANSITION', "Cannot send invoice with status '{$invoice['status']}'. Only draft invoices can be sent.", 409);
}

// ── S-LEASE-MILEAGE: HARD mileage review gate ──────────────
// When excess mileage was detected, mileage_review_status is 'pending'
// until a manager explicitly approves or overrides it via the review UI.
// No role exempts this gate — super_admin and finance roles fall through
// the same check. Pattern matches S-PROD-1B invoice-immutability uniformity.
// CRA defensibility: an excess mileage charge cannot reach the customer
// without a documented manager review-and-approve trail.
if ($invoice['mileage_review_status'] === 'pending') {
    json_error(
        'MILEAGE_REVIEW_REQUIRED',
        'This invoice has excess mileage that requires manager review before it can be sent. Open the invoice and complete the mileage review first.',
        422
    );
}

$sentToEmail = clean_email($body['sent_to_email'] ?? null) ?? $invoice['customer_email_snapshot'];
$now = date('Y-m-d H:i:s');

// FIX #19 + FIX #32: wrap db_update + audit_log in transaction
// S030: Auto-JE bridge — JE posts inside same transaction so failure rolls back send
db_transaction(function () use ($id, $invoice, $sentToEmail, $now) {
    db_update('invoices', [
        'status'          => 'sent',
        'sent_date'       => date('Y-m-d'),
        'sent_at'         => $now,
        'sent_by'         => current_user_id(),
        'sent_to_email'   => $sentToEmail,
        'delivery_method' => 'email',
        'updated_by'      => current_user_id(),
    ], 'id = ?', [$id]);

    // S-FIX-2 Path B: outstanding_balance increments on draft -> sent.
    // Drafts do not contribute to OB; only sent/partially_paid/overdue do.
    // Both the lease counter and the customer counter advance together.
    $balanceDue = (string) $invoice['balance_due'];
    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
            [$balanceDue, $invoice['lease_id']]
        );
    }
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
            [$balanceDue, $invoice['customer_id']]
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
        'notes'        => "Invoice {$invoice['invoice_number']} sent (draft → sent). Counter delta: outstanding_balance += {$balanceDue} (Path B).",
        'old_values'   => json_encode(['status' => 'draft']),
        'new_values'   => json_encode(['status' => 'sent', 'outstanding_balance_delta' => $balanceDue]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE: DR 1030 AR / CR 4xxx Revenue / CR 2030 GST / CR 2040 PST
    // WHY: Posted inside same transaction — JE failure rolls back the send (A8, §16)
    \FleetForge\Accounting\AutoEntryBridge::onInvoiceSent($id, current_user_id());

    // ── In-app notifications (NOTIF-1) ─────────────────────────
    try {
        $companyName = $invoice['company_name_snapshot'] ?? 'customer';
        \FleetForge\Notifications\NotificationService::notify(
            type:       'invoice.sent',
            title:      "Invoice {$invoice['invoice_number']} sent",
            message:    "Invoice {$invoice['invoice_number']} sent to {$companyName}",
            entityType: 'invoice',
            entityId:   $id,
            url:        '/fleetforge/invoices/show?id=' . $id
        );
        if (!empty($invoice['customer_id'])) {
            $amt = '$' . number_format((float) $invoice['total_amount'], 2);
            \FleetForge\Notifications\NotificationService::notifyPortal(
                type:       'invoice.sent',
                customerId: (int) $invoice['customer_id'],
                title:      "Your invoice is ready",
                message:    "Invoice {$invoice['invoice_number']} — {$amt} due " . ($invoice['due_date'] ?? 'on receipt'),
                entityType: 'invoice',
                entityId:   $id,
                url:        '/fleetforge/portal/invoices/show?id=' . $id
            );
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF invoice.sent] ' . $e->getMessage());
    }
});

json_success(['id' => $id, 'status' => 'sent']);
