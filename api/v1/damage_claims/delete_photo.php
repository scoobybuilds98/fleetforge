<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/delete_photo.php
 *
 * Delete a photo from a damage claim.
 * Hard-deletes the damage_claim_photos row and removes the file from storage.
 *
 * damage_claim_photos has no deleted_at — this is a permanent hard delete.
 * StorageClient::delete() removes the backing file (local or S3).
 *
 * @method  POST
 * @body    JSON: photo_id (required), claim_id (required for ownership check)
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { deleted: true }
 *
 * Decisions: D9 (StorageClient for file deletion)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Validate inputs
// -----------------------------------------------------------------------
$body    = json_body();
$photoId = clean_int($body['photo_id'] ?? null);
$claimId = clean_int($body['claim_id'] ?? null);

if (!$photoId) {
    json_error('MISSING_REQUIRED', 'photo_id is required.', 422);
}
if (!$claimId) {
    json_error('MISSING_REQUIRED', 'claim_id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Load photo — verify it belongs to the given claim
// -----------------------------------------------------------------------
$photo = db_row(
    "SELECT p.id, p.claim_id, p.file_path, dc.claim_number
     FROM damage_claim_photos p
     JOIN damage_claims dc ON dc.id = p.claim_id AND dc.deleted_at IS NULL
     WHERE p.id = ? AND p.claim_id = ?",
    [$photoId, $claimId]
);

if (!$photo) {
    json_error('NOT_FOUND', 'Photo not found or does not belong to this claim.', 404);
}

// -----------------------------------------------------------------------
// 3. Hard-delete photo record + remove file from storage
// -----------------------------------------------------------------------
db_transaction(function () use ($photoId, $claimId, $photo) {
    // Remove DB record first — if storage delete fails the record is gone
    // but the orphaned file is recoverable and harmless
    db_execute(
        "DELETE FROM damage_claim_photos WHERE id = ?",
        [$photoId]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'delete',
        'module'       => 'maintenance',
        'entity_type'  => 'damage_claim_photo',
        'entity_id'    => $claimId,
        'entity_label' => $photo['claim_number'],
        'notes'        => "Photo (id:{$photoId}) deleted from claim {$photo['claim_number']}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Remove backing file — outside transaction (storage is not transactional)
// Failure is logged but does not fail the response
try {
    StorageClient::delete($photo['file_path']);
} catch (Throwable $e) {
    error_log("[S012 delete_photo] Storage delete failed for {$photo['file_path']}: " . $e->getMessage());
}

json_success(['deleted' => true]);
