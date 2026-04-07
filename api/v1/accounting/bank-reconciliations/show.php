<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-reconciliations/show.php
 *
 * Show a single reconciliation with computed balances and transaction list.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('bank_accounts','view')
 * @returns 200 reconciliation with summary and transactions
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BankService;

require_method('GET');
require_auth_api();
require_permission('bank_accounts', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$recon = db_row(
    "SELECT r.*, ba.name AS bank_account_name, ba.currency,
            p.name AS period_name
     FROM acc_bank_reconciliations r
     JOIN acc_bank_accounts ba ON ba.id = r.bank_account_id
     LEFT JOIN acc_periods p ON p.id = r.period_id
     WHERE r.id = ?",
    [$id]
);

if (!$recon) json_error('NOT_FOUND', 'Reconciliation not found.', 404);

// Get live summary calculations
$summary = BankService::reconciliationSummary(
    (int) $recon['bank_account_id'],
    $id,
    $recon['statement_ending_balance']
);
$recon['summary'] = $summary;

// Get transactions for this reconciliation
$transactions = db_select(
    "SELECT bt.*
     FROM acc_bank_transactions bt
     WHERE bt.bank_account_id = ?
       AND (bt.reconciliation_id = ? OR (bt.reconciliation_id IS NULL AND bt.is_cleared = 0))
       AND bt.status != 'excluded'
     ORDER BY bt.transaction_date ASC, bt.id ASC",
    [(int) $recon['bank_account_id'], $id]
);

$recon['transactions'] = $transactions;

json_success($recon);
