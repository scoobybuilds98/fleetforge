<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/historical_pull/start.php
 *
 * Open a historical-pull run and run the AR-drift DETECTION + REPORT
 * (machinery-only ship, S-QBO-27). In dry-run mode (the default + only mode
 * available pre-cutover) this:
 *   1. opens an acc_qbo_historical_pull_runs row,
 *   2. runs ArDriftRemediator::detectAndReport — detects H5/H6 anomalies +
 *      builds the proposed compensating-JE plan + records it,
 *   3. returns the detection summary.
 *
 * It does NOT pull live QBO data or post any JE — those require a seeded
 * sandbox + the live gate (D-QBO-27-3/-5). 'live' mode is refused unless the
 * dry-run setting is '0' and sync is enabled (HistoricalPuller::startRun).
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'force_full_resync')  (super-admin)
 * @body    { mode?: 'dry_run'|'live' }  (defaults 'dry_run')
 * @returns 200 { run_id, mode, dry_run, detection, remediation_status, plan }
 *
 * @session  S-QBO-27
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'force_full_resync');

use FleetForge\QboPushers\HistoricalPuller;
use FleetForge\QboPushers\ArDriftRemediator;
use FleetForge\Exceptions\QuickBooksException;

$body = json_body();
$mode = (string) ($body['mode'] ?? 'dry_run');

try {
    $runId = HistoricalPuller::startRun($mode, current_user_id());

    // Machinery-only: the valuable offline action is AR-drift detection +
    // report (pure FF queries). Live reference/transactional pull needs a
    // seeded sandbox and runs only when the gate is opened.
    $result = ArDriftRemediator::detectAndReport($runId);
    HistoricalPuller::setStatus($runId, 'completed');

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_historical_pull_run',
        'entity_id'    => $runId,
        'entity_label' => "Historical pull run #{$runId} ({$mode})",
        'notes'        => "AR-drift detect+report: H5=" . count($result['h5'] ?? []) .
                          " H6=" . count($result['h6'] ?? []) .
                          " net_drift=" . ($result['net_drift'] ?? '0.00') .
                          " remediation_status=" . ($result['remediation_status'] ?? '?'),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success([
        'run_id'  => $runId,
        'mode'    => $mode,
        'dry_run' => HistoricalPuller::isDryRun(),
        'detection' => [
            'ok'        => (bool) ($result['ok'] ?? false),
            'reason'    => $result['reason'] ?? null,
            'h5_count'  => count($result['h5'] ?? []),
            'h6_count'  => count($result['h6'] ?? []),
            'total_h5'  => $result['total_h5'] ?? '0.00',
            'total_h6'  => $result['total_h6'] ?? '0.00',
            'net_drift' => $result['net_drift'] ?? '0.00',
        ],
        'remediation_status' => $result['remediation_status'] ?? 'not_run',
        'plan'               => $result['plan'] ?? [],
    ]);
} catch (QuickBooksException $e) {
    json_error('INVALID_STATE', $e->getMessage(), 422);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Historical pull start failed: ' . $e->getMessage(), 500);
}
