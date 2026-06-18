<?php
declare(strict_types=1);

/**
 * api/v1/invoices/send.php
 *
 * Transition invoice from draft → sent. Freezes all financial fields (D12).
 * Invoice send vs email delivery are separate (PASS-15:E3): this endpoint
 * always succeeds (DB write). Email may fail separately.
 *
 * @method  POST
 * @body    id (required), sent_to_email (optional override)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 422 INVALID_TRANSITION
 *
 * Decisions: D12 (immutability after send)
 * Spec ref: §6 Invoice state machine (draft → sent)
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

// ── Delegate to the shared action service (S-AI-ACTION-2) ──────
// Path B counters, precharge lifecycle stamp, revenue JE posting, audit,
// notifications, and QBO enqueue all live in FinancialActions::sendInvoice so
// the AI confirm→apply path runs the exact same logic. ActionException →
// json_error preserves the old inline precondition responses.
try {
    $result = \FleetForge\AI\Actions\FinancialActions::sendInvoice(
        $id, $body['sent_to_email'] ?? null,
        current_user_id(), current_user()['name'] ?? 'System',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status);
}

json_success(['id' => $result['id'], 'status' => $result['status']]);

