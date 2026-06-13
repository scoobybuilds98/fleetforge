<?php
declare(strict_types=1);
/**
 * api/v1/reports/customer.php
 *
 * Customer Reports API — lifetime value, payment behavior, new vs returning,
 * lease frequency, and credit-note impact.
 *
 * LTV view returns ALL-TIME totals per customer (not just the selected period)
 * because lifetime value is inherently a cumulative metric.  A `period_revenue`
 * field is also included showing just the period-scoped revenue for comparison.
 *
 * Required by: app/admin/reports/index.php (Customer tab)
 * Requires: api/bootstrap.php, lib/Reports/ReportBuilder.php (autoloaded)
 *
 * GET params:
 *   preset    : date preset slug (default 'this_month')
 *   date_from : Y-m-d (used when preset = 'custom')
 *   date_to   : Y-m-d (used when preset = 'custom')
 *   view      : ltv | payment_behavior | new_returning | frequency | credit_notes
 *   format    : json | csv
 *
 * Spec ref: §7.10 Reports, Reports Charts #9–#10, PROGRESS.md S021
 * Decisions: D16 (bcmath), D5 (soft-delete both JOIN sides)
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
$allowedViews = ['ltv', 'payment_behavior', 'new_returning', 'frequency', 'credit_notes'];
$view   = clean_string($_GET['view']   ?? 'ltv') ?? 'ltv';
$format = clean_string($_GET['format'] ?? 'json') ?? 'json';

if (!in_array($view, $allowedViews, true))       $view   = 'ltv';
if (!in_array($format, ['json', 'csv'], true))   $format = 'json';

// ── Cache check ──────────────────────────────────────────────────────────────
$cacheParams = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'view' => $view];
$cacheType   = 'customer_' . $view;

if ($format === 'json') {
    $cached = ReportBuilder::getCached($cacheType, $cacheParams);
    if ($cached) { $cached['cached'] = true; json_success($cached); }
}

// ── Shared KPIs ──────────────────────────────────────────────────────────────
$kpiRow = db_row(
    "SELECT
        COUNT(DISTINCT i.customer_id)                                       AS unique_customers,
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END)        AS active_customers,
        COALESCE(SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0)                                    AS total_revenue,
        COALESCE(AVG(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0)                                    AS avg_invoice_value,
        COUNT(*)                                                             AS invoice_count,
        COUNT(DISTINCT i.lease_id)                                           AS lease_count
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.deleted_at IS NULL
       AND i.status NOT IN ('draft','void','written_off')
       AND i.invoice_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

// Avg days to pay (for customers with paid invoices in the period)
$payRow = db_row(
    "SELECT COALESCE(AVG(DATEDIFF(i.paid_date, i.due_date)), 0) AS avg_days_to_pay
     FROM invoices i
     WHERE i.deleted_at IS NULL
       AND i.status = 'paid'
       AND i.paid_date IS NOT NULL
       AND i.invoice_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

$kpis = [
    'unique_customers'  => (int)   $kpiRow['unique_customers'],
    'active_customers'  => (int)   $kpiRow['active_customers'],
    'total_revenue'     => bcround((string) ($kpiRow['total_revenue']    ?? '0'), 2),
    'avg_invoice_value' => bcround((string) ($kpiRow['avg_invoice_value'] ?? '0'), 2),
    'invoice_count'     => (int)   $kpiRow['invoice_count'],
    'lease_count'       => (int)   $kpiRow['lease_count'],
    'avg_days_to_pay'   => bcround((string) ($payRow['avg_days_to_pay']   ?? '0'), 1),
];

// ── View-specific data ───────────────────────────────────────────────────────
$chartData = [];
$table     = [];
$totals    = [];

switch ($view) {

    // ── Customer lifetime value ranking ───────────────────────────────────────
    // All-time revenue per customer; period_revenue shows just the selected range.
    case 'ltv':
        $rows = db_select(
            "SELECT
                c.id                              AS customer_id,
                c.company_name,
                c.status                          AS customer_status,
                c.created_at                      AS customer_since,
                -- All-time totals
                COALESCE(all_inv.total_invoiced,  0) AS lifetime_revenue,
                COALESCE(all_inv.total_paid,      0) AS lifetime_collected,
                COALESCE(all_inv.total_balance,   0) AS lifetime_outstanding,
                COALESCE(all_inv.invoice_count,   0) AS total_invoices,
                COALESCE(all_inv.lease_count,     0) AS total_leases,
                all_inv.first_invoice_date,
                all_inv.last_invoice_date,
                -- Period-scoped revenue (for period comparison)
                COALESCE(period_inv.period_revenue, 0) AS period_revenue,
                COALESCE(period_inv.period_invoices, 0) AS period_invoices,
                -- Credit notes issued (all time)
                COALESCE(cn_agg.credit_issued, 0) AS credit_issued
             FROM customers c
             LEFT JOIN (
                 SELECT customer_id,
                        SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END)   AS total_invoiced,
                        SUM(CASE WHEN currency='USD' THEN amount_paid*COALESCE(exchange_rate_to_cad,1) ELSE amount_paid END)    AS total_paid,
                        SUM(CASE WHEN currency='USD' THEN balance_due*COALESCE(exchange_rate_to_cad,1) ELSE balance_due END)    AS total_balance,
                        COUNT(*)            AS invoice_count,
                        COUNT(DISTINCT lease_id) AS lease_count,
                        MIN(invoice_date)   AS first_invoice_date,
                        MAX(invoice_date)   AS last_invoice_date
                 FROM invoices
                 WHERE deleted_at IS NULL AND status NOT IN ('draft','void','written_off')
                 GROUP BY customer_id
             ) all_inv ON all_inv.customer_id = c.id
             LEFT JOIN (
                 SELECT customer_id,
                        SUM(CASE WHEN currency='USD' THEN total_amount*COALESCE(exchange_rate_to_cad,1) ELSE total_amount END) AS period_revenue,
                        COUNT(*) AS period_invoices
                 FROM invoices
                 WHERE deleted_at IS NULL
                   AND status NOT IN ('draft','void','written_off')
                   AND invoice_date BETWEEN ? AND ?
                 GROUP BY customer_id
             ) period_inv ON period_inv.customer_id = c.id
             LEFT JOIN (
                 SELECT customer_id, SUM(amount) AS credit_issued
                 FROM credit_notes
                 WHERE deleted_at IS NULL
                 GROUP BY customer_id
             ) cn_agg ON cn_agg.customer_id = c.id
             WHERE c.deleted_at IS NULL
               AND all_inv.total_invoiced > 0
             ORDER BY lifetime_revenue DESC
             LIMIT 50",
            [$dateFrom, $dateTo]
        );

        $top15 = array_slice($rows, 0, 15);
        $chartData = [
            'categories'       => array_column($top15, 'company_name'),
            'lifetime_revenue' => array_map(fn($r) => (float) $r['lifetime_revenue'], $top15),
            'period_revenue'   => array_map(fn($r) => (float) $r['period_revenue'],   $top15),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'customer_ltv_' . $dateFrom . '_to_' . $dateTo,
                ['Customer', 'Status', 'Since', 'Lifetime Revenue', 'Lifetime Collected', 'Outstanding', 'Total Invoices', 'Total Leases', 'Period Revenue', 'Credit Issued', 'First Invoice', 'Last Invoice'],
                array_map(fn($r) => [
                    $r['company_name'], $r['customer_status'],
                    substr((string) ($r['customer_since'] ?? ''), 0, 10),
                    ReportBuilder::csvMoney($r['lifetime_revenue']),
                    ReportBuilder::csvMoney($r['lifetime_collected']),
                    ReportBuilder::csvMoney($r['lifetime_outstanding']),
                    $r['total_invoices'], $r['total_leases'],
                    ReportBuilder::csvMoney($r['period_revenue']),
                    ReportBuilder::csvMoney($r['credit_issued']),
                    $r['first_invoice_date'] ?? '',
                    $r['last_invoice_date']  ?? '',
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [];
        break;

    // ── Payment behavior (average days to pay per customer) ───────────────────
    // Days to pay: DATEDIFF(paid_date, due_date). Negative = paid early.
    case 'payment_behavior':
        $rows = db_select(
            "SELECT
                i.customer_id,
                ANY_VALUE(COALESCE(c.company_name, i.company_name_snapshot, 'Unknown')) AS company_name,
                COUNT(*)                                               AS paid_invoice_count,
                ROUND(AVG(DATEDIFF(i.paid_date, i.due_date)), 1)      AS avg_days_to_pay,
                MIN(DATEDIFF(i.paid_date, i.due_date))                AS min_days,
                MAX(DATEDIFF(i.paid_date, i.due_date))                AS max_days,
                COUNT(CASE WHEN DATEDIFF(i.paid_date, i.due_date) > 0  THEN 1 END) AS late_count,
                COUNT(CASE WHEN DATEDIFF(i.paid_date, i.due_date) <= 0 THEN 1 END) AS on_time_count,
                COALESCE(SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0)                      AS total_paid_amount
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
             WHERE i.deleted_at IS NULL
               AND i.status = 'paid'
               AND i.paid_date IS NOT NULL
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY i.customer_id
             HAVING paid_invoice_count >= 1
             ORDER BY avg_days_to_pay DESC
             LIMIT 30",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'categories'      => array_column($rows, 'company_name'),
            'avg_days_to_pay' => array_map(fn($r) => (float) $r['avg_days_to_pay'], $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'customer_payment_behavior_' . $dateFrom . '_to_' . $dateTo,
                ['Customer', 'Paid Invoices', 'Avg Days to Pay', 'Min Days', 'Max Days', 'On Time', 'Late', 'Total Amount Paid'],
                array_map(fn($r) => [
                    $r['company_name'], $r['paid_invoice_count'],
                    $r['avg_days_to_pay'], $r['min_days'], $r['max_days'],
                    $r['on_time_count'], $r['late_count'],
                    ReportBuilder::csvMoney($r['total_paid_amount']),
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [];
        break;

    // ── New vs returning revenue by month ─────────────────────────────────────
    // "New" customer: their first-ever invoice falls within the selected period.
    // "Returning" customer: had at least one invoice before the period start.
    case 'new_returning':
        $rows = db_select(
            "SELECT
                DATE_FORMAT(i.invoice_date, '%Y-%m')     AS period,
                DATE_FORMAT(i.invoice_date, '%b %Y')     AS period_label,
                COALESCE(SUM(CASE WHEN first_inv.first_date >= ? THEN (CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END) ELSE 0 END), 0) AS new_revenue,
                COALESCE(SUM(CASE WHEN first_inv.first_date <  ? THEN (CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END) ELSE 0 END), 0) AS returning_revenue,
                COUNT(DISTINCT CASE WHEN first_inv.first_date >= ? THEN i.customer_id END) AS new_customers,
                COUNT(DISTINCT CASE WHEN first_inv.first_date <  ? THEN i.customer_id END) AS returning_customers,
                COALESCE(SUM(CASE WHEN i.currency='USD' THEN i.total_amount*COALESCE(i.exchange_rate_to_cad,1) ELSE i.total_amount END), 0) AS total_revenue
             FROM invoices i
             JOIN (
                 SELECT customer_id, MIN(invoice_date) AS first_date
                 FROM invoices
                 WHERE deleted_at IS NULL AND status NOT IN ('draft','void','written_off')
                 GROUP BY customer_id
             ) first_inv ON first_inv.customer_id = i.customer_id
             WHERE i.deleted_at IS NULL
               AND i.status NOT IN ('draft','void','written_off')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), DATE_FORMAT(i.invoice_date, '%b %Y')
             ORDER BY period ASC",
            [$dateFrom, $dateFrom, $dateFrom, $dateFrom, $dateFrom, $dateTo]
        );

        $chartData = [
            'categories'        => array_column($rows, 'period_label'),
            'new_revenue'       => array_map(fn($r) => (float) $r['new_revenue'],        $rows),
            'returning_revenue' => array_map(fn($r) => (float) $r['returning_revenue'],  $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'new_vs_returning_' . $dateFrom . '_to_' . $dateTo,
                ['Period', 'New Revenue', 'Returning Revenue', 'Total Revenue', 'New Customers', 'Returning Customers'],
                array_map(fn($r) => [
                    $r['period_label'],
                    ReportBuilder::csvMoney($r['new_revenue']),
                    ReportBuilder::csvMoney($r['returning_revenue']),
                    ReportBuilder::csvMoney($r['total_revenue']),
                    $r['new_customers'],
                    $r['returning_customers'],
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [
            'new_revenue'       => bcround((string) array_sum(array_column($rows, 'new_revenue')),       2),
            'returning_revenue' => bcround((string) array_sum(array_column($rows, 'returning_revenue')), 2),
        ];
        break;

    // ── Lease frequency ranking ───────────────────────────────────────────────
    case 'frequency':
        $rows = db_select(
            "SELECT
                l.customer_id,
                COALESCE(c.company_name, 'Unknown')     AS company_name,
                c.status                                AS customer_status,
                COUNT(*)                                AS lease_count,
                COUNT(CASE WHEN l.status = 'active'    THEN 1 END) AS active_leases,
                COUNT(CASE WHEN l.status = 'completed' THEN 1 END) AS completed_leases,
                MIN(l.start_date)                       AS first_lease_date,
                MAX(l.start_date)                       AS last_lease_date,
                COALESCE(AVG(
                    DATEDIFF(
                        COALESCE(l.actual_return_date, CURDATE()),
                        l.start_date
                    ) + 1
                ), 0)                                   AS avg_lease_days,
                COUNT(DISTINCT et.category)             AS equipment_categories_used
             FROM leases l
             LEFT JOIN customers c        ON c.id  = l.customer_id       AND c.deleted_at IS NULL
             LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
             LEFT JOIN equipment_templates et ON et.id = eu.template_id  AND et.deleted_at IS NULL
             WHERE l.deleted_at IS NULL
               AND l.status != 'cancelled'
               AND l.start_date BETWEEN ? AND ?
             GROUP BY l.customer_id, company_name, c.status
             ORDER BY lease_count DESC, company_name ASC
             LIMIT 50",
            [$dateFrom, $dateTo]
        );

        $top15 = array_slice($rows, 0, 15);
        $chartData = [
            'categories'  => array_column($top15, 'company_name'),
            'lease_count' => array_map(fn($r) => (int) $r['lease_count'], $top15),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'customer_lease_frequency_' . $dateFrom . '_to_' . $dateTo,
                ['Customer', 'Status', 'Leases (period)', 'Active Now', 'Completed', 'Avg Lease Days', 'Equipment Types', 'First Lease', 'Last Lease'],
                array_map(fn($r) => [
                    $r['company_name'], $r['customer_status'] ?? '',
                    $r['lease_count'], $r['active_leases'], $r['completed_leases'],
                    number_format((float) $r['avg_lease_days'], 1),
                    $r['equipment_categories_used'],
                    $r['first_lease_date'] ?? '', $r['last_lease_date'] ?? '',
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [];
        break;

    // ── Credit note impact ────────────────────────────────────────────────────
    case 'credit_notes':
        $rows = db_select(
            "SELECT
                cn.customer_id,
                ANY_VALUE(COALESCE(c.company_name, 'Unknown'))    AS company_name,
                ANY_VALUE(c.status)                               AS customer_status,
                COUNT(*)                                          AS credit_note_count,
                COALESCE(SUM(cn.amount),                     0)  AS total_issued,
                COALESCE(SUM(cn.amount - cn.amount_remaining), 0) AS total_used,
                COALESCE(SUM(cn.amount_remaining),           0)  AS total_remaining,
                COALESCE(AVG(cn.amount),                     0)  AS avg_credit_amount,
                COUNT(CASE WHEN cn.status = 'active'         THEN 1 END) AS active_credits,
                COUNT(CASE WHEN cn.status = 'fully_used'     THEN 1 END) AS fully_used,
                COUNT(CASE WHEN cn.status = 'expired'        THEN 1 END) AS expired_credits
             FROM credit_notes cn
             LEFT JOIN customers c ON c.id = cn.customer_id AND c.deleted_at IS NULL
             WHERE cn.deleted_at IS NULL
               AND cn.created_at BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
             GROUP BY cn.customer_id
             ORDER BY total_issued DESC
             LIMIT 50",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'categories'    => array_column($rows, 'company_name'),
            'total_issued'  => array_map(fn($r) => (float) $r['total_issued'],    $rows),
            'total_used'    => array_map(fn($r) => (float) $r['total_used'],      $rows),
            'total_remaining' => array_map(fn($r) => (float) $r['total_remaining'], $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'customer_credit_notes_' . $dateFrom . '_to_' . $dateTo,
                ['Customer', 'Status', 'Credit Notes', 'Total Issued', 'Total Used', 'Remaining Balance', 'Avg Amount', 'Active', 'Fully Used', 'Expired'],
                array_map(fn($r) => [
                    $r['company_name'], $r['customer_status'] ?? '',
                    $r['credit_note_count'],
                    ReportBuilder::csvMoney($r['total_issued']),
                    ReportBuilder::csvMoney($r['total_used']),
                    ReportBuilder::csvMoney($r['total_remaining']),
                    ReportBuilder::csvMoney($r['avg_credit_amount']),
                    $r['active_credits'], $r['fully_used'], $r['expired_credits'],
                ], $rows)
            );
        }
        $table  = $rows;
        $totals = [
            'total_issued'    => bcround((string) array_sum(array_column($rows, 'total_issued')),    2),
            'total_remaining' => bcround((string) array_sum(array_column($rows, 'total_remaining')), 2),
        ];
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
