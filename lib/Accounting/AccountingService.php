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
     * Validate that a period is open for posting.
     * Returns error string if posting is blocked, null if OK.
     *
     * @param int $periodId
     * @return string|null Error message or null
     */
    public static function validatePeriodForPosting(int $periodId): ?string
    {
        $period = \db_row("SELECT * FROM acc_periods WHERE id = ?", [$periodId]);
        if (!$period) return 'Period not found.';

        if ($period['status'] === 'locked') {
            return "Period {$period['name']} is locked. No entries can be posted.";
        }

        if ($period['status'] === 'closed') {
            return "Period {$period['name']} is closed. Only Super Admin adjusting entries allowed.";
        }

        return null; // open — OK to post
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
     * Generic atomic sequence number generator.
     * WHY: Same pattern as invoice numbering (Trap 9) — prevents gaps.
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

    // ============================================================
    // ACCOUNT BALANCE CALCULATION
    // WHY: All balances computed from posted JE lines — no denormalized counters.
    // This is the single source of truth for account balances.
    // ============================================================

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
               AND je.status = 'posted'
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
        $where = "je.status = 'posted'";
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
        static $mapCache = null;
        static $codeToIdCache = [];

        // Load and cache the JSON map
        if ($mapCache === null) {
            $mapCache = self::setting('accounting.revenue_account_map', []);
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
        static $mapCache = null;

        if ($mapCache === null) {
            $mapCache = self::setting('accounting.revenue_account_map', []);
            if (is_string($mapCache)) {
                $mapCache = json_decode($mapCache, true) ?? [];
            }
        }

        return isset($mapCache[$lineType]) ? (string) $mapCache[$lineType] : ($mapCache['other'] ?? null);
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

        // Sum of open invoice balances (the subledger)
        $row = \db_row(
            "SELECT COALESCE(SUM(balance_due), 0) AS total
             FROM invoices
             WHERE status NOT IN ('paid','void','written_off')
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
        $subledgerBalance = $row['total'] ?? '0.00';
        $difference = bcsub($glBalance, $subledgerBalance, 2);

        return [
            'gl_balance'         => $glBalance,
            'subledger_balance'  => $subledgerBalance,
            'difference'         => $difference,
            'is_reconciled'      => bccomp($difference, '0.00', 2) === 0,
        ];
    }
}
