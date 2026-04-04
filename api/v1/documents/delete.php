<?php
declare(strict_types=1);

/**
 * api/v1/documents/delete.php
 *
 * Soft-delete a document. Clears the matching legacy column on equipment_units
 * or leases if it still points to this file, so compliance icons and lease
 * document links accurately reflect reality after deletion.
 *
 * NOTE: the actual file in storage is NOT removed — documents may be referenced
 * by audit trails or other records. Physical deletion is a separate admin task.
 *
 * @method  POST
 * @body    JSON { id: int }
 * @auth    Session required; permission: {module}.edit (scoped to entity type)
 * @returns 200 { deleted: true }
 *
 * D5: soft delete (sets deleted_at = NOW()).
 * Session: S021-DOC (Documents Module)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$body  = json_body();
$docId = clean_int($body['id'] ?? null);
if (!$docId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// Load document first to determine entity type and permission scope
$doc = db_row(
    "SELECT id, entity_type, entity_id, document_type, file_path
       FROM documents
      WHERE id = ? AND deleted_at IS NULL",
    [$docId]
);
if (!$doc) {
    json_error('NOT_FOUND', 'Document not found.', 404);
}

// Permission gate: edit permission on the entity's module
$permModule = match ($doc['entity_type']) {
    'equipment_unit' => 'equipment',
    'lease'          => 'leases',
    'customer'       => 'customers',
    'inspection'     => 'inspections',
    'damage_claim'   => 'maintenance',
    default          => 'equipment',
};
require_permission($permModule, 'edit');

// Soft-delete the document row
db_execute("UPDATE documents SET deleted_at = NOW() WHERE id = ?", [$docId]);

// ── Sync legacy columns ───────────────────────────────────────────────────────
// WHY: clear the legacy column only when it still points to this specific file —
//      a more recent upload may have already overwritten it, and we must not
//      inadvertently blank out the newer reference.

if ($doc['entity_type'] === 'equipment_unit'
    && in_array($doc['document_type'], ['cvi', 'registration', 'insurance'], true)) {
    $col  = $doc['document_type'] . '_document';
    $unit = db_row(
        "SELECT {$col} AS current_path FROM equipment_units WHERE id = ?",
        [$doc['entity_id']]
    );
    if ($unit && $unit['current_path'] === $doc['file_path']) {
        db_execute("UPDATE equipment_units SET {$col} = NULL WHERE id = ?", [$doc['entity_id']]);
    }
}

if ($doc['entity_type'] === 'lease'
    && in_array($doc['document_type'], ['contract', 'inspection_in', 'inspection_out'], true)) {
    $col   = match ($doc['document_type']) {
        'contract'       => 'contract_file',
        'inspection_in'  => 'inspection_in_file',
        'inspection_out' => 'inspection_out_file',
    };
    $lease = db_row(
        "SELECT {$col} AS current_path FROM leases WHERE id = ?",
        [$doc['entity_id']]
    );
    if ($lease && $lease['current_path'] === $doc['file_path']) {
        db_execute("UPDATE leases SET {$col} = NULL WHERE id = ?", [$doc['entity_id']]);
    }
}

$userId   = current_user_id();
$fileName = basename($doc['file_path'] ?? '');
db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => $permModule,
    'entity_type'  => $doc['entity_type'],
    'entity_id'    => $doc['entity_id'],
    'entity_label' => $fileName,
    'notes'        => "Document removed: {$fileName} ({$doc['document_type']})",
    'old_values'   => json_encode(['document_type' => $doc['document_type'], 'file_name' => $fileName]),
    'new_values'   => null,
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true]);
