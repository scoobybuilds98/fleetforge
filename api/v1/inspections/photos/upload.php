<?php
declare(strict_types=1);

/**
 * api/v1/inspections/photos/upload.php
 *
 * Upload a photo to an inspection section via StorageClient.
 *
 * Security (Trap 5):
 *   - MIME type detected server-side via finfo_file() — never trust $_FILES['type']
 *   - Extension derived from MIME map, not from original filename
 *   - Safe filename: {inspection_id}_{section_id|0}_photo_{timestamp}.{ext}
 *   - Storage path: inspections/{inspection_id}/{safe_name}
 *
 * Limits (spec §4.6):
 *   - Max 20 photos per inspection
 *   - Max 10 MB per file
 *   - Allowed types: JPEG, PNG, HEIC
 *
 * D9: All file I/O goes through StorageClient — never move_uploaded_file() directly.
 *
 * @method  POST (multipart/form-data)
 * @body    inspection_id (required), photo[file] (required), section_id (optional),
 *          caption (optional)
 * @auth    Session required; require_permission('inspections','edit')
 * @returns 201 { id, inspection_id, section_id, caption, uploaded_at, url }
 *
 * Decisions: D7 (routing), D9 (StorageClient), Trap 5 (never trust client MIME), Trap 7 (no path exposure)
 * Session: S016
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('inspections', 'edit');

// ── 1. Validate inspection exists and is not signed (terminal)
$inspectionId = clean_int($_POST['inspection_id'] ?? null);
if (!$inspectionId) json_error('MISSING_REQUIRED', 'inspection_id is required.', 422);

$insp = db_row(
    "SELECT id, inspection_number, status FROM inspections WHERE id = ?",
    [$inspectionId]
);
if (!$insp) json_error('NOT_FOUND', 'Inspection not found.', 404);

if ($insp['status'] === 'signed') {
    json_error('IMMUTABLE_RECORD', 'Signed inspections cannot be modified.', 422);
}

// ── 2. Optional section_id — must belong to this inspection if provided
$sectionId = clean_int($_POST['section_id'] ?? null);
if ($sectionId) {
    $sec = db_row(
        "SELECT id FROM inspection_sections WHERE id = ? AND inspection_id = ?",
        [$sectionId, $inspectionId]
    );
    if (!$sec) json_error('NOT_FOUND', 'Section not found on this inspection.', 404);
}

// ── 3. Check photo count limit (max 20 per inspection — spec §4.6)
$photoCount = db_count(
    "SELECT COUNT(*) FROM inspection_photos WHERE inspection_id = ?",
    [$inspectionId]
);
if ($photoCount >= 20) {
    json_error('VALIDATION_ERROR', 'Maximum 20 photos per inspection.', 422);
}

// ── 4. Validate uploaded file
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

// Size check — 10 MB
if ($_FILES['photo']['size'] > 10 * 1024 * 1024) {
    json_error('VALIDATION_ERROR', 'File must be 10 MB or smaller.', 422);
}

// Server-side MIME detection — never trust client type (Trap 5)
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

// ── 5. Build safe filename and upload via StorageClient (D9)
//    Path: inspections/{inspection_id}/{inspection_id}_{section_id|0}_photo_{timestamp}.{ext}
$timestamp   = time();
$secPart     = $sectionId ?? 0;
$safeName    = "{$inspectionId}_{$secPart}_photo_{$timestamp}.{$ext}";
$storagePath = "inspections/{$inspectionId}/{$safeName}";

StorageClient::upload($tmpPath, $storagePath);

$caption = clean_string($_POST['caption'] ?? null);

// ── 6. Insert photo record + audit
$photoId = null;

db_transaction(function () use ($inspectionId, $sectionId, $storagePath, $caption, $insp, &$photoId) {
    $photoId = db_insert('inspection_photos', [
        'inspection_id' => $inspectionId,
        'section_id'    => $sectionId,
        'file_path'     => $storagePath,   // storage key — NOT served to client (Trap 7)
        'caption'       => $caption,
        'sort_order'    => 0,
        'uploaded_at'   => date('Y-m-d H:i:s'),
    ]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'inspections',
        'entity_type'  => 'inspection',
        'entity_id'    => $inspectionId,
        'entity_label' => $insp['inspection_number'],
        'notes'        => "Photo uploaded to inspection {$insp['inspection_number']}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Return signed URL — never the raw storage path (Trap 7)
json_success([
    'id'            => $photoId,
    'inspection_id' => $inspectionId,
    'section_id'    => $sectionId,
    'caption'       => $caption,
    'uploaded_at'   => date('Y-m-d H:i:s'),
    'url'           => StorageClient::url($storagePath),
], 201);
