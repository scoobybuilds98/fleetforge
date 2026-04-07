<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-accounts/show.php
 *
 * Show a single bank account with GL mapping and balance.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('bank_accounts','view')
 * @returns 200 bank account row with GL balance
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\AccountingService;

require_method('GET');
require_auth_api();
require_permission('bank_accounts', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$row = db_row(
    "SELECT ba.*, a.code AS gl_account_code, a.name AS gl_account_name
     FROM acc_bank_accounts ba
     LEFT JOIN acc_accounts a ON a.id = ba.gl_account_id
     WHERE ba.id = ?",
    [$id]
);

if (!$row) json_error('NOT_FOUND', 'Bank account not found.', 404);

// Add computed GL balance
$row['gl_balance'] = AccountingService::accountBalance((int) $row['gl_account_id']);

// Transaction count
$row['transaction_count'] = db_count(
    "SELECT COUNT(*) FROM acc_bank_transactions WHERE bank_account_id = ?",
    [$id]
);

// Last reconciliation
$lastRecon = db_row(
    "SELECT id, statement_date, status FROM acc_bank_reconciliations
     WHERE bank_account_id = ? ORDER BY statement_date DESC LIMIT 1",
    [$id]
);
$row['last_reconciliation'] = $lastRecon;

json_success($row);
