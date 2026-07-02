<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/items/list.php
 *
 * List query for the Items Mapping page. Returns paginated mapping
 * rows + KPI counts. Differs from tax_codes/list.php in that there is
 * no FF table to JOIN — the FF side is just the (ff_item_type,
 * ff_item_type_variant) tuple stored directly on the mapping row.
 *
 * The UI grouping (Rental & Mileage / Fees / Other Recoveries /
 * Adjustments) is computed server-side via ItemMatcher::UI_CATEGORIES
 * and added to each row's response so the UI doesn't need to duplicate
 * the category map.
 *
 * Filters:
 *   status (mapped|ff_only|qbo_only|ignored|all)
 *   q (search) — matches ff_item_type, qbo_name, qbo_description, qbo_income_account_name
 *   show_inactive_qbo (true|false) — default false; when false, qbo_active=0 rows excluded
 *   page, page_size (default 100 — fits all 18+ FF tuples + mapped/unmapped QBO Items)
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 *
 * Spec ref: §7.3
 * Session:  S-QBO-10
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\ItemMatcher;

$status          = (string) ($_GET['status'] ?? 'all');
$q               = trim((string) ($_GET['q'] ?? ''));
$showInactiveQbo = isset($_GET['show_inactive_qbo']) && $_GET['show_inactive_qbo'] === 'true';
$page            = max(1, (int) ($_GET['page'] ?? 1));
$pageSize        = max(1, min(500, (int) ($_GET['page_size'] ?? 100)));

$allowedStatus = ['all', 'mapped', 'ff_only', 'qbo_only', 'ignored'];
if (!in_array($status, $allowedStatus, true)) { $status = 'all'; }

try {
    $where  = ['1=1'];
    $params = [];

    if ($status !== 'all') {
        $where[]  = "mapping_status = ?";
        $params[] = $status;
    }

    if (!$showInactiveQbo) {
        // Exclude QBO-side inactive rows. ff_only rows have qbo_active
        // = NULL — keep them.
        $where[] = "(qbo_active IS NULL OR qbo_active = 1)";
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(ff_item_type LIKE ? OR qbo_name LIKE ? OR qbo_description LIKE ? OR qbo_income_account_name LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    // KPIs (global, status-axis only; respects show_inactive_qbo filter).
    $kpiRows = db_select(
        "SELECT mapping_status, COUNT(*) AS n
           FROM acc_qbo_item_map
          WHERE (qbo_active IS NULL OR qbo_active = 1 OR ? = 1)
          GROUP BY mapping_status",
        [$showInactiveQbo ? 1 : 0]
    );
    $kpis = ['mapped' => 0, 'ff_only' => 0, 'qbo_only' => 0, 'ignored' => 0];
    foreach ($kpiRows as $r) {
        $kpis[$r['mapping_status']] = (int) $r['n'];
    }
    $kpis['auto_created'] = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_item_map WHERE match_confidence = 'auto_created'"
    );

    $lastPulled = db_row(
        "SELECT MAX(last_pull_at) AS t FROM acc_qbo_item_map WHERE last_pull_at IS NOT NULL"
    );
    $lastPulledAt = $lastPulled['t'] ?? null;

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_item_map WHERE {$whereSql}",
        $params
    );

    $offset = ($page - 1) * $pageSize;
    $rows = db_select(
        "SELECT
            id                       AS mapping_id,
            ff_item_type,
            ff_item_type_variant,
            qbo_item_id,
            qbo_sync_token,
            qbo_name,
            qbo_fully_qualified_name,
            qbo_description,
            qbo_type,
            qbo_active,
            qbo_income_account_id,
            qbo_income_account_name,
            qbo_expense_account_id,
            qbo_expense_account_name,
            mapping_status,
            match_confidence,
            is_credit_variant,
            presentation_variant,
            match_notes,
            last_synced_at,
            last_pull_at
         FROM acc_qbo_item_map
         WHERE {$whereSql}
         ORDER BY
            -- Group by FF category for stable presentation
            CASE
              WHEN ff_item_type IN ('base_rental','base_rental_reconciliation_credit','gps',
                                    'mileage_precharge','mileage_estimate','mileage_adjustment','mileage_credit',
                                    'mileage_usage','mileage_drawdown_credit') THEN 1
              WHEN ff_item_type IN ('late_fee','manual_adjustment') THEN 2
              WHEN ff_item_type IN ('damage','insurance','warranty') THEN 3
              WHEN ff_item_type IN ('discount','early_return_credit','account_credit_applied','other') THEN 4
              ELSE 5
            END,
            ff_item_type,
            ff_item_type_variant,
            mapping_status,
            qbo_name,
            id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );

    // Decorate rows with computed display fields.
    foreach ($rows as &$row) {
        if ($row['ff_item_type'] !== null) {
            $row['ff_display_name'] = ItemMatcher::displayNameFor(
                (string) $row['ff_item_type'],
                $row['ff_item_type_variant']
            );
            $row['ff_category'] = self_category_for($row['ff_item_type']);
        } else {
            $row['ff_display_name'] = null;
            $row['ff_category']     = 'QBO Only';
        }
    }
    unset($row);

    $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

    json_success([
        'kpis'                  => $kpis,
        'last_pulled_at'        => $lastPulledAt,
        'ui_categories'         => ItemMatcher::UI_CATEGORIES,
        'rows'                  => $rows,
        'pagination'            => [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Items list failed: ' . $e->getMessage(), 500);
}

/**
 * Map an ff_item_type to its UI category label.
 * Lookup is small enough that we just inline it.
 */
function self_category_for(string $itemType): string
{
    foreach (ItemMatcher::UI_CATEGORIES as $label => $types) {
        if (in_array($itemType, $types, true)) {
            return $label;
        }
    }
    return 'Other';
}
