<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/historical_pull/remediation_plan.php
 *
 * The detailed H5/H6 AR-drift remediation plan for a run (or a fresh detection
 * if no run_id given). Read-only — the plan is a PROPOSAL; posting is a
 * separate operator-approved + live-gated action (D-QBO-27-5), not exposed in
 * the machinery-only ship.
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @query   run_id? (int)
 * @returns 200 { ar_account_id, h5, h6, total_h5, total_h6, net_drift, plan }
 *
 * @session  S-QBO-27
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\ArDriftRemediator;

try {
    $detection = ArDriftRemediator::detect();
    $plan = $detection['ok'] ? ArDriftRemediator::buildPlan($detection) : [];

    json_success([
        'ok'            => (bool) $detection['ok'],
        'reason'        => $detection['reason'] ?? null,
        'ar_account_id' => $detection['ar_account_id'] ?? null,
        'h5'            => $detection['h5'] ?? [],
        'h6'            => $detection['h6'] ?? [],
        'total_h5'      => $detection['total_h5'] ?? '0.00',
        'total_h6'      => $detection['total_h6'] ?? '0.00',
        'net_drift'     => $detection['net_drift'] ?? '0.00',
        'plan'          => $plan,
        'note'          => 'Plan is a PROPOSAL. Posting compensating JEs is an operator-approved, live-gated action (D-QBO-27-5) executed against the seeded sandbox (F29) — not from this endpoint.',
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Remediation plan failed: ' . $e->getMessage(), 500);
}
