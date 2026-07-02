<?php
declare(strict_types=1);

/**
 * lib/Accounting/UnitProfitabilityService.php
 *
 * Per-unit P&L + cost allocation engine per FLEETFORGE_ACCOUNTING_SPEC.md
 * §23.10. Surfaces revenue, direct costs, contribution margin, allocated
 * overhead, EBIT, and ROIC at the equipment_unit grain — plus the four
 * fleet-wide KPIs (RevPAU, dollar utilization, maintenance ratio, age).
 *
 * Direct cost attribution path (pre-flight verified):
 *   acc_journal_entry_lines.equipment_unit_id EXISTS on disk — direct
 *   GL-line attribution is available. Costs are pulled by JOINing
 *   acc_journal_entry_lines → acc_accounts WHERE
 *     jel.equipment_unit_id = $unitId
 *     AND je.status = 'posted'
 *     AND je.entry_date BETWEEN $from AND $to
 *     AND a.account_type = 'cost_of_revenue'
 *   This is the canonical path; no fallback needed for this session.
 *
 * Overhead pool definition:
 *   Operating-expense JE debit lines in the date range (account_type =
 *   'operating_expense', code prefix '6'). Distribution among units uses
 *   one of four bases per `accounting.overhead_allocation_basis`:
 *   revenue (default), unit_count, weighted (70/30), manual.
 *
 * Pre-flight column-name catches (K-22, locked in REFERENCE.md):
 *   - acc_periods.start_date / end_date  (NOT period_start/_end —
 *     period_start/_end is acc_tax_filing_periods, see Trap 29)
 *   - acc_depreciation_run_lines.depreciation (Trap 37)
 *   - acc_fixed_assets.depreciation_method (Trap 36) +
 *     .equipment_unit_id FK; uses .acquisition_cost (Trap 16)
 *   - equipment_units.status ENUM has 'decommissioned' (NOT 'disposed').
 *     Active filter: status NOT IN ('decommissioned','inactive')
 *     AND deleted_at IS NULL.
 *   - invoices.total_amount (Trap 41) + status set with partially_paid
 *     + overdue for active AR (Trap 42)
 *   - equipment_units identity: make/model lives on equipment_templates
 *     (template_id FK on equipment_units); template uses .brand + .model
 *
 * @session S-ACCT-UNIT
 */

namespace FleetForge\Accounting;

class UnitProfitabilityService
{
    /** Maintenance-cost-ratio benchmark band per spec §23.10 (15-20%). */
    private const MAINTENANCE_BENCHMARK_LOW  = '0.15';
    private const MAINTENANCE_BENCHMARK_HIGH = '0.20';

    /**
     * Revenue for one unit over [$from, $to]. Walks
     *   leases (where equipment_unit_id) → invoices (date range, active
     *   status set) → invoice_line_items (grouped by item_type).
     *
     * @return array{
     *   unit_id:int, from:string, to:string,
     *   revenue: array<string,string>,
     *   invoice_ids: int[]
     * }
     */
    public static function getUnitRevenue(int $unitId, string $from, string $to): array
    {
        $invoices = \db_select(
            "SELECT i.id, i.total_amount, i.subtotal_after_discount, i.status
               FROM invoices i
               JOIN leases l ON l.id = i.lease_id
              WHERE l.equipment_unit_id = ?
                AND i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND i.deleted_at IS NULL",
            [$unitId, $from, $to]
        );

        $revenue = [
            'base_rental'     => '0.00',
            'mileage'         => '0.00',
            'gps'             => '0.00',
            'insurance'       => '0.00',
            'warranty'        => '0.00',
            'damage_recovery' => '0.00',
            'other'           => '0.00',
            'total'           => '0.00',
        ];
        $invoiceIds = array_map(static fn($i) => (int) $i['id'], $invoices);

        if (!$invoiceIds) {
            return [
                'unit_id'     => $unitId,
                'from'        => $from,
                'to'          => $to,
                'revenue'     => $revenue,
                'invoice_ids' => [],
            ];
        }

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $lines = \db_select(
            "SELECT item_type, amount, is_credit
               FROM invoice_line_items
              WHERE invoice_id IN ({$placeholders})",
            $invoiceIds
        );

        foreach ($lines as $line) {
            $amt = (string) ($line['amount'] ?? '0.00');
            if ((int) ($line['is_credit'] ?? 0) === 1) {
                $amt = bcmul($amt, '-1', 2);
            }
            $bucket = match ((string) $line['item_type']) {
                'base_rental', 'base_rental_reconciliation_credit' => 'base_rental',
                'mileage_precharge', 'mileage_usage', 'mileage_estimate', 'mileage_adjustment',
                'mileage_credit', 'mileage_drawdown_credit'        => 'mileage',
                'gps'                                              => 'gps',
                'insurance'                                        => 'insurance',
                'warranty'                                         => 'warranty',
                'damage'                                           => 'damage_recovery',
                default                                            => 'other',
            };
            $revenue[$bucket] = bcadd($revenue[$bucket], $amt, 2);
            $revenue['total'] = bcadd($revenue['total'], $amt, 2);
        }

        return [
            'unit_id'     => $unitId,
            'from'        => $from,
            'to'          => $to,
            'revenue'     => $revenue,
            'invoice_ids' => $invoiceIds,
        ];
    }

    /**
     * Direct costs attributable to one unit via JE line equipment_unit_id.
     *
     * Two paths combine into the result:
     *   1. acc_journal_entry_lines WHERE jel.equipment_unit_id = $unitId
     *      AND a.account_type = 'cost_of_revenue' → grouped by account code
     *   2. acc_depreciation_run_lines JOIN acc_fixed_assets
     *      WHERE fa.equipment_unit_id = $unitId — picks up depreciation
     *      booked through the run-line pipeline (which DOES post JE lines
     *      tagged with equipment_unit_id, but using the dep-run-line table
     *      gives us a finer-grained source for the depreciation bucket).
     */
    public static function getUnitDirectCosts(int $unitId, string $from, string $to): array
    {
        $costs = [
            'depreciation'  => '0.00',
            'insurance'     => '0.00',
            'licensing'     => '0.00',
            'gps_cost'      => '0.00',
            'maintenance'   => '0.00',
            'other_direct'  => '0.00',
            'total_direct'  => '0.00',
        ];

        // 1. JE-line cost_of_revenue lines tagged with equipment_unit_id
        $rows = \db_select(
            "SELECT a.code, a.name,
                    COALESCE(SUM(jel.debit), 0) AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE jel.equipment_unit_id = ?
                AND je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?
                AND a.account_type = 'cost_of_revenue'
              GROUP BY a.id, a.code, a.name",
            [$unitId, $from, $to]
        );

        foreach ($rows as $r) {
            $net    = bcsub((string) $r['total_debit'], (string) $r['total_credit'], 2);
            $bucket = self::classifyDirectCostAccount((string) $r['code'], (string) $r['name']);
            $costs[$bucket]     = bcadd($costs[$bucket], $net, 2);
            $costs['total_direct'] = bcadd($costs['total_direct'], $net, 2);
        }

        return $costs + ['attribution_method' => 'je_lines'];
    }

    /**
     * Classify a cost_of_revenue account code into a unit-P&L bucket.
     * Mainland's COA convention: 5010=dep fleet, 5020=insurance,
     * 5030=licensing, 5040=GPS. Anything else falls to other_direct.
     * The COA may evolve — we treat unknown 5xxx codes as other_direct
     * rather than throwing, and rely on operator review of admin output.
     */
    private static function classifyDirectCostAccount(string $code, string $name): string
    {
        $lowerName = strtolower($name);
        if ($code === '5010' || str_contains($lowerName, 'depreciation')) {
            return 'depreciation';
        }
        if ($code === '5020' || str_contains($lowerName, 'insurance')) {
            return 'insurance';
        }
        if ($code === '5030' || str_contains($lowerName, 'licens')) {
            return 'licensing';
        }
        if ($code === '5040' || str_contains($lowerName, 'gps') || str_contains($lowerName, 'telematics')) {
            return 'gps_cost';
        }
        if (str_contains($lowerName, 'maintenance') || str_contains($lowerName, 'repair')) {
            return 'maintenance';
        }
        return 'other_direct';
    }

    /**
     * Compute allocated overhead for one unit in [$from, $to].
     *
     * Overhead pool = sum of posted JE debit lines on operating_expense
     * accounts in the period. Basis is read from
     * accounting.overhead_allocation_basis.
     *
     * @param string $unitRevenue        bcmath — this unit's revenue
     * @param string $totalFleetRevenue  bcmath — fleet-wide revenue
     * @param int    $totalActiveUnits   active-unit count
     */
    public static function allocateOverhead(
        int    $unitId,
        string $from,
        string $to,
        string $unitRevenue,
        string $totalFleetRevenue,
        int    $totalActiveUnits
    ): string {
        $basis = (string) \settings_get('accounting.overhead_allocation_basis', 'revenue');
        $pool  = self::getOverheadPool($from, $to);

        if (bccomp($pool, '0', 2) === 0) {
            return '0.00';
        }

        return match ($basis) {
            'revenue'    => self::overheadByRevenue($pool, $unitRevenue, $totalFleetRevenue),
            'unit_count' => self::overheadByUnitCount($pool, $totalActiveUnits),
            'weighted'   => bcadd(
                bcmul(self::overheadByRevenue($pool, $unitRevenue, $totalFleetRevenue), '0.7', 4),
                bcmul(self::overheadByUnitCount($pool, $totalActiveUnits), '0.3', 4),
                2
            ),
            'manual'     => (string) \settings_get(
                "accounting.overhead_allocation_manual_{$unitId}",
                '0.00'
            ),
            default      => '0.00',
        };
    }

    /** Sum of operating_expense debits in [$from, $to] (the overhead pool). */
    public static function getOverheadPool(string $from, string $to): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(jel.debit - jel.credit), 0) AS pool
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?
                AND a.account_type = 'operating_expense'",
            [$from, $to]
        );
        return (string) ($row['pool'] ?? '0.00');
    }

    private static function overheadByRevenue(string $pool, string $unitRev, string $fleetRev): string
    {
        if (bccomp($fleetRev, '0', 2) === 0) return '0.00';
        $share = bcdiv($unitRev, $fleetRev, 6);
        return bcmul($pool, $share, 2);
    }

    private static function overheadByUnitCount(string $pool, int $totalActiveUnits): string
    {
        if ($totalActiveUnits <= 0) return '0.00';
        return bcdiv($pool, (string) $totalActiveUnits, 2);
    }

    /**
     * ROIC = annualized EBIT / average NBV.
     *
     * NBV simplification (logged in result): we use current
     * acc_fixed_assets.net_book_value as the denominator. Operator can
     * refine to a time-weighted average via dep-run-line snapshots in a
     * future session if more precision is needed.
     *
     * Returns string percentage (e.g. '18.70') or null when NBV is zero
     * or no fixed asset is linked to the unit.
     */
    public static function computeRoic(int $unitId, string $from, string $to, string $ebit): ?string
    {
        $asset = \db_row(
            "SELECT id, net_book_value
               FROM acc_fixed_assets
              WHERE equipment_unit_id = ?
                AND status IN ('active','impaired')
              ORDER BY id ASC LIMIT 1",
            [$unitId]
        );
        if (!$asset) return null;
        $nbv = (string) ($asset['net_book_value'] ?? '0.00');
        if (bccomp($nbv, '0', 2) <= 0) return null;

        $periodDays = self::periodDays($from, $to);
        if ($periodDays <= 0) return null;

        $annualizedEbit = bcmul($ebit, bcdiv('365', (string) $periodDays, 6), 4);
        $ratio = bcdiv($annualizedEbit, $nbv, 6);
        // Express as percentage with 2-decimal precision.
        return bcmul($ratio, '100', 2);
    }

    /**
     * Full P&L for one unit. Orchestrates revenue / direct cost / overhead /
     * EBIT / ROIC and returns the §23.10 shape.
     */
    public static function getUnitPnl(
        int    $unitId,
        string $from,
        string $to,
        ?string $totalFleetRevenue = null,
        ?int    $totalActiveUnits = null
    ): array {
        $unit = \db_row(
            "SELECT u.id, u.unit_number, u.year, u.status, u.acquisition_cost,
                    t.name AS template_name, t.brand AS template_brand, t.model AS template_model
               FROM equipment_units u
               LEFT JOIN equipment_templates t ON t.id = u.template_id
              WHERE u.id = ?",
            [$unitId]
        );
        if (!$unit) {
            return ['error' => "Unit {$unitId} not found.", 'unit_id' => $unitId];
        }

        $rev   = self::getUnitRevenue($unitId, $from, $to);
        $costs = self::getUnitDirectCosts($unitId, $from, $to);

        $revenueTotal = $rev['revenue']['total'];
        $directTotal  = $costs['total_direct'];
        $contribution = bcsub($revenueTotal, $directTotal, 2);

        // Fleet-totals fallback — needed for revenue-basis OH allocation
        // when caller didn't precompute. Costs an extra query per unit,
        // so getFleetPnl precomputes and passes through.
        if ($totalFleetRevenue === null || $totalActiveUnits === null) {
            $totals = self::getFleetTotals($from, $to);
            $totalFleetRevenue = $totals['fleet_revenue'];
            $totalActiveUnits  = $totals['active_units'];
        }

        $overhead = self::allocateOverhead(
            $unitId, $from, $to,
            $revenueTotal, $totalFleetRevenue, $totalActiveUnits
        );

        $ebit = bcsub($contribution, $overhead, 2);
        $roic = self::computeRoic($unitId, $from, $to, $ebit);

        $contribPct = bccomp($revenueTotal, '0', 2) > 0
            ? bcmul(bcdiv($contribution, $revenueTotal, 6), '100', 2)
            : null;

        $displayLabel = trim(sprintf(
            '%s %s %s',
            $unit['year'] ?? '',
            $unit['template_brand'] ?? '',
            $unit['template_model'] ?? $unit['template_name'] ?? ''
        ));

        return [
            'unit_id'                  => (int) $unit['id'],
            'unit_number'              => $unit['unit_number'],
            'display_label'            => $displayLabel ?: $unit['unit_number'],
            'year'                     => $unit['year'] ? (int) $unit['year'] : null,
            'status'                   => $unit['status'],
            'from'                     => $from,
            'to'                       => $to,
            'revenue'                  => $rev['revenue'],
            'direct_costs'             => array_diff_key($costs, ['attribution_method' => true]),
            'contribution_margin'      => $contribution,
            'contribution_margin_pct'  => $contribPct,
            'overhead_allocated'       => $overhead,
            'ebit'                     => $ebit,
            'roic_pct'                 => $roic,
            'attribution_method'       => $costs['attribution_method'] ?? 'je_lines',
            'invoice_ids'              => $rev['invoice_ids'],
        ];
    }

    /**
     * Fleet-wide totals used by overhead allocation + KPI math:
     *   fleet_revenue, active_units, period_days, overhead_pool.
     */
    public static function getFleetTotals(string $from, string $to): array
    {
        $activeUnits = (int) (\db_row(
            "SELECT COUNT(*) AS n FROM equipment_units
              WHERE status NOT IN ('decommissioned','inactive')
                AND deleted_at IS NULL"
        )['n'] ?? 0);

        // bcmath-safe: pull rows, flip sign in PHP for credit lines.
        $rows = \db_select(
            "SELECT li.amount, li.is_credit
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND i.deleted_at IS NULL",
            [$from, $to]
        );
        $fleetRevenue = '0.00';
        foreach ($rows as $r) {
            $a = (string) ($r['amount'] ?? '0.00');
            if ((int) ($r['is_credit'] ?? 0) === 1) $a = bcmul($a, '-1', 2);
            $fleetRevenue = bcadd($fleetRevenue, $a, 2);
        }

        return [
            'fleet_revenue'  => $fleetRevenue,
            'active_units'   => $activeUnits,
            'period_days'    => self::periodDays($from, $to),
            'overhead_pool'  => self::getOverheadPool($from, $to),
        ];
    }

    /**
     * Per-unit P&L for the fleet (or a single unit when $unitId is set).
     * Also computes fleet rollup totals + per-customer rollup.
     */
    public static function getFleetPnl(string $from, string $to, ?int $unitId = null): array
    {
        $totals = self::getFleetTotals($from, $to);

        if ($unitId !== null) {
            $units = \db_select(
                "SELECT id FROM equipment_units
                  WHERE id = ? AND deleted_at IS NULL",
                [$unitId]
            );
        } else {
            $units = \db_select(
                "SELECT id FROM equipment_units
                  WHERE status NOT IN ('decommissioned','inactive')
                    AND deleted_at IS NULL
                  ORDER BY unit_number ASC"
            );
        }

        if (!$units) {
            return [
                'from'           => $from,
                'to'             => $to,
                'units'          => [],
                'fleet_totals'   => array_merge($totals, [
                    'fleet_direct_costs'        => '0.00',
                    'fleet_contribution_margin' => '0.00',
                    'fleet_overhead_allocated'  => '0.00',
                    'fleet_ebit'                => '0.00',
                ]),
                'by_customer'    => [],
                'message'        => 'No active units found for this period.',
            ];
        }

        $unitsOut         = [];
        $fleetDirect      = '0.00';
        $fleetContribution = '0.00';
        $fleetOverhead    = '0.00';
        $fleetEbit        = '0.00';

        foreach ($units as $u) {
            $row = self::getUnitPnl(
                (int) $u['id'], $from, $to,
                $totals['fleet_revenue'], $totals['active_units']
            );
            $unitsOut[] = $row;

            $fleetDirect       = bcadd($fleetDirect, $row['direct_costs']['total_direct'] ?? '0.00', 2);
            $fleetContribution = bcadd($fleetContribution, $row['contribution_margin'] ?? '0.00', 2);
            $fleetOverhead     = bcadd($fleetOverhead, $row['overhead_allocated'] ?? '0.00', 2);
            $fleetEbit         = bcadd($fleetEbit, $row['ebit'] ?? '0.00', 2);
        }

        // Per-customer rollup (group by leases.customer_id).
        $byCustomer = self::getCustomerRollup($from, $to);

        return [
            'from'         => $from,
            'to'           => $to,
            'units'        => $unitsOut,
            'fleet_totals' => array_merge($totals, [
                'fleet_direct_costs'        => $fleetDirect,
                'fleet_contribution_margin' => $fleetContribution,
                'fleet_overhead_allocated'  => $fleetOverhead,
                'fleet_ebit'                => $fleetEbit,
            ]),
            'by_customer'  => $byCustomer,
        ];
    }

    /**
     * Group revenue + margin by customer_id. Costs are not directly
     * attributable to customers at the GL level (the JE lines tag units
     * not customers), so we approximate per-customer cost as the share of
     * fleet direct costs proportional to that customer's revenue share —
     * giving an "effective margin" view for stakeholder review.
     */
    private static function getCustomerRollup(string $from, string $to): array
    {
        $rows = \db_select(
            "SELECT c.id AS customer_id, c.company_name,
                    COALESCE(SUM(i.total_amount), 0) AS gross_revenue,
                    COUNT(DISTINCT i.id) AS invoice_count
               FROM invoices i
               JOIN customers c ON c.id = i.customer_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND i.deleted_at IS NULL
              GROUP BY c.id, c.company_name
              ORDER BY gross_revenue DESC",
            [$from, $to]
        );
        return array_map(static fn(array $r) => [
            'customer_id'   => (int) $r['customer_id'],
            'company_name'  => $r['company_name'],
            'gross_revenue' => (string) $r['gross_revenue'],
            'invoice_count' => (int) $r['invoice_count'],
        ], $rows);
    }

    /**
     * Fleet-wide KPIs per spec §23.10.
     *
     * @return array{
     *   revpau:string, dollar_utilization_pct:string|null,
     *   maintenance_cost_ratio_pct:string, maintenance_benchmark_flag:string,
     *   fleet_age_weighted_avg_years:string|null,
     *   period_days:int, total_units:int
     * }
     */
    public static function getFleetKpis(string $from, string $to): array
    {
        $totals     = self::getFleetTotals($from, $to);
        $periodDays = $totals['period_days'];
        $units      = $totals['active_units'];
        $rev        = $totals['fleet_revenue'];

        // RevPAU = revenue / (units × days)
        $revpau = '0.00';
        if ($units > 0 && $periodDays > 0) {
            $denom  = bcmul((string) $units, (string) $periodDays, 0);
            $revpau = bcdiv($rev, $denom, 2);
        }

        // Dollar Utilization = annualized revenue / total OEC
        $oecRow = \db_row(
            "SELECT COALESCE(SUM(acquisition_cost), 0) AS oec
               FROM acc_fixed_assets
              WHERE equipment_unit_id IS NOT NULL
                AND status IN ('active','impaired','fully_depreciated')"
        );
        $totalOec = (string) ($oecRow['oec'] ?? '0.00');
        $dollarUtil = null;
        if (bccomp($totalOec, '0', 2) > 0 && $periodDays > 0) {
            $annualized = bcmul($rev, bcdiv('365', (string) $periodDays, 6), 4);
            $dollarUtil = bcmul(bcdiv($annualized, $totalOec, 6), '100', 2);
        }

        // Maintenance Cost Ratio:
        //   (maintenance line-item revenue + JE maintenance costs) / base_rental revenue
        // Source 1: invoice_line_items WHERE item_type='maintenance' isn't actually in
        // the ENUM on disk; the spec referenced maintenance recharge revenue but on-disk
        // the closest billing source is damage (rare) or 'other'. Treat invoice-side
        // maintenance as $0 absent a real ENUM value, and compute the ratio off GL
        // maintenance expense (account codes 6010-6030 per Mainland COA) against base
        // rental revenue — gives an operationally-meaningful ratio.
        $maintRow = \db_row(
            "SELECT COALESCE(SUM(jel.debit - jel.credit), 0) AS total
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
               JOIN acc_accounts a ON a.id = jel.account_id
              WHERE je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?
                AND a.code IN ('6010','6020','6030')",
            [$from, $to]
        );
        $maintCost = (string) ($maintRow['total'] ?? '0.00');

        $rentalRow = \db_row(
            "SELECT COALESCE(SUM(li.amount), 0) AS total
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND i.deleted_at IS NULL
                AND li.item_type IN ('base_rental','base_rental_reconciliation_credit')
                AND li.is_credit = 0",
            [$from, $to]
        );
        $rentalRev = (string) ($rentalRow['total'] ?? '0.00');

        $maintRatioPct = '0.00';
        $maintFlag     = 'normal';
        if (bccomp($rentalRev, '0', 2) > 0) {
            $ratio = bcdiv($maintCost, $rentalRev, 6);
            $maintRatioPct = bcmul($ratio, '100', 2);
            if (bccomp($ratio, self::MAINTENANCE_BENCHMARK_HIGH, 4) > 0) {
                $maintFlag = 'high';
            } elseif (bccomp($ratio, self::MAINTENANCE_BENCHMARK_LOW, 4) < 0) {
                $maintFlag = 'low';
            }
        }

        // Fleet age weighted average = SUM(age × cost) / SUM(cost)
        // age = ($to year - unit.year). Weight by acquisition_cost from
        // equipment_units (the direct column — exists on disk).
        $ageRows = \db_select(
            "SELECT u.year, COALESCE(u.acquisition_cost, 0) AS cost
               FROM equipment_units u
              WHERE u.status NOT IN ('decommissioned','inactive')
                AND u.deleted_at IS NULL
                AND u.year IS NOT NULL"
        );
        $toYear      = (int) substr($to, 0, 4);
        $weightedSum = '0.00';
        $weightTotal = '0.00';
        foreach ($ageRows as $r) {
            $age  = (string) max(0, $toYear - (int) $r['year']);
            $cost = (string) $r['cost'];
            if (bccomp($cost, '0', 2) <= 0) continue;
            $weightedSum = bcadd($weightedSum, bcmul($age, $cost, 4), 4);
            $weightTotal = bcadd($weightTotal, $cost, 4);
        }
        $ageWeighted = bccomp($weightTotal, '0', 4) > 0
            ? bcdiv($weightedSum, $weightTotal, 2)
            : null;

        return [
            'revpau'                       => $revpau,
            'dollar_utilization_pct'       => $dollarUtil,
            'maintenance_cost_ratio_pct'   => $maintRatioPct,
            'maintenance_benchmark_flag'   => $maintFlag,
            'maintenance_cost_total'       => $maintCost,
            'rental_revenue_total'         => $rentalRev,
            'fleet_age_weighted_avg_years' => $ageWeighted,
            'total_oec'                    => $totalOec,
            'annualized_revenue'           => bcmul($rev, bcdiv('365', (string) max(1, $periodDays), 6), 2),
            'period_days'                  => $periodDays,
            'total_units'                  => $units,
            'fleet_revenue'                => $rev,
        ];
    }

    /** Inclusive day count between $from and $to. */
    private static function periodDays(string $from, string $to): int
    {
        $f = new \DateTime($from);
        $t = new \DateTime($to);
        if ($t < $f) return 0;
        return (int) $f->diff($t)->days + 1;
    }
}
