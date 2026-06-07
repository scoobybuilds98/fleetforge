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

        // S-ACCT-GPS: cache customer + lease + GPS settings once before the
        // loop (only when GPS lines exist + need NET split). Cheap NULL
        // initializers means the cache stays disabled if no GPS line surfaces.
        $gpsCache = null;

        // Build revenue lines grouped by GL account
        $revenueByAccount = [];
        foreach ($lineItems as $line) {
            // GPS PRINCIPAL/AGENT PRESENTATION (ASPE 3400)
            // Per customer.gps_revenue_presentation toggle (D-GPS-1..D-GPS-4).
            // NET: splits GPS billing into margin (revenue) + Samsara cost (recoverable asset).
            // GROSS or net-with-zero-cost: full billing to GPS gross revenue account.
            // Samsara cost configured via accounting.samsara_daily_unit_cost.
            // Credit GPS lines (is_credit=1) bypass split — flow through original
            // path so refunds/adjustments hit the same single account they originally credited.
            // Locked: S-ACCT-GPS 2026-05-19.
            if ($line['item_type'] === 'gps' && empty($line['is_credit'])) {
                if ($gpsCache === null) {
                    $gpsCache = self::loadGpsContext($invoice);
                }
                if ($gpsCache['split_enabled']) {
                    $gpsAmount = (string) $line['amount'];
                    $leaseGpsCost = $gpsCache['lease_gps_cost'];
                    // Derive billing days as integer division of GPS line / lease.gps_cost.
                    $days = bcdiv($gpsAmount, $leaseGpsCost, 0);
                    $totalCost = bcmul($gpsCache['daily_cost'], $days, 2);
                    // Cost can never exceed the billing amount — bcmin equivalent.
                    if (bccomp($totalCost, $gpsAmount, 2) > 0) {
                        $totalCost = $gpsAmount;
                    }
                    $margin = bcsub($gpsAmount, $totalCost, 2);
                    if (bccomp($margin, '0', 2) < 0) {
                        // Cost > billing — guard against negative revenue.
                        \error_log("AutoEntryBridge GPS NET: margin<0 on invoice {$invoiceId} line — clamping margin=0, cost={$gpsAmount}");
                        $margin = '0.00';
                        $totalCost = $gpsAmount;
                    }

                    $netAccId = (int) $gpsCache['net_acct_id'];
                    $recAccId = (int) $gpsCache['recoverable_acct_id'];
                    $revenueByAccount[$netAccId] = bcadd(
                        $revenueByAccount[$netAccId] ?? '0.00', $margin, 2);
                    $revenueByAccount[$recAccId] = bcadd(
                        $revenueByAccount[$recAccId] ?? '0.00', $totalCost, 2);
                    continue;
                }
                // GROSS path (presentation=gross OR net-but-no-cost OR fallback)
                if (!empty($gpsCache['gross_acct_id'])) {
                    $grossAccId = (int) $gpsCache['gross_acct_id'];
                    $revenueByAccount[$grossAccId] = bcadd(
                        $revenueByAccount[$grossAccId] ?? '0.00', (string) $line['amount'], 2);
                    continue;
                }
                // Final fallback — let original mapping path resolve (legacy 'gps' → mapped account).
            }

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

        // D-GL-REVREC-1 (S-INVOICE-DATING-FIX): rental revenue recognizes in its
        // BILLING PERIOD, not on the send action. entry_date = the invoice's
        // issue_date (= billing_period_start) — the `sent_date ??` fallback is
        // dropped, so a March-period invoice posts to March no matter when it's
        // sent. resolvePeriod() still redirects a closed/missing target period to
        // the earliest open one (audited).
        //
        // Future guard: never post revenue into a FUTURE period. If issue_date is
        // after business-local today (advance-dated rows, e.g. far-future test
        // invoices), post on today and let resolvePeriod() map it to the current
        // open period. "Today" is computed in the BUSINESS timezone
        // (settings.company.timezone, the cron's source) — NOT raw UTC — so a
        // late-evening month boundary can't roll the period (the known UTC/local
        // write skew). True deferred-revenue treatment for advance billing is out
        // of scope (D-GL-REVREC-2; only if advance_billing_periods is enabled).
        // Single source of truth (D-GL-REVREC-1 + D-QBO-DATING-1): the future-
        // guarded recognition date lives in AccountingService::recognitionDate so
        // the GL JE and the QBO TxnDate (InvoicePusher) can't drift. resolvePeriod
        // then maps it to the FF open period (closed/missing → earliest open).
        $entryDate  = AccountingService::recognitionDate((string) $invoice['invoice_date']);
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
    // CREDIT NOTE UN-APPLIED — DR 1030 AR / CR 2060 Customer Credits Liability
    // (the exact inverse of onCreditNoteApplied — F27)
    // ============================================================

    /**
     * Post the reversing JE when a credit-note application is un-applied
     * (api/v1/credit_notes/unapply.php). Restores AR that onCreditNoteApplied
     * reduced: DR 1030 AR / CR 2060 Customer Credits Liability — the exact
     * swap of the apply JE. source_type='credit_note' so it stays bridge-derived
     * (QBO derives its own reversal from the deleted apply Payment; the FF JE is
     * never double-pushed per D-QBO-21-1).
     *
     * @param int      $creditNoteId
     * @param int      $invoiceId
     * @param string   $amountApplied  bcmath string (the amount being restored)
     * @param int|null $userId
     * @return array|null
     */
    public static function onCreditNoteUnapplied(
        int $creditNoteId,
        int $invoiceId,
        string $amountApplied,
        ?int $userId = null
    ): ?array {
        if (!self::isEnabled()) return null;

        if (bccomp($amountApplied, '0', 2) <= 0) return null;

        $cn = \db_row(
            "SELECT credit_note_number, customer_id FROM credit_notes WHERE id = ?",
            [$creditNoteId]
        );
        if (!$cn) return null;

        $invoice = \db_row(
            "SELECT invoice_number FROM invoices WHERE id = ?",
            [$invoiceId]
        );
        if (!$invoice) return null;

        $creditsLiabilityId = self::requireAccountId(
            'accounting.customer_credits_account_id',
            'Customer Credits Liability (2060)'
        );
        $arAccountId = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

        // Inverse of onCreditNoteApplied: DR AR (restore) / CR Credits Liability.
        $jeLines = [
            [
                'account_id'  => $arAccountId,
                'debit'       => $amountApplied,
                'credit'      => '0.00',
                'description' => "AR restored — credit {$cn['credit_note_number']} un-applied from {$invoice['invoice_number']}",
                'customer_id' => $cn['customer_id'],
            ],
            [
                'account_id'  => $creditsLiabilityId,
                'debit'       => '0.00',
                'credit'      => $amountApplied,
                'description' => "Credit liability restored — {$cn['credit_note_number']} un-applied",
                'customer_id' => $cn['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Credit {$cn['credit_note_number']} un-applied from {$invoice['invoice_number']}",
            'entry_type'       => 'system',
            'reference'        => $cn['credit_note_number'] . '↩' . $invoice['invoice_number'],
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
    /**
     * S-ACCT-GPS: load + cache GPS presentation context for an invoice.
     * Reads customer.gps_revenue_presentation + lease.gps_cost + the 4 GPS-
     * related settings exactly once per invoice. Returns a context array
     * with `split_enabled` true when ALL conditions for NET treatment hold:
     * presentation='net', samsara_daily_unit_cost > 0, lease.gps_cost > 0,
     * and both account_id settings configured. STOP CONDITION compliance:
     * never throws — falls back to GROSS treatment when anything is missing
     * + logs the fallback reason.
     */
    private static function loadGpsContext(array $invoice): array
    {
        $ctx = [
            'split_enabled'       => false,
            'presentation'        => 'gross',
            'daily_cost'          => '0.00',
            'lease_gps_cost'      => '0.00',
            'net_acct_id'         => null,
            'recoverable_acct_id' => null,
            'gross_acct_id'       => null,
        ];

        // Customer presentation policy. Backward-compat guard: if the column
        // is missing (impossible post-migration but defensive), treat as gross.
        try {
            $cust = \db_row(
                "SELECT gps_revenue_presentation FROM customers WHERE id = ?",
                [(int) $invoice['customer_id']]
            );
            $ctx['presentation'] = $cust['gps_revenue_presentation'] ?? 'gross';
        } catch (\Throwable) {
            \error_log('AutoEntryBridge GPS: customers.gps_revenue_presentation read failed — defaulting to gross.');
            return $ctx;
        }

        // Daily cost + account IDs (all four settings must be present for NET).
        $ctx['daily_cost']          = (string) AccountingService::setting('accounting.samsara_daily_unit_cost', '0.00');
        $ctx['net_acct_id']         = AccountingService::setting('accounting.gps_net_revenue_account_id');
        $ctx['recoverable_acct_id'] = AccountingService::setting('accounting.samsara_recoverable_account_id');
        $ctx['gross_acct_id']       = AccountingService::setting('accounting.gps_gross_revenue_account_id');

        // Lease GPS cost — needed only for NET path day-derivation.
        if (!empty($invoice['lease_id'])) {
            $lease = \db_row("SELECT gps_cost FROM leases WHERE id = ?", [(int) $invoice['lease_id']]);
            $ctx['lease_gps_cost'] = (string) ($lease['gps_cost'] ?? '0.00');
        }

        // Decide split_enabled (NET treatment) — all five gates must pass.
        $gates = [
            $ctx['presentation'] === 'net',
            bccomp($ctx['daily_cost'], '0.00', 4) > 0,
            bccomp($ctx['lease_gps_cost'], '0.00', 4) > 0,
            !empty($ctx['net_acct_id']),
            !empty($ctx['recoverable_acct_id']),
        ];
        $ctx['split_enabled'] = !in_array(false, $gates, true);

        if ($ctx['presentation'] === 'net' && !$ctx['split_enabled']) {
            \error_log(sprintf(
                'AutoEntryBridge GPS NET fallback to gross on invoice %d: daily_cost=%s lease_gps_cost=%s net_acct=%s rec_acct=%s',
                (int) $invoice['id'],
                $ctx['daily_cost'],
                $ctx['lease_gps_cost'],
                $ctx['net_acct_id'] ? 'set' : 'MISSING',
                $ctx['recoverable_acct_id'] ? 'set' : 'MISSING'
            ));
        }

        return $ctx;
    }

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

    // ============================================================
    // DAMAGE CLAIMS HOOKS  (S-ACCT-DMG, spec §23.11)
    //
    // Three methods wired into the damage_claims status-transition
    // pipeline and bills/approve.php. Each is idempotent — calling
    // twice for the same claim returns the existing JE row instead
    // of double-posting.
    //
    // Pre-flight K-22 catches (locked in REFERENCE.md):
    //   - damage_claims.status has 'invoiced' NOT 'billed_to_customer'
    //     → bridge fires on 'invoiced' transition
    //   - GL accounts:
    //       damage_recovery_revenue → #47 (code 4100, NOT 4140 — absent)
    //       damage_repair_expense   → #55 (code 6010 operating_expense
    //                                 — NOT 5130, which is absent; Mainland's
    //                                 COA places repair in 6xxx not 5xxx)
    //       bad_debt_expense        → #70 (code 6160 — existing)
    //   - acc_bill_lines has NO damage_claim_id FK → repair-bridge
    //     wiring via bills/approve.php is feature-gated on this column
    //     existing; current behavior is "log + skip".
    // ============================================================

    /**
     * Fire when damage_claims.status transitions to 'invoiced' (recovery
     * billed to customer). Reclassifies the invoice's existing JE source
     * from 'invoice' → 'damage_recovery' so the subledger can drill down.
     *
     * Idempotent: returns existing JE row when one with source_type=
     * 'damage_recovery' AND source_id=$claimId already exists. Never
     * double-posts. Falls back to creating a minimal reference JE only
     * if no underlying invoice JE exists (defensive — onInvoiceSent
     * should have posted one already).
     *
     * @param int      $claimId   damage_claims.id
     * @param int      $invoiceId invoices.id of the recovery invoice
     * @param int|null $userId    actor for audit trail
     * @return array|null         the JE row (existing or new), or null when
     *                            bridge is disabled / row missing
     */
    public static function onDamageRecoveryBilled(int $claimId, int $invoiceId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        // Idempotency guard — already linked?
        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'damage_recovery' AND source_id = ?
              LIMIT 1",
            [$claimId]
        );
        if ($existing) {
            return $existing;
        }

        $claim = \db_row(
            "SELECT id, claim_number, customer_id, customer_liable_amount
               FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
            [$claimId]
        );
        if (!$claim) return null;

        $invoice = \db_row(
            "SELECT id, invoice_number, customer_id, company_name_snapshot,
                    total_amount, customer_name_snapshot
               FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) return null;

        // Preferred path: reclassify the existing invoice JE in place.
        // The DR AR / CR Revenue / CR Tax lines stay exactly the same —
        // we just retag the source so subledger queries pick it up.
        $existingInvoiceJe = \db_row(
            "SELECT id FROM acc_journal_entries
              WHERE source_type = 'invoice' AND source_id = ?
              LIMIT 1",
            [$invoiceId]
        );

        if ($existingInvoiceJe) {
            $jeId = (int) $existingInvoiceJe['id'];
            \db_execute(
                "UPDATE acc_journal_entries
                    SET source_type = 'damage_recovery',
                        source_id   = ?
                  WHERE id = ?
                    AND source_type = 'invoice'",
                [$claimId, $jeId]
            );

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'action'       => 'update',
                'module'       => 'accounting',
                'entity_type'  => 'damage_claim',
                'entity_id'    => $claimId,
                'entity_label' => $claim['claim_number'],
                'notes'        => "Damage recovery JE linked — claim #{$claim['claim_number']}, invoice {$invoice['invoice_number']}, JE #{$jeId}.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$jeId]);
        }

        // Fallback path: no invoice JE exists (defensive — shouldn't
        // happen in normal flow). Create a minimal reference JE so the
        // subledger has a drill-down target.
        $recoveryAmount = (string) ($claim['customer_liable_amount'] ?? '0.00');
        if (bccomp($recoveryAmount, '0', 2) <= 0) {
            $recoveryAmount = (string) ($invoice['total_amount'] ?? '0.00');
        }
        if (bccomp($recoveryAmount, '0', 2) <= 0) return null;

        $arAccountId       = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');
        $revenueAccountId  = self::requireAccountId('accounting.damage_recovery_revenue_account_id', 'Damage Recovery Revenue');

        $jeLines = [
            [
                'account_id'  => $arAccountId,
                'debit'       => $recoveryAmount,
                'credit'      => '0.00',
                'description' => "AR — Damage claim {$claim['claim_number']} invoice {$invoice['invoice_number']}",
                'customer_id' => $claim['customer_id'],
            ],
            [
                'account_id'  => $revenueAccountId,
                'debit'       => '0.00',
                'credit'      => $recoveryAmount,
                'description' => "Damage recovery revenue — claim {$claim['claim_number']}",
                'customer_id' => $claim['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Damage recovery billed — claim {$claim['claim_number']} — invoice {$invoice['invoice_number']}",
            'entry_type'       => 'system',
            'reference'        => $claim['claim_number'],
            'source_type'      => 'damage_recovery',
            'source_id'        => $claimId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    /**
     * Fire when a vendor bill for repair work is approved and a damage
     * claim is linked. Reclassifies the bill's existing AP/expense JE
     * source from 'ap_bill' → 'damage_repair'.
     *
     * Idempotent: returns existing JE row when source_type='damage_repair'
     * + source_id=$claimId already exists.
     *
     * @param int      $claimId damage_claims.id
     * @param int      $billId  acc_bills.id of the repair bill
     * @param int|null $userId  actor for audit trail
     */
    public static function onDamageRepairExpensed(int $claimId, int $billId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'damage_repair' AND source_id = ?
              LIMIT 1",
            [$claimId]
        );
        if ($existing) {
            return $existing;
        }

        $claim = \db_row(
            "SELECT id, claim_number FROM damage_claims
              WHERE id = ? AND deleted_at IS NULL",
            [$claimId]
        );
        if (!$claim) return null;

        $bill = \db_row(
            "SELECT id, bill_number, vendor_id, total_amount
               FROM acc_bills WHERE id = ?",
            [$billId]
        );
        if (!$bill) return null;

        // Preferred path: reclassify the existing bill JE.
        $existingBillJe = \db_row(
            "SELECT id FROM acc_journal_entries
              WHERE source_type = 'ap_bill' AND source_id = ?
              LIMIT 1",
            [$billId]
        );

        if ($existingBillJe) {
            $jeId = (int) $existingBillJe['id'];
            \db_execute(
                "UPDATE acc_journal_entries
                    SET source_type = 'damage_repair',
                        source_id   = ?
                  WHERE id = ?
                    AND source_type = 'ap_bill'",
                [$claimId, $jeId]
            );

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'action'       => 'update',
                'module'       => 'accounting',
                'entity_type'  => 'damage_claim',
                'entity_id'    => $claimId,
                'entity_label' => $claim['claim_number'],
                'notes'        => "Damage repair JE linked — claim #{$claim['claim_number']}, bill {$bill['bill_number']}, JE #{$jeId}.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$jeId]);
        }

        // Fallback path: create new bill-shaped JE
        $billAmount = (string) ($bill['total_amount'] ?? '0.00');
        if (bccomp($billAmount, '0', 2) <= 0) return null;

        $expenseAccountId = self::requireAccountId('accounting.damage_repair_expense_account_id', 'Damage Repair Expense');
        $apAccountId      = self::requireAccountId('accounting.ap_account_id', 'Accounts Payable');

        $jeLines = [
            [
                'account_id'  => $expenseAccountId,
                'debit'       => $billAmount,
                'credit'      => '0.00',
                'description' => "Damage repair expense — claim {$claim['claim_number']} bill {$bill['bill_number']}",
                'vendor_id'   => $bill['vendor_id'],
            ],
            [
                'account_id'  => $apAccountId,
                'debit'       => '0.00',
                'credit'      => $billAmount,
                'description' => "AP — Damage repair bill {$bill['bill_number']}",
                'vendor_id'   => $bill['vendor_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Damage repair expensed — claim {$claim['claim_number']} — bill {$bill['bill_number']}",
            'entry_type'       => 'system',
            'reference'        => $claim['claim_number'],
            'source_type'      => 'damage_repair',
            'source_id'        => $claimId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    /**
     * Fire when damage_claims.status transitions to 'written_off'.
     * Posts the bad-debt JE: DR Bad Debt Expense / CR AR for the
     * outstanding balance on the recovery invoice. The invoice itself
     * is NOT voided here (voiding is a billing-side concern; only the
     * GL write-off is in scope).
     *
     * Idempotent: returns existing JE row when source_type='damage_writeoff'
     * + source_id=$claimId already exists. Returns null with no error
     * when invoice balance is $0 (nothing to write off).
     *
     * @param int      $claimId damage_claims.id
     * @param int|null $userId  actor for audit trail
     */
    public static function onDamageWrittenOff(int $claimId, ?int $userId = null): ?array
    {
        if (!self::isEnabled()) return null;

        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'damage_writeoff' AND source_id = ?
              LIMIT 1",
            [$claimId]
        );
        if ($existing) {
            return $existing;
        }

        $claim = \db_row(
            "SELECT id, claim_number, customer_id, invoice_id
               FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
            [$claimId]
        );
        if (!$claim) return null;

        if (empty($claim['invoice_id'])) {
            throw new \RuntimeException(
                "Cannot write off damage claim {$claim['claim_number']} — no recovery invoice on file."
            );
        }

        $invoice = \db_row(
            "SELECT id, invoice_number, customer_id, balance_due
               FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [(int) $claim['invoice_id']]
        );
        if (!$invoice) {
            throw new \RuntimeException(
                "Damage claim {$claim['claim_number']} references invoice #{$claim['invoice_id']} which is missing."
            );
        }

        $writeOffAmount = (string) ($invoice['balance_due'] ?? '0.00');
        if (bccomp($writeOffAmount, '0', 2) <= 0) {
            error_log("[AutoEntryBridge::onDamageWrittenOff] Claim {$claim['claim_number']} invoice balance is \$0 — no JE needed.");
            return null;
        }

        $badDebtAccountId = self::requireAccountId('accounting.bad_debt_expense_account_id', 'Bad Debt Expense');
        $arAccountId      = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

        $jeLines = [
            [
                'account_id'  => $badDebtAccountId,
                'debit'       => $writeOffAmount,
                'credit'      => '0.00',
                'description' => "Bad debt — damage claim {$claim['claim_number']} invoice {$invoice['invoice_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
            [
                'account_id'  => $arAccountId,
                'debit'       => '0.00',
                'credit'      => $writeOffAmount,
                'description' => "AR written off — damage claim {$claim['claim_number']}",
                'customer_id' => $invoice['customer_id'],
            ],
        ];

        $periodInfo = self::resolvePeriod(date('Y-m-d'));

        return JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Damage claim write-off — {$claim['claim_number']} (invoice {$invoice['invoice_number']})",
            'entry_type'       => 'system',
            'reference'        => $claim['claim_number'],
            'source_type'      => 'damage_writeoff',
            'source_id'        => $claimId,
            'post_immediately' => true,
        ], $jeLines, $userId);
    }

    // ============================================================
    // ASPE 3065 SALES-TYPE LEASE — Inception / Period / Termination
    //
    // Three methods drive the lessor-side capital-lease GL posting:
    //   onLeaseInception_SalesType  — fires from activate.php on
    //     pending→active for sales_type leases (LESSOR-4 will add a
    //     parallel onLeaseInception_DirectFinancing method)
    //   onLeasePeriodPosting_Capital — fires from the daily cron for
    //     each amortization schedule row whose period_date ≤ today AND
    //     status='scheduled' (generic for sales_type + direct_financing
    //     per D-LESSOR-3-PERIOD-GENERIC; LESSOR-4 reuses without change)
    //   onLeaseTermination — fires from close.php on active→completed
    //     for capital leases (no-op when closing NI already zero; posts
    //     write-off only when an unguaranteed residual remains)
    //
    // Hard gates on every method:
    //   - isEnabled()                                  bridge master switch
    //   - lessor_module_enabled='1' setting             LESSOR-1 gate
    // Both return null when disabled — no JE, no error.
    //
    // All amounts bcmath. All account ids via requireAccountId(). Every
    // method asserts Σ DR = Σ CR before posting; the assertion throws
    // RuntimeException with a full line breakdown so a divergent JE
    // never lands. See spec §24.4 / §24.6 for the canonical line shape.
    //
    // Session: S-ACCT-LESSOR-3
    // ============================================================

    /**
     * Gate check shared by the 3 lessor bridge methods. Returns true when
     * `accounting.lessor_module_enabled` setting is '1' — operator must
     * explicitly flip the flag before any capital-lease JE posts.
     * Stays at '0' until the first sales-type lease is classified and
     * the operator readies the lessor accounting workflow.
     */
    private static function isLessorModuleEnabled(): bool
    {
        return (string) AccountingService::setting('accounting.lessor_module_enabled', '0') === '1';
    }

    /**
     * Sales-type lease inception JE (ASPE 3065 §24.4).
     *
     * Posted on transition pending→active when classification='sales_type'.
     * Derecognizes the underlying asset (cost + accumulated depreciation
     * — both legs, NOT just NBV — per D-LESSOR-3-INVENTORY) and recognizes
     * the receivable (split current/long-term per ASPE 3065.54), the
     * sales revenue (PV of MLP), the COGS (carrying minus PV of
     * unguaranteed residual), and the unearned finance income (gross
     * minus net investment).
     *
     * Initial direct costs are expensed via a SEPARATE JE per
     * D-LESSOR-3-IDC-TREATMENT — the main inception JE stays focused.
     *
     * Idempotent: returns the existing JE row when source_type=
     * 'lease_inception' + source_id=$leaseId already exists.
     *
     * @return array|null  { je, asset_disposed, idc_je_id } or null when
     *                     either bridge or lessor module is disabled
     * @throws \RuntimeException  Σ DR ≠ Σ CR; asset missing/ambiguous;
     *                             called on non-sales_type lease
     */
    public static function onLeaseInception_SalesType(int $leaseId, ?int $userId = null): ?array
    {
        if (!self::isEnabled())            return null;
        if (!self::isLessorModuleEnabled()) {
            error_log("[AutoEntryBridge::onLeaseInception_SalesType] lessor module disabled — JE skipped for lease #{$leaseId}");
            return null;
        }

        // Idempotency guard — already posted?
        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'lease_inception' AND source_id = ?
              LIMIT 1",
            [$leaseId]
        );
        if ($existing) {
            return ['je' => $existing, 'asset_disposed' => null, 'idc_je_id' => null];
        }

        return \db_transaction(function () use ($leaseId, $userId): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_inception_{$leaseId}"]);

            try {
                $lease = \db_row(
                    "SELECT id, contract_number, customer_id, equipment_unit_id, classification,
                            initial_fair_value, initial_direct_costs,
                            guaranteed_residual_value, unguaranteed_residual_value,
                            implicit_rate, start_date, deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \RuntimeException("AutoEntryBridge::onLeaseInception_SalesType: lease #{$leaseId} not found.");
                }
                if ($lease['classification'] !== 'sales_type') {
                    throw new \RuntimeException(
                        "AutoEntryBridge::onLeaseInception_SalesType called on non-sales_type lease #{$leaseId} "
                        . "(classification='{$lease['classification']}')."
                    );
                }

                // Schedule — pulled by the LESSOR-2 service. Used to split
                // initial NI into current (sum of principal in next 12 periods)
                // and long-term, and to derive gross investment.
                $schedule = LeaseAmortizationService::getSchedule($leaseId);
                if (empty($schedule['periods'])) {
                    throw new \RuntimeException(
                        "Lease #{$leaseId} has no amortization schedule. "
                        . "LESSOR-2 should have generated it at activation — investigate."
                    );
                }

                // Linked acc_fixed_assets row.
                $assets = \db_select(
                    "SELECT id, asset_account_id, accum_depr_account_id,
                            acquisition_cost, accumulated_depreciation, net_book_value,
                            status, notes
                       FROM acc_fixed_assets
                      WHERE equipment_unit_id = ? AND status = 'active'",
                    [(int) $lease['equipment_unit_id']]
                );
                if (count($assets) === 0) {
                    throw new \RuntimeException(
                        "No active fixed asset linked to equipment unit #{$lease['equipment_unit_id']}. "
                        . "Asset must exist before sales-type inception JE can post."
                    );
                }
                if (count($assets) > 1) {
                    throw new \RuntimeException(
                        "Ambiguous asset linkage for unit #{$lease['equipment_unit_id']} — "
                        . count($assets) . ' active assets found.'
                    );
                }
                $asset = $assets[0];

                // ── Derive amounts ──────────────────────────────────
                $acquisitionCost = (string) $asset['acquisition_cost'];
                $accumulatedDep  = (string) $asset['accumulated_depreciation'];
                $carryingAmount  = bcsub($acquisitionCost, $accumulatedDep, 2);

                $initialNi = (string) $lease['initial_fair_value'];

                // NI split: current = sum of principal_reduction over the
                // first 12 schedule rows; long-term = remainder.
                $niCurrent = '0.00';
                foreach ($schedule['periods'] as $p) {
                    if ((int) $p['period_number'] > 12) break;
                    $niCurrent = bcadd($niCurrent, (string) $p['principal_reduction'], 2);
                }
                // Cap at initialNi (defensive — short leases where 12 periods
                // exceed initial NI by rounding tail of <$0.01).
                if (bccomp($niCurrent, $initialNi, 2) > 0) {
                    $niCurrent = $initialNi;
                }
                $niLongTerm = bcsub($initialNi, $niCurrent, 2);

                // Gross investment = Σ cash_receipt across the full schedule
                // (regular periods + BPO row if present).
                $grossInvestment = '0.00';
                foreach ($schedule['periods'] as $p) {
                    $grossInvestment = bcadd($grossInvestment, (string) $p['cash_receipt'], 2);
                }

                // PV of unguaranteed residual at t=0 using monthly implicit rate.
                // termMonths = highest non-BPO period_number (BPO row is termMonths+1).
                $unguarResid = (string) $lease['unguaranteed_residual_value'];
                $monthlyRate = bccomp((string) $lease['implicit_rate'], '0', 6) === 0
                    ? '0'
                    : bcdiv((string) $lease['implicit_rate'], '12', 10);

                // Find the last regular (non-BPO) period number = first period
                // with finance_income > 0 from the end. BPO row has finance=0.
                $termMonths = 0;
                foreach (array_reverse($schedule['periods']) as $p) {
                    if (bccomp((string) $p['finance_income'], '0', 2) > 0) {
                        $termMonths = (int) $p['period_number'];
                        break;
                    }
                }
                if ($termMonths === 0) {
                    // All-zero-finance schedule (rate=0 edge case) — fall back to
                    // the highest period_number; treat the BPO row's date alignment.
                    $termMonths = (int) end($schedule['periods'])['period_number'];
                }

                if (bccomp($unguarResid, '0', 2) > 0 && bccomp($monthlyRate, '0', 10) > 0) {
                    $onePlusR = bcadd('1', $monthlyRate, 10);
                    $df = '1';
                    for ($t = 1; $t <= $termMonths; $t++) {
                        $df = bcmul($df, $onePlusR, 10);
                    }
                    $pvUnguaranteed = bcdiv($unguarResid, $df, 2);
                } else {
                    // rate=0 → nominal sum (no discounting).
                    $pvUnguaranteed = bcadd($unguarResid, '0', 2);
                }

                $pvMlp = bcsub($initialNi, $pvUnguaranteed, 2);
                $cogs  = bcsub($carryingAmount, $pvUnguaranteed, 2);

                // D-LESSOR-3-NET-METHOD: NET method consistently applied
                // for both inception and period JEs. The spec narrative
                // §24.4 mixes NET on DR (NI = initial_fair_value) with
                // GROSS on CR (Unearned = cash-gross − net) — that pair
                // cannot balance under double-entry. Smoke caught this
                // as a $72,001 invariant failure. Operator-locked at
                // S-ACCT-LESSOR-3 pre-flight AskUserQuestion: drop
                // Unearned Finance Income from inception + period JEs;
                // leave the Unearned LESSOR-1 setting configured for
                // future gross-presentation disclosure reports.
                $unearnedFinanceIncome = '0.00';   // intentionally not posted

                // ── Resolve GL accounts ─────────────────────────────
                $niCurrentAcc = self::requireAccountId('accounting.lessor_ni_current_account_id',  'NI Lease — Current');
                $niLongTermAcc = self::requireAccountId('accounting.lessor_ni_longterm_account_id','NI Lease — Long-Term');
                $salesRevAcc  = self::requireAccountId('accounting.lessor_sales_revenue_account_id','Sales Revenue — Lease-to-Own');
                $cogsAcc      = self::requireAccountId('accounting.lessor_cogs_account_id',         'COGS — Fleet (Lease-to-Own)');
                $assetAcc     = (int) $asset['asset_account_id'];
                $accumDepAcc  = (int) $asset['accum_depr_account_id'];

                // ── Build JE lines (skip $0 lines for clean GL ledger) ─
                $jeLines = [];
                if (bccomp($niCurrent, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $niCurrentAcc,  'debit' => $niCurrent,    'credit' => '0.00',
                        'description' => "Net Investment in Lease (current) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($niLongTerm, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $niLongTermAcc, 'debit' => $niLongTerm,   'credit' => '0.00',
                        'description' => "Net Investment in Lease (long-term) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($accumulatedDep, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $accumDepAcc,   'debit' => $accumulatedDep, 'credit' => '0.00',
                        'description' => "Accumulated depreciation cleared — asset disposed via lease {$lease['contract_number']}",
                    ];
                }
                if (bccomp($cogs, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $cogsAcc,       'debit' => $cogs,         'credit' => '0.00',
                        'description' => "COGS — Fleet (Lease-to-Own) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($acquisitionCost, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $assetAcc,      'debit' => '0.00',         'credit' => $acquisitionCost,
                        'description' => "Asset cost derecognized — lease {$lease['contract_number']}",
                    ];
                }
                if (bccomp($pvMlp, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $salesRevAcc,   'debit' => '0.00',         'credit' => $pvMlp,
                        'description' => "Sales revenue (PV of MLP) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                // Unearned Finance Income line intentionally omitted per
                // D-LESSOR-3-NET-METHOD (see derivation comment above).

                // ── INVARIANT: Σ DR = Σ CR ─────────────────────────
                $totalDr = '0.00';
                $totalCr = '0.00';
                foreach ($jeLines as $l) {
                    $totalDr = bcadd($totalDr, (string) $l['debit'],  2);
                    $totalCr = bcadd($totalCr, (string) $l['credit'], 2);
                }
                if (bccomp($totalDr, $totalCr, 2) !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Sales-type inception JE unbalanced: DR=%s, CR=%s, diff=%s. '
                        . 'Breakdown: NI_cur=%s NI_lt=%s AccDep=%s COGS=%s Cost=%s SalesRev=%s Unearned=%s.',
                        $totalDr, $totalCr, bcsub($totalDr, $totalCr, 2),
                        $niCurrent, $niLongTerm, $accumulatedDep, $cogs,
                        $acquisitionCost, $pvMlp, $unearnedFinanceIncome
                    ));
                }

                // ── Post main inception JE ──────────────────────────
                $periodInfo = self::resolvePeriod($lease['start_date']);
                $je = JournalEntryService::create([
                    'entry_date'       => $periodInfo['entry_date'],
                    'description'      => "Sales-type lease inception — {$lease['contract_number']}",
                    'entry_type'       => 'system',
                    'reference'        => "LSE-INC-{$lease['contract_number']}",
                    'source_type'      => 'lease_inception',
                    'source_id'        => $leaseId,
                    'post_immediately' => true,
                ], $jeLines, $userId);

                // ── Dispose the underlying asset ────────────────────
                $disposalNote = ($asset['notes'] ? $asset['notes'] . "\n" : '')
                    . "Disposed via sales-type lease {$lease['contract_number']} on " . date('Y-m-d')
                    . ". JE {$je['entry_number']}.";
                \db_execute(
                    "UPDATE acc_fixed_assets
                        SET status                 = 'disposed',
                            fully_depreciated_date = COALESCE(fully_depreciated_date, ?),
                            notes                  = ?
                      WHERE id = ?",
                    [date('Y-m-d'), $disposalNote, (int) $asset['id']]
                );

                // ── IDC handling (D-LESSOR-3-IDC-TREATMENT) ─────────
                $idcJeId = null;
                $idcAmount = (string) $lease['initial_direct_costs'];
                if (bccomp($idcAmount, '0', 2) > 0) {
                    $sellingExpAcc = self::requireAccountId('accounting.lessor_selling_expense_account_id', 'Selling Expense — Lease Origination');
                    // Default offset = AR (1030). When the broker bill is paid via AP,
                    // the standard AP-payment flow reverses the AR clear.
                    $idcOffsetAcc = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');

                    $idcLines = [
                        [
                            'account_id'  => $sellingExpAcc, 'debit' => $idcAmount, 'credit' => '0.00',
                            'description' => "Initial direct costs — lease {$lease['contract_number']}",
                            'customer_id' => $lease['customer_id'],
                        ],
                        [
                            'account_id'  => $idcOffsetAcc, 'debit' => '0.00', 'credit' => $idcAmount,
                            'description' => "IDC accrued pending vendor bill — {$lease['contract_number']}",
                            'customer_id' => $lease['customer_id'],
                        ],
                    ];
                    $idcJe = JournalEntryService::create([
                        'entry_date'       => $periodInfo['entry_date'],
                        'description'      => "Sales-type IDC — {$lease['contract_number']}",
                        'entry_type'       => 'system',
                        'reference'        => "LSE-IDC-{$lease['contract_number']}",
                        'source_type'      => 'lease_inception',
                        'source_id'        => $leaseId,
                        'post_immediately' => true,
                    ], $idcLines, $userId);
                    $idcJeId = (int) $idcJe['id'];
                }

                // ── Audit log ───────────────────────────────────────
                $sellingProfit = bcsub($pvMlp, $cogs, 2);
                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'       => sprintf(
                        'Sales-type inception JE %s: initial_NI=%s, NI_cur=%s, NI_lt=%s, COGS=%s, sales_rev=%s, selling_profit=%s. Asset #%d disposed. IDC JE: %s.',
                        $je['entry_number'], $initialNi, $niCurrent, $niLongTerm,
                        $cogs, $pvMlp, $sellingProfit, (int) $asset['id'],
                        $idcJeId !== null ? (string) $idcJeId : 'none ($0 IDC)'
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'je'             => $je,
                    'asset_disposed' => (int) $asset['id'],
                    'idc_je_id'      => $idcJeId,
                    'breakdown'      => [
                        'initial_ni'             => $initialNi,
                        'ni_current'             => $niCurrent,
                        'ni_long_term'           => $niLongTerm,
                        'acquisition_cost'       => $acquisitionCost,
                        'accumulated_dep'        => $accumulatedDep,
                        'carrying_amount'        => $carryingAmount,
                        'pv_unguaranteed'        => $pvUnguaranteed,
                        'pv_mlp'                 => $pvMlp,
                        'cogs'                   => $cogs,
                        'unearned_finance'       => $unearnedFinanceIncome,
                        'gross_investment'       => $grossInvestment,
                        'selling_profit'         => $sellingProfit,
                    ],
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_inception_{$leaseId}"]);
            }
        });
    }

    /**
     * Period-end JE for a single amortization schedule row (ASPE 3065
     * §24.4). Generic for sales_type AND direct_financing per
     * D-LESSOR-3-PERIOD-GENERIC — LESSOR-4 reuses this method unchanged.
     *
     * Two-leg per period:
     *   DR Accounts Receivable     cash_receipt      (cash is recognized
     *   CR NI — Current            principal_reduction   when the invoice
     *   DR Unearned Finance Income finance_income     is paid via the
     *   CR Finance Income          finance_income     existing AR flow)
     *
     * Idempotent: if the schedule row is already status='posted', the
     * existing JE is returned (no double-post). status='reversed' throws.
     *
     * @return array|null  { je, period } or null when gates are off
     * @throws \RuntimeException  invariant fail, reversed row, etc.
     */
    public static function onLeasePeriodPosting_Capital(
        int $leaseId,
        int $periodNumber,
        ?int $userId = null
    ): ?array {
        if (!self::isEnabled())             return null;
        if (!self::isLessorModuleEnabled()) {
            error_log("[AutoEntryBridge::onLeasePeriodPosting_Capital] lessor module disabled — lease #{$leaseId} period {$periodNumber} skipped");
            return null;
        }

        return \db_transaction(function () use ($leaseId, $periodNumber, $userId): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_period_{$leaseId}_{$periodNumber}"]);

            try {
                $lease = \db_row(
                    "SELECT id, contract_number, customer_id, classification, deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \RuntimeException("Lease #{$leaseId} not found.");
                }
                if (!in_array($lease['classification'], ['sales_type', 'direct_financing'], true)) {
                    throw new \RuntimeException(
                        "onLeasePeriodPosting_Capital called on lease #{$leaseId} with classification "
                        . "'{$lease['classification']}' — capital classifications only."
                    );
                }

                $row = \db_row(
                    "SELECT id, period_number, period_date,
                            cash_receipt, finance_income, principal_reduction,
                            opening_net_investment, closing_net_investment,
                            status, posted_je_id
                       FROM acc_lease_amortization_schedules
                      WHERE lease_id = ? AND period_number = ?
                      FOR UPDATE",
                    [$leaseId, $periodNumber]
                );
                if (!$row) {
                    throw new \RuntimeException(
                        "No schedule row for lease #{$leaseId} period {$periodNumber}."
                    );
                }
                if ($row['status'] === 'posted') {
                    // Idempotent path — return the existing JE.
                    $existingJe = \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [(int) $row['posted_je_id']]);
                    return ['je' => $existingJe, 'period' => $periodNumber];
                }
                if ($row['status'] === 'reversed') {
                    throw new \RuntimeException(
                        "Cannot re-post period {$periodNumber} for lease #{$leaseId} — schedule row is reversed."
                    );
                }

                $cash    = (string) $row['cash_receipt'];
                $finance = (string) $row['finance_income'];
                // Principal_reduction is DERIVED here (not pulled from the
                // schedule's stored column) so the per-row identity
                //   cash = principal + finance
                // holds for every JE — including the last regular period
                // where LeaseAmortizationService::buildSchedule applies a
                // rounding-tail correction that bumps stored principal
                // above (cash − finance) to land closing_ni on
                // guaranteed_residual_value. That stored projection is
                // for UI display only; the GL post must balance per row.
                // Residual NI remaining at term end (the unguaranteed
                // residual portion absorbed by the schedule projection)
                // is written off by onLeaseTermination — already designed
                // for the closing_net_investment > 0 case.
                $principal = bcsub($cash, $finance, 2);

                // ── D-LESSOR-4-PERIOD-EXTENDED: DF IDC amortization ─
                // Sales-type periods keep the 3-line LESSOR-3 shape.
                // Direct-financing leases with deferred IDC > 0 take one
                // line of finance income each period and re-route it to
                // amortize the Deferred IDC asset (capitalized at
                // inception per D-LESSOR-4-IDC-DEFERRAL). The cash and
                // principal lines are untouched — the JE still balances
                // because adjustedFinance + idcAmort = original finance.
                //
                // Per-period amort = initial_direct_costs / termMonths
                // (D-LESSOR-4-IDC-AMORT-STRAIGHT-LINE). Last regular
                // period absorbs the rounding tail by setting
                // amort = initial_direct_costs − Σ(prior amortizations).
                // BPO row (period > termMonths) gets amort = 0 — IDC is
                // already fully amortized by end of regular term.
                $idcAmortThisPeriod  = '0.00';
                $adjustedFinance     = $finance;
                $deferredIdcAcc      = null;
                $needsIdcAmort       = false;

                if ($lease['classification'] === 'direct_financing') {
                    $leaseFull = \db_row(
                        "SELECT initial_direct_costs FROM leases WHERE id = ?",
                        [$leaseId]
                    );
                    $idcTotal = (string) ($leaseFull['initial_direct_costs'] ?? '0.00');
                    if (bccomp($idcTotal, '0', 2) > 0) {
                        $needsIdcAmort = true;
                        $clsRow = \db_row(
                            "SELECT criterion_b_lease_term_months
                               FROM acc_lease_classifications WHERE lease_id = ?",
                            [$leaseId]
                        );
                        $termMonths = (int) ($clsRow['criterion_b_lease_term_months'] ?? 0);
                        if ($termMonths <= 0) {
                            throw new \RuntimeException(
                                "Direct-financing lease #{$leaseId} has no classification row "
                                . "or zero term_months — cannot compute IDC amortization."
                            );
                        }

                        if ($periodNumber > $termMonths) {
                            // BPO row — IDC already fully amortized at period termMonths.
                            $idcAmortThisPeriod = '0.00';
                        } elseif ($periodNumber === $termMonths) {
                            // Last regular period absorbs the rounding tail.
                            $deferredIdcAcc = self::requireAccountId(
                                'accounting.lessor_deferred_idc_account_id',
                                'Deferred Initial Direct Costs — Lease'
                            );
                            $prior = \db_row(
                                "SELECT COALESCE(SUM(jel.credit), '0.00') AS prior_amort
                                   FROM acc_journal_entry_lines jel
                                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                                  WHERE je.source_type = 'lease_period'
                                    AND je.source_id   = ?
                                    AND jel.account_id = ?",
                                [$leaseId, $deferredIdcAcc]
                            );
                            $priorAmort = (string) ($prior['prior_amort'] ?? '0.00');
                            $idcAmortThisPeriod = bcsub($idcTotal, $priorAmort, 2);
                            // Defensive: never negative (would imply prior over-amortization).
                            if (bccomp($idcAmortThisPeriod, '0', 2) < 0) {
                                $idcAmortThisPeriod = '0.00';
                            }
                        } else {
                            // Regular interior period — straight-line slice.
                            $idcAmortThisPeriod = bcdiv($idcTotal, (string) $termMonths, 2);
                        }

                        if (bccomp($idcAmortThisPeriod, '0', 2) > 0) {
                            $deferredIdcAcc = $deferredIdcAcc ?: self::requireAccountId(
                                'accounting.lessor_deferred_idc_account_id',
                                'Deferred Initial Direct Costs — Lease'
                            );
                            $adjustedFinance = bcsub($finance, $idcAmortThisPeriod, 2);
                            // STOP CONDITION (warn-only): IDC amort exceeds period's
                            // finance income — produces negative adjusted finance.
                            // The JE still balances (CR side: principal +
                            // negative-finance + idcAmort = principal + finance =
                            // cash). Log for operator review.
                            if (bccomp($adjustedFinance, '0', 2) < 0) {
                                error_log(sprintf(
                                    '[onLeasePeriodPosting_Capital] lease=%d period=%d: IDC amort (%s) '
                                    . 'exceeds finance_income (%s); adjusted=%s. JE still balances.',
                                    $leaseId, $periodNumber, $idcAmortThisPeriod, $finance, $adjustedFinance
                                ));
                            }
                        }
                    }
                }

                // ── Resolve GL accounts ────────────────────────────
                $arAcc        = self::requireAccountId('accounting.ar_account_id', 'Accounts Receivable');
                $niCurrentAcc = self::requireAccountId('accounting.lessor_ni_current_account_id', 'NI Lease — Current');
                $financeIncAcc = self::requireAccountId('accounting.lessor_finance_income_account_id', 'Finance Income');

                // ── Build JE lines (D-LESSOR-3-NET-METHOD + D-LESSOR-4-PERIOD-EXTENDED) ──
                // Sales-type / DF-without-IDC: 3-line NET-method JE:
                //   DR AR             $cash
                //      CR NI (current) $principal       (= cash − finance)
                //      CR Finance Income $finance        (recognized this period)
                // DF with IDC amort: 4-line variant — finance line is reduced
                // by $idcAmortThisPeriod, and a 4th line CR Deferred IDC
                // absorbs the difference. Net effect: same cash + principal,
                // finance income is "moved" into IDC reduction.
                // Sums: DR = cash, CR = principal + adjustedFinance + idcAmort
                //                   = principal + (finance − idcAmort) + idcAmort
                //                   = principal + finance = cash ✓
                $jeLines = [];
                if (bccomp($cash, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id'  => $arAcc,        'debit' => $cash,      'credit' => '0.00',
                        'description' => "AR — Lease {$lease['contract_number']} period {$periodNumber}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($principal, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id'  => $niCurrentAcc, 'debit' => '0.00',     'credit' => $principal,
                        'description' => "NI principal reduction — period {$periodNumber}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                // Adjusted finance income — may be 0 (BPO row), positive
                // (regular), or negative (rare: IDC amort > finance income
                // for that period). bccomp !== 0 catches both positive and
                // negative; the sign determines whether it lands on debit
                // or credit side of the JE for proper double-entry.
                if (bccomp($adjustedFinance, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id'  => $financeIncAcc, 'debit' => '0.00',    'credit' => $adjustedFinance,
                        'description' => "Finance income recognized — period {$periodNumber}"
                            . ($needsIdcAmort ? ' (net of IDC amort)' : ''),
                        'customer_id' => $lease['customer_id'],
                    ];
                } elseif (bccomp($adjustedFinance, '0', 2) < 0) {
                    // Negative adjusted finance → DR Finance Income (revenue reduction).
                    $jeLines[] = [
                        'account_id'  => $financeIncAcc, 'debit' => bcmul($adjustedFinance, '-1', 2), 'credit' => '0.00',
                        'description' => "Finance income reduced — period {$periodNumber} (IDC amort exceeds period interest)",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($idcAmortThisPeriod, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id'  => $deferredIdcAcc, 'debit' => '0.00', 'credit' => $idcAmortThisPeriod,
                        'description' => "Deferred IDC amortization (straight-line) — period {$periodNumber}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }

                // ── INVARIANT: Σ DR = Σ CR ─────────────────────────
                $totalDr = '0.00';
                $totalCr = '0.00';
                foreach ($jeLines as $l) {
                    $totalDr = bcadd($totalDr, (string) $l['debit'],  2);
                    $totalCr = bcadd($totalCr, (string) $l['credit'], 2);
                }
                if (bccomp($totalDr, $totalCr, 2) !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Lease period JE unbalanced: lease=%d period=%d DR=%s CR=%s diff=%s '
                        . '(cash=%s, principal=%s, finance=%s, idc_amort=%s, adjusted_finance=%s).',
                        $leaseId, $periodNumber, $totalDr, $totalCr,
                        bcsub($totalDr, $totalCr, 2),
                        $cash, $principal, $finance, $idcAmortThisPeriod, $adjustedFinance
                    ));
                }

                // ── Post period JE ──────────────────────────────────
                $periodInfo = self::resolvePeriod((string) $row['period_date']);
                $je = JournalEntryService::create([
                    'entry_date'       => $periodInfo['entry_date'],
                    'description'      => "Lease period {$periodNumber} — {$lease['contract_number']}",
                    'entry_type'       => 'system',
                    'reference'        => "LSE-{$lease['contract_number']}-P{$periodNumber}",
                    'source_type'      => 'lease_period',
                    'source_id'        => $leaseId,
                    'post_immediately' => true,
                ], $jeLines, $userId);

                \db_execute(
                    "UPDATE acc_lease_amortization_schedules
                        SET status = 'posted', posted_je_id = ?
                      WHERE id = ?",
                    [(int) $je['id'], (int) $row['id']]
                );

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'       => sprintf(
                        'Lease period %d posted: JE %s, cash=%s, principal=%s, finance=%s%s.',
                        $periodNumber, $je['entry_number'], $cash, $principal, $finance,
                        $needsIdcAmort
                            ? sprintf(', idc_amort=%s, adjusted_finance=%s', $idcAmortThisPeriod, $adjustedFinance)
                            : ''
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'je'                  => $je,
                    'period'              => $periodNumber,
                    'idc_amort'           => $idcAmortThisPeriod,
                    'adjusted_finance'    => $adjustedFinance,
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_period_{$leaseId}_{$periodNumber}"]);
            }
        });
    }

    /**
     * Lease termination JE — fires from close.php after active→completed.
     *
     * Most of the lease's cash flow has already been captured by the
     * regular period JEs (including the BPO settlement row, if any).
     * This method handles the residual edge cases:
     *
     *   - Final closing_net_investment == 0  → no JE needed; logs only.
     *   - Final closing_net_investment > 0   → write off the remaining NI
     *     (the unguaranteed residual the lessor never collected). DR a
     *     loss account, CR NI Long-Term (where the residual lives at
     *     term end).
     *
     * Idempotent: returns existing JE when source_type='lease_termination'
     * + source_id=$leaseId already exists.
     *
     * @return array|null { je, residual_written_off } or null when gates off
     */
    public static function onLeaseTermination(int $leaseId, ?int $userId = null): ?array
    {
        if (!self::isEnabled())             return null;
        if (!self::isLessorModuleEnabled()) {
            error_log("[AutoEntryBridge::onLeaseTermination] lessor module disabled — lease #{$leaseId} skipped");
            return null;
        }

        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'lease_termination' AND source_id = ?
              LIMIT 1",
            [$leaseId]
        );
        if ($existing) {
            return ['je' => $existing, 'residual_written_off' => null];
        }

        $lease = \db_row(
            "SELECT id, contract_number, customer_id, classification, status, deleted_at
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        if (!$lease || $lease['deleted_at'] !== null) {
            throw new \RuntimeException("Lease #{$leaseId} not found.");
        }
        if (!in_array($lease['classification'], ['sales_type', 'direct_financing'], true)) {
            throw new \RuntimeException(
                "onLeaseTermination called on non-capital lease #{$leaseId} (classification='{$lease['classification']}')."
            );
        }
        if ($lease['status'] !== 'completed') {
            throw new \RuntimeException(
                "Lease #{$leaseId} is not in 'completed' status (current: '{$lease['status']}')."
            );
        }

        $lastRow = \db_row(
            "SELECT closing_net_investment
               FROM acc_lease_amortization_schedules
              WHERE lease_id = ?
              ORDER BY period_number DESC
              LIMIT 1",
            [$leaseId]
        );
        $closingNi = $lastRow ? (string) $lastRow['closing_net_investment'] : '0.00';

        if (bccomp($closingNi, '0', 2) <= 0) {
            // Nothing to post — schedule already zeroed out.
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'lease',
                'entity_id'   => $leaseId,
                'entity_label' => $lease['contract_number'],
                'notes'       => "Lease termination — NI already zero, no JE needed.",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            return ['je' => null, 'residual_written_off' => '0.00', 'reason' => 'NI already zero'];
        }

        // Write off the remaining NI. Use a loss account; D-LESSOR-3 falls
        // back to the bad-debt expense account when no specific loss
        // account is configured (operator can wire a 6290 'Loss on Lease
        // Termination' later via a settings key without code change).
        $lossAccountId = (int) (AccountingService::setting('accounting.lessor_termination_loss_account_id')
                              ?? AccountingService::setting('accounting.bad_debt_expense_account_id'));
        if ($lossAccountId <= 0) {
            $lossAccountId = self::requireAccountId('accounting.bad_debt_expense_account_id', 'Bad Debt Expense');
        }
        $niLongTermAcc = self::requireAccountId('accounting.lessor_ni_longterm_account_id', 'NI Lease — Long-Term');

        $jeLines = [
            [
                'account_id'  => $lossAccountId, 'debit' => $closingNi, 'credit' => '0.00',
                'description' => "Unguaranteed residual write-off — lease {$lease['contract_number']}",
                'customer_id' => $lease['customer_id'],
            ],
            [
                'account_id'  => $niLongTermAcc, 'debit' => '0.00', 'credit' => $closingNi,
                'description' => "NI Long-Term cleared — lease terminated {$lease['contract_number']}",
                'customer_id' => $lease['customer_id'],
            ],
        ];

        $totalDr = bcadd($jeLines[0]['debit'], $jeLines[1]['debit'], 2);
        $totalCr = bcadd($jeLines[0]['credit'], $jeLines[1]['credit'], 2);
        if (bccomp($totalDr, $totalCr, 2) !== 0) {
            throw new \RuntimeException("Termination JE unbalanced: DR={$totalDr}, CR={$totalCr}.");
        }

        $periodInfo = self::resolvePeriod(date('Y-m-d'));
        $je = JournalEntryService::create([
            'entry_date'       => $periodInfo['entry_date'],
            'description'      => "Lease termination — {$lease['contract_number']}",
            'entry_type'       => 'system',
            'reference'        => "LSE-TERM-{$lease['contract_number']}",
            'source_type'      => 'lease_termination',
            'source_id'        => $leaseId,
            'post_immediately' => true,
        ], $jeLines, $userId);

        \db_insert('audit_log', [
            'user_id'     => $userId,
            'action'      => 'status_change',
            'module'      => 'accounting',
            'entity_type' => 'lease',
            'entity_id'   => $leaseId,
            'entity_label' => $lease['contract_number'],
            'notes'       => "Lease termination JE {$je['entry_number']}: unguaranteed residual written off = \${$closingNi}.",
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return ['je' => $je, 'residual_written_off' => $closingNi];
    }

    /**
     * Direct-financing lease inception JE (ASPE 3065 §24.5).
     *
     * Posted on transition pending→active when classification=
     * 'direct_financing'. Mirrors onLeaseInception_SalesType structure
     * but with three differences locked by D-LESSOR-3 + D-LESSOR-4:
     *
     *   - No COGS line (no selling profit at inception; FV = carrying)
     *   - No Sales Revenue line (no revenue recognized at inception)
     *   - No separate IDC expense JE (D-LESSOR-4-IDC-DEFERRAL — IDC is
     *     CAPITALIZED as a deferred asset, then amortized straight-line
     *     over the lease term as a yield adjustment to finance income;
     *     handled by onLeasePeriodPosting_Capital's DF branch per
     *     D-LESSOR-4-PERIOD-EXTENDED)
     *
     * Asset derecognition uses the dual-leg pattern (D-LESSOR-3-INVENTORY):
     * DR accum_dep + CR asset_cost — net PP&E reduction = NBV exactly.
     * acc_fixed_assets.status flipped to 'disposed'.
     *
     * STOP CONDITION: initial_fair_value + IDC must equal carrying_amount
     * within $0.02. For DF leases, LESSOR-2's solveImplicitRate uses
     * pvTarget = FV − IDC; the operator's FV input MUST equal carrying
     * for the implicit rate to amortize the receivable to zero by term
     * end. A drift > $0.02 means either the rate solver didn't apply
     * the IDC offset OR the operator entered a non-DF-shaped FV — both
     * cases require classification re-review.
     *
     * Idempotent: returns existing JE row when source_type='lease_inception'
     * + source_id=$leaseId already exists.
     *
     * @return array|null  { je, asset_disposed, idc_deferred, breakdown }
     *                     or null when bridge / lessor module is disabled
     * @throws \RuntimeException  Σ DR ≠ Σ CR; asset missing/ambiguous;
     *                             NI mismatch; called on non-DF lease
     */
    public static function onLeaseInception_DirectFinancing(int $leaseId, ?int $userId = null): ?array
    {
        if (!self::isEnabled())             return null;
        if (!self::isLessorModuleEnabled()) {
            error_log("[AutoEntryBridge::onLeaseInception_DirectFinancing] lessor module disabled — JE skipped for lease #{$leaseId}");
            return null;
        }

        // Idempotency guard — already posted?
        $existing = \db_row(
            "SELECT * FROM acc_journal_entries
              WHERE source_type = 'lease_inception' AND source_id = ?
              LIMIT 1",
            [$leaseId]
        );
        if ($existing) {
            return ['je' => $existing, 'asset_disposed' => null, 'idc_deferred' => null];
        }

        return \db_transaction(function () use ($leaseId, $userId): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_inception_{$leaseId}"]);

            try {
                $lease = \db_row(
                    "SELECT id, contract_number, customer_id, equipment_unit_id, classification,
                            initial_fair_value, initial_direct_costs,
                            guaranteed_residual_value, unguaranteed_residual_value,
                            implicit_rate, start_date, deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \RuntimeException("AutoEntryBridge::onLeaseInception_DirectFinancing: lease #{$leaseId} not found.");
                }
                if ($lease['classification'] !== 'direct_financing') {
                    throw new \RuntimeException(
                        "AutoEntryBridge::onLeaseInception_DirectFinancing called on non-direct_financing lease #{$leaseId} "
                        . "(classification='{$lease['classification']}')."
                    );
                }

                $schedule = LeaseAmortizationService::getSchedule($leaseId);
                if (empty($schedule['periods'])) {
                    throw new \RuntimeException(
                        "Lease #{$leaseId} has no amortization schedule. "
                        . "LESSOR-2 should have generated it at activation — investigate."
                    );
                }

                $assets = \db_select(
                    "SELECT id, asset_account_id, accum_depr_account_id,
                            acquisition_cost, accumulated_depreciation, net_book_value,
                            status, notes
                       FROM acc_fixed_assets
                      WHERE equipment_unit_id = ? AND status = 'active'",
                    [(int) $lease['equipment_unit_id']]
                );
                if (count($assets) === 0) {
                    throw new \RuntimeException(
                        "No active fixed asset linked to equipment unit #{$lease['equipment_unit_id']}. "
                        . "Asset must exist before direct-financing inception JE can post."
                    );
                }
                if (count($assets) > 1) {
                    throw new \RuntimeException(
                        "Ambiguous asset linkage for unit #{$lease['equipment_unit_id']} — "
                        . count($assets) . ' active assets found.'
                    );
                }
                $asset = $assets[0];

                // ── Derive amounts ──────────────────────────────────
                $acquisitionCost = (string) $asset['acquisition_cost'];
                $accumulatedDep  = (string) $asset['accumulated_depreciation'];
                $carryingAmount  = bcsub($acquisitionCost, $accumulatedDep, 2);
                $idc             = (string) $lease['initial_direct_costs'];
                $initialNi       = (string) $lease['initial_fair_value'];

                // ── STOP CONDITION: NI + IDC must match carrying ────
                // For DF leases: implicit-rate solver uses pvTarget =
                // FV − IDC. The economic identity at inception is:
                //   initial_fair_value + IDC = carrying_amount
                // i.e. the receivable + capitalized IDC must equal what
                // we're derecognizing from PP&E. A drift > $0.02 means
                // either the operator's FV entry was inconsistent with
                // the DF treatment, or the rate solver didn't apply the
                // IDC offset — both require classification re-review.
                $expectedNi = bcsub($carryingAmount, $idc, 2);
                $drift = bcsub($initialNi, $expectedNi, 2);
                $absDrift = $drift[0] === '-' ? substr($drift, 1) : $drift;
                if (bccomp($absDrift, '0.02', 2) > 0) {
                    throw new \RuntimeException(sprintf(
                        'Direct financing NI mismatch: initial_fair_value=%s, carrying_amount=%s, '
                        . 'IDC=%s, expected NI=%s, drift=%s. For DF leases, implicit_rate solver '
                        . 'target must be carrying_amount − IDC (LESSOR-2 pvTarget = FV − IDC).',
                        $initialNi, $carryingAmount, $idc, $expectedNi, $drift
                    ));
                }

                // NI split via schedule — same logic as sales-type.
                $niCurrent = '0.00';
                foreach ($schedule['periods'] as $p) {
                    if ((int) $p['period_number'] > 12) break;
                    $niCurrent = bcadd($niCurrent, (string) $p['principal_reduction'], 2);
                }
                if (bccomp($niCurrent, $initialNi, 2) > 0) {
                    $niCurrent = $initialNi;
                }
                $niLongTerm = bcsub($initialNi, $niCurrent, 2);

                // ── Resolve GL accounts ─────────────────────────────
                $niCurrentAcc  = self::requireAccountId('accounting.lessor_ni_current_account_id',  'NI Lease — Current');
                $niLongTermAcc = self::requireAccountId('accounting.lessor_ni_longterm_account_id', 'NI Lease — Long-Term');
                $assetAcc      = (int) $asset['asset_account_id'];
                $accumDepAcc   = (int) $asset['accum_depr_account_id'];
                $deferredIdcAcc = bccomp($idc, '0', 2) > 0
                    ? self::requireAccountId('accounting.lessor_deferred_idc_account_id', 'Deferred Initial Direct Costs — Lease')
                    : null;

                // ── Build JE lines (NET method per D-LESSOR-3-NET-METHOD) ─
                // DF inception (D-LESSOR-4-IDC-DEFERRAL):
                //   DR NI_current
                //   DR NI_long_term
                //   DR Deferred IDC                  (if IDC > 0)
                //   DR Accumulated Depreciation
                //   CR Asset Cost
                // NO COGS, NO Sales Revenue, NO Unearned Finance Income.
                // Balance: Σ DR = initialNi + IDC + accumDep
                //                = (carrying − IDC) + IDC + accumDep    (by STOP-condition identity)
                //                = carrying + accumDep
                //                = acquisitionCost − accumDep + accumDep
                //                = acquisitionCost
                //                = Σ CR  ✓
                $jeLines = [];
                if (bccomp($niCurrent, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $niCurrentAcc,  'debit' => $niCurrent,    'credit' => '0.00',
                        'description' => "Net Investment in Lease (current) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($niLongTerm, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $niLongTermAcc, 'debit' => $niLongTerm,   'credit' => '0.00',
                        'description' => "Net Investment in Lease (long-term) — {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if ($deferredIdcAcc !== null && bccomp($idc, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $deferredIdcAcc, 'debit' => $idc,         'credit' => '0.00',
                        'description' => "Deferred initial direct costs — lease {$lease['contract_number']}",
                        'customer_id' => $lease['customer_id'],
                    ];
                }
                if (bccomp($accumulatedDep, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $accumDepAcc,   'debit' => $accumulatedDep, 'credit' => '0.00',
                        'description' => "Accumulated depreciation cleared — asset disposed via DF lease {$lease['contract_number']}",
                    ];
                }
                if (bccomp($acquisitionCost, '0', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $assetAcc,      'debit' => '0.00',         'credit' => $acquisitionCost,
                        'description' => "Asset cost derecognized — DF lease {$lease['contract_number']}",
                    ];
                }

                // ── INVARIANT: Σ DR = Σ CR ─────────────────────────
                $totalDr = '0.00';
                $totalCr = '0.00';
                foreach ($jeLines as $l) {
                    $totalDr = bcadd($totalDr, (string) $l['debit'],  2);
                    $totalCr = bcadd($totalCr, (string) $l['credit'], 2);
                }
                if (bccomp($totalDr, $totalCr, 2) !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Direct-financing inception JE unbalanced: DR=%s, CR=%s, diff=%s. '
                        . 'Breakdown: NI_cur=%s NI_lt=%s IDC=%s AccDep=%s Cost=%s carrying=%s.',
                        $totalDr, $totalCr, bcsub($totalDr, $totalCr, 2),
                        $niCurrent, $niLongTerm, $idc, $accumulatedDep,
                        $acquisitionCost, $carryingAmount
                    ));
                }

                // ── Post JE ─────────────────────────────────────────
                $periodInfo = self::resolvePeriod($lease['start_date']);
                $je = JournalEntryService::create([
                    'entry_date'       => $periodInfo['entry_date'],
                    'description'      => "Direct financing lease inception — {$lease['contract_number']}",
                    'entry_type'       => 'system',
                    'reference'        => "LSE-DF-INC-{$lease['contract_number']}",
                    'source_type'      => 'lease_inception',
                    'source_id'        => $leaseId,
                    'post_immediately' => true,
                ], $jeLines, $userId);

                // ── Dispose the underlying asset ────────────────────
                $disposalNote = ($asset['notes'] ? $asset['notes'] . "\n" : '')
                    . "Disposed via direct-financing lease {$lease['contract_number']} on " . date('Y-m-d')
                    . ". JE {$je['entry_number']}.";
                \db_execute(
                    "UPDATE acc_fixed_assets
                        SET status                 = 'disposed',
                            fully_depreciated_date = COALESCE(fully_depreciated_date, ?),
                            notes                  = ?
                      WHERE id = ?",
                    [date('Y-m-d'), $disposalNote, (int) $asset['id']]
                );

                // ── Audit log ───────────────────────────────────────
                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'       => sprintf(
                        'Direct-financing inception JE %s: initial_NI=%s, NI_cur=%s, NI_lt=%s, IDC_deferred=%s, AccDep=%s, AssetCost=%s. Asset #%d disposed.',
                        $je['entry_number'], $initialNi, $niCurrent, $niLongTerm,
                        $idc, $accumulatedDep, $acquisitionCost, (int) $asset['id']
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'je'             => $je,
                    'asset_disposed' => (int) $asset['id'],
                    'idc_deferred'   => $idc,
                    'breakdown'      => [
                        'initial_ni'        => $initialNi,
                        'ni_current'        => $niCurrent,
                        'ni_long_term'      => $niLongTerm,
                        'idc_deferred'      => $idc,
                        'acquisition_cost'  => $acquisitionCost,
                        'accumulated_dep'   => $accumulatedDep,
                        'carrying_amount'   => $carryingAmount,
                    ],
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_inception_{$leaseId}"]);
            }
        });
    }
}
