<?php
declare(strict_types=1);

namespace FleetForge\AI\Actions;

/**
 * lib/AI/Actions/StatusActions.php
 *
 * S-AI-ACTION-1 — Lifecycle status-change actions, extracted from their API
 * endpoints so the canonical endpoint AND the AI confirm-then-apply path run
 * the exact same state-machine guards, status-log writes, audit rows, and
 * notifications. No logic duplication, no drift.
 *
 * Each method: validates the transition (throws ActionException on a bad one),
 * does the work inside a db_transaction, and returns a small result array.
 * Auth/permission are the caller's responsibility.
 *
 * @session S-AI-ACTION-1
 */
class StatusActions
{
    /** Equipment state machine (canonical — mirrors update_status.php spec §11). */
    public const UNIT_STATUS_TRANSITIONS = [
        'available'      => ['reserved', 'maintenance', 'inactive'],
        'reserved'       => ['available'],
        'on_lease'       => ['available', 'maintenance'],
        'maintenance'    => ['available', 'inactive', 'decommissioned'],
        'inactive'       => ['available', 'decommissioned'],
        'decommissioned' => [], // TERMINAL
    ];

    /**
     * Change an equipment unit's status with state-machine validation.
     *
     * @throws ActionException  NOT_FOUND / INVALID_TRANSITION
     * @return array{id:int, old_status:string, new_status:string, unit_number:string}
     */
    public static function changeEquipmentStatus(int $id, string $newStatus, ?string $reason, int $userId, string $userName, ?string $ip): array
    {
        $result = null;

        db_transaction(function () use ($id, $newStatus, $reason, $userId, $userName, $ip, &$result): void {
            // D20: FOR UPDATE — lock the unit row
            $unit = db_row(
                "SELECT id, unit_number, status FROM equipment_units
                 WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );
            if (!$unit) {
                throw new ActionException('NOT_FOUND', 'Equipment unit not found.', 404);
            }

            $oldStatus   = $unit['status'];
            $allowedNext = self::UNIT_STATUS_TRANSITIONS[$oldStatus] ?? [];

            // Idempotent same→same
            if ($oldStatus === $newStatus) {
                $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'unit_number' => $unit['unit_number']];
                return;
            }
            if (!in_array($newStatus, $allowedNext, true)) {
                throw new ActionException('INVALID_TRANSITION',
                    "Cannot change unit {$unit['unit_number']} status from '{$oldStatus}' to '{$newStatus}'.", 409);
            }

            // E13: leaving 'on_lease' manually must not orphan a live lease. The
            // legitimate way a unit leaves on_lease is closing its lease (which
            // flips the unit to 'available' itself). If an active lease still
            // references this unit, block the manual transition so the unit can't
            // be moved to available/maintenance — and re-leased — while on lease.
            if ($oldStatus === 'on_lease') {
                $activeLease = db_row(
                    "SELECT id, contract_number FROM leases
                      WHERE equipment_unit_id = ? AND status = 'active' AND deleted_at IS NULL
                      LIMIT 1",
                    [$id]
                );
                if ($activeLease) {
                    throw new ActionException('UNIT_ON_ACTIVE_LEASE',
                        "Cannot change unit {$unit['unit_number']} off 'on_lease': lease {$activeLease['contract_number']} is still active. Close the lease first.", 409);
                }
            }

            if ($newStatus === 'decommissioned') {
                db_execute(
                    "UPDATE equipment_units
                        SET status = ?, decommissioned_date = ?, decommission_reason = ?,
                            updated_by = ?, updated_at = NOW()
                      WHERE id = ?",
                    [$newStatus, date('Y-m-d'), $reason, $userId, $id]
                );
            } else {
                db_execute(
                    "UPDATE equipment_units SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$newStatus, $userId, $id]
                );
            }

            db_insert('equipment_status_log', [
                'equipment_unit_id'  => $id,
                'old_status'         => $oldStatus,
                'new_status'         => $newStatus,
                'reason'             => $reason ?? "Manual status change: {$oldStatus} → {$newStatus}",
                'changed_by'         => $userName,
                'changed_by_user_id' => $userId,
            ]);

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'equipment',
                'entity_type'  => 'equipment_unit',
                'entity_id'    => $id,
                'entity_label' => $unit['unit_number'],
                'notes'        => "Unit {$unit['unit_number']} status changed: {$oldStatus} → {$newStatus}",
                'old_values'   => json_encode(['status' => $oldStatus]),
                'new_values'   => json_encode(['status' => $newStatus, 'reason' => $reason]),
                'ip_address'   => $ip ?? '127.0.0.1',
            ]);

            $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'unit_number' => $unit['unit_number']];

            // In-app notification (NOTIF-1) — non-fatal
            try {
                if ($newStatus === 'decommissioned') {
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'equipment.decommissioned',
                        title: "Unit {$unit['unit_number']} decommissioned",
                        message: "Unit {$unit['unit_number']} has been decommissioned",
                        entityType: 'equipment_unit', entityId: $id,
                        url: '/fleetforge/equipment/show?id=' . $id, severity: 'warning'
                    );
                } else {
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'equipment.status_changed',
                        title: "Unit {$unit['unit_number']} → {$newStatus}",
                        message: "Unit {$unit['unit_number']} status changed to {$newStatus}",
                        entityType: 'equipment_unit', entityId: $id,
                        url: '/fleetforge/equipment/show?id=' . $id
                    );
                }
            } catch (\Throwable $e) {
                error_log('[NOTIF equipment.status_changed] ' . $e->getMessage());
            }
        });

        return $result;
    }

    /** Work-order state machine (canonical — mirrors maintenance_work_orders/update_status.php). */
    public const WO_STATUS_TRANSITIONS = [
        'open'          => ['in_progress', 'cancelled'],
        'in_progress'   => ['waiting_parts', 'completed'],
        'waiting_parts' => ['in_progress'],
        'completed'     => [], // TERMINAL
        'cancelled'     => [], // TERMINAL
    ];

    /**
     * Transition a maintenance work order. On completion: stamps completed_date/
     * _by + optional resolution_notes, and bumps vendors.total_spent +
     * equipment_units.total_maintenance_cost by total_cost (Trap 6, same txn).
     * Mirrors api/v1/maintenance_work_orders/update_status.php.
     *
     * @throws ActionException  NOT_FOUND / INVALID_TRANSITION / VALIDATION_ERROR
     * @return array{id:int, old_status:string, new_status:string, work_order_number:string}
     */
    public static function changeWorkOrderStatus(int $id, string $newStatus, ?string $reason, ?string $resNotes, int $userId, string $userName, ?string $ip): array
    {
        if (!in_array($newStatus, ['open', 'in_progress', 'waiting_parts', 'completed', 'cancelled'], true)) {
            throw new ActionException('VALIDATION_ERROR', 'Please select a valid work-order status.', 422);
        }

        $result = null;

        db_transaction(function () use ($id, $newStatus, $reason, $resNotes, $userId, $userName, $ip, &$result): void {
            $wo = db_row(
                "SELECT id, work_order_number, status, vendor_id, equipment_unit_id, total_cost
                 FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );
            if (!$wo) {
                throw new ActionException('NOT_FOUND', 'Work order not found.', 404);
            }

            $oldStatus   = $wo['status'];
            $allowedNext = self::WO_STATUS_TRANSITIONS[$oldStatus] ?? [];

            if ($oldStatus === $newStatus) {
                $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'work_order_number' => $wo['work_order_number']];
                return;
            }
            if (!in_array($newStatus, $allowedNext, true)) {
                throw new ActionException('INVALID_TRANSITION',
                    "Cannot transition work order {$wo['work_order_number']} from '{$oldStatus}' to '{$newStatus}'.", 409);
            }

            $setClause = 'status = ?';
            $setParams = [$newStatus];
            if ($newStatus === 'completed') {
                $setClause .= ', completed_date = CURDATE(), completed_by = ?';
                $setParams[] = $userId;
                if ($resNotes !== null) {
                    $setClause .= ', resolution_notes = ?';
                    $setParams[] = $resNotes;
                }
            }
            $setParams[] = $id;
            db_execute("UPDATE maintenance_work_orders SET $setClause WHERE id = ?", $setParams);

            if ($newStatus === 'completed') {
                $totalCost = $wo['total_cost'] ?? '0.00';
                if ($wo['vendor_id'] !== null) {
                    db_execute("UPDATE vendors SET total_spent = total_spent + ? WHERE id = ?", [$totalCost, $wo['vendor_id']]);
                }
                if ($wo['equipment_unit_id'] !== null) {
                    db_execute("UPDATE equipment_units SET total_maintenance_cost = total_maintenance_cost + ?, updated_at = NOW() WHERE id = ?", [$totalCost, $wo['equipment_unit_id']]);
                }
            }

            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName, 'action' => 'status_change',
                'module' => 'maintenance', 'entity_type' => 'work_order', 'entity_id' => $id,
                'entity_label' => $wo['work_order_number'],
                'old_values' => json_encode(['status' => $oldStatus]),
                'new_values' => json_encode(['status' => $newStatus, 'reason' => $reason]),
                'notes' => "Work order {$wo['work_order_number']}: {$oldStatus} → {$newStatus}" . ($reason ? " — {$reason}" : ''),
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'work_order_number' => $wo['work_order_number']];

            try {
                if ($newStatus === 'completed') {
                    $cost = '$' . number_format((float) $wo['total_cost'], 2);
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'maintenance.completed', title: "Work order {$wo['work_order_number']} completed",
                        message: "Work order {$wo['work_order_number']} completed — {$cost}",
                        entityType: 'work_order', entityId: $id, url: '/fleetforge/maintenance_work_orders/show?id=' . $id
                    );
                } elseif ($newStatus === 'waiting_parts') {
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'maintenance.waiting_parts', title: "Work order {$wo['work_order_number']} waiting for parts",
                        message: "Work order {$wo['work_order_number']} waiting for parts",
                        entityType: 'work_order', entityId: $id, url: '/fleetforge/maintenance_work_orders/show?id=' . $id, severity: 'warning'
                    );
                }
            } catch (\Throwable $e) {
                error_log('[NOTIF maintenance.status_change] ' . $e->getMessage());
            }
        });

        return $result;
    }

    /**
     * Activate / deactivate a yard. Mirrors api/v1/yards/bulk_{activate,deactivate}.php:
     * role-gated (super_admin/manager); deactivation is blocked while the yard has
     * upcoming pending/confirmed reservations (matched by name snapshot). Yards have
     * no deleted_at — is_active IS the lifecycle flag.
     *
     * @throws ActionException  FORBIDDEN / NOT_FOUND / INVALID_TRANSITION / CONFLICT
     * @return array{id:int, name:string, is_active:bool}
     */
    public static function setYardActive(int $id, bool $active, int $userId, string $userName, string $userRoleSlug, ?string $ip): array
    {
        if (!in_array($userRoleSlug, ['super_admin', 'manager'], true)) {
            throw new ActionException('FORBIDDEN', 'Activating or deactivating yards requires Manager role.', 403);
        }

        $result = null;

        db_transaction(function () use ($id, $active, $userId, $userName, $ip, &$result): void {
            $yard = db_row("SELECT id, name, is_active FROM yards WHERE id = ? FOR UPDATE", [$id]);
            if (!$yard) {
                throw new ActionException('NOT_FOUND', 'Yard not found.', 404);
            }
            if ((bool) (int) $yard['is_active'] === $active) {
                throw new ActionException('INVALID_TRANSITION', "Yard '{$yard['name']}' is already " . ($active ? 'active' : 'inactive') . '.', 409);
            }

            if (!$active) {
                $upcoming = db_count(
                    "SELECT COUNT(*) FROM reservations
                      WHERE yard_location = ? AND pickup_date >= ? AND status IN ('pending','confirmed') AND deleted_at IS NULL",
                    [$yard['name'], date('Y-m-d')]
                );
                if ($upcoming > 0) {
                    throw new ActionException('CONFLICT',
                        "Cannot deactivate '{$yard['name']}' — it has {$upcoming} upcoming reservation(s). Cancel or move those first.", 409);
                }
            }

            db_execute("UPDATE yards SET is_active = ? WHERE id = ?", [$active ? 1 : 0, $id]);

            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName,
                'action' => $active ? 'update' : 'delete', 'module' => 'settings',
                'entity_type' => 'yard', 'entity_id' => $id, 'entity_label' => $yard['name'],
                'notes' => "Yard '{$yard['name']}' " . ($active ? 'activated' : 'deactivated') . ' (AI action)',
                'old_values' => json_encode(['is_active' => (int) $yard['is_active']]),
                'new_values' => json_encode(['is_active' => $active ? 1 : 0]),
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            $result = ['id' => $id, 'name' => $yard['name'], 'is_active' => $active];
        });

        return $result;
    }

    /** Reservation state machine (canonical — mirrors reservations/update_status.php). */
    public const RESERVATION_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['cancelled'],
        'completed' => ['confirmed'], // reverse mark-out (manager only)
        'cancelled' => [],            // TERMINAL
    ];

    /**
     * Change a reservation's status with state-machine validation, unit
     * status transitions, conflict re-check, and the manager-only reversal
     * gate. Mirrors api/v1/reservations/update_status.php.
     *
     * @throws ActionException  NOT_FOUND / INVALID_TRANSITION / FORBIDDEN / VALIDATION_ERROR / CONFLICT
     * @return array{id:int, status:string}
     */
    public static function changeReservationStatus(int $id, string $targetStatus, ?string $cancelReason, int $userId, string $userName, string $userRoleSlug, ?string $ip): array
    {
        if (!in_array($targetStatus, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
            throw new ActionException('VALIDATION_ERROR', 'Please select a valid reservation status.', 422);
        }

        $result = null;

        db_transaction(function () use ($id, $targetStatus, $cancelReason, $userId, $userName, $userRoleSlug, $ip, &$result): void {
            $reservation = db_row(
                "SELECT id, status, company_name, pickup_date
                 FROM reservations WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );
            if (!$reservation) {
                throw new ActionException('NOT_FOUND', 'Reservation not found.', 404);
            }

            $currentStatus = $reservation['status'];
            $allowed = self::RESERVATION_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($targetStatus, $allowed, true)) {
                $allowedText = empty($allowed) ? 'none (terminal state)' : implode(', ', $allowed);
                throw new ActionException('INVALID_TRANSITION',
                    "Cannot transition reservation #{$id} from '{$currentStatus}' to '{$targetStatus}'. Allowed: {$allowedText}.", 409);
            }

            // completed → confirmed is a manager-level reversal
            if ($currentStatus === 'completed' && $targetStatus === 'confirmed'
                && !in_array($userRoleSlug, ['super_admin', 'manager'], true)) {
                throw new ActionException('FORBIDDEN', 'Reversing a completed reservation requires Manager role.', 403);
            }

            if ($targetStatus === 'cancelled' && !$cancelReason) {
                throw new ActionException('VALIDATION_ERROR', 'Please provide a reason for cancellation.', 422);
            }

            $units = db_select(
                "SELECT ru.equipment_unit_id, eu.unit_number, eu.status AS current_status
                 FROM reservation_units ru
                 JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
                 WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
                [$id]
            );

            // Conflict re-check for pending→confirmed under FOR UPDATE (D20).
            if ($currentStatus === 'pending' && $targetStatus === 'confirmed') {
                foreach ($units as $u) {
                    $conflict = db_row(
                        "SELECT r.id, r.company_name, r.pickup_date
                         FROM reservation_units ru JOIN reservations r ON r.id = ru.reservation_id
                         WHERE ru.equipment_unit_id = ? AND r.status IN ('pending','confirmed')
                           AND r.id != ? AND r.deleted_at IS NULL",
                        [$u['equipment_unit_id'], $id]
                    );
                    if ($conflict) {
                        throw new ActionException('CONFLICT',
                            "Unit {$u['unit_number']} is already in an active reservation (Res #{$conflict['id']} — {$conflict['company_name']} — " . date('M j, Y', strtotime($conflict['pickup_date'])) . ").", 409);
                    }
                }
            }

            $unitOldStatus = null;
            $unitNewStatus = null;
            if ($targetStatus === 'confirmed') { $unitOldStatus = 'available'; $unitNewStatus = 'reserved'; }
            elseif ($targetStatus === 'cancelled') { $unitOldStatus = 'reserved'; $unitNewStatus = 'available'; }

            foreach ($units as $u) {
                if (!$unitNewStatus) continue;
                if ($unitOldStatus && $u['current_status'] !== $unitOldStatus) continue; // guard double-transition
                db_execute(
                    "UPDATE equipment_units SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL",
                    [$unitNewStatus, $u['equipment_unit_id']]
                );
                db_insert('equipment_status_log', [
                    'equipment_unit_id' => $u['equipment_unit_id'],
                    'changed_by'        => $userId,
                    'old_status'        => $u['current_status'],
                    'new_status'        => $unitNewStatus,
                    'reason'            => "Reservation #{$id} status changed: {$currentStatus} → {$targetStatus}" . ($cancelReason ? " — {$cancelReason}" : ''),
                    'changed_at'        => date('Y-m-d H:i:s'),
                ]);
            }

            if ($cancelReason && $targetStatus === 'cancelled') {
                db_execute(
                    "UPDATE reservations
                     SET status = ?, updated_by = ?,
                         internal_notes = CONCAT(COALESCE(internal_notes, ''), '\n[Cancelled by ',
                             (SELECT name FROM users WHERE id = ?), ' on ', NOW(), ']: ', ?)
                     WHERE id = ?",
                    [$targetStatus, $userId, $userId, $cancelReason, $id]
                );
            } else {
                db_update('reservations', ['status' => $targetStatus, 'updated_by' => $userId], 'id = ?', [$id]);
            }

            $description = "Reservation #{$id} status changed: {$currentStatus} → {$targetStatus} — {$reservation['company_name']}";
            if ($cancelReason) $description .= " — Reason: {$cancelReason}";
            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName, 'action' => 'status_change',
                'module' => 'reservations', 'entity_type' => 'reservation', 'entity_id' => $id,
                'entity_label' => "#{$id} — {$reservation['company_name']}", 'notes' => $description,
                'old_values' => json_encode(['status' => $currentStatus]),
                'new_values' => json_encode(['status' => $targetStatus, 'cancel_reason' => $cancelReason]),
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            $result = ['id' => $id, 'status' => $targetStatus];

            try {
                if ($targetStatus === 'confirmed') {
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'reservation.confirmed', title: "Reservation #{$id} confirmed",
                        message: "Reservation #{$id} confirmed for {$reservation['company_name']}",
                        entityType: 'reservation', entityId: $id, url: '/fleetforge/reservations/show?id=' . $id
                    );
                } elseif ($targetStatus === 'cancelled') {
                    \FleetForge\Notifications\NotificationService::notify(
                        type: 'reservation.cancelled', title: "Reservation #{$id} cancelled",
                        message: "Reservation #{$id} cancelled" . ($cancelReason ? ": {$cancelReason}" : ''),
                        entityType: 'reservation', entityId: $id, url: '/fleetforge/reservations/show?id=' . $id, severity: 'warning'
                    );
                }
            } catch (\Throwable $e) {
                error_log('[NOTIF reservation.status_change] ' . $e->getMessage());
            }
        });

        return $result;
    }
}
