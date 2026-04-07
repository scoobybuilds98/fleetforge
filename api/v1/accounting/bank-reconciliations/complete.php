<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-reconciliations/complete.php
 *
 * Complete a reconciliation. Difference must be $0.00 to close.
 * Optionally allows a small adjustment entry for rounding differences.
 * Once completed, reconciliation is locked — no changes allowed.
 *
 * @method  POST
 * @body    id, adjustment_amount? (small rounding difference),
 *          adjustment_account_id? (GL account for adjustment)
 * @auth    Session required; require_permission('bank_accounts','edit')
 * @returns 200 completed reconciliation
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank reconciliation process)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BankService;
use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Reconciliation ID is required.']);
}

$recon = db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$id]);
if (!$recon) {
    json_error('NOT_FOUND', 'Reconciliation not found.', 404, [
        'fields' => ['id' => 'Reconciliation not found.'],
    ]);
}
if ($recon['status'] !== 'in_progress') {
    json_error('IMMUTABLE_RECORD',
        'Reconciliation is already completed or locked.', 422,
        ['fields' => ['id' => 'Reconciliation is already completed or locked.']]);
}

$bankAccount = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [(int) $recon['bank_account_id']]);
if (!$bankAccount) {
    json_error('NOT_FOUND', 'Bank account not found.', 404, [
        'fields' => ['id' => 'Bank account not found.'],
    ]);
}

// Calculate current difference
$summary = BankService::reconciliationSummary(
    (int) $recon['bank_account_id'],
    $id,
    $recon['statement_ending_balance']
);

$difference = $summary['difference'];

// Handle adjustment if provided
$adjustmentAmount = clean_decimal($input['adjustment_amount'] ?? null);
$adjustmentAccountId = clean_int($input['adjustment_account_id'] ?? null);

if ($adjustmentAmount && bccomp($adjustmentAmount, '0.00', 2) !== 0) {
    // WHY: Small rounding differences can be written off with an adjustment entry
    // Maximum adjustment: $5.00 to prevent abuse
    $absAdj = bccomp($adjustmentAmount, '0.00', 2) < 0
        ? bcmul($adjustmentAmount, '-1', 2)
        : $adjustmentAmount;
    if (bccomp($absAdj, '5.00', 2) > 0) {
        json_validation_error(
            ['adjustment_amount' => 'Adjustment amount cannot exceed $5.00. Investigate the difference.'],
            'Adjustment amount cannot exceed $5.00. Investigate the difference.'
        );
    }
    if (!$adjustmentAccountId) {
        json_validation_error(
            ['adjustment_account_id' => 'Please select an adjustment GL account.'],
            'Adjustment GL account is required for adjustment entries.'
        );
    }

    // After adjustment, difference should be zero
    $adjustedDiff = bcsub($difference, $adjustmentAmount, 2);
    if (bccomp($adjustedDiff, '0.00', 2) !== 0) {
        json_validation_error(
            ['adjustment_amount' => "Adjustment of \${$adjustmentAmount} does not resolve the difference of \${$difference}."],
            "Adjustment does not resolve the difference."
        );
    }
} else {
    // No adjustment — difference must already be zero
    if (bccomp($difference, '0.00', 2) !== 0) {
        json_validation_error(
            ['_general' => "Cannot complete — difference is \${$difference}. Must be \$0.00."],
            "Cannot complete — difference is \${$difference}. Must be \$0.00."
        );
    }
}

$userId = current_user_id();

db_transaction(function () use (
    $id, $recon, $bankAccount, $summary,
    $adjustmentAmount, $adjustmentAccountId, $userId
) {
    // D20: FOR UPDATE on bank account during reconciliation
    db_row("SELECT id FROM acc_bank_accounts WHERE id = ? FOR UPDATE", [(int) $recon['bank_account_id']]);

    // Create adjustment JE if needed
    if ($adjustmentAmount && bccomp($adjustmentAmount, '0.00', 2) !== 0) {
        $cashAccountId = (int) $bankAccount['gl_account_id'];
        $absAdj = bccomp($adjustmentAmount, '0.00', 2) < 0
            ? bcmul($adjustmentAmount, '-1', 2)
            : $adjustmentAmount;

        $lines = [];
        if (bccomp($adjustmentAmount, '0.00', 2) > 0) {
            // Positive adjustment: book balance too high → DR Expense, CR Cash
            $lines = [
                ['account_id' => $adjustmentAccountId, 'debit' => $absAdj, 'credit' => '0.00', 'description' => 'Reconciliation adjustment'],
                ['account_id' => $cashAccountId, 'debit' => '0.00', 'credit' => $absAdj, 'description' => 'Reconciliation adjustment'],
            ];
        } else {
            // Negative adjustment: book balance too low → DR Cash, CR Income/Adjustment
            $lines = [
                ['account_id' => $cashAccountId, 'debit' => $absAdj, 'credit' => '0.00', 'description' => 'Reconciliation adjustment'],
                ['account_id' => $adjustmentAccountId, 'debit' => '0.00', 'credit' => $absAdj, 'description' => 'Reconciliation adjustment'],
            ];
        }

        JournalEntryService::create([
            'entry_date'       => $recon['statement_date'],
            'description'      => "Bank reconciliation adjustment — {$bankAccount['name']}",
            'entry_type'       => 'adjustment',
            'source_type'      => 'bank_transaction',
            'source_id'        => $id,
            'post_immediately' => true,
        ], $lines, $userId);
    }

    // Lock all cleared transactions for this reconciliation
    db_execute(
        "UPDATE acc_bank_transactions SET reconciliation_id = ? WHERE reconciliation_id = ? AND is_cleared = 1",
        [$id, $id]
    );

    // Complete the reconciliation
    db_update('acc_bank_reconciliations', [
        'status'                => 'completed',
        'book_balance'          => $summary['book_balance'],
        'outstanding_deposits'  => $summary['outstanding_deposits'],
        'outstanding_checks'    => $summary['outstanding_checks'],
        'adjusted_book_balance' => $summary['adjusted_book_balance'],
        'difference'            => '0.00',
        'completed_by'          => $userId,
        'completed_at'          => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'status_change',
        'module'      => 'accounting',
        'entity_type' => 'bank_reconciliation',
        'entity_id'   => $id,
        'notes'       => "Bank reconciliation completed for {$bankAccount['name']} (statement date: {$recon['statement_date']})",
        'old_values'  => json_encode(['status' => 'in_progress']),
        'new_values'  => json_encode(['status' => 'completed']),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$completed = db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$id]);
json_success($completed);
