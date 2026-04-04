<?php
declare(strict_types=1);

/**
 * api/v1/vendors/kpis.php
 *
 * Vendor KPI tile counts for the vendors index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { total, preferred, active_wo, top_vendor_name, top_vendor_spent }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

$total     = db_count("SELECT COUNT(*) FROM vendors WHERE deleted_at IS NULL");
$preferred = db_count("SELECT COUNT(*) FROM vendors WHERE is_preferred = 1 AND deleted_at IS NULL");
$activeWo  = db_count(
    "SELECT COUNT(*) FROM maintenance_work_orders
     WHERE status IN ('open','in_progress','waiting_parts') AND deleted_at IS NULL
       AND vendor_id IS NOT NULL"
);

$topSpendRow = db_row(
    "SELECT name, total_spent FROM vendors
     WHERE deleted_at IS NULL AND total_spent > 0
     ORDER BY total_spent DESC LIMIT 1"
);

json_success([
    'total'            => $total,
    'preferred'        => $preferred,
    'active_wo'        => $activeWo,
    'top_vendor_name'  => $topSpendRow['name'] ?? null,
    'top_vendor_spent' => $topSpendRow['total_spent'] ?? '0.00',
]);
