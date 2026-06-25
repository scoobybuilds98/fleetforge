<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/vin_check.php
 *
 * Live VIN-availability lookup for the unit create/edit forms. Lets the UI warn
 * "VIN already in use by unit X" as the operator types — before they submit — and
 * decide whether to move it (S-UNIT-VIN-MOVE).
 *
 * equipment_units.vin is a GLOBAL UNIQUE index spanning soft-deleted rows, so a
 * VIN can be held by a live unit (usually mis-assigned during import) or by a
 * soft-deleted one. This reports the holder either way (preferring a LIVE one).
 *
 * @method  GET
 * @query   vin (required), exclude_id (optional — the unit being edited)
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { taken: bool, unit_id: ?int, unit_number: ?string, deleted: bool }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$vin       = clean_string($_GET['vin'] ?? null, 50);
$excludeId = clean_int($_GET['exclude_id'] ?? null);

if ($vin === null) {
    json_success(['taken' => false, 'unit_id' => null, 'unit_number' => null, 'deleted' => false]);
}

$sql    = "SELECT id, unit_number, deleted_at FROM equipment_units WHERE vin = ?";
$params = [$vin];
if ($excludeId) {
    $sql     .= " AND id != ?";
    $params[] = $excludeId;
}
// Prefer a LIVE holder so the UI points at a unit the operator can act on.
$sql .= " ORDER BY (deleted_at IS NULL) DESC LIMIT 1";

$row = db_row($sql, $params);

json_success([
    'taken'       => $row !== null,
    'unit_id'     => $row ? (int) $row['id'] : null,
    'unit_number' => $row['unit_number'] ?? null,
    'deleted'     => $row ? ($row['deleted_at'] !== null) : false,
]);
