<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/customer_groups.php
 *
 * Paginated, name-searchable list of CUSTOMERS that have at least one
 * customer-specific rate card — one row per customer with its card count.
 *
 * WHY: the rates dashboard groups customer-specific rate cards under each
 * customer (S-RATES-CONSOLIDATE — overrides retired, rate cards are the
 * single per-customer pricing mechanism). This endpoint paginates the
 * customer list itself so the dashboard renders only a page of customers
 * at a time; each customer's actual cards are lazy-loaded on expand via
 * rate_cards/index?customer_id=.
 *
 * D5: rate_cards has deleted_at — always filtered. customers are
 * soft-deleted and always filtered.
 *
 * @method  GET
 * @query   q? (customer name LIKE), page?, per_page?
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([{customer_id, customer_name, card_count}], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S-RATES-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Filters — only customer-specific, non-deleted cards on live customers
// -----------------------------------------------------------------------
$where  = ['rc.deleted_at IS NULL', 'rc.customer_id IS NOT NULL', 'c.deleted_at IS NULL'];
$params = [];

if ($q = clean_string($_GET['q'] ?? null, 255)) {
    $where[]  = 'c.company_name LIKE ?';
    $params[] = '%' . $q . '%';
}

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 2. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(5, clean_int($_GET['per_page'] ?? 15) ?? 15));
$offset  = ($page - 1) * $perPage;

// -----------------------------------------------------------------------
// 3. Count distinct customers (for pagination total)
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM (
         SELECT rc.customer_id
         FROM rate_cards rc
         JOIN customers c ON c.id = rc.customer_id
         WHERE $whereSQL
         GROUP BY rc.customer_id
     ) t",
    $params
);

// -----------------------------------------------------------------------
// 4. One row per customer with card count, name-sorted
// -----------------------------------------------------------------------
$rows = db_select(
    "SELECT rc.customer_id,
            c.company_name AS customer_name,
            COUNT(*)       AS card_count
     FROM rate_cards rc
     JOIN customers c ON c.id = rc.customer_id
     WHERE $whereSQL
     GROUP BY rc.customer_id, c.company_name
     ORDER BY c.company_name ASC
     LIMIT $perPage OFFSET $offset",
    $params
);

foreach ($rows as &$row) {
    $row['card_count'] = (int) $row['card_count'];
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
