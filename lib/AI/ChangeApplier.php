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
        $payload     = json_decode($proposal['payload'] ?? '[]', true) ?: [];
        $entityType  = (string) ($proposal['entity_type'] ?? '');
        $entry       = \FleetForge\AI\WriteRegistry::get($entityType);
        if ($entry === null) {
            throw new \RuntimeException("Unsupported proposal entity_type: {$entityType}");
        }
        $table       = $entry['table'];
        $auditModule = $entry['audit_module'];
        $softSql     = $entry['soft_delete'] ? ' AND deleted_at IS NULL' : '';
        // WHY: only 4 of the 9 registry tables carry an updated_by column. Writing
        // it to the others (vendors/yards/work orders/damage claims/rate cards)
        // throws 1054 — apply-change.php only catches 23000, so it 500s.
        $hasUpdatedBy = !empty($entry['has_updated_by']);
        // Apply-time allowlist: only columns registered for THIS entity may reach
        // the raw-identifier SELECT/UPDATE below. The validated planners only ever
        // write registry columns, so this is defense-in-depth against a tampered
        // or future-buggy payload reaching the `{$column}` identifier sink.
        $allowedColumns = array_keys($entry['fields'] ?? []);
        $targets     = $payload['targets'] ?? [];

        $appliedDiff = [];

        db_transaction(function () use ($targets, $userId, $userName, $ip, $table, $auditModule, $entityType, $softSql, $hasUpdatedBy, $allowedColumns, &$appliedDiff): void {
            foreach ($targets as $t) {
                $rowId  = (int) ($t['id'] ?? $t['unit_id'] ?? 0); // unit_id for back-compat with S-AI-WRITE-1 rows
                $column = (string) ($t['column'] ?? '');
                $newVal = $t['new_db_value'] ?? null;
                $label  = (string) ($t['label'] ?? $rowId);

                if (!in_array($column, $allowedColumns, true)) {
                    // Fail the whole apply (transaction rolls back) — a non-registry
                    // column signals tampering or a planner bug, never normal input.
                    throw new \RuntimeException("Refusing to apply unregistered column '{$column}' for {$entityType}.");
                }

                // Re-read current value NOW: it is both the audit old_values and
                // the undo before-snapshot. Skip rows deleted between proposal
                // and apply.
                $current = db_row(
                    "SELECT id, `{$column}` AS current_value FROM `{$table}` WHERE id = ?{$softSql}",
                    [$rowId]
                );
                if (!$current) {
                    continue;
                }

                $updateData = [$column => $newVal];
                if ($hasUpdatedBy) {
                    $updateData['updated_by'] = $userId;
                }
                db_update($table, $updateData, 'id = ?', [$rowId]);

                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'update',
                    'module'       => $auditModule,
                    'entity_type'  => $entityType,
                    'entity_id'    => $rowId,
                    'entity_label' => $label,
                    'old_values'   => json_encode([$column => $current['current_value']]),
                    'new_values'   => json_encode([$column => $newVal]),
                    'ip_address'   => $ip,
                ]);

                $appliedDiff[] = [
                    'id'     => $rowId,
                    'label'  => $label,
                    'table'  => $table,
                    'column' => $column,
                    'before' => $current['current_value'],
                    'after'  => $newVal,
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

        $entityType  = (string) ($proposal['entity_type'] ?? '');
        $entry       = \FleetForge\AI\WriteRegistry::get($entityType);
        // Fall back to the table recorded in the diff (older S-AI-WRITE-1 rows
        // used equipment_units and an id/unit_id key).
        $auditModule = $entry['audit_module'] ?? 'equipment';
        // Honor the per-entity updated_by flag (see apply()). Legacy rows with no
        // registered entity targeted equipment_units, which has the column.
        $hasUpdatedBy = $entry !== null ? !empty($entry['has_updated_by']) : true;
        // Apply-time allowlist on undo too: prefer the registry's table/columns
        // over the values stored in the diff (which is the tamperable surface).
        $regTable    = $entry['table'] ?? null;
        $allowedCols = $entry !== null ? array_keys($entry['fields'] ?? []) : null;

        db_transaction(function () use ($diff, $userId, $userName, $ip, $entityType, $auditModule, $hasUpdatedBy, $regTable, $allowedCols): void {
            foreach ($diff as $d) {
                // Re-resolve the table from the registry when known; only fall back
                // to the diff's table for legacy rows whose entity isn't registered.
                $table = $regTable ?? ($d['table'] ?? 'equipment_units');
                $rowId = (int) ($d['id'] ?? $d['unit_id'] ?? 0);
                $label = (string) ($d['label'] ?? $d['unit_number'] ?? $rowId);

                if ($allowedCols !== null && !in_array((string) ($d['column'] ?? ''), $allowedCols, true)) {
                    throw new \RuntimeException("Refusing to undo unregistered column '" . ($d['column'] ?? '') . "' for {$entityType}.");
                }

                $updateData = [$d['column'] => $d['before']];
                if ($hasUpdatedBy) {
                    $updateData['updated_by'] = $userId;
                }
                db_update($table, $updateData, 'id = ?', [$rowId]);

                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'update',
                    'module'       => $auditModule,
                    'entity_type'  => $entityType ?: 'equipment_unit',
                    'entity_id'    => $rowId,
                    'entity_label' => $label,
                    'old_values'   => json_encode([$d['column'] => $d['after']]),
                    'new_values'   => json_encode([$d['column'] => $d['before']]),
                    'ip_address'   => $ip,
                ]);
            }
        });

        return count($diff);
    }
}
