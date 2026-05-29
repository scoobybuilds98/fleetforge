<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/candidates.php
 *
 * Return ranked QBO bank account candidates for a given FF bank account.
 * Calls BankAccountMatcher::pullFromQbo + getCandidates with the FF
 * row's currency/type as ranking signals. Top 10.
 *
 * @method  GET ?ff_id=N
 * @auth    require_permission('quickbooks', 'view')
 * @session S-QBO-20
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\BankAccountMatcher;

$ffId = (int) ($_GET['ff_id'] ?? 0);
if ($ffId <= 0) {
    json_error('VALIDATION_ERROR', 'ff_id is required', 422);
}

try {
    $qboAccounts = BankAccountMatcher::pullFromQbo();
    $candidates  = BankAccountMatcher::getCandidates($ffId, $qboAccounts);
    json_success([
        'candidates' => $candidates,
        'qbo_count'  => count($qboAccounts),
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Candidates failed: ' . $e->getMessage(), 500);
}
