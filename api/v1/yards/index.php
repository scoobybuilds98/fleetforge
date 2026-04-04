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
 *              Returns all matching rows sorted by name ASC.
 *
 * @method      GET
 * @query       all (optional, 1 = include inactive yards)
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

$where  = $showAll ? '1=1' : 'y.is_active = 1';

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
     ORDER BY y.is_active DESC, y.name ASC",
    []
);

json_success(['yards' => $yards]);
