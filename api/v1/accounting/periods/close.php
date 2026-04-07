<?php declare(strict_types=1);

/**
 * api/v1/accounting/periods/close.php
 *
 * Close an accounting period: transition status from 'open' to 'closed'.
 * Only open periods can be closed. Sets closed_by and closed_at.
 * Writes an audit log entry for the state change.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('period_management','edit')
 * @returns 200 updated period | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('period_management', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$result = null;

db_transaction(function () use ($id, &$result) {
    // WHY: FOR UPDATE prevents two concurrent close requests from racing
    $period = db_row(
        "SELECT * FROM acc_periods WHERE id = ? FOR UPDATE",
        [$id]
    );

    if (!$period) {
        json_error('NOT_FOUND', 'Accounting period not found.', 404);
    }

    if ($period['status'] !== 'open') {
        json_error(
            'INVALID_TRANSITION',
            "Cannot close period {$period['name']}: current status is '{$period['status']}'. Only open periods can be closed.",
            409
        );
    }

    $userId = current_user_id();
    $now    = date('Y-m-d H:i:s');

    db_update('acc_periods', [
        'status'    => 'closed',
        'closed_by' => $userId,
        'closed_at' => $now,
    ], 'id = ?', [$id]);

    // --- Audit log ---
    $user = current_user();
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $user['name'] ?? 'System',
        'action'       => 'status_change',
        'module'       => 'accounting',
        'entity_type'  => 'period',
        'entity_id'    => $id,
        'entity_label' => $period['name'],
        'notes'        => "Period {$period['name']} closed",
        'old_values'   => json_encode(['status' => 'open']),
        'new_values'   => json_encode(['status' => 'closed']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = db_row("SELECT * FROM acc_periods WHERE id = ?", [$id]);
});

json_success($result);
