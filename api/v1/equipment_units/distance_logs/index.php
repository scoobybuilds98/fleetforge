<?php
declare(strict_types=1);

/**
 * api/v1/equipment_units/distance_logs/index.php
 *
 * GET — list saved distance logs for an equipment unit, newest first.
 *
 * Query params:
 *   equipment_unit_id  int  required
 *
 * Returns:
 *   { success: true, data: { items: [...] } }
 *
 * Each item:
 *   id, equipment_unit_id, period_start, period_end,
 *   distance (string — bcmath decimal), unit, source,
 *   reading_count, warnings (array), first_reading_at,
 *   last_reading_at, label, queried_at, created_by,
 *   created_at, created_by_name
 *
 * Permission: equipment:view
 *
 * @method   GET
 * @auth     Session required
 * @session  S-UNIT-DISTANCE-SECTION
 * @depends  api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$unitId = clean_int($_GET['equipment_unit_id'] ?? null);
if (!$unitId) {
    json_error('VALIDATION_ERROR', 'equipment_unit_id is required.', 422);
}

$unit = db_row(
    "SELECT id FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

$rows = db_select(
    "SELECT edl.id, edl.equipment_unit_id, edl.period_start, edl.period_end,
            edl.distance, edl.unit, edl.source, edl.reading_count,
            edl.warnings, edl.first_reading_at, edl.last_reading_at,
            edl.label, edl.queried_at, edl.created_by, edl.created_at,
            COALESCE(u.name, 'System') AS created_by_name
       FROM equipment_distance_logs edl
       LEFT JOIN users u ON u.id = edl.created_by
      WHERE edl.equipment_unit_id = ?
      ORDER BY edl.created_at DESC, edl.id DESC
      LIMIT 100",
    [$unitId]
);

// Decode warnings JSON for each row
foreach ($rows as &$row) {
    if (isset($row['warnings']) && is_string($row['warnings'])) {
        $row['warnings'] = json_decode($row['warnings'], true) ?? [];
    }
    // Ensure distance is always a plain string (DECIMAL comes back as string from PDO)
    $row['distance'] = (string) $row['distance'];
}
unset($row);

json_success(['items' => $rows]);
