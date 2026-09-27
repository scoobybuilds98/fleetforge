<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Charts API
 *
 * @file        api/v1/dashboard/charts.php
 * @description Returns datasets for the ApexCharts / visual cards rendered on the
 *              admin dashboard. Each chart dataset is cached independently
 *              (15-min TTL per spec §8) — one report_cache row per chart, but
 *              read with ONE lookup and written with ONE multi-row REPLACE per
 *              request (S-PERF-3).
 *
 *              Available charts (pass ?chart=<key> for a single chart, or omit for all):
 *                revenue_trend        — 12-month area (current year vs prior year)
 *                fleet_status         — donut, 5 equipment status segments
 *                ar_aging             — horizontal bar, 4 AR buckets
 *                top_customers        — horizontal bar, top 5 by YTD revenue
 *                                       (+ ytd_total across ALL customers, for share-of-total)
 *                leases_trend         — grouped bar, opened vs closed per month (12mo)
 *                utilization_trend    — line, monthly utilization % (12mo)
 *                revenue_by_type      — donut, revenue grouped by equipment category
 *                weekly_heatmap       — 7×12 heatmap grid (daily revenue last 12 weeks)
 *                revenue_forecast     — area, projected revenue next 6 months from active leases
 *                lease_expiry_calendar— bar, count of active leases expiring each month (12mo)
 *                occupancy_by_type    — grouped bar, occupied % vs available % per equipment category
 *                payment_speed        — line, avg days from invoice_date to paid_date (last 12mo)
 *
 *              S-DASHBOARD-VIZ (plain-shaped datasets, not ApexCharts series):
 *                cash_flow            — MONEY. Billed vs collected per month, rolling 12
 *                                       months ending with the current month (CAD)
 *                receivables          — MONEY. AR total + 5 aging buckets with invoice
 *                                       counts, from lib/Reports/ArAging.php
 *                overdue_customers    — MONEY. Top 6 customers by overdue balance (CAD)
 *                fleet_mix            — unit counts by status + per-category split, right now
 *                lease_flow           — leases opened / closed / on rent per month (12mo)
 *
 * @method      GET
 * @params      chart  (optional) — one chart key; returns that dataset directly
 *                                  under `data` (403 for a money chart without
 *                                  financial access). Wins over `charts`.
 *              charts (optional) — comma list of chart keys (S-DASHBOARD-VIZ);
 *                                  returns only those keys, keyed like the
 *                                  all-charts payload. Unknown/empty list → 422.
 *                                  Money keys are omitted for non-financial roles.
 *              omit both to fetch all charts
 * @auth        Session required (require_auth_api)
 * @returns     { [chart_key]: { labels: [], series: [], ... } }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php,
 *              lib/Reports/FleetUtilization.php (utilization_trend),
 *              lib/Reports/ArAging.php (ar_aging, receivables, overdue_customers),
 *              lib/Reports/ReportBuilder.php (pct() — fleet_mix)
 * @spec        FLEETFORGE_SPEC_FINAL.md §9 Charts & Analytics Specification
 * @design      FLEETFORGE_DESIGN_DETAILS.md §4 Dashboard Grid Layout
 * @session     S004, S-DASH-CHART-REDACT, S-DASHBOARD-VIZ, S-PERF-3
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
    // S-DASHBOARD-VIZ
    'cash_flow',
    'receivables',
    'overdue_customers',
    'fleet_mix',
    'lease_flow',
];

$requestedChart = clean_string($_GET['chart'] ?? null);
if ($requestedChart !== null && !in_array($requestedChart, $allowedCharts, true)) {
    json_error('NOT_FOUND', 'Unknown chart key.', 404);
}

// S-DASHBOARD-VIZ: ?charts=a,b,c — build/return ONLY those keys in the combined
// (keyed) payload. WHY: the redesigned dashboard shows 11 of the 17 datasets;
// fetching all of them rebuilt six unused charts on every cold cache. Same
// per-chart cache and same serve-time money redaction as the all-charts mode:
// a money key asked for by a non-financial role is simply omitted (not a 403 —
// one request serves every role). ?chart= (single mode) wins when both are
// sent, so existing callers behave exactly as before. maxLen 1000: the full
// allowlist joined is ~250 chars and clean_string() would otherwise truncate
// it into a bogus "unknown key".
$requestedList = ($requestedChart === null) ? clean_string($_GET['charts'] ?? null, 1000) : null;
$listedCharts  = null;
if ($requestedList !== null) {
    $listedCharts = array_values(array_unique(array_filter(
        array_map('trim', explode(',', $requestedList)),
        static fn(string $k): bool => $k !== ''
    )));
    $unknownCharts = array_values(array_diff($listedCharts, $allowedCharts));
    if ($listedCharts === [] || $unknownCharts !== []) {
        $msg = $listedCharts === []
            ? 'No chart keys given in charts.'
            : 'Unknown chart key(s): ' . implode(', ', array_slice($unknownCharts, 0, 10)) . '.';
        json_error('VALIDATION_ERROR', $msg, 422, ['fields' => ['charts' => $msg]]);
    }
}

// Build the list of charts to fetch
$chartsToFetch = ($requestedChart !== null) ? [$requestedChart] : ($listedCharts ?? $allowedCharts);

// S-LOCAL-DAY-TS: report_cache timestamps are UTC (cache_cleanup.php purges
// `expires_at < NOW()` on the +00:00 session — a Pacific wall-time expiry was
// deleted on the next run). $now is both the `expires_at > ?` cutoff and the
// written generated_at, so it moves to UTC together with $expiresAt.
$now          = ff_now_utc();
$cacheTtlMin  = 15;
$results      = [];

// Per-chart calc version: bumping it orphans a cached payload computed with
// superseded maths (utilization merge fix, CAD-canonical AR aging) instead
// of serving it for up to 15 more minutes.
// S-DASHBOARD-VIZ: top_customers gained `ytd_total`; a pre-change cached
// payload lacks it, so the new dashboard's share-of-total would read NaN.
// S-PERF-3: the four month-bucketed charts now anchor their month windows on
// the 1st (chart_months()); a payload cached on a 29th–31st by the old
// strtotime("±N months") loops carries duplicated/missing months.
$calcVersions = [
    'utilization_trend'     => '|fleet-util-v2',
    'ar_aging'              => '|ar-aging-cad-v2',
    'top_customers'         => '|ytd-total-v1',
    'revenue_forecast'      => '|month-anchor-v1',
    'lease_expiry_calendar' => '|month-anchor-v1',
    // S-PERF-3 (C2): payment_speed re-dated from updated_at to paid_date.
    'payment_speed'         => '|month-anchor-v1|paid-date-v1',
    'leases_trend'          => '|month-anchor-v1',
];

// report_type => parameters_hash for every chart this request serves.
$cacheKeys = [];
foreach ($chartsToFetch as $chartKey) {
    $cacheKeys['dashboard_chart_' . $chartKey] =
        hash('sha256', 'dashboard_chart_' . $chartKey . ($calcVersions[$chartKey] ?? ''));
}

// ── Cache hits: ONE lookup for every requested chart ──────────────────
// S-PERF-3: was one SELECT per chart (11 on the dashboard's cold AND warm
// path). The two IN lists can pair a type with another chart's hash in
// theory, so hits are matched on the exact (type, hash) pair below — a
// calc-version bump still orphans the old row exactly as before.
$ph   = implode(',', array_fill(0, count($cacheKeys), '?'));
$hits = [];
foreach (db_select(
    "SELECT report_type, parameters_hash, result_data FROM report_cache
      WHERE report_type IN ({$ph})
        AND parameters_hash IN ({$ph})
        AND expires_at > ?",
    [...array_keys($cacheKeys), ...array_values($cacheKeys), $now]
) as $row) {
    if (($cacheKeys[$row['report_type']] ?? null) === $row['parameters_hash']) {
        $hits[$row['report_type']] = $row['result_data'];
    }
}

// ── Build the misses; cache them with ONE write after the loop ────────
// WHY builders run outside any transaction: holding the REPLACE's row locks
// across ~50–100 ms of builds would block invalidate_analytics_cache()'s
// DELETE in concurrent write requests.
$fresh = [];
foreach ($chartsToFetch as $chartKey) {
    $type = 'dashboard_chart_' . $chartKey;
    if (isset($hits[$type])) {
        $results[$chartKey] = json_decode($hits[$type], true);
        continue;
    }

    $dataset = build_chart_dataset($chartKey);
    $fresh[] = [
        $type,
        $cacheKeys[$type],
        json_encode(['chart' => $chartKey]),
        json_encode($dataset),
        $now,
        ff_now_utc("+{$cacheTtlMin} minutes"), // UTC, lockstep with $now
        current_user_id(),
    ];
    $results[$chartKey] = $dataset;
}
chart_cache_write($fresh);

// Serve-time financial redaction. Revenue/AR charts expose dollar figures and
// are gated on payments:view; operational charts (fleet_status, utilization)
// stay visible to all staff. Per-chart cache is role-blind, so withhold on the
// way out rather than caching pre-redacted.
// S-DASH-CHART-REDACT: top_customers (YTD revenue per customer) and
// weekly_heatmap (daily revenue totals) are dollar datasets too — a new chart
// whose builder SUMs invoice amounts belongs in this list.
// S-DASHBOARD-VIZ: cash_flow (billed/collected), receivables (AR buckets) and
// overdue_customers (per-customer overdue balances) are money; fleet_mix and
// lease_flow are unit/lease COUNTS only and stay visible to every role.
$moneyCharts = [
    'revenue_trend', 'ar_aging', 'revenue_by_type', 'revenue_forecast', 'top_customers', 'weekly_heatmap',
    'cash_flow', 'receivables', 'overdue_customers',
];
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

// ?charts= made of money keys only, for a non-financial role, leaves nothing:
// keep `data` a JSON object ({} — not []) so the keyed contract holds.
if ($listedCharts !== null && $results === []) {
    json_success(new \stdClass());
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
        'cash_flow'              => chart_cash_flow(),
        'receivables'            => chart_receivables(),
        'overdue_customers'      => chart_overdue_customers(),
        'fleet_mix'              => chart_fleet_mix(),
        'lease_flow'             => chart_lease_flow(),
        default                  => [],
    };
}

/**
 * Cache freshly built chart datasets with ONE multi-row REPLACE (S-PERF-3).
 *
 * WHY one statement: every autocommit write is an fsync'd commit on prod
 * (sync_binlog=1, flush_log_at_trx_commit=1, ~6–7 ms each); a cold dashboard
 * load used to issue 11 of them back to back.
 *
 * WHY sorted by (report_type, parameters_hash): a multi-row REPLACE locks up to
 * 17 uq_report_hash entries in one statement. Taking them in index order means
 * two concurrent cold chart requests always lock in the same order and cannot
 * deadlock each other. It does NOT rule out a deadlock with
 * invalidate_analytics_cache()'s DELETE (every successful write request): that
 * DELETE's next-key/gap locks still cross the REPLACE's insert locks. Under a
 * synthetic stress loop (S-PERF-3) the REPLACE side lost ~7–10 per 120 cold
 * builds and is retried once here; the DELETE side lost ~10 per 7,000
 * invalidations (HEAD: ~4), which is invalidate_analytics_cache()'s to retry.
 *
 * WHY failures are swallowed: the cache is an optimisation. A failed write
 * only means the next request rebuilds — it must never turn a correctly built
 * charts response into a 500. It is logged, not rethrown.
 *
 * @param list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:?int}> $fresh
 *        rows of (report_type, parameters_hash, parameters, result_data,
 *        generated_at, expires_at, generated_by)
 * @return void
 */
function chart_cache_write(array $fresh): void
{
    if ($fresh === []) {
        return;
    }
    // strcmp, not <=>: a hash made only of digits would compare numerically.
    // Lowercase report types + lowercase hex order the same under strcmp as
    // under the column's utf8mb4_unicode_ci collation.
    usort($fresh, static fn(array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

    $sql = "REPLACE INTO report_cache
                (report_type, parameters_hash, parameters, result_data, generated_at, expires_at, generated_by)
            VALUES " . implode(', ', array_fill(0, count($fresh), '(?, ?, ?, ?, ?, ?, ?)'));
    $params = array_merge(...$fresh);
    // Read BEFORE the write: a deadlock rolls the server-side transaction
    // back, after which inTransaction() may no longer report the caller's one.
    $standalone = !db_pdo()->inTransaction();

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            db_execute($sql, $params);
            return;
        } catch (\PDOException $e) {
            $isDeadlock = (int) ($e->errorInfo[1] ?? 0) === 1213;
            // A deadlock inside a caller's transaction rolled that whole
            // transaction back — re-running just this statement would not
            // restore it, so only a standalone (autocommit) write is retried.
            if ($attempt === 1 && $isDeadlock && $standalone) {
                continue;
            }
            error_log('[dashboard/charts] report_cache write skipped (' . count($fresh)
                . ' charts, attempt ' . $attempt . '): ' . $e->getMessage());
            return;
        }
    }
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

    // S-DASHBOARD-VIZ: ytd_total = the same revenue across ALL customers, so the
    // dashboard can show each top customer's share. Same filters and the same
    // per-customer grouping as the top-5 query, with each group rounded to 2dp
    // BEFORE summing (bcmath) — the total is then exactly the sum of what the
    // chart would show per customer, so the five shares can never add past 100%.
    $allGroups = db_select(
        "SELECT SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END) AS total
           FROM invoices
          WHERE status NOT IN ('draft','void','written_off')
            AND deleted_at IS NULL
            AND YEAR(invoice_date) = ?
          GROUP BY customer_id, company_name_snapshot, customer_name_snapshot",
        [$currentYear]
    );
    $ytdTotal = '0.00';
    foreach ($allGroups as $g) {
        $ytdTotal = bcadd($ytdTotal, bcround((string) $g['total'], 2), 2);
    }

    return [
        'labels'    => $labels,
        'series'    => [['name' => 'Revenue', 'data' => $data]],
        'ytd_total' => (float) $ytdTotal,
    ];
}

/**
 * Leases trend — grouped bar: leases opened vs closed per month (last 12 months).
 *
 * Not in the redesigned dashboard's ?charts= list, but still served in the
 * all-charts payload and by ?chart=leases_trend, so it is kept (deleting it
 * would change both contracts).
 * opened = created_at in the month; "closed" = completed or cancelled with
 * updated_at in the month (a proxy, unchanged). Both are bucketed on the raw
 * stored DATETIME, exactly as the old per-month YEAR(col) = ? AND MONTH(col) = ?
 * COUNTs bucketed them.
 *
 * S-PERF-3: months come from chart_months() — anchored on the 1st; the old
 * strtotime("-N months") loop repeated/skipped months on the 29th–31st — and
 * two GROUP BY queries replace the 24 per-month COUNTs.
 */
function chart_leases_trend(): array
{
    $months = chart_months(-11, 0);
    // Half-open DATETIME window [first month 00:00, the month after the last
    // 00:00) — the same rows the per-month YEAR()/MONTH() = ? tests matched,
    // and sargable.
    $from = $months[0]['start'] . ' 00:00:00';
    $to   = chart_months(1, 1)[0]['start'] . ' 00:00:00';

    $openedBy = array_column(db_select(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
           FROM leases
          WHERE deleted_at IS NULL
            AND created_at >= ?
            AND created_at <  ?
          GROUP BY ym",
        [$from, $to]
    ), 'cnt', 'ym');

    // "Closed" = completed or cancelled in this month
    $closedBy = array_column(db_select(
        "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS ym, COUNT(*) AS cnt
           FROM leases
          WHERE status IN ('completed','cancelled')
            AND deleted_at IS NULL
            AND updated_at >= ?
            AND updated_at <  ?
          GROUP BY ym",
        [$from, $to]
    ), 'cnt', 'ym');

    $opened = [];
    $closed = [];
    foreach ($months as $m) {
        $opened[] = (int) ($openedBy[$m['ym']] ?? 0);
        $closed[] = (int) ($closedBy[$m['ym']] ?? 0);
    }

    return [
        'labels' => array_column($months, 'label'),
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
 * S-PERF-3: ONE fetch of every active lease overlapping the 6-month window
 * (was one query per month); each month then re-applies the exact per-month
 * overlap test (start_date <= month end AND (end_date IS NULL OR end_date >=
 * month start)) in PHP. Months come from chart_months(), anchored on the 1st —
 * the old strtotime("+N months") loop double-counted/skipped months in the
 * 6-month total on the 29th–31st.
 *
 * @return array{ labels: string[], series: array<array{name: string, data: float[]}> }
 */
function chart_revenue_forecast(): array
{
    $months = chart_months(0, 5);

    // Every active lease that overlaps ANY of the six months — a superset of
    // each month's set; the per-month test below narrows it exactly.
    $leases = db_select(
        "SELECT start_date, end_date, monthly_rate, weekly_rate, daily_rate
           FROM leases
          WHERE status = 'active'
            AND deleted_at IS NULL
            AND start_date <= ?
            AND (end_date IS NULL OR end_date >= ?)",
        [$months[5]['end'], $months[0]['start']]
    );

    $data = [];
    foreach ($months as $m) {
        // Accumulate projected income for this month using bcmath (D16 rule)
        $monthTotal = '0.00';
        foreach ($leases as $lease) {
            // Same overlap predicate the per-month SQL applied (DATE columns
            // arrive as Y-m-d strings, which compare chronologically).
            if ((string) $lease['start_date'] > $m['end']
                || ($lease['end_date'] !== null && (string) $lease['end_date'] < $m['start'])) {
                continue;
            }

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
                $contribution = bcmul($daily, (string) $m['days'], 2);
            }

            $monthTotal = bcadd($monthTotal, $contribution, 2);
        }

        $data[] = (float) $monthTotal;
    }

    return [
        'labels' => array_column($months, 'label'),
        'series' => [['name' => 'Projected Revenue', 'data' => $data]],
    ];
}

/**
 * Lease expiry calendar — count of active leases expiring in each of the next 12 months.
 *
 * Useful for forecasting renewal workload and identifying concentration risk
 * (many leases expiring in a single month).
 *
 * S-PERF-3: one GROUP BY over the 12-month end_date window (was 12 per-month
 * COUNTs); months from chart_months(), anchored on the 1st — the old
 * strtotime("+N months") loop showed a month twice and skipped another on the
 * 29th–31st.
 *
 * @return array{ labels: string[], series: array<array{name: string, data: int[]}> }
 */
function chart_lease_expiry_calendar(): array
{
    $months = chart_months(0, 11);

    $byYm = array_column(db_select(
        "SELECT DATE_FORMAT(end_date, '%Y-%m') AS ym, COUNT(*) AS cnt
           FROM leases
          WHERE status = 'active'
            AND deleted_at IS NULL
            AND end_date >= ?
            AND end_date <= ?
          GROUP BY ym",
        [$months[0]['start'], $months[11]['end']]
    ), 'cnt', 'ym');

    $data = [];
    foreach ($months as $m) {
        $data[] = (int) ($byYm[$m['ym']] ?? 0);
    }

    return [
        'labels' => array_column($months, 'label'),
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
 * Payment speed — average days between invoice_date and paid_date (last 12 months),
 * bucketed by the month of paid_date.
 *
 * WHY paid_date (S-PERF-3, was updated_at): invoices.updated_at is ON UPDATE
 * CURRENT_TIMESTAMP, so ANY later write to a paid invoice — a PDF (re)generation
 * stamping pdf_path, a delivery-method change, a recalc, a repair script —
 * re-dated the payment into the current month with inflated days-to-pay. Every
 * status→paid writer (payments create/allocate/reallocate, credit-note and
 * deposit apply, RoundingSettler, PaymentWebhookHandler) sets paid_date, and the
 * reversal paths clear it. paid_date is the local business date the payment was
 * RECORDED (not payments.payment_date), so a back-dated payment lands in the
 * month it was entered. No COALESCE(paid_date, updated_at) fallback on purpose:
 * it would bring the re-dating bug back for any row missing paid_date, which is
 * then simply left out.
 *
 * Months with no paid invoices yield null so the frontend can render a gap rather
 * than a misleading zero.
 *
 * S-PERF-3: one GROUP BY over the 12-month window (was 12 per-month AVGs);
 * months from chart_months(), anchored on the 1st — the old
 * strtotime("-N months") loop showed the current month twice (and skipped the
 * previous one) on the 29th–31st.
 *
 * @return array{ labels: string[], series: array<array{name: string, data: (float|null)[]}> }
 */
function chart_payment_speed(): array
{
    $months = chart_months(-11, 0);

    // AVG(DATEDIFF) across all invoices paid in each month. paid_date is a
    // DATE holding the company-local business day, so BETWEEN the first
    // month's 1st and the last month's last day is exact (and excludes NULLs).
    $byYm = array_column(db_select(
        "SELECT DATE_FORMAT(paid_date, '%Y-%m') AS ym,
                AVG(DATEDIFF(paid_date, invoice_date)) AS avg_days
           FROM invoices
          WHERE status = 'paid'
            AND deleted_at IS NULL
            AND paid_date BETWEEN ? AND ?
          GROUP BY ym",
        [$months[0]['start'], $months[11]['end']]
    ), 'avg_days', 'ym');

    $data = [];
    foreach ($months as $m) {
        $avg = $byYm[$m['ym']] ?? null;
        // WHY null guard: a month with no paid invoices has no row; use null in
        // data so ApexCharts renders a gap instead of a zero which would skew
        // the trend line.
        $data[] = $avg !== null ? round((float) $avg, 1) : null;
    }

    return [
        'labels' => array_column($months, 'label'),
        'series' => [['name' => 'Avg Days to Pay', 'data' => $data]],
    ];
}


// ============================================================
// S-DASHBOARD-VIZ BUILDERS
// Plain-shaped datasets for the redesigned dashboard cards (not ApexCharts
// labels/series pairs — the front-end maps them itself). Rules shared by all:
//   - Money is CAD-canonical: each row converts with ITS OWN frozen
//     exchange_rate_to_cad (USD; CAD passes through; a missing/zero USD rate
//     falls back to 1.0 exactly like ArAging / ReportBuilder::cad()), summed
//     in bcmath and emitted as floats rounded to 2dp.
//   - "Today" is ff_today(), the company-local business date. The PDO session
//     is UTC, so CURDATE() would roll to tomorrow at 5pm Pacific.
//   - A lease's effective end is actual_return_date, else end_date; NULL is
//     open-ended. Leases overlap (never cross-validated), so leases are
//     COUNTED — never assumed one per unit.
// ============================================================

/**
 * Calendar months relative to the current company-local month, oldest first.
 *
 * $from / $to are month offsets from the current month, inclusive: (-11, 0) is
 * the rolling 12 ending this month, (0, 5) this month and the next five,
 * (1, 1) just next month.
 *
 * S-PERF-3: the ONE month-window builder for every month-bucketed chart.
 * Anchored on the 1st so the arithmetic never repeats or skips a month
 * ("Oct 31 + 1 month" = Dec 1 in PHP; "Mar 31 − 1 month" = Mar 3), and on
 * ff_today() (the business timezone) so every chart shares one "current month".
 *
 * @param int $from first month offset (may be negative)
 * @param int $to   last month offset, >= $from
 * @return list<array{ym:string, label:string, start:string, end:string, days:int}>
 *         ym 'Y-m'; label 'M Y' (e.g. 'Oct 2025'); start/end = first/last
 *         calendar day of the month (Y-m-d); days = days in the month
 */
function chart_months(int $from, int $to): array
{
    $anchor = new DateTimeImmutable(substr(ff_today(), 0, 7) . '-01');
    $months = [];
    for ($i = $from; $i <= $to; $i++) {
        $m = $anchor->modify(($i >= 0 ? '+' : '') . $i . ' months');
        $months[] = [
            'ym'    => $m->format('Y-m'),
            'label' => $m->format('M Y'),
            'start' => $m->format('Y-m-01'),
            'end'   => $m->format('Y-m-t'),
            'days'  => (int) $m->format('t'),
        ];
    }
    return $months;
}

/**
 * The rolling 12 calendar months ending with the current company-local month,
 * oldest first.
 *
 * S-PERF-3: a wrapper of chart_months(-11, 0) (which also carries `days`).
 *
 * @return list<array{ym:string, label:string, start:string, end:string, days:int}>
 *         ym 'Y-m'; label 'M Y' (e.g. 'Oct 2025'); start/end = first/last
 *         calendar day of the month (Y-m-d)
 */
function chart_rolling_months(): array
{
    return chart_months(-11, 0);
}

/**
 * Fold rows grouped by (ym, currency, exchange_rate_to_cad) into CAD per month.
 *
 * WHY grouped by rate: SQL sums the native amounts per frozen rate (exact
 * DECIMAL), and the conversion + cross-rate sum happen here in bcmath, so no
 * money value ever passes through a float before the final JSON cast.
 *
 * @param list<array{ym:string, currency:string, exchange_rate_to_cad:?string, total:string}> $rows
 * @param list<array{ym:string}> $months chart_rolling_months()
 * @return array<string,string> ym => CAD 2dp string, one entry per month (zero-filled)
 */
function chart_cad_by_month(array $rows, array $months): array
{
    $byYm = array_fill_keys(array_column($months, 'ym'), '0');
    foreach ($rows as $r) {
        $ym = (string) $r['ym'];
        if (!isset($byYm[$ym])) {
            continue; // defensive — the query window already matches the months
        }
        $rate = '1';
        if ((string) $r['currency'] === 'USD'
            && $r['exchange_rate_to_cad'] !== null
            && bccomp((string) $r['exchange_rate_to_cad'], '0', 6) > 0) {
            $rate = (string) $r['exchange_rate_to_cad'];
        }
        $byYm[$ym] = bcadd($byYm[$ym], bcmul((string) $r['total'], $rate, 8), 8);
    }
    return array_map(static fn(string $v): string => bcround($v, 2), $byYm);
}

/**
 * Cash flow — billed vs collected per month, rolling 12 months (MONEY).
 *
 * billed    = invoice total_amount by invoice_date month, excluding draft,
 *             void and written_off (the revenue rule used everywhere).
 * collected = customer payments by payment_date month. Voided payments are
 *             SOFT-DELETED (payments/delete.php → FinancialActions::voidPayment),
 *             so deleted_at IS NULL is the void filter; status void / failed /
 *             returned / refunded never represent money kept, so they are out
 *             too (cleared + pending count — a pending cheque was received).
 *             payment_method 'account_credit' is a customer's existing credit
 *             being applied, not new cash, so it is excluded. Credit-note
 *             applications and deposits live in their own tables and never
 *             appear here.
 * Whole calendar months (the current month includes invoices dated later in
 * the month), matching revenue_trend's month bucketing.
 *
 * @return array{labels:string[], billed:float[], collected:float[],
 *               this_month:array{billed:float, collected:float},
 *               prev_month:array{billed:float, collected:float}}
 */
function chart_cash_flow(): array
{
    $months = chart_rolling_months();
    $from   = $months[0]['start'];
    $to     = $months[11]['end'];

    $billedRows = db_select(
        "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym, currency, exchange_rate_to_cad,
                SUM(total_amount) AS total
           FROM invoices
          WHERE status NOT IN ('draft','void','written_off')
            AND deleted_at IS NULL
            AND invoice_date >= ?
            AND invoice_date <= ?
          GROUP BY ym, currency, exchange_rate_to_cad",
        [$from, $to]
    );

    $collectedRows = db_select(
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, currency, exchange_rate_to_cad,
                SUM(amount) AS total
           FROM payments
          WHERE deleted_at IS NULL
            AND status NOT IN ('void','failed','returned','refunded')
            AND payment_method <> 'account_credit'
            AND payment_date >= ?
            AND payment_date <= ?
          GROUP BY ym, currency, exchange_rate_to_cad",
        [$from, $to]
    );

    $billed    = array_values(chart_cad_by_month($billedRows, $months));
    $collected = array_values(chart_cad_by_month($collectedRows, $months));

    return [
        'labels'     => array_column($months, 'label'),
        'billed'     => array_map('floatval', $billed),
        'collected'  => array_map('floatval', $collected),
        'this_month' => ['billed' => (float) $billed[11], 'collected' => (float) $collected[11]],
        'prev_month' => ['billed' => (float) $billed[10], 'collected' => (float) $collected[10]],
    ];
}

/**
 * AR aging as at company-local today, computed ONCE per request.
 *
 * receivables and overdue_customers both read it, so in a combined payload the
 * two cards come from the same per-invoice rows and can never disagree (and
 * the aging query runs once, not twice).
 *
 * @return array ArAging::asOf() result
 */
function chart_ar_aging_today(): array
{
    static $aging = null;
    return $aging ??= \FleetForge\Reports\ArAging::asOf(ff_today());
}

/**
 * Receivables — total AR + 5 aging buckets with invoice counts (MONEY).
 *
 * Straight from lib/Reports/ArAging.php as of ff_today(), so the figures agree
 * with Accounting → AR Aging to the cent (CAD-canonical, as-of balances).
 * ArAging returns bucket totals but not counts; the counts come from its
 * per-invoice rows (each carries the bucket it was summed into), so every
 * count matches the amount beside it. customer_count = ArAging's customer
 * groups (invoices with no linked customer form one group).
 *
 * @return array{total:float, invoice_count:int, customer_count:int,
 *               buckets:list<array{key:string, label:string, amount:float, count:int}>}
 */
function chart_receivables(): array
{
    $aging = chart_ar_aging_today();

    // ArAging bucket key => [dashboard key, plain-English label], display order.
    $defs = [
        'current'      => ['current', 'Not due yet'],
        'days_1_30'    => ['d1_30',   '1–30 days late'],
        'days_31_60'   => ['d31_60',  '31–60 days late'],
        'days_61_90'   => ['d61_90',  '61–90 days late'],
        'days_90_plus' => ['d90',     '90+ days late'],
    ];

    $counts = array_fill_keys(array_keys($defs), 0);
    foreach ($aging['invoices'] as $inv) {
        $counts[$inv['bucket']] = ($counts[$inv['bucket']] ?? 0) + 1;
    }

    $buckets = [];
    foreach ($defs as $agingKey => [$key, $label]) {
        $buckets[] = [
            'key'    => $key,
            'label'  => $label,
            'amount' => (float) $aging['totals'][$agingKey],
            'count'  => $counts[$agingKey],
        ];
    }

    return [
        'total'          => (float) $aging['totals']['total'],
        'invoice_count'  => (int) $aging['invoice_count'],
        'customer_count' => count($aging['customers']),
        'buckets'        => $buckets,
    ];
}

/**
 * Overdue customers — top 6 customers by overdue balance, largest first (MONEY).
 *
 * Overdue = an ArAging row (outstanding: sent / partially_paid / overdue with a
 * balance, as of ff_today()) whose due_date is before today — ArAging's
 * days_past_due > 0 is exactly `due_date < ff_today()`. Reading the same rows
 * as `receivables` means total_overdue always equals that card's four "late"
 * buckets. oldest_days = days past due of the customer's oldest overdue
 * invoice. Invoices with no linked customer are grouped by their snapshot name
 * and reported with customer_id 0 (there is no record to link to).
 *
 * @return array{rows:list<array{customer_id:int, name:string, amount:float,
 *               invoice_count:int, oldest_days:int}>, total_overdue:float}
 */
function chart_overdue_customers(): array
{
    $aging        = chart_ar_aging_today();
    $groups       = [];
    $totalOverdue = '0.00';

    foreach ($aging['invoices'] as $inv) {
        $days = (int) $inv['days_past_due'];
        if ($days <= 0) {
            continue; // not due yet — 'current', not overdue
        }
        $cid = $inv['customer_id'];
        $gk  = $cid !== null ? 'c' . $cid : 'n' . $inv['company_name'];
        if (!isset($groups[$gk])) {
            $groups[$gk] = [
                'customer_id'   => (int) ($cid ?? 0),
                'name'          => (string) $inv['company_name'],
                'amount'        => '0.00',
                'invoice_count' => 0,
                'oldest_days'   => 0,
            ];
        }
        // balance_due on an ArAging row is already CAD (its own frozen rate).
        $groups[$gk]['amount']        = bcadd($groups[$gk]['amount'], (string) $inv['balance_due'], 2);
        $groups[$gk]['invoice_count'] += 1;
        $groups[$gk]['oldest_days']   = max($groups[$gk]['oldest_days'], $days);
        $totalOverdue                 = bcadd($totalOverdue, (string) $inv['balance_due'], 2);
    }

    $rows = array_values($groups);
    // Largest balance first; ties → oldest debt first, then name (stable output).
    usort($rows, static fn(array $a, array $b): int =>
        bccomp($b['amount'], $a['amount'], 2)
        ?: ($b['oldest_days'] <=> $a['oldest_days'])
        ?: strcasecmp($a['name'], $b['name']));

    $rows = array_map(static function (array $r): array {
        $r['amount'] = (float) $r['amount'];
        return $r;
    }, array_slice($rows, 0, 6));

    return [
        'rows'          => $rows,
        'total_overdue' => (float) $totalOverdue,
    ];
}

/**
 * Fleet mix — unit counts by status plus a per-category split, right now.
 *
 * Same universe as chart_fleet_status (not soft-deleted, not decommissioned);
 * per-category grouping on equipment_templates.category, the same key
 * chart_occupancy_by_type groups on (the retained category "mirror" — Combo is
 * its own line). The label prefers the operator-editable
 * equipment_categories.label for that slug, else the humanised slug (the
 * occupancy chart's format). Counts come from the UNIT status so the per-type
 * split always adds back up to the status totals (on_lease + available +
 * other == total, per type and overall).
 *
 * @return array{total:int, statuses:list<array{key:string, label:string, count:int}>,
 *               types:list<array{label:string, total:int, on_lease:int,
 *               available:int, other:int, pct_on_lease:float}>}
 */
function chart_fleet_mix(): array
{
    // LEFT JOINs: template_id is NOT NULL with an FK, so every unit resolves —
    // but a join must never be what silently drops a unit from the total.
    $rows = db_select(
        "SELECT eu.status,
                COALESCE(et.category, 'unknown') AS category,
                ec.label                         AS category_label,
                COUNT(*)                         AS cnt
           FROM equipment_units eu
           LEFT JOIN equipment_templates et  ON et.id   = eu.template_id
           LEFT JOIN equipment_categories ec ON ec.slug = et.category
          WHERE eu.deleted_at IS NULL
            AND eu.status <> 'decommissioned'
          GROUP BY eu.status, et.category, ec.label"
    );

    $statusDefs = [
        'on_lease'    => 'On lease',
        'available'   => 'Available',
        'reserved'    => 'Reserved',
        'maintenance' => 'In the shop',
        'inactive'    => 'Inactive',
    ];
    $statusCounts = array_fill_keys(array_keys($statusDefs), 0);
    $types        = [];

    foreach ($rows as $r) {
        $status = (string) $r['status'];
        // The status ENUM is exactly these five once decommissioned is filtered.
        if (!isset($statusCounts[$status])) {
            continue;
        }
        $n = (int) $r['cnt'];
        $statusCounts[$status] += $n;

        $slug = (string) $r['category'];
        if (!isset($types[$slug])) {
            $types[$slug] = [
                'label'     => $r['category_label'] !== null && $r['category_label'] !== ''
                    ? (string) $r['category_label']
                    : ucwords(str_replace('_', ' ', $slug)),
                'total'     => 0,
                'on_lease'  => 0,
                'available' => 0,
                'other'     => 0,
            ];
        }
        $types[$slug]['total'] += $n;
        // reserved / maintenance / inactive all fold into "other".
        $col = match ($status) {
            'on_lease', 'available' => $status,
            default                 => 'other',
        };
        $types[$slug][$col] += $n;
    }

    $typeList = [];
    foreach ($types as $t) {
        $t['pct_on_lease'] = (float) \FleetForge\Reports\ReportBuilder::pct((string) $t['on_lease'], (string) $t['total'], 1);
        $typeList[] = $t;
    }
    usort($typeList, static fn(array $a, array $b): int =>
        ($b['total'] <=> $a['total']) ?: strcasecmp($a['label'], $b['label']));

    $statuses = [];
    foreach ($statusDefs as $key => $label) {
        $statuses[] = ['key' => $key, 'label' => $label, 'count' => $statusCounts[$key]];
    }

    return [
        'total'    => array_sum($statusCounts),
        'statuses' => $statuses,
        'types'    => $typeList,
    ];
}

/**
 * Lease flow — leases opened, closed and on rent per month, rolling 12 months.
 *
 * STATUS CHOICES (leases.status is pending / active / completed / cancelled):
 *   - Only active + completed leases count — the leases that actually went on
 *     rent (the same allowlist as FleetUtilization and the Days-on-Rent panel).
 *     cancelled never ran; pending has not been handed over yet, even when its
 *     start_date has passed.
 *   - opened  = start_date in the month, and not after today (a future start
 *               has not opened yet).
 *   - closed  = COMPLETED leases whose effective end (actual_return_date, else
 *               end_date) falls in the month and is not after today. An active
 *               lease past its end_date has not been closed (it is late back),
 *               and a reopened lease is active again, so status is the gate.
 *   - on_rent = leases out on the LAST day of the month (the current month:
 *               ff_today()): start_date <= that day AND (effective end IS NULL
 *               OR effective end >= that day). A completed lease with neither
 *               return nor end date is closed but undated; it is left out
 *               rather than counted as out forever.
 * Leases are counted, not units: overlapping leases on one unit count twice,
 * exactly as they appear on the Leases page.
 *
 * @return array{labels:string[], opened:int[], closed:int[], on_rent:int[]}
 */
function chart_lease_flow(): array
{
    $months = chart_rolling_months();
    $today  = ff_today();
    $from   = $months[0]['start'];

    $openRows = db_select(
        "SELECT DATE_FORMAT(start_date, '%Y-%m') AS ym, COUNT(*) AS cnt
           FROM leases
          WHERE deleted_at IS NULL
            AND status IN ('active','completed')
            AND start_date >= ?
            AND start_date <= ?
          GROUP BY ym",
        [$from, $today]
    );

    $closeRows = db_select(
        "SELECT DATE_FORMAT(COALESCE(actual_return_date, end_date), '%Y-%m') AS ym, COUNT(*) AS cnt
           FROM leases
          WHERE deleted_at IS NULL
            AND status = 'completed'
            AND COALESCE(actual_return_date, end_date) >= ?
            AND COALESCE(actual_return_date, end_date) <= ?
          GROUP BY ym",
        [$from, $today]
    );

    // Every lease that could be out on any of the 12 as-of days: started by
    // today, and not ended before the first month-end. Counted per month in PHP
    // (one query instead of twelve).
    $spells = db_select(
        "SELECT start_date, COALESCE(actual_return_date, end_date) AS eff_end
           FROM leases
          WHERE deleted_at IS NULL
            AND status IN ('active','completed')
            AND NOT (status = 'completed' AND actual_return_date IS NULL AND end_date IS NULL)
            AND start_date <= ?
            AND (COALESCE(actual_return_date, end_date) IS NULL
                 OR COALESCE(actual_return_date, end_date) >= ?)",
        [$today, $months[0]['end']]
    );

    $openedBy = array_column($openRows, 'cnt', 'ym');
    $closedBy = array_column($closeRows, 'cnt', 'ym');

    $opened = [];
    $closed = [];
    $onRent = [];
    foreach ($months as $i => $m) {
        $opened[] = (int) ($openedBy[$m['ym']] ?? 0);
        $closed[] = (int) ($closedBy[$m['ym']] ?? 0);

        // As-of day: the month's last day; for the current month, today.
        $asOf = ($i === count($months) - 1) ? $today : $m['end'];
        $n    = 0;
        foreach ($spells as $s) {
            // Y-m-d strings compare chronologically.
            if ($s['start_date'] <= $asOf && ($s['eff_end'] === null || $s['eff_end'] >= $asOf)) {
                $n++;
            }
        }
        $onRent[] = $n;
    }

    return [
        'labels'  => array_column($months, 'label'),
        'opened'  => $opened,
        'closed'  => $closed,
        'on_rent' => $onRent,
    ];
}
