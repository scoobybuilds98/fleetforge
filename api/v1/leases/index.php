<?php
declare(strict_types=1);

/**
 * FleetForge — Leases List API
 *
 * @file        api/v1/leases/index.php
 * @description Returns a paginated list of leases with optional filters.
 *              Supports FULLTEXT search on contract_number, company_name_snapshot,
 *              unit_number_snapshot. Filters: status, customer_id, equipment_unit_id.
 *              Default sort: created_at DESC (spec §4.1).
 *              JOINs customers + equipment_units for live names (snapshots as fallback).
 *              Both leases.deleted_at and customers.deleted_at checked per D5.
 *
 * @method      GET
 * @query       search, status, customer_id, unit_id, page, per_page, sort, dir
 * @auth        Session required; require_permission('leases','view')
 * @returns     200 paginated { items, pagination }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D5 (soft-delete), D7 (base path), D19 (optimistic lock)
 * @session     S007
 */

// dirname(__DIR__, 3): api/v1/leases/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('leases', 'view');

// ── Allowlisted sort columns ───────────────────────────────────
$allowedSorts = ['created_at', 'start_date', 'end_date', 'contract_number', 'status'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// ── Filters ────────────────────────────────────────────────────
$where  = ['l.deleted_at IS NULL'];
$params = [];

// Status filter — validate against allowed values
$allowedStatuses = ['pending', 'active', 'completed', 'cancelled'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $allowedStatuses)) {
        $where[]  = 'l.status = ?';
        $params[] = $status;
    }
}

if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'l.customer_id = ?';
    $params[] = $customerId;
}

if ($unitId = clean_int($_GET['unit_id'] ?? null)) {
    $where[]  = 'l.equipment_unit_id = ?';
    $params[] = $unitId;
}

// LIKE-based substring search — supports partial matches
if ($search = clean_string($_GET['search'] ?? null)) {
    $like    = '%' . $search . '%';
    $where[] = '(l.contract_number LIKE ? OR l.company_name_snapshot LIKE ? OR l.unit_number_snapshot LIKE ?)';
    array_push($params, $like, $like, $like);
}

$whereSQL = implode(' AND ', $where);

// ── Pagination ─────────────────────────────────────────────────
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

// ── Count ──────────────────────────────────────────────────────
$total = db_count("SELECT COUNT(*) FROM leases l WHERE $whereSQL", $params);

// ── Rows ───────────────────────────────────────────────────────
// LEFT JOIN customers + units for live display names
// D5: also check c.deleted_at IS NULL in the JOIN condition (not WHERE — keep soft-deleted customer data visible via snapshot)
$rows = db_select(
    "SELECT
        l.id,
        l.contract_number,
        l.customer_id,
        l.equipment_unit_id,
        l.company_name_snapshot,
        l.customer_name_snapshot,
        l.unit_number_snapshot,
        l.template_name_snapshot,
        l.status,
        l.start_date,
        l.end_date,
        l.actual_return_date,
        l.daily_rate,
        l.weekly_rate,
        l.monthly_rate,
        l.currency,
        l.billing_cycle,
        l.outstanding_balance,
        l.total_invoiced,
        l.next_billing_date,
        l.po_number,
        -- S-LEASE-MILEAGE: surface mileage totals on the lease list so the
        -- equipment-unit Lease History tab can show total km without an
        -- extra round-trip per row.
        l.estimated_mileage_km,
        l.mileage_unit,
        l.odometer_start_km,
        l.odometer_end_km,
        l.total_distance_km,
        l.created_at,
        l.updated_at,
        COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
        COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY l.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
