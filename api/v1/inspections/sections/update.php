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

$body        = json_body();
$fieldErrors = [];

$sectionId = clean_int($body['section_id'] ?? null);
if (!$sectionId) {
    $fieldErrors['section_id'] = 'Section ID is required.';
}
if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

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
    json_error('IMMUTABLE_RECORD',
        'Signed inspections cannot be modified.', 422,
        ['fields' => ['section_id' => 'Cannot edit a section on a signed inspection.']]);
}

// ── Build update fields (validate first, accumulate errors, then build SQL)
$sqlFields = [];
$params    = [];

if (array_key_exists('condition', $body)) {
    $condition = clean_string($body['condition'] ?? null);
    $validConds = ['ok', 'fair', 'damaged', 'missing', 'na'];
    if ($condition && !in_array($condition, $validConds, true)) {
        $fieldErrors['condition'] = 'Please select a valid condition (ok, fair, damaged, missing, or na).';
    } else {
        $sqlFields[] = '`condition` = ?';
        $params[]    = $condition;
    }
}

if (array_key_exists('notes', $body)) {
    $sqlFields[] = 'notes = ?';
    $params[]    = clean_string($body['notes'] ?? null, 5000);
}

if (array_key_exists('section_data', $body)) {
    $sectionData = $body['section_data'];
    if ($sectionData !== null) {
        // Validate it's encodable JSON (array or object)
        if (!is_array($sectionData)) {
            $fieldErrors['section_data'] = 'Section data must be an object or null.';
        } else {
            $sqlFields[] = 'section_data = ?';
            $params[]    = json_encode($sectionData);
        }
    } else {
        $sqlFields[] = 'section_data = NULL';
    }
}

if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

if (!$sqlFields) {
    json_validation_error(['_general' => 'No updatable fields provided.'], 'No updatable fields provided.');
}

$params[] = $sectionId;

db_transaction(function () use ($sqlFields, $params, $sectionId, $section) {
    db_execute(
        "UPDATE inspection_sections SET " . implode(', ', $sqlFields) . " WHERE id = ?",
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
