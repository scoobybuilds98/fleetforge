<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/vendors/list.php
 *
 * List query for the Vendors Sync page. Returns paginated mapping
 * rows joined to FF vendor data, plus the 4 KPI counts used for the
 * page's tile row. Mirrors api/v1/quickbooks/customers/list.php
 * (S-QBO-5).
 *
 * Filters:
 *   status (mapped|ff_only|qbo_only|ignored|all)        — mapping_status
 *   confidence (exact|high|medium|low|manual|unmatched|all) — match_confidence
 *   q (search string)                                   — matches FF
 *      name OR QBO display_name OR QBO company_name
 *   page (int, default 1)                               — pagination
 *   page_size (int, default 50, max 200)                — pagination
 *
 * Returns:
 *   {
 *     success: true,
 *     data: {
 *       kpis: { mapped, ff_only, qbo_only, ignored },
 *       last_pulled_at: string|null,   // MAX(last_pull_at) across all rows
 *       rows: [
 *         { mapping_id, ff_vendor_id, ff_name, ff_email, ff_phone,
 *           qbo_vendor_id, qbo_display_name, qbo_email, qbo_phone,
 *           qbo_active, qbo_v4v_status, mapping_status, match_confidence,
 *           last_synced_at, last_pull_at }
 *       ],
 *       pagination: { page, page_size, total, total_pages }
 *     }
 *   }
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 *
 * Spec ref: §7.5
 * Session:  S-QBO-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status      = (string) ($_GET['status']     ?? 'all');
$confidence  = (string) ($_GET['confidence'] ?? 'all');
$q           = trim((string) ($_GET['q']     ?? ''));
$page        = max(1, (int) ($_GET['page']      ?? 1));
$pageSize    = max(1, min(200, (int) ($_GET['page_size'] ?? 50)));

$allowedStatus     = ['all', 'mapped', 'ff_only', 'qbo_only', 'ignored'];
$allowedConfidence = ['all', 'exact', 'high', 'medium', 'low', 'manual', 'unmatched'];

if (!in_array($status, $allowedStatus, true))         { $status = 'all'; }
if (!in_array($confidence, $allowedConfidence, true)) { $confidence = 'all'; }

try {
    $where  = ['1=1'];
    $params = [];

    if ($status !== 'all') {
        $where[]  = "m.mapping_status = ?";
        $params[] = $status;
    }

    if ($confidence !== 'all') {
        if ($confidence === 'unmatched') {
            $where[] = "m.match_confidence IS NULL";
        } else {
            $where[]  = "m.match_confidence = ?";
            $params[] = $confidence;
        }
    }

    if ($q !== '') {
        // Match on either side's name. vendors has FULLTEXT idx but
        // LIKE is fine for the small mapping-row volumes here.
        $like = '%' . $q . '%';
        $where[] = "(v.name LIKE ? OR m.qbo_display_name LIKE ? OR m.qbo_company_name LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    // ── KPI counts (always full-set, ignore filters) ─────────────
    // KPI tiles show global state, not filtered subset.
    $kpiRows = db_select(
        "SELECT mapping_status, COUNT(*) AS n FROM acc_qbo_vendor_map GROUP BY mapping_status"
    );
    $kpis = ['mapped' => 0, 'ff_only' => 0, 'qbo_only' => 0, 'ignored' => 0];
    foreach ($kpiRows as $r) {
        $kpis[$r['mapping_status']] = (int) $r['n'];
    }

    $lastPulled = db_row(
        "SELECT MAX(last_pull_at) AS t FROM acc_qbo_vendor_map WHERE last_pull_at IS NOT NULL"
    );
    $lastPulledAt = $lastPulled['t'] ?? null;

    // ── Total (filtered) row count for pagination ────────────────
    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_vendor_map m
         LEFT JOIN vendors v ON v.id = m.ff_vendor_id
         WHERE {$whereSql}",
        $params
    );

    // ── Page query ──────────────────────────────────────────────
    // Sorted: mapped+manual first (operator-confirmed), then unmapped
    // by id desc (most recent first). LEFT JOIN vendors because
    // ff_vendor_id can be NULL on qbo_only rows.
    $offset = ($page - 1) * $pageSize;
    $rows = db_select(
        "SELECT
            m.id                AS mapping_id,
            m.ff_vendor_id,
            v.name              AS ff_name,
            v.email             AS ff_email,
            v.phone             AS ff_phone,
            m.qbo_vendor_id,
            m.qbo_display_name,
            m.qbo_company_name,
            m.qbo_given_name,
            m.qbo_family_name,
            m.qbo_email,
            m.qbo_phone,
            m.qbo_active,
            m.qbo_v4v_status,
            m.mapping_status,
            m.match_confidence,
            m.match_notes,
            m.last_synced_at,
            m.last_pull_at
         FROM acc_qbo_vendor_map m
         LEFT JOIN vendors v ON v.id = m.ff_vendor_id
         WHERE {$whereSql}
         ORDER BY
            CASE m.mapping_status
              WHEN 'mapped'   THEN 1
              WHEN 'ff_only'  THEN 2
              WHEN 'qbo_only' THEN 3
              WHEN 'ignored'  THEN 4
              ELSE 5
            END,
            m.id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );

    $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

    json_success([
        'kpis'           => $kpis,
        'last_pulled_at' => $lastPulledAt,
        'rows'           => $rows,
        'pagination'     => [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Vendors list failed: ' . $e->getMessage(), 500);
}
