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

use FleetForge\Accounting\ApPaymentService;

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

// The reversal itself lives in ApPaymentService (shared with the QuickBooks
// bill-payment mirror — S-QBO-BILLPAY-MIRROR); its two refusals map onto
// this endpoint's original responses.
try {
    $result = db_transaction(fn() => ApPaymentService::void(
        $id, $voidReason, current_user_id(), $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ));
} catch (\InvalidArgumentException $e) {
    json_validation_error(['id' => $e->getMessage()], $e->getMessage());
} catch (\DomainException $e) {
    json_error('INVALID_TRANSITION', $e->getMessage(), 409, ['fields' => ['id' => $e->getMessage()]]);
}

// ── QBO sync enqueue (S-QBO-PUSHVOID-TRIO / F7) ─────────────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws. AFTER the
// db_transaction commits (status now 'void'), BEFORE json_success. The
// Enqueuer's gate-0 requires status='void' for the 'void' op; sync_enabled
// + sync_mode gates still apply. Mirrors the create/update hook pattern.
if (!empty($id)) {
    \FleetForge\QboPushers\BillPaymentEnqueuer::enqueue((int) $id, 'void');
}

json_success($result);
