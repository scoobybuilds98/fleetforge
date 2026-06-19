<?php
declare(strict_types=1);

/**
 * api/v1/compliance/index.php
 *
 * Paginated list of equipment units with CVI and Registration compliance data.
 * MVI and Insurance are excluded.
 *
 * Each row includes:
 *   - Expiry date (to) for each doc type
 *   - "From" date (directly stored cvi_from_date / registration_from_date)
 *   - Signed download URL for the document when one is on file
 *     (via StorageClient::url() → api/v1/storage/serve.php)
 *
 * Filters:
 *   yard   — equipment_units.yard_location LIKE (partial match)
 *   status — equipment status ENUM (available/reserved/on_lease/maintenance)
 *   window — integer days; only units with ANY expiry within N days or already
 *            expired. Values 7/14/30/60/90 supported (any positive int accepted).
 *            Omit or 0 to return ALL active units.
 *   q      — unit_number or template name search (LIKE)
 *   sort   — allowlisted column
 *   dir    — ASC|DESC
 *
 * D5:  equipment_units has deleted_at — all queries filter it.
 * Trap 7: document paths (cvi_document etc.) are NEVER returned raw — converted
 *         to signed StorageClient URLs before output.
 *
 * @method  GET
 * @auth    Session required; require_permission('compliance','view')
 * @returns 200 json_paginated([unit rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), D9 (storage), §10 (list pattern), §7.9
 * Session: S020 (updated: removed MVI, added from dates + doc URLs)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();
require_permission('compliance', 'view');

// -----------------------------------------------------------------------
// 1. Build WHERE clause — always exclude deleted + inactive/decommissioned
// -----------------------------------------------------------------------
$where  = ["eu.deleted_at IS NULL", "eu.status NOT IN ('inactive','decommissioned')"];
$params = [];

// Filter: yard
if ($yard = clean_string($_GET['yard'] ?? null)) {
    $where[]  = 'eu.yard_location LIKE ?';
    $params[] = '%' . $yard . '%';
}

// Filter: status
$validStatuses = ['available', 'reserved', 'on_lease', 'maintenance'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'eu.status = ?';
        $params[] = $status;
    }
}

// Filter: expired_only — units with at least one doc past expiry date
// WHY: separate from window — "Expired Documents" KPI tile drills down to only
//      past-due units, not "expiring soon" ones.
// E04: use company-local "today" (matching kpis.php + reports/compliance.php),
// NOT CURDATE() — the DB connection is pinned to UTC (+00:00), so CURDATE() rolls
// to tomorrow during the Vancouver evening and the grid then disagrees with the
// KPI tiles / CSV / report on what is expired.
$today = date('Y-m-d');
if (clean_string($_GET['expired_only'] ?? '') === '1') {
    $where[]  = "(
        (eu.cvi_expiry IS NOT NULL AND eu.cvi_expiry < ?)
        OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry < ?)
    )";
    $params[] = $today;
    $params[] = $today;
}

// Filter: window — units with CVI or Registration expiry <= today + N days
$window = clean_int($_GET['window'] ?? 0) ?? 0;
if ($window > 0) {
    $where[]  = "(
        (eu.cvi_expiry IS NOT NULL AND eu.cvi_expiry <= DATE_ADD(?, INTERVAL ? DAY))
        OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry <= DATE_ADD(?, INTERVAL ? DAY))
    )";
    $params[] = $today;
    $params[] = $window;
    $params[] = $today;
    $params[] = $window;
}

// Filter: q
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(eu.unit_number LIKE ? OR et.name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort allowlist — mvi_expiry removed
// -----------------------------------------------------------------------
$allowedSorts = ['unit_number', 'template_name', 'yard_location', 'status',
                 'cvi_expiry', 'registration_expiry'];
$sort    = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'unit_number';
$dir     = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
$orderBy = ($sort === 'template_name') ? "et.name $dir" : "eu.$sort $dir";

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(200, max(10, clean_int($_GET['per_page'] ?? 50) ?? 50));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*)
     FROM equipment_units eu
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE $whereSQL",
    $params
);

// -----------------------------------------------------------------------
// 5. Rows — include expiry dates, interval days, and raw doc path columns
//    FROM dates computed in SQL as expiry - interval_days
// -----------------------------------------------------------------------
$rows = db_select(
    "SELECT
         eu.id,
         eu.unit_number,
         et.name          AS template_name,
         et.category      AS equipment_type,
         eu.yard_location,
         eu.status,

         -- CVI
         eu.cvi_expiry,
         eu.cvi_from_date        AS cvi_from,
         eu.cvi_document,

         -- Registration
         eu.registration_expiry,
         eu.registration_from_date AS registration_from,
         eu.registration_document,

         eu.updated_at
     FROM equipment_units eu
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY $orderBy
     LIMIT $perPage OFFSET $offset",
    $params
);

// -----------------------------------------------------------------------
// 6. Post-process — convert raw file paths to signed download URLs (Trap 7)
//    Raw cvi_document / registration_document are server filesystem keys
//    and must never be returned as-is to the client.
// -----------------------------------------------------------------------
foreach ($rows as &$row) {
    // CVI document URL
    $row['cvi_doc_url'] = null;
    if (!empty($row['cvi_document'])) {
        try {
            $row['cvi_doc_url'] = StorageClient::url($row['cvi_document'], 3600);
        } catch (\Throwable) {
            // If key is invalid, omit the URL rather than crashing
        }
    }
    unset($row['cvi_document']);   // Never expose raw path

    // Registration document URL
    $row['registration_doc_url'] = null;
    if (!empty($row['registration_document'])) {
        try {
            $row['registration_doc_url'] = StorageClient::url($row['registration_document'], 3600);
        } catch (\Throwable) {}
    }
    unset($row['registration_document']);
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
