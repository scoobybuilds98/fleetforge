<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/tax_codes/list.php
 *
 * List query for the Tax Codes Mapping page. Returns paginated
 * mapping rows joined to FF tax_rates data, plus KPI counts
 * including override-target-set status (D-QBO-9-2).
 *
 * Filters:
 *   status (mapped|ff_only|qbo_only|ignored|all)
 *   q (search) — matches FF tax_rates.name OR province OR QBO name
 *   show_historical (true|false) — default false; when false, FF
 *     ff_only/mapped rows are filtered to currently-effective rates
 *     per D-QBO-9-5 (is_active=1 AND effective_from <= today AND
 *     (effective_to IS NULL OR effective_to > today)). Historical
 *     ff_only rows excluded by default; qbo_only rows always included.
 *   page, page_size (default 50, max 200)
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 *
 * Spec ref: §7.2
 * Session:  S-QBO-9
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status         = (string) ($_GET['status'] ?? 'all');
$q              = trim((string) ($_GET['q'] ?? ''));
$showHistorical = isset($_GET['show_historical']) && $_GET['show_historical'] === 'true';
$page           = max(1, (int) ($_GET['page'] ?? 1));
$pageSize       = max(1, min(200, (int) ($_GET['page_size'] ?? 50)));

$allowedStatus = ['all', 'mapped', 'ff_only', 'qbo_only', 'ignored'];
if (!in_array($status, $allowedStatus, true)) { $status = 'all'; }

try {
    $where  = ['1=1'];
    $params = [];

    if ($status !== 'all') {
        $where[]  = "m.mapping_status = ?";
        $params[] = $status;
    }

    // D-QBO-9-5: historical filter on the FF side. Apply only when
    // the row has an FF link (qbo_only rows always pass).
    if (!$showHistorical) {
        $where[] = "(m.ff_tax_rate_id IS NULL OR (r.is_active = 1 AND r.effective_from <= CURDATE() AND (r.effective_to IS NULL OR r.effective_to > CURDATE())))";
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(r.name LIKE ? OR r.province LIKE ? OR m.qbo_name LIKE ? OR m.qbo_description LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    // KPIs (global, ignore filters except status — which is a separate axis from KPI counts).
    $kpiRows = db_select(
        "SELECT mapping_status, COUNT(*) AS n FROM acc_qbo_tax_code_map GROUP BY mapping_status"
    );
    $kpis = ['mapped' => 0, 'ff_only' => 0, 'qbo_only' => 0, 'ignored' => 0];
    foreach ($kpiRows as $r) {
        $kpis[$r['mapping_status']] = (int) $r['n'];
    }
    // Override-target-set KPI (D-QBO-9-2 invariant: 0 or 1 rows max).
    $overrideTargetSet = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_tax_code_map WHERE is_override_target = 1",
        []
    );
    $kpis['override_target_set'] = $overrideTargetSet;

    // Pull the override target details for the banner.
    $overrideTargetRow = db_row(
        "SELECT id, qbo_tax_code_id, qbo_name FROM acc_qbo_tax_code_map WHERE is_override_target = 1"
    );

    $lastPulled = db_row(
        "SELECT MAX(last_pull_at) AS t FROM acc_qbo_tax_code_map WHERE last_pull_at IS NOT NULL"
    );
    $lastPulledAt = $lastPulled['t'] ?? null;

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_tax_code_map m
         LEFT JOIN tax_rates r ON r.id = m.ff_tax_rate_id
         WHERE {$whereSql}",
        $params
    );

    $offset = ($page - 1) * $pageSize;
    $rows = db_select(
        "SELECT
            m.id                AS mapping_id,
            m.ff_tax_rate_id,
            r.name              AS ff_name,
            r.province          AS ff_province,
            r.country           AS ff_country,
            r.gst_rate          AS ff_gst_rate,
            r.pst_rate          AS ff_pst_rate,
            r.hst_rate          AS ff_hst_rate,
            r.is_default        AS ff_is_default,
            r.is_active         AS ff_is_active,
            r.effective_from    AS ff_effective_from,
            r.effective_to      AS ff_effective_to,
            m.qbo_tax_code_id,
            m.qbo_name,
            m.qbo_description,
            m.qbo_taxable,
            m.qbo_hidden,
            m.qbo_active,
            m.qbo_tax_group,
            m.qbo_sales_rate_refs,
            m.ff_rate_snapshot,
            m.mapping_status,
            m.match_confidence,
            m.is_override_target,
            m.match_notes,
            m.last_synced_at,
            m.last_pull_at
         FROM acc_qbo_tax_code_map m
         LEFT JOIN tax_rates r ON r.id = m.ff_tax_rate_id
         WHERE {$whereSql}
         ORDER BY
            -- Override target first (D-QBO-9-2 — special row)
            CASE WHEN m.is_override_target = 1 THEN 0 ELSE 1 END,
            -- Then by mapping_status priority (mapped first)
            CASE m.mapping_status
              WHEN 'mapped'   THEN 1
              WHEN 'ff_only'  THEN 2
              WHEN 'qbo_only' THEN 3
              WHEN 'ignored'  THEN 4
              ELSE 5
            END,
            r.province,
            r.name,
            m.qbo_name,
            m.id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );

    $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

    json_success([
        'kpis'                  => $kpis,
        'last_pulled_at'        => $lastPulledAt,
        'override_target_row'   => $overrideTargetRow,
        'rows'                  => $rows,
        'pagination'            => [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Tax codes list failed: ' . $e->getMessage(), 500);
}
