<?php
declare(strict_types=1);
/**
 * api/v1/analytics/index.php
 *
 * Analytics API — 8 forward-looking pattern views for the Analytics Module.
 * Dispatches on ?view= param. No caching (fast-path queries).
 * Different from Reports: analytics is forecasting + patterns, not historical tables.
 *
 * Required by: app/admin/analytics/index.php (FF_Analytics component)
 * Requires:    api/bootstrap.php
 *
 * GET params:
 *   view : revenue_forecast | utilization_matrix | concentration_risk |
 *          seasonal_pattern | cohort_revenue | fleet_optimizer |
 *          lead_time | avg_lease_value
 *   date_from : Y-m-d  (optional — used by: revenue_forecast, lead_time,
 *                        avg_lease_value, cohort_revenue)
 *   date_to   : Y-m-d  (optional)
 *
 * Returns: { success:true, data:{ chart_data:{}, kpis:{}, view, date_from, date_to,
 *            excluded? } }  — `excluded` is present only on the six
 *            invoice-status-dependent views (see analytics_view_window).
 *
 * Spec ref: §7.11 Analytics, §9 Analytics Module Charts (8), PROGRESS.md S023
 * Decisions: D5 (soft-delete both sides), D16 (bcmath), D32 (no float math)
 * Permission: analytics / view  (dispatcher has NO analytics access per matrix)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('analytics', 'view');

// ── View dispatch ────────────────────────────────────────────────────────────
$allowedViews = [
    'revenue_forecast', 'utilization_matrix', 'concentration_risk',
    'seasonal_pattern', 'cohort_revenue', 'fleet_optimizer',
    'lead_time', 'avg_lease_value',
];
$view = clean_string($_GET['view'] ?? '') ?? '';
if (!in_array($view, $allowedViews, true)) {
    json_error('NOT_FOUND', 'Unknown analytics view. Valid: ' . implode(', ', $allowedViews), 404);
}

// ── Date range (optional — views that support it use it; others ignore) ──────
// Default: last 12 months to today
$dateFrom = clean_date($_GET['date_from'] ?? null) ?? date('Y-m-d', strtotime('-12 months'));
$dateTo   = clean_date($_GET['date_to']   ?? null) ?? date('Y-m-d');

// Clamp: never allow future date_to beyond today, never allow date_from > date_to
if ($dateTo > date('Y-m-d')) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) {
    $dateFrom = date('Y-m-d', strtotime('-12 months'));
}

// Dispatch to view handler
$result = match ($view) {
    'revenue_forecast'    => view_revenue_forecast($dateFrom, $dateTo),
    'utilization_matrix'  => view_utilization_matrix(),
    'concentration_risk'  => view_concentration_risk(),
    'seasonal_pattern'    => view_seasonal_pattern(),
    'cohort_revenue'      => view_cohort_revenue($dateFrom, $dateTo),
    'fleet_optimizer'     => view_fleet_optimizer(),
    'lead_time'           => view_lead_time($dateFrom, $dateTo),
    'avg_lease_value'     => view_avg_lease_value($dateFrom, $dateTo),
};

// Six of the eight views aggregate money and therefore filter out
// draft/void/written_off per the reporting policy. Attach a census of what was
// withheld so the UI can explain a flat chart instead of implying no activity.
// The other two (fleet_optimizer, lead_time) are lease-driven — no census.
$window = analytics_view_window($view, $dateFrom, $dateTo);

json_success(array_merge($result, [
    'view'      => $view,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
], $window === null ? [] : ['excluded' => analytics_excluded_census($window[0], $window[1])]));


// ════════════════════════════════════════════════════════════════════════════
// EXCLUDED-INVOICE CENSUS (shared by the six invoice-status-dependent views)
// ════════════════════════════════════════════════════════════════════════════

/**
 * The invoice_date window a given view actually queries.
 *
 * WHY per-view rather than one global range: the views do NOT share a window.
 * revenue_forecast/cohort_revenue/avg_lease_value honour the user's date
 * pickers, utilization_matrix/concentration_risk hard-code a trailing 12
 * months, and seasonal_pattern deliberately spans all time to build its radar.
 * A census computed over the wrong window would contradict the chart beside it.
 *
 * @return array{0:?string,1:?string}|null  [from, to]; nulls mean all-time.
 *                                          NULL = view is not invoice-driven.
 */
function analytics_view_window(string $view, string $dateFrom, string $dateTo): ?array
{
    return match ($view) {
        'revenue_forecast', 'cohort_revenue', 'avg_lease_value' => [$dateFrom, $dateTo],
        'utilization_matrix', 'concentration_risk'              => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')],
        'seasonal_pattern'                                      => [null, null],
        default                                                 => null,
    };
}

/**
 * Count the invoices a money view withheld, plus how many it COULD see.
 *
 * `billable_count` is the discriminator the UI needs: zero billable with
 * non-zero drafts means "nothing has been sent yet" (explainable), whereas zero
 * of both simply means no invoices exist in the window at all.
 *
 * @param ?string $from  Y-m-d, or null for all-time
 * @param ?string $to    Y-m-d, or null for all-time
 */
function analytics_excluded_census(?string $from, ?string $to): array
{
    $where  = 'i.deleted_at IS NULL';
    $params = [];
    if ($from !== null && $to !== null) {
        $where   .= ' AND i.invoice_date BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;
    }

    $row = db_row(
        "SELECT
            COUNT(CASE WHEN i.status = 'draft'       THEN 1 END) AS draft_count,
            COUNT(CASE WHEN i.status = 'void'        THEN 1 END) AS void_count,
            COUNT(CASE WHEN i.status = 'written_off' THEN 1 END) AS written_off_count,
            COUNT(CASE WHEN i.status NOT IN ('draft','void','written_off') THEN 1 END) AS billable_count,
            COALESCE(SUM(CASE WHEN i.status = 'draft'
                              THEN (CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END)
                              ELSE 0 END), 0)                    AS draft_total
         FROM invoices i
         WHERE {$where}",
        $params
    );

    return [
        'draft_count'       => (int) ($row['draft_count']       ?? 0),
        'void_count'        => (int) ($row['void_count']        ?? 0),
        'written_off_count' => (int) ($row['written_off_count'] ?? 0),
        'billable_count'    => (int) ($row['billable_count']    ?? 0),
        'draft_total'       => bcround((string) ($row['draft_total'] ?? '0'), 2),
        'window_from'       => $from,
        'window_to'         => $to,
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 1 — Revenue Forecast
// Historical monthly revenue + 3-month SQL linear regression projection
// ════════════════════════════════════════════════════════════════════════════

/**
 * Returns 12m historical + 3-month projected trend with ±10% confidence band.
 * Regression computed in PHP from the most recent 6 data points.
 *
 * @param string $dateFrom  Y-m-d  (effective start of historical window)
 * @param string $dateTo    Y-m-d  (end of historical window — usually today)
 * @return array  { chart_data, kpis }
 */
function view_revenue_forecast(string $dateFrom, string $dateTo): array
{
    // ── Historical monthly revenue ───────────────────────────────────────────
    $rows = db_select(
        "SELECT
            DATE_FORMAT(i.invoice_date, '%Y-%m')  AS period,
            COALESCE(SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0)       AS revenue
         FROM invoices i
         WHERE i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
           AND i.invoice_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), period
         ORDER BY period ASC",
        [$dateFrom, $dateTo]
    );

    // Build indexed array of (period => revenue) — keep order
    $historical = [];
    foreach ($rows as $r) {
        $historical[$r['period']] = (float)$r['revenue'];
    }

    // ── Fill any missing months in the window with zero ──────────────────────
    // WHY: missing months (no invoices) must appear as zero, not gap
    $months = [];
    $cursor = strtotime($dateFrom);
    $end    = strtotime($dateTo);
    while ($cursor <= $end) {
        $m = date('Y-m', $cursor);
        $months[$m] = $historical[$m] ?? 0.0;
        $cursor = strtotime('+1 month', $cursor);
    }

    // ── Linear regression on last 6 data points ─────────────────────────────
    $allValues = array_values($months);
    $n6Points  = array_slice($allValues, -6); // last 6 months for regression
    $n         = count($n6Points);

    $m_slope = 0.0;
    $b_int   = 0.0;

    if ($n >= 2) {
        // WHY: need at least 2 points for a line; avoid division by zero
        $sumX = $sumY = $sumXY = $sumX2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x     = (float)($i + 1);
            $y     = $n6Points[$i];
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        $denom   = ($n * $sumX2) - ($sumX * $sumX);
        if ($denom != 0.0) {
            $m_slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
            $b_int   = ($sumY - $m_slope * $sumX) / $n;
        }
    }

    // ── Generate 3 projected months ──────────────────────────────────────────
    $projCategories = [];
    $projValues     = [];
    $upperBand      = [];
    $lowerBand      = [];

    $lastMonth = array_key_last($months);
    for ($i = 1; $i <= 3; $i++) {
        // Projected x = n + i (continuing regression from last 6 points)
        $x         = (float)($n + $i);
        $yProj     = max(0.0, $m_slope * $x + $b_int);
        $upper     = $yProj * 1.10;   // +10% confidence band
        $lower     = max(0.0, $yProj * 0.90);  // -10% confidence band

        $projDate         = strtotime("+{$i} month", strtotime($lastMonth . '-01'));
        $projCategories[] = date('Y-m', $projDate);
        $projValues[]     = round($yProj, 2);
        $upperBand[]      = round($upper, 2);
        $lowerBand[]      = round($lower, 2);
    }

    // ── KPIs ─────────────────────────────────────────────────────────────────
    $allRevValues  = array_values($months);
    $total12m      = array_sum($allRevValues);
    $avgMonthly    = $total12m > 0 ? $total12m / max(count($allRevValues), 1) : 0;
    $peakValue     = max(array_merge($allRevValues, [0]));
    $peakPeriod    = '';
    foreach ($months as $mo => $rev) {
        if ($rev === $peakValue) {
            $peakPeriod = $mo;
            break;
        }
    }
    $projected3m = array_sum($projValues);

    return [
        'chart_data' => [
            'categories'      => array_keys($months),
            'historical'      => array_values(array_map(fn($v) => round($v, 2), $months)),
            'proj_categories' => $projCategories,
            'projected'       => $projValues,
            'upper_band'      => $upperBand,
            'lower_band'      => $lowerBand,
        ],
        'kpis' => [
            'total_12m'    => number_format($total12m, 2, '.', ''),
            'avg_monthly'  => number_format($avgMonthly, 2, '.', ''),
            'peak_month'   => $peakPeriod,
            'peak_value'   => number_format($peakValue, 2, '.', ''),
            'projected_3m' => number_format($projected3m, 2, '.', ''),
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 2 — Utilization Efficiency Matrix
// Scatter: each unit as (utilization_pct, revenue_per_day); series = category
// ════════════════════════════════════════════════════════════════════════════

/**
 * @return array { chart_data: { series }, kpis }
 */
function view_utilization_matrix(): array
{
    // Fixed 12-month window regardless of date params
    $from12m   = date('Y-m-d', strtotime('-12 months'));
    $today     = date('Y-m-d');
    $periodDays = 365;

    // Per-unit: days on lease (clamped to 12m window) + total revenue in same window
    // WHY: GREATEST/LEAST clamps overlapping lease periods to the 12m window
    $units = db_select(
        "SELECT
            eu.id,
            eu.unit_number,
            et.category,
            COALESCE(SUM(
                GREATEST(0,
                    DATEDIFF(
                        LEAST(COALESCE(l.actual_return_date, l.end_date, ?), ?),
                        GREATEST(l.start_date, ?)
                    ) + 1
                )
            ), 0) AS days_on_lease,
            COALESCE(SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0) AS total_revenue
         FROM equipment_units eu
         JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
         LEFT JOIN leases l
            ON l.equipment_unit_id = eu.id
           AND l.deleted_at IS NULL
           AND l.status IN ('active', 'completed')
           AND l.start_date <= ?
           AND (l.actual_return_date IS NULL OR l.actual_return_date >= ?)
         LEFT JOIN invoices i
            ON i.lease_id = l.id
           AND i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
           AND i.invoice_date BETWEEN ? AND ?
         WHERE eu.deleted_at IS NULL
         GROUP BY eu.id, eu.unit_number, et.category
         ORDER BY et.category, eu.unit_number",
        [$today, $today, $from12m, $today, $from12m, $from12m, $today]
    );

    // Group into series by category for ApexCharts scatter
    $seriesMap = [];
    $totalUtil = 0.0;
    $totalRpd  = 0.0;
    $count     = 0;

    foreach ($units as $u) {
        $daysOnLease  = (int)$u['days_on_lease'];
        $revenue      = (float)$u['total_revenue'];
        $utilPct      = round($daysOnLease / $periodDays * 100, 1);
        $revPerDay    = $daysOnLease > 0 ? round($revenue / $daysOnLease, 2) : 0.0;

        $cat = $u['category'] ?: 'Uncategorized';
        if (!isset($seriesMap[$cat])) {
            $seriesMap[$cat] = [];
        }
        $seriesMap[$cat][] = [
            'x'           => $utilPct,
            'y'           => $revPerDay,
            'unit_number' => $u['unit_number'],
        ];

        $totalUtil += $utilPct;
        $totalRpd  += $revPerDay;
        $count++;
    }

    // Convert to ApexCharts series format
    $series = [];
    foreach ($seriesMap as $cat => $points) {
        $series[] = ['name' => $cat, 'data' => $points];
    }

    $avgUtil = $count > 0 ? round($totalUtil / $count, 1) : 0.0;
    $avgRpd  = $count > 0 ? round($totalRpd  / $count, 2) : 0.0;

    // Count high (>70%) and low (<30%) utilization units
    $highUtil = 0;
    $lowUtil  = 0;
    foreach ($units as $u) {
        $pct = (int)$u['days_on_lease'] / $periodDays * 100;
        if ($pct >= 70) $highUtil++;
        if ($pct < 30)  $lowUtil++;
    }

    return [
        'chart_data' => ['series' => $series],
        'kpis' => [
            'avg_utilization'   => $avgUtil,
            'avg_revenue_per_day' => number_format($avgRpd, 2, '.', ''),
            'high_util_count'   => $highUtil,
            'low_util_count'    => $lowUtil,
            'total_units'       => $count,
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 3 — Customer Concentration Risk
// Pie: top 5 customers by % of total 12m revenue + "Other" slice
// ════════════════════════════════════════════════════════════════════════════

/**
 * @return array { chart_data: { labels, series }, kpis }
 */
function view_concentration_risk(): array
{
    $from12m = date('Y-m-d', strtotime('-12 months'));
    $today   = date('Y-m-d');

    $rows = db_select(
        "SELECT
            COALESCE(c.company_name, i.company_name_snapshot, 'Unknown') AS customer_name,
            SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END) AS revenue
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
           AND i.invoice_date BETWEEN ? AND ?
         GROUP BY i.customer_id, customer_name
         ORDER BY revenue DESC
         LIMIT 50",
        [$from12m, $today]
    );

    $total   = array_sum(array_column($rows, 'revenue'));
    $labels  = [];
    $series  = [];
    $topSum  = 0.0;
    $top5Sum = 0.0;

    foreach (array_slice($rows, 0, 5) as $r) {
        $labels[] = $r['customer_name'];
        $rev      = (float)$r['revenue'];
        $series[] = round($rev, 2);
        $top5Sum += $rev;
    }

    // "Other" slice: all remaining customers
    if (count($rows) > 5) {
        $otherSum = $total - $top5Sum;
        $labels[] = 'Other';
        $series[] = round($otherSum, 2);
    }

    // KPIs
    $topCustomerPct = $total > 0 && count($series) > 0
        ? round($series[0] / $total * 100, 1) : 0.0;
    $top5Pct = $total > 0
        ? round($top5Sum / $total * 100, 1) : 0.0;

    return [
        'chart_data' => [
            'labels' => $labels,
            'series' => $series,
        ],
        'kpis' => [
            'top_customer_name' => $labels[0] ?? '',
            'top_customer_pct'  => $topCustomerPct,
            'top5_pct'          => $top5Pct,
            'customer_count'    => count($rows),
            'total_revenue'     => number_format($total, 2, '.', ''),
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 4 — Seasonal Revenue Pattern
// Radar: avg monthly revenue per month-of-year (all-time Jan–Dec)
// ════════════════════════════════════════════════════════════════════════════

/**
 * All-time data — date params not used here.
 * @return array { chart_data: { categories, series }, kpis }
 */
function view_seasonal_pattern(): array
{
    $rows = db_select(
        "SELECT
            MONTH(i.invoice_date)                  AS month_num,
            DATE_FORMAT(i.invoice_date, '%b')      AS month_label,
            AVG(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END)                    AS avg_revenue,
            COUNT(*)                               AS invoice_count
         FROM invoices i
         WHERE i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
         GROUP BY MONTH(i.invoice_date), month_label
         ORDER BY month_num ASC",
        []
    );

    // Build 12-month array — fill missing months with 0
    // WHY: radar chart needs all 12 spokes even if no data for a month
    $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $byMonth     = [];
    foreach ($rows as $r) {
        $byMonth[(int)$r['month_num']] = (float)$r['avg_revenue'];
    }

    $avgData = [];
    for ($m = 1; $m <= 12; $m++) {
        $avgData[] = round($byMonth[$m] ?? 0.0, 2);
    }

    // KPIs: best and worst months.
    // WHY the ?: [0] fallback: "worst month" ignores zero-revenue months, but
    // when EVERY month is zero (no billable invoices at all — a fresh
    // deployment, or a company with only drafts/voids) array_filter() returns
    // an empty array and min() throws. Both call sites need the same guard.
    $nonZero = array_filter($avgData) ?: [0.0];

    $bestIdx  = array_search(max($avgData), $avgData);
    $worstIdx = array_search(min($nonZero), $avgData);
    $bestIdx  = $bestIdx  === false ? 0 : $bestIdx;
    $worstIdx = $worstIdx === false ? 0 : $worstIdx;

    $maxVal = max($avgData);
    $minVal = min($nonZero);
    $variance = $maxVal > 0 ? round(($maxVal - $minVal) / $maxVal * 100, 1) : 0.0;

    return [
        'chart_data' => [
            'categories' => $monthLabels,
            'series'     => [
                ['name' => 'Avg Monthly Revenue', 'data' => $avgData],
            ],
        ],
        'kpis' => [
            'best_month'        => $monthLabels[$bestIdx] ?? '',
            'worst_month'       => $monthLabels[$worstIdx] ?? '',
            'seasonal_variance' => $variance,
            'avg_all_time'      => number_format(array_sum($avgData) / 12, 2, '.', ''),
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 5 — Cohort Revenue
// Stacked area: monthly revenue cohorted by lease start year
// ════════════════════════════════════════════════════════════════════════════

/**
 * @param string $dateFrom  Y-m-d
 * @param string $dateTo    Y-m-d
 * @return array { chart_data: { categories, series }, kpis }
 */
function view_cohort_revenue(string $dateFrom, string $dateTo): array
{
    $rows = db_select(
        "SELECT
            DATE_FORMAT(i.invoice_date, '%Y-%m')   AS period,
            YEAR(l.start_date)                     AS cohort_year,
            SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END)                    AS revenue
         FROM invoices i
         JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
           AND i.invoice_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), period, YEAR(l.start_date), cohort_year
         ORDER BY period ASC, cohort_year ASC",
        [$dateFrom, $dateTo]
    );

    // Build all unique periods and cohort years
    $periodSet = [];
    $yearSet   = [];
    foreach ($rows as $r) {
        $periodSet[$r['period']] = true;
        $yearSet[$r['cohort_year']] = true;
    }
    $periods = array_keys($periodSet);
    $years   = array_keys($yearSet);
    sort($periods);
    sort($years);

    // Pivot: for each year, produce revenue per period
    $pivot = [];
    foreach ($rows as $r) {
        $pivot[$r['cohort_year']][$r['period']] = (float)$r['revenue'];
    }

    $series = [];
    foreach ($years as $yr) {
        $data = [];
        foreach ($periods as $p) {
            $data[] = round($pivot[$yr][$p] ?? 0.0, 2);
        }
        $series[] = ['name' => (string)$yr, 'data' => $data];
    }

    // KPIs
    $currentYear      = (int)date('Y');
    $currentYearTotal = 0.0;
    $prevYearTotal    = 0.0;
    foreach ($rows as $r) {
        if ((int)$r['cohort_year'] === $currentYear) {
            $currentYearTotal += (float)$r['revenue'];
        } elseif ((int)$r['cohort_year'] === $currentYear - 1) {
            $prevYearTotal += (float)$r['revenue'];
        }
    }
    $growthPct = $prevYearTotal > 0
        ? round(($currentYearTotal - $prevYearTotal) / $prevYearTotal * 100, 1)
        : 0.0;

    return [
        'chart_data' => [
            'categories' => $periods,
            'series'     => $series,
        ],
        'kpis' => [
            'cohort_count'      => count($years),
            'current_year'      => $currentYear,
            'current_year_total'=> number_format($currentYearTotal, 2, '.', ''),
            'growth_pct'        => $growthPct,
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 6 — Fleet Composition Optimizer
// Grouped bar: current count vs recommended per equipment category
// Recommended = ceil(peak_concurrent_leases × 1.15) per category
// Peak = max concurrent active leases on any day in last 12m (sweep algorithm)
// ════════════════════════════════════════════════════════════════════════════

/**
 * @return array { chart_data: { categories, current, recommended }, kpis }
 */
function view_fleet_optimizer(): array
{
    $from12m = date('Y-m-d', strtotime('-12 months'));
    $today   = date('Y-m-d');

    // ── Current unit counts per category ────────────────────────────────────
    $currentRows = db_select(
        "SELECT et.category, COUNT(eu.id) AS unit_count
         FROM equipment_units eu
         JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
         WHERE eu.deleted_at IS NULL
           AND eu.status != 'decommissioned'
         GROUP BY et.category
         ORDER BY et.category ASC",
        []
    );

    $currentMap = [];
    foreach ($currentRows as $r) {
        $currentMap[$r['category']] = (int)$r['unit_count'];
    }

    // ── Fetch all leases in last 12m to compute peak concurrent per category ─
    // WHY: sweep algorithm — sort start/end events and find max running count
    $leaseRows = db_select(
        "SELECT
            et.category,
            l.start_date,
            COALESCE(l.actual_return_date, l.end_date, ?) AS end_date
         FROM leases l
         JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
         JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
         WHERE l.deleted_at IS NULL
           AND l.status IN ('active', 'completed')
           AND l.start_date <= ?
           AND (l.actual_return_date IS NULL OR l.actual_return_date >= ?)
         ORDER BY et.category ASC",
        [$today, $today, $from12m]
    );

    // Build events per category: +1 at start, -1 at end
    $events = [];
    foreach ($leaseRows as $r) {
        $cat = $r['category'];
        if (!isset($events[$cat])) {
            $events[$cat] = [];
        }
        $events[$cat][] = ['date' => $r['start_date'], 'delta' => 1];
        $events[$cat][] = ['date' => $r['end_date'],   'delta' => -1];
    }

    // Sweep per category to find peak concurrent count
    $peakMap = [];
    foreach ($events as $cat => $catEvents) {
        usort($catEvents, fn($a, $b) => strcmp($a['date'], $b['date']));
        $peak    = 0;
        $running = 0;
        foreach ($catEvents as $e) {
            $running += $e['delta'];
            if ($running > $peak) $peak = $running;
        }
        $peakMap[$cat] = $peak;
    }

    // ── Build chart data ─────────────────────────────────────────────────────
    // Union of all categories (current fleet + any category with leases)
    $allCats = array_unique(array_merge(
        array_keys($currentMap),
        array_keys($peakMap)
    ));
    sort($allCats);

    $categories   = [];
    $currentData  = [];
    $recommended  = [];
    $overCapacity = 0;
    $underCapacity= 0;

    foreach ($allCats as $cat) {
        $curr = $currentMap[$cat] ?? 0;
        $peak = $peakMap[$cat] ?? 0;
        $rec  = (int)ceil($peak * 1.15);
        // WHY: minimum recommendation of 1 if any leases exist in category
        if ($peak > 0 && $rec < 1) $rec = 1;

        $categories[]  = $cat;
        $currentData[] = $curr;
        $recommended[] = $rec;

        if ($curr > $rec)       $overCapacity++;
        elseif ($curr < $rec)   $underCapacity++;
    }

    return [
        'chart_data' => [
            'categories'  => $categories,
            'current'     => $currentData,
            'recommended' => $recommended,
        ],
        'kpis' => [
            'total_categories'  => count($categories),
            'over_capacity'     => $overCapacity,
            'under_capacity'    => $underCapacity,
            'balanced'          => count($categories) - $overCapacity - $underCapacity,
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 7 — Lead Time Analysis
// Line: avg days from lease created_at to activation per month (last 12m)
// Uses lease_status_log WHERE new_status = 'active' to find activation time
// ════════════════════════════════════════════════════════════════════════════

/**
 * @param string $dateFrom  Y-m-d
 * @param string $dateTo    Y-m-d
 * @return array { chart_data: { categories, series }, kpis }
 */
function view_lead_time(string $dateFrom, string $dateTo): array
{
    // WHY: subquery gets first activation time per lease (MIN in case of reopen/reclose).
    // Outer query then groups by month — avoids invalid use of aggregate in GROUP BY.
    $rows = db_select(
        "SELECT
            DATE_FORMAT(fa.activated_at, '%Y-%m')          AS period,
            DATE_FORMAT(fa.activated_at, '%Y-%m')          AS period2,
            AVG(DATEDIFF(fa.activated_at, l.created_at))   AS avg_lead_days,
            COUNT(l.id)                                    AS lease_count
         FROM leases l
         JOIN (
             SELECT lease_id, MIN(changed_at) AS activated_at
             FROM lease_status_log
             WHERE new_status = 'active'
             GROUP BY lease_id
         ) fa ON fa.lease_id = l.id
         WHERE l.deleted_at IS NULL
           AND fa.activated_at BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(fa.activated_at, '%Y-%m'), period2
         ORDER BY period ASC",
        [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
    );

    $categories = [];
    $avgData    = [];
    foreach ($rows as $r) {
        $categories[] = $r['period'];
        $avgData[]    = round((float)$r['avg_lead_days'], 1);
    }

    // KPIs
    $allDays = $avgData ?: [0];
    $overallAvg = count($allDays) > 0 ? round(array_sum($allDays) / count($allDays), 1) : 0.0;
    $bestIdx    = array_search(min($allDays), $allDays);
    $worstIdx   = array_search(max($allDays), $allDays);

    return [
        'chart_data' => [
            'categories' => $categories,
            'series'     => [
                ['name' => 'Avg Lead Time (days)', 'data' => $avgData],
            ],
        ],
        'kpis' => [
            'avg_lead_days'  => $overallAvg,
            'best_month'     => $categories[$bestIdx]  ?? '',
            'worst_month'    => $categories[$worstIdx] ?? '',
            'data_points'    => count($rows),
        ],
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// VIEW 8 — Average Lease Value Trend
// Line: avg invoice total per month (last 12m)
// ════════════════════════════════════════════════════════════════════════════

/**
 * @param string $dateFrom  Y-m-d
 * @param string $dateTo    Y-m-d
 * @return array { chart_data: { categories, series }, kpis }
 */
function view_avg_lease_value(string $dateFrom, string $dateTo): array
{
    $rows = db_select(
        "SELECT
            DATE_FORMAT(i.invoice_date, '%Y-%m')  AS period,
            AVG(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END)                   AS avg_value,
            COUNT(*)                              AS invoice_count,
            SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END)                   AS total_revenue
         FROM invoices i
         WHERE i.deleted_at IS NULL
           AND i.status NOT IN ('void', 'draft', 'written_off')
           AND i.invoice_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), period
         ORDER BY period ASC",
        [$dateFrom, $dateTo]
    );

    $categories = [];
    $avgData    = [];
    foreach ($rows as $r) {
        $categories[] = $r['period'];
        $avgData[]    = round((float)$r['avg_value'], 2);
    }

    // All-time avg for comparison
    $allTimeRow = db_row(
        "SELECT AVG(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END) AS avg_all_time
         FROM invoices
         WHERE deleted_at IS NULL AND status NOT IN ('void', 'draft', 'written_off')",
        []
    );
    $avgAllTime = round((float)($allTimeRow['avg_all_time'] ?? 0), 2);

    // Trend direction: compare last value to first
    $trendDir = 'flat';
    if (count($avgData) >= 2) {
        $diff = end($avgData) - $avgData[0];
        if ($diff > 0)  $trendDir = 'up';
        if ($diff < 0)  $trendDir = 'down';
    }

    $currentMonth = count($avgData) > 0 ? end($avgData) : 0.0;

    return [
        'chart_data' => [
            'categories' => $categories,
            'series'     => [
                ['name' => 'Avg Invoice Value', 'data' => $avgData],
            ],
        ],
        'kpis' => [
            'avg_all_time'    => number_format($avgAllTime, 2, '.', ''),
            'trend_direction' => $trendDir,
            'current_month'   => number_format($currentMonth, 2, '.', ''),
            'data_points'     => count($rows),
        ],
    ];
}
