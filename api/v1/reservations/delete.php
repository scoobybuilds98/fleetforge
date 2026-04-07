<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Delete API
 *
 * @file        api/v1/reservations/delete.php
 * @description Soft-deletes a reservation (sets deleted_at = NOW()).
 *              Only pending or cancelled reservations can be deleted.
 *              Confirmed/completed reservations must be cancelled first via
 *              update_status.php — this prevents accidental deletion of
 *              reservations that have been acknowledged or fulfilled.
 *
 *              On delete: any system-linked units in 'reserved' status are
 *              reverted to 'available' and an equipment_status_log row is written.
 *
 * @method      POST
 * @body        JSON — id (required)
 * @auth        Session required; require_permission('reservations','delete')
 * @returns     200 { id } | 422 IMMUTABLE_RECORD | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D5 (soft-delete), D20 (FOR UPDATE on unit revert)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'delete');

$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Reservation ID is required.';
    json_validation_error($fields);
}

db_transaction(function () use ($id) {
    // ── Fetch with lock ────────────────────────────────────────
    $reservation = db_row(
        "SELECT id, status, company_name, pickup_date
         FROM reservations WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );
    if (!$reservation) {
        json_error('NOT_FOUND', 'Reservation not found.', 404);
    }

    // ── Guard: only pending or cancelled can be hard-deleted ───
    if (!in_array($reservation['status'], ['pending', 'cancelled'])) {
        json_error('IMMUTABLE_RECORD',
            "Reservation #{$id} is '{$reservation['status']}'. Cancel it first before deleting.", 422,
            ['fields' => ['id' => "Cannot delete a {$reservation['status']} reservation. Cancel it first."]]);
    }

    // ── Release any 'reserved' units back to 'available' ──────
    $units = db_select(
        "SELECT ru.equipment_unit_id, eu.status, eu.unit_number
         FROM reservation_units ru
         JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
        [$id]
    );
    foreach ($units as $u) {
        if ($u['status'] === 'reserved') {
            db_execute(
                "UPDATE equipment_units SET status = 'available', updated_at = NOW()
                 WHERE id = ? AND deleted_at IS NULL",
                [$u['equipment_unit_id']]
            );
            db_insert('equipment_status_log', [
                'equipment_unit_id' => $u['equipment_unit_id'],
                'changed_by'        => current_user_id(),
                'old_status'        => 'reserved',
                'new_status'        => 'available',
                'reason'            => "Reservation #{$id} deleted — unit released",
                'changed_at'        => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ── Soft delete ────────────────────────────────────────────
    db_execute(
        "UPDATE reservations SET deleted_at = NOW(), updated_by = ?
         WHERE id = ? AND deleted_at IS NULL",
        [current_user_id(), $id]
    );

    // ── Audit log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'reservations',
        'entity_type'  => 'reservation',
        'entity_id'    => $id,
        'entity_label' => "#{$id} — {$reservation['company_name']}",
        'notes'        => "Reservation #{$id} deleted — {$reservation['company_name']} (pickup: {$reservation['pickup_date']})",
        'old_values'   => json_encode($reservation),
        'new_values'   => null,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
