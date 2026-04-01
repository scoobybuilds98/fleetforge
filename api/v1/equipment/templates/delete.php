<?php
declare(strict_types=1);

/**
 * api/v1/equipment/templates/delete.php
 *
 * Soft-deletes an equipment template. BLOCKED if any non-deleted units still
 * reference this template (spec §13 edge case: HAS_ACTIVE_UNITS).
 *
 * @method   POST
 * @body     JSON { id }
 * @required id
 * @auth     Session required; require_permission('equipment','delete')
 * @returns  200 on success
 *           422 HAS_ACTIVE_UNITS if units reference the template
 *           404 NOT_FOUND if already deleted
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.3, §13 edge cases
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}

$template = db_row(
    "SELECT id, name FROM equipment_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

// ── Block if units exist ───────────────────────────────────────
// WHY: deleting a template while units reference it would orphan the FK
// and break all unit profile pages that display template info
$unitCount = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE template_id = ? AND deleted_at IS NULL",
    [$id]
);
if ($unitCount > 0) {
    json_error(
        'HAS_ACTIVE_UNITS',
        "Cannot delete: {$unitCount} unit(s) use this template. Remove or reassign them first.",
        422
    );
}

$userId = current_user_id();

db_transaction(function () use ($id, $userId, $template): void {
    db_execute(
        "UPDATE equipment_templates SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_template',
        'entity_id'    => $id,
        'entity_label' => $template['name'],
        'old_values'   => json_encode(['name' => $template['name']]),
        'new_values'   => null,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
