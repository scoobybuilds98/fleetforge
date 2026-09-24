<?php
declare(strict_types=1);

/**
 * api/v1/portal/requests/reply.php
 *
 * Portal endpoint — called when a customer types a reply on their service
 * request view page. Delegates to RequestMessageService::appendPortalMessage
 * which:
 *   - Trap-8 verifies the portal user owns the request (via customer_id)
 *   - inserts a new portal_service_request_messages row
 *   - re-opens the request if it was resolved/closed (customer follow-up
 *     means it needs another look)
 *   - audit_logs
 *   - notifies the routed admins (via PortalRequestNotifier::resolveRecipients
 *     so the per-type role/user mapping applies the same as original submission)
 *
 * Returns:
 *   - 200 { success: true, message_id, status }
 *   - 422 / 401 / 404 / 500 on the corresponding error class
 *
 * @method  POST
 * @body    { request_id: int, body: string }
 * @auth    portal session (require_portal_auth)
 * @session S-PORTAL-REQUEST-THREAD, S-PORTAL-REDESIGN (JSON 401, 5,000-char cap,
 *          no exception text to the customer)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';

require_method('POST');
// S-PORTAL-REDESIGN: JSON 401 (was a 302 to the login page, which the
// composer's fetch() couldn't read).
require_portal_auth_api();

$body       = json_body();
$requestId  = (int) ($body['request_id'] ?? 0);
$messageBody = trim((string) ($body['body'] ?? ''));

if ($requestId <= 0) {
    json_error('MISSING_REQUIRED', 'request_id is required', 422);
}
if ($messageBody === '') {
    json_error('MISSING_REQUIRED', 'Type a reply before sending.', 422);
}
// S-PORTAL-REDESIGN: a body over the TEXT column limit used to fail the insert
// and come back as a misleading 403. Same cap as a new request's message.
if (mb_strlen($messageBody) > 5000) {
    json_error('VALIDATION_ERROR', 'Replies can be up to 5,000 characters. Please shorten it or send it in two parts.', 422);
}

$portalUserId = portal_user_id();
if ($portalUserId === null) {
    json_error('NOT_AUTHENTICATED', 'Portal session expired', 401);
}

// Trap-8 + ownership check happens inside the service. Service returns 0
// on any rejection; we surface a generic NOT_FOUND/FORBIDDEN to avoid
// leaking whether a non-owned request exists.
try {
    $messageId = \FleetForge\Requests\RequestMessageService::appendPortalMessage(
        $requestId,
        (int) $portalUserId,
        $messageBody
    );

    if ($messageId === 0) {
        json_error('FORBIDDEN', "Cannot reply to this request. It may not exist or you may not have access.", 403);
    }

    $req = db_row("SELECT status FROM portal_service_requests WHERE id = ?", [$requestId]);
    json_success([
        'request_id' => $requestId,
        'message_id' => $messageId,
        'status'     => (string) ($req['status'] ?? 'open'),
        'admins_notified' => true,
    ]);
} catch (\Throwable $e) {
    // Trap 7: log the detail, never send it to the customer.
    error_log('[portal/requests/reply] request ' . $requestId . ': ' . $e->getMessage());
    json_error('INTERNAL_ERROR', 'Your reply could not be sent. Please try again.', 500);
}
