<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/index.php
 *
 * Paginated list of maintenance work orders with optional filters and sort.
 *
 * Filters: status, work_type, priority, equipment_unit_id, vendor_id,
 *          date_from, date_to (on requested_date), q (LIKE on title/WO#).
 * Sort allowlist: requested_date, total_cost, work_order_number, status, priority,
 *                  completed_date, scheduled_date, updated_at, vendor_name.
 * Default sort: requested_date DESC.
 *
 * SOFT_DELETE: maintenance_work_orders has deleted_at — always AND mwo.deleted_at IS NULL.
 * JOINs: equipment_units (eu) + equipment_templates (et) for the model label,
 *        equipment_brands (eb) via eu.brand_id for the brand label.
 *        vendors (v) for vendor name. users (u) for assigned_to name.
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 json_paginated([work_order rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['mwo.deleted_at IS NULL'];
$params = [];

// Filter: status ENUM
if ($status = clean_string($_GET['status'] ?? null)) {
    $validStatuses = ['open', 'in_progress', 'waiting_parts', 'completed', 'cancelled'];
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'mwo.status = ?';
        $params[] = $status;
    }
}

// Filter: work_type ENUM
if ($workType = clean_string($_GET['work_type'] ?? null)) {
    $validTypes = ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'];
    if (in_array($workType, $validTypes, true)) {
        $where[]  = 'mwo.work_type = ?';
        $params[] = $workType;
    }
}

// Filter: priority ENUM
if ($priority = clean_string($_GET['priority'] ?? null)) {
    $validPriorities = ['low', 'medium', 'high', 'emergency'];
    if (in_array($priority, $validPriorities, true)) {
        $where[]  = 'mwo.priority = ?';
        $params[] = $priority;
    }
}

// Filter: equipment_unit_id
if ($unitId = clean_int($_GET['equipment_unit_id'] ?? null)) {
    $where[]  = 'mwo.equipment_unit_id = ?';
    $params[] = $unitId;
}

// Filter: vendor_id
if ($vendorId = clean_int($_GET['vendor_id'] ?? null)) {
    $where[]  = 'mwo.vendor_id = ?';
    $params[] = $vendorId;
}

// Filter: date_from (requested_date >=)
if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'mwo.requested_date >= ?';
    $params[] = $dateFrom;
}

// Filter: date_to (requested_date <=)
if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'mwo.requested_date <= ?';
    $params[] = $dateTo;
}

// Search: title or work_order_number (LIKE — no FULLTEXT index on these cols)
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(mwo.title LIKE ? OR mwo.work_order_number LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted
// -----------------------------------------------------------------------
$allowedSorts = ['requested_date', 'total_cost', 'work_order_number', 'status', 'priority',
                 'completed_date', 'scheduled_date', 'updated_at', 'vendor_name'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'requested_date';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
// vendor_name is a JOIN alias — must reference it without the mwo. table prefix
$orderExpr = ($sort === 'vendor_name') ? "$sort $dir" : "mwo.$sort $dir";

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows
// -----------------------------------------------------------------------
$total = db_count("SELECT COUNT(*) FROM maintenance_work_orders mwo WHERE $whereSQL", $params);

$rows = db_select(
    "SELECT
         mwo.id, mwo.work_order_number, mwo.work_type, mwo.priority, mwo.status,
         mwo.title, mwo.requested_date, mwo.scheduled_date, mwo.completed_date,
         mwo.labor_cost, mwo.parts_cost, mwo.total_cost,
         mwo.equipment_unit_id, mwo.vendor_id, mwo.assigned_to,
         mwo.created_at, mwo.updated_at,
         eu.unit_number,
         eb.label AS brand, et.model,
         v.name AS vendor_name,
         u.name AS assigned_to_name
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     LEFT JOIN vendors v ON v.id = mwo.vendor_id AND v.deleted_at IS NULL
     LEFT JOIN users u ON u.id = mwo.assigned_to AND u.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY $orderExpr
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
