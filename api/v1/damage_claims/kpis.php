<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/kpis.php
 *
 * Damage claim KPI tile counts for the damage claims index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { open, invoiced, year_total, avg_repair }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

$open = db_count(
    "SELECT COUNT(*) FROM damage_claims
     WHERE status IN ('reported','assessed','repair_ordered')
       AND deleted_at IS NULL"
);

$invoiced = db_count(
    "SELECT COUNT(*) FROM damage_claims
     WHERE status = 'invoiced' AND deleted_at IS NULL"
);

$yearTotal = db_count(
    "SELECT COUNT(*) FROM damage_claims
     WHERE YEAR(created_at) = YEAR(NOW()) AND deleted_at IS NULL"
);

$avgRow = db_row(
    "SELECT AVG(estimated_repair_cost) AS avg_cost
     FROM damage_claims
     WHERE estimated_repair_cost IS NOT NULL
       AND YEAR(created_at) = YEAR(NOW())
       AND deleted_at IS NULL"
);
$avgRepair = $avgRow ? round((float)($avgRow['avg_cost'] ?? 0), 2) : 0.00;

json_success([
    'open'       => $open,
    'invoiced'   => $invoiced,
    'year_total' => $yearTotal,
    'avg_repair' => number_format($avgRepair, 2, '.', ''),
]);
