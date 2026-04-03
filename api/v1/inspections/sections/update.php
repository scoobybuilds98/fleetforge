<?php
declare(strict_types=1);

/**
 * api/v1/inspections/sections/update.php
 *
 * Update a single inspection section: condition, notes, and/or section_data JSON.
 *
 * Business rules:
 *   - Requires section_id.
 *   - No optimistic lock — inspection_sections has no updated_at column.
 *   - Blocks update if parent inspection status = 'signed' (terminal).
 *   - For Tires section: section_data contains JSON {positions:{...}} tire table.
 *   - For Trailer Condition section: section_data contains JSON {mud_flaps:{...}, ...} checklist.
 *   - For standard exterior/interior sections: section_data is null, only condition + notes used.
 *   - section_data is merged (PATCH semantics) — only keys present in the request are updated.
 *     Send full section_data to replace entirely.
 *
 * @method  POST
 * @body    JSON: section_id (required), condition?, notes?, section_data?
 * @auth    Session required; require_permission('inspections','edit')
 * @returns 200 { section_id, condition, notes, section_data }
 *
 * Decisions: D7 (routing), §7 (audit log)
 * Session: S016
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'edit');

$body = json_body();

$sectionId = clean_int($body['section_id'] ?? null);
if (!$sectionId) json_error('MISSING_REQUIRED', 'section_id is required.', 422);

// ── Fetch section and parent inspection in one query
$section = db_row(
    "SELECT s.id, s.inspection_id, s.section_name, s.`condition`, s.notes, s.section_data,
            i.status AS inspection_status, i.inspection_number
     FROM inspection_sections s
     JOIN inspections i ON i.id = s.inspection_id
     WHERE s.id = ?",
    [$sectionId]
);
if (!$section) json_error('NOT_FOUND', 'Inspection section not found.', 404);

// Signed inspections are terminal — no further edits allowed
if ($section['inspection_status'] === 'signed') {
    json_error('IMMUTABLE_RECORD', 'Signed inspections cannot be modified.', 422);
}

// ── Build update fields
$fields  = [];
$params  = [];

if (array_key_exists('condition', $body)) {
    $condition = clean_string($body['condition'] ?? null);
    $validConds = ['ok', 'fair', 'damaged', 'missing', 'na'];
    if ($condition && !in_array($condition, $validConds, true)) {
        json_error('VALIDATION_ERROR', 'Invalid condition value.', 422,
            ['fields' => ['condition' => 'Must be ok, fair, damaged, missing, or na.']]);
    }
    $fields[]  = '`condition` = ?';
    $params[]  = $condition;
}

if (array_key_exists('notes', $body)) {
    $fields[]  = 'notes = ?';
    $params[]  = clean_string($body['notes'] ?? null, 5000);
}

if (array_key_exists('section_data', $body)) {
    $sectionData = $body['section_data'];
    if ($sectionData !== null) {
        // Validate it's encodable JSON (array or object)
        if (!is_array($sectionData)) {
            json_error('VALIDATION_ERROR', 'section_data must be an object or null.', 422);
        }
        $fields[]  = 'section_data = ?';
        $params[]  = json_encode($sectionData);
    } else {
        $fields[]  = 'section_data = NULL';
    }
}

if (!$fields) json_error('VALIDATION_ERROR', 'No updatable fields provided.', 422);

$params[] = $sectionId;

db_transaction(function () use ($fields, $params, $sectionId, $section) {
    db_execute(
        "UPDATE inspection_sections SET " . implode(', ', $fields) . " WHERE id = ?",
        $params
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'inspections',
        'entity_type'  => 'inspection_section',
        'entity_id'    => $sectionId,
        'entity_label' => $section['inspection_number'] . ' / ' . $section['section_name'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Return updated section
$updated = db_row(
    "SELECT id, inspection_id, section_name, `condition`, notes, section_data, sort_order
     FROM inspection_sections WHERE id = ?",
    [$sectionId]
);
if ($updated['section_data'] !== null) {
    $updated['section_data'] = json_decode($updated['section_data'], true);
}

json_success($updated);
