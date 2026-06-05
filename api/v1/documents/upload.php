<?php
declare(strict_types=1);

/**
 * api/v1/documents/upload.php
 *
 * Upload a document (PDF, JPEG, PNG) for any supported entity.
 * Stores the file via StorageClient and inserts a row into the documents table.
 *
 * Backward-compat syncing:
 *   - equipment_unit + (cvi|registration|insurance) → also updates
 *     equipment_units.{type}_document so the compliance list page continues
 *     to show 📄 icons without any changes.
 *   - lease + (contract|inspection_in|inspection_out) → also updates the
 *     corresponding leases.contract_file / inspection_in_file / inspection_out_file.
 *
 * Security:
 *   - MIME detected server-side via finfo_file() — never trust $_FILES['type'] (Trap 5)
 *   - Extension derived from safe MIME map, never from original filename
 *   - Safe filename: {doc_type}_{timestamp}.{ext}
 *   - Storage path: documents/{entity_type}/{entity_id}/{safe_name}
 *   - file_path never returned in response (Trap 7)
 *
 * @method  POST (multipart/form-data)
 * @body    entity_type, entity_id, document_type, document (file),
 *          title (optional), expiration_date (optional, YYYY-MM-DD),
 *          notes (optional)
 * @auth    Session required; permission: {module}.edit
 * @returns 201 { id, document_type, title, file_name, file_size_kb,
 *                mime_type, expiration_date, uploaded_at, uploaded_by_name, url }
 *
 * Decisions: D5, D9 (StorageClient), Trap 5 (server-side MIME), Trap 7 (no path)
 * Session: S021-DOC (Documents Module)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();

// ── 1. Validate entity_type ───────────────────────────────────────────────────
$allowedTypes = ['equipment_unit', 'lease', 'customer', 'inspection', 'damage_claim'];
$entityType   = clean_string($_POST['entity_type'] ?? '');
if (!in_array($entityType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR',
        'entity_type must be one of: ' . implode(', ', $allowedTypes), 422);
}

$entityId = clean_int($_POST['entity_id'] ?? null);
if (!$entityId) {
    json_error('MISSING_REQUIRED', 'entity_id is required.', 422);
}

// ── 2. Permission gate ────────────────────────────────────────────────────────
$permModule = match ($entityType) {
    'equipment_unit' => 'equipment',
    'lease'          => 'leases',
    'customer'       => 'customers',
    'inspection'     => 'inspections',
    'damage_claim'   => 'maintenance',
    default          => 'equipment',
};
require_permission($permModule, 'edit');

// ── 3. Validate document_type (allowlist per entity type) ─────────────────────
$docTypesByEntity = [
    'equipment_unit' => ['cvi', 'registration', 'insurance', 'other'],
    'lease'          => ['contract', 'inspection_in', 'inspection_out', 'amendment', 'other'],
    'customer'       => ['tax_exemption', 'credit_agreement', 'credit_application', 'other'],
    'inspection'     => ['report', 'other'],
    'damage_claim'   => ['estimate', 'repair_invoice', 'other'],
];
$docType = clean_string($_POST['document_type'] ?? '');
if (!in_array($docType, $docTypesByEntity[$entityType] ?? [], true)) {
    json_error('VALIDATION_ERROR', 'Invalid document_type for this entity type.', 422);
}

// ── 4. Verify entity exists (D5) ─────────────────────────────────────────────
$entityTable = match ($entityType) {
    'equipment_unit' => 'equipment_units',
    'lease'          => 'leases',
    'customer'       => 'customers',
    'inspection'     => 'inspections',
    'damage_claim'   => 'damage_claims',
};
$entity = db_row(
    "SELECT id FROM {$entityTable} WHERE id = ? AND deleted_at IS NULL",
    [$entityId]
);
if (!$entity) {
    json_error('NOT_FOUND', ucwords(str_replace('_', ' ', $entityType)) . ' not found.', 404);
}

// ── 5. Validate uploaded file ─────────────────────────────────────────────────
if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errMsg = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'File exceeds the maximum upload size (20 MB).',
        UPLOAD_ERR_NO_FILE =>
            'No file was uploaded.',
        default =>
            'File upload failed (error code ' . $uploadError . ').',
    };
    json_error('UPLOAD_ERROR', $errMsg, 422);
}

// Max 20 MB
if ($_FILES['document']['size'] > 20 * 1024 * 1024) {
    json_error('UPLOAD_ERROR', 'File exceeds the maximum allowed size of 20 MB.', 422);
}

// MIME detection — never trust the client-supplied Content-Type (Trap 5)
$tmpPath = $_FILES['document']['tmp_name'];
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
$mime    = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
if (!isset($allowedMimes[$mime])) {
    json_error('UPLOAD_ERROR', 'Only PDF, JPEG, and PNG files are accepted.', 422);
}
$ext = $allowedMimes[$mime];

// ── 6. Build safe storage path ────────────────────────────────────────────────
// WHY: use only doc_type + timestamp in filename — never original filename,
// which could contain injection characters or reveal internal paths.
$timestamp   = time();
$safeDocType = preg_replace('/[^a-z0-9_]/', '', $docType);
$safeName    = "{$safeDocType}_{$timestamp}.{$ext}";
$storagePath = "documents/{$entityType}/{$entityId}/{$safeName}";

// ── 7. Upload via StorageClient (D9) ─────────────────────────────────────────
try {
    StorageClient::upload($tmpPath, $storagePath);
} catch (\RuntimeException $e) {
    // WHY: log internally but show a generic message — don't expose storage details
    error_log('StorageClient upload failed: ' . $e->getMessage());
    json_error('UPLOAD_ERROR', 'File storage failed. Please try again.', 500);
}

// ── 8. Build human title ──────────────────────────────────────────────────────
$titleDefaults = [
    'cvi'              => 'CVI Certificate',
    'registration'     => 'Registration Document',
    'insurance'        => 'Insurance Certificate',
    'contract'         => 'Lease Contract',
    'inspection_in'    => 'Pre-Lease Inspection',
    'inspection_out'   => 'Post-Lease Inspection',
    'amendment'        => 'Lease Amendment',
    'tax_exemption'      => 'Tax Exemption Certificate',
    'credit_agreement'   => 'Credit Agreement',
    'credit_application' => 'Credit Application Document',
    'estimate'         => 'Repair Estimate',
    'repair_invoice'   => 'Repair Invoice',
    'report'           => 'Inspection Report',
    'other'            => 'Document',
];
$title      = clean_string($_POST['title'] ?? '') ?: ($titleDefaults[$docType] ?? 'Document');
$notes      = clean_string($_POST['notes'] ?? '') ?: null;
$expiryRaw  = clean_string($_POST['expiration_date'] ?? '');
$expiry     = ($expiryRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryRaw)) ? $expiryRaw : null;

$fileSizeKb = (int) ceil($_FILES['document']['size'] / 1024);
$userId     = current_user()['id'];

// ── 9. Insert into documents table ───────────────────────────────────────────
// WHY db_insert(): returns the new row's ID directly; uploaded_at uses
// the column's DEFAULT CURRENT_TIMESTAMP so we don't need NOW() here.
$docId = db_insert('documents', [
    'entity_type'     => $entityType,
    'entity_id'       => $entityId,
    'document_type'   => $docType,
    'title'           => $title,
    'file_path'       => $storagePath,
    'file_name'       => $safeName,
    'file_size_kb'    => $fileSizeKb,
    'mime_type'       => $mime,
    'expiration_date' => $expiry,
    'notes'           => $notes,
    'uploaded_by'     => $userId,
]);

// ── 10. Sync legacy columns for backward compat ───────────────────────────────
// WHY: compliance list page reads cvi_document / registration_document /
//      insurance_document directly from equipment_units. Keep these in sync so
//      the 📄 icons on the compliance grid reflect the latest upload without
//      requiring a compliance module refactor.
if ($entityType === 'equipment_unit'
    && in_array($docType, ['cvi', 'registration', 'insurance'], true)) {
    $col = $docType . '_document'; // cvi_document | registration_document | insurance_document
    db_execute("UPDATE equipment_units SET {$col} = ? WHERE id = ?", [$storagePath, $entityId]);

    // WHY: if the user supplied an expiry date with the upload, sync it to the compliance
    // column so the compliance grid reflects the new document's expiry immediately —
    // without requiring a separate inline-edit step on the compliance page.
    if ($expiry) {
        $expiryCol = $docType . '_expiry'; // cvi_expiry | registration_expiry | insurance_expiry
        db_execute("UPDATE equipment_units SET {$expiryCol} = ? WHERE id = ?", [$expiry, $entityId]);
    }
}

// WHY: lease show page and other consumers reference these columns directly.
if ($entityType === 'lease'
    && in_array($docType, ['contract', 'inspection_in', 'inspection_out'], true)) {
    $col = match ($docType) {
        'contract'       => 'contract_file',
        'inspection_in'  => 'inspection_in_file',
        'inspection_out' => 'inspection_out_file',
    };
    db_execute("UPDATE leases SET {$col} = ? WHERE id = ?", [$storagePath, $entityId]);
}

// ── 11. Audit log ─────────────────────────────────────────────────────────────
db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => $permModule,
    'entity_type'  => $entityType,
    'entity_id'    => $entityId,
    'entity_label' => $title,
    'notes'        => "Document uploaded: {$safeName} ({$docType})",
    'old_values'   => null,
    'new_values'   => json_encode(['document_type' => $docType, 'file_name' => $safeName, 'title' => $title]),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// ── 12. Return newly created document (Trap 7: signed URL, no file_path) ──────
$doc = db_row(
    "SELECT d.id, d.document_type, d.title, d.file_path, d.file_name,
            d.file_size_kb, d.mime_type, d.expiration_date,
            d.version, d.is_current, d.notes, d.uploaded_at,
            u.name AS uploaded_by_name
       FROM documents d
       LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.id = ?",
    [$docId]
);

$doc['url'] = StorageClient::url($doc['file_path'], 3600);
unset($doc['file_path']); // Trap 7

json_success($doc, 201);
