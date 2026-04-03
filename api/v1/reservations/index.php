<?php
declare(strict_types=1);

/**
 * FleetForge — Reservations List API
 *
 * @file        api/v1/reservations/index.php
 * @description Returns a paginated, filterable list of reservations.
 *              JOINs reservation_units to surface unit info per reservation.
 *              Soft-delete enforced on both reservations and customers (D5).
 *
 *              Filters: status, pickup_date (exact), pickup_date_from/to (range),
 *              customer_id, q (LIKE on contact_name + company_name).
 *              Sort: pickup_date (default ASC), created_at, company_name, status.
 *
 *              Each row includes a JSON-aggregated `units` array so the UI can
 *              render unit numbers without a second round-trip.
 *
 * @method      GET
 * @query       status, pickup_date, pickup_date_from, pickup_date_to,
 *              customer_id, q, page, per_page, sort, dir
 * @auth        Session required; require_permission('reservations','view')
 * @returns     200 json_paginated { items, pagination }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D5 (soft-delete), D7 (base path)
 * @session     S018
 */

// dirname(__DIR__, 3): api/v1/reservations/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('reservations', 'view');

// ── Allowlisted sort columns ───────────────────────────────────
$allowedSorts = ['pickup_date', 'created_at', 'company_name', 'status', 'priority'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'pickup_date';
$dir  = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

// ── Filters ────────────────────────────────────────────────────
$where  = ['r.deleted_at IS NULL'];
$params = [];

// Status filter — validate against allowed ENUM values
$allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $allowedStatuses)) {
        $where[]  = 'r.status = ?';
        $params[] = $status;
    }
}

// Exact pickup date (for dashboard "Today's Pickups" link)
if ($pickupDate = clean_date($_GET['pickup_date'] ?? null)) {
    $where[]  = 'r.pickup_date = ?';
    $params[] = $pickupDate;
}

// Date range
if ($dateFrom = clean_date($_GET['pickup_date_from'] ?? null)) {
    $where[]  = 'r.pickup_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo = clean_date($_GET['pickup_date_to'] ?? null)) {
    $where[]  = 'r.pickup_date <= ?';
    $params[] = $dateTo;
}

// Customer filter
if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'r.customer_id = ?';
    $params[] = $customerId;
}

// Text search — LIKE on contact_name + company_name (no FULLTEXT on reservations)
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(r.contact_name LIKE ? OR r.company_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);

// ── Pagination ─────────────────────────────────────────────────
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

// ── Count ──────────────────────────────────────────────────────
$total = db_count(
    "SELECT COUNT(*) FROM reservations r WHERE $whereSQL",
    $params
);

// ── Rows ───────────────────────────────────────────────────────
// Aggregate reservation_units into a JSON array per reservation so the
// frontend can render unit numbers in the table without extra requests.
$rows = db_select(
    "SELECT
        r.id,
        r.status,
        r.customer_id,
        r.contact_name,
        r.company_name,
        r.contact_phone,
        r.contact_email,
        r.quantity,
        r.pickup_date,
        r.pickup_time,
        r.yard_location,
        r.purpose,
        r.priority,
        r.notes,
        r.internal_notes,
        r.marked_out_at,
        r.created_at,
        r.updated_at,
        cb.name AS created_by_name,
        -- Aggregate unit info from junction table
        JSON_ARRAYAGG(
            JSON_OBJECT(
                'id',                   ru.id,
                'equipment_unit_id',    ru.equipment_unit_id,
                'unit_number',          COALESCE(eu.unit_number, ru.unit_number_snapshot),
                'template_name',        COALESCE(t.name, ru.template_name_snapshot),
                'entry_type',           ru.entry_type,
                'lease_id_linked',      ru.lease_id_linked
            )
        ) AS units_json
     FROM reservations r
     LEFT JOIN reservation_units ru ON ru.reservation_id = r.id
     LEFT JOIN equipment_units eu   ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
     LEFT JOIN users cb             ON cb.id = r.created_by
     WHERE $whereSQL
     GROUP BY r.id
     ORDER BY r.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

// ── Decode units_json per row ──────────────────────────────────
// JSON_ARRAYAGG returns a JSON string; decode it so the API response
// contains a proper array, not a nested string.
foreach ($rows as &$row) {
    $decoded = json_decode($row['units_json'] ?? '[]', true);
    // JSON_ARRAYAGG returns [null] when no reservation_units rows match
    $row['units'] = array_filter($decoded ?? [], fn($u) => $u['id'] !== null);
    $row['units'] = array_values($row['units']);
    unset($row['units_json']);
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
