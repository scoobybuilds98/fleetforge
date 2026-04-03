<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Mark Out API
 *
 * @file        api/v1/reservations/mark_out.php
 * @description Marks a confirmed reservation as completed (unit physically
 *              checked out). This is the "Chassis Out" action in the UI.
 *
 *              Transition: confirmed → completed
 *
 *              On mark-out:
 *                1. reservations.status → 'completed'
 *                2. reservations.marked_out_at → NOW()
 *                3. reservations.marked_out_by → current_user_id()
 *                4. Each system-linked unit: status 'reserved' → 'on_lease'
 *                   (or 'available' if no lease_id_linked)
 *                5. equipment_status_log row per unit
 *                6. audit_log entry
 *
 *              Optional lease linkage: if lease_id (int) is provided in the
 *              request body, that lease_id is written to
 *              reservation_units.lease_id_linked for each unit in the
 *              reservation, creating a soft association to the lease.
 *
 * @method      POST
 * @body        JSON — id (req), lease_id (optional — link reservation to a lease)
 * @auth        Session required; require_permission('reservations','edit')
 * @returns     200 { id, status, marked_out_at } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D20 (FOR UPDATE), spec §6 reservation + equipment state machines
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'edit');

$body    = json_body();
$id      = clean_int($body['id'] ?? null);
$leaseId = clean_int($body['lease_id'] ?? null);

if (!$id) json_error('MISSING_REQUIRED', 'id is required.', 422);

$result = null;

db_transaction(function () use ($id, $leaseId, &$result) {
    // ── Fetch with FOR UPDATE lock ─────────────────────────────
    $reservation = db_row(
        "SELECT id, status, company_name, pickup_date
         FROM reservations WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );
    if (!$reservation) {
        json_error('NOT_FOUND', 'Reservation not found.', 404);
    }

    // ── Only confirmed reservations can be marked out ──────────
    if ($reservation['status'] !== 'confirmed') {
        json_error('INVALID_TRANSITION',
            "Reservation #{$id} is '{$reservation['status']}'. Only confirmed reservations can be marked out.",
            409
        );
    }

    // ── Validate lease linkage if provided ─────────────────────
    if ($leaseId) {
        $lease = db_row(
            "SELECT id, status FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$leaseId]
        );
        if (!$lease) {
            json_error('NOT_FOUND', "Lease #{$leaseId} not found.", 404);
        }
    }

    $now    = date('Y-m-d H:i:s');
    $userId = current_user_id();

    // ── Update reservation ─────────────────────────────────────
    db_execute(
        "UPDATE reservations
         SET status = 'completed', marked_out_at = ?, marked_out_by = ?, updated_by = ?
         WHERE id = ? AND deleted_at IS NULL",
        [$now, $userId, $userId, $id]
    );

    // ── Fetch system-linked units and update their status ──────
    $units = db_select(
        "SELECT ru.id AS ru_id, ru.equipment_unit_id, eu.unit_number, eu.status AS current_status
         FROM reservation_units ru
         JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
        [$id]
    );

    // Unit goes to 'on_lease' if linked to a lease, otherwise 'available'
    $unitTargetStatus = $leaseId ? 'on_lease' : 'available';

    foreach ($units as $u) {
        // Update unit status
        db_execute(
            "UPDATE equipment_units SET status = ?, updated_at = NOW()
             WHERE id = ? AND deleted_at IS NULL",
            [$unitTargetStatus, $u['equipment_unit_id']]
        );
        db_insert('equipment_status_log', [
            'equipment_unit_id' => $u['equipment_unit_id'],
            'changed_by'        => $userId,
            'old_status'        => $u['current_status'],
            'new_status'        => $unitTargetStatus,
            'reason'            => "Reservation #{$id} marked out" .
                                   ($leaseId ? " — linked to Lease #{$leaseId}" : ''),
            'changed_at'        => $now,
        ]);

        // Link to lease if provided
        if ($leaseId) {
            db_execute(
                "UPDATE reservation_units SET lease_id_linked = ? WHERE id = ?",
                [$leaseId, $u['ru_id']]
            );
        }
    }

    // ── Audit log ──────────────────────────────────────────────
    $desc = "Reservation #{$id} marked out — {$reservation['company_name']} (pickup: {$reservation['pickup_date']})";
    if ($leaseId) $desc .= " — linked to Lease #{$leaseId}";

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'reservations',
        'entity_type'  => 'reservation',
        'entity_id'    => $id,
        'entity_label' => "#{$id} — {$reservation['company_name']}",
        'notes'        => $desc,
        'old_values'   => json_encode(['status' => 'confirmed']),
        'new_values'   => json_encode([
            'status'        => 'completed',
            'marked_out_at' => $now,
            'lease_id'      => $leaseId,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'            => $id,
        'status'        => 'completed',
        'marked_out_at' => $now,
    ];
});

json_success($result);
