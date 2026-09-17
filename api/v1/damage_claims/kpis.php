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

// S-UTC-STAMPS: damage_claims.created_at is UTC. "This year" is the company-
// local calendar year: 00:00 local Jan 1 → 00:00 local Jan 1 next year, as
// UTC bounds (sargable). YEAR(created_at) = YEAR(NOW()) used UTC years, so
// claims filed after 4pm PST on Dec 31 counted toward the next year.
$yearStartLocal = substr(ff_today(), 0, 4) . '-01-01';
$yearBounds     = [
    ff_local_day_start_utc($yearStartLocal),
    ff_local_day_start_utc(((int) substr($yearStartLocal, 0, 4) + 1) . '-01-01'),
];

$yearTotal = db_count(
    "SELECT COUNT(*) FROM damage_claims
     WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL",
    $yearBounds
);

$avgRow = db_row(
    "SELECT AVG(estimated_repair_cost) AS avg_cost
     FROM damage_claims
     WHERE estimated_repair_cost IS NOT NULL
       AND created_at >= ? AND created_at < ?
       AND deleted_at IS NULL",
    $yearBounds
);
$avgRepair = $avgRow ? round((float)($avgRow['avg_cost'] ?? 0), 2) : 0.00;

json_success([
    'open'       => $open,
    'invoiced'   => $invoiced,
    'year_total' => $yearTotal,
    'avg_repair' => number_format($avgRepair, 2, '.', ''),
]);
