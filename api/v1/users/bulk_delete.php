<?php
declare(strict_types=1);

/**
 * api/v1/users/bulk_delete.php
 *
 * Soft-delete multiple users in one request.
 *
 * Business rules:
 *   - Cannot delete yourself — own ID is added to errors array, not a hard abort.
 *   - Cannot delete an active user unless caller is super_admin (must deactivate first).
 *   - Soft delete: SET deleted_at = NOW().
 *   - Each ID processed independently; one failure never aborts the rest.
 *   - Audit log: action='delete' written inside each per-ID transaction.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { "ids": [1, 2, 3] }  (array of int, max 100)
 * @auth    Session required; require_permission('users','delete')
 * @returns 200 { success: true, data: { deleted: N, skipped: N, errors: [...] } }
 *          403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S-BULK-DELETE-USERS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'delete');

// -----------------------------------------------------------------------
// 1. Parse and validate request body
// -----------------------------------------------------------------------
$body = json_body();

$ids = $body['ids'] ?? null;

if (!is_array($ids) || count($ids) === 0) {
    json_error('VALIDATION_ERROR', 'ids must be a non-empty array.', 422);
}

if (count($ids) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 IDs per request.', 422);
}

// Sanitise each element to a positive integer, reject the whole request if any
// entry is unparseable — caller should never be sending garbage IDs.
$clean_ids = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if (!$int) {
        json_error('VALIDATION_ERROR', 'Every element of ids must be a positive integer.', 422);
    }
    $clean_ids[] = $int;
}

// De-duplicate so the caller can't trick us into double-deleting / double-counting.
$clean_ids = array_values(array_unique($clean_ids));

// -----------------------------------------------------------------------
// 2. Process each ID independently
// -----------------------------------------------------------------------
$me       = current_user_id();
$actor    = current_user();
$actor_name = $actor['name'] ?? 'system';
$super    = is_super_admin();

$deleted = 0;
$skipped = 0;
$errors  = [];

foreach ($clean_ids as $id) {

    // -- 2a. Cannot delete yourself -----------------------------------------
    // WHY: in a bulk operation the caller might accidentally include their own
    //      ID; we surface it as a per-item error rather than aborting the batch.
    if ($id === $me) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'You cannot delete your own account.',
        ];
        continue;
    }

    // -- 2b. Fetch the user --------------------------------------------------
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

    // -- 2c. Block deletion of active users unless super_admin ---------------
    // WHY: matches the single-delete rule — non-super_admin callers must
    //      deactivate the user first; super_admin may bypass this gate.
    if ($user['status'] === 'active' && !$super) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Active users cannot be deleted. Deactivate the user first.',
        ];
        continue;
    }

    // -- 2d. Soft delete inside its own transaction + audit log --------------
    // WHY: each ID gets an independent transaction so a DB error on one row
    //      never rolls back successfully completed deletes earlier in the batch.
    try {
        db_transaction(function () use ($id, $user, $me, $actor_name) {
            db_execute(
                "UPDATE users SET deleted_at = NOW() WHERE id = ?",
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $me,
                'user_name'    => $actor_name,
                'action'       => 'delete',
                'module'       => 'users',
                'entity_type'  => 'user',
                'entity_id'    => $id,
                'entity_label' => $user['email'],
                'old_values'   => json_encode(['status' => $user['status'], 'email' => $user['email']]),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        });

        $deleted++;

    } catch (\Throwable $e) {
        // WHY: catching per-ID so a transient DB error on one record never
        //      kills the rest of the batch; error is surfaced to caller.
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Delete failed: ' . $e->getMessage(),
        ];
    }
}

// -----------------------------------------------------------------------
// 3. Return partial-success envelope
// -----------------------------------------------------------------------
json_success([
    'deleted' => $deleted,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
