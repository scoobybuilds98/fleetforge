<?php
declare(strict_types=1);

/**
 * api/v1/inspections/photos/delete.php
 *
 * Hard delete an inspection photo (no deleted_at on inspection_photos table).
 * Also removes the file from storage via StorageClient.
 *
 * Business rules:
 *   - inspection_photos has NO deleted_at — this is a hard delete.
 *   - Blocks delete if parent inspection is 'signed' (terminal).
 *   - StorageClient::delete() called to remove the file from disk/S3.
 *     If storage delete fails, DB record is still removed (photo is orphaned in storage —
 *     acceptable tradeoff vs leaving a dangling DB record pointing to a deleted file).
 *
 * @method  POST
 * @body    JSON: photo_id (required)
 * @auth    Session required; require_permission('inspections','edit')
 * @returns 200 { deleted: true }
 *
 * Decisions: D7 (routing), D9 (StorageClient), §7 (audit log)
 * Session: S016
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('inspections', 'edit');

$body = json_body();

$photoId = clean_int($body['photo_id'] ?? null);
if (!$photoId) json_error('MISSING_REQUIRED', 'photo_id is required.', 422);

// Fetch photo with parent inspection status
$photo = db_row(
    "SELECT p.id, p.inspection_id, p.file_path, i.status AS inspection_status, i.inspection_number
     FROM inspection_photos p
     JOIN inspections i ON i.id = p.inspection_id
     WHERE p.id = ?",
    [$photoId]
);
if (!$photo) json_error('NOT_FOUND', 'Photo not found.', 404);

// Signed inspections are terminal — photos cannot be removed
if ($photo['inspection_status'] === 'signed') {
    json_error('IMMUTABLE_RECORD', 'Signed inspections cannot be modified.', 422);
}

// Delete DB record first — if storage delete fails, we don't want a dangling DB record
db_transaction(function () use ($photoId, $photo) {
    db_execute("DELETE FROM inspection_photos WHERE id = ?", [$photoId]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'inspections',
        'entity_type'  => 'inspection_photo',
        'entity_id'    => $photoId,
        'entity_label' => $photo['inspection_number'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Remove file from storage — best-effort (log failure, don't throw to client)
try {
    StorageClient::delete($photo['file_path']);
} catch (Throwable $e) {
    error_log("[inspections/photos/delete] Storage delete failed for {$photo['file_path']}: " . $e->getMessage());
}

json_success(['deleted' => true]);
