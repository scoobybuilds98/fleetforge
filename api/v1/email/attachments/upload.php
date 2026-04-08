<?php
declare(strict_types=1);

/**
 * api/v1/email/attachments/upload.php
 *
 * POST (multipart/form-data) → upload a file to be used as an
 * email attachment.
 *
 * Files go to storage/uploads/email_attachments/ via StorageClient.
 * Returns the storage path + name + size + type so the compose
 * modal can chip it without committing to send yet.
 *
 * Trap 5: server-detected MIME from file content, not client-supplied.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'create');

use FleetForge\Storage\StorageClient;

if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('VALIDATION_ERROR', 'No file uploaded or upload failed.', 422);
}

$tmp = $_FILES['file']['tmp_name'];
$origName = (string)$_FILES['file']['name'];
$size = (int)$_FILES['file']['size'];

// Reject anything bigger than 25 MB — keeps SMTP-safe.
if ($size > 25 * 1024 * 1024) {
    json_error('VALIDATION_ERROR', 'Attachment exceeds the 25 MB limit.', 422);
}

// Trap 5 — server-detected MIME
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$type  = $finfo ? finfo_file($finfo, $tmp) : null;
if ($finfo) finfo_close($finfo);
if (!$type) {
    json_error('INVALID_FILE_TYPE', 'Could not detect file type.', 422);
}

$extMap = [
    'application/pdf'                                                            => 'pdf',
    'image/jpeg'                                                                 => 'jpg',
    'image/png'                                                                  => 'png',
    'image/gif'                                                                  => 'gif',
    'application/msword'                                                         => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
    'application/vnd.ms-excel'                                                   => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
    'text/plain'                                                                 => 'txt',
    'text/csv'                                                                   => 'csv',
];
$ext = $extMap[$type] ?? null;
if (!$ext) {
    json_error('INVALID_FILE_TYPE', 'Unsupported file type: ' . $type, 422);
}

// Safe filename — never trust the original
$safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME));
$safeBase = substr($safeBase ?: 'attachment', 0, 80);
$key      = 'uploads/email_attachments/' . current_user_id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;

try {
    $path = StorageClient::upload($tmp, $key);
} catch (Throwable $e) {
    error_log('[email/attachments/upload] StorageClient failed: ' . $e->getMessage());
    json_error('UPLOAD_FAILED', 'Could not save uploaded file.', 500);
}

json_success([
    'upload_path' => $path,
    'name'        => $origName,
    'size'        => $size,
    'type'        => $type,
], 201);
