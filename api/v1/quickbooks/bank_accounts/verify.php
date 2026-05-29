<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/verify.php
 *
 * Trigger BankAccountMatcher::verifyMappingStillValid — iterates every
 * mapped row, fetches current QBO Account, compares against snapshot,
 * flags drift (currency/name/active/type). Updates last_synced_at on
 * every row touched + transitions mapping_status to 'conflict' on drift.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @session S-QBO-20
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\BankAccountMatcher;

try {
    $report = BankAccountMatcher::verifyMappingStillValid();
    $drift = 0;
    foreach ($report as $r) {
        if (!empty($r['drift_types'])) $drift++;
    }
    json_success([
        'checked'     => count($report),
        'drift_count' => $drift,
        'report'      => $report,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Verify failed: ' . $e->getMessage(), 500);
}
