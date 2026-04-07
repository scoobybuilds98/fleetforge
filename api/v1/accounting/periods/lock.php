<?php declare(strict_types=1);

/**
 * api/v1/accounting/periods/lock.php
 *
 * Lock an accounting period: transition status from 'closed' to 'locked'.
 * Locked periods are permanently sealed — no journal entries can be posted.
 * This is a privileged operation restricted to super_admin role only.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_role('super_admin')
 * @returns 200 updated period | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_role('super_admin');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$result = null;

db_transaction(function () use ($id, &$result) {
    // WHY: FOR UPDATE prevents two concurrent lock requests from racing
    $period = db_row(
        "SELECT * FROM acc_periods WHERE id = ? FOR UPDATE",
        [$id]
    );

    if (!$period) {
        json_error('NOT_FOUND', 'Accounting period not found.', 404);
    }

    if ($period['status'] !== 'closed') {
        json_error(
            'INVALID_TRANSITION',
            "Cannot lock period {$period['name']}: current status is '{$period['status']}'. Only closed periods can be locked.",
            409
        );
    }

    $userId = current_user_id();
    $now    = date('Y-m-d H:i:s');

    db_update('acc_periods', [
        'status'     => 'locked',
        'locked_by'  => $userId,
        'locked_at'  => $now,
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
        'notes'        => "Period {$period['name']} locked by super_admin",
        'old_values'   => json_encode(['status' => 'closed']),
        'new_values'   => json_encode(['status' => 'locked']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = db_row("SELECT * FROM acc_periods WHERE id = ?", [$id]);
});

json_success($result);
