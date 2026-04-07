<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/bad_debt_writeoff.php
 *
 * Write off an invoice as bad debt. Updates invoice status to 'written_off',
 * records in acc_bad_debt_writeoffs, and posts JE via AutoEntryBridge.
 *
 * @method  POST
 * @body    invoice_id (required), reason (required)
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Bad debt write-off)
 * Session: S031
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

use FleetForge\Accounting\AutoEntryBridge;

$invoiceId = clean_int($_POST['invoice_id'] ?? null);
$reason    = trim($_POST['reason'] ?? '');

if (!$invoiceId) json_error('VALIDATION_ERROR', 'invoice_id is required.', 422);
if ($reason === '') json_error('VALIDATION_ERROR', 'reason is required.', 422);

$result = db_transaction(function () use ($invoiceId, $reason) {
    // Lock invoice row to prevent concurrent modification
    $invoice = db_row(
        "SELECT id, invoice_number, customer_id, balance_due, status, company_name_snapshot
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) json_error('NOT_FOUND', 'Invoice not found.', 404);

    // Only overdue or partially_paid invoices can be written off
    $writableStatuses = ['sent', 'overdue', 'partially_paid'];
    if (!in_array($invoice['status'], $writableStatuses, true)) {
        json_error('INVALID_TRANSITION', "Cannot write off invoice with status '{$invoice['status']}'. Must be sent, overdue, or partially_paid.", 409);
    }

    $amount = (string) $invoice['balance_due'];
    if (bccomp($amount, '0', 2) <= 0) {
        json_error('VALIDATION_ERROR', 'Invoice has no balance to write off.', 422);
    }

    // Record in acc_bad_debt_writeoffs
    $writeoffId = db_insert('acc_bad_debt_writeoffs', [
        'invoice_id'  => $invoiceId,
        'customer_id' => $invoice['customer_id'],
        'writeoff_date' => date('Y-m-d'),
        'amount'      => $amount,
        'reason'      => $reason,
        'created_by'  => current_user_id(),
    ]);

    // Update invoice status to written_off and zero out balance
    db_update('invoices', [
        'status'      => 'written_off',
        'balance_due' => '0.00',
        'updated_by'  => current_user_id(),
    ], 'id = ?', [$invoiceId]);

    // Update customer outstanding_balance
    db_execute(
        "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id = ?",
        [$amount, $invoice['customer_id']]
    );

    // Post JE via bridge: DR Bad Debt Expense / CR AR
    $je = AutoEntryBridge::onBadDebtWriteOff($invoiceId, $amount, current_user_id());

    // Link JE to writeoff record
    if ($je) {
        db_update('acc_bad_debt_writeoffs', [
            'journal_entry_id' => $je['id'],
        ], 'id = ?', [$writeoffId]);
    }

    // Audit log
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'bad_debt_writeoff',
        'entity_id'   => $writeoffId,
        'notes'       => "Bad debt write-off: {$invoice['invoice_number']} — {$amount} ({$reason})",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'id'               => $writeoffId,
        'invoice_number'   => $invoice['invoice_number'],
        'amount'           => $amount,
        'journal_entry_id' => $je['id'] ?? null,
    ];
});

json_success($result, 201);
