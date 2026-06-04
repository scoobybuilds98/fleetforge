<?php
declare(strict_types=1);

// ============================================================
// GET /api/v1/mileage_logs/index
//
// Paginated list of mileage log entries.
//
// Filters (all optional):
//   equipment_unit_id — filter to one unit
//   lease_id          — filter to one lease
//   log_type          — ENUM: manual|gps_sync|lease_start|lease_end|service
//   date_from         — Y-m-d inclusive
//   date_to           — Y-m-d inclusive
//
// Sort: log_date DESC (default), also allows log_date ASC and
//       odometer_reading ASC/DESC.
//
// Permission: maintenance view
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// ── Allowed sort columns (never allow raw user input in ORDER BY)
$allowedSorts = ['log_date', 'odometer_reading', 'log_type', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'log_date';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// ── Filters
$where  = ['ml.deleted_at IS NULL']; // mileage_logs has no deleted_at — always false, but safe
// Actually mileage_logs has no deleted_at, so we just build a true WHERE
$where  = ['1=1'];
$params = [];

if ($unitId = clean_int($_GET['equipment_unit_id'] ?? null)) {
    $where[]  = 'ml.equipment_unit_id = ?';
    $params[] = $unitId;
}

if ($leaseId = clean_int($_GET['lease_id'] ?? null)) {
    $where[]  = 'ml.lease_id = ?';
    $params[] = $leaseId;
}

// WHY: customer_id has no direct column — filter via lease ownership
if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'ml.lease_id IN (SELECT id FROM leases WHERE customer_id = ? AND deleted_at IS NULL)';
    $params[] = $customerId;
}

if ($logType = clean_string($_GET['log_type'] ?? null)) {
    $validTypes = ['manual', 'gps_sync', 'lease_start', 'lease_end', 'service'];
    if (in_array($logType, $validTypes, true)) {
        $where[]  = 'ml.log_type = ?';
        $params[] = $logType;
    }
}

if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'ml.log_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'ml.log_date <= ?';
    $params[] = $dateTo;
}

$whereSQL = implode(' AND ', $where);

// ── Pagination
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

// ── Count
$total = db_count(
    "SELECT COUNT(*)
     FROM mileage_logs ml
     WHERE $whereSQL",
    $params
);

// ── Rows — join equipment_units + templates for context, users for recorded_by_name
$rows = db_select(
    "SELECT
        ml.id,
        ml.equipment_unit_id,
        ml.lease_id,
        ml.log_type,
        ml.odometer_reading,
        ml.mileage_unit,
        ml.log_date,
        ml.notes,
        ml.recorded_by,
        ml.created_at,
        eu.unit_number,
        et.brand,
        et.model,
        u.name AS recorded_by_name
     FROM mileage_logs ml
     JOIN equipment_units eu ON eu.id = ml.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN users u ON u.id = ml.recorded_by
     WHERE $whereSQL
     ORDER BY ml.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
