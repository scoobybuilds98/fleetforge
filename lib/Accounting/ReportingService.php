<?php
declare(strict_types=1);

/**
 * lib/Accounting/ReportingService.php
 *
 * Financial reporting engine — produces the canonical management reports
 * (Profit & Loss, Balance Sheet, Cash Flow Statement, Working Trial
 * Balance, Fixed Asset Schedule) from posted JE data. All monetary arithmetic via
 * bcmath; all balance queries delegate to AccountingService so a single
 * implementation is the source of truth for "what does this account
 * have on it as of date X".
 *
 * Sign convention (every statement): an amount is signed by the SECTION's
 * natural side, not by the account's own normal_balance — assets are
 * debit − credit, liabilities/equity/revenue/other income credit − debit,
 * costs/expenses debit − credit. A contra account (Accumulated Depreciation,
 * Allowance for Doubtful Accounts, Owner Drawings, a sales-discount revenue
 * account) therefore shows NEGATIVE and reduces its section total.
 *
 * Ledger scope: posted AND reversed entries (AccountingService::
 * LEDGER_STATUSES_SQL) — a reversed original stays on the books, offset by
 * its posted reversal.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 (Financial Statements)
 *           and §21 (Phase B reporting + budgeting build).
 * Session:  S036
 */

namespace FleetForge\Accounting;

class ReportingService
{
    /** P&L account types whose natural (positive) side is CREDIT. */
    private const CREDIT_NATURAL_PL_TYPES = ['revenue', 'other_income'];

    /** Every account type that closes to retained earnings. */
    private const PL_TYPES = ['revenue', 'other_income', 'cost_of_revenue', 'operating_expense', 'other_expense'];

    // ============================================================
    // PROFIT & LOSS
    // ============================================================

    /**
     * Profit & Loss statement for a date range with optional comparison.
     *
     * @param string $from        period start YYYY-MM-DD (inclusive)
     * @param string $to          period end YYYY-MM-DD (inclusive)
     * @param string $compareMode 'none' | 'prior_period' | 'prior_year' | 'budget'
     * @param int|null $budgetId  required when compareMode === 'budget'
     * @return array
     */
    public static function profitAndLoss(
        string $from,
        string $to,
        string $compareMode = 'none',
        ?int $budgetId = null
    ): array {
        $base = self::buildPLBlock($from, $to);

        $compare = null;
        if ($compareMode === 'prior_period') {
            [$priorFrom, $priorTo] = self::priorPeriodRange($from, $to);
            $compare = self::buildPLBlock($priorFrom, $priorTo);
        } elseif ($compareMode === 'prior_year') {
            $priorFrom = date('Y-m-d', strtotime($from . ' -1 year'));
            $priorTo   = date('Y-m-d', strtotime($to . ' -1 year'));
            $compare = self::buildPLBlock($priorFrom, $priorTo);
        } elseif ($compareMode === 'budget' && $budgetId) {
            $compare = self::buildBudgetPLBlock($budgetId, $from, $to);
        }

        // Merge comparison amounts onto each base row + compute variance
        if ($compare) {
            foreach (['revenue', 'direct_costs', 'operating_expenses', 'other'] as $group) {
                // accountSection() returns a LIST, so index the compare block by
                // account_id first — looking up $compare[$group][$accountId]
                // directly read whatever row sat at that list position.
                $cmpByAccount = array_column($compare[$group], null, 'account_id');
                foreach ($base[$group] as &$row) {
                    $cmp = $cmpByAccount[$row['account_id']] ?? null;
                    $row['compare_amount'] = $cmp ? $cmp['amount'] : '0.00';
                    $row['var_amt']        = bcsub($row['amount'], $row['compare_amount'], 2);
                    $row['var_pct']        = self::variancePct($row['amount'], $row['compare_amount']);
                }
                unset($row);
            }
        }

        $base['compare_mode']  = $compareMode;
        $base['compare_total'] = $compare ? [
            'revenue'      => $compare['revenue_total'],
            'direct_costs' => $compare['direct_costs_total'],
            'opex'         => $compare['opex_total'],
            'other'        => $compare['other_total'],
            'net_income'   => $compare['net_income'],
        ] : null;

        return $base;
    }

    /**
     * One-shot P&L assembly for a date range — used as both the base
     * and the comparison block.
     */
    private static function buildPLBlock(string $from, string $to): array
    {
        $revenue       = self::accountSection($from, $to, ['revenue']);
        $directCosts   = self::accountSection($from, $to, ['cost_of_revenue']);
        $operatingExp  = self::accountSection($from, $to, ['operating_expense']);
        $other         = self::accountSection($from, $to, ['other_income', 'other_expense']);

        $revenueTotal      = self::sumSection($revenue);
        $directCostsTotal  = self::sumSection($directCosts);
        $grossProfit       = bcsub($revenueTotal, $directCostsTotal, 2);
        $grossProfitPct    = self::variancePct($grossProfit, $revenueTotal);
        $opexTotal         = self::sumSection($operatingExp);
        $operatingIncome   = bcsub($grossProfit, $opexTotal, 2);
        $otherTotal        = self::sumSection($other, true); // signed: other_income adds, other_expense subtracts
        $netIncomeBeforeTax = bcadd($operatingIncome, $otherTotal, 2);

        // Tax provision: none is derived here. This used to subtract
        // acc_tax_remittances, but those rows are GST/HST + PST remittances
        // (acc_tax_filing_periods.tax_type is gst_hst / pst_*), booked
        // DR tax payable / CR cash — settling a liability, not an expense.
        // Subtracting them understated net income by every remittance and
        // unbalanced the balance sheet by the same amount. Corporate income tax
        // is booked by JE to an expense account, so it is already in the
        // sections above. Key kept (always 0.00) for the PDF/API shape.
        $taxProvision = '0.00';
        $netIncome    = bcsub($netIncomeBeforeTax, $taxProvision, 2);

        return [
            'period'             => ['from' => $from, 'to' => $to],
            'revenue'            => $revenue,
            'revenue_total'      => $revenueTotal,
            'direct_costs'       => $directCosts,
            'direct_costs_total' => $directCostsTotal,
            'gross_profit'       => $grossProfit,
            'gross_profit_pct'   => $grossProfitPct,
            'operating_expenses' => $operatingExp,
            'opex_total'         => $opexTotal,
            'operating_income'   => $operatingIncome,
            'other'              => $other,
            'other_total'        => $otherTotal,
            'net_income_before_tax' => $netIncomeBeforeTax,
            'tax_provision'      => $taxProvision,
            'net_income'         => $netIncome,
        ];
    }

    /**
     * Aggregate JE-line activity by account, filtered by account_type list,
     * within a date range. Returns a flat array of rows with drill-down JE
     * line id list (truncated to 50).
     */
    private static function accountSection(string $from, string $to, array $types): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge($types, [$from, $to]);
        $ledger = AccountingService::LEDGER_STATUSES_SQL;

        // C5: the je status/date predicates MUST live in WHERE (with INNER
        // JOINs), not in a LEFT JOIN ... ON. With a LEFT JOIN, a non-matching
        // je (draft, or out-of-period) only nulls the je columns — the jel row
        // survives the join, so SUM(jel.debit/credit) still counts it, leaking
        // draft + other-period activity into every period total. INNER JOIN +
        // WHERE drops those lines entirely. Accounts with no posted-in-period
        // activity simply produce no row (they were skipped as zero anyway).
        //
        // No is_active filter: an account deactivated mid-year still carries
        // the activity it booked, and dropping it would understate the period.
        //
        // Year-end closing entries (source_type 'year_end', and their reversal,
        // which inherits the source_type) are excluded: they zero every P&L
        // account into retained earnings on Dec 31, so including them made the
        // P&L of any CLOSED year read $0 — including the year-end package's own
        // P&L. The balance sheet reads the ledger WITH them (see ledgerEarnings()).
        $rows = \db_select(
            "SELECT a.id AS account_id, a.code, a.name, a.account_type, a.normal_balance,
                    COALESCE(SUM(jel.debit), 0) AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit
               FROM acc_accounts a
               JOIN acc_journal_entry_lines jel ON jel.account_id = a.id
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE a.account_type IN ({$placeholders})
                AND a.is_header = 0
                AND je.status IN ({$ledger})
                AND COALESCE(je.source_type, '') <> 'year_end'
                AND je.entry_date BETWEEN ? AND ?
              GROUP BY a.id, a.code, a.name, a.account_type, a.normal_balance
              ORDER BY a.sort_order ASC, a.code ASC",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $debit  = (string) ($r['total_debit']  ?? '0.00');
            $credit = (string) ($r['total_credit'] ?? '0.00');

            // Signed by the section's natural side (account TYPE), not the
            // account's normal_balance: revenue / other income = credit − debit,
            // costs / expenses = debit − credit. A contra account (e.g. a
            // debit-normal sales-discount account typed 'revenue') then comes
            // out negative and reduces its section, instead of inflating it.
            $amount = in_array($r['account_type'], self::CREDIT_NATURAL_PL_TYPES, true)
                ? bcsub($credit, $debit, 2)
                : bcsub($debit, $credit, 2);

            // Skip zero rows to keep the report tight
            if (bccomp($amount, '0', 2) === 0) continue;

            // Drill-down: up to 50 JE line ids for the modal
            $lineRows = \db_select(
                "SELECT jel.id
                   FROM acc_journal_entry_lines jel
                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                  WHERE jel.account_id = ?
                    AND je.status IN ({$ledger})
                    AND COALESCE(je.source_type, '') <> 'year_end'
                    AND je.entry_date BETWEEN ? AND ?
                  ORDER BY je.entry_date DESC, jel.id DESC
                  LIMIT 50",
                [(int) $r['account_id'], $from, $to]
            );
            $lineIds = array_map(static fn($x) => (int) $x['id'], $lineRows);

            $out[(int) $r['account_id']] = [
                'account_id'   => (int) $r['account_id'],
                'code'         => $r['code'],
                'name'         => $r['name'],
                'account_type' => $r['account_type'],
                'amount'       => $amount,
                'je_line_ids'  => $lineIds,
            ];
        }
        return array_values($out);
    }

    private static function sumSection(array $section, bool $signedByType = false): string
    {
        $total = '0.00';
        foreach ($section as $row) {
            if ($signedByType && ($row['account_type'] ?? '') === 'other_expense') {
                $total = bcsub($total, $row['amount'], 2);
            } else {
                $total = bcadd($total, $row['amount'], 2);
            }
        }
        return $total;
    }

    private static function priorPeriodRange(string $from, string $to): array
    {
        $diffDays = (int) round((strtotime($to) - strtotime($from)) / 86400);
        $priorTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $priorFrom = date('Y-m-d', strtotime($priorTo . ' -' . $diffDays . ' days'));
        return [$priorFrom, $priorTo];
    }

    private static function variancePct(string $actual, string $base): string
    {
        if (bccomp($base, '0', 2) === 0) return '0.00';
        $diff = bcsub($actual, $base, 4);
        $pct  = bcmul(bcdiv($diff, $base, 6), '100', 4);
        return bcadd($pct, '0', 2);
    }

    /**
     * Synthesise a P&L-shaped compare block from a budget for the given
     * date range. Pro-rates monthly columns to days in the range.
     */
    private static function buildBudgetPLBlock(int $budgetId, string $from, string $to): array
    {
        $lines = \db_select(
            "SELECT bl.account_id, a.account_type, a.normal_balance,
                    bl.`jan`, bl.`feb`, bl.`mar`, bl.`apr`, bl.`may`, bl.`jun`,
                    bl.`jul`, bl.`aug`, bl.`sep`, bl.`oct`, bl.`nov`, bl.`dec`
               FROM acc_budget_lines bl
               JOIN acc_accounts a ON a.id = bl.account_id
              WHERE bl.budget_id = ?",
            [$budgetId]
        );

        $byGroup = [
            'revenue'            => [],
            'direct_costs'       => [],
            'operating_expenses' => [],
            'other'              => [],
        ];
        $totals = [
            'revenue_total'      => '0.00',
            'direct_costs_total' => '0.00',
            'opex_total'         => '0.00',
            'other_total'        => '0.00',
        ];

        foreach ($lines as $l) {
            $amount = self::prorateBudgetLine($l, $from, $to);
            if (bccomp($amount, '0', 2) === 0) continue;

            $group = match ($l['account_type']) {
                'revenue'           => 'revenue',
                'cost_of_revenue'   => 'direct_costs',
                'operating_expense' => 'operating_expenses',
                'other_income', 'other_expense' => 'other',
                default             => null,
            };
            if ($group === null) continue;

            $byGroup[$group][(int) $l['account_id']] = [
                'account_id' => (int) $l['account_id'],
                'amount'     => $amount,
            ];
            $totalsKey = match ($group) {
                'revenue'            => 'revenue_total',
                'direct_costs'       => 'direct_costs_total',
                'operating_expenses' => 'opex_total',
                'other'              => 'other_total',
            };
            $totals[$totalsKey] = bcadd($totals[$totalsKey], $amount, 2);
        }

        $grossProfit       = bcsub($totals['revenue_total'], $totals['direct_costs_total'], 2);
        $operatingIncome   = bcsub($grossProfit, $totals['opex_total'], 2);
        $netIncome         = bcadd($operatingIncome, $totals['other_total'], 2);

        return array_merge($byGroup, $totals, [
            'net_income' => $netIncome,
        ]);
    }

    /**
     * Pro-rate a 12-month budget line to a partial date range. For each
     * month in the range, take the corresponding column proportionally
     * to the days of that month included in [$from, $to].
     */
    private static function prorateBudgetLine(array $line, string $from, string $to): string
    {
        $cols = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        $total = '0.00';
        $year  = (int) substr($from, 0, 4);

        for ($m = 1; $m <= 12; $m++) {
            $monthStart = sprintf('%04d-%02d-01', $year, $m);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));

            // Intersect [monthStart, monthEnd] with [from, to]
            $overlapStart = max(strtotime($from), strtotime($monthStart));
            $overlapEnd   = min(strtotime($to), strtotime($monthEnd));
            if ($overlapEnd < $overlapStart) continue;

            $monthDays   = (int) date('t', strtotime($monthStart));
            $overlapDays = (int) round(($overlapEnd - $overlapStart) / 86400) + 1;
            $share       = bcdiv((string) $overlapDays, (string) $monthDays, 6);
            $colVal      = (string) ($line[$cols[$m - 1]] ?? '0.00');
            $portion     = bcmul($colVal, $share, 6);
            $total       = bcadd($total, $portion, 6);
        }
        return bcadd($total, '0', 2);
    }

    // ============================================================
    // BALANCE SHEET
    // ============================================================

    /**
     * Balance sheet as of a date. Injects YTD net income into equity.
     *
     * @param string $asOf       YYYY-MM-DD
     * @param string $compareMode 'none' | 'prior_period' | 'prior_year'
     * @return array
     */
    public static function balanceSheet(string $asOf, string $compareMode = 'none'): array
    {
        $base = self::buildBSBlock($asOf);

        $compare = null;
        if ($compareMode === 'prior_period') {
            $diff = (int) round((time() - strtotime($asOf)) / 86400);
            $priorAsOf = date('Y-m-d', strtotime($asOf . ' -' . max(1, $diff) . ' days'));
            $compare = self::buildBSBlock($priorAsOf);
        } elseif ($compareMode === 'prior_year') {
            $priorAsOf = date('Y-m-d', strtotime($asOf . ' -1 year'));
            $compare = self::buildBSBlock($priorAsOf);
        }

        if ($compare) {
            foreach (['current_assets','long_term_assets','current_liabilities','long_term_liabilities','equity'] as $group) {
                foreach ($base[$group] as &$row) {
                    $cmp = $compare[$group][$row['account_id']] ?? null;
                    $row['compare_amount'] = $cmp ? $cmp['amount'] : '0.00';
                    $row['var_amt']        = bcsub($row['amount'], $row['compare_amount'], 2);
                    $row['var_pct']        = self::variancePct($row['amount'], $row['compare_amount']);
                }
                unset($row);
            }
        }

        $base['compare_mode']   = $compareMode;
        $base['compare_totals'] = $compare ? [
            'total_assets'      => $compare['total_assets'],
            'total_liabilities' => $compare['total_liabilities'],
            'total_equity'      => $compare['total_equity'],
        ] : null;

        return $base;
    }

    /**
     * One balance-sheet snapshot as of a date — used for both the base and the
     * comparison column.
     *
     * Equity = equity-account balances
     *        + current fiscal-year earnings   (P&L accounts, Jan 1 → as-of)
     *        + prior-year earnings not closed (P&L accounts, all time → Dec 31 prior)
     *
     * Both earnings figures are read straight from the ledger INCLUDING
     * year-end closing entries, which is what makes the equation hold by
     * construction: every posted JE balances, so assets − liabilities −
     * equity accounts ≡ cumulative P&L-account net. After a year-end close the
     * closing JE has already zeroed that year's P&L accounts into Retained
     * Earnings, so the prior-years term is 0 and nothing is counted twice.
     * Before this, only YTD income was injected: with 2022–2025 never closed,
     * every earlier year's profit was missing from equity.
     *
     * @param string $asOf YYYY-MM-DD
     * @return array
     */
    private static function buildBSBlock(string $asOf): array
    {
        // Fiscal year = calendar year (decision A4; YearEndService closes Jan–Dec).
        $year       = (int) substr($asOf, 0, 4);
        $fyStart    = sprintf('%04d-01-01', $year);
        $priorEnd   = sprintf('%04d-12-31', $year - 1);

        $netIncomeYtd          = self::ledgerEarnings($fyStart, $asOf);
        $priorUnclosedEarnings = self::ledgerEarnings(null, $priorEnd);

        $assets = self::balanceSheetSection($asOf, ['asset']);
        $liabs  = self::balanceSheetSection($asOf, ['liability']);
        $equity = self::balanceSheetSection($asOf, ['equity']);

        // Split assets and liabilities by subtype hint in coa_group/name
        [$currentAssets, $longTermAssets] = self::splitAssetsByTerm($assets);
        [$currentLiabs, $longTermLiabs]   = self::splitLiabilitiesByTerm($liabs);

        $totalAssets         = self::sumBSSection($assets);
        $totalLiabilities    = self::sumBSSection($liabs);

        // Earnings are shown as their own equity lines (not silently folded
        // into the total) so the section rows add up to Total Equity. Synthetic
        // rows carry a string account_id (unique x-for key) and an empty code.
        if (bccomp($priorUnclosedEarnings, '0', 2) !== 0) {
            $equity['retained_earnings_unclosed'] = [
                'account_id'     => 'retained_earnings_unclosed',
                'code'           => '',
                'name'           => 'Retained Earnings — prior years not yet closed',
                'account_type'   => 'equity',
                'normal_balance' => 'credit',
                'coa_group'      => 'Equity',
                'amount'         => $priorUnclosedEarnings,
                'is_computed'    => true,
            ];
        }
        $equity['net_income_ytd'] = [
            'account_id'     => 'net_income_ytd',
            'code'           => '',
            'name'           => "Net Income — {$year} year to date",
            'account_type'   => 'equity',
            'normal_balance' => 'credit',
            'coa_group'      => 'Equity',
            'amount'         => $netIncomeYtd,
            'is_computed'    => true,
        ];
        $totalEquity         = self::sumBSSection($equity);

        $currentAssetsTotal      = self::sumBSSection($currentAssets);
        $longTermAssetsTotal     = self::sumBSSection($longTermAssets);
        $currentLiabsTotal       = self::sumBSSection($currentLiabs);
        $longTermLiabsTotal      = self::sumBSSection($longTermLiabs);

        $liabPlusEquity = bcadd($totalLiabilities, $totalEquity, 2);
        $drift          = bcsub($totalAssets, $liabPlusEquity, 2);
        $isBalanced     = bccomp($drift, '0.00', 2) === 0;

        return [
            'as_of'                 => $asOf,
            'current_assets'        => $currentAssets,
            'current_assets_total'  => $currentAssetsTotal,
            'long_term_assets'      => $longTermAssets,
            'long_term_assets_total' => $longTermAssetsTotal,
            'total_assets'          => $totalAssets,
            'current_liabilities'   => $currentLiabs,
            'current_liabilities_total' => $currentLiabsTotal,
            'long_term_liabilities' => $longTermLiabs,
            'long_term_liabilities_total' => $longTermLiabsTotal,
            'total_liabilities'     => $totalLiabilities,
            'equity'                => $equity,
            'net_income_injected'   => $netIncomeYtd,
            'prior_unclosed_earnings' => $priorUnclosedEarnings,
            'total_equity'          => $totalEquity,
            'total_liabilities_and_equity' => $liabPlusEquity,
            'is_balanced'           => $isBalanced,
            'drift'                 => $drift,
        ];
    }

    /**
     * Net earnings (credit − debit across every P&L-type account) booked in a
     * date window, read from the ledger INCLUDING year-end closing entries.
     *
     * @param string|null $from YYYY-MM-DD inclusive, or null for "since inception"
     * @param string      $to   YYYY-MM-DD inclusive
     * @return string bcmath amount; positive = profit
     */
    private static function ledgerEarnings(?string $from, string $to): string
    {
        $typeIn = implode(',', array_fill(0, count(self::PL_TYPES), '?'));
        $params = self::PL_TYPES;
        $fromSql = '';
        if ($from !== null) {
            $fromSql  = 'AND je.entry_date >= ?';
            $params[] = $from;
        }
        $params[] = $to;

        $row = \db_row(
            "SELECT COALESCE(SUM(jel.credit), 0) - COALESCE(SUM(jel.debit), 0) AS net
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE a.account_type IN ({$typeIn})
                AND je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                {$fromSql}
                AND je.entry_date <= ?",
            $params
        );
        return bcadd((string) ($row['net'] ?? '0'), '0', 2);
    }

    /**
     * Pull accounts of the given balance-sheet types with their balance as of
     * the date, signed by the section's natural side (asset → debit − credit;
     * liability/equity → credit − debit). Contra accounts come out negative:
     * 1220 Accumulated Depreciation reduces Total Assets and 3030 Owner Drawings
     * reduces Total Equity. (Previously the account's own normal-balance sign
     * was used, so accumulated depreciation was ADDED to assets — a $X
     * depreciation run drifted the sheet by 2×$X.)
     *
     * Inactive accounts are included when they still carry a balance —
     * leaving them out would unbalance the sheet.
     *
     * Each row { account_id, code, name, account_type, normal_balance, coa_group, account_subtype, amount }.
     *
     * @param string   $asOf  YYYY-MM-DD
     * @param string[] $types account_type values (asset | liability | equity)
     * @return array<int, array>
     */
    private static function balanceSheetSection(string $asOf, array $types): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $accts = \db_select(
            "SELECT id, code, name, account_type, account_subtype, normal_balance, coa_group, sort_order
               FROM acc_accounts
              WHERE account_type IN ({$placeholders})
                AND is_header = 0
              ORDER BY sort_order ASC, code ASC",
            $types
        );

        $out = [];
        foreach ($accts as $a) {
            // accountBalance() is positive on the ACCOUNT's normal side; flip it
            // when that differs from the section's natural side (a contra account).
            $amount  = AccountingService::accountBalance((int) $a['id'], $asOf);
            $natural = $a['account_type'] === 'asset' ? 'debit' : 'credit';
            if ($a['normal_balance'] !== $natural) {
                $amount = bcmul($amount, '-1', 2);
            }
            if (bccomp($amount, '0', 2) === 0) continue;
            $out[(int) $a['id']] = [
                'account_id'      => (int) $a['id'],
                'code'            => $a['code'],
                'name'            => $a['name'],
                'account_type'    => $a['account_type'],
                'account_subtype' => $a['account_subtype'],
                'normal_balance'  => $a['normal_balance'],
                'coa_group'       => $a['coa_group'],
                'amount'          => $amount,
            ];
        }
        return $out;
    }

    /**
     * Sum the (already section-signed) amounts of a balance-sheet section.
     *
     * @param array $section rows from balanceSheetSection() (plus synthetic equity rows)
     * @return string bcmath total
     */
    private static function sumBSSection(array $section): string
    {
        $total = '0.00';
        foreach ($section as $row) $total = bcadd($total, $row['amount'], 2);
        return $total;
    }

    /**
     * Split asset rows into current vs long-term (see isLongTermAsset()).
     */
    private static function splitAssetsByTerm(array $assets): array
    {
        $current   = [];
        $longTerm  = [];
        foreach ($assets as $key => $row) {
            if (self::isLongTermAsset($row)) $longTerm[$key] = $row;
            else                             $current[$key]  = $row;
        }
        return [$current, $longTerm];
    }

    /**
     * Split liability rows into current vs long-term (see isLongTermLiability()).
     */
    private static function splitLiabilitiesByTerm(array $liabs): array
    {
        $current  = [];
        $longTerm = [];
        foreach ($liabs as $key => $row) {
            if (self::isLongTermLiability($row)) $longTerm[$key] = $row;
            else                                 $current[$key]  = $row;
        }
        return [$current, $longTerm];
    }

    /**
     * Is this asset account long-term? Subtype / coa_group / name hints first
     * (they catch accounts seeded without a coa_group, e.g. 1600 Net Investment
     * in Lease — Long-Term), then the 12xx–15xx code convention. Anything
     * without a hint is current. Shared by the balance sheet (current vs
     * long-term split) and the cash flow statement (operating vs investing),
     * so an account is classified the same way on both.
     *
     * @param array $row needs code, coa_group, account_subtype, name
     * @return bool
     */
    private static function isLongTermAsset(array $row): bool
    {
        $code    = (string) ($row['code'] ?? '');
        $group   = strtolower((string) ($row['coa_group'] ?? ''));
        $subtype = strtolower((string) ($row['account_subtype'] ?? ''));
        $name    = strtolower((string) ($row['name'] ?? ''));
        return $subtype === 'fixed_asset' || str_contains($subtype, 'long')
            || str_contains($group, 'fixed') || str_contains($group, 'long')
            || str_contains($name, 'long-term')
            || str_starts_with($code, '12') || str_starts_with($code, '13')
            || str_starts_with($code, '14') || str_starts_with($code, '15');
    }

    /**
     * Is this liability account long-term? Subtype / coa_group hints, then the
     * 25xx–26xx code convention. Shared by the balance sheet and the cash flow
     * statement (see isLongTermAsset()).
     *
     * @param array $row needs code, coa_group, account_subtype
     * @return bool
     */
    private static function isLongTermLiability(array $row): bool
    {
        $code    = (string) ($row['code'] ?? '');
        $group   = strtolower((string) ($row['coa_group'] ?? ''));
        $subtype = strtolower((string) ($row['account_subtype'] ?? ''));
        return str_contains($subtype, 'long') || str_contains($group, 'long')
            || str_starts_with($code, '25') || str_starts_with($code, '26');
    }

    // ============================================================
    // CASH FLOW STATEMENT (ASPE 1540 indirect method)
    // ============================================================

    /**
     * Entry source types whose balance-sheet lines are not cash flows.
     *
     * For these entries the statement adds the P&L effect back as a named
     * non-cash adjustment ('adjustment' key) and drops their non-cash
     * balance-sheet lines — so a depreciation run never shows up as an
     * "inflow" on accumulated depreciation, and a sales-type lease inception
     * never shows the equipment it derecognises as sale proceeds. A line such
     * an entry posts to a CASH account (disposal proceeds; the revaluation of
     * the USD bank account) is real cash movement and is reported on the line
     * named by 'cash'. Reversals inherit the source_type (JournalEntryService::
     * reverse()), so a reversed run nets out inside its own adjustment.
     *
     * There is deliberately no 'bad_debt' entry: the source_type ENUM never had
     * one. Write-offs post DR bad-debt expense / CR AR as source 'invoice' or
     * 'damage_writeoff', so they sit inside the change in receivables (the
     * direct write-off presentation) — the old "+ Bad Debt" row always read $0.
     *
     * 'year_end' has no adjustment: the closing entry moves P&L balances into
     * retained earnings, and net income already excludes it (as the P&L does).
     */
    private const CF_NON_CASH_SOURCES = [
        'depreciation'              => ['adjustment' => 'depreciation',    'cash' => 'investing'],
        'impairment'                => ['adjustment' => 'impairment',      'cash' => 'investing'],
        'asset_disposal'            => ['adjustment' => 'asset_disposal',  'cash' => 'investing'],
        'fx_revaluation'            => ['adjustment' => 'fx_revaluation',  'cash' => 'fx_effect'],
        'lease_inception'           => ['adjustment' => 'lease_inception', 'cash' => 'investing'],
        'lease_residual_impairment' => ['adjustment' => 'impairment',      'cash' => 'investing'],
        'lease_termination'         => ['adjustment' => 'impairment',      'cash' => 'investing'],
        'year_end'                  => ['adjustment' => null,              'cash' => 'financing'],
    ];

    /** Display labels for the non-cash adjustments, in statement order. */
    private const CF_ADJUSTMENT_LABELS = [
        'depreciation'    => 'Depreciation',
        'impairment'      => 'Impairment & lease residual write-offs',
        'asset_disposal'  => 'Loss / (gain) on asset disposals',
        'fx_revaluation'  => 'Unrealized foreign exchange loss / (gain)',
        'lease_inception' => 'Sales-type lease inception (non-cash)',
    ];

    /**
     * Liability-name hints that mark borrowings (financing) even when the
     * account is typed current — e.g. 2070 "Current Portion of Long-Term Debt".
     */
    private const CF_DEBT_NAME_PATTERN = '/\b(loans?|debt|line of credit|credit facility|mortgages?|notes? payable|borrowings?|dividends? payable)\b/';

    /**
     * Cash flow statement for a date range (indirect method).
     *
     * TIES BY CONSTRUCTION. Every journal entry balances, so over any window
     * Σ(debit − credit) on the cash accounts equals Σ(credit − debit) on every
     * other line. Each posted/reversed line in the window lands in exactly one
     * place: net income (P&L lines), a working-capital / investing / financing
     * row (other balance-sheet lines, classified by account), a non-cash
     * adjustment that cancels its own P&L lines (CF_NON_CASH_SOURCES), or a
     * cash-line bucket (disposal proceeds / FX effect on cash). Net change in
     * cash therefore equals the GL movement on the cash accounts; tie_diff is a
     * runtime invariant, not a tolerance.
     *
     * Before S-CASHFLOW-TIE the statement treated only account 1010 as cash
     * (1020 USD was ignored), read working capital from six hard-coded codes
     * (two of them wrong: 1050 is GST receivable, 2070 is current debt — and
     * 1040/1055/1060/1065/1070/1080/2020/2060 were missing), read investing
     * from the fixed-asset register instead of the GL (register-only assets
     * showed as cash spent), found no long-term debt (it looked for code 25xx;
     * the chart uses 22xx) and no owner draws (it looked for "dividend").
     *
     * Account classification (cashFlowAccounts()):
     *   cash       asset accounts flagged is_bank_account, linked from a
     *              checking/savings acc_bank_accounts row, mapped as QBO
     *              undeposited funds, the default cash setting, or named
     *              "undeposited"
     *   investing  fixed-asset / long-term assets (isLongTermAsset()) and the
     *              lessor net-investment accounts (spec §21.3)
     *   financing  equity, long-term liabilities, borrowings by name, and
     *              line-of-credit bank accounts
     *   operating  every other asset / liability (working capital)
     *
     * @param string $from YYYY-MM-DD inclusive
     * @param string $to   YYYY-MM-DD inclusive
     * @return array
     */
    public static function cashFlow(string $from, string $to): array
    {
        $beforeFrom = date('Y-m-d', strtotime($from . ' -1 day'));
        $accounts   = self::cashFlowAccounts();

        $sources = array_keys(self::CF_NON_CASH_SOURCES);
        $srcIn   = implode(',', array_fill(0, count($sources), '?'));
        $lines = \db_select(
            "SELECT jel.account_id,
                    CASE WHEN je.source_type IN ({$srcIn}) THEN je.source_type ELSE '' END AS non_cash_source,
                    COALESCE(SUM(jel.debit), 0)  AS debit,
                    COALESCE(SUM(jel.credit), 0) AS credit
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.entry_date BETWEEN ? AND ?
              GROUP BY jel.account_id, non_cash_source",
            array_merge($sources, [$from, $to])
        );

        $netIncome   = '0.00';
        $adjustments = array_fill_keys(array_keys(self::CF_ADJUSTMENT_LABELS), '0.00');
        $bySection   = ['operating' => [], 'investing' => [], 'financing' => []];
        $cashBuckets = ['investing' => '0.00', 'fx_effect' => '0.00', 'financing' => '0.00'];

        foreach ($lines as $l) {
            $acct = $accounts[(int) $l['account_id']];
            $debitNet  = bcsub((string) $l['debit'], (string) $l['credit'], 2);  // debit − credit
            $creditNet = bcmul($debitNet, '-1', 2);                               // credit − debit
            $section   = $acct['cf_section'];
            $rule      = $l['non_cash_source'] === '' ? null : self::CF_NON_CASH_SOURCES[$l['non_cash_source']];

            if ($rule === null) {
                if ($section === 'income') {
                    $netIncome = bcadd($netIncome, $creditNet, 2);
                } elseif ($section !== 'cash') {
                    // A balance-sheet account's credit − debit is its cash effect:
                    // an asset rising (debit) consumed cash, a liability or equity
                    // account rising (credit) provided it.
                    $id = (int) $acct['id'];
                    $bySection[$section][$id] = bcadd($bySection[$section][$id] ?? '0.00', $creditNet, 2);
                }
                continue;
            }

            if ($section === 'income') {
                if ($rule['adjustment'] === null) continue; // closing entry — outside net income already
                $netIncome = bcadd($netIncome, $creditNet, 2);
                $adjustments[$rule['adjustment']] = bcadd($adjustments[$rule['adjustment']], $debitNet, 2);
            } elseif ($section === 'cash') {
                $cashBuckets[$rule['cash']] = bcadd($cashBuckets[$rule['cash']], $debitNet, 2);
            }
            // Non-cash balance-sheet lines of these entries are represented by
            // the adjustment above — counting them too would double them.
        }

        $nonCashTotal = '0.00';
        $nonCashLines = [];
        foreach (self::CF_ADJUSTMENT_LABELS as $key => $label) {
            $nonCashTotal = bcadd($nonCashTotal, $adjustments[$key], 2);
            if (bccomp($adjustments[$key], '0', 2) !== 0) {
                $nonCashLines[] = ['key' => $key, 'label' => $label, 'amount' => $adjustments[$key]];
            }
        }

        [$wcRows, $wcTotal]         = self::cashFlowRows($bySection['operating'], $accounts);
        [$invRows, $invAccountsNet] = self::cashFlowRows($bySection['investing'], $accounts);
        [$finRows, $finAccountsNet] = self::cashFlowRows($bySection['financing'], $accounts);
        if (bccomp($cashBuckets['financing'], '0', 2) !== 0) {
            // Only reachable if a year-end closing entry ever touched a cash account.
            $finRows[] = ['label' => 'Year-end closing entries', 'account_id' => null, 'code' => '', 'cash_impact' => $cashBuckets['financing']];
        }

        $operatingCash = bcadd(bcadd($netIncome, $nonCashTotal, 2), $wcTotal, 2);
        $investingCash = bcadd($invAccountsNet, $cashBuckets['investing'], 2);
        $financingCash = bcadd($finAccountsNet, $cashBuckets['financing'], 2);
        $fxEffect      = $cashBuckets['fx_effect'];
        $netChange     = bcadd(bcadd(bcadd($operatingCash, $investingCash, 2), $financingCash, 2), $fxEffect, 2);

        $cash = self::cashAccountBalances($accounts, $beforeFrom, $to);
        $closingCashCalc = bcadd($cash['opening'], $netChange, 2);
        $tieDiff = bcsub($closingCashCalc, $cash['closing'], 2);

        return [
            'period'     => ['from' => $from, 'to' => $to],
            'net_income' => $netIncome,
            'non_cash'   => $adjustments + ['total' => $nonCashTotal, 'lines' => $nonCashLines],
            'working_capital'       => $wcRows,
            'working_capital_total' => $wcTotal,
            'operating_cash'        => $operatingCash,
            'investing' => [
                'lines'                   => $invRows,
                'asset_disposal_proceeds' => $cashBuckets['investing'],
                'net'                     => $investingCash,
            ],
            'financing' => [
                'lines' => $finRows,
                'net'   => $financingCash,
            ],
            'fx_effect_on_cash' => $fxEffect,
            'net_change'        => $netChange,
            'opening_cash'      => $cash['opening'],
            'closing_cash_calc' => $closingCashCalc,
            'closing_cash_gl'   => $cash['closing'],
            'cash_accounts'     => $cash['accounts'],
            'tie_diff'          => $tieDiff,
            'is_tied_out'       => bccomp($tieDiff, '0', 2) === 0,
        ];
    }

    /**
     * The statement as ordered display rows — the single layout shared by the
     * report page (via the API), the PDF export and the year-end package, so the
     * three cannot drift apart.
     *
     * Row { type: section|line|subtotal|total, label, amount (null for a section
     * heading), indent (0|1) }.
     *
     * @param array $r cashFlow() result
     * @return array<int, array{type:string,label:string,amount:?string,indent:int}>
     */
    public static function cashFlowStatementRows(array $r): array
    {
        $rows = [];
        $add  = static function (string $type, string $label, ?string $amount = null, int $indent = 0) use (&$rows): void {
            $rows[] = ['type' => $type, 'label' => $label, 'amount' => $amount, 'indent' => $indent];
        };

        $add('section', 'Operating Activities');
        $add('line', 'Net income', $r['net_income']);
        foreach ($r['non_cash']['lines'] as $l) $add('line', $l['label'], $l['amount'], 1);
        foreach ($r['working_capital'] as $wc) $add('line', 'Change in ' . $wc['label'], $wc['cash_impact'], 1);
        $add('subtotal', 'Net cash from operating activities', $r['operating_cash']);

        $add('section', 'Investing Activities');
        foreach ($r['investing']['lines'] as $l) $add('line', $l['label'], $l['cash_impact'], 1);
        if (bccomp($r['investing']['asset_disposal_proceeds'], '0', 2) !== 0) {
            $add('line', 'Proceeds from asset disposals', $r['investing']['asset_disposal_proceeds'], 1);
        }
        $add('subtotal', 'Net cash from investing activities', $r['investing']['net']);

        $add('section', 'Financing Activities');
        foreach ($r['financing']['lines'] as $l) $add('line', $l['label'], $l['cash_impact'], 1);
        $add('subtotal', 'Net cash from financing activities', $r['financing']['net']);

        if (bccomp($r['fx_effect_on_cash'], '0', 2) !== 0) {
            $add('line', 'Effect of exchange-rate changes on cash', $r['fx_effect_on_cash']);
        }
        $add('total', 'Net change in cash', $r['net_change']);
        $add('line', 'Opening cash', $r['opening_cash']);
        $add('total', 'Closing cash', $r['closing_cash_calc']);
        $codes = implode(', ', array_map(static fn($a) => $a['code'], $r['cash_accounts']));
        $add('line', 'Closing cash per GL' . ($codes !== '' ? " ({$codes})" : ''), $r['closing_cash_gl']);
        return $rows;
    }

    /**
     * Every account keyed by id, each with a 'cf_section' of income | cash |
     * operating | investing | financing. Includes inactive and header accounts
     * so no journal line can fall outside the statement.
     *
     * Driven by account type/subtype, the is_bank_account flag and the account
     * links other modules already maintain (bank accounts, the QBO
     * undeposited-funds mapping, the lessor settings) — not by account codes.
     *
     * @return array<int, array>
     */
    private static function cashFlowAccounts(): array
    {
        $cashIds = $creditLineIds = $leaseIds = [];
        foreach (\db_select("SELECT gl_account_id, account_type FROM acc_bank_accounts") as $b) {
            if (in_array($b['account_type'], ['checking', 'savings'], true)) $cashIds[(int) $b['gl_account_id']] = true;
            if ($b['account_type'] === 'line_of_credit')                     $creditLineIds[(int) $b['gl_account_id']] = true;
        }
        foreach (\db_select(
            "SELECT ff_account_id FROM acc_qbo_account_map
              WHERE critical_category = 'undeposited_funds' AND ff_account_id IS NOT NULL"
        ) as $m) {
            $cashIds[(int) $m['ff_account_id']] = true;
        }
        $defaultCash = (int) AccountingService::setting('accounting.default_cash_account_id', 0);
        if ($defaultCash > 0) $cashIds[$defaultCash] = true;

        // The lessor's net investment (+ its deferred IDC and unearned-income
        // contra) is one receivable split across accounts; spec §21.3 reports
        // it under investing. Keeping every piece in one section also makes the
        // monthly current/long-term reclass net to zero instead of showing as an
        // operating outflow matched by an investing inflow.
        foreach ([
            'accounting.lessor_ni_current_account_id',
            'accounting.lessor_ni_longterm_account_id',
            'accounting.lessor_deferred_idc_account_id',
            'accounting.lessor_unearned_finance_income_account_id',
        ] as $key) {
            $id = (int) AccountingService::setting($key, 0);
            if ($id > 0) $leaseIds[$id] = true;
        }

        $out = [];
        foreach (\db_select(
            "SELECT id, code, name, account_type, account_subtype, normal_balance, coa_group,
                    currency, is_bank_account, is_active, sort_order
               FROM acc_accounts"
        ) as $a) {
            $id   = (int) $a['id'];
            $name = strtolower((string) $a['name']);
            if (in_array($a['account_type'], self::PL_TYPES, true)) {
                $section = 'income';
            } elseif ($a['account_type'] === 'asset') {
                if ((int) $a['is_bank_account'] === 1 || isset($cashIds[$id]) || str_contains($name, 'undeposited')) {
                    $section = 'cash';
                } elseif (isset($leaseIds[$id]) || self::isLongTermAsset($a)) {
                    $section = 'investing';
                } else {
                    $section = 'operating';
                }
            } elseif ($a['account_type'] === 'liability') {
                if (isset($leaseIds[$id])) {
                    $section = 'investing';
                } elseif (isset($creditLineIds[$id]) || self::isLongTermLiability($a)
                    || preg_match(self::CF_DEBT_NAME_PATTERN, $name) === 1) {
                    $section = 'financing';
                } else {
                    $section = 'operating';
                }
            } else {
                // Equity: share capital, owner drawings / dividends, and any
                // direct retained-earnings entry (the year-end close is excluded).
                $section = 'financing';
            }
            $a['cf_section'] = $section;
            $out[$id] = $a;
        }
        return $out;
    }

    /**
     * Turn account_id => cash impact into display rows (chart order, zero rows
     * dropped) plus their total.
     *
     * @param array<int,string> $impacts  account_id => credit − debit
     * @param array<int,array>  $accounts cashFlowAccounts()
     * @return array{0: array<int, array{label:string,account_id:int,code:string,cash_impact:string}>, 1: string}
     */
    private static function cashFlowRows(array $impacts, array $accounts): array
    {
        $ids = array_keys(array_filter($impacts, static fn($v) => bccomp($v, '0', 2) !== 0));
        usort($ids, static fn($x, $y) =>
            [(int) $accounts[$x]['sort_order'], $accounts[$x]['code']] <=> [(int) $accounts[$y]['sort_order'], $accounts[$y]['code']]);

        $rows  = [];
        $total = '0.00';
        foreach ($ids as $id) {
            $rows[] = [
                'label'       => $accounts[$id]['name'],
                'account_id'  => $id,
                'code'        => $accounts[$id]['code'],
                'cash_impact' => $impacts[$id],
            ];
            $total = bcadd($total, $impacts[$id], 2);
        }
        return [$rows, $total];
    }

    /**
     * Opening (end of $beforeFrom) and closing (end of $to) balance of every
     * cash account, plus the per-account detail. Accounts with no balance at
     * either date are listed only while active, so a dormant bank account
     * doesn't clutter the footer.
     *
     * @param array<int,array> $accounts   cashFlowAccounts()
     * @param string           $beforeFrom YYYY-MM-DD
     * @param string           $to         YYYY-MM-DD
     * @return array{opening:string, closing:string, accounts:array}
     */
    private static function cashAccountBalances(array $accounts, string $beforeFrom, string $to): array
    {
        $cash = array_filter($accounts, static fn($a) => $a['cf_section'] === 'cash');
        $out  = ['opening' => '0.00', 'closing' => '0.00', 'accounts' => []];
        if (!$cash) return $out;

        $in  = implode(',', array_fill(0, count($cash), '?'));
        $bal = [];
        foreach (\db_select(
            "SELECT jel.account_id,
                    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jel.debit - jel.credit ELSE 0 END), 0) AS opening,
                    COALESCE(SUM(jel.debit - jel.credit), 0) AS closing
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE jel.account_id IN ({$in})
                AND je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.entry_date <= ?
              GROUP BY jel.account_id",
            array_merge([$beforeFrom], array_keys($cash), [$to])
        ) as $b) {
            $bal[(int) $b['account_id']] = $b;
        }

        uasort($cash, static fn($x, $y) => [(int) $x['sort_order'], $x['code']] <=> [(int) $y['sort_order'], $y['code']]);
        foreach ($cash as $id => $a) {
            $opening = bcadd((string) ($bal[$id]['opening'] ?? '0'), '0', 2);
            $closing = bcadd((string) ($bal[$id]['closing'] ?? '0'), '0', 2);
            $out['opening'] = bcadd($out['opening'], $opening, 2);
            $out['closing'] = bcadd($out['closing'], $closing, 2);
            if (!(int) $a['is_active'] && bccomp($opening, '0', 2) === 0 && bccomp($closing, '0', 2) === 0) continue;
            $out['accounts'][] = [
                'account_id' => $id,
                'code'       => $a['code'],
                'name'       => $a['name'],
                'currency'   => $a['currency'],
                'opening'    => $opening,
                'closing'    => $closing,
            ];
        }
        return $out;
    }

    // ============================================================
    // WORKING TRIAL BALANCE (spec §23.2)
    // ============================================================

    /** Journal entry types that count as adjusting entries (S-ACCT-AJE, D-AJE-3). */
    private const AJE_ENTRY_TYPES = ['adjusting', 'reclassifying', 'prior_period'];

    /**
     * Working Trial Balance v2 — GL# | Account | Lead | PY Balance | Unadj CY |
     * AJEs | Adj CY | Var $ | Var % | Ref.
     *
     * EVERY AMOUNT COLUMN IS A BALANCE, on the same basis as the balance sheet
     * and P&L:
     *   - balance-sheet accounts: cumulative balance at the date
     *   - income-statement accounts: fiscal year-to-date (Jan 1 → the date)
     *   - plus a computed "Retained Earnings — prior years not yet closed" row
     *     (the balance sheet's figure), which is what keeps debits = credits
     *     when earlier years were never closed
     *   Adj CY    = that balance at the period's end date
     *   AJEs      = this period's adjusting / reclassifying / prior-period entries
     *   Unadj CY  = Adj CY − AJEs (the balance before this period's adjustments)
     *   PY        = the same balance at the comparison date
     *   Var $ / % = Adj CY − PY — like-for-like
     *
     * WHY (S-CASHFLOW-TIE): Unadj CY / AJEs / Adj CY used to be the selected
     * PERIOD's activity only, while PY Balance was a cumulative balance — so
     * Var $ compared a month's movement with a multi-year balance (July 2026 on
     * dev showed cash "down $697,340" in a month cash rose $21,401), and PY
     * revenue was every unclosed year since inception rather than the prior
     * year. Inactive accounts were also dropped even when they carried a balance.
     *
     * @param array  $period      acc_periods row (id, name, start_date, end_date, year, status)
     * @param array  $pyPeriod    ['id' => ?int, 'name' => string, 'end_date' => YYYY-MM-DD]
     * @param string $materiality non-negative decimal; > 0 enables the flags
     * @return array { period, py_period, materiality, basis, accounts[], totals, is_balanced }
     */
    public static function workingTrialBalance(array $period, array $pyPeriod, string $materiality = '0.00'): array
    {
        $cy = self::trialBalanceAt((string) $period['end_date']);
        $py = self::trialBalanceAt((string) $pyPeriod['end_date']);

        // This period's adjusting entries, per account, in one pass. Periods are
        // assigned from entry_date (JournalEntryService::create()), so every AJE
        // with this period_id falls inside the Adj CY balance above.
        $ajeTypes = self::AJE_ENTRY_TYPES;
        $ajeIn    = implode(',', array_fill(0, count($ajeTypes), '?'));
        $ajeNet   = [];
        foreach (\db_select(
            "SELECT jel.account_id, COALESCE(SUM(jel.debit - jel.credit), 0) AS net
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.period_id = ?
                AND je.entry_type IN ({$ajeIn})
              GROUP BY jel.account_id",
            array_merge([(int) $period['id']], $ajeTypes)
        ) as $r) {
            $ajeNet[(int) $r['account_id']] = (string) $r['net'];
        }

        // AJE-line drilldown (most recent 5 per account in this period).
        $ajeLinesByAcct = [];
        foreach (\db_select(
            "SELECT jel.account_id, je.id AS je_id, je.entry_number, je.description,
                    jel.debit, jel.credit, je.entry_date
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.period_id = ?
                AND je.entry_type IN ({$ajeIn})
              ORDER BY je.entry_date DESC, je.id DESC",
            array_merge([(int) $period['id']], $ajeTypes)
        ) as $line) {
            $aid = (int) $line['account_id'];
            if (count($ajeLinesByAcct[$aid] ?? []) >= 5) continue;
            $ajeLinesByAcct[$aid][] = [
                'je_id'        => (int) $line['je_id'],
                'entry_number' => $line['entry_number'],
                'description'  => $line['description'],
                'entry_date'   => $line['entry_date'],
                'debit'        => $line['debit'],
                'credit'       => $line['credit'],
            ];
        }

        // No is_active filter: an inactive account that still carries a balance
        // must stay on the trial balance or debits stop equalling credits.
        $accounts = \db_select(
            "SELECT id, code, name, account_type, normal_balance, lead_schedule_code, coa_group, sort_order
               FROM acc_accounts
              WHERE is_header = 0
              ORDER BY sort_order ASC, code ASC"
        );
        // Computed equity row, placed after the equity accounts by the page's
        // type grouping. Debit − credit convention like every other balance.
        $accounts[] = [
            'id' => 'retained_earnings_unclosed', 'code' => '', 'name' => 'Retained Earnings — prior years not yet closed',
            'account_type' => 'equity', 'normal_balance' => 'credit', 'lead_schedule_code' => null, 'coa_group' => 'Equity',
            'is_computed' => true,
        ];

        $result = [];
        $totals = ['py_balance' => '0.00', 'unadj_cy' => '0.00', 'ajes' => '0.00', 'adj_cy' => '0.00', 'debits' => '0.00', 'credits' => '0.00'];

        foreach ($accounts as $acct) {
            $aid      = empty($acct['is_computed']) ? (int) $acct['id'] : $acct['id'];
            $isDebit  = $acct['normal_balance'] === 'debit';
            // Balances arrive as debit − credit; show each on its normal side.
            $toNormal = static fn(string $debitNet): string => $isDebit ? bcadd($debitNet, '0', 2) : bcmul($debitNet, '-1', 2);

            $adjCy  = $toNormal($cy[$aid] ?? '0');
            $pyBal  = $toNormal($py[$aid] ?? '0');
            $ajeAmt = $toNormal(is_int($aid) ? ($ajeNet[$aid] ?? '0') : '0');
            $unadjCy = bcsub($adjCy, $ajeAmt, 2);

            if (bccomp($adjCy, '0', 2) === 0 && bccomp($pyBal, '0', 2) === 0 && bccomp($ajeAmt, '0', 2) === 0) {
                continue;
            }

            $varAmt = bcsub($adjCy, $pyBal, 2);
            $varPct = null;
            $absPy  = ltrim($pyBal, '-');
            if (bccomp($absPy, '0', 2) !== 0) {
                $varPct = bcmul(bcdiv($varAmt, $absPy, 6), '100', 2);
            }

            $balanceFlag = $varianceFlag = null;
            if (bccomp($materiality, '0', 2) > 0) {
                if (bccomp(ltrim($adjCy, '-'), $materiality, 2) > 0)  $balanceFlag  = 'red';
                if (bccomp(ltrim($varAmt, '-'), $materiality, 2) > 0) $varianceFlag = 'yellow';
            }

            $totals['py_balance'] = bcadd($totals['py_balance'], $pyBal, 2);
            $totals['unadj_cy']   = bcadd($totals['unadj_cy'], $unadjCy, 2);
            $totals['ajes']       = bcadd($totals['ajes'], $ajeAmt, 2);
            $totals['adj_cy']     = bcadd($totals['adj_cy'], $adjCy, 2);
            // Debit/credit columns from the underlying side, so a contra balance
            // (a negative normal-side amount) lands on the opposite column.
            $onDebitSide = $isDebit === (bccomp($adjCy, '0', 2) >= 0);
            $totals[$onDebitSide ? 'debits' : 'credits'] = bcadd($totals[$onDebitSide ? 'debits' : 'credits'], ltrim($adjCy, '-'), 2);

            $result[] = [
                'account_id'         => $aid,
                'code'               => $acct['code'],
                'name'               => $acct['name'],
                'account_type'       => $acct['account_type'],
                'normal_balance'     => $acct['normal_balance'],
                'lead_schedule_code' => $acct['lead_schedule_code'],
                'coa_group'          => $acct['coa_group'],
                'py_balance'         => $pyBal,
                'unadj_cy'           => $unadjCy,
                'ajes'               => $ajeAmt,
                'adj_cy'             => $adjCy,
                'var_amt'            => $varAmt,
                'var_pct'            => $varPct,
                'balance_flag'       => $balanceFlag,
                'variance_flag'      => $varianceFlag,
                'ref'                => $acct['lead_schedule_code'],
                'aje_entries'        => is_int($aid) ? ($ajeLinesByAcct[$aid] ?? []) : [],
                'is_computed'        => !empty($acct['is_computed']),
            ];
        }

        return [
            'period'      => $period,
            'py_period'   => $pyPeriod,
            'materiality' => $materiality,
            'basis'       => 'Balances at each date: balance-sheet accounts cumulative, income-statement accounts '
                           . 'fiscal year-to-date. Unadj CY = Adj CY before this period\'s adjusting entries; '
                           . 'Var = Adj CY − PY.',
            'accounts'    => $result,
            'totals'      => $totals,
            'is_balanced' => bccomp($totals['debits'], $totals['credits'], 2) === 0,
        ];
    }

    /**
     * Trial-balance basis balances at a date, as debit − credit per account id:
     * cumulative for balance-sheet accounts, fiscal year-to-date for
     * income-statement accounts, plus 'retained_earnings_unclosed' — every
     * earlier year's P&L not moved to retained earnings by a year-end close
     * (the same figure buildBSBlock() shows). The entries sum to zero.
     *
     * @param string $asOf YYYY-MM-DD
     * @return array<int|string, string>
     */
    private static function trialBalanceAt(string $asOf): array
    {
        $fyStart = substr($asOf, 0, 4) . '-01-01'; // fiscal year = calendar year (A4)
        $out = [];
        $priorEarnings = '0.00';
        foreach (\db_select(
            "SELECT jel.account_id, a.account_type,
                    COALESCE(SUM(jel.debit - jel.credit), 0) AS cumulative,
                    COALESCE(SUM(CASE WHEN je.entry_date >= ? THEN jel.debit - jel.credit ELSE 0 END), 0) AS fiscal_ytd
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.entry_date <= ?
              GROUP BY jel.account_id, a.account_type",
            [$fyStart, $asOf]
        ) as $r) {
            if (in_array($r['account_type'], self::PL_TYPES, true)) {
                $out[(int) $r['account_id']] = (string) $r['fiscal_ytd'];
                $priorEarnings = bcadd($priorEarnings, bcsub((string) $r['cumulative'], (string) $r['fiscal_ytd'], 2), 2);
            } else {
                $out[(int) $r['account_id']] = (string) $r['cumulative'];
            }
        }
        $out['retained_earnings_unclosed'] = $priorEarnings;
        return $out;
    }

    // ============================================================
    // ASSET SCHEDULE
    // ============================================================

    /**
     * PP&E continuity schedule as of a date.
     *
     * @param string $asOf      YYYY-MM-DD
     * @param string $category 'all' | asset_class value
     * @return array
     */
    public static function assetSchedule(string $asOf, string $category = 'all'): array
    {
        $year     = (int) substr($asOf, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);

        $where  = ['fa.deleted_at IS NULL OR fa.deleted_at IS NULL']; // placeholder
        $params = [];
        // acc_fixed_assets has no deleted_at on every install — keep filter simple
        $where = ['1=1'];

        if ($category !== 'all') {
            $where[] = 'fa.asset_class = ?';
            $params[] = $category;
        }
        $whereSql = implode(' AND ', $where);

        $assets = \db_select(
            "SELECT fa.id, fa.asset_number, fa.name, fa.asset_class,
                    fa.acquisition_date, fa.acquisition_cost, fa.salvage_value,
                    fa.depreciable_cost, fa.accumulated_depreciation,
                    fa.net_book_value, fa.useful_life_years,
                    fa.depreciation_method
               FROM acc_fixed_assets fa
              WHERE {$whereSql}
              ORDER BY fa.asset_class ASC, fa.asset_number ASC",
            $params
        );

        // Disposals in the period
        $disposalsRows = \db_select(
            "SELECT asset_id, disposal_date, proceeds, net_book_value_at_disposal, gain_loss
               FROM acc_asset_disposals
              WHERE disposal_date BETWEEN ? AND ?",
            [$yearStart, $asOf]
        );
        $disposalsByAsset = [];
        foreach ($disposalsRows as $d) {
            $disposalsByAsset[(int) $d['asset_id']] = $d;
        }

        // Depreciation in the period (this run-line set is the YTD movement)
        $deprRows = \db_select(
            "SELECT drl.asset_id,
                    COALESCE(SUM(drl.depreciation), 0) AS ytd_depr
               FROM acc_depreciation_run_lines drl
               JOIN acc_depreciation_runs dr ON dr.id = drl.run_id
              WHERE dr.run_date BETWEEN ? AND ?
              GROUP BY drl.asset_id",
            [$yearStart, $asOf]
        );
        $deprByAsset = [];
        foreach ($deprRows as $r) {
            $deprByAsset[(int) $r['asset_id']] = (string) $r['ytd_depr'];
        }

        $byClass = [];
        foreach ($assets as $a) {
            $cls   = (string) $a['asset_class'];
            $aId   = (int) $a['id'];
            $cost  = (string) $a['acquisition_cost'];
            $ytdDepr = $deprByAsset[$aId] ?? '0.00';

            // Additions: assets acquired in this year
            $addition = (strtotime($a['acquisition_date']) >= strtotime($yearStart)
                && strtotime($a['acquisition_date']) <= strtotime($asOf))
                ? $cost : '0.00';

            $disposalRow = $disposalsByAsset[$aId] ?? null;
            $disposalCost = $disposalRow ? $cost : '0.00';
            $disposalNbv  = $disposalRow ? (string) $disposalRow['net_book_value_at_disposal'] : '0.00';

            if (!isset($byClass[$cls])) {
                $byClass[$cls] = [
                    'asset_class'        => $cls,
                    'opening_cost'       => '0.00',
                    'additions'          => '0.00',
                    'disposals_cost'     => '0.00',
                    'closing_cost'       => '0.00',
                    'opening_accum_dep'  => '0.00',
                    'current_depr'       => '0.00',
                    'disposals_accum_dep' => '0.00',
                    'closing_accum_dep'  => '0.00',
                    'nbv'                => '0.00',
                    'assets'             => [],
                ];
            }

            $openingCost = bcsub($cost, $addition, 2);
            $openingAccumDep = bcsub((string) $a['accumulated_depreciation'], $ytdDepr, 2);

            $byClass[$cls]['opening_cost']       = bcadd($byClass[$cls]['opening_cost'], $openingCost, 2);
            $byClass[$cls]['additions']          = bcadd($byClass[$cls]['additions'], $addition, 2);
            $byClass[$cls]['disposals_cost']     = bcadd($byClass[$cls]['disposals_cost'], $disposalCost, 2);
            $byClass[$cls]['closing_cost']       = bcadd($byClass[$cls]['closing_cost'], $cost, 2);
            $byClass[$cls]['opening_accum_dep']  = bcadd($byClass[$cls]['opening_accum_dep'], $openingAccumDep, 2);
            $byClass[$cls]['current_depr']       = bcadd($byClass[$cls]['current_depr'], $ytdDepr, 2);
            $byClass[$cls]['disposals_accum_dep'] = bcadd($byClass[$cls]['disposals_accum_dep'], bcsub($cost, $disposalNbv, 2), 2);
            $byClass[$cls]['closing_accum_dep']  = bcadd($byClass[$cls]['closing_accum_dep'], (string) $a['accumulated_depreciation'], 2);
            $byClass[$cls]['nbv']                = bcadd($byClass[$cls]['nbv'], (string) $a['net_book_value'], 2);

            $byClass[$cls]['assets'][] = [
                'asset_id'            => $aId,
                'asset_number'        => $a['asset_number'],
                'name'                => $a['name'],
                'acquisition_date'    => $a['acquisition_date'],
                'acquisition_cost'    => $cost,
                'accumulated_depreciation' => (string) $a['accumulated_depreciation'],
                'net_book_value'      => (string) $a['net_book_value'],
                'ytd_depreciation'    => $ytdDepr,
                'disposed'            => $disposalRow !== null,
            ];
        }

        return [
            'as_of'    => $asOf,
            'category' => $category,
            'classes'  => array_values($byClass),
        ];
    }
}
