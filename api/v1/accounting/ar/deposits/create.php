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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$customerId   = clean_int($input['customer_id'] ?? null);
$amount       = clean_decimal($input['amount'] ?? null);
$receivedDate = clean_date($input['received_date'] ?? null);
$depositType  = clean_string($input['deposit_type'] ?? null) ?? 'security';

if (!$customerId)   $fields['customer_id']   = 'Please select a customer.';
if ($amount === null || $amount === '') {
    $fields['amount'] = 'Deposit amount is required.';
} elseif (bccomp($amount, '0', 2) <= 0) {
    $fields['amount'] = 'Deposit amount must be greater than zero.';
}
if (!$receivedDate) $fields['received_date'] = 'Received date is required.';

$validTypes = ['security', 'damage', 'advance_payment', 'other'];
if (!in_array($depositType, $validTypes, true)) {
    $fields['deposit_type'] = 'Deposit type must be security, damage, advance payment, or other.';
}

if ($fields) {
    json_validation_error($fields);
}

$customer = db_row(
    "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404, [
        'fields' => ['customer_id' => 'Customer not found.'],
    ]);
}

$leaseId  = clean_int($input['lease_id'] ?? null);
$notes    = clean_string($input['notes'] ?? null, 2000);
$currency = clean_string($input['currency'] ?? null) ?? 'CAD';

if ($leaseId) {
    if (!db_exists('leases', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$leaseId, $customerId])) {
        json_error('NOT_FOUND', 'Lease not found for this customer.', 404, [
            'fields' => ['lease_id' => 'Lease not found for this customer.'],
        ]);
    }
}

$result = db_transaction(function () use ($customerId, $amount, $receivedDate, $depositType, $leaseId, $notes, $currency, $customer) {
    $depNumber = AccountingService::nextDepositNumber(substr($receivedDate, 0, 4));

    $cashAccountId    = AccountingService::setting('accounting.default_cash_account_id');
    $depositAccountId = AccountingService::setting('accounting.customer_deposits_account_id');

    // S-AUDIT-BILLING-ENGINE-1 #10: HARD BLOCK on missing mappings (§16) —
    // this endpoint used to silently record the deposit with NO journal entry
    // (journal_entry_id=NULL, no error, no log). json_error inside the txn
    // rolls back (db_rollback_if_active).
    if (!$cashAccountId || !$depositAccountId) {
        json_error('ACCOUNTING_CONFIG_INCOMPLETE',
            'Cannot record deposit — accounting configuration incomplete. Map the Cash and Customer Deposits accounts in Accounting → Settings.',
            422);
    }

    // Deposit row FIRST so the JE can stamp source_id = the deposit id
    // (S-AUDIT-BILLING-ENGINE-1 #10 — JEs must be traceable to their source).
    $id = db_insert('acc_customer_deposits', [
        'deposit_number'       => $depNumber,
        'customer_id'          => $customerId,
        'lease_id'             => $leaseId,
        'deposit_type'         => $depositType,
        'amount'               => $amount,
        'currency'             => $currency,
        'received_date'        => $receivedDate,
        'status'               => 'held',
        'journal_entry_id'     => null,
        'liability_account_id' => (int)$depositAccountId,
        'notes'                => $notes,
        'created_by'           => current_user_id(),
    ]);

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
        // S-AUDIT-BILLING-ENGINE-1 #10: real source stamping (§16) — was
        // 'manual' with no source_id, making deposit JEs untraceable.
        'source_type'      => 'customer_deposit',
        'source_id'        => $id,
        'post_immediately' => true,
    ], $jeLines, current_user_id());
    $jeId = $je['id'];
    db_update('acc_customer_deposits', ['journal_entry_id' => $jeId], 'id = ?', [$id]);

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
