<?php
declare(strict_types=1);
/**
 * api/v1/reports/compliance.php
 *
 * Compliance Reports API — expiry timeline, status snapshot, expired items list,
 * and upcoming renewals.  Unlike other report endpoints this one is forward-looking:
 * "date range" is a number of days ahead (window_days) rather than a historical range.
 *
 * Tracks three document types per unit: CVI, Registration, Insurance.
 * (MVI removed per S020-EXT user decision.)
 *
 * Required by: app/admin/reports/index.php (Compliance tab)
 * Requires: api/bootstrap.php, lib/Reports/ReportBuilder.php (autoloaded)
 *
 * GET params:
 *   view         : timeline | status | expired | upcoming  (default timeline)
 *   window_days  : integer 7–365 — look-ahead window for timeline/upcoming  (default 90)
 *   format       : json | csv
 *
 * Spec ref: §7.10 Reports, PROGRESS.md S020-EXT (MVI removal), S021
 * Decisions: D5 (soft-delete), D16 (no monetary values here)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Reports\ReportBuilder;

require_method('GET');
require_auth_api();
require_permission('reports', 'view');

// ── Input validation ─────────────────────────────────────────────────────────
$allowedViews = ['timeline', 'status', 'expired', 'upcoming'];
$view        = clean_string($_GET['view']   ?? 'timeline') ?? 'timeline';
$format      = clean_string($_GET['format'] ?? 'json')     ?? 'json';
$windowDays  = min(365, max(7, clean_int($_GET['window_days'] ?? 90) ?? 90));

if (!in_array($view, $allowedViews, true))       $view   = 'timeline';
if (!in_array($format, ['json', 'csv'], true))   $format = 'json';

$today      = date('Y-m-d');
$windowEnd  = date('Y-m-d', strtotime("+{$windowDays} days"));

// ── Cache check ──────────────────────────────────────────────────────────────
$cacheParams = ['view' => $view, 'window_days' => $windowDays, 'today' => $today];
$cacheType   = 'compliance_' . $view;

if ($format === 'json') {
    $cached = ReportBuilder::getCached($cacheType, $cacheParams);
    // Compliance data changes daily — use a 15-min cache, same as other reports
    if ($cached) { $cached['cached'] = true; json_success($cached); }
}

// ── Shared KPIs — always computed from current state ─────────────────────────
$kpiRow = db_row(
    "SELECT
        COUNT(*) AS total_units,
        -- Expired: ANY document past today
        SUM(CASE WHEN (cvi_expiry < ? OR registration_expiry < ? OR insurance_expiry < ?)
                      AND status NOT IN ('decommissioned') THEN 1 ELSE 0 END) AS expired_count,
        -- Expiring ≤30d: any document within 30 days (and not yet expired)
        SUM(CASE WHEN status NOT IN ('decommissioned')
                      AND (
                          (cvi_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY))
                       OR (registration_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY))
                       OR (insurance_expiry    BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY))
                      ) THEN 1 ELSE 0 END) AS expiring_30_count,
        -- Expiring ≤90d: any document within 90 days
        SUM(CASE WHEN status NOT IN ('decommissioned')
                      AND (
                          (cvi_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 90 DAY))
                       OR (registration_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 90 DAY))
                       OR (insurance_expiry    BETWEEN ? AND DATE_ADD(?, INTERVAL 90 DAY))
                      ) THEN 1 ELSE 0 END) AS expiring_90_count
     FROM equipment_units
     WHERE deleted_at IS NULL AND status != 'decommissioned'",
    [
        // expired
        $today, $today, $today,
        // expiring_30
        $today, $today, $today, $today, $today, $today,
        // expiring_90
        $today, $today, $today, $today, $today, $today,
    ]
);

$totalUnits = (int) $kpiRow['total_units'];
$kpis = [
    'total_units'      => $totalUnits,
    'expired_count'    => (int) $kpiRow['expired_count'],
    'expiring_30'      => (int) $kpiRow['expiring_30_count'],
    'expiring_90'      => (int) $kpiRow['expiring_90_count'],
    'ok_count'         => max(0, $totalUnits - (int) $kpiRow['expiring_90_count'] - (int) $kpiRow['expired_count']),
    'window_days'      => $windowDays,
    'as_of_date'       => $today,
];

$chartData = [];
$table     = [];
$totals    = [];

switch ($view) {

    // ── 12-month (or window_days) expiry timeline ─────────────────────────────
    // For each future month, count how many documents (CVI/Reg/Insurance) expire.
    // Returns one row per month, with counts per document type.
    case 'timeline':
        $cviRows = db_select(
            "SELECT DATE_FORMAT(cvi_expiry, '%Y-%m') AS period,
                    DATE_FORMAT(cvi_expiry, '%b %Y') AS period_label,
                    COUNT(*) AS count
             FROM equipment_units
             WHERE deleted_at IS NULL AND status != 'decommissioned'
               AND cvi_expiry BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(cvi_expiry, '%Y-%m'), DATE_FORMAT(cvi_expiry, '%b %Y')
             ORDER BY period ASC",
            [$today, $windowEnd]
        );

        $regRows = db_select(
            "SELECT DATE_FORMAT(registration_expiry, '%Y-%m') AS period, COUNT(*) AS count
             FROM equipment_units
             WHERE deleted_at IS NULL AND status != 'decommissioned'
               AND registration_expiry BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(registration_expiry, '%Y-%m')
             ORDER BY period ASC",
            [$today, $windowEnd]
        );

        $insRows = db_select(
            "SELECT DATE_FORMAT(insurance_expiry, '%Y-%m') AS period, COUNT(*) AS count
             FROM equipment_units
             WHERE deleted_at IS NULL AND status != 'decommissioned'
               AND insurance_expiry BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(insurance_expiry, '%Y-%m')
             ORDER BY period ASC",
            [$today, $windowEnd]
        );

        // Build a unified month list covering all three document types
        $allPeriods = array_unique(array_merge(
            array_column($cviRows, 'period'),
            array_column($regRows, 'period'),
            array_column($insRows, 'period'),
        ));
        sort($allPeriods);

        $cviMap  = array_column($cviRows, 'count', 'period');
        $regMap  = array_column($regRows, 'count', 'period');
        $insMap  = array_column($insRows, 'count', 'period');

        $timelineRows = [];
        foreach ($allPeriods as $p) {
            $label          = date('M Y', strtotime($p . '-01'));
            $cviCount       = (int) ($cviMap[$p]  ?? 0);
            $regCount       = (int) ($regMap[$p]  ?? 0);
            $insCount       = (int) ($insMap[$p]  ?? 0);
            $timelineRows[] = [
                'period'       => $p,
                'period_label' => $label,
                'cvi_count'    => $cviCount,
                'reg_count'    => $regCount,
                'ins_count'    => $insCount,
                'total'        => $cviCount + $regCount + $insCount,
            ];
        }

        $chartData = [
            'categories' => array_column($timelineRows, 'period_label'),
            'cvi'        => array_map(fn($r) => $r['cvi_count'], $timelineRows),
            'registration' => array_map(fn($r) => $r['reg_count'], $timelineRows),
            'insurance'  => array_map(fn($r) => $r['ins_count'], $timelineRows),
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'compliance_timeline_next_' . $windowDays . 'd',
                ['Month', 'CVI Expirations', 'Registration Expirations', 'Insurance Expirations', 'Total'],
                array_map(fn($r) => [$r['period_label'], $r['cvi_count'], $r['reg_count'], $r['ins_count'], $r['total']], $timelineRows)
            );
        }
        $table  = $timelineRows;
        $totals = [];
        break;

    // ── Current status snapshot ───────────────────────────────────────────────
    // Per-document-type distribution: expired / expiring ≤30d / expiring ≤90d / ok / no date
    case 'status':
        $statusRows = db_row(
            "SELECT
                -- CVI
                SUM(CASE WHEN cvi_expiry < ?                                       THEN 1 ELSE 0 END) AS cvi_expired,
                SUM(CASE WHEN cvi_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS cvi_expiring_30,
                SUM(CASE WHEN cvi_expiry BETWEEN DATE_ADD(?, INTERVAL 31 DAY) AND DATE_ADD(?, INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS cvi_expiring_90,
                SUM(CASE WHEN cvi_expiry > DATE_ADD(?, INTERVAL 90 DAY)           THEN 1 ELSE 0 END) AS cvi_ok,
                SUM(CASE WHEN cvi_expiry IS NULL                                   THEN 1 ELSE 0 END) AS cvi_missing,
                -- Registration
                SUM(CASE WHEN registration_expiry < ?                              THEN 1 ELSE 0 END) AS reg_expired,
                SUM(CASE WHEN registration_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS reg_expiring_30,
                SUM(CASE WHEN registration_expiry BETWEEN DATE_ADD(?, INTERVAL 31 DAY) AND DATE_ADD(?, INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS reg_expiring_90,
                SUM(CASE WHEN registration_expiry > DATE_ADD(?, INTERVAL 90 DAY)  THEN 1 ELSE 0 END) AS reg_ok,
                SUM(CASE WHEN registration_expiry IS NULL                          THEN 1 ELSE 0 END) AS reg_missing,
                -- Insurance
                SUM(CASE WHEN insurance_expiry < ?                                 THEN 1 ELSE 0 END) AS ins_expired,
                SUM(CASE WHEN insurance_expiry BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS ins_expiring_30,
                SUM(CASE WHEN insurance_expiry BETWEEN DATE_ADD(?, INTERVAL 31 DAY) AND DATE_ADD(?, INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS ins_expiring_90,
                SUM(CASE WHEN insurance_expiry > DATE_ADD(?, INTERVAL 90 DAY)     THEN 1 ELSE 0 END) AS ins_ok,
                SUM(CASE WHEN insurance_expiry IS NULL                             THEN 1 ELSE 0 END) AS ins_missing
             FROM equipment_units
             WHERE deleted_at IS NULL AND status != 'decommissioned'",
            [
                $today, $today, $today, $today, $today, $today,   // CVI (6)
                $today, $today, $today, $today, $today, $today,   // Registration (6)
                $today, $today, $today, $today, $today, $today,   // Insurance (6)
            ]
        );

        $chartData = [
            'labels'    => ['CVI', 'Registration', 'Insurance'],
            'expired'   => [(int) $statusRows['cvi_expired'],     (int) $statusRows['reg_expired'],     (int) $statusRows['ins_expired']],
            'expiring_30' => [(int) $statusRows['cvi_expiring_30'], (int) $statusRows['reg_expiring_30'], (int) $statusRows['ins_expiring_30']],
            'expiring_90' => [(int) $statusRows['cvi_expiring_90'], (int) $statusRows['reg_expiring_90'], (int) $statusRows['ins_expiring_90']],
            'ok'        => [(int) $statusRows['cvi_ok'],          (int) $statusRows['reg_ok'],          (int) $statusRows['ins_ok']],
            'missing'   => [(int) $statusRows['cvi_missing'],     (int) $statusRows['reg_missing'],     (int) $statusRows['ins_missing']],
        ];

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'compliance_status_snapshot_' . $today,
                ['Document Type', 'Expired', 'Expiring ≤30d', 'Expiring 31-90d', 'OK (>90d)', 'No Date Set'],
                [
                    ['CVI',          $statusRows['cvi_expired'], $statusRows['cvi_expiring_30'], $statusRows['cvi_expiring_90'], $statusRows['cvi_ok'], $statusRows['cvi_missing']],
                    ['Registration', $statusRows['reg_expired'], $statusRows['reg_expiring_30'], $statusRows['reg_expiring_90'], $statusRows['reg_ok'], $statusRows['reg_missing']],
                    ['Insurance',    $statusRows['ins_expired'], $statusRows['ins_expiring_30'], $statusRows['ins_expiring_90'], $statusRows['ins_ok'], $statusRows['ins_missing']],
                ]
            );
        }
        $table  = $statusRows ? [$statusRows] : [];
        $totals = [];
        break;

    // ── Expired documents (all units with at least one expired document) ───────
    case 'expired':
        $rows = db_select(
            "SELECT
                id, unit_number, yard_location, status,
                cvi_expiry, cvi_from_date,
                registration_expiry, registration_from_date,
                insurance_expiry, insurance_from_date,
                -- Days since each expiry (negative = still valid)
                DATEDIFF(?, cvi_expiry)          AS cvi_days_overdue,
                DATEDIFF(?, registration_expiry) AS reg_days_overdue,
                DATEDIFF(?, insurance_expiry)    AS ins_days_overdue
             FROM equipment_units
             WHERE deleted_at IS NULL
               AND status != 'decommissioned'
               AND (cvi_expiry < ? OR registration_expiry < ? OR insurance_expiry < ?)
             ORDER BY LEAST(
                 COALESCE(cvi_expiry,          '9999-12-31'),
                 COALESCE(registration_expiry, '9999-12-31'),
                 COALESCE(insurance_expiry,    '9999-12-31')
             ) ASC",
            [$today, $today, $today, $today, $today, $today]
        );

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'compliance_expired_as_of_' . $today,
                ['Unit #', 'Yard', 'Status', 'CVI Expiry', 'CVI Days Overdue', 'Reg. Expiry', 'Reg. Days Overdue', 'Insurance Expiry', 'Ins. Days Overdue'],
                array_map(fn($r) => [
                    $r['unit_number'], $r['yard_location'] ?? '', $r['status'],
                    $r['cvi_expiry']          ?? 'N/A', max(0, (int) ($r['cvi_days_overdue'] ?? 0)),
                    $r['registration_expiry'] ?? 'N/A', max(0, (int) ($r['reg_days_overdue'] ?? 0)),
                    $r['insurance_expiry']    ?? 'N/A', max(0, (int) ($r['ins_days_overdue'] ?? 0)),
                ], $rows)
            );
        }
        $chartData = [];
        $table     = $rows;
        $totals    = ['expired_unit_count' => count($rows)];
        break;

    // ── Upcoming renewals within window_days ──────────────────────────────────
    case 'upcoming':
        $rows = db_select(
            "SELECT
                id, unit_number, yard_location, status,
                cvi_expiry, cvi_from_date,
                registration_expiry, registration_from_date,
                insurance_expiry, insurance_from_date,
                -- Days remaining until each expiry
                DATEDIFF(cvi_expiry,          ?) AS cvi_days_left,
                DATEDIFF(registration_expiry, ?) AS reg_days_left,
                DATEDIFF(insurance_expiry,    ?) AS ins_days_left,
                -- Nearest upcoming expiry (for sort)
                LEAST(
                    COALESCE(NULLIF(LEAST(COALESCE(cvi_expiry,'9999-12-31'), '9999-12-31'), '9999-12-31'), '9999-12-31'),
                    COALESCE(NULLIF(LEAST(COALESCE(registration_expiry,'9999-12-31'), '9999-12-31'), '9999-12-31'), '9999-12-31'),
                    COALESCE(NULLIF(LEAST(COALESCE(insurance_expiry,'9999-12-31'), '9999-12-31'), '9999-12-31'), '9999-12-31')
                ) AS nearest_expiry
             FROM equipment_units
             WHERE deleted_at IS NULL
               AND status != 'decommissioned'
               AND (
                   (cvi_expiry          BETWEEN ? AND ?)
                OR (registration_expiry BETWEEN ? AND ?)
                OR (insurance_expiry    BETWEEN ? AND ?)
               )
             ORDER BY nearest_expiry ASC",
            [
                $today, $today, $today,
                $today, $windowEnd,
                $today, $windowEnd,
                $today, $windowEnd,
            ]
        );

        if ($format === 'csv') {
            ReportBuilder::outputCsv(
                'compliance_upcoming_' . $windowDays . 'd_' . $today,
                ['Unit #', 'Yard', 'Status', 'CVI Expiry', 'CVI Days Left', 'Reg. Expiry', 'Reg. Days Left', 'Insurance Expiry', 'Ins. Days Left'],
                array_map(fn($r) => [
                    $r['unit_number'], $r['yard_location'] ?? '', $r['status'],
                    $r['cvi_expiry']          ?? '', $r['cvi_days_left']          ?? '',
                    $r['registration_expiry'] ?? '', $r['reg_days_left']          ?? '',
                    $r['insurance_expiry']    ?? '', $r['ins_days_left']          ?? '',
                ], $rows)
            );
        }
        $chartData = [];
        $table     = $rows;
        $totals    = ['upcoming_count' => count($rows), 'window_days' => $windowDays];
        break;
}

$result = [
    'kpis'         => $kpis,
    'chart_data'   => $chartData,
    'table'        => $table,
    'totals'       => $totals,
    'view'         => $view,
    'window_days'  => $windowDays,
    'as_of_date'   => $today,
    'generated_at' => date('c'),
    'cached'       => false,
];

ReportBuilder::setCached($cacheType, $cacheParams, $result);
json_success($result);
