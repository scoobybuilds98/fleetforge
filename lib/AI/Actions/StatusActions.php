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
