<?php
declare(strict_types=1);
/**
 * api/v1/reports/revenue.php
 *
 * Financial Reports API — revenue, AR aging, collection rate, invoice status.
 * All views share the same date-range and are cached for 15 minutes.
 * CSV export exits the request with raw file output (no JSON wrapper).
 *
 * Required by: app/admin/reports/index.php (Financial tab)
 * Requires: api/bootstrap.php, lib/Reports/ReportBuilder.php (autoloaded)
 *
 * GET params:
 *   preset      : date preset slug (default 'this_month')
 *   date_from   : Y-m-d (required when preset = 'custom')
 *   date_to     : Y-m-d (required when preset = 'custom')
 *   view        : period | customer | equipment_type | ar_aging | collection | status
 *   format      : json | csv  (default json)
 *
 * Returns (json): { success, data: { kpis, chart_data, table, totals, date_from, date_to, view, cached } }
 * Returns (csv):  Content-Disposition: attachment download
 *
 * Spec ref: §7.10 Reports, Reports Charts #1–#4, PROGRESS.md S021
 * Decisions: D5 (soft-delete both JOIN sides), D16 (bcmath), D19 (no write ops here)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Reports\ReportBuilder;

require_method('GET');
require_auth_api();
require_permission('reports', 'view');

// ── Date range resolution ────────────────────────────────────────────────────
$preset     = clean_string($_GET['preset'] ?? 'this_month') ?? 'this_month';
$customFrom = clean_date($_GET['date_from'] ?? null);
$customTo   = clean_date($_GET['date_to']   ?? null);

[$dateFrom, $dateTo] = ReportBuilder::resolvePreset($preset, $customFrom, $customTo);
[$dateFrom, $dateTo] = ReportBuilder::clampDates($dateFrom, $dateTo);

// ── Input validation ─────────────────────────────────────────────────────────
$allowedViews = ['period', 'customer', 'equipment_type', 'ar_aging', 'collection', 'status'];
$view  = clean_string($_GET['view'] ?? 'period') ?? 'period';
$format = clean_string($_GET['format'] ?? 'json') ?? 'json';
if (!in_array($view, $allowedViews, true)) {
    $view = 'period';
}
if (!in_array($format, ['json', 'csv'], true)) {
    $format = 'json';
}

// ── Cache check (JSON only — CSV always regenerates for freshness) ───────────
$cacheParams = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'view' => $view];
$cacheType   = 'revenue_' . $view;

if ($format === 'json') {
    $cached = ReportBuilder::getCached($cacheType, $cacheParams);
    if ($cached) {
        $cached['cached'] = true;
        json_success($cached);
    }
}

// ── Shared KPIs (returned with every view, same date range) ─────────────────
// These tiles appear at the top of the Financial tab regardless of sub-tab.
$kpiRow = db_row(
    "SELECT
        COUNT(*)                                           AS invoice_count,
        COALESCE(SUM(i.total_amount),             0)      AS gross_revenue,
        COALESCE(SUM(i.subtotal_after_discount),  0)      AS net_revenue,
        COALESCE(SUM(i.tax_total),                0)      AS total_tax,
        COALESCE(SUM(i.amount_paid),              0)      AS total_collected,
        COALESCE(SUM(i.balance_due),              0)      AS total_outstanding,
        COALESCE(AVG(i.total_amount),             0)      AS avg_invoice_value,
        COALESCE(SUM(i.discount_amount),          0)      AS total_discounts,
        COUNT(DISTINCT i.customer_id)                     AS unique_customers,
        COUNT(CASE WHEN i.status = 'paid' THEN 1 END)     AS paid_count,
        COUNT(CASE WHEN i.status = 'overdue' THEN 1 END)  AS overdue_count,
        COALESCE(SUM(CASE WHEN i.status = 'overdue' THEN i.balance_due ELSE 0 END), 0) AS overdue_amount
     FROM invoices i
     WHERE i.deleted_at IS NULL
       AND i.status NOT IN ('draft', 'void')
       AND i.invoice_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

// Collection rate: how much of invoiced revenue has been collected (%)
// bcmath — avoid float imprecision on large monetary values (D16)
$gross = (string) ($kpiRow['gross_revenue'] ?? '0');
$collected = (string) ($kpiRow['total_collected'] ?? '0');
$collectionRate = ReportBuilder::pct($collected, $gross, 1);

$kpis = [
    'invoice_count'     => (int)   $kpiRow['invoice_count'],
    'gross_revenue'     => bcround($gross, 2),
    'net_revenue'       => bcround((string) ($kpiRow['net_revenue']      ?? '0'), 2),
    'total_tax'         => bcround((string) ($kpiRow['total_tax']        ?? '0'), 2),
    'total_collected'   => bcround($collected, 2),
    'total_outstanding' => bcround((string) ($kpiRow['total_outstanding'] ?? '0'), 2),
    'avg_invoice_value' => bcround((string) ($kpiRow['avg_invoice_value'] ?? '0'), 2),
    'total_discounts'   => bcround((string) ($kpiRow['total_discounts']   ?? '0'), 2),
    'collection_rate'   => $collectionRate,
    'unique_customers'  => (int)   $kpiRow['unique_customers'],
    'paid_count'        => (int)   $kpiRow['paid_count'],
    'overdue_count'     => (int)   $kpiRow['overdue_count'],
    'overdue_amount'    => bcround((string) ($kpiRow['overdue_amount']    ?? '0'), 2),
];

// ── View-specific data ───────────────────────────────────────────────────────
$chartData = [];
$table     = [];
$totals    = [];

switch ($view) {

    // ── Revenue by period (monthly breakdown) ────────────────────────────────
    case 'period':
        $rows = db_select(
            "SELECT
                DATE_FORMAT(i.invoice_date, '%Y-%m')           AS period,
                DATE_FORMAT(i.invoice_date, '%b %Y')           AS period_label,
                COUNT(*)                                        AS invoice_count,
                COUNT(DISTINCT i.customer_id)                   AS unique_customers,
                COALESCE(SUM(i.subtotal_after_discount), 0)    AS net_revenue,
                COALESCE(SUM(i.tax_total),               0)    AS tax_total,
                COALESCE(SUM(i.total_amount),            0)    AS gross_revenue,
                COALESCE(SUM(i.discount_amount),         0)    AS discounts,
                COALESCE(SUM(i.amount_paid),             0)    AS collected,
                COALESCE(SUM(i.balance_due),             0)    AS outstanding
             FROM invoices i
             WHERE i.deleted_at IS NULL
               AND i.status NOT IN ('draft','void')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), DATE_FORMAT(i.invoice_date, '%b %Y')
             ORDER BY period ASC",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'categories'  => array_column($rows, 'period_label'),
            'net_revenue' => array_map(fn($r) => (float) $r['net_revenue'],  $rows),
            'collected'   => array_map(fn($r) => (float) $r['collected'],    $rows),
            'outstanding' => array_map(fn($r) => (float) $r['outstanding'],  $rows),
            'tax'         => array_map(fn($r) => (float) $r['tax_total'],    $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'revenue_by_period_' . $dateFrom . '_to_' . $dateTo,
                ['Period', 'Invoices', 'Customers', 'Net Revenue', 'Tax', 'Gross Revenue', 'Discounts', 'Collected', 'Outstanding'],
                array_map(fn($r) => [
                    $r['period_label'],
                    $r['invoice_count'],
                    $r['unique_customers'],
                    ReportBuilder::csvMoney($r['net_revenue']),
                    ReportBuilder::csvMoney($r['tax_total']),
                    ReportBuilder::csvMoney($r['gross_revenue']),
                    ReportBuilder::csvMoney($r['discounts']),
                    ReportBuilder::csvMoney($r['collected']),
                    ReportBuilder::csvMoney($r['outstanding']),
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = [
            'net_revenue'   => bcround((string) array_sum(array_column($rows, 'net_revenue')),   2),
            'gross_revenue' => bcround((string) array_sum(array_column($rows, 'gross_revenue')), 2),
            'collected'     => bcround((string) array_sum(array_column($rows, 'collected')),     2),
            'outstanding'   => bcround((string) array_sum(array_column($rows, 'outstanding')),   2),
            'tax_total'     => bcround((string) array_sum(array_column($rows, 'tax_total')),     2),
        ];
        break;

    // ── Revenue by customer (ranked) ─────────────────────────────────────────
    case 'customer':
        $rows = db_select(
            "SELECT
                i.customer_id,
                ANY_VALUE(COALESCE(c.company_name, i.company_name_snapshot, 'Unknown')) AS company_name,
                ANY_VALUE(c.status)                                                      AS customer_status,
                COUNT(*)                                                                 AS invoice_count,
                COUNT(DISTINCT i.lease_id)                                               AS lease_count,
                COALESCE(SUM(i.subtotal_after_discount), 0)                             AS net_revenue,
                COALESCE(SUM(i.total_amount),            0)                             AS gross_revenue,
                COALESCE(SUM(i.amount_paid),             0)                             AS collected,
                COALESCE(SUM(i.balance_due),             0)                             AS outstanding,
                COALESCE(AVG(i.total_amount),            0)                             AS avg_invoice
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
             WHERE i.deleted_at IS NULL
               AND i.status NOT IN ('draft','void')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY i.customer_id
             ORDER BY gross_revenue DESC
             LIMIT 50",
            [$dateFrom, $dateTo]
        );

        // Chart shows top 15 for readability
        $top15 = array_slice($rows, 0, 15);
        $chartData = [
            'categories'    => array_column($top15, 'company_name'),
            'gross_revenue' => array_map(fn($r) => (float) $r['gross_revenue'], $top15),
            'outstanding'   => array_map(fn($r) => (float) $r['outstanding'],   $top15),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'revenue_by_customer_' . $dateFrom . '_to_' . $dateTo,
                ['Customer', 'Status', 'Invoices', 'Leases', 'Net Revenue', 'Gross Revenue', 'Collected', 'Outstanding', 'Avg Invoice'],
                array_map(fn($r) => [
                    $r['company_name'],
                    $r['customer_status'] ?? '',
                    $r['invoice_count'],
                    $r['lease_count'],
                    ReportBuilder::csvMoney($r['net_revenue']),
                    ReportBuilder::csvMoney($r['gross_revenue']),
                    ReportBuilder::csvMoney($r['collected']),
                    ReportBuilder::csvMoney($r['outstanding']),
                    ReportBuilder::csvMoney($r['avg_invoice']),
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = ['gross_revenue' => bcround((string) array_sum(array_column($rows, 'gross_revenue')), 2)];
        break;

    // ── Revenue by equipment type (category) ─────────────────────────────────
    case 'equipment_type':
        // JOIN through leases → equipment_units → equipment_templates
        // Invoices without a lease (manual) land in 'Unassigned'
        $rows = db_select(
            "SELECT
                COALESCE(et.category, 'Unassigned')         AS equipment_type,
                COUNT(DISTINCT eu.id)                        AS unit_count,
                COUNT(DISTINCT i.lease_id)                   AS lease_count,
                COUNT(*)                                     AS invoice_count,
                COALESCE(SUM(i.subtotal_after_discount), 0) AS net_revenue,
                COALESCE(SUM(i.total_amount),            0) AS gross_revenue,
                COALESCE(SUM(i.amount_paid),             0) AS collected
             FROM invoices i
             LEFT JOIN leases l           ON l.id  = i.lease_id          AND l.deleted_at  IS NULL
             LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
             LEFT JOIN equipment_templates et ON et.id = eu.template_id  AND et.deleted_at IS NULL
             WHERE i.deleted_at IS NULL
               AND i.status NOT IN ('draft','void')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY equipment_type
             ORDER BY gross_revenue DESC",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'categories'    => array_column($rows, 'equipment_type'),
            'gross_revenue' => array_map(fn($r) => (float) $r['gross_revenue'], $rows),
            'unit_count'    => array_map(fn($r) => (int)   $r['unit_count'],    $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'revenue_by_equipment_type_' . $dateFrom . '_to_' . $dateTo,
                ['Equipment Type', 'Units', 'Leases', 'Invoices', 'Net Revenue', 'Gross Revenue', 'Collected'],
                array_map(fn($r) => [
                    $r['equipment_type'], $r['unit_count'], $r['lease_count'],
                    $r['invoice_count'],
                    ReportBuilder::csvMoney($r['net_revenue']),
                    ReportBuilder::csvMoney($r['gross_revenue']),
                    ReportBuilder::csvMoney($r['collected']),
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = ['gross_revenue' => bcround((string) array_sum(array_column($rows, 'gross_revenue')), 2)];
        break;

    // ── AR Aging snapshot ────────────────────────────────────────────────────
    // "As of date" = $dateTo. Shows all invoices with a balance_due > 0 as of that date.
    // Bucket assignment is based on DATEDIFF(as_of_date, due_date).
    case 'ar_aging':
        $asOf = $dateTo;
        $rows = db_select(
            "SELECT
                i.id,
                i.invoice_number,
                COALESCE(c.company_name, i.company_name_snapshot, 'Unknown') AS company_name,
                i.customer_id,
                i.invoice_date,
                i.due_date,
                i.total_amount,
                i.amount_paid,
                i.balance_due,
                i.status,
                DATEDIFF(?, i.due_date)                              AS days_overdue,
                CASE
                    WHEN DATEDIFF(?, i.due_date) <= 0  THEN 'current'
                    WHEN DATEDIFF(?, i.due_date) <= 30 THEN '1_30'
                    WHEN DATEDIFF(?, i.due_date) <= 60 THEN '31_60'
                    WHEN DATEDIFF(?, i.due_date) <= 90 THEN '61_90'
                    ELSE '90_plus'
                END AS aging_bucket
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
             WHERE i.deleted_at IS NULL
               AND i.balance_due > 0
               AND i.status IN ('sent','partially_paid','overdue')
               AND i.invoice_date <= ?
             ORDER BY days_overdue DESC, i.customer_id ASC",
            [$asOf, $asOf, $asOf, $asOf, $asOf, $asOf]
        );

        // Aggregate into 5 buckets for the chart
        $buckets = [
            'current'  => ['label' => 'Current',     'count' => 0, 'amount' => '0'],
            '1_30'     => ['label' => '1–30 Days',   'count' => 0, 'amount' => '0'],
            '31_60'    => ['label' => '31–60 Days',  'count' => 0, 'amount' => '0'],
            '61_90'    => ['label' => '61–90 Days',  'count' => 0, 'amount' => '0'],
            '90_plus'  => ['label' => '90+ Days',    'count' => 0, 'amount' => '0'],
        ];
        foreach ($rows as $r) {
            $b = $r['aging_bucket'];
            $buckets[$b]['count']++;
            $buckets[$b]['amount'] = bcadd($buckets[$b]['amount'], (string) $r['balance_due'], 2);
        }

        $chartData = [
            'categories' => array_column(array_values($buckets), 'label'),
            'amounts'    => array_map(fn($b) => (float) $b['amount'], array_values($buckets)),
            'counts'     => array_map(fn($b) => $b['count'],          array_values($buckets)),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'ar_aging_as_of_' . $asOf,
                ['Invoice #', 'Customer', 'Invoice Date', 'Due Date', 'Total Amount', 'Paid', 'Balance Due', 'Days Overdue', 'Bucket'],
                array_map(fn($r) => [
                    $r['invoice_number'],
                    $r['company_name'],
                    $r['invoice_date'],
                    $r['due_date'],
                    ReportBuilder::csvMoney($r['total_amount']),
                    ReportBuilder::csvMoney($r['amount_paid']),
                    ReportBuilder::csvMoney($r['balance_due']),
                    max(0, (int) $r['days_overdue']),
                    str_replace('_', '–', $r['aging_bucket']),
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = [
            'total_outstanding' => bcround((string) array_sum(array_column($rows, 'balance_due')), 2),
            'invoice_count'     => count($rows),
            'buckets'           => $buckets,
        ];
        break;

    // ── Payment collection rate by month ─────────────────────────────────────
    case 'collection':
        $rows = db_select(
            "SELECT
                DATE_FORMAT(i.invoice_date, '%Y-%m')     AS period,
                DATE_FORMAT(i.invoice_date, '%b %Y')     AS period_label,
                COUNT(*)                                  AS invoice_count,
                COUNT(CASE WHEN i.status = 'paid' THEN 1 END) AS fully_paid_count,
                COALESCE(SUM(i.total_amount), 0)         AS invoiced,
                COALESCE(SUM(i.amount_paid),  0)         AS collected,
                COALESCE(SUM(i.balance_due),  0)         AS outstanding
             FROM invoices i
             WHERE i.deleted_at IS NULL
               AND i.status NOT IN ('draft','void')
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), DATE_FORMAT(i.invoice_date, '%b %Y')
             ORDER BY period ASC",
            [$dateFrom, $dateTo]
        );

        // Compute collection rate per period — D16: bcmath
        foreach ($rows as &$r) {
            $r['collection_rate'] = ReportBuilder::pct((string) $r['collected'], (string) $r['invoiced'], 1);
        }
        unset($r);

        $chartData = [
            'categories'      => array_column($rows, 'period_label'),
            'invoiced'        => array_map(fn($r) => (float) $r['invoiced'],         $rows),
            'collected'       => array_map(fn($r) => (float) $r['collected'],        $rows),
            'collection_rate' => array_map(fn($r) => (float) $r['collection_rate'],  $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'collection_rate_' . $dateFrom . '_to_' . $dateTo,
                ['Period', 'Invoices', 'Fully Paid', 'Invoiced', 'Collected', 'Outstanding', 'Collection Rate %'],
                array_map(fn($r) => [
                    $r['period_label'],
                    $r['invoice_count'],
                    $r['fully_paid_count'],
                    ReportBuilder::csvMoney($r['invoiced']),
                    ReportBuilder::csvMoney($r['collected']),
                    ReportBuilder::csvMoney($r['outstanding']),
                    $r['collection_rate'],
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = [];
        break;

    // ── Invoice status distribution ───────────────────────────────────────────
    case 'status':
        $rows = db_select(
            "SELECT
                i.status,
                COUNT(*)                                  AS invoice_count,
                COALESCE(SUM(i.total_amount), 0)         AS total_amount,
                COALESCE(SUM(i.balance_due),  0)         AS balance_due,
                COALESCE(AVG(i.total_amount), 0)         AS avg_amount,
                COUNT(DISTINCT i.customer_id)             AS unique_customers
             FROM invoices i
             WHERE i.deleted_at IS NULL
               AND i.invoice_date BETWEEN ? AND ?
             GROUP BY i.status
             ORDER BY total_amount DESC",
            [$dateFrom, $dateTo]
        );

        $chartData = [
            'labels'        => array_column($rows, 'status'),
            'counts'        => array_map(fn($r) => (int)   $r['invoice_count'], $rows),
            'total_amounts' => array_map(fn($r) => (float) $r['total_amount'],  $rows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'invoice_status_' . $dateFrom . '_to_' . $dateTo,
                ['Status', 'Invoice Count', 'Unique Customers', 'Total Amount', 'Balance Due', 'Avg Amount'],
                array_map(fn($r) => [
                    $r['status'],
                    $r['invoice_count'],
                    $r['unique_customers'],
                    ReportBuilder::csvMoney($r['total_amount']),
                    ReportBuilder::csvMoney($r['balance_due']),
                    ReportBuilder::csvMoney($r['avg_amount']),
                ], $rows)
            );
        }

        $table  = $rows;
        $totals = [];
        break;
}

// ── Build result and cache ───────────────────────────────────────────────────
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

// CSV exits before this point via ReportBuilder::outputCsv()
ReportBuilder::setCached($cacheType, $cacheParams, $result);
json_success($result);
