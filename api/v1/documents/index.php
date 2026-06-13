<?php
declare(strict_types=1);

/**
 * api/v1/documents/index.php
 *
 * Two modes depending on whether entity_id is supplied:
 *
 * MODE A — entity-specific list (entity_type + entity_id both provided)
 *   Returns all non-deleted documents for the given entity, sorted by
 *   document_type ASC then uploaded_at DESC so the most recent version of
 *   each type appears first. Used by equipment/show, leases/show,
 *   customers/show Documents tabs.
 *
 * MODE B — global paginated list (entity_id absent)
 *   Returns a paginated list of all documents across all entities.
 *   Supports optional filters: entity_type, text search (title / file_name).
 *   Joins customers / equipment_units / leases to produce a human-readable
 *   entity_label for each row. Used by app/admin/documents/index.php.
 *
 * Security:
 *   Trap 7: file_path is NEVER returned — only a short-lived signed URL.
 *   D5: deleted_at IS NULL — soft-deleted documents are excluded.
 *
 * @method  GET
 * @query   entity_type  (Mode A: required) equipment_unit | lease | customer |
 *                       inspection | damage_claim
 *                       (Mode B: optional filter)
 * @query   entity_id    (Mode A: required integer)
 * @query   q            (Mode B: optional text search on title + file_name)
 * @query   sort         (Mode B: uploaded_at | title | document_type | file_size_kb |
 *                       expiration_date — default uploaded_at)
 * @query   dir          (Mode B: ASC | DESC — default DESC)
 * @query   page         (Mode B: default 1)
 * @query   per_page     (Mode B: 10–100, default 25)
 * @auth    Session required; Mode A scopes permission to entity's module
 * @returns Mode A: 200 { items: [ { id, document_type, title, file_name,
 *                       file_size_kb, mime_type, expiration_date, version,
 *                       is_current, notes, uploaded_at, uploaded_by_name, url } ] }
 *          Mode B: 200 paginated { items: [ ... + entity_type, entity_id,
 *                       entity_label ] }
 *
 * Decisions: D5 (soft delete), D9 (StorageClient), Trap 7 (no path exposure)
 * Session: S022 (Documents Module)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();

$allowedTypes = ['equipment_unit', 'lease', 'customer', 'inspection', 'damage_claim'];
$entityType   = clean_string($_GET['entity_type'] ?? '');
$entityId     = clean_int($_GET['entity_id'] ?? null);

// ════════════════════════════════════════════════════════════════════════════
// MODE A — entity-specific list
// ════════════════════════════════════════════════════════════════════════════
if ($entityId !== null) {

    if (!in_array($entityType, $allowedTypes, true)) {
        json_error('VALIDATION_ERROR',
            'entity_type must be one of: ' . implode(', ', $allowedTypes), 422);
    }

    // ── Permission gate (maps entity type → module) ───────────────────────
    // C1: shared map (document_entity_module) so Mode A, Mode B and serve.php
    // all gate on the same per-entity permission.
    require_permission(document_entity_module($entityType), 'view');

    // ── Fetch documents ───────────────────────────────────────────────────
    $rows = db_select(
        "SELECT d.id, d.document_type, d.title, d.file_path, d.file_name,
                d.file_size_kb, d.mime_type, d.expiration_date,
                d.version, d.is_current, d.notes, d.uploaded_at,
                u.name AS uploaded_by_name
           FROM documents d
           LEFT JOIN users u ON u.id = d.uploaded_by AND u.deleted_at IS NULL
          WHERE d.entity_type = ? AND d.entity_id = ?
            AND d.deleted_at IS NULL
          ORDER BY d.document_type ASC, d.uploaded_at DESC",
        [$entityType, $entityId]
    );

    // ── Generate signed URLs — Trap 7: never expose raw file_path ─────────
    foreach ($rows as &$row) {
        $row['url'] = $row['file_path']
            ? StorageClient::url($row['file_path'], 3600)
            : null;
        unset($row['file_path']); // WHY: raw storage paths must never reach the client
    }
    unset($row);

    json_success(['items' => $rows]);
}

// ════════════════════════════════════════════════════════════════════════════
// MODE B — global paginated list
// ════════════════════════════════════════════════════════════════════════════
// No entity_id provided — list all documents with optional entity_type filter.
//
// C1 (access-scope): the global list is visible to any authenticated user, so
// it MUST be scoped to the entity types the caller can actually view — using
// the same per-entity permission Mode A enforces. Without this, a user whose
// access to a module was revoked (per-user/role override) could still list and
// download every document of that type. super_admin sees all (can() wins).

$where  = ['d.deleted_at IS NULL'];
$params = [];

// C1: restrict the result set to entity types the caller may view. Checking
// every documents.entity_type enum member keeps the scope correct even for
// types Mode A's $allowedTypes doesn't list (contract / service_request).
$documentEntityTypes = [
    'customer', 'equipment_unit', 'lease', 'inspection',
    'damage_claim', 'contract', 'service_request',
];
$viewableTypes = array_values(array_filter(
    $documentEntityTypes,
    static fn(string $t): bool => can_view_document($t)
));
if ($viewableTypes === []) {
    // Caller can view no document-bearing module → empty list (not a leak, not a 500).
    json_paginated([], 0, $page ?? 1, $perPage ?? 25);
}
$where[]  = 'd.entity_type IN (' . implode(',', array_fill(0, count($viewableTypes), '?')) . ')';
foreach ($viewableTypes as $t) { $params[] = $t; }

// Optional entity_type filter
if ($entityType && in_array($entityType, $allowedTypes, true)) {
    $where[]  = 'd.entity_type = ?';
    $params[] = $entityType;
}

// Optional text search — LIKE on title and file_name
// WHY: no FULLTEXT index on documents; LIKE is acceptable given typical low volume
if ($q = clean_string($_GET['q'] ?? null)) {
    $where[]  = '(d.title LIKE ? OR d.file_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$allowedSorts = ['uploaded_at', 'title', 'document_type', 'file_size_kb', 'expiration_date'];
$sort    = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'uploaded_at';
$dir     = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
$page    = max(1, clean_int($_GET['page']     ?? 1)  ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

$total = db_count("SELECT COUNT(*) FROM documents d WHERE $whereSQL", $params);

// WHY: LEFT JOINs on entity tables with entity_type guard so each join only
// fires for its own type — avoids cross-entity ID collisions.
// Inspections + damage_claims fall back to the CONCAT default (no join needed
// since they lack a short human-readable identifier column).
$rows = db_select(
    "SELECT d.id, d.entity_type, d.entity_id,
            d.document_type, d.title, d.file_path, d.file_name,
            d.file_size_kb, d.mime_type, d.expiration_date,
            d.version, d.is_current, d.notes, d.uploaded_at,
            u.name AS uploaded_by_name,
            COALESCE(
                c.company_name,
                eu.unit_number,
                l.contract_number,
                CONCAT(d.entity_type, ' #', d.entity_id)
            ) AS entity_label
       FROM documents d
       LEFT JOIN users u
              ON u.id = d.uploaded_by AND u.deleted_at IS NULL
       LEFT JOIN customers c
              ON d.entity_type = 'customer'
             AND d.entity_id = c.id
             AND c.deleted_at IS NULL
       LEFT JOIN equipment_units eu
              ON d.entity_type = 'equipment_unit'
             AND d.entity_id = eu.id
             AND eu.deleted_at IS NULL
       LEFT JOIN leases l
              ON d.entity_type = 'lease'
             AND d.entity_id = l.id
             AND l.deleted_at IS NULL
      WHERE $whereSQL
      ORDER BY d.`$sort` $dir
      LIMIT $perPage OFFSET $offset",
    $params
);

// ── Generate signed URLs — Trap 7: never expose raw file_path ─────────────
foreach ($rows as &$row) {
    $row['url'] = $row['file_path']
        ? StorageClient::url($row['file_path'], 3600)
        : null;
    unset($row['file_path']); // WHY: raw storage paths must never reach the client
}
unset($row);

json_paginated($rows, $total, $page, $perPage);
