<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/historical_pull/status.php
 *
 * Current historical-pull state: the dry-run gate, the configured entity pull
 * order, and the most recent run(s) with counts + remediation status.
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { dry_run, batch_size, entity_order, latest, recent }
 *
 * @session  S-QBO-27
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\HistoricalPuller;

try {
    $recent = db_select(
        "SELECT id, realm_id, mode, phase, status, entity_counts, checkpoints,
                ar_drift_before, ar_drift_after, remediation_status,
                started_at, finished_at
           FROM acc_qbo_historical_pull_runs
          ORDER BY id DESC
          LIMIT 10"
    );
    foreach ($recent as &$r) {
        $r['entity_counts'] = json_decode((string) ($r['entity_counts'] ?? '{}'), true) ?: new stdClass();
        $r['checkpoints']   = json_decode((string) ($r['checkpoints'] ?? '{}'), true) ?: new stdClass();
    }
    unset($r);

    json_success([
        'dry_run'      => HistoricalPuller::isDryRun(),
        'batch_size'   => HistoricalPuller::batchSize(),
        'entity_order' => HistoricalPuller::ENTITY_ORDER,
        'latest'       => $recent[0] ?? null,
        'recent'       => $recent,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Status failed: ' . $e->getMessage(), 500);
}
