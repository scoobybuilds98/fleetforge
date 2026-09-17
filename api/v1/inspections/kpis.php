<?php
declare(strict_types=1);

/**
 * api/v1/inspections/kpis.php
 *
 * Inspection KPI tile counts for the inspections index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('inspections','view')
 * @returns 200 { total, draft, complete, signed_this_month }
 *
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('inspections', 'view');

$total    = db_count("SELECT COUNT(*) FROM inspections");
$draft    = db_count("SELECT COUNT(*) FROM inspections WHERE status = 'draft'");
$complete = db_count("SELECT COUNT(*) FROM inspections WHERE status = 'complete'");
$signed   = db_count(
    "SELECT COUNT(*) FROM inspections
     WHERE status = 'signed'
       AND signed_at >= ?",
    // signed_at is a UTC DATETIME: "this month" starts at LOCAL midnight on the
    // 1st (ff_local_month_start_utc), not the UTC month boundary, which rolled
    // the tile over at 5pm Pacific on the last day (S-LOCAL-DAY-TS).
    [ff_local_month_start_utc()]
);

json_success([
    'total'             => $total,
    'draft'             => $draft,
    'complete'          => $complete,
    'signed_this_month' => $signed,
]);
