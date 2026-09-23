<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/refund.php
 *
 * Pay a customer's credit (a credit note's unused balance) back in cash —
 * SOP known issue I18 ("no way to refund a credit note as cash; only a lease
 * precharge can be refunded"). Overpayments always become credit notes, so
 * until now a customer who overpaid could never be paid back.
 *
 * Posts DR 2060 Customer Credits / CR the bank it was paid from, at the
 * credit note's frozen rate (the rate its liability was booked at); a USD
 * cash leg carries its USD figure. Reduces amount_remaining and sets the
 * status (partially_used / fully_used). One credit_note_refunds row per
 * refund.
 *
 * QuickBooks: not pushed (credit_note_refund is bridge-derived — a JE would
 * not close the QuickBooks CreditMemo). The response says so; the accountant
 * records the refund against the credit memo in QuickBooks.
 *
 * @method  POST
 * @body    JSON: credit_note_id (required), amount (required, in the credit
 *          note's currency), refund_date?, method (required: cheque, eft,
 *          e_transfer, wire, credit_card, cash, other), reference?,
 *          bank_account_id?, notes?
 * @auth    Session required; require_permission('invoices','edit') (same as
 *          applying a credit) AND require_permission('payments','create')
 * @returns 201 { refund_id, credit_note_number, amount, status, amount_remaining, journal_entry_id }
 *
 * @session S-SOP-KNOWN-ISSUES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Accounting\AutoEntryBridge;
use FleetForge\Accounting\BankService;
use FleetForge\Accounting\JournalEntryService;
use FleetForge\Accounting\AccountingService;

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');
require_permission('payments', 'create');

$body       = json_body();
$cnId       = clean_int($body['credit_note_id'] ?? null);
$amount     = clean_decimal($body['amount'] ?? null);
$refundDate = clean_date($body['refund_date'] ?? null) ?? ff_today();
$method     = (string) ($body['method'] ?? '');
$reference  = clean_string($body['reference'] ?? null, 100);
$bankIdIn   = clean_int($body['bank_account_id'] ?? null);
$notes      = clean_string($body['notes'] ?? null, 2000);

$methods = ['cheque', 'eft', 'e_transfer', 'wire', 'credit_card', 'cash', 'other'];
$fields  = [];
if (!$cnId) $fields['credit_note_id'] = 'Credit note is required.';
if ($amount === null || $amount === '' || bccomp($amount, '0', 2) <= 0) {
    $fields['amount'] = 'Enter the amount paid back (greater than zero).';
}
if (!in_array($method, $methods, true)) {
    $fields['method'] = 'Choose how it was paid back.';
}
if ($fields) {
    json_validation_error($fields);
}

$result = null;

db_transaction(function () use ($cnId, $amount, $refundDate, $method, $reference, $bankIdIn, $notes, &$result) {
    $cn = db_row(
        "SELECT * FROM credit_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$cnId]
    );
    if (!$cn) {
        json_error('NOT_FOUND', 'Credit note not found.', 404);
    }
    if (!in_array($cn['status'], ['active', 'partially_used'], true)) {
        json_error('INVALID_TRANSITION', "A {$cn['status']} credit note has nothing left to refund.", 409);
    }
    if (bccomp($amount, (string) $cn['amount_remaining'], 2) > 0) {
        json_validation_error(['amount' => "Only {$cn['currency']} {$cn['amount_remaining']} of this credit is left."]);
    }

    $currency = (string) $cn['currency'];
    $bank = BankService::resolveReceivingBank($bankIdIn, $currency);
    if ($bank['error'] !== null) {
        json_validation_error(['bank_account_id' => $bank['error']], $bank['error']);
    }

    // CAD at the credit note's frozen rate — the rate its 2060 credit used.
    $rate = $currency === 'CAD' ? '1' : (string) ($cn['exchange_rate_to_cad'] ?? '');
    if ($rate !== '1' && (trim($rate) === '' || bccomp($rate, '0', 6) <= 0)) {
        json_error('FX_RATE_MISSING', "Credit note {$cn['credit_note_number']} has no frozen exchange rate.", 422);
    }
    $cad = $rate === '1' ? $amount : bcround(bcmul($amount, $rate, 6), 2);

    $creditsAccountId = (int) AccountingService::setting('accounting.customer_credits_account_id', 0);
    try {
        $cashAccountId = AutoEntryBridge::cashAccountForBank($bank['id']);
    } catch (\RuntimeException $e) {
        $cashAccountId = 0;
    }
    if (!$creditsAccountId || !$cashAccountId) {
        json_error('ACCOUNTING_CONFIG_INCOMPLETE',
            'Map the Customer Credits (2060) and Cash accounts in Accounting → Settings first.', 422);
    }

    $refundId = db_insert('credit_note_refunds', [
        'credit_note_id'  => $cnId,
        'customer_id'     => (int) $cn['customer_id'],
        'amount'          => $amount,
        'amount_cad'      => $cad,
        'refund_date'     => $refundDate,
        'method'          => $method,
        'reference'       => $reference,
        'bank_account_id' => $bank['id'],
        'notes'           => $notes,
        'created_by'      => current_user_id(),
    ]);

    $je = JournalEntryService::create([
        'entry_date'       => $refundDate,
        'description'      => "Credit refunded — {$cn['credit_note_number']} — " . ($cn['company_name_snapshot'] ?? ''),
        'entry_type'       => 'system',
        'reference'        => $cn['credit_note_number'],
        'source_type'      => 'credit_note_refund',
        'source_id'        => $refundId,
        'post_immediately' => true,
    ], [
        [
            'account_id'  => $creditsAccountId,
            'debit'       => $cad,
            'credit'      => '0.00',
            'description' => "Customer credit paid back — {$cn['credit_note_number']}",
            'customer_id' => (int) $cn['customer_id'],
        ],
        [
            'account_id'  => $cashAccountId,
            'debit'       => '0.00',
            'credit'      => $cad,
            'description' => "Refund {$method}" . ($reference ? " {$reference}" : ''),
            'customer_id' => (int) $cn['customer_id'],
        ] + AutoEntryBridge::foreignLeg($currency, $amount, $rate),
    ], current_user_id());

    db_update('credit_note_refunds', ['journal_entry_id' => (int) $je['id']], 'id = ?', [$refundId]);

    $remaining = bcsub((string) $cn['amount_remaining'], $amount, 2);
    $status    = bccomp($remaining, '0', 2) === 0 ? 'fully_used' : 'partially_used';
    db_update('credit_notes', [
        'amount_remaining' => $remaining,
        'status'           => $status,
        'updated_at'       => ff_now_utc(),
    ], 'id = ?', [$cnId]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note',
        'entity_id'    => $cnId,
        'entity_label' => $cn['credit_note_number'],
        'old_values'   => json_encode(['status' => $cn['status'], 'amount_remaining' => $cn['amount_remaining']]),
        'new_values'   => json_encode(['status' => $status, 'amount_remaining' => $remaining]),
        'notes'        => "Refunded {$currency} {$amount} by {$method}" . ($reference ? " ({$reference})" : '') . " — JE {$je['entry_number']}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'refund_id'          => $refundId,
        'credit_note_number' => $cn['credit_note_number'],
        'amount'             => $amount,
        'status'             => $status,
        'amount_remaining'   => $remaining,
        'journal_entry_id'   => (int) $je['id'],
        'quickbooks_note'    => 'Not sent to QuickBooks — record this refund against the credit memo in QuickBooks.',
    ];
});

invalidate_dashboard_cache();

json_success($result, 201);
