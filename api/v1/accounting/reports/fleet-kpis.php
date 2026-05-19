<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/fleet-kpis.php
 *
 * Fleet-wide financial KPIs per spec §23.10:
 *   - RevPAU (Revenue per Available Unit)
 *   - Dollar utilization (annualized revenue / total OEC)
 *   - Maintenance cost ratio (industry benchmark 15-20%)
 *   - Fleet age weighted average (cost-weighted)
 *
 * @method  GET
 * @query   period_start, period_end (both required, YYYY-MM-DD)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { revpau, dollar_utilization_pct, maintenance_cost_ratio_pct,
 *                maintenance_benchmark_flag, fleet_age_weighted_avg_years,
 *                period_days, total_units, ... }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.10
 * Session: S-ACCT-UNIT
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\UnitProfitabilityService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$from = clean_string($_GET['period_start'] ?? '', 10);
$to   = clean_string($_GET['period_end']   ?? '', 10);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    json_error('VALIDATION_ERROR', 'period_start must be YYYY-MM-DD.', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_error('VALIDATION_ERROR', 'period_end must be YYYY-MM-DD.', 422);
}
if ($to < $from) {
    json_error('VALIDATION_ERROR', 'period_end must be on or after period_start.', 422);
}

$kpis = UnitProfitabilityService::getFleetKpis($from, $to);

json_success([
    'period_start' => $from,
    'period_end'   => $to,
    'kpis'         => $kpis,
]);
