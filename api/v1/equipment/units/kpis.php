<?php
declare(strict_types=1);

/**
 * FleetForge — Equipment list summary tiles
 *
 * @file        api/v1/equipment/units/kpis.php
 * @description The four numbers on the equipment list's tiles (Available,
 *              On lease, Maintenance, Total) in ONE query. Replaces the four
 *              `units?status=…&per_page=1` calls the page used to make just to
 *              read pagination.total: each of those was a full bootstrap and
 *              a COUNT + 1-row SELECT, and all four queued behind each other
 *              on the PHP session lock before the table could even be asked
 *              for (S-PERF-3).
 *
 *              SEMANTICS — identical to the old per_page=1 totals:
 *                - Same base WHERE as units/index.php: the unit is not
 *                  soft-deleted AND its template is not soft-deleted (the
 *                  JOIN also drops units whose template row is gone). So a
 *                  tile click (drilldown → units?status=X) always lists
 *                  exactly the number on the tile.
 *                - `total` is the SUM of every status row — including
 *                  reserved / inactive / decommissioned — because the old
 *                  Total tile was units?per_page=1 with no status filter.
 *                  The utilization % (on_lease / total) depends on this.
 *                - A status with no units (e.g. maintenance) is 0, not absent.
 *
 *              No money fields (units.total_revenue is deliberately NOT
 *              summed here), so no can_view_financials() gate is needed.
 *
 * @method      GET
 * @auth        Session required; require_permission('equipment','view')
 *              (same gate as units/index.php, which the tiles drill into)
 * @returns     200 { available, on_lease, maintenance, total }  (all int)
 *
 * @depends     api/bootstrap.php
 * @consumer    app/admin/equipment/index.php (loadKpis — tiles + bulk-action refresh)
 * @decisions   D5 (soft delete)
 * @session     S-PERF-3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

// One GROUP BY over the list's own base filter (units/index.php `$conditions`
// before any user filter). ~1 ms on prod (idx_template lookups, ~170 rows).
$rows = db_select(
    "SELECT u.status, COUNT(*) AS n
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE u.deleted_at IS NULL
        AND t.deleted_at IS NULL
      GROUP BY u.status"
);

$byStatus = [];
$total    = 0;
foreach ($rows as $r) {
    $n = (int) $r['n'];
    $byStatus[(string) $r['status']] = $n;
    // WHY sum every row (not just the three tiled statuses): the old Total
    // tile counted ALL non-deleted units, so reserved / inactive /
    // decommissioned must stay in the denominator of the utilization %.
    $total += $n;
}

json_success([
    'available'   => $byStatus['available']   ?? 0,
    'on_lease'    => $byStatus['on_lease']    ?? 0,
    'maintenance' => $byStatus['maintenance'] ?? 0,
    'total'       => $total,
]);
