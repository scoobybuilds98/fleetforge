<?php
declare(strict_types=1);

/**
 * api/v1/equipment_units/distance_logs/delete.php
 *
 * POST — hard-delete a saved distance log row + write audit_log.
 *
 * Body (JSON):
 *   id                 int  required  — equipment_distance_logs.id
 *   equipment_unit_id  int  required  — guard: must match the row's unit
 *
 * Hard delete (not soft-delete) because this is an informational
 * telemetry log, not a financial record (per S-UNIT-DISTANCE-SECTION
 * spec). Audit trail is preserved in audit_log.
 *
 * Returns: { success: true, data: { deleted: true, id: N } }
 *
 * Permission: equipment:edit
 *
 * @method   POST
 * @auth     Session required
 * @session  S-UNIT-DISTANCE-SECTION
 * @depends  api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body   = json_body();
$id     = clean_int($body['id']                ?? null);
$unitId = clean_int($body['equipment_unit_id'] ?? null);

if (!$id) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}
if (!$unitId) {
    json_error('VALIDATION_ERROR', 'equipment_unit_id is required.', 422);
}

// ── Fetch + guard ────────────────────────────────────────────────────
$log = db_row(
    "SELECT id, equipment_unit_id, period_start, period_end, distance, unit, source, label
       FROM equipment_distance_logs
      WHERE id = ? AND equipment_unit_id = ?",
    [$id, $unitId]
);
if (!$log) {
    json_error('NOT_FOUND', 'Distance log not found.', 404);
}

// ── Hard delete ──────────────────────────────────────────────────────
db_execute(
    "DELETE FROM equipment_distance_logs WHERE id = ?",
    [$id]
);

$userId = current_user_id();

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => 'equipment',
    'entity_type'  => 'equipment_distance_log',
    'entity_id'    => $id,
    'entity_label' => "unit #{$unitId} {$log['distance']} {$log['unit']} [{$log['period_start']} — {$log['period_end']}]",
    'notes'        => 'Distance log hard-deleted' . ($log['label'] ? " (label: {$log['label']})" : ''),
    'old_values'   => json_encode([
        'distance'     => $log['distance'],
        'unit'         => $log['unit'],
        'source'       => $log['source'],
        'period_start' => $log['period_start'],
        'period_end'   => $log['period_end'],
        'label'        => $log['label'],
    ]),
    'new_values'   => null,
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true, 'id' => $id]);
