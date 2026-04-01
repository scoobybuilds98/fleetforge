<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Charts API
 *
 * @file        api/v1/dashboard/charts.php
 * @description Returns datasets for the 8 ApexCharts rendered on the admin dashboard.
 *              Each chart dataset is cached independently (15-min TTL per spec §8).
 *
 *              Available charts (pass ?chart=<key> for a single chart, or omit for all):
 *                revenue_trend    — 12-month area (current year vs prior year)
 *                fleet_status     — donut, 5 equipment status segments
 *                ar_aging         — horizontal bar, 4 AR buckets
 *                top_customers    — horizontal bar, top 5 by YTD revenue
 *                leases_trend     — grouped bar, opened vs closed per month (12mo)
 *                utilization_trend— line, monthly utilization % (12mo)
 *                revenue_by_type  — donut, revenue grouped by equipment category
 *                weekly_heatmap   — 7×12 heatmap grid (daily revenue last 12 weeks)
 *
 * @method      GET
 * @params      chart (optional) — chart key; omit to fetch all
 * @auth        Session required (require_auth_api)
 * @returns     { [chart_key]: { labels: [], series: [], ... } }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §9 Charts & Analytics Specification
 * @design      FLEETFORGE_DESIGN_DETAILS.md §4 Dashboard Grid Layout
 * @session     S004
 */

// dirname(__DIR__, 3): api/v1/dashboard/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Allowed chart keys ─────────────────────────────────────────
// WHY allowlist: never let user input drive which query runs (SQL injection guard
// beyond just param binding — prevents enumeration of internal report types).
$allowedCharts = [
    'revenue_trend',
    'fleet_status',
    'ar_aging',
    'top_customers',
    'leases_trend',
    'utilization_trend',
    'revenue_by_type',
    'weekly_heatmap',
];

$requestedChart = clean_string($_GET['chart'] ?? null);
if ($requestedChart !== null && !in_array($requestedChart, $allowedCharts, true)) {
    json_error('NOT_FOUND', 'Unknown chart key.', 404);
}

// Build the list of charts to fetch
$chartsToFetch = ($requestedChart !== null) ? [$requestedChart] : $allowedCharts;

$now          = date('Y-m-d H:i:s');
$cacheTtlMin  = 15;
$results      = [];

foreach ($chartsToFetch as $chartKey) {
    $cacheHash = hash('sha256', 'dashboard_chart_' . $chartKey);

    // ── Cache hit? ─────────────────────────────────────────────
    $cached = db_row(
        "SELECT result_data FROM report_cache
          WHERE report_type = ?
            AND parameters_hash = ?
            AND expires_at > ?",
        ['dashboard_chart_' . $chartKey, $cacheHash, $now]
    );

    if ($cached) {
        $results[$chartKey] = json_decode($cached['result_data'], true);
        continue;
    }

    // ── Build fresh dataset ────────────────────────────────────
    $dataset    = build_chart_dataset($chartKey);
    $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$cacheTtlMin} minutes"));

    db_execute(
        "REPLACE INTO report_cache
            (report_type, parameters_hash, parameters, result_data, generated_at, expires_at, generated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            'dashboard_chart_' . $chartKey,
            $cacheHash,
            json_encode(['chart' => $chartKey]),
            json_encode($dataset),
            $now,
            $expiresAt,
            current_user_id(),
        ]
    );

    $results[$chartKey] = $dataset;
}

// If a single chart was requested, return its dataset directly under 'data'
if ($requestedChart !== null) {
    json_success($results[$requestedChart]);
}

json_success($results);


// ============================================================
// CHART DATASET BUILDERS
// Each function returns an array that ApexCharts can consume
// directly on the frontend (labels + series + optional meta).
// ============================================================

/**
 * Dispatch to the correct builder based on chart key.
 */
function build_chart_dataset(string $key): array
{
    return match ($key) {
        'revenue_trend'     => chart_revenue_trend(),
        'fleet_status'      => chart_fleet_status(),
        'ar_aging'          => chart_ar_aging(),
        'top_customers'     => chart_top_customers(),
        'leases_trend'      => chart_leases_trend(),
        'utilization_trend' => chart_utilization_trend(),
        'revenue_by_type'   => chart_revenue_by_type(),
        'weekly_heatmap'    => chart_weekly_heatmap(),
        default             => [],
    };
}

/**
 * Revenue trend — 12-month area chart, current year vs prior year.
 *
 * Returns monthly invoice totals for the current calendar year and the
 * prior year so ApexCharts can render two area series.
 * Only paid/partially_paid/overdue invoices count as realised revenue.
 * Void and written_off invoices are excluded.
 */
function chart_revenue_trend(): array
{
    $currentYear = (int) date('Y');
    $priorYear   = $currentYear - 1;

    // Month labels: Jan–Dec
    $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    // Fetch monthly totals for both years in one query
    $rows = db_select(
        "SELECT
            YEAR(invoice_date)  AS yr,
            MONTH(invoice_date) AS mo,
            SUM(total_amount)   AS total
           FROM invoices
          WHERE status NOT IN ('draft','void','written_off')
            AND deleted_at IS NULL
            AND YEAR(invoice_date) IN (?, ?)
          GROUP BY yr, mo
          ORDER BY yr, mo",
        [$currentYear, $priorYear]
    );

    // Bucket results by year → month
    $byYear = [$currentYear => array_fill(1, 12, '0.00'), $priorYear => array_fill(1, 12, '0.00')];
    foreach ($rows as $row) {
        $byYear[(int)$row['yr']][(int)$row['mo']] = bcround((string) $row['total'], 2);
    }

    return [
        'labels'  => $labels,
        'series'  => [
            ['name' => (string) $currentYear, 'data' => array_values($byYear[$currentYear])],
            ['name' => (string) $priorYear,   'data' => array_values($byYear[$priorYear])],
        ],
    ];
}

/**
 * Fleet status donut — count of units in each status (excluding decommissioned).
 */
function chart_fleet_status(): array
{
    $rows = db_select(
        "SELECT status, COUNT(*) AS cnt
           FROM equipment_units
          WHERE deleted_at IS NULL
            AND status != 'decommissioned'
          GROUP BY status
          ORDER BY cnt DESC"
    );

    // Maintain a consistent label/colour order regardless of what the DB returns
    $order  = ['available', 'on_lease', 'reserved', 'maintenance', 'inactive'];
    $counts = array_fill_keys($order, 0);
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
    }

    return [
        'labels' => ['Available', 'On Lease', 'Reserved', 'Maintenance', 'Inactive'],
        'series' => array_values($counts),
    ];
}

/**
 * AR aging — sum of balance_due bucketed into 4 age brackets.
 *
 * Buckets are based on days between today and invoice due_date.
 * Only overdue / partially_paid / sent invoices are included.
 */
function chart_ar_aging(): array
{
    $today = date('Y-m-d');

    $rows = db_select(
        "SELECT
            DATEDIFF(?, due_date) AS days_overdue,
            balance_due
           FROM invoices
          WHERE status IN ('overdue','partially_paid','sent')
            AND deleted_at IS NULL
            AND balance_due > 0",
        [$today]
    );

    // WHY string init + bcadd loop: balance_due is monetary — D16 bcmath rule
    $buckets = ['0-30' => '0.00', '31-60' => '0.00', '61-90' => '0.00', '90+' => '0.00'];

    foreach ($rows as $row) {
        $days = max(0, (int) $row['days_overdue']);
        $amt  = (string) $row['balance_due'];

        if ($days <= 30) {
            $buckets['0-30']  = bcadd($buckets['0-30'],  $amt, 2);
        } elseif ($days <= 60) {
            $buckets['31-60'] = bcadd($buckets['31-60'], $amt, 2);
        } elseif ($days <= 90) {
            $buckets['61-90'] = bcadd($buckets['61-90'], $amt, 2);
        } else {
            $buckets['90+']   = bcadd($buckets['90+'],   $amt, 2);
        }
    }

    return [
        'labels' => ['0–30 days', '31–60 days', '61–90 days', '90+ days'],
        'series' => [
            [
                'name' => 'Balance Due',
                'data' => [
                    (float) $buckets['0-30'],
                    (float) $buckets['31-60'],
                    (float) $buckets['61-90'],
                    (float) $buckets['90+'],
                ],
            ],
        ],
    ];
}

/**
 * Top 5 customers by YTD invoice revenue.
 *
 * Uses company_name_snapshot so the chart works even if a customer record
 * was later soft-deleted (snapshots are frozen at invoice creation).
 */
function chart_top_customers(): array
{
    $currentYear = date('Y');

    $rows = db_select(
        "SELECT
            COALESCE(company_name_snapshot, customer_name_snapshot, 'Unknown') AS customer_name,
            SUM(total_amount) AS total
           FROM invoices
          WHERE status NOT IN ('draft','void','written_off')
            AND deleted_at IS NULL
            AND YEAR(invoice_date) = ?
          GROUP BY customer_id, company_name_snapshot, customer_name_snapshot
          ORDER BY total DESC
          LIMIT 5",
        [$currentYear]
    );

    $labels = [];
    $data   = [];
    foreach ($rows as $row) {
        $labels[] = $row['customer_name'];
        $data[]   = (float) bcround((string) $row['total'], 2);
    }

    return [
        'labels' => $labels,
        'series' => [['name' => 'Revenue', 'data' => $data]],
    ];
}

/**
 * Leases trend — grouped bar: leases opened vs closed per month (last 12 months).
 */
function chart_leases_trend(): array
{
    // Last 12 complete months + current month
    $labels  = [];
    $opened  = [];
    $closed  = [];

    for ($i = 11; $i >= 0; $i--) {
        $ts      = strtotime("-{$i} months");
        $yr      = date('Y', $ts);
        $mo      = date('n', $ts);
        $label   = date('M Y', $ts);
        $labels[] = $label;

        $openRow = db_row(
            "SELECT COUNT(*) AS cnt FROM leases
              WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?
                AND deleted_at IS NULL",
            [$yr, $mo]
        );
        $opened[] = (int) $openRow['cnt'];

        // "Closed" = completed or cancelled in this month
        $closeRow = db_row(
            "SELECT COUNT(*) AS cnt FROM leases
              WHERE status IN ('completed','cancelled')
                AND YEAR(updated_at) = ? AND MONTH(updated_at) = ?
                AND deleted_at IS NULL",
            [$yr, $mo]
        );
        $closed[] = (int) $closeRow['cnt'];
    }

    return [
        'labels' => $labels,
        'series' => [
            ['name' => 'Opened', 'data' => $opened],
            ['name' => 'Closed',  'data' => $closed],
        ],
    ];
}

/**
 * Utilization trend — monthly fleet utilization % over the last 12 months.
 *
 * Approximation: each month counts units that had at least one active
 * lease day in that month vs total active units at month end.
 * This avoids a full lease-day expansion scan; acceptable for a dashboard chart.
 */
function chart_utilization_trend(): array
{
    $labels = [];
    $data   = [];

    for ($i = 11; $i >= 0; $i--) {
        $ts         = strtotime("-{$i} months");
        $yr         = date('Y', $ts);
        $mo         = date('n', $ts);
        $monthStart = date('Y-m-01', $ts);
        $monthEnd   = date('Y-m-t', $ts);
        $labels[]   = date('M Y', $ts);

        // Units on lease at any point during this month
        $onLeaseRow = db_row(
            "SELECT COUNT(DISTINCT equipment_unit_id) AS cnt
               FROM leases
              WHERE status IN ('active','completed')
                AND deleted_at IS NULL
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)",
            [$monthEnd, $monthStart]
        );
        $onLease = (int) $onLeaseRow['cnt'];

        // Total active fleet at month end (not decommissioned, not deleted before month end)
        $totalRow = db_row(
            "SELECT COUNT(*) AS cnt
               FROM equipment_units
              WHERE status != 'decommissioned'
                AND deleted_at IS NULL
                AND created_at <= ?",
            [$monthEnd . ' 23:59:59']
        );
        $total = (int) $totalRow['cnt'];

        $data[] = $total > 0 ? round(($onLease / $total) * 100, 1) : 0.0;
    }

    return [
        'labels' => $labels,
        'series' => [['name' => 'Utilization %', 'data' => $data]],
    ];
}

/**
 * Revenue by equipment type — donut, grouped by template category.
 *
 * Joins invoices → leases → equipment_units → equipment_templates.
 * Uses unit_number_invoice_snapshot to avoid broken joins on soft-deleted units.
 * WHY snapshot join: lease_id on invoice is the reliable link even after unit deletion.
 */
function chart_revenue_by_type(): array
{
    $currentYear = date('Y');

    $rows = db_select(
        "SELECT
            COALESCE(et.category, 'unknown') AS category,
            SUM(inv.total_amount) AS total
           FROM invoices inv
           JOIN leases l         ON l.id  = inv.lease_id
           JOIN equipment_units eu ON eu.id = l.equipment_unit_id
           JOIN equipment_templates et ON et.id = eu.template_id
          WHERE inv.status NOT IN ('draft','void','written_off')
            AND inv.deleted_at IS NULL
            AND l.deleted_at   IS NULL
            AND eu.deleted_at  IS NULL
            AND YEAR(inv.invoice_date) = ?
          GROUP BY et.category
          ORDER BY total DESC",
        [$currentYear]
    );

    $labels = [];
    $series = [];
    foreach ($rows as $row) {
        // Humanise the enum value for the chart label
        $labels[] = ucwords(str_replace('_', ' ', $row['category']));
        $series[] = (float) bcround((string) $row['total'], 2);
    }

    return [
        'labels' => $labels,
        'series' => $series,
    ];
}

/**
 * Weekly revenue heatmap — daily invoice totals for the last 12 weeks (84 days).
 *
 * Returns a 12-element array of weeks, each week being an array of 7 day objects.
 * ApexCharts heatmap series format:
 *   series = [{ name: 'Week N', data: [{ x: 'Mon', y: 1234.56 }, ...] }]
 *
 * Only non-void, non-draft invoices are included.
 */
function chart_weekly_heatmap(): array
{
    $days    = 83; // 84 days including today = 12 complete weeks
    $startDt = date('Y-m-d', strtotime("-{$days} days"));
    $today   = date('Y-m-d');

    $rows = db_select(
        "SELECT DATE(invoice_date) AS day, SUM(total_amount) AS total
           FROM invoices
          WHERE status NOT IN ('draft','void','written_off')
            AND deleted_at IS NULL
            AND invoice_date >= ?
            AND invoice_date <= ?
          GROUP BY day
          ORDER BY day",
        [$startDt, $today]
    );

    // Index daily totals by date string for O(1) lookup
    $dailyTotals = [];
    foreach ($rows as $row) {
        $dailyTotals[$row['day']] = (float) bcround((string) $row['total'], 2);
    }

    // Build 12 week series
    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $series   = [];

    for ($week = 0; $week < 12; $week++) {
        $weekOffset  = (11 - $week) * 7;
        $weekDayData = [];

        for ($d = 0; $d < 7; $d++) {
            $daysAgo = $weekOffset - $d;
            $dt      = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $dow     = (int) date('w', strtotime($dt));

            $weekDayData[] = [
                'x' => $dayNames[$dow],
                'y' => $dailyTotals[$dt] ?? 0.0,
            ];
        }

        $series[] = [
            'name' => 'Week ' . ($week + 1),
            'data' => $weekDayData,
        ];
    }

    return ['series' => $series];
}
