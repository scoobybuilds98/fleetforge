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
}
