<?php
declare(strict_types=1);

/**
 * lib/Accounting/ReportingService.php
 *
 * Financial reporting engine — produces the four canonical management
 * reports (Profit & Loss, Balance Sheet, Cash Flow Statement, Fixed
 * Asset Schedule) from posted JE data. All monetary arithmetic via
 * bcmath; all balance queries delegate to AccountingService so a single
 * implementation is the source of truth for "what does this account
 * have on it as of date X".
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 (Financial Statements)
 *           and §21 (Phase B reporting + budgeting build).
 * Session:  S036
 */

namespace FleetForge\Accounting;

class ReportingService
{
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
                foreach ($base[$group] as &$row) {
                    $cmp = $compare[$group][$row['account_id']] ?? null;
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

        // Tax provision: sum acc_tax_remittances in the period (DR side of remittance JE)
        $taxRow = \db_row(
            "SELECT COALESCE(SUM(amount), 0) AS total
               FROM acc_tax_remittances
              WHERE remittance_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $taxProvision = (string) ($taxRow['total'] ?? '0.00');
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

        $rows = \db_select(
            "SELECT a.id AS account_id, a.code, a.name, a.account_type, a.normal_balance,
                    COALESCE(SUM(jel.debit), 0) AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit
               FROM acc_accounts a
               LEFT JOIN acc_journal_entry_lines jel ON jel.account_id = a.id
               LEFT JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                                              AND je.status = 'posted'
                                              AND je.entry_date BETWEEN ? AND ?
              WHERE a.account_type IN ({$placeholders})
                AND a.is_active = 1
                AND a.is_header = 0
              GROUP BY a.id, a.code, a.name, a.account_type, a.normal_balance
              ORDER BY a.sort_order ASC, a.code ASC",
            array_merge([$from, $to], $types)
        );

        $out = [];
        foreach ($rows as $r) {
            $debit  = (string) ($r['total_debit']  ?? '0.00');
            $credit = (string) ($r['total_credit'] ?? '0.00');

            // Revenue / income / liability / equity: credit-normal → amount = credit - debit
            // Cost / expense / asset: debit-normal → amount = debit - credit
            $amount = $r['normal_balance'] === 'credit'
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
                    AND je.status = 'posted'
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

    private static function buildBSBlock(string $asOf): array
    {
        // Inject net income from start of fiscal year (Jan 1 of $asOf's year)
        $year       = (int) substr($asOf, 0, 4);
        $fyStart    = sprintf('%04d-01-01', $year);
        $pl         = self::profitAndLoss($fyStart, $asOf);
        $netIncomeYtd = $pl['net_income'];

        $assets = self::balanceSheetSection($asOf, ['asset']);
        $liabs  = self::balanceSheetSection($asOf, ['liability']);
        $equity = self::balanceSheetSection($asOf, ['equity']);

        // Split assets and liabilities by subtype hint in coa_group/name
        [$currentAssets, $longTermAssets] = self::splitAssetsByTerm($assets);
        [$currentLiabs, $longTermLiabs]   = self::splitLiabilitiesByTerm($liabs);

        $totalAssets         = self::sumBSSection($assets);
        $totalLiabilities    = self::sumBSSection($liabs);
        // Equity rows + Net Income YTD injection
        $totalEquityRows     = self::sumBSSection($equity);
        $totalEquity         = bcadd($totalEquityRows, $netIncomeYtd, 2);

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
            'total_equity'          => $totalEquity,
            'total_liabilities_and_equity' => $liabPlusEquity,
            'is_balanced'           => $isBalanced,
            'drift'                 => $drift,
        ];
    }

    /**
     * Pull active accounts of the given types with their balance as of the date.
     * Each row { account_id, code, name, account_type, normal_balance, coa_group, amount }.
     */
    private static function balanceSheetSection(string $asOf, array $types): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $accts = \db_select(
            "SELECT id, code, name, account_type, normal_balance, coa_group, sort_order
               FROM acc_accounts
              WHERE account_type IN ({$placeholders})
                AND is_active = 1
                AND is_header = 0
              ORDER BY sort_order ASC, code ASC",
            $types
        );

        $out = [];
        foreach ($accts as $a) {
            $amount = AccountingService::accountBalance((int) $a['id'], $asOf);
            if (bccomp($amount, '0', 2) === 0) continue;
            $out[(int) $a['id']] = [
                'account_id'     => (int) $a['id'],
                'code'           => $a['code'],
                'name'           => $a['name'],
                'account_type'   => $a['account_type'],
                'normal_balance' => $a['normal_balance'],
                'coa_group'      => $a['coa_group'],
                'amount'         => $amount,
            ];
        }
        return $out;
    }

    private static function sumBSSection(array $section): string
    {
        $total = '0.00';
        foreach ($section as $row) $total = bcadd($total, $row['amount'], 2);
        return $total;
    }

    /**
     * Split asset rows into current vs long-term. Convention: account codes
     * starting with 11- are current (cash, AR, inventory), 12-15 are long-term
     * (fixed assets, intangibles). If no clear hint, default to current.
     */
    private static function splitAssetsByTerm(array $assets): array
    {
        $current   = [];
        $longTerm  = [];
        foreach ($assets as $key => $row) {
            $code = (string) $row['code'];
            $group = strtolower((string) ($row['coa_group'] ?? ''));
            $isLongTerm = (
                str_starts_with($code, '12') || str_starts_with($code, '13') ||
                str_starts_with($code, '14') || str_starts_with($code, '15') ||
                str_contains($group, 'fixed') ||
                str_contains($group, 'long')
            );
            if ($isLongTerm) $longTerm[$key] = $row;
            else             $current[$key]  = $row;
        }
        return [$current, $longTerm];
    }

    /**
     * Split liability rows into current vs long-term. Convention: codes
     * starting with 25- are long-term debt, everything else is current.
     */
    private static function splitLiabilitiesByTerm(array $liabs): array
    {
        $current  = [];
        $longTerm = [];
        foreach ($liabs as $key => $row) {
            $code = (string) $row['code'];
            $group = strtolower((string) ($row['coa_group'] ?? ''));
            $isLongTerm = (
                str_starts_with($code, '25') || str_starts_with($code, '26') ||
                str_contains($group, 'long')
            );
            if ($isLongTerm) $longTerm[$key] = $row;
            else             $current[$key]  = $row;
        }
        return [$current, $longTerm];
    }

    // ============================================================
    // CASH FLOW STATEMENT (ASPE 1540 indirect method)
    // ============================================================

    /**
     * Cash flow statement for a date range using the indirect method.
     */
    public static function cashFlow(string $from, string $to): array
    {
        $pl         = self::profitAndLoss($from, $to);
        $netIncome  = $pl['net_income'];

        // Non-cash adjustments
        $depreciation     = self::sumJELinesBySourceType('depreciation', $from, $to, 'debit');
        $assetDisposal    = self::sumJELinesBySourceType('asset_disposal', $from, $to, 'net');
        $badDebt          = self::sumJELinesBySourceType('bad_debt', $from, $to, 'debit');
        $fxRevaluation    = self::sumJELinesBySourceType('fx_revaluation', $from, $to, 'net');
        $nonCashTotal = bcadd(bcadd(bcadd($depreciation, $assetDisposal, 2), $badDebt, 2), $fxRevaluation, 2);

        // Working capital changes — opening vs closing balance comparison
        $beforeFrom = date('Y-m-d', strtotime($from . ' -1 day'));
        $wcAccounts = self::wcAccountIds();
        $wcChanges = [];
        $wcTotal = '0.00';
        foreach ($wcAccounts as $label => $acctIds) {
            $opening = '0.00';
            $closing = '0.00';
            foreach ($acctIds as $aid) {
                $opening = bcadd($opening, AccountingService::accountBalance($aid, $beforeFrom), 2);
                $closing = bcadd($closing, AccountingService::accountBalance($aid, $to), 2);
            }
            $change = bcsub($closing, $opening, 2);
            // For asset accounts (AR, prepaid): increase reduces cash, decrease increases cash
            // For liability accounts (AP, accrued, deposits, tax payable): increase adds cash, decrease subtracts
            $sign = in_array($label, ['ar', 'prepaid'], true) ? '-1' : '1';
            $signedChange = bcmul($change, $sign, 2);
            $wcChanges[] = ['label' => $label, 'opening' => $opening, 'closing' => $closing, 'change' => $change, 'cash_impact' => $signedChange];
            $wcTotal = bcadd($wcTotal, $signedChange, 2);
        }

        $operatingCash = bcadd(bcadd($netIncome, $nonCashTotal, 2), $wcTotal, 2);

        // Investing: net of asset acquisitions (cost in period) and disposals proceeds
        $investingAcq      = self::sumAssetAcquisitions($from, $to);
        $investingProceeds = self::sumAssetDisposalProceeds($from, $to);
        $investingCash     = bcsub($investingProceeds, $investingAcq, 2);

        // Financing: long-term debt + dividends/owner draws (rows ON equity accounts whose name contains "dividend")
        $longTermDebtNet = self::sumLongTermDebtNet($from, $to);
        $dividends       = self::sumDividends($from, $to);
        $financingCash   = bcsub($longTermDebtNet, $dividends, 2);

        $netChange    = bcadd(bcadd($operatingCash, $investingCash, 2), $financingCash, 2);
        $openingCash  = self::cashAccountBalance($beforeFrom);
        $closingCashCalc = bcadd($openingCash, $netChange, 2);
        $closingCashGL   = self::cashAccountBalance($to);
        $tieDiff = bcsub($closingCashCalc, $closingCashGL, 2);
        $isTiedOut = bccomp($tieDiff, '1.00', 2) <= 0 && bccomp($tieDiff, '-1.00', 2) >= 0;

        return [
            'period'             => ['from' => $from, 'to' => $to],
            'net_income'         => $netIncome,
            'non_cash' => [
                'depreciation'      => $depreciation,
                'asset_disposal'    => $assetDisposal,
                'bad_debt'          => $badDebt,
                'fx_revaluation'    => $fxRevaluation,
                'total'             => $nonCashTotal,
            ],
            'working_capital'   => $wcChanges,
            'working_capital_total' => $wcTotal,
            'operating_cash'    => $operatingCash,
            'investing' => [
                'asset_acquisitions'    => $investingAcq,
                'asset_disposal_proceeds' => $investingProceeds,
                'net'                   => $investingCash,
            ],
            'financing' => [
                'long_term_debt_net' => $longTermDebtNet,
                'dividends'          => $dividends,
                'net'                => $financingCash,
            ],
            'net_change'        => $netChange,
            'opening_cash'      => $openingCash,
            'closing_cash_calc' => $closingCashCalc,
            'closing_cash_gl'   => $closingCashGL,
            'tie_diff'          => $tieDiff,
            'is_tied_out'       => $isTiedOut,
        ];
    }

    /**
     * Sum JE lines for the given source_type within date range.
     * Direction:
     *   'debit'  → return debit side (typical for expense)
     *   'credit' → return credit side
     *   'net'    → return (debit - credit)
     */
    private static function sumJELinesBySourceType(string $sourceType, string $from, string $to, string $dir): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.debit), 0) AS dr, COALESCE(SUM(jel.credit), 0) AS cr
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
              WHERE je.source_type = ?
                AND je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?",
            [$sourceType, $from, $to]
        );
        $dr = (string) ($row['dr'] ?? '0.00');
        $cr = (string) ($row['cr'] ?? '0.00');
        return match ($dir) {
            'debit'  => $dr,
            'credit' => $cr,
            'net'    => bcsub($dr, $cr, 2),
            default  => '0.00',
        };
    }

    /**
     * Conventional account-code map for working capital. Returns
     * [label => [account_id, ...]]. Resolves by code via grep, then by name fallback.
     */
    private static function wcAccountIds(): array
    {
        $byCode = static function (array $codes): array {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $rows = \db_select(
                "SELECT id FROM acc_accounts WHERE code IN ({$placeholders}) AND is_active = 1",
                $codes
            );
            return array_map(static fn($r) => (int) $r['id'], $rows);
        };

        return [
            'ar'                => $byCode(['1030']),
            'prepaid'           => $byCode(['1050']),
            'ap'                => $byCode(['2010']),
            'accrued_liabs'     => $byCode(['2070']),
            'customer_deposits' => $byCode(['2050']),
            'tax_payable'       => $byCode(['2030', '2040', '2080']),
        ];
    }

    private static function sumAssetAcquisitions(string $from, string $to): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(acquisition_cost), 0) AS total
               FROM acc_fixed_assets
              WHERE acquisition_date BETWEEN ? AND ?",
            [$from, $to]
        );
        return (string) ($row['total'] ?? '0.00');
    }

    private static function sumAssetDisposalProceeds(string $from, string $to): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(proceeds), 0) AS total
               FROM acc_asset_disposals
              WHERE disposal_date BETWEEN ? AND ?",
            [$from, $to]
        );
        return (string) ($row['total'] ?? '0.00');
    }

    private static function sumLongTermDebtNet(string $from, string $to): string
    {
        // Account codes starting with '25' are long-term debt by convention.
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.credit - jel.debit), 0) AS net
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE a.code LIKE '25%%'
                AND je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?",
            [$from, $to]
        );
        return (string) ($row['net'] ?? '0.00');
    }

    private static function sumDividends(string $from, string $to): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.debit - jel.credit), 0) AS net
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE a.account_type = 'equity'
                AND LOWER(a.name) LIKE '%dividend%'
                AND je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?",
            [$from, $to]
        );
        return (string) ($row['net'] ?? '0.00');
    }

    private static function cashAccountBalance(string $asOf): string
    {
        // GL account 1010 — primary operating cash, per spec
        $row = \db_row("SELECT id FROM acc_accounts WHERE code = '1010'");
        if (!$row) return '0.00';
        return AccountingService::accountBalance((int) $row['id'], $asOf);
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
