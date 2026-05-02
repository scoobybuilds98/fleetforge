<?php
declare(strict_types=1);

/**
 * lib/Accounting/AutoEntryBridge.php
 *
 * The critical integration layer between FleetForge billing and the accounting GL.
 * Every financial event (invoice send, payment, credit note, void) calls a method
 * here to post the corresponding journal entry automatically.
 *
 * Required by: api/v1/invoices/send.php, void.php; api/v1/payments/create.php;
 *              api/v1/credit_notes/create.php, apply.php
 * Depends on: AccountingService (settings, periods, balances)
 *             JournalEntryService (create/post/reverse)
 *
 * Decisions: A8 (JE backbone — nothing bypasses GL), A9 (AR reconciliation)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5, §16 (Automatic Journal Entry Rules)
 *
 * IMPORTANT: Every public method here MUST be called INSIDE the same db_transaction()
 * as the triggering billing event. If the JE fails, the billing event rolls back.
 * If accounting is disabled, all methods silently return null.
 */

namespace FleetForge\Accounting;

class AutoEntryBridge
{
    // ============================================================
    // GUARD — skip if accounting module is disabled
    // ============================================================

    /**
     * Check if the accounting module is enabled.
     * WHY: When accounting.enabled = false, bridge calls are no-ops.
     * This lets the system run without accounting configured.
     *
     * @return bool
     */
    private static function isEnabled(): bool
    {
        return (bool) AccountingService::setting('accounting.enabled', false);
    }

    // ============================================================
    // INVOICE SENT — DR 1030 AR / CR 4xxx Revenue / CR 2030 GST / CR 2040 PST
    // Spec ref: §5, §16 Rule: "Invoice status → sent"
    // ============================================================

    /**
     * Post JE when an invoice transitions to 'sent'.
     *
     * Line items are grouped by revenue account to minimize JE lines.
     * Credit line items (is_credit=1) produce DEBIT revenue lines (PASS-6:G3).
     * Tax lines (GST, PST, HST) are separate CR lines.
     * Total DR to 1030 AR always equals invoice.total_amount.
     *
     * @param int      $invoiceId   The invoice that was just sent
     * @param int|null $userId      User who triggered the send
     * @return array|null           The posted JE row, or null if accounting disabled
     * @throws \RuntimeException    If GL accounts are not mapped
     */
    public static function onInvoiceSent(int $invoiceId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        // Fetch the invoice
        $invoice = \db_row(
            "SELECT * FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) {
            throw new \RuntimeException("AutoEntryBridge: Invoice {$invoiceId} not found.");
        }

        // Fetch line items to determine revenue account mapping
        $lineItems = \db_select(
            "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order",
            [$invoiceId]
        );

        // Build revenue lines grouped by GL account
        $revenueByAccount = [];
        foreach ($lineItems as $line) {
            $accountId = self::resolveRevenueAccount($line['item_type']);
            if (!$accountId) {
                throw new \RuntimeException(
                    "Cannot post invoice JE — no GL revenue account mapped for line type '{$line['item_type']}'. " .
                    "Go to Accounting → Settings → Revenue Mapping to configure."
                );
            }

            $amount = (string) $line['amount'];

            if (!isset($revenueByAccount[$accountId])) {
                $revenueByAccount[$accountId] = '0.00';
            }

            if ($line['is_credit']) {
                // PASS-6:G3: Credit line items REDUCE revenue (debit side),
                // but we track net per account. Negative means this account
                // will be a debit line instead of credit.
                $revenueByAccount[$accountId] = bcsub($revenueByAccount[$accountId], $amount, 2);
            } else {
                $revenueByAccount[$accountId] = bcadd($revenueByAccount[$accountId], $amount, 2);
            }
        }

        // Build JE lines
        $jeLines = [];

        // Line 1: DR 1030 Accounts Receivable for total_amount
        $arAccountId = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');
        $jeLines[] = [
            'account_id'  => $arAccountId,
            'debit'       => (string) $invoice['total_amount'],
            'credit'      => '0.00',
            'description' => "AR — Invoice {$invoice['invoice_number']}",
            'customer_id' => $invoice['customer_id'],
        ];

        // Revenue lines: CR each revenue account (or DR if net is negative from credits)
        foreach ($revenueByAccount as $accountId => $netAmount) {
            if (bccomp($netAmount, '0', 2) > 0) {
                // Normal: credit revenue
                $jeLines[] = [
                    'account_id'  => $accountId,
                    'debit'       => '0.00',
                    'credit'      => $netAmount,
                    'description' => "Revenue — Invoice {$invoice['invoice_number']}",
                    'customer_id' => $invoice['customer_id'],
                ];
            } elseif (bccomp($netAmount, '0', 2) < 0) {
                // PASS-6:G3: Net negative = credit line items exceeded charges for this account
                // Post as a debit (revenue reduction)
                $jeLines[] = [
                    'account_id'  => $accountId,
                    'debit'       => bcmul($netAmount, '-1', 2),
                    'credit'      => '0.00',
                    'description' => "Revenue credit — Invoice {$invoice['invoice_number']}",
                    'customer_id' => $invoice['customer_id'],
                ];
            }
            // Skip zero-net accounts
        }

        // Tax lines
        $gstHst = bcadd((string) $invoice['tax_gst_amount'], (string) $invoice['tax_hst_amount'], 2);
        if (bccomp($gstHst, '0', 2) > 0) {
            $gstAccountId = self::requireAccountId('accounting.gst_payable_account_id', 'GST/HST Payable');
            $jeLines[] = [
                'account_id'  => $gstAccountId,
                'debit'       => '0.00',
                'credit'      => $gstHst,
                'description' => "GST/HST — Invoice {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ];
        }

        $pst = (string) $invoice['tax_pst_amount'];
        if (bccomp($pst, '0', 2) > 0) {
            $pstAccountId = self::requireAccountId('accounting.pst_payable_account_id', 'PST Payable');
            $jeLines[] = [
                'account_id'  => $pstAccountId,
                'debit'       => '0.00',
                'credit'      => $pst,
                'description' => "PST — Invoice {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ];
        }

        // Resolve period — use invoice_date, fall back to earliest open if closed
        $entryDate = $invoice['sent_date'] ?? $invoice['invoice_date'];
        $periodInfo = self::resolvePeriod($entryDate);

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Invoice {$invoice['invoice_number']} sent — {$invoice['company_name_snapshot']}",
            'entry_type'       => 'system',
            'reference'        => $invoice['invoice_number'],
            'source_type'      => 'invoice',
            'source_id'        => $invoiceId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // INVOICE VOIDED — Reverse the original invoice JE
    // Spec ref: §16 Rule: "Invoice voided"
    // ============================================================

    /**
     * Post reversal JE when an invoice is voided.
     * Only reverses if an original JE exists for this invoice.
     *
     * @param int      $invoiceId
     * @param int|null $userId
     * @return array|null  The reversal JE row, or null
     */
    public static function onInvoiceVoided(int $invoiceId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        // Find the original JE for this invoice
        $originalJe = \db_row(
            "SELECT id, status, reversed_by_id FROM acc_journal_entries
             WHERE source_type = 'invoice' AND source_id = ? AND status = 'posted'
             AND is_reversal = 0
             ORDER BY id DESC LIMIT 1",
            [$invoiceId]
        );

        // No JE to reverse (invoice was draft-only, never sent, or accounting was disabled at send time)
        if (!$originalJe) return null;

        // Already reversed
        if ($originalJe['reversed_by_id']) return null;

        return JournalEntryService::reverse($originalJe['id'], date('Y-m-d'), $userId);
    }

    // ============================================================
    // PAYMENT RECEIVED — DR 1010 Cash / CR 1030 AR
    // Spec ref: §5, §16 Rule: "Payment received"
    // ============================================================

    /**
     * Post JE when a payment is recorded and allocated.
     *
     * @param int      $paymentId       The payment row ID
     * @param int      $invoiceId       The invoice being paid
     * @param string   $allocatedAmount Amount actually applied to the invoice (bcmath string)
     * @param int|null $userId
     * @return array|null
     */
    public static function onPaymentReceived(
        int $paymentId,
        int $invoiceId,
        string $allocatedAmount,
        ?int $userId = null
    ): ?array {
        if (!self::isEnabled()) return null;

        if (bccomp($allocatedAmount, '0', 2) <= 0) return null;

        $payment = \db_row(
            "SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL",
            [$paymentId]
        );
        if (!$payment) return null;

        $invoice = \db_row(
            "SELECT invoice_number, customer_id, company_name_snapshot
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) return null;

        $cashAccountId = self::requireAccountId('accounting.default_cash_account_id', 'Cash');
        $arAccountId   = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

        $jeLines = [
            [
                'account_id'  => $cashAccountId,
                'debit'       => $allocatedAmount,
                'credit'      => '0.00',
                'description' => "Cash received — {$payment['payment_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
            [
                'account_id'  => $arAccountId,
                'debit'       => '0.00',
                'credit'      => $allocatedAmount,
                'description' => "AR reduced — {$payment['payment_number']} → {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod($payment['payment_date']);

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Payment {$payment['payment_number']} received — {$invoice['company_name_snapshot']}",
            'entry_type'       => 'system',
            'reference'        => $payment['payment_number'],
            'source_type'      => 'payment',
            'source_id'        => $paymentId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // OVERPAYMENT RECEIVED — DR 1010 Cash full / CR 1030 AR allocated /
    //                       CR 2060 Customer Credits Liability overpayment
    // Spec ref: §16, S-FIX-2 audit #2
    //
    // WHY a dedicated method: the standard onPaymentReceived posts DR Cash /
    // CR AR for the allocated amount only. For an overpayment we receive cash
    // for the FULL amount but only the allocated portion reduces AR — the
    // remainder becomes a customer credit liability and is recorded on a
    // separate credit_notes row with source='overpayment'. Posting the two
    // events as a single 3-line JE keeps the cash side balanced and ensures
    // the credit_note creation does NOT call onCreditNoteIssued (which would
    // double-count the liability).
    // ============================================================

    /**
     * Post JE when a payment is recorded with an overpayment portion.
     *
     * @param int      $paymentId          The payment row ID
     * @param int      $invoiceId          The invoice the allocated portion reduced
     * @param string   $allocatedAmount    Amount applied to the invoice (bcmath)
     * @param string   $overpaymentAmount  Amount routed to credit liability (bcmath, > 0)
     * @param int|null $userId
     * @return array|null
     */
    public static function onOverpaymentReceived(
        int $paymentId,
        int $invoiceId,
        string $allocatedAmount,
        string $overpaymentAmount,
        ?int $userId = null
    ): ?array {
        if (!self::isEnabled()) return null;

        if (bccomp($overpaymentAmount, '0', 2) <= 0) {
            // No overpayment — caller should have used onPaymentReceived instead.
            return null;
        }

        $payment = \db_row(
            "SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL",
            [$paymentId]
        );
        if (!$payment) return null;

        $invoice = \db_row(
            "SELECT invoice_number, customer_id, company_name_snapshot
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) return null;

        $cashAccountId        = self::requireAccountId('accounting.default_cash_account_id', 'Cash');
        $arAccountId          = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');
        $creditsLiabilityId   = self::requireAccountId(
            'accounting.customer_credits_account_id',
            'Customer Credits Liability (2060)'
        );

        $totalCash = bcadd($allocatedAmount, $overpaymentAmount, 2);

        $jeLines = [
            [
                'account_id'  => $cashAccountId,
                'debit'       => $totalCash,
                'credit'      => '0.00',
                'description' => "Cash received — {$payment['payment_number']} (incl. overpayment)",
                'customer_id' => $invoice['customer_id'],
            ],
        ];

        if (bccomp($allocatedAmount, '0', 2) > 0) {
            $jeLines[] = [
                'account_id'  => $arAccountId,
                'debit'       => '0.00',
                'credit'      => $allocatedAmount,
                'description' => "AR reduced — {$payment['payment_number']} → {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ];
        }

        $jeLines[] = [
            'account_id'  => $creditsLiabilityId,
            'debit'       => '0.00',
            'credit'      => $overpaymentAmount,
            'description' => "Customer credit — overpayment from {$payment['payment_number']}",
            'customer_id' => $invoice['customer_id'],
        ];

        $periodInfo = self::resolvePeriod($payment['payment_date']);

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Payment {$payment['payment_number']} received with overpayment — {$invoice['company_name_snapshot']}",
            'entry_type'       => 'system',
            'reference'        => $payment['payment_number'],
            'source_type'      => 'payment',
            'source_id'        => $paymentId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // CREDIT NOTE ISSUED — DR 4xxx Revenue / CR 2060 Customer Credits Liability
    // Spec ref: §16 PASS-6:G2 — posts to liability, NOT directly to AR
    // ============================================================

    /**
     * Post JE when a credit note is created.
     * DR Revenue (same account as original line type, or Other Revenue as fallback)
     * CR 2060 Customer Credits Liability
     *
     * WHY: Credit note creation does NOT touch AR. AR is only reduced when
     * the credit is APPLIED to a specific invoice (see onCreditNoteApplied).
     *
     * @param int      $creditNoteId
     * @param int|null $userId
     * @return array|null
     */
    public static function onCreditNoteIssued(int $creditNoteId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        $cn = \db_row(
            "SELECT * FROM credit_notes WHERE id = ? AND deleted_at IS NULL",
            [$creditNoteId]
        );
        if (!$cn) return null;

        $customer = \db_row(
            "SELECT company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$cn['customer_id']]
        );

        // Revenue account: if tied to an invoice, try to look up the line types.
        // Otherwise fall back to 4110 Other Revenue.
        $revenueAccountId = self::resolveRevenueAccount('other');
        if ($cn['source_invoice_id']) {
            // Try to find the primary line type from the source invoice
            $primaryLine = \db_row(
                "SELECT item_type FROM invoice_line_items
                 WHERE invoice_id = ? AND is_credit = 0
                 ORDER BY amount DESC LIMIT 1",
                [$cn['source_invoice_id']]
            );
            if ($primaryLine) {
                $mapped = self::resolveRevenueAccount($primaryLine['item_type']);
                if ($mapped) $revenueAccountId = $mapped;
            }
        }

        if (!$revenueAccountId) {
            throw new \RuntimeException(
                "Cannot post credit note JE — no GL revenue account mapped. " .
                "Go to Accounting → Settings → Revenue Mapping to configure."
            );
        }

        $creditsLiabilityId = self::requireAccountId(
            'accounting.customer_credits_account_id',
            'Customer Credits Liability (2060)'
        );

        $amount = (string) $cn['amount'];
        $companyName = $customer['company_name'] ?? 'Unknown';

        $jeLines = [
            [
                'account_id'  => $revenueAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "Revenue reversal — CN {$cn['credit_note_number']}",
                'customer_id' => $cn['customer_id'],
            ],
            [
                'account_id'  => $creditsLiabilityId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "Customer credit liability — CN {$cn['credit_note_number']}",
                'customer_id' => $cn['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod($cn['created_at'] ? substr($cn['created_at'], 0, 10) : date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Credit note {$cn['credit_note_number']} issued — {$companyName}",
            'entry_type'       => 'system',
            'reference'        => $cn['credit_note_number'],
            'source_type'      => 'credit_note',
            'source_id'        => $creditNoteId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // CREDIT NOTE APPLIED — DR 2060 Customer Credits Liability / CR 1030 AR
    // Spec ref: §16 PASS-6:G2
    // ============================================================

    /**
     * Post JE when a credit note is applied to an invoice.
     * This is the step that actually reduces AR in the GL.
     *
     * @param int      $creditNoteId
     * @param int      $invoiceId
     * @param string   $amountApplied  bcmath string
     * @param int|null $userId
     * @return array|null
     */
    public static function onCreditNoteApplied(
        int $creditNoteId,
        int $invoiceId,
        string $amountApplied,
        ?int $userId = null
    ): ?array {
        if (!self::isEnabled()) return null;

        if (bccomp($amountApplied, '0', 2) <= 0) return null;

        $cn = \db_row(
            "SELECT credit_note_number, customer_id FROM credit_notes WHERE id = ? AND deleted_at IS NULL",
            [$creditNoteId]
        );
        if (!$cn) return null;

        $invoice = \db_row(
            "SELECT invoice_number, company_name_snapshot FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) return null;

        $creditsLiabilityId = self::requireAccountId(
            'accounting.customer_credits_account_id',
            'Customer Credits Liability (2060)'
        );
        $arAccountId = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

        $jeLines = [
            [
                'account_id'  => $creditsLiabilityId,
                'debit'       => $amountApplied,
                'credit'      => '0.00',
                'description' => "Credit applied — {$cn['credit_note_number']} → {$invoice['invoice_number']}",
                'customer_id' => $cn['customer_id'],
            ],
            [
                'account_id'  => $arAccountId,
                'debit'       => '0.00',
                'credit'      => $amountApplied,
                'description' => "AR reduced — {$cn['credit_note_number']} → {$invoice['invoice_number']}",
                'customer_id' => $cn['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Credit {$cn['credit_note_number']} applied to {$invoice['invoice_number']}",
            'entry_type'       => 'system',
            'reference'        => $cn['credit_note_number'] . '→' . $invoice['invoice_number'],
            'source_type'      => 'credit_note',
            'source_id'        => $creditNoteId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // BAD DEBT WRITE-OFF — DR 6160 Bad Debt Expense / CR 1030 AR
    // Spec ref: §5, §16 Rule: "Bad debt write-off"
    // ============================================================

    /**
     * Post JE for a bad debt write-off.
     *
     * @param int      $invoiceId
     * @param string   $amount     bcmath string
     * @param int|null $userId
     * @return array|null
     */
    public static function onBadDebtWriteOff(int $invoiceId, string $amount, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        if (bccomp($amount, '0', 2) <= 0) return null;

        $invoice = \db_row(
            "SELECT invoice_number, customer_id, company_name_snapshot
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) return null;

        $badDebtAccountId = self::requireAccountId('accounting.bad_debt_expense_account_id', 'Bad Debt Expense');
        $arAccountId      = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

        $jeLines = [
            [
                'account_id'  => $badDebtAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "Bad debt — Invoice {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
            [
                'account_id'  => $arAccountId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "AR written off — Invoice {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Bad debt write-off — Invoice {$invoice['invoice_number']} — {$invoice['company_name_snapshot']}",
            'entry_type'       => 'system',
            'reference'        => $invoice['invoice_number'],
            'source_type'      => 'invoice',
            'source_id'        => $invoiceId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // HELPER: Resolve revenue account from line item type
    // Uses the JSON map at accounting.revenue_account_map
    // ============================================================

    /**
     * Resolve a GL account ID for an invoice line item type.
     *
     * WHY: The accounting settings store a JSON map of item_type → account_code.
     * We parse the JSON, look up the code, then resolve to the acc_accounts.id.
     * Falls back to 'other' mapping if no specific mapping exists.
     *
     * @param string $lineType  e.g. 'base_rental', 'late_fee', 'mileage_precharge'
     * @return int|null          Account ID or null if not mapped
     */
    private static function resolveRevenueAccount(string $lineType): ?int
    {
        static $mapCache = null;
        static $codeToIdCache = [];

        // Load and cache the JSON map
        if ($mapCache === null) {
            $mapCache = AccountingService::setting('accounting.revenue_account_map', []);
            if (is_string($mapCache)) {
                $mapCache = json_decode($mapCache, true) ?? [];
            }
        }

        // Look up account code for this line type
        $accountCode = $mapCache[$lineType] ?? $mapCache['other'] ?? null;
        if (!$accountCode) return null;

        $accountCode = (string) $accountCode;

        // Resolve code → id (cached)
        if (isset($codeToIdCache[$accountCode])) {
            return $codeToIdCache[$accountCode];
        }

        $account = \db_row(
            "SELECT id FROM acc_accounts WHERE code = ? AND is_active = 1",
            [$accountCode]
        );

        $codeToIdCache[$accountCode] = $account ? (int) $account['id'] : null;
        return $codeToIdCache[$accountCode];
    }

    // ============================================================
    // HELPER: Require a GL account ID from settings — throw if missing
    // ============================================================

    /**
     * Get a required GL account ID from accounting settings.
     * Throws RuntimeException if the setting is empty or the account doesn't exist.
     *
     * @param string $settingKey   e.g. 'accounting.ar_account_id'
     * @param string $friendlyName e.g. 'Accounts Receivable'
     * @return int
     * @throws \RuntimeException
     */
    private static function requireAccountId(string $settingKey, string $friendlyName): int
    {
        $accountId = AccountingService::setting($settingKey);
        if (!$accountId) {
            throw new \RuntimeException(
                "Cannot complete — accounting configuration incomplete. " .
                "No GL account mapped for {$friendlyName} (setting: {$settingKey}). " .
                "Go to Accounting → Settings to configure."
            );
        }

        // Verify the account exists and is active
        $account = \db_row(
            "SELECT id, is_active FROM acc_accounts WHERE id = ?",
            [(int) $accountId]
        );
        if (!$account) {
            throw new \RuntimeException(
                "GL account #{$accountId} ({$friendlyName}) not found in chart of accounts."
            );
        }
        if (!$account['is_active']) {
            throw new \RuntimeException(
                "GL account #{$accountId} ({$friendlyName}) is inactive. " .
                "Reactivate it or update the mapping in Accounting → Settings."
            );
        }

        return (int) $accountId;
    }

    // ============================================================
    // HELPER: Resolve period for an entry date
    // WHY: §16 — If date falls in a closed period, post to earliest open period
    // ============================================================

    /**
     * Resolve the accounting period and effective entry date for a JE.
     *
     * If the natural period is open, use the original date.
     * If closed/locked, fall back to the earliest open period's start_date
     * and log the discrepancy.
     *
     * @param string $date Y-m-d
     * @return array ['entry_date' => string, 'period_id' => int, 'was_redirected' => bool]
     * @throws \RuntimeException if no open period exists at all
     */
    private static function resolvePeriod(string $date): array
    {
        $period = AccountingService::periodForDate($date);

        if ($period && $period['status'] === 'open') {
            return [
                'entry_date'     => $date,
                'period_id'      => (int) $period['id'],
                'was_redirected' => false,
            ];
        }

        // Period is closed/locked or doesn't exist — fall back to earliest open period
        $openPeriod = AccountingService::currentOpenPeriod();
        if (!$openPeriod) {
            throw new \RuntimeException(
                "No open accounting period available. Cannot post journal entry for date {$date}. " .
                "Open a period in Accounting → Periods."
            );
        }

        // Use the open period's start date as the entry date
        $redirectedDate = $openPeriod['start_date'];

        // Log the discrepancy to audit
        // WHY 'status_change' (not 'period_redirect'): the audit_log.action
        // ENUM does not include period_redirect — using it would crash the
        // insert. status_change is the closest semantic match (the entry's
        // effective period changed).
        \db_insert('audit_log', [
            'user_id'     => null,
            'action'      => 'status_change',
            'module'      => 'accounting',
            'entity_type' => 'journal_entry',
            'entity_id'   => 0,
            'notes'       => "PERIOD REDIRECT: Auto-JE for date {$date} redirected to period {$openPeriod['name']} " .
                             "(original period closed/missing). Entry date set to {$redirectedDate}.",
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return [
            'entry_date'     => $redirectedDate,
            'period_id'      => (int) $openPeriod['id'],
            'was_redirected' => true,
        ];
    }

    // ============================================================
    // FIXED-ASSET HOOKS  (S034)
    //
    // These are thin wrappers around FixedAssetService methods. They
    // exist so that external code paths (e.g. equipment unit decommission,
    // bill posting that triggers a CapEx flag) call into AutoEntryBridge
    // for ALL accounting events, the same way invoice / payment / credit
    // note do. The bridge guards on isEnabled(), then delegates.
    //
    // Spec ref: §16 PASS-7 Fixed Asset Auto-JE Rules
    // ============================================================

    /**
     * Triggered when an equipment unit (or other asset) is disposed of.
     * Posts the disposal JE via FixedAssetService::dispose() which handles:
     *   DR Cash + DR Accumulated Depr + (DR Loss | CR Gain) + CR Asset Cost
     *
     * @param array  $data   {asset_id, disposal_date, disposal_type, proceeds, ...}
     * @param int    $userId
     * @param string $userRoleSlug  caller's role for the role guard
     * @return array|null  the acc_asset_disposals row, or null if accounting disabled
     */
    public static function onAssetDisposed(array $data, int $userId, string $userRoleSlug): ?array
    {
        if (!self::isEnabled()) return null;
        return FixedAssetService::dispose($data, $userId, $userRoleSlug);
    }

    /**
     * Triggered when an asset's carrying value is reduced via impairment.
     * Posts: DR 7020 Impairment Loss / CR Accumulated Depreciation
     *
     * @return array|null  the acc_asset_impairments row, or null if accounting disabled
     */
    public static function onAssetImpaired(array $data, int $userId, string $userRoleSlug): ?array
    {
        if (!self::isEnabled()) return null;
        return FixedAssetService::impair($data, $userId, $userRoleSlug);
    }

    /**
     * Triggered when a depreciation run is posted (one consolidated JE
     * per period). The run must already be in 'preview' state.
     *
     * @return array|null  the posted acc_depreciation_runs row, or null
     */
    public static function onDepreciationPosted(int $runId, int $userId): ?array
    {
        if (!self::isEnabled()) return null;
        return FixedAssetService::postRun($runId, $userId);
    }

    /**
     * Triggered when a depreciation run is reversed. Wraps reverseRun()
     * which restores asset NBVs and creates the reversal JE.
     *
     * @return array|null  ['run' => ..., 'reversal_je' => ...] or null
     */
    public static function onDepreciationReversed(int $runId, int $userId): ?array
    {
        if (!self::isEnabled()) return null;
        return FixedAssetService::reverseRun($runId, $userId);
    }

    // ============================================================
    // S035 — Tax Management hook
    // ============================================================

    /**
     * Triggered after a tax remittance JE has been posted by
     * TaxFilingService::recordRemittance(). The remittance JE is
     * already fully created/posted by the service inside its own
     * db_transaction, so this method is currently a no-op stub.
     *
     * It exists so that future modules (e.g. cash forecasting,
     * compliance alerts, accountant notifications) can hook in here
     * without modifying TaxFilingService. The signature mirrors the
     * other on*Posted hooks for consistency.
     *
     * Decisions: A8 (JE backbone — bridge does not bypass GL).
     *
     * @return array|null  always null in the stub form
     */
    public static function onTaxRemittancePosted(int $remittanceId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        // No-op: TaxFilingService already created the JE inside its own
        // db_transaction. This stub is the integration point for future
        // hooks (e.g. cash forecasting, compliance alerts).
        return null;
    }
}
