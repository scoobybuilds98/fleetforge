<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/run_cdc.php
 *
 * Operator-initiated manual CDC pull — equivalent to running
 * cron/qbo_bank_cdc.php out-of-band. Calls BankTransactionPuller::runCdc
 * with no override so it uses the same settings + last_bank_cdc_at logic
 * the cron uses.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @session S-QBO-20
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\BankTransactionPuller;

try {
    $result = BankTransactionPuller::runCdc();
    json_success($result);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'CDC run failed: ' . $e->getMessage(), 500);
}
