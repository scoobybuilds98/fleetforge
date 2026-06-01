<?php
declare(strict_types=1);

/**
 * lib/CreditApplicationReversal.php
 *
 * The exact inverse of api/v1/credit_notes/apply.php — un-applies a single
 * credit_note_applications row, restoring the 5 counters apply.php moved
 * (F27 / S-QBO-CREDIT-APP-UNAPPLY). Extracted into a service (not inlined in
 * the endpoint like apply.php) so the apply→reverse counter round-trip is
 * smoke-verifiable and apply.php stays untouched (D-QBO-UNAPPLY-2).
 *
 * Reverses, in ONE db_transaction with FOR UPDATE on the credit note + invoice
 * (D20 lock ordering — credit note first, then invoice, matching apply.php):
 *   1. credit_notes.amount_remaining  (incremented back, capped at amount)
 *   2. credit_notes.status            (→ active / partially_used)
 *   3. invoices.credits_applied       (decremented)
 *   4. invoices.balance_due           (recalculated from source)
 *   5. invoices.status                (paid → partially_paid → sent/overdue)
 *   6. customers.outstanding_balance  (incremented back)
 *   + marks the application status='reversed' (append-only — row kept)
 *   + posts the reversing GL JE via AutoEntryBridge::onCreditNoteUnapplied
 *
 * Edge: if the parent credit note was VOIDED after this application, its
 * amount_remaining/status are terminal — we leave the credit untouched but
 * STILL reverse the invoice + customer counters (the AR reduction was real and
 * must be restored). Flagged in the result as credit_untouched_voided.
 *
 * @session  S-QBO-CREDIT-APP-UNAPPLY
 * @closes   F27
 */

namespace FleetForge;

class CreditApplicationReversal
{
    /** Thrown for invalid reversal state (caller maps to HTTP code). */
    public const ERR_NOT_FOUND        = 'application_not_found';
    public const ERR_ALREADY_REVERSED = 'already_reversed';

    /**
     * Reverse one credit_note_applications row. Runs in its own transaction.
     *
     * @param  int      $applicationId  credit_note_applications.id
     * @param  int|null $userId         who is reversing
     * @return array    summary of the reversal
     * @throws \RuntimeException with message === one of the ERR_* codes
     */
    public static function reverse(int $applicationId, ?int $userId = null): array
    {
        $result = null;

        db_transaction(function () use ($applicationId, $userId, &$result) {
            // Lock the application row first.
            $app = db_row(
                "SELECT id, credit_note_id, invoice_id, amount_applied, status
                   FROM credit_note_applications WHERE id = ? FOR UPDATE",
                [$applicationId]
            );
            if ($app === null) {
                throw new \RuntimeException(self::ERR_NOT_FOUND);
            }
            if ($app['status'] === 'reversed') {
                throw new \RuntimeException(self::ERR_ALREADY_REVERSED);
            }

            $creditNoteId = (int) $app['credit_note_id'];
            $invoiceId    = (int) $app['invoice_id'];
            $amountApplied = bcround((string) $app['amount_applied'], 2);

            // Lock credit note (first), then invoice — apply.php's lock order.
            $cn = db_row(
                "SELECT id, credit_note_number, customer_id, amount, amount_remaining, status
                   FROM credit_notes WHERE id = ? FOR UPDATE",
                [$creditNoteId]
            );
            $invoice = db_row(
                "SELECT id, invoice_number, customer_id, total_amount, amount_paid,
                        credits_applied, balance_due, status, due_date
                   FROM invoices WHERE id = ? FOR UPDATE",
                [$invoiceId]
            );
            if ($cn === null || $invoice === null) {
                throw new \RuntimeException(self::ERR_NOT_FOUND);
            }

            $creditUntouchedVoided = false;

            // ── 1+2. Restore credit note remaining + status (unless voided) ──
            if ($cn['status'] !== 'void') {
                $cnAmount    = bcround((string) $cn['amount'], 2);
                $newRemaining = bcround(bcadd((string) $cn['amount_remaining'], $amountApplied, 6), 2);
                // Cap at the original amount (never restore more than existed).
                if (bccomp($newRemaining, $cnAmount, 2) > 0) {
                    $newRemaining = $cnAmount;
                }
                if (bccomp($newRemaining, '0', 2) === 0) {
                    $newCnStatus = 'fully_used';
                } elseif (bccomp($newRemaining, $cnAmount, 2) >= 0) {
                    $newCnStatus = 'active';
                } else {
                    $newCnStatus = 'partially_used';
                }
                db_update('credit_notes', [
                    'amount_remaining' => $newRemaining,
                    'status'           => $newCnStatus,
                    'updated_at'       => date('Y-m-d H:i:s'),
                ], 'id = ?', [$creditNoteId]);
            } else {
                $creditUntouchedVoided = true;
            }

            // ── 3+4+5. Restore invoice counters + status ─────────────────────
            $newCreditsApplied = bcround(bcsub((string) $invoice['credits_applied'], $amountApplied, 6), 2);
            if (bccomp($newCreditsApplied, '0', 2) < 0) {
                $newCreditsApplied = '0.00';
            }
            $newBalanceDue = bcround(
                bcsub(bcsub((string) $invoice['total_amount'], $newCreditsApplied, 6), (string) $invoice['amount_paid'], 6),
                2
            );
            if (bccomp($newBalanceDue, '0', 2) < 0) {
                $newBalanceDue = '0.00';
            }

            // Status derivation (inverse of apply.php). Fully-open + past-due → overdue.
            if (bccomp($newBalanceDue, '0', 2) === 0) {
                $newInvoiceStatus = 'paid';
            } elseif (bccomp((string) $invoice['amount_paid'], '0', 2) > 0 || bccomp($newCreditsApplied, '0', 2) > 0) {
                $newInvoiceStatus = 'partially_paid';
            } else {
                $pastDue = !empty($invoice['due_date']) && strtotime((string) $invoice['due_date']) < strtotime(date('Y-m-d'));
                $newInvoiceStatus = $pastDue ? 'overdue' : 'sent';
            }

            $invUpdates = [
                'credits_applied' => $newCreditsApplied,
                'balance_due'     => $newBalanceDue,
                'status'          => $newInvoiceStatus,
                'updated_at'      => date('Y-m-d H:i:s'),
            ];
            // Clear paid_date when the invoice is no longer fully paid.
            if ($newInvoiceStatus !== 'paid') {
                $invUpdates['paid_date'] = null;
            }
            db_update('invoices', $invUpdates, 'id = ?', [$invoiceId]);

            // ── 6. Restore customer outstanding balance ─────────────────────
            db_execute(
                "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
                [$amountApplied, (int) $invoice['customer_id']]
            );

            // ── Mark the application reversed (append-only) ─────────────────
            $now = date('Y-m-d H:i:s');
            db_update('credit_note_applications', [
                'status'      => 'reversed',
                'reversed_at' => $now,
                'reversed_by' => $userId,
            ], 'id = ?', [$applicationId]);

            // ── Audit + reversing GL JE ─────────────────────────────────────
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => current_user()['name'] ?? 'System',
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'credit_note_application_reversal',
                'entity_id'    => $applicationId,
                'entity_label' => $cn['credit_note_number'] . '↩' . $invoice['invoice_number'],
                'notes'        => "Credit application #{$applicationId} reversed: {$cn['credit_note_number']} un-applied from {$invoice['invoice_number']} (amount {$amountApplied})."
                                  . ($creditUntouchedVoided ? ' Parent credit was voided — credit balance left terminal; invoice/customer counters restored.' : ''),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            \FleetForge\Accounting\AutoEntryBridge::onCreditNoteUnapplied(
                $creditNoteId,
                $invoiceId,
                $amountApplied,
                $userId
            );

            $result = [
                'application_id'         => $applicationId,
                'credit_note_id'         => $creditNoteId,
                'credit_note_number'     => $cn['credit_note_number'],
                'invoice_id'             => $invoiceId,
                'invoice_number'         => $invoice['invoice_number'],
                'invoice_status'         => $newInvoiceStatus,
                'invoice_balance_due'    => $newBalanceDue,
                'amount_restored'        => $amountApplied,
                'credit_untouched_voided' => $creditUntouchedVoided,
            ];
        });

        return $result;
    }
}
