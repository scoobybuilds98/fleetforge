<?php
declare(strict_types=1);

/**
 * lib/Accounting/AccountingService.php
 *
 * Core accounting helper — shared utilities for the entire accounting module.
 * Provides period lookups, JE number generation, account balance calculations,
 * settings retrieval, and GL account mapping.
 *
 * Required by: All accounting API endpoints, JournalEntryService
 * Defines: FleetForge\Accounting\AccountingService (static methods)
 *
 * Decisions: A4 (fiscal year = calendar), A7 (DECIMAL(15,2)), A8 (JE backbone)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §1-§4, §16-§17
 */

namespace FleetForge\Accounting;

class AccountingService
{
    // ============================================================
    // SETTINGS — read accounting-specific settings from DB
    // Uses backtick-quoted column names (settings table uses reserved words)
    // ============================================================

    /**
     * Get an accounting setting value.
     *
     * @param string $key    Setting key (e.g. 'accounting.ar_account_id')
     * @param mixed  $default Fallback value
     * @return mixed
     */
    public static function setting(string $key, mixed $default = null): mixed
    {
        try {
            $row = \db_row("SELECT `value`, value_type FROM settings WHERE `key` = ?", [$key]);
            if (!$row) return $default;

            return match ($row['value_type']) {
                'integer' => (int) $row['value'],
                'decimal' => $row['value'],     // keep as string for bcmath
                'boolean' => $row['value'] === 'true' || $row['value'] === '1',
                'json'    => json_decode($row['value'], true) ?? $default,
                default   => $row['value'],
            };
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Update an accounting setting value.
     *
     * @param string $key   Setting key
     * @param string $value New value (always stored as string)
     * @return void
     */
    public static function updateSetting(string $key, string $value): void
    {
        \db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$value, $key]
        );
    }

    // ============================================================
    // PERIODS — lookup and validation
    // ============================================================

    /**
     * Get the accounting period for a given date.
     * Returns the period row or null if not found.
     *
     * @param string $date Y-m-d format
     * @return array|null
     */
    public static function periodForDate(string $date): ?array
    {
        return \db_row(
            "SELECT * FROM acc_periods WHERE start_date <= ? AND end_date >= ? LIMIT 1",
            [$date, $date]
        );
    }

    /**
     * Get the current open period (latest open period).
     *
     * @return array|null
     */
    public static function currentOpenPeriod(): ?array
    {
        return \db_row(
            "SELECT * FROM acc_periods WHERE status = 'open' ORDER BY year ASC, month ASC LIMIT 1",
            []
        );
    }

    /**
     * D-GL-REVREC-1 / D-QBO-DATING-1 — the SINGLE SOURCE OF TRUTH for an
     * invoice's revenue-recognition date. Both the internal GL JE
     * (AutoEntryBridge::onInvoiceSent → entry_date) and the QBO push
     * (InvoicePusher → TxnDate) derive their posting date from here so they
     * can never drift.
     *
     * Returns the invoice's issue_date (= billing_period_start) future-guarded:
     * if the issue_date is AFTER business-local today, the recognition date is
     * today — revenue is never posted into a FUTURE accounting period (FF or
     * QBO). "Today" is computed in the BUSINESS timezone
     * (settings.company.timezone, the cron's source), NOT raw UTC, so a
     * late-evening month boundary can't roll the period (the known UTC/local
     * write skew). For normal current-period invoices the guard never fires and
     * the recognition date IS the issue_date.
     *
     * Pure (settings read only); no posting, no FF-period redirect — callers
     * apply resolvePeriod() themselves where the FF ledger needs it.
     */
    public static function recognitionDate(string $issueDate): string
    {
        $today = self::businessToday();

        return ($issueDate > $today) ? $today : $issueDate;
    }

    /**
     * Today's date (Y-m-d) in the BUSINESS timezone (settings.company.timezone,
     * fallback APP_TIMEZONE) — the single "what day is it" source for billing
     * date math (recognition future-guard, late-fee grace days). Extracted from
     * recognitionDate() by S-AUDIT-LIFECYCLE-1 so no caller hand-rolls a
     * server-default-tz date() for money decisions.
     */
    public static function businessToday(): string
    {
        $tzName = (string) (\settings_get('company.timezone', \APP_TIMEZONE) ?? \APP_TIMEZONE);
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone(\APP_TIMEZONE);
        }

        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    /**
     * Validate that a period is open for posting.
     * Returns error string if posting is blocked, null if OK.
     *
     * $allowClosed lets a CLOSED (never a locked) period take the entry. Only
     * the year-end close and its reversal pass it (SOP I6): their entries are
     * dated Dec 31 by definition, and December is normally closed by then —
     * the year-end used to be impossible once December was closed.
     *
     * @param int  $periodId
     * @param bool $allowClosed
     * @return string|null Error message or null
     */
    public static function validatePeriodForPosting(int $periodId, bool $allowClosed = false): ?string
    {
        $period = \db_row("SELECT * FROM acc_periods WHERE id = ?", [$periodId]);
        if (!$period) return 'Period not found.';

        if ($period['status'] === 'locked') {
            return "Period {$period['name']} is locked. No entries can be posted.";
        }

        if ($period['status'] === 'closed' && !$allowClosed) {
            return "Period {$period['name']} is closed. Post into an open month, or ask a Super Admin to reopen the period (Accounting → Periods).";
        }

        return null; // open (or closed and explicitly allowed) — OK to post
    }

    // ============================================================
    // JOURNAL ENTRY NUMBER GENERATION — atomic, gap-free
    // Pattern: JE-YYYY-NNNNN (e.g. JE-2026-00047)
    // ============================================================

    /**
     * Generate the next journal entry number atomically.
     * Must be called inside a db_transaction().
     *
     * @param string $year 4-digit year
     * @return string e.g. "JE-2026-00047"
     */
    public static function nextJeNumber(string $year): string
    {
        $key = "accounting.je_next_number.{$year}";

        // Lock the settings row
        $row = \db_row(
            "SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE",
            [$key]
        );

        $next = $row ? (int) $row['value'] : 1;

        // S-AUDIT-BILLING-ENGINE-1 #15: drift guard (the invoice counter has
        // one; this didn't). A counter behind MAX(entry_number) — seeded rows,
        // restores, manual inserts — produced a 1062 duplicate that 500'd the
        // whole send. Runtime-proven via the closed-period redirect posting
        // into a prior year whose counter was stale.
        $maxRow = \db_row(
            "SELECT MAX(entry_number) AS m FROM acc_journal_entries WHERE entry_number LIKE ?",
            ["JE-{$year}-%"]
        );
        if (!empty($maxRow['m'])) {
            $maxNum = (int) substr((string) strrchr((string) $maxRow['m'], '-'), 1);
            if ($next <= $maxNum) {
                $next = $maxNum + 1;
            }
        }

        $number = sprintf("JE-%s-%05d", $year, $next);

        if ($row) {
            \db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string)($next + 1), $key]);
        } else {
            \db_execute(
                "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES (?, ?, 'integer', 'accounting', 'JE Counter', 'Auto-increment counter', 0)",
                [$key, (string)($next + 1)]
            );
        }

        return $number;
    }

    /**
     * Generate the next bill number atomically.
     * Must be called inside a db_transaction().
     *
     * @param string $year 4-digit year
     * @return string e.g. "BILL-2026-00001"
     */
    public static function nextBillNumber(string $year): string
    {
        return self::nextSequenceNumber('bill', 'BILL', $year);
    }

    /**
     * Generate the next AP payment number atomically.
     *
     * @param string $year 4-digit year
     * @return string e.g. "APAY-2026-00001"
     */
    public static function nextApPaymentNumber(string $year): string
    {
        return self::nextSequenceNumber('ap_payment', 'APAY', $year);
    }

    /**
     * Generate the next deposit number atomically.
     *
     * @param string $year 4-digit year
     * @return string e.g. "DEP-2026-00001"
     */
    public static function nextDepositNumber(string $year): string
    {
        return self::nextSequenceNumber('deposit', 'DEP', $year);
    }

    /**
     * Generate the next vendor credit number atomically.
     *
     * @param string $year 4-digit year
     * @return string e.g. "VCRED-2026-00001"
     */
    public static function nextVendorCreditNumber(string $year): string
    {
        return self::nextSequenceNumber('vendor_credit', 'VCRED', $year);
    }

    /**
     * Table + number column each nextSequenceNumber() entity is stored in —
     * used by the drift guard to find the highest number already issued.
     * None of these tables soft-delete (drafts are hard-deleted, voids keep
     * their row), so MAX over ALL rows is the collision-safe floor.
     */
    private const SEQUENCE_TABLES = [
        'bill'          => ['acc_bills',             'bill_number'],
        'ap_payment'    => ['acc_ap_payments',       'payment_number'],
        'deposit'       => ['acc_customer_deposits', 'deposit_number'],
        'vendor_credit' => ['acc_vendor_credits',    'credit_number'],
    ];

    /**
     * Generic atomic sequence number generator.
     * WHY: Same pattern as invoice numbering (Trap 9) — prevents gaps.
     *
     * Bug #9 drift guard: the settings counter is only a hint. Seeded rows,
     * DB restores, and manual/QBO-imported rows never advance it, so it produced
     * BILL-2026-00005 while BILL-2026-00059 already existed (non-monotonic) — and
     * for a year whose counter row was missing it restarted at 00001 and 500'd on
     * the UNIQUE key. The next number is now max(counter, highest issued + 1),
     * mirroring nextJeNumber(). The numeric tail is compared as an integer (not a
     * string MAX) so mixed-width legacy numbers (e.g. DEP-2023-003) and numbers
     * past 99999 still sort correctly. Serialized by the FOR UPDATE on the
     * counter row (callers must be inside db_transaction()).
     *
     * @param string $entity  Entity key (e.g. 'bill', 'ap_payment')
     * @param string $prefix  Output prefix (e.g. 'BILL', 'APAY')
     * @param string $year    4-digit year
     * @return string
     */
    private static function nextSequenceNumber(string $entity, string $prefix, string $year): string
    {
        $key = "accounting.{$entity}_next_number.{$year}";

        $row = \db_row("SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
        $next = $row ? (int) $row['value'] : 1;

        if (isset(self::SEQUENCE_TABLES[$entity])) {
            [$table, $column] = self::SEQUENCE_TABLES[$entity];
            $maxRow = \db_row(
                "SELECT MAX(CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED)) AS m
                   FROM {$table}
                  WHERE {$column} LIKE ?",
                ["{$prefix}-{$year}-%"]
            );
            $maxNum = (int) ($maxRow['m'] ?? 0);
            if ($next <= $maxNum) {
                $next = $maxNum + 1;
            }
        }

        $number = sprintf("%s-%s-%05d", $prefix, $year, $next);

        if ($row) {
            \db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string)($next + 1), $key]);
        } else {
            \db_execute(
                "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES (?, ?, 'integer', 'accounting', ?, 'Auto-increment counter', 0)",
                [$key, (string)($next + 1), "{$prefix} Counter"]
            );
        }

        return $number;
    }

    /**
     * Find an existing live bill from the SAME vendor that already carries this
     * supplier invoice number (acc_bills.vendor_bill_number).
     *
     * WHY (bug #9): nothing stopped the same supplier invoice being keyed in
     * twice, which double-posts the expense + AP liability when both bills are
     * approved. Scope is per vendor — two different suppliers can legitimately
     * both issue "INV-1001". Void bills are excluded (re-entering a voided
     * invoice is the correct fix-up path); acc_bills has no soft delete (draft
     * deletes are hard deletes). The column collation is case-insensitive
     * (utf8mb4_unicode_ci), so "inv-1001" matches "INV-1001".
     *
     * @param int         $vendorId          vendors.id the bill belongs to
     * @param string|null $vendorBillNumber  supplier's invoice # (null/blank = no check)
     * @param int|null    $excludeBillId     the bill being edited (update path)
     * @return array{id:int, bill_number:string, status:string}|null  the clashing bill
     */
    public static function findDuplicateVendorBill(int $vendorId, ?string $vendorBillNumber, ?int $excludeBillId = null): ?array
    {
        $vendorBillNumber = $vendorBillNumber !== null ? trim($vendorBillNumber) : '';
        if ($vendorBillNumber === '') {
            return null;
        }

        $row = \db_row(
            "SELECT id, bill_number, status
               FROM acc_bills
              WHERE vendor_id = ?
                AND vendor_bill_number = ?
                AND status <> 'void'
                AND id <> ?
              ORDER BY id
              LIMIT 1",
            [$vendorId, $vendorBillNumber, $excludeBillId ?? 0]
        );

        return $row ? ['id' => (int) $row['id'], 'bill_number' => (string) $row['bill_number'], 'status' => (string) $row['status']] : null;
    }

    // ============================================================
    // ACCOUNT BALANCE CALCULATION
    // WHY: All balances computed from posted JE lines — no denormalized counters.
    // This is the single source of truth for account balances.
    // ============================================================

    /**
     * JE statuses whose lines are ON THE BOOKS, as an SQL IN-list body.
     * Use as: "je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")".
     *
     * WHY 'reversed' is included: JournalEntryService::reverse() posts a NEW
     * swapped-line entry (status 'posted') and flips the ORIGINAL to status
     * 'reversed' — the original is not un-posted, it is offset. Reading only
     * status = 'posted' dropped the original but kept its reversal, so every
     * reversed entry counted as its own NEGATIVE instead of netting to zero
     * (a voided invoice left AR and revenue understated by the invoice total;
     * an auto-reversed month-end accrual vanished from the month it accrued).
     * 'reversed' is only ever set by reverse(), which requires 'posted' first.
     */
    public const LEDGER_STATUSES_SQL = "'posted','reversed'";

    /**
     * Calculate the balance of an account as of a given date.
     * Balance = total debits - total credits for debit-normal accounts,
     * or total credits - total debits for credit-normal accounts.
     *
     * @param int         $accountId
     * @param string|null $asOfDate  Y-m-d (null = all time)
     * @return string     bcmath-safe balance string
     */
    public static function accountBalance(int $accountId, ?string $asOfDate = null): string
    {
        $dateFilter = '';
        $params = [$accountId];

        if ($asOfDate) {
            $dateFilter = "AND je.entry_date <= ?";
            $params[] = $asOfDate;
        }

        $row = \db_row(
            "SELECT
                COALESCE(SUM(jel.debit), 0) AS total_debit,
                COALESCE(SUM(jel.credit), 0) AS total_credit
             FROM acc_journal_entry_lines jel
             JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
             WHERE jel.account_id = ?
               AND je.status IN (" . self::LEDGER_STATUSES_SQL . ")
               {$dateFilter}",
            $params
        );

        if (!$row) return '0.00';

        $totalDebit = $row['total_debit'] ?? '0.00';
        $totalCredit = $row['total_credit'] ?? '0.00';

        // Get account normal balance side
        $account = \db_row("SELECT normal_balance FROM acc_accounts WHERE id = ?", [$accountId]);
        if (!$account) return '0.00';

        // WHY: Debit-normal accounts show debit-credit as positive.
        // Credit-normal accounts show credit-debit as positive.
        if ($account['normal_balance'] === 'debit') {
            return bcsub($totalDebit, $totalCredit, 2);
        }
        return bcsub($totalCredit, $totalDebit, 2);
    }

    /**
     * Get balances for all accounts (for trial balance, financial statements).
     * Returns array of [account_id => [debit_total, credit_total, balance]].
     *
     * @param string|null $asOfDate
     * @param int|null    $periodId  Filter to specific period
     * @return array
     */
    public static function allAccountBalances(?string $asOfDate = null, ?int $periodId = null): array
    {
        // Reversed originals stay on the books — see LEDGER_STATUSES_SQL.
        $where = "je.status IN (" . self::LEDGER_STATUSES_SQL . ")";
        $params = [];

        if ($asOfDate) {
            $where .= " AND je.entry_date <= ?";
            $params[] = $asOfDate;
        }

        if ($periodId) {
            $where .= " AND je.period_id = ?";
            $params[] = $periodId;
        }

        $rows = \db_select(
            "SELECT
                jel.account_id,
                COALESCE(SUM(jel.debit), 0) AS total_debit,
                COALESCE(SUM(jel.credit), 0) AS total_credit
             FROM acc_journal_entry_lines jel
             JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
             WHERE {$where}
             GROUP BY jel.account_id",
            $params
        );

        $balances = [];
        foreach ($rows as $r) {
            $balances[(int)$r['account_id']] = [
                'debit_total'  => $r['total_debit'],
                'credit_total' => $r['total_credit'],
                'balance'      => bcsub($r['total_debit'], $r['total_credit'], 2),
            ];
        }

        return $balances;
    }

    // ============================================================
    // REVENUE ACCOUNT MAPPING
    // Maps FleetForge invoice line item types to GL revenue accounts.
    // ============================================================

    /**
     * Get the GL account ID for an invoice line item type.
     *
     * WHY: The setting 'accounting.revenue_account_map' stores a JSON object
     * mapping item_type → account_code (e.g. "base_rental" → "4010").
     * We parse the JSON, look up the code, then resolve to acc_accounts.id.
     * Falls back to the 'other' mapping if no specific mapping exists.
     *
     * @param string $lineType  Invoice line type (e.g. 'base_rental', 'late_fee')
     * @return int|null         Account ID or null if not mapped
     */
    public static function revenueAccountId(string $lineType): ?int
    {
        // SOP I1: one resolver for the whole app (rental → per category).
        return AutoEntryBridge::revenueAccountForLineType($lineType);
    }

    /**
     * Get the GL account code for an invoice line item type.
     *
     * WHY: Looks up the account code from the JSON revenue map.
     * Returns the code string directly for display/reference.
     *
     * @param string $lineType  Invoice line type (e.g. 'base_rental', 'late_fee')
     * @return string|null      Account code (e.g. '4010') or null
     */
    public static function revenueAccountCode(string $lineType): ?string
    {
        // SOP I1: delegate to the one resolver, then read the code back.
        $id = AutoEntryBridge::revenueAccountForLineType($lineType);
        if ($id === null) return null;
        $row = \db_row("SELECT code FROM acc_accounts WHERE id = ?", [$id]);
        return $row ? (string) $row['code'] : null;
    }

    // ============================================================
    // AR / AP RECONCILIATION CHECK
    // WHY: [A9] GL AR must always equal sum of open invoice balances.
    // ============================================================

    /**
     * Check AR subledger reconciliation.
     * Returns the discrepancy amount (should be 0.00 if in sync).
     *
     * @return array [gl_balance, subledger_balance, difference, is_reconciled]
     */
    public static function arReconciliationCheck(): array
    {
        $arAccountId = self::setting('accounting.ar_account_id');
        $glBalance = $arAccountId ? self::accountBalance($arAccountId) : '0.00';

        // Sum of open invoice balances (the subledger).
        // S-AUDIT-BILLING-ENGINE-1 #5 — the old check was structurally broken:
        //   (a) it included DRAFTS ('status NOT IN paid/void/written_off'), but
        //       the GL only posts AR at SEND — any draft made the check fail;
        //   (b) it compared mixed-currency FACE values against the (now
        //       CAD-canonical, #21) GL — any USD invoice made it fail.
        // Compare like units: ISSUED invoices only, CAD-converted at each
        // invoice's frozen rate.
        $row = \db_row(
            "SELECT COALESCE(SUM(
                        CASE WHEN currency = 'USD'
                             THEN ROUND(balance_due * COALESCE(exchange_rate_to_cad, 1), 2)
                             ELSE balance_due END
                    ), 0) AS total
             FROM invoices
             WHERE status IN ('sent','partially_paid','overdue')
               AND deleted_at IS NULL",
            []
        );
        $subledgerBalance = $row['total'] ?? '0.00';
        $difference = bcsub($glBalance, $subledgerBalance, 2);

        return [
            'gl_balance'         => $glBalance,
            'subledger_balance'  => $subledgerBalance,
            'difference'         => $difference,
            'is_reconciled'      => bccomp($difference, '0.00', 2) === 0,
        ];
    }

    /**
     * Check AP subledger reconciliation.
     *
     * @return array [gl_balance, subledger_balance, difference, is_reconciled]
     */
    public static function apReconciliationCheck(): array
    {
        $apAccountId = self::setting('accounting.ap_account_id');
        $glBalance = $apAccountId ? self::accountBalance($apAccountId) : '0.00';

        $row = \db_row(
            "SELECT COALESCE(SUM(balance_due), 0) AS total
             FROM acc_bills
             WHERE status NOT IN ('paid','void')",
            []
        );
        // SOP I3: a vendor credit debits AP when it is CREATED; applying it
        // later only moves the balance between bills. So until it is applied,
        // its unused remainder already sits in GL AP — net it off here, or
        // every open credit shows as drift.
        $credits = \db_row(
            "SELECT COALESCE(SUM(amount_remaining), 0) AS total
             FROM acc_vendor_credits
             WHERE status IN ('active','partially_used')",
            []
        );
        $subledgerBalance = bcsub((string) ($row['total'] ?? '0.00'), (string) ($credits['total'] ?? '0.00'), 2);
        $difference = bcsub($glBalance, $subledgerBalance, 2);

        return [
            'gl_balance'         => $glBalance,
            'subledger_balance'  => $subledgerBalance,
            'difference'         => $difference,
            'is_reconciled'      => bccomp($difference, '0.00', 2) === 0,
        ];
    }
}
