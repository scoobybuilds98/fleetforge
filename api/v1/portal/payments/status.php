<?php
declare(strict_types=1);

/**
 * api/v1/portal/payments/status.php
 *
 * Polled by portal/payments/payment_success.php to check whether the
 * webhook has handshook this initiation yet. Returns the current
 * initiation row state for the given token.
 *
 * Per spec §11.2 step 11 + D-QBO-15-6 (race condition — webhook may
 * fire BEFORE or AFTER the return URL redirect lands).
 *
 * @method  GET
 * @query   token (initiation_token; 64-hex-char)
 * @auth    portal session (require_portal_auth)
 * @returns 200 { status, qbo_payment_id, ff_invoice_id, completed_at }
 *
 * @session S-QBO-15
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';

require_method('GET');
require_portal_auth();

$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    json_error('MISSING_REQUIRED', 'token is required', 422);
}

$row = \FleetForge\QboPushers\PaymentInitiator::findByToken($token);
if ($row === null) {
    json_error('NOT_FOUND', 'No initiation row for that token', 404);
}

// Verify portal user owns this initiation (Trap 8 portal isolation).
// Either the same portal_user_id OR matched customer via portal_customer_id.
$portalUserId = portal_user_id();
$portalCustomerId = portal_customer_id();
$invoiceOwner = db_row(
    "SELECT customer_id FROM invoices WHERE id = ?",
    [(int) $row['ff_invoice_id']]
);
if (!$invoiceOwner || (int) $invoiceOwner['customer_id'] !== $portalCustomerId) {
    json_error('UNAUTHORIZED', 'Portal user does not own this initiation', 403);
}

json_success([
    'status'         => $row['status'],
    'qbo_payment_id' => $row['qbo_payment_id'],
    'ff_invoice_id'  => (int) $row['ff_invoice_id'],
    'completed_at'   => $row['completed_at'],
    'expires_at'     => $row['expires_at'],
]);
