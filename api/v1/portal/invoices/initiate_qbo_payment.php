<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/initiate_qbo_payment.php
 *
 * Portal endpoint — called when a customer clicks "Pay Online" on the
 * invoice view page. Delegates to PaymentInitiator::generate which
 * runs preflight gates + generates the Intuit Payments hosted URL +
 * persists the initiation row.
 *
 * Per QUICKBOOKS_SPEC.md §11.2 step 5.
 *
 * Returns:
 *   - 200 { success: true, url, token, expires_at, status }  — caller redirects
 *   - 200 { success: false, status, error }                  — display error to customer
 *   - 401/403 via require_portal_auth() redirect              — not authenticated
 *
 * @method  POST
 * @body    { invoice_id: int }
 * @auth    portal session (require_portal_auth)
 * @session S-QBO-15
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';

require_method('POST');
require_portal_auth();

$body      = json_body();
$invoiceId = clean_int($body['invoice_id'] ?? null);
if (!$invoiceId) {
    json_error('MISSING_REQUIRED', 'invoice_id is required', 422);
}

$portalUserId = portal_user_id();
if ($portalUserId === null) {
    json_error('NOT_AUTHENTICATED', 'Portal session expired', 401);
}

$result = \FleetForge\QboPushers\PaymentInitiator::generate($invoiceId, $portalUserId);

// Audit log the click regardless of outcome.
try {
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'portal:' . $portalUserId,
        'action'       => 'create',
        'module'       => 'portal',
        'entity_type'  => 'qbo_payment_initiation',
        'entity_id'    => $invoiceId,
        'entity_label' => "Pay Online click on invoice {$invoiceId}",
        'notes'        => 'Result: ' . ($result['status'] ?? 'unknown')
                        . ($result['error'] ? ' — ' . substr((string) $result['error'], 0, 200) : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (\Throwable $e) {
    error_log('[initiate_qbo_payment audit] ' . $e->getMessage());
}

json_success($result);
