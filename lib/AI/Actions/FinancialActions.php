<?php
declare(strict_types=1);

namespace FleetForge\AI\Actions;

/**
 * lib/AI/Actions/FinancialActions.php
 *
 * S-AI-ACTION-2 — Financial lifecycle actions, extracted from their API
 * endpoints so the canonical endpoint AND the AI confirm→apply path run the
 * exact same guards, denormalized-counter updates, accounting JE calls
 * (AutoEntryBridge), audit rows, notifications, and QBO enqueue. No drift.
 *
 * High blast radius — each method preserves the endpoint's behavior verbatim:
 *   - voidInvoice:   Path B counters (lease/customer/equipment) + JE reversal
 *                    + last_billed anchor walk-back + QBO void enqueue.
 *
 * Auth/permission are the caller's responsibility.
 *
 * @session S-AI-ACTION-2
 */
class FinancialActions
{
    private const VOIDABLE = ['draft', 'sent'];

    /**
     * Void an invoice (draft or sent only). Mirrors api/v1/invoices/void.php.
     *
     * @throws ActionException  NOT_FOUND / VALIDATION_ERROR / INVALID_TRANSITION
     * @return array{id:int, invoice_number:string, status:string, prev_status:string}
     */
    public static function voidInvoice(int $id, ?string $voidReason, int $userId, string $userName, ?string $ip): array
    {
        $voidReason = $voidReason !== null ? trim($voidReason) : '';
        if ($voidReason === '') {
            throw new ActionException('VALIDATION_ERROR', 'A void reason is required.', 422);
        }

        $invoice = db_row(
            "SELECT id, status, invoice_number, invoice_type, total_amount, balance_due, customer_id, lease_id
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$invoice) {
            throw new ActionException('NOT_FOUND', 'Invoice not found.', 404);
        }
        if (!in_array($invoice['status'], self::VOIDABLE, true)) {
            throw new ActionException('INVALID_TRANSITION',
                "Cannot void invoice {$invoice['invoice_number']} with status '{$invoice['status']}'. Only draft or sent invoices can be voided (paid invoices need a credit note).", 409);
        }

        // S-ORPHAN-OVERFLOW-CN: an invoice and its auto-created overflow credit
        // note live and die together — a stranded active CN poisons every later
        // mileage true-up. An already-APPLIED overflow CN blocks the void.
        $cnBlockers = \FleetForge\Billing\OverflowCreditNotes::findBlockers($id);
        if ($cnBlockers) {
            throw new ActionException('CREDIT_NOTE_APPLIED',
                \FleetForge\Billing\OverflowCreditNotes::blockerMessage($cnBlockers, 'void'), 422);
        }

        $voidedCns = [];

        db_transaction(function () use ($id, $invoice, $voidReason, $userId, $userName, $ip, &$voidedCns): void {
            $preVoidStatus = $invoice['status'];
            $totalAmount   = (string) $invoice['total_amount'];
            $balanceDue    = (string) $invoice['balance_due'];

            // Path B: draft→void OB unchanged; sent→void OB -= balance_due.
            $decOb      = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;
            $decRevenue = ($preVoidStatus === 'sent') ? $totalAmount : '0.00';

            // I05: self-guarding status flip. The status was read OUTSIDE this
            // transaction with no row lock, so two concurrent voids could both pass
            // the VOIDABLE check above and both run the counter decrements below,
            // double-reversing Path-B OB/revenue. Gate the UPDATE on the exact
            // pre-void status; a concurrent void serializes on the row lock, then
            // matches 0 rows and we abort BEFORE any counter is touched.
            $affected = db_update('invoices', [
                'status'      => 'void',
                'balance_due' => '0.00',
                'voided_date' => date('Y-m-d'),
                'void_reason' => $voidReason,
                'voided_by'   => $userId,
                'updated_by'  => $userId,
            ], 'id = ? AND status = ?', [$id, $preVoidStatus]);
            if ($affected === 0) {
                throw new ActionException('INVALID_TRANSITION',
                    "Invoice {$invoice['invoice_number']} was modified concurrently (no longer '{$preVoidStatus}'). Refresh and retry.", 409);
            }

            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $decOb, $invoice['lease_id']]
                );
                // Walk last_billed anchor back to the latest still-live invoice.
                $cov = db_row(
                    "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
                       FROM invoices i2
                      WHERE i2.lease_id = ? AND i2.deleted_at IS NULL AND i2.status <> 'void'
                        AND i2.billing_period_end IS NOT NULL
                      ORDER BY i2.billing_period_end DESC, i2.id DESC LIMIT 1",
                    [$invoice['lease_id']]
                );
                db_execute(
                    "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
                    [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $invoice['lease_id']]
                );
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance - ?, total_revenue = total_revenue - ?, updated_at = NOW() WHERE id = ?",
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
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $id,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "Invoice {$invoice['invoice_number']} voided (was {$preVoidStatus}): {$voidReason}. Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
                'old_values'   => json_encode(['status' => $preVoidStatus, 'balance_due' => $balanceDue]),
                'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
                'ip_address'   => $ip ?? '127.0.0.1',
            ]);

            // Reverse the original invoice JE — inside the txn, so a reversal
            // failure rolls back the whole void (A8, §16).
            // S-AUDIT-BILLING-ENGINE-1 #22: closed-period reversal surfaces as
            // a clean 409 (voidPayment already did this; invoice/CN voids were
            // raw 500s).
            try {
                \FleetForge\Accounting\AutoEntryBridge::onInvoiceVoided($id, $userId);
            } catch (\RuntimeException $e) {
                if (stripos($e->getMessage(), 'period') !== false) {
                    throw new ActionException('PERIOD_CLOSED',
                        "Cannot void invoice {$invoice['invoice_number']}: its journal entry's accounting period is closed. Re-open the period (or open a current one) and retry. " . $e->getMessage(), 409);
                }
                throw $e;
            }

            // S-AUDIT-LIFECYCLE-1 (closes F33 at the void site): restore any
            // precharge drawdown this invoice consumed and re-open the D138
            // precharge emit gate if it carried the mileage_precharge charge.
            ff_reverse_precharge_on_invoice_removal($id, $userId, $userName, 'voided');

            // S-AUDIT-BILLING-ENGINE-1 #18: voiding a LATE-FEE invoice unlatches
            // its original — the latch (late_fee_applied=1 + dangling
            // late_fee_invoice_id) used to survive the void, so the original
            // showed "Late fee applied" forever and could never legitimately be
            // re-assessed.
            if (($invoice['invoice_type'] ?? '') === 'late_fee') {
                $latched = db_row(
                    "SELECT id, invoice_number FROM invoices WHERE late_fee_invoice_id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$id]
                );
                if ($latched) {
                    db_update('invoices', [
                        'late_fee_applied'    => 0,
                        'late_fee_amount'     => '0.00',
                        'late_fee_date'       => null,
                        'late_fee_invoice_id' => null,
                    ], 'id = ?', [(int) $latched['id']]);
                    db_insert('audit_log', [
                        'user_id' => $userId, 'user_name' => $userName, 'action' => 'update',
                        'module' => 'invoices', 'entity_type' => 'invoice', 'entity_id' => (int) $latched['id'],
                        'entity_label' => $latched['invoice_number'],
                        'notes' => "Late-fee latch cleared: fee invoice {$invoice['invoice_number']} was voided — {$latched['invoice_number']} may be re-assessed by the late-fee cron.",
                        'ip_address' => $ip ?? '127.0.0.1',
                    ]);
                }
            }

            // S-ORPHAN-OVERFLOW-CN: void the invoice's auto-created overflow
            // CNs in the SAME transaction (unapplied-only; blockers refused
            // above). Reverses each CN's issue JE via onCreditNoteVoided.
            $voidedCns = \FleetForge\Billing\OverflowCreditNotes::voidForInvoice(
                $id, $userId, $userName,
                "source invoice {$invoice['invoice_number']} voided"
            );

            try {
                \FleetForge\Notifications\NotificationService::notify(
                    type: 'invoice.voided',
                    title: "Invoice {$invoice['invoice_number']} voided",
                    message: "Invoice {$invoice['invoice_number']} voided: {$voidReason}",
                    entityType: 'invoice', entityId: $id,
                    url: '/fleetforge/invoices/show?id=' . $id, severity: 'warning'
                );
            } catch (\Throwable $e) {
                error_log('[NOTIF invoice.voided] ' . $e->getMessage());
            }
        });

        // Best-effort QBO void enqueue AFTER commit (state durably persisted).
        \FleetForge\QboPushers\InvoiceEnqueuer::enqueue($id, 'void');
        foreach ($voidedCns as $cn) {
            \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cn['id'], 'void');
        }

        // S-AUDIT-LIFECYCLE-1: bulk_void/delete invalidate the dashboard cache;
        // the single-void path never did, leaving stale AR tiles.
        if (function_exists('invalidate_dashboard_cache')) {
            invalidate_dashboard_cache();
        }

        return ['id' => $id, 'invoice_number' => $invoice['invoice_number'], 'status' => 'void', 'prev_status' => $invoice['status']];
    }

    /**
     * Send an invoice (draft → sent). Mirrors api/v1/invoices/send.php: Path B
     * counter increments + revenue JE posting + precharge lifecycle stamp.
     * Does NOT itself email the customer (the DB flip is separate from delivery,
     * which is async); downstream notifications fire.
     *
     * @throws ActionException  NOT_FOUND / INVALID_TRANSITION / PRECHARGE_ALREADY_BILLED
     * @return array{id:int, invoice_number:string, status:string}
     */
    public static function sendInvoice(int $id, ?string $sentToEmailOverride, int $userId, string $userName, ?string $ip): array
    {
        $invoice = db_row(
            "SELECT id, status, invoice_number, customer_email_snapshot, customer_id,
                    company_name_snapshot, total_amount, balance_due, lease_id, due_date
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$invoice) {
            throw new ActionException('NOT_FOUND', 'Invoice not found.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            throw new ActionException('INVALID_TRANSITION',
                "Cannot send invoice {$invoice['invoice_number']} with status '{$invoice['status']}'. Only draft invoices can be sent.", 409);
        }

        $override   = ($sentToEmailOverride !== null && function_exists('clean_email')) ? clean_email($sentToEmailOverride) : $sentToEmailOverride;
        $sentToEmail = ($override !== null && $override !== '') ? $override : $invoice['customer_email_snapshot'];
        $now = date('Y-m-d H:i:s');

        db_transaction(function () use ($id, $invoice, $sentToEmail, $now, $userId, $userName, $ip): void {
            // Precharge lifecycle gate (S-MILEAGE-2A D-D)
            $stampPrechargeAfter = false;
            $stampPrechargeLease = null;
            if (!empty($invoice['lease_id'])) {
                $prechargeLine = db_row(
                    "SELECT 1 AS hit FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_precharge' LIMIT 1",
                    [$id]
                );
                if ($prechargeLine) {
                    $leaseRow = db_row(
                        "SELECT id, contract_number, precharge_invoiced_at, precharge_amount
                           FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                        [$invoice['lease_id']]
                    );
                    if (!$leaseRow) {
                        throw new ActionException('NOT_FOUND', 'Lease referenced by invoice not found.', 404);
                    }
                    if ($leaseRow['precharge_invoiced_at'] !== null) {
                        $prior = db_row(
                            "SELECT i.invoice_number FROM invoices i
                               JOIN invoice_line_items li ON li.invoice_id = i.id
                              WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
                                AND i.id <> ? AND li.item_type = 'mileage_precharge'
                              ORDER BY i.id LIMIT 1",
                            [$invoice['lease_id'], $id]
                        );
                        $priorNum = $prior['invoice_number'] ?? 'an earlier invoice';
                        $amt = $leaseRow['precharge_amount'] !== null ? number_format((float) $leaseRow['precharge_amount'], 2, '.', ',') : '0.00';
                        throw new ActionException('PRECHARGE_ALREADY_BILLED',
                            "Precharge of \${$amt} was already billed on invoice {$priorNum}. Void or strip the mileage_precharge line before sending this one.", 409);
                    }
                    $stampPrechargeAfter = true;
                    $stampPrechargeLease = $leaseRow;
                }
            }

            // I05: self-guarding draft→sent flip. status was read OUTSIDE this
            // transaction with no row lock, so two concurrent sends could both pass
            // the draft check above and both run the Path-B counter increments
            // below (double OB/revenue). Gate the UPDATE on status='draft'; a
            // concurrent send serializes on the row lock, matches 0 rows, and we
            // abort BEFORE any counter is touched.
            $affected = db_update('invoices', [
                'status'          => 'sent',
                'sent_date'       => date('Y-m-d'),
                'sent_at'         => $now,
                'sent_by'         => $userId,
                'sent_to_email'   => $sentToEmail,
                'delivery_method' => 'email',
                'updated_by'      => $userId,
            ], 'id = ? AND status = ?', [$id, 'draft']);
            if ($affected === 0) {
                throw new ActionException('INVALID_TRANSITION',
                    "Invoice {$invoice['invoice_number']} was modified concurrently (no longer draft). Refresh and retry.", 409);
            }

            $balanceDue  = (string) $invoice['balance_due'];
            $totalAmount = (string) $invoice['total_amount'];
            if ($invoice['lease_id']) {
                db_execute("UPDATE leases SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?", [$balanceDue, $invoice['lease_id']]);
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance + ?, total_revenue = total_revenue + ?, updated_at = NOW() WHERE id = ?",
                    [$balanceDue, $totalAmount, $invoice['customer_id']]
                );
            }
            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE equipment_units eu JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                        SET eu.total_revenue = eu.total_revenue + ?, eu.updated_at = NOW()",
                    [$invoice['lease_id'], $totalAmount]
                );
            }

            if ($stampPrechargeAfter) {
                db_execute("UPDATE leases SET precharge_invoiced_at = ?, updated_at = NOW(), updated_by = ? WHERE id = ?", [$now, $userId, $invoice['lease_id']]);
                db_insert('audit_log', [
                    'user_id' => $userId, 'user_name' => $userName, 'action' => 'update', 'module' => 'leases',
                    'entity_type' => 'lease_precharge_invoiced_at_stamp', 'entity_id' => (int) $invoice['lease_id'],
                    'entity_label' => $stampPrechargeLease['contract_number'] ?? null,
                    'notes' => "Lease precharge_invoiced_at stamped on invoice {$invoice['invoice_number']} send (S-MILEAGE-2A D-D).",
                    'old_values' => json_encode(['precharge_invoiced_at' => null]),
                    'new_values' => json_encode(['precharge_invoiced_at' => $now, 'invoice_id' => $id, 'invoice_number' => $invoice['invoice_number']]),
                    'ip_address' => $ip ?? '127.0.0.1',
                ]);
            }

            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName, 'action' => 'status_change', 'module' => 'invoices',
                'entity_type' => 'invoice', 'entity_id' => $id, 'entity_label' => $invoice['invoice_number'],
                'notes' => "Invoice {$invoice['invoice_number']} sent (draft → sent). Counter delta: outstanding_balance += {$balanceDue} (Path B).",
                'old_values' => json_encode(['status' => 'draft']),
                'new_values' => json_encode(['status' => 'sent', 'outstanding_balance_delta' => $balanceDue]),
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            // Revenue JE — posted inside the txn; failure rolls back the send.
            \FleetForge\Accounting\AutoEntryBridge::onInvoiceSent($id, $userId);

            try {
                $companyName = $invoice['company_name_snapshot'] ?? 'customer';
                \FleetForge\Notifications\NotificationService::notify(
                    type: 'invoice.sent', title: "Invoice {$invoice['invoice_number']} sent",
                    message: "Invoice {$invoice['invoice_number']} sent to {$companyName}",
                    entityType: 'invoice', entityId: $id, url: '/fleetforge/invoices/show?id=' . $id
                );
                if (!empty($invoice['customer_id'])) {
                    $amt = '$' . number_format((float) $invoice['total_amount'], 2);
                    \FleetForge\Notifications\NotificationService::notifyPortal(
                        type: 'invoice.sent', customerId: (int) $invoice['customer_id'],
                        title: "Your invoice is ready",
                        message: "Invoice {$invoice['invoice_number']} — {$amt} due " . ($invoice['due_date'] ?? 'on receipt'),
                        entityType: 'invoice', entityId: $id, url: '/fleetforge/portal/invoices/view?id=' . $id
                    );
                }
            } catch (\Throwable $e) {
                error_log('[NOTIF invoice.sent] ' . $e->getMessage());
            }
        });

        \FleetForge\QboPushers\InvoiceEnqueuer::enqueue($id, 'create');

        return ['id' => $id, 'invoice_number' => $invoice['invoice_number'], 'status' => 'sent'];
    }

    /**
     * Void (soft-delete) a payment and reverse all its effects. Mirrors
     * api/v1/payments/delete.php: reverse each allocation (invoice amount_paid /
     * balance_due / status), leases.total_paid, customers.outstanding_balance
     * (Path B status guard), and the payment GL journal entries.
     *
     * @throws ActionException  NOT_FOUND / VALIDATION_ERROR / PERIOD_CLOSED
     * @return array{id:int, payment_number:string, reverted_statuses:array}
     */
    public static function voidPayment(int $id, ?string $reason, int $userId, string $userName, ?string $ip): array
    {
        $reason = $reason !== null ? trim($reason) : '';
        if ($reason === '') {
            throw new ActionException('VALIDATION_ERROR', 'A reason is required to void a payment.', 422);
        }

        $payment = db_row(
            "SELECT id, payment_number, customer_id, amount, currency, status
             FROM payments WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$payment) {
            throw new ActionException('NOT_FOUND', 'Payment not found.', 404);
        }

        // S-AUDIT-BILLING-ENGINE-1 #9: an NSF'd payment ('failed') already had
        // its counters + GL unwound by BankService::processNsf's compensating
        // JE — voiding it on top would reverse everything a SECOND time
        // (OB double-incremented, AR debited twice).
        if (in_array($payment['status'], ['failed', 'returned'], true)) {
            throw new ActionException('INVALID_TRANSITION',
                "Payment {$payment['payment_number']} bounced (NSF) — its effects were already reversed by the NSF entry; it cannot be voided again.", 409);
        }

        // S-AUDIT-BILLING-ENGINE-1 #8: the overpayment excess of this payment
        // lives on as an account-credit note. Voiding the payment reverses the
        // 3-line JE (un-crediting 2060), so the CN must die with it — a live
        // CN would stay spendable with no funding (2060 goes negative on
        // apply). A DRAWN CN blocks the void instead (the customer already
        // spent money that traces to this payment).
        $linkedCns = db_select(
            "SELECT id, credit_note_number, amount, amount_remaining, status
               FROM credit_notes
              WHERE source_payment_id = ? AND status <> 'void' AND voided_at IS NULL AND deleted_at IS NULL",
            [$id]
        );
        foreach ($linkedCns as $cn) {
            if (bccomp((string) $cn['amount_remaining'], (string) $cn['amount'], 2) !== 0
                || in_array($cn['status'], ['partially_used', 'fully_used'], true)) {
                throw new ActionException('CREDIT_NOTE_APPLIED',
                    "Cannot void payment {$payment['payment_number']}: its overpayment credit {$cn['credit_note_number']} has already been applied. Unapply the credit first.", 422);
            }
        }

        $allocations = db_select(
            "SELECT pa.id, pa.invoice_id, pa.amount,
                    i.lease_id, i.invoice_number, i.total_amount, i.credits_applied,
                    i.amount_paid, i.balance_due, i.status AS invoice_status,
                    i.customer_id, i.deleted_at AS invoice_deleted_at
             FROM payment_allocations pa
             JOIN invoices i ON i.id = pa.invoice_id
             WHERE pa.payment_id = ?",
            [$id]
        );

        $revertedStatuses = [];

        db_transaction(function () use ($id, $payment, $allocations, $linkedCns, $reason, $userId, $userName, $ip, &$revertedStatuses): void {
            db_update('payments', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

            // S-AUDIT-BILLING-ENGINE-1 #8: void the (fully-unapplied — blockers
            // refused above) overpayment CNs WITH the payment. Deliberately NO
            // onCreditNoteVoided call: the overpayment CN's 2060 credit lives
            // in the payment's own 3-line JE, whose reversal below un-credits
            // 2060 exactly once — a forfeiture JE on top would double-debit it.
            foreach ($linkedCns as $cn) {
                $affected = db_update('credit_notes', [
                    'status'           => 'void',
                    'amount_remaining' => '0.00',
                    'voided_by'        => $userId,
                    'voided_at'        => date('Y-m-d H:i:s'),
                    'internal_notes'   => "Auto-voided: source payment {$payment['payment_number']} voided (S-AUDIT-BILLING-ENGINE-1 — an overpayment credit lives and dies with its payment).",
                ], 'id = ? AND status = ? AND amount_remaining = ?', [(int) $cn['id'], $cn['status'], $cn['amount_remaining']]);
                if ($affected === 0) {
                    throw new ActionException('CREDIT_NOTE_APPLIED',
                        "Overpayment credit {$cn['credit_note_number']} was applied concurrently; cannot void the payment.", 409);
                }
                db_insert('audit_log', [
                    'user_id' => $userId, 'user_name' => $userName, 'action' => 'status_change',
                    'module' => 'payments', 'entity_type' => 'credit_note', 'entity_id' => (int) $cn['id'],
                    'entity_label' => $cn['credit_note_number'],
                    'old_values' => json_encode(['status' => $cn['status'], 'amount_remaining' => $cn['amount_remaining']]),
                    'new_values' => json_encode(['status' => 'void', 'amount_remaining' => '0.00']),
                    'notes' => "Credit note {$cn['credit_note_number']} auto-voided with payment {$payment['payment_number']} (GL handled by the payment JE reversal).",
                    'ip_address' => $ip ?? '127.0.0.1',
                ]);
            }

            foreach ($allocations as $alloc) {
                $allocated  = (string) $alloc['amount'];
                $amountPaid = (string) $alloc['amount_paid'];
                $credits    = (string) $alloc['credits_applied'];
                $total      = (string) $alloc['total_amount'];

                // Path B status guard: void/written_off/deleted invoices already
                // reversed their counters — skip OB re-INC + invoice update.
                $invoiceTerminal = in_array($alloc['invoice_status'], ['void', 'written_off'], true)
                                || $alloc['invoice_deleted_at'] !== null;

                if ($invoiceTerminal) {
                    $revertedStatuses[] = [
                        'invoice_id' => $alloc['invoice_id'], 'invoice_number' => $alloc['invoice_number'],
                        'old_status' => $alloc['invoice_status'], 'new_status' => $alloc['invoice_status'],
                        'note' => 'skipped — invoice is void/written_off/deleted (Path B status guard)',
                    ];
                    if ($alloc['lease_id']) {
                        db_execute("UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?", [$allocated, $alloc['lease_id']]);
                    }
                    continue;
                }

                $newAmountPaid = bcround(bcsub($amountPaid, $allocated, 6), 2);
                if (bccomp($newAmountPaid, '0', 2) < 0) $newAmountPaid = '0.00';
                $newBalanceDue = bcround(bcsub(bcsub($total, $credits, 6), $newAmountPaid, 6), 2);
                if (bccomp($newBalanceDue, '0', 2) < 0) $newBalanceDue = '0.00';

                $revertedStatus = $alloc['invoice_status'];
                if (in_array($alloc['invoice_status'], ['paid', 'partially_paid'], true)) {
                    if (bccomp($newBalanceDue, '0', 2) === 0) {
                        $revertedStatus = 'paid';
                    } elseif (bccomp($newAmountPaid, '0', 2) > 0 || bccomp($credits, '0', 2) > 0) {
                        $revertedStatus = 'partially_paid';
                    } else {
                        $revertedStatus = 'sent';
                    }
                }

                $invoiceUpdates = [
                    'amount_paid' => $newAmountPaid,
                    'balance_due' => $newBalanceDue,
                    'status'      => $revertedStatus,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ];
                if ($revertedStatus !== 'paid') {
                    $invoiceUpdates['paid_date'] = null;
                }
                db_update('invoices', $invoiceUpdates, 'id = ?', [$alloc['invoice_id']]);

                if ($revertedStatus !== $alloc['invoice_status']) {
                    $revertedStatuses[] = [
                        'invoice_id' => $alloc['invoice_id'], 'invoice_number' => $alloc['invoice_number'],
                        'old_status' => $alloc['invoice_status'], 'new_status' => $revertedStatus,
                    ];
                }

                if ($alloc['lease_id']) {
                    db_execute("UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?", [$allocated, $alloc['lease_id']]);
                }
                if ($alloc['customer_id']) {
                    db_execute("UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?", [$allocated, $alloc['customer_id']]);
                }
            }

            // Reverse the payment GL journal entries. A closed-period failure
            // aborts the whole void (never a counter reversal without the GL one).
            $paymentJes = db_select(
                "SELECT id FROM acc_journal_entries WHERE source_type = 'payment' AND source_id = ? AND status = 'posted'",
                [$id]
            );
            foreach ($paymentJes as $je) {
                try {
                    \FleetForge\Accounting\JournalEntryService::reverse((int) $je['id'], null, $userId);
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'already been reversed') !== false) {
                        continue;
                    }
                    // Closed period (or no open period) → clean 409 instead of a 500.
                    throw new ActionException('PERIOD_CLOSED',
                        'Cannot void: the payment journal entry cannot be reversed (the accounting period is likely closed). ' . $e->getMessage(), 409);
                }
            }

            $invoiceList = implode(', ', array_column($allocations, 'invoice_number'));
            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName, 'action' => 'delete', 'module' => 'payments',
                'entity_type' => 'payment', 'entity_id' => $id, 'entity_label' => $payment['payment_number'],
                'notes' => "Payment {$payment['payment_number']} soft-deleted. Reason: {$reason}. Reversed allocations for: {$invoiceList}.",
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            try {
                $companyName = '';
                if (!empty($payment['customer_id'])) {
                    $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$payment['customer_id']]);
                    $companyName = $cust['company_name'] ?? '';
                }
                \FleetForge\Notifications\NotificationService::notify(
                    type: 'payment.reversed', title: "Payment {$payment['payment_number']} reversed",
                    message: "Payment {$payment['payment_number']} reversed" . ($companyName ? " for {$companyName}" : '') . ". Reason: {$reason}",
                    entityType: 'payment', entityId: $id, url: '/fleetforge/payments', severity: 'warning'
                );
            } catch (\Throwable $e) {
                error_log('[NOTIF payment.reversed] ' . $e->getMessage());
            }
        });

        // Best-effort QBO void enqueue AFTER commit (soft-delete IS the void signal).
        \FleetForge\QboPushers\PaymentEnqueuer::enqueue($id, 'void');
        // S-AUDIT-BILLING-ENGINE-1 #8: mirror the auto-voided overpayment CNs.
        foreach ($linkedCns as $cn) {
            \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cn['id'], 'void');
        }
        if (function_exists('invalidate_dashboard_cache')) {
            invalidate_dashboard_cache();
        }

        return ['id' => $id, 'payment_number' => $payment['payment_number'], 'reverted_statuses' => $revertedStatuses];
    }
}
