<?php
declare(strict_types=1);

/**
 * api/v1/users/bulk_update_status.php
 *
 * Bulk activate or deactivate up to 100 users in a single request.
 * Each ID is processed independently — one failure never aborts the batch.
 *
 * Business rules:
 *   - Valid target statuses: 'active', 'inactive' only.
 *   - Cannot change your own status — added to errors array, not a hard abort.
 *   - If a user is already at the target status it is counted as skipped (no error).
 *   - UPDATE stamps updated_by + updated_at on each changed row.
 *   - Audit log: action='status_change', module='users', per changed user.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { "ids": [1, 2, 3], "status": "active"|"inactive" }
 * @auth    Session required; require_permission('users', 'edit')
 * @returns 200 { success: true, data: { actioned: N, skipped: N, errors: [{id, reason}] } }
 *          403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * Decisions: D7 (routing), §7 (audit log)
 * Session: S-BULK-STATUS-USERS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'edit');

// -----------------------------------------------------------------------
// 1. Parse and validate request body
// -----------------------------------------------------------------------
$body = json_body();

// -- 1a. Validate ids array ----------------------------------------------
$ids = $body['ids'] ?? null;

if (!is_array($ids) || count($ids) === 0) {
    json_error('VALIDATION_ERROR', 'ids must be a non-empty array.', 422);
}

if (count($ids) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 IDs per request.', 422);
}

// Sanitise each element to a positive integer; reject the whole request on
// unparseable input — callers should never send garbage IDs.
$clean_ids = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if (!$int) {
        json_error('VALIDATION_ERROR', 'Every element of ids must be a positive integer.', 422);
    }
    $clean_ids[] = $int;
}

// De-duplicate so the caller cannot double-count a single user.
$clean_ids = array_values(array_unique($clean_ids));

// -- 1b. Validate target status ------------------------------------------
// WHY: only 'active'/'inactive' are accepted here; 'suspended' is managed
//      via update_status.php which also handles single-record semantics.
$new_status = clean_string($body['status'] ?? null);
if (!$new_status) {
    json_error('MISSING_REQUIRED', 'status is required.', 422);
}

$allowed_statuses = ['active', 'inactive'];
if (!in_array($new_status, $allowed_statuses, true)) {
    json_error('VALIDATION_ERROR', 'status must be one of: active, inactive.', 422);
}

// -----------------------------------------------------------------------
// 2. Process each ID independently
// -----------------------------------------------------------------------
$me         = current_user_id();
$actor      = current_user();
$actor_name = $actor['name'] ?? 'system';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($clean_ids as $id) {

    // -- 2a. Cannot change your own status ---------------------------------
    // WHY: changing your own status in a bulk operation could lock the caller
    //      out of the system; surface as a per-item error, not a hard abort.
    if ($id === $me) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Cannot change your own status.',
        ];
        continue;
    }

    // -- 2b. Fetch the user ------------------------------------------------
    $user = db_row(
        "SELECT id, name, email, status FROM users WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$user) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'User not found.',
        ];
        continue;
    }

    // -- 2c. Already at target status: silent skip, no error ---------------
    // WHY: idempotency — callers should not be penalised for re-sending a
    //      set that happens to include already-transitioned users.
    if ($user['status'] === $new_status) {
        $skipped++;
        continue;
    }

    // -- 2d. Apply status change inside its own transaction + audit log ----
    // WHY: each ID gets an independent transaction so a DB error on one row
    //      never rolls back successfully committed changes earlier in the batch.
    try {
        db_transaction(function () use ($id, $new_status, $user, $me, $actor_name, $ip_address, $user_agent): void {
            db_execute(
                "UPDATE users SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$new_status, $me, $id]
            );

            db_insert('audit_log', [
                'user_id'      => $me,
                'user_name'    => $actor_name,
                'action'       => 'status_change',
                'module'       => 'users',
                'entity_type'  => 'user',
                'entity_id'    => $id,
                'entity_label' => $user['email'],
                'old_values'   => json_encode(['status' => $user['status']]),
                'new_values'   => json_encode(['status' => $new_status]),
                'ip_address'   => $ip_address,
                'user_agent'   => $user_agent,
            ]);
        });

        $actioned++;

    } catch (\Throwable $e) {
        // WHY: catching per-ID so a transient DB error on one record never
        //      kills the rest of the batch; the error is surfaced to the caller.
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Status update failed: ' . $e->getMessage(),
        ];
    }
}

// -----------------------------------------------------------------------
// 3. Return partial-success envelope
// -----------------------------------------------------------------------
json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
