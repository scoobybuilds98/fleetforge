<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/void.php
 *
 * Void an AP bill — transitions draft/approved → void.
 * If the bill had a posted JE, reverses it.
 * Restores vendor.total_spent if bill was approved.
 * Cannot void partially_paid or paid bills.
 *
 * @method  POST
 * @body    id (required), void_reason (required)
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id, status }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (bill lifecycle)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

use FleetForge\Accounting\JournalEntryService;

// VALID-2: accept both JSON and form-encoded payloads.
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];
$id = clean_int($input['id'] ?? null);
$voidReason = clean_string($input['void_reason'] ?? null, 1000);

if (!$id) $fields['id'] = 'Bill ID is required.';
if (!$voidReason) $fields['void_reason'] = 'Please provide a void reason.';

if ($fields) {
    json_validation_error($fields);
}

$result = db_transaction(function () use ($id, $voidReason) {
    $bill = db_row("SELECT * FROM acc_bills WHERE id = ? FOR UPDATE", [$id]);
    if (!$bill) {
        json_validation_error(['id' => 'Bill not found.'], 'Bill not found.');
    }

    // Only draft or approved can be voided
    $voidable = ['draft', 'approved'];
    if (!in_array($bill['status'], $voidable, true)) {
        json_error('INVALID_TRANSITION',
            "Cannot void — bill is {$bill['status']}. Only draft/approved bills can be voided.", 409,
            ['fields' => ['id' => "Cannot void a bill in status '{$bill['status']}'."]]);
    }

    $oldStatus = $bill['status'];

    // If approved (has JE), reverse it
    if ($bill['journal_entry_id']) {
        JournalEntryService::reverse((int) $bill['journal_entry_id'], date('Y-m-d'), current_user_id());

        // Restore vendor.total_spent (Trap 6 — same transaction)
        db_execute(
            "UPDATE vendors SET total_spent = GREATEST(0, total_spent - ?) WHERE id = ?",
            [(string) $bill['total_amount'], (int) $bill['vendor_id']]
        );
    }

    db_update('acc_bills', [
        'status'      => 'void',
        'void_reason' => $voidReason,
        'voided_by'   => current_user_id(),
        'voided_at'   => date('Y-m-d H:i:s'),
        'balance_due' => '0.00',
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'status_change',
        'module'      => 'accounting',
        'entity_type' => 'ap_bill',
        'entity_id'   => $id,
        'notes'       => "Bill {$bill['bill_number']} voided: {$voidReason}",
        'old_values'  => json_encode(['status' => $oldStatus]),
        'new_values'  => json_encode(['status' => 'void']),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $id, 'bill_number' => $bill['bill_number'], 'status' => 'void'];
});

json_success($result);
