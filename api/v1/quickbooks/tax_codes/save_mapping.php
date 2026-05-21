<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/tax_codes/save_mapping.php
 *
 * Operator-driven mapping mutations from the Tax Codes Mapping page.
 * Five actions:
 *
 *   link                  — link FF tax rate to QBO tax code (manual)
 *   unlink                — break a mapped row into single-sided halves
 *   ignore                — mark mapping_status='ignored'
 *   unignore              — restore natural state from populated sides
 *   set_override_target   — set is_override_target=1 on the target row,
 *                            clear any prior target, sync to
 *                            settings.quickbooks.tax_override_code_id
 *
 * set_override_target uses a transactional clear-then-set sequence
 * because UNIQUE(is_override_target) would reject the new 1 if the
 * old 1 isn't cleared first. The transaction guarantees atomicity.
 *
 * D-QBO-9-2 contract: the override target row's qbo_tax_code_id must
 * equal settings.quickbooks.tax_override_code_id at all times. Both
 * are updated in the same transaction.
 *
 * Per S-QBO-8 follow-up F1 lesson: when linking, preserve any
 * existing is_override_target value from either the ff_only or
 * qbo_only source row (operator may have flagged it before linking).
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @body    JSON: { action, ff_tax_rate_id?, qbo_tax_code_id?, mapping_id?, notes? }
 * @returns 200 { success: true, mapping_id, status }
 *
 * Spec ref: §7.2
 * Session:  S-QBO-9
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body        = json_body();
$action      = (string) ($body['action'] ?? '');
$ffRateId    = isset($body['ff_tax_rate_id']) && $body['ff_tax_rate_id'] !== null && $body['ff_tax_rate_id'] !== ''
                ? (int) $body['ff_tax_rate_id']
                : null;
$qboCodeId   = isset($body['qbo_tax_code_id']) && $body['qbo_tax_code_id'] !== '' && $body['qbo_tax_code_id'] !== null
                ? (string) $body['qbo_tax_code_id']
                : null;
$mappingId   = isset($body['mapping_id']) && (int) $body['mapping_id'] > 0
                ? (int) $body['mapping_id']
                : null;
$notes       = isset($body['notes']) ? (string) $body['notes'] : null;

$validActions = ['link', 'unlink', 'ignore', 'unignore', 'set_override_target'];
if (!in_array($action, $validActions, true)) {
    json_error('VALIDATION_ERROR', 'action must be one of: ' . implode(', ', $validActions), 422);
}

switch ($action) {
    case 'link':
        if ($ffRateId === null || $qboCodeId === null) {
            json_error('VALIDATION_ERROR', 'link requires both ff_tax_rate_id and qbo_tax_code_id', 422);
        }
        break;
    case 'unlink':
        if ($mappingId === null && $ffRateId === null && $qboCodeId === null) {
            json_error('VALIDATION_ERROR', 'unlink requires mapping_id, ff_tax_rate_id, or qbo_tax_code_id', 422);
        }
        break;
    case 'ignore':
    case 'unignore':
        if ($mappingId === null && $ffRateId === null) {
            json_error('VALIDATION_ERROR', $action . ' requires mapping_id or ff_tax_rate_id', 422);
        }
        break;
    case 'set_override_target':
        if ($mappingId === null && $qboCodeId === null) {
            json_error('VALIDATION_ERROR', 'set_override_target requires mapping_id or qbo_tax_code_id', 422);
        }
        break;
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

try {
    $result = db_transaction(function () use (
        $action, $ffRateId, $qboCodeId, $mappingId, $notes, $userId, $now
    ): array {

        if ($action === 'link') {
            // Capture FF rate snapshot for divergence detection.
            $ffSnap = db_row(
                "SELECT gst_rate, pst_rate, hst_rate, province FROM tax_rates WHERE id = ?",
                [$ffRateId]
            );
            if ($ffSnap === null) {
                throw new \RuntimeException('NOT_FOUND: FF tax_rate not found');
            }
            $rateSnapshot = (float) $ffSnap['gst_rate'] + (float) $ffSnap['pst_rate'] + (float) $ffSnap['hst_rate'];
            $provinceSnap = (string) ($ffSnap['province'] ?? '');

            // Capture is_override_target from existing rows (could be
            // on either ff_only or qbo_only side — operator may have
            // pre-flagged one before linking).
            $ffOnly = db_row(
                "SELECT is_override_target FROM acc_qbo_tax_code_map
                  WHERE ff_tax_rate_id = ? AND qbo_tax_code_id IS NULL",
                [$ffRateId]
            );
            // Find the qbo_only row carrying the QBO snapshot.
            $qboOnly = db_row(
                "SELECT id, is_override_target FROM acc_qbo_tax_code_map
                  WHERE qbo_tax_code_id = ? AND ff_tax_rate_id IS NULL",
                [$qboCodeId]
            );
            // Inherit-target rule: if either source had is_override_target=1,
            // the linked row keeps that flag (NULL otherwise).
            $inheritTarget = null;
            if ($ffOnly !== null && (int) $ffOnly['is_override_target'] === 1) {
                $inheritTarget = 1;
            } elseif ($qboOnly !== null && (int) $qboOnly['is_override_target'] === 1) {
                $inheritTarget = 1;
            }

            // Drop the ff_only row to avoid UNIQUE(ff_tax_rate_id) collision.
            db_execute(
                "DELETE FROM acc_qbo_tax_code_map
                  WHERE ff_tax_rate_id = ? AND qbo_tax_code_id IS NULL",
                [$ffRateId]
            );

            if ($qboOnly !== null) {
                $id = (int) $qboOnly['id'];
                db_execute(
                    "UPDATE acc_qbo_tax_code_map SET
                        ff_tax_rate_id     = ?,
                        mapping_status     = 'mapped',
                        match_confidence   = 'manual',
                        match_notes        = ?,
                        ff_rate_snapshot   = ?,
                        ff_province        = ?,
                        is_override_target = ?,
                        last_synced_at     = ?
                      WHERE id = ?",
                    [$ffRateId, $notes, $rateSnapshot, $provinceSnap, $inheritTarget, $now, $id]
                );
            } else {
                $id = db_insert('acc_qbo_tax_code_map', [
                    'ff_tax_rate_id'     => $ffRateId,
                    'qbo_tax_code_id'    => $qboCodeId,
                    'mapping_status'     => 'mapped',
                    'match_confidence'   => 'manual',
                    'match_notes'        => $notes,
                    'ff_rate_snapshot'   => $rateSnapshot,
                    'ff_province'        => $provinceSnap,
                    'is_override_target' => $inheritTarget,
                    'last_synced_at'     => $now,
                    'created_by_user_id' => $userId,
                ]);
            }
            return ['id' => $id, 'status' => 'mapped'];
        }

        if ($action === 'unlink') {
            $row = $mappingId !== null
                ? db_row("SELECT * FROM acc_qbo_tax_code_map WHERE id = ?", [$mappingId])
                : ($ffRateId !== null
                    ? db_row("SELECT * FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = ?", [$ffRateId])
                    : db_row("SELECT * FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ?", [(string) $qboCodeId]));
            if ($row === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }
            $id     = (int) $row['id'];
            $hadFf  = $row['ff_tax_rate_id']  !== null;
            $hadQbo = $row['qbo_tax_code_id'] !== null;

            if ($hadFf && $hadQbo) {
                // is_override_target is QBO-side (it's the QBO TaxCode
                // that's the override target — Name='NON'). When
                // unlinking, the flag STAYS on the qbo_only side. The
                // ff_only side gets a fresh row WITHOUT the flag.
                db_execute(
                    "UPDATE acc_qbo_tax_code_map SET
                        ff_tax_rate_id   = NULL,
                        mapping_status   = 'qbo_only',
                        match_confidence = NULL,
                        ff_rate_snapshot = NULL,
                        ff_province      = NULL,
                        match_notes      = ?
                      WHERE id = ?",
                    [$notes, $id]
                );
                db_insert('acc_qbo_tax_code_map', [
                    'ff_tax_rate_id'     => (int) $row['ff_tax_rate_id'],
                    'mapping_status'     => 'ff_only',
                    'created_by_user_id' => $userId,
                ]);
                return ['id' => $id, 'status' => 'qbo_only'];
            }
            db_execute("DELETE FROM acc_qbo_tax_code_map WHERE id = ?", [$id]);
            return ['id' => $id, 'status' => 'deleted'];
        }

        if ($action === 'ignore') {
            $resolvedId = self_resolve_mapping_id($mappingId, $ffRateId);
            db_execute(
                "UPDATE acc_qbo_tax_code_map SET
                    mapping_status = 'ignored',
                    match_notes    = ?
                  WHERE id = ?",
                [$notes, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => 'ignored'];
        }

        if ($action === 'unignore') {
            $resolvedId = self_resolve_mapping_id($mappingId, $ffRateId);
            $row = db_row("SELECT * FROM acc_qbo_tax_code_map WHERE id = ?", [$resolvedId]);
            $hadFf  = $row['ff_tax_rate_id']  !== null;
            $hadQbo = $row['qbo_tax_code_id'] !== null;
            $newStatus = match (true) {
                $hadFf && $hadQbo => 'mapped',
                $hadFf            => 'ff_only',
                default           => 'qbo_only',
            };
            db_execute(
                "UPDATE acc_qbo_tax_code_map SET mapping_status = ? WHERE id = ?",
                [$newStatus, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => $newStatus];
        }

        // action === 'set_override_target'
        // Find the target row by mapping_id OR qbo_tax_code_id.
        $row = $mappingId !== null
            ? db_row("SELECT id, qbo_tax_code_id FROM acc_qbo_tax_code_map WHERE id = ?", [$mappingId])
            : db_row("SELECT id, qbo_tax_code_id FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ?", [(string) $qboCodeId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Target row not found');
        }
        if ($row['qbo_tax_code_id'] === null) {
            throw new \RuntimeException('VALIDATION: set_override_target requires a row with qbo_tax_code_id (the QBO side is what the override targets)');
        }
        $targetId        = (int) $row['id'];
        $targetQboCodeId = (string) $row['qbo_tax_code_id'];

        // Clear prior target then set new (UNIQUE constraint enforces single 1).
        db_execute(
            "UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE is_override_target = 1"
        );
        db_execute(
            "UPDATE acc_qbo_tax_code_map SET is_override_target = 1 WHERE id = ?",
            [$targetId]
        );
        // Sync to settings.quickbooks.tax_override_code_id.
        db_execute(
            "INSERT INTO settings (`key`, `value`, is_public, is_sensitive)
             VALUES ('quickbooks.tax_override_code_id', ?, 0, 0)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$targetQboCodeId]
        );

        return ['id' => $targetId, 'status' => 'override_target_set'];
    });

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_tax_code_map',
        'entity_id'    => $result['id'],
        'entity_label' => 'TaxCodeMapping #' . $result['id'],
        'notes'        => "Action={$action}" . ($notes !== null && $notes !== '' ? "; notes={$notes}" : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'mapping_id' => $result['id'],
        'status'     => $result['status'],
    ]);

} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    if (str_starts_with($message, 'NOT_FOUND:')) {
        json_error('NOT_FOUND', trim(substr($message, 10)), 404);
    }
    if (str_starts_with($message, 'VALIDATION:')) {
        json_error('VALIDATION_ERROR', trim(substr($message, 11)), 422);
    }
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $message, 500);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $e->getMessage(), 500);
}

/**
 * Resolve a mapping_id from either an explicit mapping_id OR an
 * ff_tax_rate_id. Throws NOT_FOUND if neither resolves.
 */
function self_resolve_mapping_id(?int $mappingId, ?int $ffRateId): int
{
    if ($mappingId !== null) {
        $row = db_row("SELECT id FROM acc_qbo_tax_code_map WHERE id = ?", [$mappingId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found');
        }
        return (int) $row['id'];
    }
    if ($ffRateId !== null) {
        $row = db_row("SELECT id FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = ?", [$ffRateId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found for ff_tax_rate_id');
        }
        return (int) $row['id'];
    }
    throw new \RuntimeException('NOT_FOUND: Could not resolve mapping_id');
}
