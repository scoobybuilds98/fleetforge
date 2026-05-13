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
            lease_id, due_date
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

// ── S-LEASE-MILEAGE HARD mileage review gate (D84) RETIRED ──
// Retired in S-MILEAGE-2B C5 per D-I locked (i) wholesale retire. Model B
// has no manager-review concept — InvoiceGenerator emits mileage_usage +
// optional mileage_drawdown_credit directly per the drawdown gate (D-B).
// The api/v1/invoices/review_mileage.php endpoint was deleted in this same
// commit; mileage_review_status column was dropped in C4 migration
// 202605120907_S-MILEAGE-2B_model_c_retirement.sql.

$sentToEmail = clean_email($body['sent_to_email'] ?? null) ?? $invoice['customer_email_snapshot'];
$now = date('Y-m-d H:i:s');

// FIX #19 + FIX #32: wrap db_update + audit_log in transaction
// S030: Auto-JE bridge — JE posts inside same transaction so failure rolls back send
db_transaction(function () use ($id, $invoice, $sentToEmail, $now) {
    // ── S-MILEAGE-2A D-D: precharge_invoiced_at lifecycle stamp ───
    // Before mutating any rows, dispatch on whether this invoice
    // carries a mileage_precharge line:
    //   IS NULL (lease.precharge_invoiced_at) → stamp NOW() after the
    //     status flip + outstanding_balance updates (happy path).
    //   IS NOT NULL                            → 409 PRECHARGE_ALREADY_BILLED.
    //
    // FOR UPDATE on the lease row serializes against any concurrent
    // send.php run on a DIFFERENT invoice of the same lease — the
    // check + stamp are atomic with respect to other senders. The
    // stamp's side-effect activates the PRECHARGE_LOCKED 409 in
    // api/v1/leases/update.php (D113) for free: once
    // precharge_invoiced_at is non-NULL, precharge_enabled/_amount
    // become immutable on the lease.
    $stampPrechargeAfter   = false;
    $stampPrechargeLease   = null;
    if (!empty($invoice['lease_id'])) {
        $prechargeLine = db_row(
            "SELECT 1 AS hit
               FROM invoice_line_items
              WHERE invoice_id = ? AND item_type = 'mileage_precharge'
              LIMIT 1",
            [$id]
        );
        if ($prechargeLine) {
            $leaseRow = db_row(
                "SELECT id, contract_number, precharge_invoiced_at, precharge_amount
                   FROM leases
                  WHERE id = ? AND deleted_at IS NULL
                  FOR UPDATE",
                [$invoice['lease_id']]
            );
            if (!$leaseRow) {
                json_error('NOT_FOUND', 'Lease referenced by invoice not found.', 404);
            }
            if ($leaseRow['precharge_invoiced_at'] !== null) {
                // ──────────────────────────────────────────────────────────
                // PRECHARGE_ALREADY_BILLED 409 — defense-in-depth backstop.
                // C3's emission gate (NOT EXISTS check on prior non-void
                // mileage_precharge lines) is the primary defense — under
                // normal flow, only Invoice 1 ever materializes a precharge
                // line. This 409 catches the residual cases the gate can't see:
                //   (1) racy concurrent generations both passing the gate
                //       before either commits
                //   (2) manual line insertion via UI/API bypassing the gate
                //   (3) future regression that weakens or removes the gate
                // See S-MILEAGE-2A C3 + D-B tightening for the gate; D-D and
                // this 409 are paired. Status family matches D113
                // PRECHARGE_LOCKED + D19 STALE_DATA (state-conflict 409s).
                // ──────────────────────────────────────────────────────────
                $priorInvoice = db_row(
                    "SELECT i.invoice_number
                       FROM invoices i
                       JOIN invoice_line_items li ON li.invoice_id = i.id
                      WHERE i.lease_id = ?
                        AND i.deleted_at IS NULL
                        AND i.status <> 'void'
                        AND i.id <> ?
                        AND li.item_type = 'mileage_precharge'
                      ORDER BY i.id
                      LIMIT 1",
                    [$invoice['lease_id'], $id]
                );
                $priorNum     = $priorInvoice['invoice_number'] ?? 'an earlier invoice';
                $prechargeAmt = $leaseRow['precharge_amount'] !== null
                    ? number_format((float) $leaseRow['precharge_amount'], 2, '.', ',')
                    : '0.00';
                json_error(
                    'PRECHARGE_ALREADY_BILLED',
                    "Precharge of \${$prechargeAmt} was already billed on invoice {$priorNum} at {$leaseRow['precharge_invoiced_at']}. Void or strip the mileage_precharge line from this invoice to send it.",
                    409
                );
            }
            // precharge_invoiced_at IS NULL → defer stamp until after the
            // status flip + counter updates land, so the audit_log row can
            // reference the now-sent invoice number.
            $stampPrechargeAfter = true;
            $stampPrechargeLease = $leaseRow;
        }
    }

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

    // ── S-MILEAGE-2A D-D: stamp precharge_invoiced_at (happy path) ───
    // The lease row was FOR UPDATE-locked above and precharge_invoiced_at
    // was confirmed NULL. Stamping it here freezes the precharge:
    // PRECHARGE_LOCKED 409 (D113) now fires on any future lease edit
    // attempting to change precharge_enabled or precharge_amount, and
    // any future invoice generation on this lease will skip the
    // mileage_precharge emission per C3's D-B lifecycle gate.
    if ($stampPrechargeAfter) {
        db_execute(
            "UPDATE leases
                SET precharge_invoiced_at = ?,
                    updated_at = NOW(),
                    updated_by = ?
              WHERE id = ?",
            [$now, current_user_id(), $invoice['lease_id']]
        );
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'System',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_invoiced_at_stamp',
            'entity_id'    => (int) $invoice['lease_id'],
            'entity_label' => $stampPrechargeLease['contract_number'] ?? null,
            'notes'        => "Lease precharge_invoiced_at stamped on invoice {$invoice['invoice_number']} send (S-MILEAGE-2A D-D). PRECHARGE_LOCKED gate now active.",
            'old_values'   => json_encode(['precharge_invoiced_at' => null]),
            'new_values'   => json_encode([
                'precharge_invoiced_at' => $now,
                'invoice_id'            => $id,
                'invoice_number'        => $invoice['invoice_number'],
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
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
                url:        '/fleetforge/portal/invoices/view?id=' . $id
            );
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF invoice.sent] ' . $e->getMessage());
    }
});

json_success(['id' => $id, 'status' => 'sent']);
