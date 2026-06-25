<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/move_vin.php
 *
 * Atomically MOVE a VIN from whatever unit currently holds it onto a target unit
 * (S-UNIT-VIN-MOVE). Resolves the common post-import mess where a VIN landed on
 * the wrong unit: the operator can reassign it in one click instead of manually
 * clearing the other unit first (which the GLOBAL UNIQUE index otherwise forces).
 *
 * Order matters: clear the VIN off the current holder FIRST, then set it on the
 * target — inside one transaction — so the unique index never collides mid-run.
 * The previous holder is left VIN-less (the operator sets its correct VIN next).
 *
 * @method  POST
 * @body    { vin (required), to_unit_id (required) }
 * @auth    Session required; require_permission('equipment','edit')
 * @returns 200 { to_unit_id, to_unit_number, moved_from_unit_id, moved_from_unit_number }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body     = json_body();
$vin      = clean_string($body['vin'] ?? null, 50);
$toUnitId = clean_int($body['to_unit_id'] ?? null);

if ($vin === null || !$toUnitId) {
    json_error('VALIDATION_ERROR', 'vin and to_unit_id are required.', 422,
        ['fields' => ['vin' => $vin === null ? 'VIN is required.' : null]]);
}

// Target unit must exist and be live.
$to = db_row("SELECT id, unit_number, vin FROM equipment_units WHERE id = ? AND deleted_at IS NULL", [$toUnitId]);
if (!$to) {
    json_error('NOT_FOUND', 'Target unit not found.', 404);
}

$result = ['to_unit_id' => $toUnitId, 'to_unit_number' => $to['unit_number'],
           'moved_from_unit_id' => null, 'moved_from_unit_number' => null];

db_transaction(function () use ($vin, $toUnitId, $to, &$result) {
    $uid = current_user_id();

    // Lock the current holder (live preferred), excluding the target itself.
    $from = db_row(
        "SELECT id, unit_number, deleted_at FROM equipment_units
          WHERE vin = ? AND id != ? AND deleted_at IS NULL
          ORDER BY id LIMIT 1 FOR UPDATE",
        [$vin, $toUnitId]
    );

    // Clear the VIN off the old holder FIRST so the target UPDATE can't 1062.
    if ($from) {
        db_execute(
            "UPDATE equipment_units SET vin = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?",
            [$uid, (int) $from['id']]
        );
        $result['moved_from_unit_id']     = (int) $from['id'];
        $result['moved_from_unit_number'] = $from['unit_number'];

        db_insert('audit_log', [
            'user_id'      => $uid,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'equipment',
            'entity_type'  => 'equipment_unit',
            'entity_id'    => (int) $from['id'],
            'entity_label' => $from['unit_number'],
            'notes'        => "S-UNIT-VIN-MOVE: VIN {$vin} moved from {$from['unit_number']} to {$to['unit_number']}.",
            'old_values'   => json_encode(['vin' => $vin]),
            'new_values'   => json_encode(['vin' => null]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }

    // Set the VIN on the target.
    db_execute(
        "UPDATE equipment_units SET vin = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
        [$vin, $uid, $toUnitId]
    );
    db_insert('audit_log', [
        'user_id'      => $uid,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $toUnitId,
        'entity_label' => $to['unit_number'],
        'notes'        => "S-UNIT-VIN-MOVE: VIN {$vin} assigned to {$to['unit_number']}"
                        . ($result['moved_from_unit_number'] ? " (moved from {$result['moved_from_unit_number']})." : '.'),
        'old_values'   => json_encode(['vin' => $to['vin']]),
        'new_values'   => json_encode(['vin' => $vin]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success($result);
