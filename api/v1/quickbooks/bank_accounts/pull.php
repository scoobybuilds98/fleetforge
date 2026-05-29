<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/pull.php
 *
 * Pull QBO bank + credit-card accounts via BankAccountMatcher::pullFromQbo.
 * Caches the result count + timestamp in settings; the candidates endpoint
 * re-pulls when serving the link modal (fresh data over cache staleness).
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
    $qboAccounts = BankAccountMatcher::pullFromQbo();
    $count = count($qboAccounts);

    $now = date('c');
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name, is_public, is_sensitive)
              VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        ['quickbooks.banking.last_bank_account_pull_at', $now]
    );

    json_success([
        'qbo_count'       => $count,
        'pulled_at'       => $now,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Pull failed: ' . $e->getMessage(), 500);
}
