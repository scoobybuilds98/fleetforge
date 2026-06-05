<?php
declare(strict_types=1);

/**
 * api/v1/inspections/bulk_delete.php
 *
 * Bulk hard-delete up to 100 inspections in a single request.
 * Each ID is processed independently inside its own transaction — one failure
 * never aborts the batch.
 *
 * Business rules (mirrors inspections/delete.php):
 *   - inspections has NO deleted_at column — this is a HARD DELETE.
 *   - Blocks delete if status = 'complete' or 'signed' (permanent records).
 *   - Cascades handled by DB: inspection_sections + inspection_photos ON DELETE CASCADE.
 *   - damage_claims.inspection_id SET NULL on delete (FK) — claim survives.
 *
 * @method  POST
 * @body    JSON: { "ids": [1, 2, 3] }   (int[], max 100)
 * @auth    Session required; require_permission('inspections','delete')
 * @returns 200 { "success": true, "data": { "deleted": N, "skipped": N, "errors": [...] } }
 *          422 VALIDATION_ERROR if ids is missing, empty, or exceeds 100
 *
 * Decisions: D7 (routing), §7 (audit log)
 * Session: S-BULK-DELETE-INSPECTIONS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'delete');

$body = json_body();

// ── Validate ids array ────────────────────────────────────────────────────────

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

// Coerce to clean integers; reject any non-positive values.
$ids = [];
foreach ($rawIds as $raw) {
    $int = clean_int($raw);
    if (!$int || $int <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $int;
}
$ids = array_values(array_unique($ids));

// ── Process each ID independently ────────────────────────────────────────────

$deleted   = 0;
$skipped   = 0;
$errors    = [];

$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'system';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($ids as $id) {
    // Fetch outside the transaction so we can gate before opening one.
    $insp = db_row(
        "SELECT id, inspection_number, status FROM inspections WHERE id = ?",
        [$id]
    );

    if (!$insp) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Inspection not found.'];
        continue;
    }

    // Block delete of completed or signed inspections — they are permanent records.
    // Mirrors the immutability gate in inspections/delete.php.
    if (in_array($insp['status'], ['complete', 'signed'], true)) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Cannot delete a {$insp['status']} inspection.",
        ];
        continue;
    }

    // Each delete is its own transaction; a failure on one ID isolates cleanly.
    try {
        db_transaction(function () use ($id, $insp, $userId, $userName, $ipAddress): void {
            // Hard delete — inspections has no deleted_at column.
            // Cascade: inspection_sections + inspection_photos removed by FK ON DELETE CASCADE.
            // damage_claims.inspection_id set to NULL by FK ON DELETE SET NULL.
            db_execute("DELETE FROM inspections WHERE id = ?", [$id]);

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'inspections',
                'entity_type'  => 'inspection',
                'entity_id'    => $id,
                'entity_label' => $insp['inspection_number'],
                'old_values'   => json_encode(['status' => $insp['status']]),
                'new_values'   => null,
                'ip_address'   => $ipAddress,
            ]);
        });

        $deleted++;
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to the caller.
        error_log("bulk_delete inspections: id={$id} failed: " . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Delete failed due to a server error.'];
    }
}

if ($deleted > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'deleted' => $deleted,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
