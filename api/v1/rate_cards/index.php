<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/index.php
 *
 * Paginated list of rate cards (with item counts).
 *
 * Filters: is_default, is_active (effective date range), q (name LIKE).
 * Sort allowlist: name, effective_from, effective_to, status, created_at, updated_at.
 * Default sort: effective_from DESC.
 *
 * SOFT_DELETE: rate_cards has deleted_at — always AND rc.deleted_at IS NULL.
 * "Active" means is_default=1 OR (effective_from <= TODAY AND
 *   (effective_to IS NULL OR effective_to >= TODAY)).
 *
 * @method  GET
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([rate_card rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['rc.deleted_at IS NULL'];
$params = [];

// Filter: is_default
if (isset($_GET['is_default']) && $_GET['is_default'] !== '') {
    $where[]  = 'rc.is_default = ?';
    $params[] = (int)(bool)$_GET['is_default'];
}

// Filter: active — effective_from <= today AND (effective_to IS NULL OR effective_to >= today)
if (isset($_GET['active']) && $_GET['active'] !== '') {
    $today    = date('Y-m-d');
    $where[]  = 'rc.effective_from <= ?';
    $params[] = $today;
    $where[]  = '(rc.effective_to IS NULL OR rc.effective_to >= ?)';
    $params[] = $today;
}

// Filter: customer_id — '0' = global cards only, numeric = specific customer
if (isset($_GET['customer_id']) && $_GET['customer_id'] !== '') {
    $cid = clean_int($_GET['customer_id']);
    if ($cid === 0) {
        $where[] = 'rc.customer_id IS NULL';
    } else {
        $where[]  = 'rc.customer_id = ?';
        $params[] = $cid;
    }
}

// Filter: has_customer=1 — customer-specific cards only (any customer)
if (!empty($_GET['has_customer'])) {
    $where[] = 'rc.customer_id IS NOT NULL';
}

// Name search
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(rc.name LIKE ? OR c.company_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted
// -----------------------------------------------------------------------
$allowedSorts = ['name', 'effective_from', 'effective_to', 'status', 'created_at', 'updated_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'effective_from';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows — include item_count per card
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     WHERE $whereSQL",
    $params
);

$rows = db_select(
    "SELECT
         rc.id, rc.name, rc.description, rc.is_default,
         rc.effective_from, rc.effective_to,
         rc.customer_id, c.company_name AS customer_name,
         rc.created_by, rc.created_at, rc.updated_at,
         (SELECT COUNT(*) FROM rate_card_items rci
          WHERE rci.rate_card_id = rc.id) AS item_count,
         u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY rc.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

// Add computed is_active flag for each card (useful for UI badges)
$today = date('Y-m-d');
foreach ($rows as &$row) {
    $row['is_active'] = (
        $row['effective_from'] <= $today &&
        ($row['effective_to'] === null || $row['effective_to'] >= $today)
    ) ? 1 : 0;
}
unset($row);

// When include_items=1, nest rate_card_items within each card row so the
// customer panel can show individual items without a per-card API round-trip.
if (!empty($_GET['include_items']) && $rows) {
    $cardIds = array_column($rows, 'id');
    $in      = implode(',', array_fill(0, count($cardIds), '?'));
    $items   = db_select(
        "SELECT rate_card_id, equipment_type,
                daily_rate, weekly_rate, monthly_rate,
                mileage_rate, mileage_unit, currency, notes
         FROM rate_card_items
         WHERE rate_card_id IN ($in)
         ORDER BY rate_card_id ASC, equipment_type ASC",
        $cardIds
    );
    $byCard = [];
    foreach ($items as $item) {
        $byCard[$item['rate_card_id']][] = $item;
    }
    foreach ($rows as &$row) {
        $row['items'] = $byCard[$row['id']] ?? [];
    }
    unset($row);
}

json_paginated($rows, $total, $page, $perPage);
