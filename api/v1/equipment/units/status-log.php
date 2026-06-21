<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/status-log.php
 *
 * Returns the status-change history for a single equipment unit, newest
 * first. Backs the "Status History" tab on the equipment unit command
 * center (app/admin/equipment/show.php → loadStatusLog()), which had no
 * reader endpoint and therefore always rendered "No status changes
 * recorded." regardless of real history (audit E01).
 *
 * The `changed_by` display name is COALESCE(live user name, stored
 * varchar): the equipment_status_log.changed_by column already snapshots
 * a name at write time ('system' for implicit lease-driven changes), but
 * we prefer the user's current name when changed_by_user_id resolves so
 * renames stay accurate. The LEFT JOIN keeps system/deleted-user rows.
 *
 * @method  GET
 * @params  unit_id (required, int) — equipment unit ID
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { items: [ {id, old_status, new_status, reason,
 *          changed_by, changed_by_user_id, changed_at} ] }
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §11 Equipment State Machine
 * @audit   CLAUDE_2026-06-20_equipment.md E01 (Status Log tab permanently dead)
 * @session S-EQUIPMENT-AUDIT-FIX-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$unitId = clean_int($_GET['unit_id'] ?? null);
if (!$unitId) {
    json_error('VALIDATION_ERROR', 'unit_id is required.', 422);
}

$rows = db_select(
    "SELECT
        l.id,
        l.equipment_unit_id,
        l.old_status,
        l.new_status,
        l.reason,
        l.changed_by_user_id,
        COALESCE(usr.name, l.changed_by) AS changed_by,
        l.changed_at
       FROM equipment_status_log l
       LEFT JOIN users usr ON usr.id = l.changed_by_user_id
      WHERE l.equipment_unit_id = ?
      ORDER BY l.changed_at DESC, l.id DESC",
    [$unitId]
);

$items = array_map(static function (array $r): array {
    return [
        'id'                 => (int) $r['id'],
        'equipment_unit_id'  => (int) $r['equipment_unit_id'],
        'old_status'         => $r['old_status'],
        'new_status'         => $r['new_status'],
        'reason'             => $r['reason'],
        'changed_by'         => $r['changed_by'],
        'changed_by_user_id' => $r['changed_by_user_id'] !== null ? (int) $r['changed_by_user_id'] : null,
        'changed_at'         => $r['changed_at'],
    ];
}, $rows);

json_success(['items' => $items]);
