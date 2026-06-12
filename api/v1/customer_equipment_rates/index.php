<?php
declare(strict_types=1);

/**
 * api/v1/customer_equipment_rates/index.php
 *
 * List all custom rate overrides for a specific customer.
 *
 * Requires customer_id query parameter. Returns all active overrides
 * sorted by equipment_type ASC.
 * Also supports listing all overrides across all customers (admin view)
 * when customer_id is omitted, but that requires rates+view permission.
 *
 * Note: customer_equipment_rates has NO deleted_at — records are hard-deleted.
 *
 * @method  GET
 * @query   customer_id? (if omitted returns all across customers, paginated)
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([rate override rows], total, page, per_page)
 *
 * Decisions: D7 (routing), §10 (list pattern)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['1=1'];
$params = [];

if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    // Verify customer exists and is not soft-deleted
    if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
        json_error('NOT_FOUND', 'Customer not found.', 404);
    }
    $where[]  = 'cer.customer_id = ?';
    $params[] = $customerId;
}

if ($equipType = clean_string($_GET['equipment_type'] ?? null)) {
    $where[]  = 'cer.equipment_type = ?';
    $params[] = $equipType;
}

// Generic q search — matches customer name or equipment type
if ($q = clean_string($_GET['q'] ?? null, 255)) {
    $like     = '%' . $q . '%';
    $where[]  = '(c.company_name LIKE ? OR cer.equipment_type LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted
// -----------------------------------------------------------------------
$allowedSorts = ['equipment_type', 'created_at', 'effective_from'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'equipment_type';
$dir  = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 50) ?? 50));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows — join customer name for cross-customer view
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM customer_equipment_rates cer
     JOIN customers c ON c.id = cer.customer_id AND c.deleted_at IS NULL
     WHERE $whereSQL",
    $params
);

$rows = db_select(
    "SELECT
         cer.id, cer.customer_id, cer.equipment_type,
         cer.daily_rate, cer.weekly_rate, cer.monthly_rate,
         cer.mileage_rate, cer.mileage_unit, cer.currency,
         cer.minimum_charge, cer.notes,
         cer.effective_from, cer.effective_to,
         cer.created_by, cer.created_at, cer.updated_at,
         c.company_name AS customer_name,
         u.name AS created_by_name
     FROM customer_equipment_rates cer
     JOIN customers c ON c.id = cer.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = cer.created_by AND u.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY cer.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
