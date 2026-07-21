<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/index.php
 *
 * Paginated list of damage claims with optional filters and sort.
 *
 * Filters: customer_id, equipment_unit_id, status, severity, date_from, date_to, q (search)
 * Sort allowlist prevents SQL injection.
 *
 * SOFT_DELETE: damage_claims has deleted_at — always AND dc.deleted_at IS NULL.
 * JOIN customers/equipment_units must also check their deleted_at (D5).
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 *          Damage claims live under the maintenance permission scope (§12 matrix).
 * @returns 200 json_paginated([damage_claim rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['dc.deleted_at IS NULL'];
$params = [];

if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'dc.customer_id = ?';
    $params[] = $customerId;
}

if ($unitId = clean_int($_GET['equipment_unit_id'] ?? null)) {
    $where[]  = 'dc.equipment_unit_id = ?';
    $params[] = $unitId;
}

if ($status = clean_string($_GET['status'] ?? null)) {
    $validStatuses = ['reported', 'assessed', 'repair_ordered', 'invoiced', 'resolved', 'written_off'];
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'dc.status = ?';
        $params[] = $status;
    }
}

if ($severity = clean_string($_GET['severity'] ?? null)) {
    $validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
    if (in_array($severity, $validSeverities, true)) {
        $where[]  = 'dc.severity = ?';
        $params[] = $severity;
    }
}

if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'DATE(dc.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'DATE(dc.created_at) <= ?';
    $params[] = $dateTo;
}

// Full-text search stripped of boolean operators (§10 pattern)
if ($q = clean_string($_GET['q'] ?? null)) {
    $where[]  = "(dc.claim_number LIKE ? OR dc.description LIKE ? OR eu.unit_number LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort (allowlisted — never from raw user input)
// -----------------------------------------------------------------------
$allowedSorts = ['created_at', 'updated_at', 'claim_number', 'status', 'severity', 'estimated_repair_cost', 'actual_repair_cost'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Queries
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*)
     FROM damage_claims dc
     LEFT JOIN equipment_units eu ON eu.id = dc.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT
        dc.id,
        dc.claim_number,
        dc.equipment_unit_id,
        eu.unit_number,
        eu.year,
        eb.label            AS brand,
        et.model,
        dc.lease_id,
        dc.customer_id,
        c.company_name      AS customer_name,
        dc.severity,
        dc.status,
        dc.damage_location,
        dc.estimated_repair_cost,
        dc.actual_repair_cost,
        dc.customer_liable_amount,
        dc.insurance_claim_amount,
        dc.created_at,
        dc.updated_at
     FROM damage_claims dc
     LEFT JOIN equipment_units     eu ON eu.id = dc.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id        AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands    eb ON eb.id = eu.brand_id
     LEFT JOIN customers c            ON c.id  = dc.customer_id        AND c.deleted_at  IS NULL
     WHERE {$whereSQL}
     ORDER BY dc.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
