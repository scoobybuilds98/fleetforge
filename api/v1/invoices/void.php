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

    // S-FIX-2 Path B: status-aware counter logic + Phase 0.5 Bug B fix.
    // Snapshot the pre-void status so the audit log shows what we decremented.
    $preVoidStatus      = $invoice['status'];
    $totalAmount        = (string) $invoice['total_amount'];
    $balanceDue         = (string) $invoice['balance_due'];

    // Path B canonical truth:
    //   draft  -> void: total_invoiced -= total_amount; OB unchanged
    //   sent   -> void: total_invoiced -= total_amount; OB -= balance_due
    //   (paid/partially_paid/overdue cannot reach this code — voidable list above
    //    is ['draft','sent']. Kept the dec_ob_amount switch for symmetry only.)
    $decOb = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;

    // S-FIX-2 Phase 0.5 Bug B: zero balance_due on the void row to prevent a
    // subsequent super_admin delete from double-decrementing the counter.
    db_update('invoices', [
        'status'      => 'void',
        'balance_due' => '0.00',
        'voided_date' => date('Y-m-d'),
        'void_reason' => $voidReason,
        'voided_by'   => current_user_id(),
        'updated_by'  => current_user_id(),
    ], 'id = ?', [$id]);

    // Reverse denormalized counters (Trap 6)
    // total_revenue tracks sent invoice amounts — only reverse when was sent
    $decRevenue = ($preVoidStatus === 'sent') ? $totalAmount : '0.00';
    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
            [$totalAmount, $decOb, $invoice['lease_id']]
        );

        // Walk the billing-coverage anchor back to the latest STILL-LIVE invoice.
        // last_billed_date is monotonic on create (GREATEST in InvoiceGenerator),
        // so without this a voided most-recent invoice would leave the anchor
        // pointing PAST real coverage. close.php now derives coverage from live
        // invoices directly (belt), but keep the denormalized anchor honest for
        // reports and any other reader (suspenders). The just-voided row is
        // already status='void' above, so MAX excludes it. NULL when no live
        // invoice remains (lease reverts to never-billed).
        $cov = db_row(
            "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
               FROM invoices i2
              WHERE i2.lease_id = ?
                AND i2.deleted_at IS NULL
                AND i2.status <> 'void'
                AND i2.billing_period_end IS NOT NULL
              ORDER BY i2.billing_period_end DESC, i2.id DESC
              LIMIT 1",
            [$invoice['lease_id']]
        );
        db_execute(
            "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
            [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $invoice['lease_id']]
        );
    }
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance - ?,
                                  total_revenue       = total_revenue       - ?,
                                  updated_at = NOW() WHERE id = ?",
            [$decOb, $decRevenue, $invoice['customer_id']]
        );
    }
    if ($decRevenue !== '0.00' && $invoice['lease_id']) {
        db_execute(
            "UPDATE equipment_units eu
               JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                SET eu.total_revenue = eu.total_revenue - ?, eu.updated_at = NOW()",
            [$invoice['lease_id'], $decRevenue]
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
        'notes'        => "Invoice {$invoice['invoice_number']} voided (was {$preVoidStatus}): {$voidReason}. Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
        'old_values'   => json_encode(['status' => $preVoidStatus, 'balance_due' => $balanceDue]),
        'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
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

// ── S-QBO-12: enqueue void to QBO ─────────────────────────────
// Pattern matches api/v1/invoices/send.php (S-QBO-11 D-QBO-11-1):
// best-effort enqueue AFTER the db_transaction commits so void
// state is durably persisted before the QBO sync attempt fires.
// InvoiceEnqueuer gate-0 (D-ENQUEUER-GATE-0-ELIGIBILITY, S-QBO-
// ENQUEUER-ELIGIBILITY-GATE) verifies status='void' which is now
// the case post-commit. Worker dispatches to InvoicePusher::pushVoid
// per dispatcher convention (D-QBO-3-2).
\FleetForge\QboPushers\InvoiceEnqueuer::enqueue((int) $id, 'void');

json_success(['id' => $id, 'status' => 'void']);
