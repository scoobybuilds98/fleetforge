<?php
declare(strict_types=1);

/**
 * api/v1/requests/respond.php
 *
 * Respond to a portal service request + flip status. Stamps assigned_to
 * to the responder + resolved_at when status flips to resolved/closed.
 *
 * @method  POST
 * @auth    require_permission('customers', 'edit')
 * @body    { id: int, response: string, status: 'open'|'in_review'|'resolved'|'closed' }
 *
 * @session S-PORTAL-REQUEST-ROUTING
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

$id       = (int) ($_POST['id'] ?? 0);
$response = trim((string) ($_POST['response'] ?? ''));
$status   = (string) ($_POST['status'] ?? 'in_review');

if ($id <= 0) {
    json_error('MISSING_REQUIRED', 'id is required', 422);
}

$validStatuses = ['open', 'in_review', 'resolved', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    json_error('INVALID_STATUS', "status must be one of: " . implode(', ', $validStatuses), 422);
}

try {
    $req = db_row(
        "SELECT id, customer_id, request_type, subject, status, response, resolved_at
           FROM portal_service_requests WHERE id = ?",
        [$id]
    );
    if (!$req) {
        json_error('NOT_FOUND', "Service request #{$id} not found", 404);
    }

    $now = date('Y-m-d H:i:s');
    $userId = current_user_id();
    $oldStatus   = (string) $req['status'];
    $oldResponse = (string) ($req['response'] ?? '');

    $fields = [
        'status'      => $status,
        'assigned_to' => $userId,
    ];

    if ($response !== '') {
        $fields['response'] = $response;
    }

    if (in_array($status, ['resolved', 'closed'], true) && empty($req['resolved_at'])) {
        $fields['resolved_at'] = $now;
    } elseif ($status === 'open') {
        $fields['resolved_at'] = null;
    }

    db_update('portal_service_requests', $fields, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'action'       => 'update',
        'module'       => 'customers',
        'entity_type'  => 'portal_service_request',
        'entity_id'    => $id,
        'entity_label' => "Service request #{$id}",
        'notes'        => "Status: {$oldStatus} → {$status}" . ($response !== '' ? '; response added' : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Notify the portal user(s) — best-effort, never throws.
    // Fires when admin adds a response OR flips status. Silent admin-edits
    // (e.g. saving the same form without changes) don't fire.
    $statusChanged   = $oldStatus !== $status;
    $responseChanged = $response !== '' && $response !== $oldResponse;

    if ($statusChanged || $responseChanged) {
        try {
            $typeLabel = \FleetForge\Notifications\PortalRequestNotifier::REQUEST_TYPE_LABELS[$req['request_type']]
                       ?? 'Service request';

            $titleParts = [];
            if ($responseChanged) $titleParts[] = 'Response added';
            if ($statusChanged)   $titleParts[] = "Status: {$oldStatus} → {$status}";
            $title = "{$typeLabel} #{$id}: " . implode(' · ', $titleParts);

            $msgParts = ["Your request \"{$req['subject']}\" has an update."];
            if ($responseChanged) {
                $excerpt = mb_substr($response, 0, 280);
                $msgParts[] = "Reply from support:\n{$excerpt}" . (mb_strlen($response) > 280 ? '…' : '');
            }
            if ($statusChanged) {
                $msgParts[] = "Status changed from '{$oldStatus}' to '{$status}'.";
            }
            $message = implode("\n\n", $msgParts);

            // Severity: response or final state → info; re-open after closed → warning
            $severity = ($oldStatus === 'closed' && $status === 'open') ? 'warning' : 'info';

            \FleetForge\Notifications\NotificationService::notifyPortal(
                'service_request.reply.' . $status,
                (int) $req['customer_id'],
                $title,
                $message,
                'service_request',
                $id,
                '/portal/requests/view?id=' . $id,
                $severity
            );
        } catch (\Throwable $e) {
            error_log("[requests/respond] portal notify failed for request {$id}: " . $e->getMessage());
        }
    }

    // Always returns JSON. The view.php form submits via Alpine + FF_Api.post()
    // which expects JSON + injects the X-CSRF-Token header that the bootstrap.php
    // CSRF gate verifies.
    json_success(['id' => $id, 'status' => $status]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Respond failed: ' . $e->getMessage(), 500);
}
