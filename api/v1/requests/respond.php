<?php
declare(strict_types=1);

/**
 * api/v1/requests/respond.php
 *
 * Admin-side append a reply to a portal service request thread + optionally
 * flip status. Delegates to RequestMessageService::appendAdminMessage which:
 *   - inserts a new portal_service_request_messages row
 *   - updates legacy portal_service_requests.response with latest admin body
 *   - stamps assigned_to + resolved_at as needed
 *   - audit_logs
 *   - notifies the customer's portal user(s) (best-effort)
 *
 * @method  POST
 * @auth    require_permission('customers', 'edit')
 * @body    { id: int, response: string, status: 'open'|'in_review'|'resolved'|'closed' }
 *
 * @session S-PORTAL-REQUEST-THREAD (succeeds S-PORTAL-REQUEST-ROUTING)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

$id       = (int) ($_POST['id'] ?? 0);
$response = trim((string) ($_POST['response'] ?? ''));
$status   = (string) ($_POST['status'] ?? '');

if ($id <= 0) {
    json_error('MISSING_REQUIRED', 'id is required', 422);
}

$validStatuses = ['open', 'in_review', 'resolved', 'closed'];
if ($status !== '' && !in_array($status, $validStatuses, true)) {
    json_error('INVALID_STATUS', "status must be one of: " . implode(', ', $validStatuses), 422);
}

// Operator intent: at least one of (new message body, status flip) must be present
$req = db_row("SELECT id, status FROM portal_service_requests WHERE id = ?", [$id]);
if (!$req) {
    json_error('NOT_FOUND', "Service request #{$id} not found", 404);
}

$statusChanged = $status !== '' && $status !== (string) $req['status'];
if ($response === '' && !$statusChanged) {
    json_error(
        'NO_CHANGE',
        "Type a reply or change the status before saving. Empty saves with no status flip are ignored to prevent silent no-op operations.",
        422
    );
}

try {
    $messageId = \FleetForge\Requests\RequestMessageService::appendAdminMessage(
        $id,
        (int) current_user_id(),
        $response,
        $status !== '' ? $status : null
    );

    json_success([
        'id'                => $id,
        'message_id'        => $messageId,
        'status'            => $status !== '' ? $status : $req['status'],
        // RequestMessageService always notifies on non-empty body or status flip
        // (no internal-note path in v1) — surface for the admin flash.
        'customer_notified' => true,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Respond failed: ' . $e->getMessage(), 500);
}
