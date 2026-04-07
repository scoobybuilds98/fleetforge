<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-import/confirm.php
 *
 * Confirm import of parsed bank transactions after user review.
 * Accepts list of row indices to import (user can exclude specific rows).
 * Skips duplicates. Creates bank transaction records for each imported row.
 *
 * @method  POST
 * @body    selected_rows? (JSON array of row indices to import, null = import all non-duplicates)
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 200 import result with counts
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank statement import)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

// Retrieve previewed data from session
if (!isset($_SESSION['bank_import_preview'])) {
    json_error('VALIDATION_ERROR', 'No import preview found. Upload a CSV first.', 422);
}

$preview = $_SESSION['bank_import_preview'];

// Expire after 30 minutes
if (time() - $preview['timestamp'] > 1800) {
    unset($_SESSION['bank_import_preview']);
    json_error('VALIDATION_ERROR', 'Import preview has expired. Please re-upload the CSV.', 422);
}

$bankAccountId = $preview['bank_account_id'];
$transactions = $preview['transactions'];

// Determine which rows to import
$selectedRows = null;
if (isset($_POST['selected_rows'])) {
    $selectedRows = json_decode($_POST['selected_rows'], true);
    if (!is_array($selectedRows)) {
        json_error('VALIDATION_ERROR', 'selected_rows must be a JSON array of row numbers.', 422);
    }
}

// Accept match overrides — user can manually match/unmatch during review
$matchOverrides = [];
if (isset($_POST['match_overrides'])) {
    $matchOverrides = json_decode($_POST['match_overrides'], true) ?? [];
}

$userId = current_user_id();
$imported = 0;
$skipped = 0;
$errors = [];

db_transaction(function () use (
    &$imported, &$skipped, &$errors,
    $bankAccountId, $transactions, $selectedRows, $matchOverrides, $userId
) {
    foreach ($transactions as $i => $txn) {
        // Skip duplicates
        if (!empty($txn['is_duplicate'])) {
            $skipped++;
            continue;
        }

        // Skip unselected rows if selection provided
        if ($selectedRows !== null && !in_array($txn['row_number'], $selectedRows)) {
            $skipped++;
            continue;
        }

        // Apply match overrides
        $match = $txn['match'] ?? null;
        if (isset($matchOverrides[$txn['row_number']])) {
            $override = $matchOverrides[$txn['row_number']];
            if ($override === null || $override === false) {
                $match = null; // User rejected the match
            } elseif (is_array($override)) {
                $match = $override; // User provided manual match
            }
        }

        try {
            $data = [
                'bank_account_id'  => $bankAccountId,
                'transaction_date' => $txn['date'],
                'description'      => $txn['description'],
                'reference'        => $txn['reference'],
                'amount'           => $txn['amount'],
                'transaction_type' => $txn['type'] === 'deposit' ? 'deposit' : 'withdrawal',
                'source'           => 'import',
                'status'           => $match ? 'matched' : 'unmatched',
                'matched_type'     => $match['type'] ?? null,
                'matched_id'       => $match['id'] ?? null,
                'matched_at'       => $match ? date('Y-m-d H:i:s') : null,
                'matched_by'       => $match ? $userId : null,
                'created_by'       => $userId,
            ];

            db_insert('acc_bank_transactions', $data);
            $imported++;
        } catch (\Throwable $e) {
            $errors[] = "Row {$txn['row_number']}: " . $e->getMessage();
        }
    }

    // Audit log for the import batch
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'bank_import',
        'entity_id'   => $bankAccountId,
        'notes'       => "Bank CSV import: {$imported} imported, {$skipped} skipped",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Clear session preview
unset($_SESSION['bank_import_preview']);

json_success([
    'imported' => $imported,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
