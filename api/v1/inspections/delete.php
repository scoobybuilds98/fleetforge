<?php
declare(strict_types=1);

/**
 * api/v1/inspections/delete.php
 *
 * Block delete if status = complete or signed. Draft/periodic inspections can be deleted.
 *
 * Business rules:
 *   - inspections has no deleted_at column — this is a HARD DELETE.
 *   - Blocks delete if status = 'complete' or 'signed' (INVALID_TRANSITION).
 *   - Cascades handled by DB: inspection_sections and inspection_photos delete on CASCADE.
 *   - damage_claims.inspection_id SET NULL on delete (FK constraint) — claim survives.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('inspections','delete')
 * @returns 200 { deleted: true }
 *
 * Decisions: D7 (routing), §7 (audit log)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'delete');

$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Inspection ID is required.';
}
if ($fields) {
    json_validation_error($fields);
}

$insp = db_row("SELECT id, inspection_number, status FROM inspections WHERE id = ?", [$id]);
if (!$insp) json_error('NOT_FOUND', 'Inspection not found.', 404);

// Block delete of completed or signed inspections — they are permanent records
if (in_array($insp['status'], ['complete', 'signed'], true)) {
    json_error('IMMUTABLE_RECORD',
        'Completed or signed inspections cannot be deleted.', 409,
        ['fields' => ['id' => "Cannot delete a {$insp['status']} inspection."]]);
}

db_transaction(function () use ($id, $insp) {
    db_execute("DELETE FROM inspections WHERE id = ?", [$id]);
    // Cascade: inspection_sections + inspection_photos deleted by FK ON DELETE CASCADE

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'inspections',
        'entity_type'  => 'inspection',
        'entity_id'    => $id,
        'entity_label' => $insp['inspection_number'],
        'old_values'   => json_encode(['status' => $insp['status']]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['deleted' => true]);
