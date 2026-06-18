<?php
declare(strict_types=1);

/**
 * api/v1/compliance/kpis.php
 *
 * Lightweight endpoint returning the three compliance KPI tile counts.
 * Called by the Alpine.js compliance grid after every inline save so the
 * tiles refresh without a full page reload.
 *
 * @method  GET
 * @auth    Session required; require_permission('compliance','view')
 * @returns 200 { total_units, expired_count, expiring_soon_count }
 *
 * Decisions: D5 (soft delete)
 * Session: S020 (added for Alpine.js tile refresh after inline save)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('compliance', 'view');

$today = date('Y-m-d');
$in30  = date('Y-m-d', strtotime('+30 days'));

$totalUnits = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')"
);

$expiredCount = db_count(
    "SELECT COUNT(DISTINCT id) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
       AND (
           (cvi_expiry IS NOT NULL AND cvi_expiry < ?)
           OR (registration_expiry IS NOT NULL AND registration_expiry < ?)
       )",
    [$today, $today]
);

$expiringSoonCount = db_count(
    "SELECT COUNT(DISTINCT id) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
       AND (
           (cvi_expiry IS NOT NULL AND cvi_expiry >= ? AND cvi_expiry <= ?)
           OR (registration_expiry IS NOT NULL AND registration_expiry >= ? AND registration_expiry <= ?)
       )",
    [$today, $in30, $today, $in30]
);

json_success([
    'total_units'         => $totalUnits,
    'expired_count'       => $expiredCount,
    'expiring_soon_count' => $expiringSoonCount,
]);
