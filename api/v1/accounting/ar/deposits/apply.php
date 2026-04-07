<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/deposits/apply.php
 *
 * Apply a held customer deposit to an invoice.
 * Posts JE: DR Customer Deposits Liability / CR AR.
 *
 * @method  POST
 * @body    deposit_id (required), invoice_id (required)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { deposit_id, invoice_id, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.10 (Customer deposits — applied)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

$depositId = clean_int($_POST['deposit_id'] ?? null);
$invoiceId = clean_int($_POST['invoice_id'] ?? null);

if (!$depositId) json_error('VALIDATION_ERROR', 'deposit_id is required.', 422);
if (!$invoiceId) json_error('VALIDATION_ERROR', 'invoice_id is required.', 422);

$result = db_transaction(function () use ($depositId, $invoiceId) {
    $deposit = db_row(
        "SELECT * FROM acc_customer_deposits WHERE id = ? FOR UPDATE",
        [$depositId]
    );
    if (!$deposit) json_error('NOT_FOUND', 'Deposit not found.', 404);

    if ($deposit['status'] !== 'held') {
        json_error('INVALID_TRANSITION', "Deposit status is '{$deposit['status']}' — only 'held' deposits can be applied.", 409);
    }

    $invoice = db_row(
        "SELECT id, invoice_number, customer_id, balance_due, company_name_snapshot
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) json_error('NOT_FOUND', 'Invoice not found.', 404);

    // Deposit and invoice must belong to the same customer
    if ((int)$deposit['customer_id'] !== (int)$invoice['customer_id']) {
        json_error('VALIDATION_ERROR', 'Deposit and invoice must belong to the same customer.', 422);
    }

    $amount = (string) $deposit['amount'];

    // Post JE: DR Deposit Liability / CR AR
    $depositAccountId = AccountingService::setting('accounting.customer_deposits_account_id');
    $arAccountId      = AccountingService::setting('accounting.ar_account_id');

    $jeId = null;
    if ($depositAccountId && $arAccountId) {
        $jeLines = [
            [
                'account_id'  => (int)$depositAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "Deposit applied — {$deposit['deposit_number']} → {$invoice['invoice_number']}",
                'customer_id' => $deposit['customer_id'],
            ],
            [
                'account_id'  => (int)$arAccountId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "AR reduced by deposit — {$deposit['deposit_number']}",
                'customer_id' => $deposit['customer_id'],
            ],
        ];

        $je = JournalEntryService::create([
            'entry_date'       => date('Y-m-d'),
            'description'      => "Deposit {$deposit['deposit_number']} applied to {$invoice['invoice_number']}",
            'entry_type'       => 'system',
            'reference'        => $deposit['deposit_number'] . '→' . $invoice['invoice_number'],
            'source_type'      => 'manual',
            'post_immediately' => true,
        ], $jeLines, current_user_id());
        $jeId = $je['id'];
    }

    // Update deposit
    db_update('acc_customer_deposits', [
        'status'                => 'applied',
        'applied_to_invoice_id' => $invoiceId,
        'applied_date'          => date('Y-m-d'),
    ], 'id = ?', [$depositId]);

    // Reduce invoice balance
    $newBalance = bcsub((string)$invoice['balance_due'], $amount, 2);
    if (bccomp($newBalance, '0', 2) < 0) $newBalance = '0.00';

    $invoiceStatus = bccomp($newBalance, '0', 2) === 0 ? 'paid' : 'partially_paid';
    db_update('invoices', [
        'balance_due' => $newBalance,
        'status'      => $invoiceStatus,
        'updated_by'  => current_user_id(),
    ], 'id = ?', [$invoiceId]);

    // Update customer outstanding_balance
    db_execute(
        "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id = ?",
        [$amount, $deposit['customer_id']]
    );

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'customer_deposit',
        'entity_id'   => $depositId,
        'old_values'  => json_encode(['status' => 'held']),
        'new_values'  => json_encode(['status' => 'applied', 'applied_to_invoice_id' => $invoiceId]),
        'notes'       => "Deposit {$deposit['deposit_number']} applied to {$invoice['invoice_number']}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'deposit_id'       => $depositId,
        'invoice_id'       => $invoiceId,
        'amount_applied'   => $amount,
        'journal_entry_id' => $jeId,
    ];
});

json_success($result);
