<?php
declare(strict_types=1);

/**
 * lib/Accounting/ApPaymentService.php
 *
 * The books of an AP (bill) payment, shared by every path that records or
 * voids one:
 *   - api/v1/accounting/ap-payments/create.php + void.php (an operator on
 *     the Bill Payments page);
 *   - lib/QboPushers/BillPaymentWebhookHandler.php (a bill the accountant
 *     paid in QuickBooks, mirrored into FleetForge — S-QBO-BILLPAY-MIRROR).
 *
 * WHY THIS EXISTS: the JE shape, the bill balance/status update and the
 * void reversal used to live inline in the two endpoints. The QuickBooks
 * mirror has to produce exactly the same books (DR AP / CR bank, bill
 * amount_paid / balance_due / status), so the logic moved here instead of
 * being copied a third time. Endpoint behaviour is unchanged.
 *
 * Callers own the transaction: every method assumes it runs inside
 * db_transaction() with the bill / payment rows already locked where the
 * caller needs read-then-write safety (D20).
 *
 * @session  S-QBO-BILLPAY-MIRROR (extracted from S032 create.php / void.php)
 * @spec     FLEETFORGE_ACCOUNTING_SPEC.md §6 (AP payment JE)
 */

namespace FleetForge\Accounting;

final class ApPaymentService
{
    /**
     * Post the AP payment JE: DR AP (accounting.ap_account_id) / CR the bank
     * account's GL account, both lines tagged with the vendor.
     *
     * @return array The created (posted) journal entry row
     * @throws \RuntimeException when the AP account is not configured or the JE is refused
     */
    public static function postPaymentJournalEntry(
        string $paymentNumber,
        string $paymentDate,
        int $vendorId,
        string $vendorName,
        int $bankGlAccountId,
        string $amount,
        ?int $userId
    ): array {
        $apAccountId = AccountingService::setting('accounting.ap_account_id');
        if (!$apAccountId) {
            throw new \RuntimeException('AP account not configured.');
        }

        $jeLines = [
            [
                'account_id'  => (int) $apAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "AP payment {$paymentNumber} — {$vendorName}",
                'vendor_id'   => $vendorId,
            ],
            [
                'account_id'  => $bankGlAccountId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "Cash — AP payment {$paymentNumber}",
                'vendor_id'   => $vendorId,
            ],
        ];

        return JournalEntryService::create([
            'entry_date'       => $paymentDate,
            'description'      => "AP Payment {$paymentNumber} — {$vendorName}",
            'entry_type'       => 'system',
            'reference'        => $paymentNumber,
            'source_type'      => 'ap_payment',
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    /**
     * Allocate part of a payment to one bill: the allocation row plus the
     * bill's amount_paid / balance_due / status. The caller has already
     * locked the bill (FOR UPDATE) and checked the amount fits its balance.
     */
    public static function applyToBill(int $apPaymentId, int $billId, string $amount): void
    {
        \db_insert('acc_ap_payment_allocations', [
            'ap_payment_id'  => $apPaymentId,
            'bill_id'        => $billId,
            'amount_applied' => $amount,
        ]);

        // WHY: MySQL SET evaluates left-to-right, so balance_due is already
        // subtracted when the CASE runs — compare to 0, not subtract again.
        \db_execute(
            "UPDATE acc_bills SET
                amount_paid = amount_paid + ?,
                balance_due = balance_due - ?,
                status = CASE
                    WHEN balance_due <= 0 THEN 'paid'
                    ELSE 'partially_paid'
                END
             WHERE id = ?",
            [$amount, $amount, $billId]
        );
    }

    /**
     * Void an AP payment: reverse its JE, give every allocated bill its
     * money back (status → partially_paid if other payments remain, else
     * approved), mark the payment void and write the audit row.
     *
     * Does NOT enqueue a QuickBooks void — the endpoint does that after its
     * transaction commits; the QuickBooks mirror must not (QuickBooks voided
     * it first).
     *
     * @return array{id:int, payment_number:string, status:string}
     * @throws \InvalidArgumentException when the payment does not exist
     * @throws \DomainException          when the payment is already void
     */
    public static function void(int $id, string $voidReason, ?int $userId, string $ipAddress, ?string $userName = null): array
    {
        $payment = \db_row("SELECT * FROM acc_ap_payments WHERE id = ? FOR UPDATE", [$id]);
        if (!$payment) {
            throw new \InvalidArgumentException('AP payment not found.');
        }
        if ($payment['status'] === 'void') {
            throw new \DomainException('Payment is already void.');
        }

        // Reverse the JE
        if ($payment['journal_entry_id']) {
            JournalEntryService::reverse((int) $payment['journal_entry_id'], date('Y-m-d'), $userId);
        }

        // Restore bill balances from allocations
        $allocations = \db_select(
            "SELECT bill_id, amount_applied FROM acc_ap_payment_allocations WHERE ap_payment_id = ?",
            [$id]
        );

        foreach ($allocations as $alloc) {
            // Lock bill row and restore balance (FOR UPDATE — D20)
            $bill = \db_row("SELECT id, status, amount_paid, balance_due, total_amount FROM acc_bills WHERE id = ? FOR UPDATE", [(int) $alloc['bill_id']]);
            if ($bill) {
                $newAmountPaid = bcsub((string) $bill['amount_paid'], (string) $alloc['amount_applied'], 2);
                $newBalance = bcadd((string) $bill['balance_due'], (string) $alloc['amount_applied'], 2);

                // Determine new status
                $newStatus = 'approved';
                if (bccomp($newAmountPaid, '0.00', 2) > 0) {
                    $newStatus = 'partially_paid';
                }

                \db_update('acc_bills', [
                    'amount_paid' => $newAmountPaid,
                    'balance_due' => $newBalance,
                    'status'      => $newStatus,
                ], 'id = ?', [(int) $alloc['bill_id']]);
            }
        }

        // Void the payment
        \db_update('acc_ap_payments', [
            'status'      => 'void',
            'void_reason' => $voidReason,
            'voided_by'   => $userId,
            // S-UTC-STAMPS: voided_at is a UTC DATETIME (audit stamp, not a posting date).
            'voided_at'   => \ff_now_utc(),
        ], 'id = ?', [$id]);

        $audit = [
            'user_id'     => $userId,
            'action'      => 'status_change',
            'module'      => 'accounting',
            'entity_type' => 'ap_payment',
            'entity_id'   => $id,
            'notes'       => "AP Payment {$payment['payment_number']} voided: {$voidReason}",
            'old_values'  => json_encode(['status' => $payment['status']]),
            'new_values'  => json_encode(['status' => 'void']),
            'ip_address'  => $ipAddress,
        ];
        if ($userName !== null) {
            $audit['user_name'] = $userName;
        }
        \db_insert('audit_log', $audit);

        return ['id' => $id, 'payment_number' => (string) $payment['payment_number'], 'status' => 'void'];
    }
}
