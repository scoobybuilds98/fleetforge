<?php
declare(strict_types=1);

/**
 * lib/Accounting/InvoiceWriteOff.php
 *
 * Writes an invoice's remaining balance off as bad debt — the ONE path both
 * FF write-offs go through (S-QBO-INVOICE-WRITEOFF):
 *   - api/v1/accounting/ar/bad_debt_writeoff.php (AR bad debt);
 *   - api/v1/damage_claims/update.php when a claim moves to 'written_off'
 *     (the claim's recovery invoice).
 *
 * WHY THIS EXISTS: the damage path used to post only the GL entry (DR Bad
 * Debt / CR AR) and leave the FF invoice open with its full balance — so FF's
 * own aging, dunning and customers.outstanding_balance still treated written-
 * off money as owed — and neither path reached QuickBooks as anything but a
 * JournalEntry (or not at all). Now every write-off:
 *   1. posts the GL entry (AutoEntryBridge — damage_writeoff tagged to the
 *      claim, or the AR bad-debt entry), BEFORE the invoice changes, because
 *      both bridge methods read the live balance and skip an invoice that is
 *      already written off;
 *   2. records an acc_bad_debt_writeoffs row (the entity
 *      InvoiceWriteoffPusher pushes to QuickBooks as a CreditMemo applied to
 *      the invoice);
 *   3. closes the invoice: status 'written_off', balance_due 0, reason /
 *      by / at stamped;
 *   4. takes the amount off customers.outstanding_balance (D45 — only SENT
 *      invoices are in it, which every writable status is).
 *
 * The caller owns the transaction and enqueues the QuickBooks push after it
 * commits (InvoiceWriteoffEnqueuer::enqueue($writeoffId, 'create')).
 *
 * @session S-QBO-INVOICE-WRITEOFF
 * @spec    FLEETFORGE_ACCOUNTING_SPEC.md §5 (bad-debt write-off), §23.11 (damage claims)
 */

namespace FleetForge\Accounting;

final class InvoiceWriteOff
{
    /** Invoice statuses that can be written off (issued, money still owed). */
    public const WRITABLE_STATUSES = ['sent', 'overdue', 'partially_paid'];

    /**
     * Write off the invoice's remaining balance. Caller wraps in db_transaction.
     *
     * @param int|null $damageClaimId set when a damage claim is being written off
     * @return array{writeoff_id:int, invoice_number:string, amount:string, journal_entry_id:?int}
     * @throws \InvalidArgumentException when the invoice does not exist
     * @throws \DomainException          when it is not writable (status / no balance)
     */
    public static function writeOff(int $invoiceId, string $reason, ?int $userId, ?int $damageClaimId = null): array
    {
        // Lock invoice row to prevent concurrent modification (D20)
        $invoice = \db_row(
            "SELECT id, invoice_number, customer_id, balance_due, status
               FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
            [$invoiceId]
        );
        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found.');
        }
        if (!in_array($invoice['status'], self::WRITABLE_STATUSES, true)) {
            throw new \DomainException("Cannot write off invoice {$invoice['invoice_number']} with status '{$invoice['status']}'. Must be sent, overdue, or partially_paid.");
        }
        $amount = bcadd((string) $invoice['balance_due'], '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException("Invoice {$invoice['invoice_number']} has no balance to write off.");
        }

        // 1. GL first — both bridge methods read the open balance.
        $je = $damageClaimId !== null
            ? AutoEntryBridge::onDamageWrittenOff($damageClaimId, $userId)
            : AutoEntryBridge::onBadDebtWriteOff($invoiceId, $amount, $userId);

        // 2. The write-off record (what QuickBooks receives).
        $writeoffId = \db_insert('acc_bad_debt_writeoffs', [
            'invoice_id'       => $invoiceId,
            'customer_id'      => (int) $invoice['customer_id'],
            'damage_claim_id'  => $damageClaimId,
            'writeoff_date'    => \ff_today(),
            'amount'           => $amount,
            'reason'           => $reason,
            'journal_entry_id' => $je['id'] ?? null,
            'created_by'       => $userId,
        ]);

        // 3. Close the invoice.
        \db_update('invoices', [
            'status'           => 'written_off',
            'balance_due'      => '0.00',
            'write_off_reason' => mb_substr($reason, 0, 1000),
            'written_off_by'   => $userId,
            'written_off_at'   => \ff_now_utc(),   // S-UTC-STAMPS
            'updated_by'       => $userId,
        ], 'id = ?', [$invoiceId]);

        // 4. Customer outstanding_balance counter (D45 / Path B).
        \db_execute(
            "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id = ?",
            [$amount, (int) $invoice['customer_id']]
        );

        return [
            'writeoff_id'      => (int) $writeoffId,
            'invoice_number'   => (string) $invoice['invoice_number'],
            'amount'           => $amount,
            'journal_entry_id' => isset($je['id']) ? (int) $je['id'] : null,
        ];
    }
}
