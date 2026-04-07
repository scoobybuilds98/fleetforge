<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/deposits/create.php
 *
 * Record a customer deposit (security, damage, advance_payment, other).
 * Posts JE: DR Cash / CR Customer Deposits Liability.
 *
 * @method  POST
 * @body    customer_id, amount, received_date, deposit_type, lease_id?, notes?, currency?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, deposit_number }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.10 (Customer deposits)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

$customerId   = clean_int($_POST['customer_id'] ?? null);
$amount       = clean_decimal($_POST['amount'] ?? null);
$receivedDate = clean_date($_POST['received_date'] ?? null);
$depositType  = clean_string($_POST['deposit_type'] ?? null) ?? 'security';

if (!$customerId)   json_error('VALIDATION_ERROR', 'customer_id is required.', 422);
if (!$amount || bccomp($amount, '0', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'amount must be greater than zero.', 422);
}
if (!$receivedDate) json_error('VALIDATION_ERROR', 'received_date is required.', 422);

$validTypes = ['security', 'damage', 'advance_payment', 'other'];
if (!in_array($depositType, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'deposit_type must be one of: ' . implode(', ', $validTypes), 422);
}

$customer = db_row(
    "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);

$leaseId  = clean_int($_POST['lease_id'] ?? null);
$notes    = clean_string($_POST['notes'] ?? null, 2000);
$currency = clean_string($_POST['currency'] ?? null) ?? 'CAD';

if ($leaseId) {
    if (!db_exists('leases', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$leaseId, $customerId])) {
        json_error('NOT_FOUND', 'Lease not found for this customer.', 404);
    }
}

$result = db_transaction(function () use ($customerId, $amount, $receivedDate, $depositType, $leaseId, $notes, $currency, $customer) {
    $depNumber = AccountingService::nextDepositNumber(substr($receivedDate, 0, 4));

    $cashAccountId    = AccountingService::setting('accounting.default_cash_account_id');
    $depositAccountId = AccountingService::setting('accounting.customer_deposits_account_id');

    $jeId = null;
    if ($cashAccountId && $depositAccountId) {
        $jeLines = [
            [
                'account_id'  => (int)$cashAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "Cash received — Deposit {$depNumber}",
                'customer_id' => $customerId,
            ],
            [
                'account_id'  => (int)$depositAccountId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "Customer deposit liability — {$depNumber}",
                'customer_id' => $customerId,
            ],
        ];

        $je = JournalEntryService::create([
            'entry_date'       => $receivedDate,
            'description'      => "Customer deposit {$depNumber} — {$customer['company_name']}",
            'entry_type'       => 'system',
            'reference'        => $depNumber,
            'source_type'      => 'manual',
            'post_immediately' => true,
        ], $jeLines, current_user_id());
        $jeId = $je['id'];
    }

    $id = db_insert('acc_customer_deposits', [
        'deposit_number'       => $depNumber,
        'customer_id'          => $customerId,
        'lease_id'             => $leaseId,
        'deposit_type'         => $depositType,
        'amount'               => $amount,
        'currency'             => $currency,
        'received_date'        => $receivedDate,
        'status'               => 'held',
        'journal_entry_id'     => $jeId,
        'liability_account_id' => $depositAccountId ? (int)$depositAccountId : null,
        'notes'                => $notes,
        'created_by'           => current_user_id(),
    ]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'customer_deposit',
        'entity_id'   => $id,
        'notes'       => "Deposit {$depNumber} recorded: {$amount} ({$depositType}) — {$customer['company_name']}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $id, 'deposit_number' => $depNumber, 'journal_entry_id' => $jeId];
});

json_success($result, 201);
