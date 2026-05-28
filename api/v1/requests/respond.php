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
    $req = db_row("SELECT id, status FROM portal_service_requests WHERE id = ?", [$id]);
    if (!$req) {
        json_error('NOT_FOUND', "Service request #{$id} not found", 404);
    }

    $now = date('Y-m-d H:i:s');
    $userId = current_user_id();

    $fields = [
        'status'      => $status,
        'assigned_to' => $userId,
    ];

    if ($response !== '') {
        $fields['response'] = $response;
    }

    if (in_array($status, ['resolved', 'closed'], true) && empty($req['resolved_at'] ?? null)) {
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
        'notes'        => "Status: {$req['status']} → {$status}" . ($response !== '' ? '; response added' : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // If this is a form POST (not XHR), redirect back to the view page.
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Location: ' . base_url('requests/view?id=' . $id));
        exit;
    }

    json_success(['id' => $id, 'status' => $status]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Respond failed: ' . $e->getMessage(), 500);
}
