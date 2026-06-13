<?php
declare(strict_types=1);
/**
 * api/v1/reports/fleet.php
 *
 * Fleet Reports API — utilization, ROI, idle units, maintenance breakdown, yard summary.
 * Utilization is computed by overlapping each lease's date range with the report period
 * (clamped to [date_from, date_to]).  Revenue and maintenance cost are similarly scoped
 * to invoices/work-orders that fall within the period.
 *
 * Required by: app/admin/reports/index.php (Fleet tab)
 * Requires: api/bootstrap.php, lib/Reports/ReportBuilder.php (autoloaded)
 *
 * GET params:
 *   preset      : date preset slug (default 'this_month')
 *   date_from   : Y-m-d (required when preset = 'custom')
 *   date_to     : Y-m-d (required when preset = 'custom')
 *   view        : utilization | roi | idle | maintenance | yard
 *   format      : json | csv
 *   yard        : filter by yard_location (optional)
 *   category    : filter by equipment_templates.category (optional)
 *
 * Spec ref: §7.10 Reports, Reports Charts #5–#7, PROGRESS.md S021
 * Decisions: D14 (inclusive day count), D16 (bcmath), D5 (soft-delete both sides)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Reports\ReportBuilder;

require_method('GET');
require_auth_api();
require_permission('reports', 'view');

// ── Date range ───────────────────────────────────────────────────────────────
$preset     = clean_string($_GET['preset'] ?? 'this_month') ?? 'this_month';
$customFrom = clean_date($_GET['date_from'] ?? null);
$customTo   = clean_date($_GET['date_to']   ?? null);

[$dateFrom, $dateTo] = ReportBuilder::resolvePreset($preset, $customFrom, $customTo);
[$dateFrom, $dateTo] = ReportBuilder::clampDates($dateFrom, $dateTo);

// ── Input validation ─────────────────────────────────────────────────────────
$allowedViews = ['utilization', 'roi', 'idle', 'maintenance', 'yard'];
$view   = clean_string($_GET['view']   ?? 'utilization') ?? 'utilization';
$format = clean_string($_GET['format'] ?? 'json')        ?? 'json';
$yardFilter     = clean_string($_GET['yard']     ?? null);
$categoryFilter = clean_string($_GET['category'] ?? null);

if (!in_array($view, $allowedViews, true))        $view   = 'utilization';
if (!in_array($format, ['json', 'csv'], true))    $format = 'json';

// ── Cache check ──────────────────────────────────────────────────────────────
$cacheParams = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'view' => $view, 'yard' => $yardFilter, 'cat' => $categoryFilter];
$cacheType   = 'fleet_' . $view;

if ($format === 'json') {
    $cached = ReportBuilder::getCached($cacheType, $cacheParams);
    if ($cached) { $cached['cached'] = true; json_success($cached); }
}

// ── Core data: all non-decommissioned units with optional filters ─────────────
// Columns: unit metadata only.  Utilization/revenue/cost joined below.
$unitWhere  = ['eu.deleted_at IS NULL', "eu.status != 'decommissioned'"];
$unitParams = [];

if ($yardFilter) {
    $unitWhere[]  = 'eu.yard_location = ?';
    $unitParams[] = $yardFilter;
}
if ($categoryFilter) {
    $unitWhere[]  = 'et.category = ?';
    $unitParams[] = $categoryFilter;
}

$unitWhereSQL = implode(' AND ', $unitWhere);

$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.yard_location, eu.status,
            et.brand, et.model, et.category AS equipment_type
     FROM equipment_units eu
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE $unitWhereSQL
     ORDER BY eu.unit_number ASC",
    $unitParams
);

// ── Parallel queries for the per-unit metrics ────────────────────────────────

// 1. Days on lease within the period.
// GREATEST(0,...) prevents negative overlap when lease falls partly outside range.
// COALESCE(actual_return_date, ?) treats open active leases as still out until period end.
$daysRows = db_select(
    "SELECT
        l.equipment_unit_id,
        SUM(GREATEST(0,
            DATEDIFF(
                LEAST(COALESCE(l.actual_return_date, ?), ?),
                GREATEST(l.start_date, ?)
            ) + 1
        )) AS days_on_lease
     FROM leases l
     WHERE l.deleted_at IS NULL
       AND l.status IN ('active','completed')
       AND l.start_date <= ?
       AND COALESCE(l.actual_return_date, CURDATE()) >= ?
     GROUP BY l.equipment_unit_id",
    [$dateTo, $dateTo, $dateFrom, $dateTo, $dateFrom]
);

// 2. Revenue per unit — invoices billed against leases for this unit, within the period.
$revRows = db_select(
    "SELECT l.equipment_unit_id,
            SUM(i.total_amount)    AS revenue,
            COUNT(DISTINCT i.id)  AS invoice_count,
            COUNT(DISTINCT i.lease_id) AS lease_count
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
     WHERE i.deleted_at IS NULL
       AND i.status NOT IN ('draft','void','written_off')
       AND i.invoice_date BETWEEN ? AND ?
     GROUP BY l.equipment_unit_id",
    [$dateFrom, $dateTo]
);

// 3. Maintenance cost per unit — completed work orders in the period.
$maintRows = db_select(
    "SELECT wo.equipment_unit_id,
            SUM(wo.total_cost)   AS maintenance_cost,
            SUM(wo.labor_cost)   AS labor_cost,
            SUM(wo.parts_cost)   AS parts_cost,
            COUNT(*)             AS work_order_count
     FROM maintenance_work_orders wo
     WHERE wo.deleted_at IS NULL
       AND wo.status = 'completed'
       AND wo.completed_date BETWEEN ? AND ?
     GROUP BY wo.equipment_unit_id",
    [$dateFrom, $dateTo]
);

// ── Build lookup maps (unit_id as key) ───────────────────────────────────────
$daysMap  = array_column($daysRows,  'days_on_lease',     'equipment_unit_id');
$revMap   = [];
foreach ($revRows  as $r) { $revMap[$r['equipment_unit_id']]  = $r; }
$maintMap = [];
foreach ($maintRows as $r) { $maintMap[$r['equipment_unit_id']] = $r; }

// Total calendar days in the period (D14: inclusive)
$totalPeriodDays = ReportBuilder::periodDays($dateFrom, $dateTo);

// ── Merge into one per-unit result set ───────────────────────────────────────
$rows = [];
foreach ($units as $unit) {
    $id          = $unit['id'];
    $daysLeased  = (int)   ($daysMap[$id] ?? 0);
    $rev         = bcround((string) ($revMap[$id]['revenue']          ?? 0), 2);
    $maint       = bcround((string) ($maintMap[$id]['maintenance_cost'] ?? 0), 2);
    $labor       = bcround((string) ($maintMap[$id]['labor_cost']      ?? 0), 2);
    $parts       = bcround((string) ($maintMap[$id]['parts_cost']      ?? 0), 2);
    $woCount     = (int) ($maintMap[$id]['work_order_count'] ?? 0);
    $invCount    = (int) ($revMap[$id]['invoice_count']  ?? 0);
    $leaseCount  = (int) ($revMap[$id]['lease_count']    ?? 0);

    // Utilization %: days on lease / total period days × 100 (D16 bcmath)
    $utilPct = $totalPeriodDays > 0
        ? ReportBuilder::pct((string) $daysLeased, (string) $totalPeriodDays, 1)
        : '0.0';

    // ROI: revenue generated minus maintenance cost for this unit in the period
    $roi = bcsub($rev, $maint, 2);

    $rows[] = array_merge($unit, [
        'total_period_days'  => $totalPeriodDays,
        'days_on_lease'      => $daysLeased,
        'utilization_pct'    => $utilPct,
        'revenue'            => $rev,
        'invoice_count'      => $invCount,
        'lease_count'        => $leaseCount,
        'maintenance_cost'   => $maint,
        'labor_cost'         => $labor,
        'parts_cost'         => $parts,
        'work_order_count'   => $woCount,
        'roi'                => $roi,
        'is_idle'            => $daysLeased === 0,
    ]);
}

// ── Shared KPIs (fleet-wide totals) ─────────────────────────────────────────
$allUtilPcts   = array_map(fn($r) => (float) $r['utilization_pct'], $rows);
$totalUnits    = count($rows);
$idleUnits     = count(array_filter($rows, fn($r) => $r['is_idle']));
$totalRevenue  = bcround((string) array_sum(array_map(fn($r) => (float) $r['revenue'],          $rows)), 2);
$totalMaint    = bcround((string) array_sum(array_map(fn($r) => (float) $r['maintenance_cost'],  $rows)), 2);
$totalRoi      = bcsub($totalRevenue, $totalMaint, 2);
$avgUtil       = $totalUnits > 0
    ? bcround((string) (array_sum($allUtilPcts) / $totalUnits), 1)
    : '0.0';

$kpis = [
    'total_units'      => $totalUnits,
    'idle_units'       => $idleUnits,
    'active_units'     => $totalUnits - $idleUnits,
    'avg_utilization'  => $avgUtil,
    'total_revenue'    => $totalRevenue,
    'total_maint_cost' => $totalMaint,
    'total_roi'        => $totalRoi,
    'period_days'      => $totalPeriodDays,
];

// ── View-specific chart/table shaping ────────────────────────────────────────
$chartData = [];
$table     = [];
$totals    = [];

switch ($view) {

    // ── Utilization ranking ───────────────────────────────────────────────────
    case 'utilization':
        usort($rows, fn($a, $b) => (float) $b['utilization_pct'] <=> (float) $a['utilization_pct']);
        $top20 = array_slice($rows, 0, 20);

        $chartData = [
            'categories'      => array_map(fn($r) => $r['unit_number'],     $top20),
            'utilization_pct' => array_map(fn($r) => (float) $r['utilization_pct'], $top20),
            'days_on_lease'   => array_map(fn($r) => (int)   $r['days_on_lease'],   $top20),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'fleet_utilization_' . $dateFrom . '_to_' . $dateTo,
                ['Unit #', 'Type', 'Yard', 'Status', 'Days On Lease', 'Period Days', 'Utilization %', 'Revenue', 'Maintenance Cost', 'ROI', 'Leases', 'Invoices'],
                array_map(fn($r) => [
                    $r['unit_number'], $r['equipment_type'], $r['yard_location'] ?? '', $r['status'],
                    $r['days_on_lease'], $r['total_period_days'], $r['utilization_pct'],
                    ReportBuilder::csvMoney($r['revenue']),
                    ReportBuilder::csvMoney($r['maintenance_cost']),
                    ReportBuilder::csvMoney($r['roi']),
                    $r['lease_count'], $r['invoice_count'],
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [];
        break;

    // ── ROI ranking (revenue – maintenance cost) ──────────────────────────────
    case 'roi':
        usort($rows, fn($a, $b) => (float) $b['roi'] <=> (float) $a['roi']);
        $top20 = array_slice($rows, 0, 20);

        $chartData = [
            'categories' => array_map(fn($r) => $r['unit_number'],           $top20),
            'revenue'    => array_map(fn($r) => (float) $r['revenue'],        $top20),
            'maint_cost' => array_map(fn($r) => (float) $r['maintenance_cost'], $top20),
            'roi'        => array_map(fn($r) => (float) $r['roi'],            $top20),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'fleet_roi_' . $dateFrom . '_to_' . $dateTo,
                ['Unit #', 'Type', 'Yard', 'Revenue', 'Labor Cost', 'Parts Cost', 'Maintenance Cost', 'ROI', 'Work Orders'],
                array_map(fn($r) => [
                    $r['unit_number'], $r['equipment_type'], $r['yard_location'] ?? '',
                    ReportBuilder::csvMoney($r['revenue']),
                    ReportBuilder::csvMoney($r['labor_cost']),
                    ReportBuilder::csvMoney($r['parts_cost']),
                    ReportBuilder::csvMoney($r['maintenance_cost']),
                    ReportBuilder::csvMoney($r['roi']),
                    $r['work_order_count'],
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = ['total_roi' => $totalRoi, 'total_revenue' => $totalRevenue, 'total_maint' => $totalMaint];
        break;

    // ── Idle units (0 days on lease in period) ────────────────────────────────
    case 'idle':
        $idleRows = array_values(array_filter($rows, fn($r) => $r['is_idle']));
        usort($idleRows, fn($a, $b) => strcmp($a['unit_number'], $b['unit_number']));

        // Group idle units by equipment type for the donut chart
        $byType = [];
        foreach ($idleRows as $r) {
            $t = $r['equipment_type'] ?: 'Unknown';
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }

        $chartData = [
            'labels' => array_keys($byType),
            'counts' => array_values($byType),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'fleet_idle_units_' . $dateFrom . '_to_' . $dateTo,
                ['Unit #', 'Type', 'Yard', 'Status', 'Maintenance Cost (period)', 'Work Orders'],
                array_map(fn($r) => [
                    $r['unit_number'], $r['equipment_type'], $r['yard_location'] ?? '', $r['status'],
                    ReportBuilder::csvMoney($r['maintenance_cost']),
                    $r['work_order_count'],
                ], $idleRows)
            );
        }
        $table  = $idleRows;
        $totals = ['idle_count' => count($idleRows), 'idle_maint_cost' => bcround((string) array_sum(array_map(fn($r) => (float) $r['maintenance_cost'], $idleRows)), 2)];
        break;

    // ── Maintenance cost breakdown by work type ───────────────────────────────
    case 'maintenance':
        $maintTypeRows = db_select(
            "SELECT
                wo.work_type,
                COUNT(*)                          AS work_order_count,
                COUNT(DISTINCT wo.equipment_unit_id) AS units_affected,
                COALESCE(SUM(wo.total_cost),  0) AS total_cost,
                COALESCE(SUM(wo.labor_cost),  0) AS labor_cost,
                COALESCE(SUM(wo.parts_cost),  0) AS parts_cost,
                COALESCE(AVG(wo.total_cost),  0) AS avg_cost
             FROM maintenance_work_orders wo
             WHERE wo.deleted_at IS NULL
               AND wo.status = 'completed'
               AND wo.completed_date BETWEEN ? AND ?
             GROUP BY wo.work_type
             ORDER BY total_cost DESC",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'labels'     => array_column($maintTypeRows, 'work_type'),
            'total_cost' => array_map(fn($r) => (float) $r['total_cost'], $maintTypeRows),
            'counts'     => array_map(fn($r) => (int)   $r['work_order_count'], $maintTypeRows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'maintenance_by_type_' . $dateFrom . '_to_' . $dateTo,
                ['Work Type', 'Work Orders', 'Units Affected', 'Labor Cost', 'Parts Cost', 'Total Cost', 'Avg Cost'],
                array_map(fn($r) => [
                    $r['work_type'], $r['work_order_count'], $r['units_affected'],
                    ReportBuilder::csvMoney($r['labor_cost']),
                    ReportBuilder::csvMoney($r['parts_cost']),
                    ReportBuilder::csvMoney($r['total_cost']),
                    ReportBuilder::csvMoney($r['avg_cost']),
                ], $maintTypeRows)
            );
        }
        $table  = $maintTypeRows;
        $totals = ['total_cost' => bcround((string) array_sum(array_column($maintTypeRows, 'total_cost')), 2)];
        break;

    // ── Revenue and utilization by yard ───────────────────────────────────────
    case 'yard':
        $yardMap = [];
        foreach ($rows as $r) {
            $yard = $r['yard_location'] ?: 'Unknown Yard';
            if (!isset($yardMap[$yard])) {
                $yardMap[$yard] = [
                    'yard_location'  => $yard,
                    'unit_count'     => 0,
                    'idle_count'     => 0,
                    'revenue'        => '0',
                    'maintenance'    => '0',
                    'days_on_lease'  => 0,
                ];
            }
            $yardMap[$yard]['unit_count']++;
            if ($r['is_idle']) $yardMap[$yard]['idle_count']++;
            $yardMap[$yard]['revenue']       = bcadd($yardMap[$yard]['revenue'],      $r['revenue'],           2);
            $yardMap[$yard]['maintenance']   = bcadd($yardMap[$yard]['maintenance'],  $r['maintenance_cost'],  2);
            $yardMap[$yard]['days_on_lease'] += $r['days_on_lease'];
        }

        // Compute avg utilization per yard (total lease-days / possible days)
        $yardRows = [];
        foreach ($yardMap as $y) {
            $possibleDays = $y['unit_count'] * $totalPeriodDays;
            $y['avg_utilization'] = ReportBuilder::pct((string) $y['days_on_lease'], (string) $possibleDays, 1);
            $y['roi']             = bcsub($y['revenue'], $y['maintenance'], 2);
            $yardRows[]           = $y;
        }
        usort($yardRows, fn($a, $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        $chartData = [
            'categories'      => array_column($yardRows, 'yard_location'),
            'revenue'         => array_map(fn($r) => (float) $r['revenue'],          $yardRows),
            'maintenance'     => array_map(fn($r) => (float) $r['maintenance'],      $yardRows),
            'avg_utilization' => array_map(fn($r) => (float) $r['avg_utilization'],  $yardRows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'fleet_by_yard_' . $dateFrom . '_to_' . $dateTo,
                ['Yard', 'Units', 'Idle Units', 'Revenue', 'Maintenance Cost', 'ROI', 'Avg Utilization %'],
                array_map(fn($r) => [
                    $r['yard_location'], $r['unit_count'], $r['idle_count'],
                    ReportBuilder::csvMoney($r['revenue']),
                    ReportBuilder::csvMoney($r['maintenance']),
                    ReportBuilder::csvMoney($r['roi']),
                    $r['avg_utilization'],
                ], $yardRows)
            );
        }
        $table  = $yardRows;
        $totals = [];
        break;
}

$result = [
    'kpis'         => $kpis,
    'chart_data'   => $chartData,
    'table'        => $table,
    'totals'       => $totals,
    'date_from'    => $dateFrom,
    'date_to'      => $dateTo,
    'preset'       => $preset,
    'view'         => $view,
    'generated_at' => date('c'),
    'cached'       => false,
];

ReportBuilder::setCached($cacheType, $cacheParams, $result);
json_success($result);
