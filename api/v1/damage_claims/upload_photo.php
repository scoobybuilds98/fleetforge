<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/upload_photo.php
 *
 * Upload a photo to a damage claim via StorageClient.
 *
 * Security (Trap 5):
 *   - MIME type detected server-side via finfo_file() — never trust $_FILES['type']
 *   - Extension derived from MIME map, not from the original filename
 *   - Safe filename: {claim_id}_photo_{timestamp}.{ext}
 *   - Storage path: damage/{claim_id}/{safe_name}
 *
 * Limits (spec §4.6):
 *   - Max 10 photos per claim
 *   - Max 10 MB per file
 *   - Allowed types: JPEG, PNG, HEIC
 *
 * D9: All file I/O goes through StorageClient — never move_uploaded_file() directly.
 *
 * @method  POST (multipart/form-data)
 * @body    claim_id (required), photo[file] (required), photo_type (optional),
 *          caption (optional)
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 201 { id, claim_id, photo_type, caption, uploaded_at, url }
 *
 * Decisions: D5 (soft delete on claim), D9 (StorageClient), §4.6 (upload limits)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Validate claim exists and is not soft-deleted
// -----------------------------------------------------------------------
$claimId = clean_int($_POST['claim_id'] ?? null);
if (!$claimId) {
    json_error('MISSING_REQUIRED', 'claim_id is required.', 422);
}

$claim = db_row(
    "SELECT id, claim_number FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
    [$claimId]
);
if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Check photo count limit (max 10 per claim)
// -----------------------------------------------------------------------
$photoCount = db_count(
    "SELECT COUNT(*) FROM damage_claim_photos WHERE claim_id = ?",
    [$claimId]
);
if ($photoCount >= 10) {
    json_error('VALIDATION_ERROR', 'Maximum 10 photos per damage claim.', 422);
}

// -----------------------------------------------------------------------
// 3. Validate uploaded file
// -----------------------------------------------------------------------
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errMsg = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size (10 MB).',
        UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
        default                                   => 'File upload failed (error code ' . $uploadError . ').',
    };
    json_error('VALIDATION_ERROR', $errMsg, 422);
}

$tmpPath = $_FILES['photo']['tmp_name'];

// 3a. Size check — 10 MB
if ($_FILES['photo']['size'] > 10 * 1024 * 1024) {
    json_error('VALIDATION_ERROR', 'File must be 10 MB or smaller.', 422);
}

// 3b. Server-side MIME detection — never trust client type (Trap 5)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/heic' => 'heic',
    'image/heif' => 'heic',   // HEIF container — same extension
];

$ext = $allowedMimes[$mimeType] ?? null;
if (!$ext) {
    json_error('VALIDATION_ERROR', 'Unsupported file type. Allowed: JPEG, PNG, HEIC.', 422);
}

// -----------------------------------------------------------------------
// 4. Build safe filename and upload via StorageClient (D9)
//    Path: damage/{claim_id}/{claim_id}_photo_{timestamp}.{ext}
//    (Never keep original filename — security)
// -----------------------------------------------------------------------
$timestamp   = time();
$safeName    = "{$claimId}_photo_{$timestamp}.{$ext}";
$storagePath = "damage/{$claimId}/{$safeName}";

StorageClient::upload($tmpPath, $storagePath);

// -----------------------------------------------------------------------
// 5. Validate photo_type
// -----------------------------------------------------------------------
$photoType      = clean_string($_POST['photo_type'] ?? 'damage');
$validPhotoTypes = ['damage', 'repair_before', 'repair_after', 'other'];
if (!in_array($photoType, $validPhotoTypes, true)) {
    $photoType = 'damage';   // safe default rather than error for optional field
}

$caption = clean_string($_POST['caption'] ?? null);

// -----------------------------------------------------------------------
// 6. Insert photo record + audit
// -----------------------------------------------------------------------
$photoId = null;

db_transaction(function () use ($claimId, $claim, $photoType, $storagePath, $caption, &$photoId) {
    $photoId = db_insert('damage_claim_photos', [
        'claim_id'   => $claimId,
        'photo_type' => $photoType,
        'file_path'  => $storagePath,      // storage key — NOT served to client
        'caption'    => $caption,
        'uploaded_at'=> date('Y-m-d H:i:s'),
    ]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'damage_claim',
        'entity_id'    => $claimId,
        'entity_label' => $claim['claim_number'],
        'notes'        => "Photo uploaded to claim {$claim['claim_number']} (type: {$photoType}).",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Return signed URL — never the raw storage path (Trap 7)
json_success([
    'id'          => $photoId,
    'claim_id'    => $claimId,
    'photo_type'  => $photoType,
    'caption'     => $caption,
    'uploaded_at' => date('Y-m-d H:i:s'),
    'url'         => StorageClient::url($storagePath),
], 201);
