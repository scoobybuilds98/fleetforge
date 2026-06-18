<?php
declare(strict_types=1);

/**
 * api/v1/payments/delete.php
 *
 * Soft-delete a payment and reverse all its effects in a single transaction:
 *   1. Soft-delete the payment row  (D5/D13 — NEVER hard-delete payments)
 *   2. For each allocation, reverse invoices.amount_paid and recompute balance_due
 *   3. Reverse leases.total_paid for each affected lease
 *   4. Reverse customers.outstanding_balance
 *   5. Revert invoice status if it was driven to 'paid' or 'partially_paid'
 *      (paid → partially_paid → sent, depending on remaining allocations)
 *
 * Requires Manager or higher permission. Accountants can record but not void.
 *
 * @method  POST
 * @body    JSON: id (required), reason (required — for audit trail)
 * @auth    Session required; require_permission('payments','delete')
 * @returns 200 { id, deleted: true, invoice_statuses_reverted: [] }
 *
 * Decisions: D5/D13 (soft-delete), D16 (bcmath), Trap 6 (counters in same txn)
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'delete');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
$reason = clean_string($body['reason'] ?? null, 500);

// ── Delegate to the shared action service (S-AI-ACTION-3) ──────
// Allocation reversal, Path B counters, GL JE reversal, audit, notification,
// and QBO void enqueue all live in FinancialActions::voidPayment so the AI
// confirm→apply path runs the exact same logic. ActionException → json_error
// preserves the old inline precondition responses (incl. closed-period → 409).
try {
    $result = \FleetForge\AI\Actions\FinancialActions::voidPayment(
        $id, $reason,
        current_user_id(), current_user()['name'] ?? 'System',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status);
}

json_success([
    'id'                        => $result['id'],
    'deleted'                   => true,
    'invoice_statuses_reverted' => $result['reverted_statuses'],
]);
