<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ap-payments/void.php
 *
 * Void an AP payment — reverses the JE and restores bill balances.
 * Full audit trail maintained.
 *
 * @method  POST
 * @body    id (required), void_reason (required)
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id, status }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

use FleetForge\Accounting\JournalEntryService;

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];
$id         = clean_int($input['id'] ?? null);
$voidReason = clean_string($input['void_reason'] ?? null, 1000);

if (!$id)         $fields['id']          = 'Payment ID is required.';
if (!$voidReason) $fields['void_reason'] = 'Please provide a void reason.';

if ($fields) {
    json_validation_error($fields);
}

$result = db_transaction(function () use ($id, $voidReason) {
    $payment = db_row("SELECT * FROM acc_ap_payments WHERE id = ? FOR UPDATE", [$id]);
    if (!$payment) {
        json_validation_error(['id' => 'AP payment not found.'], 'AP payment not found.');
    }

    if ($payment['status'] === 'void') {
        json_error('INVALID_TRANSITION',
            'Payment is already void.', 409,
            ['fields' => ['id' => 'Payment is already void.']]);
    }

    // Reverse the JE
    if ($payment['journal_entry_id']) {
        JournalEntryService::reverse((int) $payment['journal_entry_id'], date('Y-m-d'), current_user_id());
    }

    // Restore bill balances from allocations
    $allocations = db_select(
        "SELECT bill_id, amount_applied FROM acc_ap_payment_allocations WHERE ap_payment_id = ?",
        [$id]
    );

    foreach ($allocations as $alloc) {
        // Lock bill row and restore balance (FOR UPDATE — D20)
        $bill = db_row("SELECT id, status, amount_paid, balance_due, total_amount FROM acc_bills WHERE id = ? FOR UPDATE", [(int) $alloc['bill_id']]);
        if ($bill) {
            $newAmountPaid = bcsub((string) $bill['amount_paid'], (string) $alloc['amount_applied'], 2);
            $newBalance = bcadd((string) $bill['balance_due'], (string) $alloc['amount_applied'], 2);

            // Determine new status
            $newStatus = 'approved';
            if (bccomp($newAmountPaid, '0.00', 2) > 0) {
                $newStatus = 'partially_paid';
            }

            db_update('acc_bills', [
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalance,
                'status'      => $newStatus,
            ], 'id = ?', [(int) $alloc['bill_id']]);
        }
    }

    // Void the payment
    db_update('acc_ap_payments', [
        'status'      => 'void',
        'void_reason' => $voidReason,
        'voided_by'   => current_user_id(),
        'voided_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'status_change',
        'module'      => 'accounting',
        'entity_type' => 'ap_payment',
        'entity_id'   => $id,
        'notes'       => "AP Payment {$payment['payment_number']} voided: {$voidReason}",
        'old_values'  => json_encode(['status' => $payment['status']]),
        'new_values'  => json_encode(['status' => 'void']),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $id, 'payment_number' => $payment['payment_number'], 'status' => 'void'];
});

// ── QBO sync enqueue (S-QBO-PUSHVOID-TRIO / F7) ─────────────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws. AFTER the
// db_transaction commits (status now 'void'), BEFORE json_success. The
// Enqueuer's gate-0 requires status='void' for the 'void' op; sync_enabled
// + sync_mode gates still apply. Mirrors the create/update hook pattern.
if (!empty($id)) {
    \FleetForge\QboPushers\BillPaymentEnqueuer::enqueue((int) $id, 'void');
}

json_success($result);
