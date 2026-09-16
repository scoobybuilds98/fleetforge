<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Charts API
 *
 * @file        api/v1/dashboard/charts.php
 * @description Returns datasets for the 12 ApexCharts rendered on the admin dashboard.
 *              Each chart dataset is cached independently (15-min TTL per spec §8).
 *
 *              Available charts (pass ?chart=<key> for a single chart, or omit for all):
 *                revenue_trend        — 12-month area (current year vs prior year)
 *                fleet_status         — donut, 5 equipment status segments
 *                ar_aging             — horizontal bar, 4 AR buckets
 *                top_customers        — horizontal bar, top 5 by YTD revenue
 *                leases_trend         — grouped bar, opened vs closed per month (12mo)
 *                utilization_trend    — line, monthly utilization % (12mo)
 *                revenue_by_type      — donut, revenue grouped by equipment category
 *                weekly_heatmap       — 7×12 heatmap grid (daily revenue last 12 weeks)
 *                revenue_forecast     — area, projected revenue next 6 months from active leases
 *                lease_expiry_calendar— bar, count of active leases expiring each month (12mo)
 *                occupancy_by_type    — grouped bar, occupied % vs available % per equipment category
 *                payment_speed        — line, avg days from invoice_date to payment (last 12mo)
 *
 * @method      GET
 * @params      chart (optional) — chart key; omit to fetch all
 * @auth        Session required (require_auth_api)
 * @returns     { [chart_key]: { labels: [], series: [], ... } }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php,
 *              lib/Reports/FleetUtilization.php (utilization_trend),
 *              lib/Reports/ArAging.php (ar_aging)
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
    'revenue_forecast',
    'lease_expiry_calendar',
    'occupancy_by_type',
    'payment_speed',
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
    // Per-chart calc version: bumping it orphans a cached payload computed with
    // superseded maths (utilization merge fix, CAD-canonical AR aging) instead
    // of serving it for up to 15 more minutes.
    $calcVersion = ['utilization_trend' => '|fleet-util-v2', 'ar_aging' => '|ar-aging-cad-v2'][$chartKey] ?? '';
    $cacheHash = hash('sha256', 'dashboard_chart_' . $chartKey . $calcVersion);

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

// Serve-time financial redaction. Revenue/AR charts expose dollar figures and
// are gated on payments:view; operational charts (fleet_status, utilization)
// stay visible to all staff. Per-chart cache is role-blind, so withhold on the
// way out rather than caching pre-redacted.
$moneyCharts = ['revenue_trend', 'ar_aging', 'revenue_by_type', 'revenue_forecast'];
if (!can_view_financials()) {
    if ($requestedChart !== null && in_array($requestedChart, $moneyCharts, true)) {
        json_error('FORBIDDEN', 'You do not have permission to view financial charts.', 403);
    }
    foreach ($moneyCharts as $mc) {
        unset($results[$mc]);
    }
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
        'revenue_trend'          => chart_revenue_trend(),
        'fleet_status'           => chart_fleet_status(),
        'ar_aging'               => chart_ar_aging(),
        'top_customers'          => chart_top_customers(),
        'leases_trend'           => chart_leases_trend(),
        'utilization_trend'      => chart_utilization_trend(),
        'revenue_by_type'        => chart_revenue_by_type(),
        'weekly_heatmap'         => chart_weekly_heatmap(),
        'revenue_forecast'       => chart_revenue_forecast(),
        'lease_expiry_calendar'  => chart_lease_expiry_calendar(),
        'occupancy_by_type'      => chart_occupancy_by_type(),
        'payment_speed'          => chart_payment_speed(),
        default                  => [],
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
            SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END)   AS total
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
 * AR aging — horizontal bar, 4 buckets of outstanding balance as of today.
 *
 * Built from lib/Reports/ArAging.php (the same calculation as Accounting →
 * AR Aging and Reports → AR Aging), so amounts are CAD-canonical: each
 * invoice's balance is converted with its own frozen exchange_rate_to_cad.
 * WHY: the old inline query summed USD and CAD balances at face value.
 * "0–30 days" folds not-yet-due (current) together with 1–30 days past due,
 * as this chart always has.
 */
function chart_ar_aging(): array
{
    $aging = \FleetForge\Reports\ArAging::asOf(date('Y-m-d'));
    $t     = $aging['totals'];   // CAD, bcmath strings

    return [
        'labels' => ['0–30 days', '31–60 days', '61–90 days', '90+ days'],
        'series' => [
            [
                'name' => 'Balance Due (CAD)',
                'data' => [
                    (float) bcadd($t['current'], $t['days_1_30'], 2),
                    (float) $t['days_31_60'],
                    (float) $t['days_61_90'],
                    (float) $t['days_90_plus'],
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
            SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END) AS total
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
 * Each point = occupied unit-days ÷ available unit-days for that calendar
 * month (the current month runs to today), computed by
 * FleetForge\Reports\FleetUtilization — the same implementation behind
 * Reports → Fleet and Analytics, so the three surfaces agree for a month.
 *
 * WHY (was an approximation): it counted a unit as "utilized" for the WHOLE
 * month if it had even one lease day in it, and read end_date without
 * actual_return_date — a 2-day rental counted like a 31-day one.
 * One lease query serves all 12 months (FleetUtilization::forWindows).
 */
function chart_utilization_trend(): array
{
    $labels  = [];
    $windows = [];
    $today   = date('Y-m-d');

    for ($i = 11; $i >= 0; $i--) {
        // 'first day of' anchors the month arithmetic so the 31st never skips a month.
        $ts        = strtotime("first day of -{$i} months");
        $labels[]  = date('M Y', $ts);
        $windows[] = [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }

    $results = \FleetForge\Reports\FleetUtilization::forWindows($windows, null, $today);
    $data    = array_map(static fn(array $r) => (float) $r['utilization_pct'], $results);

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
            SUM(CASE WHEN inv.currency='USD' THEN inv.total_amount*COALESCE(inv.exchange_rate_to_cad,1) ELSE inv.total_amount END) AS total
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
        "SELECT DATE(invoice_date) AS day, SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END) AS total
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

/**
 * Revenue forecast — projected revenue for the next 6 months from currently active leases.
 *
 * For each upcoming month, sums the expected income across every active lease that
 * overlaps that month's date range.  Rate priority: monthly_rate → weekly_rate × 4.33
 * → daily_rate × days_in_month.  All arithmetic uses bcmath (D16 rule).
 *
 * @return array{ labels: string[], series: array<array{name: string, data: float[]}> }
 */
function chart_revenue_forecast(): array
{
    $labels = [];
    $data   = [];

    for ($i = 0; $i <= 5; $i++) {
        $ts         = strtotime("+{$i} months");
        $monthStart = date('Y-m-01', $ts);
        $monthEnd   = date('Y-m-t', $ts);
        $daysInMonth = (int) date('t', $ts);
        $labels[]   = date('M Y', $ts);

        // Fetch all active leases that overlap this calendar month
        $leases = db_select(
            "SELECT monthly_rate, weekly_rate, daily_rate
               FROM leases
              WHERE status = 'active'
                AND deleted_at IS NULL
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)",
            [$monthEnd, $monthStart]
        );

        // Accumulate projected income for this month using bcmath (D16 rule)
        $monthTotal = '0.00';
        foreach ($leases as $lease) {
            $monthly = (string) $lease['monthly_rate'];
            $weekly  = (string) $lease['weekly_rate'];
            $daily   = (string) $lease['daily_rate'];

            if (bccomp($monthly, '0', 2) > 0) {
                // Monthly rate takes precedence
                $contribution = $monthly;
            } elseif (bccomp($weekly, '0', 2) > 0) {
                // Weekly rate × 4.33 average weeks per month
                $contribution = bcmul($weekly, '4.33', 2);
            } else {
                // Daily rate × actual days in this month
                $contribution = bcmul($daily, (string) $daysInMonth, 2);
            }

            $monthTotal = bcadd($monthTotal, $contribution, 2);
        }

        $data[] = (float) $monthTotal;
    }

    return [
        'labels' => $labels,
        'series' => [['name' => 'Projected Revenue', 'data' => $data]],
    ];
}

/**
 * Lease expiry calendar — count of active leases expiring in each of the next 12 months.
 *
 * Useful for forecasting renewal workload and identifying concentration risk
 * (many leases expiring in a single month).
 *
 * @return array{ labels: string[], series: array<array{name: string, data: int[]}> }
 */
function chart_lease_expiry_calendar(): array
{
    $labels = [];
    $data   = [];

    for ($i = 0; $i <= 11; $i++) {
        $ts      = strtotime("+{$i} months");
        $yr      = date('Y', $ts);
        $mo      = date('n', $ts);
        $labels[] = date('M Y', $ts);

        $row = db_row(
            "SELECT COUNT(*) AS cnt
               FROM leases
              WHERE status = 'active'
                AND deleted_at IS NULL
                AND YEAR(end_date)  = ?
                AND MONTH(end_date) = ?",
            [$yr, $mo]
        );

        $data[] = (int) $row['cnt'];
    }

    return [
        'labels' => $labels,
        'series' => [['name' => 'Leases Expiring', 'data' => $data]],
    ];
}

/**
 * Occupancy by equipment type — grouped bar showing occupied % vs available % per category.
 *
 * Query A: total units per category (excludes decommissioned and soft-deleted units).
 * Query B: units currently on an active lease per category.
 * WHY two queries: a single LEFT JOIN + GROUP BY mixes total and leased counts cleanly
 * but requires care around NULL; two focused queries are easier to audit and test.
 *
 * @return array{ labels: string[], series: array<array{name: string, data: float[]}> }
 */
function chart_occupancy_by_type(): array
{
    // (A) Total units per equipment category (excludes decommissioned and deleted)
    $totalRows = db_select(
        "SELECT et.category, COUNT(*) AS total
           FROM equipment_units eu
           JOIN equipment_templates et ON et.id = eu.template_id
          WHERE eu.status != 'decommissioned'
            AND eu.deleted_at IS NULL
          GROUP BY et.category
          ORDER BY et.category"
    );

    // Build an indexed map: category → total count
    $totals = [];
    foreach ($totalRows as $row) {
        $totals[$row['category']] = (int) $row['total'];
    }

    // (B) Units currently on an active lease per category
    $leasedRows = db_select(
        "SELECT et.category, COUNT(DISTINCT eu.id) AS leased
           FROM leases l
           JOIN equipment_units eu       ON eu.id  = l.equipment_unit_id
           JOIN equipment_templates et   ON et.id  = eu.template_id
          WHERE l.status = 'active'
            AND l.deleted_at  IS NULL
            AND eu.deleted_at IS NULL
          GROUP BY et.category"
    );

    // Build an indexed map: category → leased count
    $leased = [];
    foreach ($leasedRows as $row) {
        $leased[$row['category']] = (int) $row['leased'];
    }

    // Compute occupied/available percentages for every category from query A
    $labels       = [];
    $occupiedPct  = [];
    $availablePct = [];

    foreach ($totals as $category => $total) {
        $leasedCount   = $leased[$category] ?? 0;
        $occupied      = $total > 0 ? round(($leasedCount / $total) * 100) : 0;
        $labels[]       = ucwords(str_replace('_', ' ', $category));
        $occupiedPct[]  = $occupied;
        $availablePct[] = 100 - $occupied;
    }

    return [
        'labels' => $labels,
        'series' => [
            ['name' => 'Occupied %',  'data' => $occupiedPct],
            ['name' => 'Available %', 'data' => $availablePct],
        ],
    ];
}

/**
 * Payment speed — average days between invoice_date and payment date (last 12 months).
 *
 * Uses updated_at as a proxy for payment date: invoices move to status='paid' when
 * payment is recorded, so updated_at closely approximates actual payment timing.
 * Months with no paid invoices yield null so the frontend can render a gap rather
 * than a misleading zero.
 *
 * @return array{ labels: string[], series: array<array{name: string, data: (float|null)[]}> }
 */
function chart_payment_speed(): array
{
    $labels = [];
    $data   = [];

    for ($i = 11; $i >= 0; $i--) {
        $ts      = strtotime("-{$i} months");
        $yr      = date('Y', $ts);
        $mo      = date('n', $ts);
        $labels[] = date('M Y', $ts);

        // AVG(DATEDIFF) across all invoices paid in this month
        $row = db_row(
            "SELECT AVG(DATEDIFF(DATE(updated_at), invoice_date)) AS avg_days
               FROM invoices
              WHERE status = 'paid'
                AND deleted_at IS NULL
                AND YEAR(updated_at)  = ?
                AND MONTH(updated_at) = ?",
            [$yr, $mo]
        );

        // WHY null guard: AVG on an empty set returns NULL; use null in data so
        // ApexCharts renders a gap instead of a zero which would skew the trend line.
        $data[] = $row['avg_days'] !== null ? round((float) $row['avg_days'], 1) : null;
    }

    return [
        'labels' => $labels,
        'series' => [['name' => 'Avg Days to Pay', 'data' => $data]],
    ];
}
