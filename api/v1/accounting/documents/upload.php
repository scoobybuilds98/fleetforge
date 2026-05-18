<?php
declare(strict_types=1);

/**
 * api/v1/accounting/documents/upload.php
 *
 * Upload a document attached to an accounting entity (bill, JE, AP payment,
 * bank transaction, fixed asset, tax filing). Distinct from the
 * non-accounting api/v1/documents/upload.php because acc_documents has a
 * different ENUM domain + no soft-delete + no per-entity-type document_type
 * subdivision (free-text title only).
 *
 * Security:
 *   - MIME detected server-side via finfo_file() — never trust $_FILES['type'] (Trap 5)
 *   - Extension derived from MIME map, never from original filename
 *   - file_path NEVER returned in response — signed URL only (Trap 7)
 *
 * @method  POST (multipart/form-data)
 * @body    entity_type, entity_id, title (required), notes (optional),
 *          file (uploaded file)
 * @auth    Session required; require_permission('journal_entries', 'view')
 * @returns 201 { id, entity_type, entity_id, title, file_name, file_size_kb,
 *                mime_type, notes, uploaded_at, uploaded_by_name, signed_url }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3
 * Session:  S-ACCT-FIX-DOCS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'view');

// ── 1. Validate entity_type ──────────────────────────────────────────────────
// Mirrors the acc_documents.entity_type ENUM. 'reconciliation' and 'other'
// are accepted but no entity-existence check fires for them (free-form).
$allowedTypes = [
    'journal_entry', 'bill', 'ap_payment', 'bank_transaction',
    'asset', 'tax_filing', 'reconciliation', 'other',
];
$entityType = clean_string($_POST['entity_type'] ?? '');
if (!in_array($entityType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR',
        'entity_type must be one of: ' . implode(', ', $allowedTypes), 422);
}

$entityId = clean_int($_POST['entity_id'] ?? null);
if (!$entityId) {
    json_error('MISSING_REQUIRED', 'entity_id is required.', 422);
}

$title = clean_string($_POST['title'] ?? '');
if (!$title) {
    json_error('MISSING_REQUIRED', 'title is required.', 422);
}
if (strlen($title) > 255) {
    $title = substr($title, 0, 255);
}
$notes = clean_string($_POST['notes'] ?? '') ?: null;

// ── 2. Verify entity exists (for ENUM values that map to a table) ────────────
$entityTable = match ($entityType) {
    'journal_entry'    => 'acc_journal_entries',
    'bill'             => 'acc_bills',
    'ap_payment'       => 'acc_ap_payments',
    'bank_transaction' => 'acc_bank_transactions',
    'asset'            => 'acc_fixed_assets',
    'tax_filing'       => 'acc_tax_filing_periods',
    default            => null, // reconciliation, other — no table check
};
if ($entityTable !== null) {
    $exists = db_row("SELECT id FROM {$entityTable} WHERE id = ?", [$entityId]);
    if (!$exists) {
        json_error('NOT_FOUND',
            ucwords(str_replace('_', ' ', $entityType)) . ' not found.', 404);
    }
}

// ── 3. Validate the uploaded file ────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the maximum upload size (20 MB).',
        UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
        default                                    => 'File upload failed (error code ' . $err . ').',
    };
    json_error('UPLOAD_ERROR', $msg, 422);
}

if ($_FILES['file']['size'] > 20 * 1024 * 1024) {
    json_error('UPLOAD_ERROR', 'File exceeds the maximum allowed size of 20 MB.', 422);
}

// ── 4. MIME validation via finfo (Trap 5) ────────────────────────────────────
$tmpPath = $_FILES['file']['tmp_name'];
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
$mime    = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedMimes = [
    'application/pdf'                                                           => 'pdf',
    'image/jpeg'                                                                => 'jpg',
    'image/png'                                                                 => 'png',
    'image/tiff'                                                                => 'tif',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
];
if (!isset($allowedMimes[$mime])) {
    json_error('UPLOAD_ERROR',
        'Only PDF, JPEG, PNG, TIFF, DOCX, and XLSX files are accepted.', 422);
}
$ext = $allowedMimes[$mime];

// ── 5. Build safe storage path ───────────────────────────────────────────────
// WHY: timestamp-based filename — original filename could contain path
// traversal or injection characters. The original name is preserved
// separately in acc_documents.file_name for display.
$timestamp   = time();
$rand        = bin2hex(random_bytes(4));
$safeName    = "{$timestamp}_{$rand}.{$ext}";
$storagePath = "acc_documents/{$entityType}/{$entityId}/{$safeName}";

// ── 6. Upload via StorageClient ──────────────────────────────────────────────
try {
    StorageClient::upload($tmpPath, $storagePath);
} catch (\RuntimeException $e) {
    error_log('StorageClient upload failed: ' . $e->getMessage());
    json_error('UPLOAD_ERROR', 'File storage failed. Please try again.', 500);
}

// ── 7. Insert acc_documents row ──────────────────────────────────────────────
$origFileName = $_FILES['file']['name'] ?? $safeName;
if (strlen($origFileName) > 255) {
    $origFileName = substr($origFileName, 0, 255);
}
$fileSizeKb = (int) ceil($_FILES['file']['size'] / 1024);
$userId     = current_user_id();

$docId = db_insert('acc_documents', [
    'entity_type'  => $entityType,
    'entity_id'    => $entityId,
    'title'        => $title,
    'file_path'    => $storagePath,
    'file_name'    => $origFileName,
    'file_size_kb' => $fileSizeKb,
    'mime_type'    => $mime,
    'notes'        => $notes,
    'uploaded_by'  => $userId,
]);

// ── 8. Audit log ─────────────────────────────────────────────────────────────
db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'accounting',
    'entity_type'  => 'acc_document',
    'entity_id'    => $docId,
    'entity_label' => $title,
    'notes'        => "Document uploaded for {$entityType} #{$entityId}: {$title}",
    'new_values'   => json_encode([
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'file_name'   => $origFileName,
        'mime_type'   => $mime,
    ]),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// ── 9. Return the new row with signed URL (Trap 7 — no file_path) ────────────
$doc = db_row(
    "SELECT d.id, d.entity_type, d.entity_id, d.title, d.file_name,
            d.file_size_kb, d.mime_type, d.notes, d.uploaded_at,
            u.name AS uploaded_by_name, d.file_path
       FROM acc_documents d
       LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.id = ?",
    [$docId]
);

$doc['signed_url'] = StorageClient::url($doc['file_path'], 3600);
unset($doc['file_path']);

json_success($doc, 201);
