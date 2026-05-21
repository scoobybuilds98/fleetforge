<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/items/save_mapping.php
 *
 * Operator-driven mapping mutations from the Items Mapping page.
 * Four actions:
 *
 *   link     — link FF (item_type, variant) to QBO Item (manual)
 *   unlink   — break a mapped row into single-sided halves
 *   ignore   — mark mapping_status='ignored'
 *   unignore — restore natural state from populated sides
 *
 * (No set_override_target equivalent — Items don't have a single
 * override slot like the 'NON' tax code; every FF item_type needs an
 * explicit Item mapping for S-QBO-11 push.)
 *
 * Each linked row carries is_credit_variant (1 only for
 * base_rental_reconciliation_credit per D-QBO-10-1) and
 * presentation_variant (net/gross for GPS variants per D-QBO-10-2);
 * those fields are preserved across link/unlink cycles via the
 * tuple metadata.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @body    JSON: { action, ff_item_type?, ff_item_type_variant?, qbo_item_id?, mapping_id?, notes? }
 * @returns 200 { success: true, mapping_id, status }
 *
 * Spec ref: §7.3
 * Session:  S-QBO-10
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\ItemMatcher;

$body         = json_body();
$action       = (string) ($body['action'] ?? '');
$ffItemType   = isset($body['ff_item_type']) && $body['ff_item_type'] !== ''
                ? (string) $body['ff_item_type']
                : null;
$ffVariant    = isset($body['ff_item_type_variant']) && $body['ff_item_type_variant'] !== ''
                ? (string) $body['ff_item_type_variant']
                : null;
$qboItemId    = isset($body['qbo_item_id']) && $body['qbo_item_id'] !== ''
                ? (string) $body['qbo_item_id']
                : null;
$mappingId    = isset($body['mapping_id']) && (int) $body['mapping_id'] > 0
                ? (int) $body['mapping_id']
                : null;
$notes        = isset($body['notes']) ? (string) $body['notes'] : null;

$validActions = ['link', 'unlink', 'ignore', 'unignore'];
if (!in_array($action, $validActions, true)) {
    json_error('VALIDATION_ERROR', 'action must be one of: ' . implode(', ', $validActions), 422);
}

switch ($action) {
    case 'link':
        if ($ffItemType === null || $qboItemId === null) {
            json_error('VALIDATION_ERROR', 'link requires both ff_item_type and qbo_item_id', 422);
        }
        // Validate that ff_item_type is a real ENUM value via ItemMatcher::ffItemTypes.
        $validTuples = ItemMatcher::ffItemTypes();
        $found = false;
        foreach ($validTuples as $t) {
            if ($t['ff_item_type'] === $ffItemType && (($t['variant'] === $ffVariant) || ($t['variant'] === null && $ffVariant === null))) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            json_error('VALIDATION_ERROR', "Unknown (ff_item_type, variant) tuple: ({$ffItemType}, " . ($ffVariant ?? 'null') . ")", 422);
        }
        break;
    case 'unlink':
        if ($mappingId === null && $ffItemType === null && $qboItemId === null) {
            json_error('VALIDATION_ERROR', 'unlink requires mapping_id, ff_item_type, or qbo_item_id', 422);
        }
        break;
    case 'ignore':
    case 'unignore':
        if ($mappingId === null && $ffItemType === null) {
            json_error('VALIDATION_ERROR', $action . ' requires mapping_id or ff_item_type', 422);
        }
        break;
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

try {
    $result = db_transaction(function () use (
        $action, $ffItemType, $ffVariant, $qboItemId, $mappingId, $notes, $userId, $now
    ): array {

        if ($action === 'link') {
            $isCredit     = ($ffItemType === 'base_rental_reconciliation_credit') ? 1 : 0;
            $presentation = ($ffItemType === 'gps' && $ffVariant !== null) ? $ffVariant : null;

            // Drop the ff_only row to avoid UNIQUE(ff_item_type, ff_item_type_variant) collision.
            db_execute(
                "DELETE FROM acc_qbo_item_map
                  WHERE ff_item_type = ?
                    AND ((ff_item_type_variant IS NULL AND ? IS NULL)
                      OR ff_item_type_variant = ?)
                    AND qbo_item_id IS NULL",
                [$ffItemType, $ffVariant, $ffVariant]
            );

            // Find the qbo_only row.
            $qboOnly = db_row(
                "SELECT id FROM acc_qbo_item_map
                  WHERE qbo_item_id = ? AND ff_item_type IS NULL",
                [$qboItemId]
            );

            if ($qboOnly !== null) {
                $id = (int) $qboOnly['id'];
                db_execute(
                    "UPDATE acc_qbo_item_map SET
                        ff_item_type         = ?,
                        ff_item_type_variant = ?,
                        mapping_status       = 'mapped',
                        match_confidence     = 'manual',
                        is_credit_variant    = ?,
                        presentation_variant = ?,
                        match_notes          = ?,
                        last_synced_at       = ?
                      WHERE id = ?",
                    [$ffItemType, $ffVariant, $isCredit, $presentation, $notes, $now, $id]
                );
            } else {
                $id = db_insert('acc_qbo_item_map', [
                    'ff_item_type'         => $ffItemType,
                    'ff_item_type_variant' => $ffVariant,
                    'qbo_item_id'          => $qboItemId,
                    'mapping_status'       => 'mapped',
                    'match_confidence'     => 'manual',
                    'is_credit_variant'    => $isCredit,
                    'presentation_variant' => $presentation,
                    'match_notes'          => $notes,
                    'last_synced_at'       => $now,
                    'created_by_user_id'   => $userId,
                ]);
            }
            return ['id' => $id, 'status' => 'mapped'];
        }

        if ($action === 'unlink') {
            $row = $mappingId !== null
                ? db_row("SELECT * FROM acc_qbo_item_map WHERE id = ?", [$mappingId])
                : ($ffItemType !== null
                    ? db_row(
                        "SELECT * FROM acc_qbo_item_map
                          WHERE ff_item_type = ?
                            AND ((ff_item_type_variant IS NULL AND ? IS NULL)
                              OR ff_item_type_variant = ?)",
                        [$ffItemType, $ffVariant, $ffVariant]
                      )
                    : db_row("SELECT * FROM acc_qbo_item_map WHERE qbo_item_id = ?", [(string) $qboItemId]));
            if ($row === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }
            $id      = (int) $row['id'];
            $hadFf   = $row['ff_item_type']  !== null;
            $hadQbo  = $row['qbo_item_id']   !== null;

            if ($hadFf && $hadQbo) {
                // Split into qbo_only + ff_only. The qbo_only retains
                // the QBO Item snapshot (it's owned by QBO); the ff_only
                // is a fresh row for the FF tuple. NOTE: is_credit_variant
                // and presentation_variant are FF-side metadata so they
                // travel with the ff_only side, NOT the qbo_only side.
                $ffType    = (string) $row['ff_item_type'];
                $ffVar     = $row['ff_item_type_variant'];
                $isCredit  = (int) ($row['is_credit_variant'] ?? 0);
                $presNew   = $row['presentation_variant'];

                db_execute(
                    "UPDATE acc_qbo_item_map SET
                        ff_item_type         = NULL,
                        ff_item_type_variant = NULL,
                        mapping_status       = 'qbo_only',
                        match_confidence     = NULL,
                        is_credit_variant    = 0,
                        presentation_variant = NULL,
                        match_notes          = ?
                      WHERE id = ?",
                    [$notes, $id]
                );
                db_insert('acc_qbo_item_map', [
                    'ff_item_type'         => $ffType,
                    'ff_item_type_variant' => $ffVar,
                    'mapping_status'       => 'ff_only',
                    'is_credit_variant'    => $isCredit,
                    'presentation_variant' => $presNew,
                    'created_by_user_id'   => $userId,
                ]);
                return ['id' => $id, 'status' => 'qbo_only'];
            }
            db_execute("DELETE FROM acc_qbo_item_map WHERE id = ?", [$id]);
            return ['id' => $id, 'status' => 'deleted'];
        }

        if ($action === 'ignore') {
            $resolvedId = self_resolve_item_mapping_id($mappingId, $ffItemType, $ffVariant);
            db_execute(
                "UPDATE acc_qbo_item_map SET
                    mapping_status = 'ignored',
                    match_notes    = ?
                  WHERE id = ?",
                [$notes, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => 'ignored'];
        }

        // action === 'unignore'
        $resolvedId = self_resolve_item_mapping_id($mappingId, $ffItemType, $ffVariant);
        $row = db_row("SELECT * FROM acc_qbo_item_map WHERE id = ?", [$resolvedId]);
        $hadFf  = $row['ff_item_type'] !== null;
        $hadQbo = $row['qbo_item_id']  !== null;
        $newStatus = match (true) {
            $hadFf && $hadQbo => 'mapped',
            $hadFf            => 'ff_only',
            default           => 'qbo_only',
        };
        db_execute(
            "UPDATE acc_qbo_item_map SET mapping_status = ? WHERE id = ?",
            [$newStatus, $resolvedId]
        );
        return ['id' => $resolvedId, 'status' => $newStatus];
    });

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_item_map',
        'entity_id'    => $result['id'],
        'entity_label' => 'ItemMapping #' . $result['id'],
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
 * (ff_item_type, ff_item_type_variant) tuple. Throws NOT_FOUND on miss.
 */
function self_resolve_item_mapping_id(?int $mappingId, ?string $ffItemType, ?string $ffVariant): int
{
    if ($mappingId !== null) {
        $row = db_row("SELECT id FROM acc_qbo_item_map WHERE id = ?", [$mappingId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found');
        }
        return (int) $row['id'];
    }
    if ($ffItemType !== null) {
        $row = db_row(
            "SELECT id FROM acc_qbo_item_map
              WHERE ff_item_type = ?
                AND ((ff_item_type_variant IS NULL AND ? IS NULL)
                  OR ff_item_type_variant = ?)",
            [$ffItemType, $ffVariant, $ffVariant]
        );
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found for ff_item_type tuple');
        }
        return (int) $row['id'];
    }
    throw new \RuntimeException('NOT_FOUND: Could not resolve mapping_id');
}
