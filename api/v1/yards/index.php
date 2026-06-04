<?php
declare(strict_types=1);

/**
 * FleetForge — Yards List API
 *
 * @file        api/v1/yards/index.php
 * @description Returns all yards. By default returns only active yards
 *              (is_active = 1). Pass ?all=1 to include inactive yards.
 *              Used by reservation create/edit forms for the Pickup Yard dropdown,
 *              and by the yards management admin page.
 *
 *              No pagination — yards is a small reference table (typically < 20 rows).
 *              Supports ?sort= and ?dir= for flexible ordering.
 *
 * @method      GET
 * @query       all  (optional, 1 = include inactive yards)
 * @query       sort (optional, one of: name|created_at|is_active — default: name)
 * @query       dir  (optional, ASC|DESC — default: ASC)
 * @auth        Session required; require_permission('reservations','view')
 *              (yards are a support table for reservations — no dedicated module)
 * @returns     200 { yards[] }
 *
 * @depends     api/bootstrap.php
 * @session     S018-EXT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('reservations', 'view');

$showAll = (($_GET['all'] ?? '0') === '1');

// --- Sort / direction ---
// Allowed sort keys and the ORDER BY expression each produces.
// 'is_active' always appends y.name ASC as a secondary sort so active yards
// group together but remain alphabetical within each group.
$allowedSorts = ['name', 'created_at', 'is_active'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true)
    ? $_GET['sort']
    : 'name';

// Sanitise direction to exactly ASC or DESC.
$dir = (strtoupper($_GET['dir'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

$sortMap = [
    'name'       => "y.name {$dir}",
    'created_at' => "y.created_at {$dir}",
    // Secondary y.name ASC is intentionally fixed so active/inactive groups
    // stay alphabetical regardless of the requested $dir.
    'is_active'  => "y.is_active {$dir}, y.name ASC",
];
$orderBy = $sortMap[$sort];

// Always exclude soft-deleted yards; optionally include inactive (is_active=0) ones
$where  = $showAll ? 'y.deleted_at IS NULL' : 'y.is_active = 1 AND y.deleted_at IS NULL';

$yards = db_select(
    "SELECT
        y.id,
        y.name,
        y.slug,
        y.address,
        y.city,
        y.state,
        y.postal_code,
        y.capacity,
        y.phone,
        y.notes,
        y.is_active,
        y.created_at,
        y.updated_at,
        u.name AS manager_name
     FROM yards y
     LEFT JOIN users u ON u.id = y.manager_id
     WHERE $where
     ORDER BY {$orderBy}",
    []
);

json_success(['yards' => $yards]);
