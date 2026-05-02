<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/index.php
 *
 * Returns a paginated list of equipment units. Supports filtering by status,
 * template_id, and yard_location. Full-text search on unit_number, vin,
 * gps_device_id, license_plate. Default sort: unit_number ASC (spec §4.1).
 * Each row includes the parent template name and category for display.
 *
 * @method  GET
 * @params  search      (optional) — FULLTEXT on unit_number, vin, license_plate
 *          status      (optional) — available|reserved|on_lease|maintenance|inactive|decommissioned
 *          template_id (optional, int)
 *          yard        (optional) — yard_location value
 *          sort        (optional) — unit_number|status|template|created_at  default: unit_number
 *          dir         (optional) — ASC|DESC  default: ASC
 *          page        (optional, int ≥1, default 1)
 *          per_page    (optional, int 1–100, default 25)
 * @auth    Session required; require_permission('equipment','view')
 * @returns json_paginated — unit rows with template info
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4 Equipment Units
 * @session S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

// ── Input ──────────────────────────────────────────────────────
$search     = clean_string($_GET['search'] ?? null, 255);
$status     = clean_string($_GET['status'] ?? null);
$templateId = clean_int($_GET['template_id'] ?? null);
$yard       = clean_string($_GET['yard'] ?? null, 100);
$sort       = clean_string($_GET['sort'] ?? 'unit_number');
$dir        = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$page       = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage    = min(100, max(1, clean_int($_GET['per_page'] ?? 25) ?? 25));

// ── Validate enum filters ──────────────────────────────────────
$validStatuses = ['available','reserved','on_lease','maintenance','inactive','decommissioned'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    $status = null;
}

// ── Sort allowlist — never interpolate user input directly ─────
$sortMap = [
    'unit_number' => "u.unit_number {$dir}",
    'status'      => "u.status {$dir}, u.unit_number ASC",
    'template'    => "t.name {$dir}, u.unit_number ASC",
    'created_at'  => "u.created_at {$dir}",
];
$orderBy = $sortMap[$sort] ?? "u.unit_number ASC";

// ── Build WHERE ────────────────────────────────────────────────
$conditions = ['u.deleted_at IS NULL', 't.deleted_at IS NULL'];
$params     = [];

if ($search !== null && $search !== '') {
    // LIKE-based substring search — supports partial matches
    $like         = '%' . $search . '%';
    $conditions[] = '(u.unit_number LIKE ? OR u.vin LIKE ? OR u.gps_device_id LIKE ? OR u.license_plate LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

if ($status !== null) {
    $conditions[] = 'u.status = ?';
    $params[]     = $status;
}

if ($templateId !== null) {
    $conditions[] = 'u.template_id = ?';
    $params[]     = $templateId;
}

if ($yard !== null && $yard !== '') {
    $conditions[] = 'u.yard_location = ?';
    $params[]     = $yard;
}

$whereSQL = 'WHERE ' . implode(' AND ', $conditions);

// ── Count ──────────────────────────────────────────────────────
$total = db_count(
    "SELECT COUNT(*)
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      {$whereSQL}",
    $params
);

// ── Paginated query ────────────────────────────────────────────
$offset = ($page - 1) * $perPage;

$rows = db_select(
    "SELECT
        u.id,
        u.template_id,
        u.unit_number,
        u.vin,
        u.year,
        u.status,
        u.yard_location,
        u.ownership_type,
        u.mileage,
        u.license_plate,
        u.license_state,
        u.cvi_expiry,
        u.registration_expiry,
        u.mvi_expiry,
        u.insurance_expiry,
        u.health_score,
        u.lease_count,
        u.total_revenue,
        u.acquired_date,
        u.created_at,
        u.updated_at,
        t.name     AS template_name,
        t.category AS template_category
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      {$whereSQL}
      ORDER BY {$orderBy}
      LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Format ─────────────────────────────────────────────────────
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'                  => (int) $row['id'],
        'template_id'         => (int) $row['template_id'],
        'template_name'       => $row['template_name'],
        'template_category'   => $row['template_category'],
        'unit_number'         => $row['unit_number'],
        'vin'                 => $row['vin'],
        'year'                => $row['year'] ? (int) $row['year'] : null,
        'status'              => $row['status'],
        'yard_location'       => $row['yard_location'],
        'ownership_type'      => $row['ownership_type'],
        'mileage'             => (int) $row['mileage'],
        'license_plate'       => $row['license_plate'],
        'license_state'       => $row['license_state'],
        'cvi_expiry'          => $row['cvi_expiry'],
        'registration_expiry' => $row['registration_expiry'],
        'mvi_expiry'          => $row['mvi_expiry'],
        'insurance_expiry'    => $row['insurance_expiry'],
        'health_score'        => $row['health_score'] !== null ? (int) $row['health_score'] : null,
        // health_color: derived band per S-CRON-3 (no separate column;
        // recomputed on every read from the canonical helper).
        'health_color'        => equipment_health_color($row['health_score'] !== null ? (int) $row['health_score'] : null),
        'lease_count'         => (int) $row['lease_count'],
        'total_revenue'       => $row['total_revenue'],
        'acquired_date'       => $row['acquired_date'],
        'created_at'          => $row['created_at'],
        'updated_at'          => $row['updated_at'],
    ];
}

json_paginated($items, $total, $page, $perPage);
