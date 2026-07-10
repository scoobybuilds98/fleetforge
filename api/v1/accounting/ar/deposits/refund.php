<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/deposits/refund.php
 *
 * Refund a held customer deposit back to the customer.
 * Posts JE: DR Customer Deposits Liability / CR Cash.
 *
 * @method  POST
 * @body    deposit_id (required), refund_method?
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { deposit_id, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.10 (Customer deposits — refunded)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$depositId    = clean_int($input['deposit_id'] ?? null);
$refundMethod = clean_string($input['refund_method'] ?? null) ?? 'check';

if (!$depositId) $fields['deposit_id'] = 'Please select a deposit.';

$validRefundMethods = ['check', 'eft', 'wire', 'credit_card', 'cash', 'other'];
if (!in_array($refundMethod, $validRefundMethods, true)) {
    $fields['refund_method'] = 'Refund method must be check, EFT, wire, credit card, cash, or other.';
}

if ($fields) {
    json_validation_error($fields);
}

$result = db_transaction(function () use ($depositId, $refundMethod) {
    $deposit = db_row(
        "SELECT d.*, c.company_name
         FROM acc_customer_deposits d
         JOIN customers c ON c.id = d.customer_id
         WHERE d.id = ? FOR UPDATE",
        [$depositId]
    );
    if (!$deposit) {
        json_error('NOT_FOUND', 'Deposit not found.', 404, [
            'fields' => ['deposit_id' => 'Deposit not found.'],
        ]);
    }

    if ($deposit['status'] !== 'held') {
        json_error('INVALID_TRANSITION',
            "Deposit status is '{$deposit['status']}' — only 'held' deposits can be refunded.", 409,
            ['fields' => ['deposit_id' => "Deposit status is '{$deposit['status']}' — only 'held' deposits can be refunded."]]);
    }

    $amount = (string) $deposit['amount'];

    // Post JE: DR Deposit Liability / CR Cash
    $depositAccountId = AccountingService::setting('accounting.customer_deposits_account_id');
    $cashAccountId    = AccountingService::setting('accounting.default_cash_account_id');

    // S-AUDIT-BILLING-ENGINE-1 #10: HARD BLOCK on missing mappings (§16) —
    // this endpoint used to mark the deposit refunded even when the JE was
    // silently skipped (fail-open). json_error inside the txn rolls back.
    if (!$depositAccountId || !$cashAccountId) {
        json_error('ACCOUNTING_CONFIG_INCOMPLETE',
            'Cannot refund deposit — accounting configuration incomplete. Map the Cash and Customer Deposits accounts in Accounting → Settings.',
            422);
    }

    $jeId = null;
    if (true) {
        $jeLines = [
            [
                'account_id'  => (int)$depositAccountId,
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "Deposit refunded — {$deposit['deposit_number']}",
                'customer_id' => $deposit['customer_id'],
            ],
            [
                'account_id'  => (int)$cashAccountId,
                'debit'       => '0.00',
                'credit'      => $amount,
                'description' => "Cash refund — {$deposit['deposit_number']}",
                'customer_id' => $deposit['customer_id'],
            ],
        ];

        $je = JournalEntryService::create([
            'entry_date'       => date('Y-m-d'),
            'description'      => "Deposit refund {$deposit['deposit_number']} — {$deposit['company_name']}",
            'entry_type'       => 'system',
            'reference'        => $deposit['deposit_number'],
            // S-AUDIT-BILLING-ENGINE-1 #10: real source stamping (§16) — was
            // 'manual' with no source_id, making deposit JEs untraceable.
            'source_type'      => 'customer_deposit',
            'source_id'        => $depositId,
            'post_immediately' => true,
        ], $jeLines, current_user_id());
        $jeId = $je['id'];
    }

    db_update('acc_customer_deposits', [
        'status'        => 'refunded',
        'refund_date'   => date('Y-m-d'),
        'refund_method' => $refundMethod,
    ], 'id = ?', [$depositId]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'customer_deposit',
        'entity_id'   => $depositId,
        'old_values'  => json_encode(['status' => 'held']),
        'new_values'  => json_encode(['status' => 'refunded', 'refund_method' => $refundMethod]),
        'notes'       => "Deposit {$deposit['deposit_number']} refunded ({$refundMethod}) — {$amount}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'deposit_id'       => $depositId,
        'amount_refunded'  => $amount,
        'refund_method'    => $refundMethod,
        'journal_entry_id' => $jeId,
    ];
});

json_success($result);
