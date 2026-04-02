<?php
declare(strict_types=1);

/**
 * api/v1/vendors/index.php
 *
 * Paginated list of vendors with optional filters and sort.
 *
 * Filters: vendor_type, is_preferred, q (FULLTEXT on name + contact_name).
 * Sort allowlist: name, total_spent, rating, created_at.
 * Default sort: name ASC.
 *
 * SOFT_DELETE: vendors has deleted_at — always AND v.deleted_at IS NULL.
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 json_paginated([vendor rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['v.deleted_at IS NULL'];
$params = [];

// Filter: vendor_type ENUM
if ($vendorType = clean_string($_GET['vendor_type'] ?? null)) {
    $validTypes = ['maintenance', 'repair', 'parts', 'inspection', 'towing', 'other'];
    if (in_array($vendorType, $validTypes, true)) {
        $where[]  = 'v.vendor_type = ?';
        $params[] = $vendorType;
    }
}

// Filter: is_preferred boolean
if (isset($_GET['is_preferred']) && $_GET['is_preferred'] !== '') {
    $where[]  = 'v.is_preferred = ?';
    $params[] = (int)(bool)$_GET['is_preferred'];
}

// Full-text search on name + contact_name (FULLTEXT index idx_ft_vendors)
// Fall back to LIKE if the search term is too short for FULLTEXT (< 3 chars)
if ($q = clean_string($_GET['q'] ?? null)) {
    // Strip MySQL boolean operators to prevent injection
    $safe     = preg_replace('/[+\-<>()~*\"@]/', '', $q);
    $where[]  = 'MATCH(v.name, v.contact_name) AGAINST(? IN BOOLEAN MODE)';
    $params[] = $safe . '*';
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted, never from raw user input
// -----------------------------------------------------------------------
$allowedSorts = ['name', 'total_spent', 'rating', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'name';
$dir  = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows — include work_order_count for display
// -----------------------------------------------------------------------
$total = db_count("SELECT COUNT(*) FROM vendors v WHERE $whereSQL", $params);

$rows = db_select(
    "SELECT
         v.id, v.name, v.vendor_type, v.contact_name, v.email, v.phone,
         v.city, v.state, v.is_preferred, v.rating, v.hourly_rate,
         v.total_spent, v.specializations, v.created_at, v.updated_at,
         (SELECT COUNT(*) FROM maintenance_work_orders mwo
          WHERE mwo.vendor_id = v.id
            AND mwo.deleted_at IS NULL) AS work_order_count
     FROM vendors v
     WHERE $whereSQL
     ORDER BY v.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

// Decode JSON specializations for each row
foreach ($rows as &$row) {
    $row['specializations'] = $row['specializations']
        ? json_decode($row['specializations'], true)
        : [];
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
