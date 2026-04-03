<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard KPI API
 *
 * @file        api/v1/dashboard/kpis.php
 * @description Returns the 6 KPI tile values for the admin dashboard.
 *              Results are cached in report_cache for 5 minutes (TTL per spec §8).
 *              No module permission required — dashboard is accessible to all
 *              authenticated staff users.
 *
 * @method      GET
 * @auth        Session required (require_auth_api)
 * @returns     {
 *                active_revenue:      string   (sum of active lease monthly_rate, bcmath string)
 *                fleet_utilization:   float    (on_lease / total_active * 100, 1 decimal)
 *                on_lease_count:      int
 *                total_active_units:  int
 *                overdue_invoices:    { count: int, total: string }
 *                compliance_alerts:   int      (units with any expiry ≤ 30 days from today)
 *                open_leases:         int      (status IN active, pending)
 *                todays_pickups:      int      (reservations with pickup_date = today, status pending/confirmed)
 *              }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard KPI tiles
 * @design      FLEETFORGE_DESIGN_DETAILS.md §4 Dashboard Grid Layout
 * @session     S004
 */

// dirname(__DIR__, 3): api/v1/dashboard/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Cache lookup ───────────────────────────────────────────────
// KPI cache key is constant — no per-user parameters.
// TTL: 5 minutes per spec §8 caching strategy.
$cacheKey    = 'dashboard_kpis';
$cacheHash   = hash('sha256', $cacheKey);
$cacheTtlMin = 5;
$now         = date('Y-m-d H:i:s');

$cached = db_row(
    "SELECT result_data FROM report_cache
      WHERE report_type = 'dashboard_kpis'
        AND parameters_hash = ?
        AND expires_at > ?",
    [$cacheHash, $now]
);

if ($cached) {
    // Return cached payload directly — avoid re-running all queries
    $payload = json_decode($cached['result_data'], true);
    json_success($payload);
}

// ── KPI 1: Active Revenue ──────────────────────────────────────
// Sum of monthly_rate for all active leases (not deleted).
// Uses SQL SUM (DECIMAL field) then formats as bcmath string.
// WHY bcmath: monetary display value must not go through float math (D16).
$revenueRow = db_row(
    "SELECT COALESCE(SUM(monthly_rate), 0) AS total
       FROM leases
      WHERE status = 'active'
        AND deleted_at IS NULL"
);
// COALESCE guarantees a numeric string, not NULL
$activeRevenue = bcround((string) $revenueRow['total'], 2);

// ── KPI 2: Fleet Utilization % ────────────────────────────────
// on_lease count / total active units (not decommissioned, not deleted)
$fleetRow = db_row(
    "SELECT
        COUNT(*) AS total_active,
        SUM(CASE WHEN status = 'on_lease' THEN 1 ELSE 0 END) AS on_lease
       FROM equipment_units
      WHERE status != 'decommissioned'
        AND deleted_at IS NULL"
);
$totalActiveUnits = (int) $fleetRow['total_active'];
$onLeaseCount     = (int) $fleetRow['on_lease'];

// Utilization as percentage, 1 decimal — safe to use / here since it's
// a display ratio, not a monetary value (D16 applies to monetary only).
$utilizationPct = $totalActiveUnits > 0
    ? round(($onLeaseCount / $totalActiveUnits) * 100, 1)
    : 0.0;

// ── KPI 3: Overdue Invoices ────────────────────────────────────
// Count + sum of balance_due for invoices in 'overdue' status (not deleted).
$overdueRow = db_row(
    "SELECT
        COUNT(*) AS inv_count,
        COALESCE(SUM(balance_due), 0) AS inv_total
       FROM invoices
      WHERE status = 'overdue'
        AND deleted_at IS NULL"
);
$overdueCount = (int) $overdueRow['inv_count'];
$overdueTotal = bcround((string) $overdueRow['inv_total'], 2);

// ── KPI 4: Compliance Alerts ──────────────────────────────────
// Units with ANY of the 4 expiry dates within the next 30 days (not null),
// not deleted, not decommissioned.
// WHY 4 separate conditions: spec lists CVI, registration, MVI, insurance
// as the tracked compliance documents on equipment_units.
$alertThreshold  = date('Y-m-d', strtotime('+30 days'));
$complianceRow   = db_row(
    "SELECT COUNT(DISTINCT id) AS cnt
       FROM equipment_units
      WHERE deleted_at IS NULL
        AND status != 'decommissioned'
        AND (
               (cvi_expiry          IS NOT NULL AND cvi_expiry          <= ?)
            OR (registration_expiry IS NOT NULL AND registration_expiry <= ?)
            OR (mvi_expiry          IS NOT NULL AND mvi_expiry          <= ?)
            OR (insurance_expiry    IS NOT NULL AND insurance_expiry    <= ?)
        )",
    [$alertThreshold, $alertThreshold, $alertThreshold, $alertThreshold]
);
$complianceAlerts = (int) $complianceRow['cnt'];

// ── KPI 5: Open Leases ────────────────────────────────────────
// Count of leases with status 'active' or 'pending', not deleted.
$openLeasesRow = db_row(
    "SELECT COUNT(*) AS cnt
       FROM leases
      WHERE status IN ('active', 'pending')
        AND deleted_at IS NULL"
);
$openLeases = (int) $openLeasesRow['cnt'];

// ── KPI 6: Today's Pickups ────────────────────────────────────
// Reservations with pickup_date = today in pending or confirmed status.
// WHY reservations not leases: the "Today's Pickups" dashboard tile was
// originally wired to leases.start_date (S004). S018 corrects this — the
// reservations module owns the pickup workflow. Pending + confirmed means
// the unit is expected to leave the yard today.
$today          = date('Y-m-d');
$pickupsRow     = db_row(
    "SELECT COUNT(*) AS cnt
       FROM reservations
      WHERE pickup_date = ?
        AND status IN ('pending', 'confirmed')
        AND deleted_at IS NULL",
    [$today]
);
$todaysPickups = (int) $pickupsRow['cnt'];

// ── Build payload ──────────────────────────────────────────────
$payload = [
    'active_revenue'     => $activeRevenue,
    'fleet_utilization'  => $utilizationPct,
    'on_lease_count'     => $onLeaseCount,
    'total_active_units' => $totalActiveUnits,
    'overdue_invoices'   => [
        'count' => $overdueCount,
        'total' => $overdueTotal,
    ],
    'compliance_alerts'  => $complianceAlerts,
    'open_leases'        => $openLeases,
    'todays_pickups'     => $todaysPickups,
    'generated_at'       => $now,
];

// ── Write cache ────────────────────────────────────────────────
// Upsert into report_cache. REPLACE INTO atomically removes any stale row
// matching the unique key (report_type, parameters_hash) then inserts fresh.
// WHY REPLACE: simpler than INSERT … ON DUPLICATE KEY UPDATE for cache writes.
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$cacheTtlMin} minutes"));

db_execute(
    "REPLACE INTO report_cache
        (report_type, parameters_hash, parameters, result_data, generated_at, expires_at, generated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    [
        'dashboard_kpis',
        $cacheHash,
        json_encode(['key' => $cacheKey]),
        json_encode($payload),
        $now,
        $expiresAt,
        current_user_id(),
    ]
);

json_success($payload);
