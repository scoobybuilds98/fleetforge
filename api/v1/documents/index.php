<?php
declare(strict_types=1);

/**
 * api/v1/documents/index.php
 *
 * List all documents for an entity (equipment unit, lease, customer, etc.).
 * Returns documents sorted by document_type ASC then uploaded_at DESC so the
 * most recent version of each type appears first.
 *
 * Trap 7: file_path is NEVER returned — only a short-lived signed URL.
 * D5: deleted_at IS NULL — soft-deleted documents are excluded.
 *
 * @method  GET
 * @query   entity_type  required — equipment_unit | lease | customer |
 *                                  inspection | damage_claim
 * @query   entity_id    required — integer primary key of the entity
 * @auth    Session required; permission scoped to entity's module
 * @returns 200 { items: [ { id, document_type, title, file_name, file_size_kb,
 *                            mime_type, expiration_date, version, is_current,
 *                            notes, uploaded_at, uploaded_by_name, url } ] }
 *
 * Decisions: D5 (soft delete), D9 (StorageClient), Trap 7 (no path exposure)
 * Session: S021-DOC (Documents Module)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();

// ── Validate entity_type ──────────────────────────────────────────────────────
$allowedTypes = ['equipment_unit', 'lease', 'customer', 'inspection', 'damage_claim'];
$entityType   = clean_string($_GET['entity_type'] ?? '');
if (!in_array($entityType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR',
        'entity_type must be one of: ' . implode(', ', $allowedTypes), 422);
}

$entityId = clean_int($_GET['entity_id'] ?? null);
if (!$entityId) {
    json_error('MISSING_REQUIRED', 'entity_id is required.', 422);
}

// ── Permission gate (maps entity type → module) ───────────────────────────────
$permModule = match ($entityType) {
    'equipment_unit' => 'equipment',
    'lease'          => 'leases',
    'customer'       => 'customers',
    'inspection'     => 'inspections',
    'damage_claim'   => 'maintenance',
    default          => 'equipment',
};
require_permission($permModule, 'view');

// ── Fetch documents ───────────────────────────────────────────────────────────
$rows = db_select(
    "SELECT d.id, d.document_type, d.title, d.file_path, d.file_name,
            d.file_size_kb, d.mime_type, d.expiration_date,
            d.version, d.is_current, d.notes, d.uploaded_at,
            u.name AS uploaded_by_name
       FROM documents d
       LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.entity_type = ? AND d.entity_id = ?
        AND d.deleted_at IS NULL
      ORDER BY d.document_type ASC, d.uploaded_at DESC",
    [$entityType, $entityId]
);

// ── Generate signed URLs — Trap 7: never expose raw file_path ────────────────
foreach ($rows as &$row) {
    $row['url'] = $row['file_path']
        ? StorageClient::url($row['file_path'], 3600)
        : null;
    unset($row['file_path']); // WHY: raw storage paths must never reach the client
}
unset($row);

json_success(['items' => $rows]);
