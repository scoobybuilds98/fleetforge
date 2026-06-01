<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/unapply.php
 *
 * Un-apply a single credit-note application — the explicit inverse of apply.php
 * (F27 / S-QBO-CREDIT-APP-UNAPPLY). Reverses the 5 counters + posts a reversing
 * GL JE + marks the application 'reversed', then enqueues the QBO Payment void.
 *
 * Thin endpoint: the financial reversal lives in
 * lib/CreditApplicationReversal::reverse() (testable; D-QBO-UNAPPLY-2).
 *
 * @method  POST
 * @body    JSON: application_id (required)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { application_id, credit_note_number, invoice_number,
 *                invoice_status, invoice_balance_due, amount_restored }
 *
 * @session S-QBO-CREDIT-APP-UNAPPLY
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\CreditApplicationReversal;

$body = json_body();
$applicationId = clean_int($body['application_id'] ?? null);
if (!$applicationId) {
    json_error('MISSING_REQUIRED', 'application_id is required.', 422);
}

// Pre-flight for clean HTTP codes (the service re-checks under FOR UPDATE).
$appCheck = db_row(
    "SELECT id, status FROM credit_note_applications WHERE id = ?",
    [$applicationId]
);
if (!$appCheck) {
    json_error('NOT_FOUND', 'Credit application not found.', 404);
}
if ($appCheck['status'] === 'reversed') {
    json_error('INVALID_TRANSITION', 'This credit application has already been reversed.', 409);
}

try {
    $result = CreditApplicationReversal::reverse($applicationId, current_user_id());
} catch (\RuntimeException $e) {
    if ($e->getMessage() === CreditApplicationReversal::ERR_NOT_FOUND) {
        json_error('NOT_FOUND', 'Credit application not found.', 404);
    }
    if ($e->getMessage() === CreditApplicationReversal::ERR_ALREADY_REVERSED) {
        json_error('INVALID_TRANSITION', 'This credit application has already been reversed.', 409);
    }
    json_error('INTERNAL_ERROR', 'Un-apply failed: ' . $e->getMessage(), 500);
}

// S-QBO-CREDIT-APP-UNAPPLY (F27): enqueue the QBO Payment void after the
// reversal commits. Best-effort per D-ENQUEUER-CONTRACT — never throws;
// sync_enabled='0' default makes this a silent no-op until cutover.
if (is_array($result) && !empty($result['application_id'])) {
    try {
        \FleetForge\QboPushers\CreditApplicationEnqueuer::enqueue(
            (int) $result['application_id'],
            'void'
        );
    } catch (\Throwable $e) {
        error_log("credit_notes/unapply.php: CreditApplicationEnqueuer threw despite best-effort discipline: " . $e->getMessage());
    }
}

json_success($result);
