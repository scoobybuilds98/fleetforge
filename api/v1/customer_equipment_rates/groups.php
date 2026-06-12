<?php
declare(strict_types=1);

/**
 * api/v1/customer_equipment_rates/groups.php
 *
 * @deprecated S-RATES-CONSOLIDATE — customer pricing moved onto rate cards;
 *   no UI calls this endpoint. Kept for reversibility until a cleanup migration.
 *
 * Paginated, name-searchable list of CUSTOMERS that have at least one
 * rate override — one row per customer with its override count.
 *
 * WHY: the rates dashboard previously loaded every override row and
 * grouped them client-side, rendering one accordion per customer. That
 * does not scale to thousands of customers. This endpoint paginates the
 * customer list itself and supports a server-side name search, so the
 * dashboard renders only a page of customers at a time and the actual
 * override cards are lazy-loaded per customer via index.php?customer_id=.
 *
 * customer_equipment_rates has NO deleted_at (hard-deleted); customers
 * are soft-deleted and always filtered.
 *
 * @method  GET
 * @query   q? (customer name LIKE), page?, per_page?
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([{customer_id, customer_name, override_count}], total, page, per_page)
 *
 * Decisions: D7 (routing), §10 (list pattern)
 * Session: S-RATES-CUSTOMER-PAGINATION
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Filters — optional customer-name search
// -----------------------------------------------------------------------
$where  = ['c.deleted_at IS NULL'];
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
         SELECT cer.customer_id
         FROM customer_equipment_rates cer
         JOIN customers c ON c.id = cer.customer_id
         WHERE $whereSQL
         GROUP BY cer.customer_id
     ) t",
    $params
);

// -----------------------------------------------------------------------
// 4. One row per customer with override count, name-sorted
// -----------------------------------------------------------------------
$rows = db_select(
    "SELECT cer.customer_id,
            c.company_name AS customer_name,
            COUNT(*)       AS override_count
     FROM customer_equipment_rates cer
     JOIN customers c ON c.id = cer.customer_id
     WHERE $whereSQL
     GROUP BY cer.customer_id, c.company_name
     ORDER BY c.company_name ASC
     LIMIT $perPage OFFSET $offset",
    $params
);

// Normalise count to int for the UI
foreach ($rows as &$row) {
    $row['override_count'] = (int)$row['override_count'];
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
