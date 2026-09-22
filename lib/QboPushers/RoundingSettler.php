<?php
declare(strict_types=1);

/**
 * lib/QboPushers/RoundingSettler.php
 *
 * Closes the last few cents FleetForge and QuickBooks disagree on
 * (S-QBO-GOLIVE-AUDIT, operator decision 2026-09-23).
 *
 * WHY: FF rounds sales tax per line; QuickBooks rounds once per invoice. On
 * the production copy one invoice in five differs by $0.01 from the copy the
 * accountant typed into QuickBooks. When the customer pays QuickBooks' total
 * (pay link, or the accountant records the cheque), FF mirrors that payment
 * and is left with a $0.01 balance that nobody will ever pay — the invoice
 * sits "partially paid" and ages forever.
 *
 * When QuickBooks shows the invoice PAID IN FULL and FF is short by at most
 * InvoiceLinker::AMOUNT_TOLERANCE, the residual is settled with an FF
 * adjustment credit note applied to the invoice — the same books
 * credit_notes/create.php + apply.php produce (DR revenue / CR customer
 * credits, then DR customer credits / CR A/R), so FF's revenue and A/R end
 * up exactly where QuickBooks has them. The credit note is marked
 * [qbo-rounding] and is never sent to QuickBooks: QuickBooks already carries
 * the lower figure (CreditMemoEnqueuer / CreditMemoPusher /
 * CreditApplicationEnqueuer refuse it).
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Accounting\AutoEntryBridge;

class RoundingSettler
{
    /** internal_notes prefix that keeps the adjustment out of QuickBooks. */
    public const MARKER = '[qbo-rounding]';

    public static function isRoundingNote(?string $internalNotes): bool
    {
        return str_starts_with((string) $internalNotes, self::MARKER);
    }

    /**
     * Settle $ffInvoiceId's residual when QuickBooks has it paid.
     *
     * @param array|null $qboInvoice the QBO Invoice when the caller already has it
     * @return array{settled: bool, reason?: string, amount?: string, credit_note?: string}
     */
    public static function settleIfQboPaid(int $ffInvoiceId, ?array $qboInvoice = null, ?QuickBooksClient $client = null): array
    {
        $inv = db_row("SELECT status, balance_due FROM invoices WHERE id = ? AND deleted_at IS NULL", [$ffInvoiceId]);
        if (!$inv || !in_array($inv['status'], ['sent', 'partially_paid', 'overdue'], true)) {
            return ['settled' => false, 'reason' => 'not open'];
        }
        $residual = bcadd((string) $inv['balance_due'], '0', 2);
        if (bccomp($residual, '0', 2) <= 0 || bccomp($residual, InvoiceLinker::AMOUNT_TOLERANCE, 2) > 0) {
            return ['settled' => false, 'reason' => 'balance is not a rounding residual'];
        }
        if ($qboInvoice === null) {
            $map = db_row("SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ? AND qbo_invoice_id IS NOT NULL", [$ffInvoiceId]);
            if (!$map) {
                return ['settled' => false, 'reason' => 'not in QuickBooks'];
            }
            try {
                $client ??= new QuickBooksClient();
                $qboInvoice = $client->getEntity('invoice', (string) $map['qbo_invoice_id'],
                    ['entity_type' => 'invoice', 'entity_id' => $ffInvoiceId, 'operation' => 'rounding_check'])['Invoice'] ?? null;
            } catch (\Throwable $e) {
                return ['settled' => false, 'reason' => 'QuickBooks read failed: ' . $e->getMessage()];
            }
        }
        if (!is_array($qboInvoice) || !isset($qboInvoice['Balance'])
            || bccomp(bcadd((string) $qboInvoice['Balance'], '0', 2), '0', 2) !== 0) {
            return ['settled' => false, 'reason' => 'QuickBooks does not show it paid in full'];
        }
        $qboTotal = bcadd((string) ($qboInvoice['TotalAmt'] ?? '0'), '0', 2);

        return db_transaction(function () use ($ffInvoiceId, $qboTotal): array {
            $invoice = db_row(
                "SELECT i.id, i.invoice_number, i.customer_id, i.lease_id, i.currency, i.status, i.total_amount,
                        i.amount_paid, i.credits_applied, i.balance_due, i.exchange_rate_to_cad,
                        c.company_name, c.contact_name, c.billing_address, c.province, c.email
                   FROM invoices i JOIN customers c ON c.id = i.customer_id
                  WHERE i.id = ? AND i.deleted_at IS NULL FOR UPDATE",
                [$ffInvoiceId]
            );
            $amount = bcadd((string) $invoice['balance_due'], '0', 2);
            if (!in_array($invoice['status'], ['sent', 'partially_paid', 'overdue'], true)
                || bccomp($amount, '0', 2) <= 0 || bccomp($amount, InvoiceLinker::AMOUNT_TOLERANCE, 2) > 0) {
                return ['settled' => false, 'reason' => 'changed meanwhile'];
            }

            // 1. The adjustment credit note (credit_notes/create.php shape).
            $number = ff_next_credit_note_number();
            $cnId = db_insert('credit_notes', [
                'credit_note_number'       => $number,
                'company_name_snapshot'    => $invoice['company_name'] ?? null,
                'customer_name_snapshot'   => $invoice['contact_name'] ?? null,
                'billing_address_snapshot' => $invoice['billing_address'] ?? null,
                'province_snapshot'        => $invoice['province'] ?? null,
                'customer_email_snapshot'  => $invoice['email'] ?? null,
                'customer_id'              => (int) $invoice['customer_id'],
                'lease_id'                 => $invoice['lease_id'] ?: null,
                'source'                   => 'invoice_adjustment',
                'source_invoice_id'        => $ffInvoiceId,
                'amount'                   => $amount,
                'currency'                 => $invoice['currency'],
                'exchange_rate_to_cad'     => $invoice['currency'] === 'USD' ? $invoice['exchange_rate_to_cad'] : null,
                'amount_remaining'         => '0.00',
                'status'                   => 'fully_used',
                'reason'                   => "Rounding difference: QuickBooks has invoice {$invoice['invoice_number']} paid in full at {$qboTotal}; FleetForge's total was {$invoice['total_amount']} (tax rounded per line).",
                'internal_notes'           => self::MARKER . ' auto-settled to match QuickBooks — never pushed to QuickBooks.',
                'created_by'               => null,
            ]);
            AutoEntryBridge::onCreditNoteIssued($cnId, null);

            // 2. Apply it (credit_notes/apply.php shape).
            $appId = db_insert('credit_note_applications', [
                'credit_note_id' => $cnId,
                'invoice_id'     => $ffInvoiceId,
                'amount_applied' => $amount,
                'applied_by'     => null,
                'applied_at'     => ff_now_utc(),
            ]);
            $newCredits = bcadd((string) $invoice['credits_applied'], $amount, 2);
            db_update('invoices', [
                'credits_applied' => $newCredits,
                'balance_due'     => '0.00',
                'status'          => 'paid',
                'paid_date'       => ff_today(),
                'updated_at'      => ff_now_utc(),
            ], 'id = ?', [$ffInvoiceId]);
            db_execute(
                "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
                [$amount, (int) $invoice['customer_id']]
            );
            AutoEntryBridge::onCreditNoteApplied($cnId, $ffInvoiceId, $amount, null);

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'QuickBooks',
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'credit_note_application',
                'entity_id'    => $appId,
                'entity_label' => $number . '→' . $invoice['invoice_number'],
                'notes'        => "Rounding residual {$invoice['currency']} {$amount} settled with {$number}: QuickBooks shows {$invoice['invoice_number']} paid in full ({$qboTotal}).",
                'ip_address'   => '127.0.0.1',
            ]);
            return ['settled' => true, 'amount' => $amount, 'credit_note' => $number];
        });
    }
}
