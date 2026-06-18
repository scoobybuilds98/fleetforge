<?php
declare(strict_types=1);

/**
 * api/v1/invoices/void.php
 *
 * Void an invoice. Valid from draft or sent status. Paid/partially_paid
 * cannot be voided (requires credit note). Updates denormalized counters.
 *
 * @method  POST
 * @body    id (required), void_reason (required)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 409 INVALID_TRANSITION
 *
 * Decisions: D12 (immutability — void preserves the number, D15)
 * Spec ref: §6 Invoice state machine (draft → void, sent → void)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
$voidReason = clean_string($body['void_reason'] ?? null, 1000);

// ── Delegate to the shared action service (S-AI-ACTION-2) ──────
// Counters (Path B), JE reversal, last_billed anchor walk-back, audit,
// notification, and QBO void enqueue all live in FinancialActions::voidInvoice
// so the AI confirm→apply path runs the exact same logic. ActionException →
// json_error preserves the old inline precondition responses.
try {
    $result = \FleetForge\AI\Actions\FinancialActions::voidInvoice(
        $id, $voidReason,
        current_user_id(), current_user()['name'] ?? 'System',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status);
}

json_success(['id' => $result['id'], 'status' => $result['status']]);
