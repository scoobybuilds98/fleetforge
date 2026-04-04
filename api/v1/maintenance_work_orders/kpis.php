<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/kpis.php
 *
 * Work order KPI tile counts for the maintenance work orders index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { total, open, active, completed_this_month }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

$total     = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE deleted_at IS NULL");
$open      = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE status = 'open' AND deleted_at IS NULL");
$active    = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE status IN ('in_progress','waiting_parts') AND deleted_at IS NULL");
$completed = db_count(
    "SELECT COUNT(*) FROM maintenance_work_orders
     WHERE status = 'completed' AND deleted_at IS NULL
       AND completed_date >= DATE_FORMAT(NOW(), '%Y-%m-01')"
);

json_success([
    'total'                => $total,
    'open'                 => $open,
    'active'               => $active,
    'completed_this_month' => $completed,
]);
