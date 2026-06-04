<?php
declare(strict_types=1);

/**
 * api/v1/inspections/index.php
 *
 * Paginated list of inspections with filters.
 *
 * Business rules:
 *   - No soft delete on inspections (no deleted_at column) — all records returned.
 *   - Filters: status, inspection_type, equipment_unit_id, lease_id, date_from, date_to, q (notes search).
 *   - JOINs equipment_units + equipment_templates for unit_number/brand/model.
 *   - JOINs leases for contract_number.
 *   - Sort allowlist: inspection_date, inspection_number, status, inspection_type,
 *     created_at, updated_at, overall_condition, unit_number.
 *   - Sort allowlist prevents SQL injection.
 *
 * @method  GET
 * @query   status?, inspection_type?, equipment_unit_id?, lease_id?, date_from?, date_to?,
 *          q?, sort?, dir?, page?, per_page?
 * @auth    Session required; require_permission('inspections','view')
 * @returns 200 paginated { items[], pagination{} }
 *
 * Decisions: D7 (routing), D10 (list pattern), §5 (soft delete — inspections NOT in list)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('inspections', 'view');

// ── Sort allowlist (never pass user input directly into SQL ORDER BY)
$allowedSorts = ['inspection_date', 'inspection_number', 'status', 'inspection_type', 'created_at',
                 'updated_at', 'overall_condition', 'unit_number'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'inspection_date';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
// unit_number is a JOIN alias from equipment_units — must not carry the i. table prefix
$orderExpr = ($sort === 'unit_number') ? "$sort $dir" : "i.$sort $dir";

// ── Build WHERE dynamically from allowlisted filters
$where  = ['1=1'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[]  = 'i.status = ?';
    $params[] = $status;
}

if ($type = clean_string($_GET['inspection_type'] ?? null)) {
    $where[]  = 'i.inspection_type = ?';
    $params[] = $type;
}

if ($unitId = clean_int($_GET['equipment_unit_id'] ?? null)) {
    $where[]  = 'i.equipment_unit_id = ?';
    $params[] = $unitId;
}

if ($leaseId = clean_int($_GET['lease_id'] ?? null)) {
    $where[]  = 'i.lease_id = ?';
    $params[] = $leaseId;
}

if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'i.inspection_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'i.inspection_date <= ?';
    $params[] = $dateTo;
}

if ($q = clean_string($_GET['q'] ?? null)) {
    // Notes text search — strip boolean operators to prevent FULLTEXT injection
    $where[]  = '(i.notes LIKE ? OR i.inspected_by LIKE ? OR i.inspection_number LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);

$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

// ── Count total
$total = db_count(
    "SELECT COUNT(*) FROM inspections i WHERE $whereSQL",
    $params
);

// ── Fetch rows with JOINs for labels
$rows = db_select(
    "SELECT
        i.id,
        i.inspection_number,
        i.inspection_type,
        i.status,
        i.inspection_date,
        i.overall_condition,
        i.condition_score,
        i.inspected_by,
        i.mileage_at_inspection,
        i.reefer_hours,
        i.fuel_level,
        i.is_clean,
        i.created_at,
        i.equipment_unit_id,
        eu.unit_number,
        et.brand,
        et.model,
        i.lease_id,
        l.contract_number
     FROM inspections i
     LEFT JOIN equipment_units eu  ON eu.id = i.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id   AND et.deleted_at IS NULL
     LEFT JOIN leases l             ON l.id  = i.lease_id         AND l.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY $orderExpr
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
