<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_resolve.php
 *
 * Mark a drift event as resolved. Records the resolver user ID +
 * timestamp + a required resolution note (minimum 5 chars — short
 * enough for "fixed" / "n/a" but blocks empty strings).
 *
 * Optimistic-lock contract: UPDATE … WHERE resolved_at IS NULL.
 * If affected_rows is 0, returns 409 CONFLICT with message
 * "Already resolved by another user" — prevents two operators
 * from both clicking Resolve and the second one overwriting the
 * first's resolution_note silently.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'view')
 *          (Per S-QBO-4 prompt's locked decision: resolve is an
 *           accountant-level annotation, NOT an extended action.
 *           D-PERM-EXPAND-5's force_resync/clear_queue/etc. are
 *           operational controls; resolve is workflow + audit only.)
 * @body    JSON: { id: int, resolution_note: string }
 * @returns 200 { resolved: true }
 *        | 404 NOT_FOUND
 *        | 409 CONFLICT (already resolved)
 *        | 422 VALIDATION_ERROR
 *
 * Spec ref: §15.4 (drift resolution workflows)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body = json_body();
$id   = isset($body['id']) ? (int) $body['id'] : 0;
$note = isset($body['resolution_note']) ? trim((string) $body['resolution_note']) : '';

if ($id <= 0) {
    json_validation_error(['id' => 'Provide id (positive integer).']);
}
if (strlen($note) < 5) {
    json_validation_error(['resolution_note' => 'Resolution note required (min 5 chars).']);
}
if (strlen($note) > 500) {
    $note = substr($note, 0, 497) . '...';
}

try {
    $user   = current_user();
    $userId = $user['id'] ?? null;
    if (!$userId) {
        json_error('FORBIDDEN', 'No authenticated user.', 403);
    }

    // Existence check first — gives a precise 404 if id doesn't
    // exist (vs the more ambiguous 0-affected-rows result that
    // could be either 404 or 409).
    $row = db_row(
        "SELECT id, entity_type, entity_id, category, resolved_at
           FROM acc_qbo_drift_events
          WHERE id = ?",
        [$id]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Drift event #{$id} not found.", 404);
    }
    if ($row['resolved_at'] !== null) {
        json_error('CONFLICT', 'Already resolved by another user.', 409);
    }

    $affected = (int) db_execute(
        "UPDATE acc_qbo_drift_events
            SET resolved_at = NOW(),
                resolved_by_user_id = ?,
                resolution_note = ?
          WHERE id = ?
            AND resolved_at IS NULL",
        [$userId, $note, $id]
    );

    if ($affected === 0) {
        // Race — somebody else resolved between our SELECT + UPDATE.
        json_error('CONFLICT', 'Already resolved by another user.', 409);
    }

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $user['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_drift_event',
        'entity_id'    => $id,
        'entity_label' => "{$row['entity_type']}#{$row['entity_id']} {$row['category']}",
        'notes'        => 'Drift resolved: ' . substr($note, 0, 400),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success(['resolved' => true]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Resolve failed: ' . $e->getMessage(), 500);
}
