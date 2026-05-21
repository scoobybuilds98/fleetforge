<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/accounts/list.php
 *
 * List query for the Accounts Mapping page. Returns paginated mapping
 * rows joined to FF acc_accounts data, plus KPI counts including the
 * D-QBO-8-2 'critical_unmapped' metric (bridge accounts that block
 * downstream invoice push).
 *
 * Filters:
 *   status (mapped|ff_only|qbo_only|ignored|all)
 *   type (asset|liability|equity|revenue|cost_of_revenue|
 *         operating_expense|other_income|other_expense|all)
 *         — matches FF acc_accounts.account_type ENUM (8 lowercase values
 *         per S-QBO-8 pre-flight)
 *   critical_only (true|false) — filter to bridge accounts only
 *   q (search) — matches FF code OR name OR QBO name
 *   page, page_size (default 50, max 200)
 *
 * Returns:
 *   {
 *     success: true,
 *     data: {
 *       kpis: { mapped, ff_only, qbo_only, ignored, critical_unmapped },
 *       last_pulled_at: string|null,
 *       rows: [
 *         { mapping_id, ff_account_id, ff_code, ff_name, ff_account_type,
 *           ff_account_subtype, qbo_account_id, qbo_name, qbo_account_type,
 *           qbo_account_subtype, qbo_account_number, qbo_active,
 *           qbo_current_balance, mapping_status, match_confidence,
 *           is_critical, critical_reason, last_synced_at, last_pull_at }
 *       ],
 *       pagination: { page, page_size, total, total_pages }
 *     }
 *   }
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 *
 * Spec ref: §7.1
 * Session:  S-QBO-8
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status        = (string) ($_GET['status'] ?? 'all');
$type          = (string) ($_GET['type']   ?? 'all');
$criticalOnly  = isset($_GET['critical_only']) && $_GET['critical_only'] === 'true';
$q             = trim((string) ($_GET['q'] ?? ''));
$page          = max(1, (int) ($_GET['page'] ?? 1));
$pageSize      = max(1, min(200, (int) ($_GET['page_size'] ?? 50)));

$allowedStatus = ['all', 'mapped', 'ff_only', 'qbo_only', 'ignored'];
$allowedType   = ['all', 'asset', 'liability', 'equity', 'revenue',
                  'cost_of_revenue', 'operating_expense',
                  'other_income', 'other_expense'];

if (!in_array($status, $allowedStatus, true)) { $status = 'all'; }
if (!in_array($type,   $allowedType,   true)) { $type   = 'all'; }

try {
    $where  = ['1=1'];
    $params = [];

    if ($status !== 'all') {
        $where[]  = "m.mapping_status = ?";
        $params[] = $status;
    }

    if ($type !== 'all') {
        $where[]  = "a.account_type = ?";
        $params[] = $type;
    }

    if ($criticalOnly) {
        $where[] = "m.is_critical = 1";
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(a.code LIKE ? OR a.name LIKE ? OR m.qbo_name LIKE ? OR m.qbo_fully_qualified_name LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    // KPIs (global, ignore filters).
    $kpiRows = db_select(
        "SELECT mapping_status, COUNT(*) AS n FROM acc_qbo_account_map GROUP BY mapping_status"
    );
    $kpis = ['mapped' => 0, 'ff_only' => 0, 'qbo_only' => 0, 'ignored' => 0];
    foreach ($kpiRows as $r) {
        $kpis[$r['mapping_status']] = (int) $r['n'];
    }
    // critical_unmapped count (bridge-account validator metric).
    $criticalUnmapped = (int) db_count(
        "SELECT COUNT(*)
           FROM acc_qbo_account_map m
           JOIN acc_accounts a ON a.id = m.ff_account_id
          WHERE m.is_critical = 1
            AND (m.mapping_status != 'mapped' OR m.qbo_account_id IS NULL)
            AND a.is_active = 1",
        []
    );
    $kpis['critical_unmapped'] = $criticalUnmapped;

    $lastPulled = db_row(
        "SELECT MAX(last_pull_at) AS t FROM acc_qbo_account_map WHERE last_pull_at IS NOT NULL"
    );
    $lastPulledAt = $lastPulled['t'] ?? null;

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_account_map m
         LEFT JOIN acc_accounts a ON a.id = m.ff_account_id
         WHERE {$whereSql}",
        $params
    );

    $offset = ($page - 1) * $pageSize;
    $rows = db_select(
        "SELECT
            m.id                       AS mapping_id,
            m.ff_account_id,
            a.code                     AS ff_code,
            a.name                     AS ff_name,
            a.account_type             AS ff_account_type,
            a.account_subtype          AS ff_account_subtype,
            a.is_system                AS ff_is_system,
            m.qbo_account_id,
            m.qbo_name,
            m.qbo_fully_qualified_name,
            m.qbo_account_type,
            m.qbo_account_subtype,
            m.qbo_account_number,
            m.qbo_active,
            m.qbo_current_balance,
            m.mapping_status,
            m.match_confidence,
            m.is_critical,
            m.critical_reason,
            m.match_notes,
            m.last_synced_at,
            m.last_pull_at
         FROM acc_qbo_account_map m
         LEFT JOIN acc_accounts a ON a.id = m.ff_account_id
         WHERE {$whereSql}
         ORDER BY
            -- Critical-unmapped first (operator's blocker list)
            CASE WHEN m.is_critical = 1 AND (m.mapping_status != 'mapped' OR m.qbo_account_id IS NULL) THEN 0 ELSE 1 END,
            -- Then by FF account code natural order (1xxx, 2xxx, ...)
            CASE WHEN a.code IS NULL THEN 1 ELSE 0 END,
            a.code,
            -- Then qbo_only rows (sorted by QBO account number)
            m.qbo_account_number,
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
    json_error('INTERNAL_ERROR', 'Accounts list failed: ' . $e->getMessage(), 500);
}
