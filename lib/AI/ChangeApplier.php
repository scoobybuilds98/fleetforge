<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/ChangeApplier.php
 *
 * S-AI-WRITE-1 — Executes / reverts AI change proposals.
 *
 * This is the WRITE half of the propose→confirm→apply pipeline. The AI
 * (via plan_* tools in FleetForgeTools) only ever PERSISTS a pending
 * proposal in ai_pending_changes. Nothing mutates the target row until
 * the user confirms, at which point api/v1/ai/apply-change.php calls
 * apply() here.
 *
 * Kept as a thin service (not inline in the endpoint) so the write logic
 * is unit-testable without an HTTP request body — the endpoint becomes a
 * pure auth + IO wrapper, and tests/_smoke_ai_write_equipment.php drives
 * these methods directly against the real schema.
 *
 * Design notes:
 *   - Mirrors the manual Edit Unit write path (api/v1/equipment/units/
 *     update.php): db_transaction + audit_log row per changed unit.
 *   - Last-write-wins — D19 optimistic locking is disabled app-wide, so we
 *     do NOT block on updated_at; we re-read the current value at apply
 *     time both for the audit old_values and the undo before-snapshot.
 *   - Permission + auth are the CALLER's responsibility (the endpoint
 *     checks ai:view + equipment:edit). These methods assume that gate
 *     already passed.
 *
 * @depends includes/db.php (db_row, db_update, db_insert, db_transaction)
 * @session S-AI-WRITE-1
 */
class ChangeApplier
{
    /**
     * Apply a pending proposal. Writes each target row, logs an audit
     * entry per row, and returns the before/after diff (which the caller
     * persists to ai_pending_changes.applied_diff for undo).
     *
     * @param  array       $proposal  Row from ai_pending_changes (status must be pending).
     * @param  int         $userId    User confirming the change (→ updated_by, audit user_id).
     * @param  string      $userName  Display name for the audit row.
     * @param  string|null $ip        Request IP for the audit row.
     * @return array{diff: array, count: int}
     * @throws \RuntimeException  If the proposal type is unsupported.
     * @throws \PDOException      On a unique-constraint collision (caller maps to 409).
     */
    public static function apply(array $proposal, int $userId, string $userName, ?string $ip): array
    {
        if (($proposal['entity_type'] ?? '') !== 'equipment_unit') {
            throw new \RuntimeException('Unsupported proposal entity_type: ' . ($proposal['entity_type'] ?? '(none)'));
        }

        $payload = json_decode($proposal['payload'] ?? '[]', true) ?: [];
        $targets = $payload['targets'] ?? [];

        $appliedDiff = [];

        db_transaction(function () use ($targets, $userId, $userName, $ip, &$appliedDiff): void {
            foreach ($targets as $t) {
                $unitId = (int) ($t['unit_id'] ?? 0);
                $column = (string) ($t['column'] ?? '');
                $newVal = $t['new_db_value'] ?? null;

                // Re-read current value NOW: it is both the audit old_values and
                // the undo before-snapshot. Skip rows that were deleted between
                // proposal and apply.
                $current = db_row(
                    "SELECT id, unit_number, `{$column}` AS current_value
                       FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
                    [$unitId]
                );
                if (!$current) {
                    continue;
                }

                db_update('equipment_units', [
                    $column      => $newVal,
                    'updated_by' => $userId,
                ], 'id = ?', [$unitId]);

                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'update',
                    'module'       => 'equipment',
                    'entity_type'  => 'equipment_unit',
                    'entity_id'    => $unitId,
                    'entity_label' => $current['unit_number'],
                    'old_values'   => json_encode([$column => $current['current_value']]),
                    'new_values'   => json_encode([$column => $newVal]),
                    'ip_address'   => $ip,
                ]);

                $appliedDiff[] = [
                    'unit_id'     => $unitId,
                    'unit_number' => $current['unit_number'],
                    'column'      => $column,
                    'before'      => $current['current_value'],
                    'after'       => $newVal,
                ];
            }
        });

        return ['diff' => $appliedDiff, 'count' => count($appliedDiff)];
    }

    /**
     * Revert a previously-applied proposal using its stored before-snapshot.
     *
     * @param  array       $proposal  Row from ai_pending_changes (status must be applied).
     * @param  int         $userId
     * @param  string      $userName
     * @param  string|null $ip
     * @return int  Number of rows reverted.
     * @throws \RuntimeException  If no before-snapshot was recorded.
     */
    public static function undo(array $proposal, int $userId, string $userName, ?string $ip): int
    {
        $diff = json_decode($proposal['applied_diff'] ?? '[]', true) ?: [];
        if (!$diff) {
            throw new \RuntimeException('No before-snapshot recorded; cannot undo.');
        }

        db_transaction(function () use ($diff, $userId, $userName, $ip): void {
            foreach ($diff as $d) {
                db_update('equipment_units', [
                    $d['column'] => $d['before'],
                    'updated_by' => $userId,
                ], 'id = ?', [$d['unit_id']]);

                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'update',
                    'module'       => 'equipment',
                    'entity_type'  => 'equipment_unit',
                    'entity_id'    => $d['unit_id'],
                    'entity_label' => $d['unit_number'] ?? (string) $d['unit_id'],
                    'old_values'   => json_encode([$d['column'] => $d['after']]),
                    'new_values'   => json_encode([$d['column'] => $d['before']]),
                    'ip_address'   => $ip,
                ]);
            }
        });

        return count($diff);
    }
}
