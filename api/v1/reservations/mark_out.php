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
 *                4. Each system-linked unit (guarded — see below)
 *                5. equipment_status_log row per unit whose status changed
 *                6. audit_log entry
 *
 *              Optional lease linkage: if lease_id (int) is provided, it must be
 *              a pending/active lease on one of the reservation's units (or, for
 *              a reservation with no system units, for the same customer). It is
 *              written to reservation_units.lease_id_linked for the matching unit
 *              row(s), and the UI (index + show "Chassis Out" modal) offers the
 *              open leases on the reserved unit(s), pre-selecting the customer's.
 *
 *              Unit status (fixed S-RESERVATION-MARKOUT-LEASE): the old code set
 *              EVERY unit to 'on_lease' (lease given) or 'available' (no lease)
 *              regardless of where it stood, which (a) released units that were
 *              already on another lease, and (b) jumped a pending lease's unit to
 *              'on_lease', after which leases/activate.php (which requires
 *              'reserved') refused to activate. Now:
 *                - the linked lease's unit mirrors that lease: active → on_lease,
 *                  pending → reserved (activation moves it on_lease later);
 *                - any other unit is released reserved → available ONLY when no
 *                  open (pending/active) lease holds it; otherwise untouched.
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
$fields  = [];

$id      = clean_int($body['id'] ?? null);
$leaseId = clean_int($body['lease_id'] ?? null);

if (!$id) {
    $fields['id'] = 'Reservation ID is required.';
    json_validation_error($fields);
}

$result = null;

db_transaction(function () use ($id, $leaseId, &$result) {
    // ── Fetch with FOR UPDATE lock ─────────────────────────────
    $reservation = db_row(
        "SELECT id, status, customer_id, company_name, pickup_date
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
            409,
            ['fields' => ['status' => "Only confirmed reservations can be marked out. This reservation is '{$reservation['status']}'."]]
        );
    }

    // ── Fetch system-linked units (locked) ─────────────────────
    $units = db_select(
        "SELECT ru.id AS ru_id, ru.equipment_unit_id, eu.unit_number, eu.status AS current_status
         FROM reservation_units ru
         JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL
         FOR UPDATE",
        [$id]
    );
    $unitIds = array_map(fn($u) => (int) $u['equipment_unit_id'], $units);

    // ── Validate lease linkage if provided ─────────────────────
    $lease = null;
    if ($leaseId) {
        $lease = db_row(
            "SELECT id, status, customer_id, equipment_unit_id, contract_number
             FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$leaseId]
        );
        if (!$lease) {
            json_validation_error(['lease_id' => "Lease #{$leaseId} not found."]);
        }
        // Only an OPEN lease can take the unit out of the yard.
        if (!in_array($lease['status'], ['pending', 'active'], true)) {
            json_validation_error(['lease_id' =>
                "Lease {$lease['contract_number']} is {$lease['status']} — only a pending or active lease can be linked."]);
        }
        // The lease must actually be for this reservation: its unit is one of the
        // reserved units, or (manual-only reservation, nothing to match by unit)
        // it belongs to the same customer.
        $leaseUnit   = $lease['equipment_unit_id'] !== null ? (int) $lease['equipment_unit_id'] : null;
        $unitMatches = $leaseUnit !== null && in_array($leaseUnit, $unitIds, true);
        $custMatches = !$unitIds
            && $reservation['customer_id'] !== null
            && (int) $reservation['customer_id'] === (int) $lease['customer_id'];
        if (!$unitMatches && !$custMatches) {
            json_validation_error(['lease_id' =>
                "Lease {$lease['contract_number']} is not for a unit on this reservation."]);
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

    // ── Update unit statuses (guarded) + lease linkage ─────────
    foreach ($units as $u) {
        $unitId    = (int) $u['equipment_unit_id'];
        $isLeaseOf = $lease !== null && (int) ($lease['equipment_unit_id'] ?? 0) === $unitId;

        if ($isLeaseOf) {
            // Mirror the linked lease's lifecycle so leases/activate.php still
            // finds a pending lease's unit in 'reserved'.
            $target = $lease['status'] === 'active' ? 'on_lease' : 'reserved';
        } elseif ($u['current_status'] === 'reserved') {
            // Release the reservation's hold — unless an open lease is what is
            // holding the unit (lease create also sets 'reserved').
            $heldByLease = db_count(
                "SELECT COUNT(*) FROM leases
                 WHERE equipment_unit_id = ? AND status IN ('pending','active') AND deleted_at IS NULL",
                [$unitId]
            ) > 0;
            $target = $heldByLease ? 'reserved' : 'available';
        } else {
            // on_lease / maintenance / etc. belong to something else — leave them.
            $target = $u['current_status'];
        }

        if ($target !== $u['current_status']) {
            db_execute(
                "UPDATE equipment_units SET status = ?, updated_at = NOW()
                 WHERE id = ? AND deleted_at IS NULL",
                [$target, $unitId]
            );
            db_insert('equipment_status_log', [
                'equipment_unit_id' => $unitId,
                'changed_by'        => $userId,
                'old_status'        => $u['current_status'],
                'new_status'        => $target,
                'reason'            => "Reservation #{$id} marked out" .
                                       ($isLeaseOf ? " — linked to Lease #{$leaseId}" : ''),
                'changed_at'        => $now,
            ]);
        }

        if ($isLeaseOf) {
            db_execute(
                "UPDATE reservation_units SET lease_id_linked = ? WHERE id = ?",
                [$leaseId, $u['ru_id']]
            );
        }
    }

    // Manual-only reservation (no system units to match) linked by customer:
    // attach the lease to every row so the show page can link to it.
    if ($lease !== null && !$units) {
        db_execute(
            "UPDATE reservation_units SET lease_id_linked = ? WHERE reservation_id = ?",
            [$leaseId, $id]
        );
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
