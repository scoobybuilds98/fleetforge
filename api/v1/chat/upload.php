<?php
/**
 * api/v1/chat/upload.php
 *
 * POST (multipart/form-data) file=<file>
 * Accepts file uploads for chat attachments. Max 10MB.
 * Allowed: PDF, JPG, PNG, DOCX, XLSX.
 * Stores via StorageClient. Returns file metadata.
 *
 * WHY: Files are uploaded first, then attached to messages.
 *      Server-side MIME detection only — never trust client MIME type (Trap 5).
 *
 * Dependencies: api/bootstrap.php, lib/Storage/StorageClient.php
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('POST');
require_auth_api();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $msg     = match($errCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large. Maximum 10MB.',
        UPLOAD_ERR_NO_FILE => 'No file uploaded.',
        default            => 'File upload failed.',
    };
    json_error('VALIDATION_ERROR', $msg, 422);
}

$file    = $_FILES['file'];
$maxSize = 10 * 1024 * 1024; // 10MB

if ($file['size'] > $maxSize) {
    json_error('VALIDATION_ERROR', 'File must be under 10MB.', 422);
}

// Server-side MIME detection (Trap 5 — never trust client)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = [
    'application/pdf'      => 'pdf',
    'image/jpeg'           => 'jpg',
    'image/png'            => 'png',
    'image/gif'            => 'gif',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-excel'                                          => 'xls',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/msword'   => 'doc',
];

if (!array_key_exists($mimeType, $allowedMimes)) {
    json_error('VALIDATION_ERROR', 'Unsupported file type. Allowed: PDF, JPG, PNG, DOCX, XLSX.', 422);
}

$ext      = $allowedMimes[$mimeType];
$userId   = current_user_id();
$safeName = 'chat_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

require_once dirname(__DIR__, 2) . '/../lib/Storage/StorageClient.php';
try {
    $path = \FleetForge\Storage\StorageClient::upload(
        $file['tmp_name'],
        'chat/' . $safeName,
        $mimeType
    );
} catch (Throwable $e) {
    error_log('[CHAT UPLOAD] ' . $e->getMessage());
    json_error('SERVER_ERROR', 'File storage failed. Please try again.', 500);
}

$attachType = str_starts_with($mimeType, 'image/') ? 'image' : 'file';

json_success([
    'file_name'       => $file['name'],
    'file_size'       => $file['size'],
    'mime_type'       => $mimeType,
    'attachment_type' => $attachType,
    'preview_title'   => $file['name'],
    'preview_subtitle'=> round($file['size'] / 1024, 1) . ' KB',
    'preview_badge'   => $ext,
    'preview_badge_class' => 'badge-neutral',
    // WHY: Never return file_path to client — use download endpoint instead
]);
