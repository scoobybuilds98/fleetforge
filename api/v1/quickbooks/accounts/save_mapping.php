<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/accounts/save_mapping.php
 *
 * Operator-driven mapping mutations from the Accounts Mapping page.
 * Six actions:
 *
 *   link             — link FF account to QBO account (manual)
 *   unlink           — break a mapped row into single-sided halves
 *   ignore           — mark mapping_status='ignored' (preserves fields)
 *   unignore         — restore natural state from populated sides
 *   mark_critical    — set is_critical=1 (operator override of heuristic)
 *   unmark_critical  — set is_critical=0 (override the auto-heuristic)
 *
 * mark_critical / unmark_critical are INDEPENDENT of mapping_status
 * per D-QBO-8-4 — bridge-account flag can be toggled regardless of
 * whether the account is currently mapped.
 *
 * audit_log: every mutation writes one row with action='update',
 * module='quickbooks', entity_type='qbo_account_map'.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @body    JSON: { action, ff_account_id?, qbo_account_id?, mapping_id?, critical_reason?, notes? }
 * @returns 200 { success: true, mapping_id, status }
 *
 * Spec ref: §7.1
 * Session:  S-QBO-8
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body            = json_body();
$action          = (string) ($body['action'] ?? '');
$ffAcctId        = isset($body['ff_account_id']) && $body['ff_account_id'] !== null && $body['ff_account_id'] !== ''
                     ? (int) $body['ff_account_id']
                     : null;
$qboAcctId       = isset($body['qbo_account_id']) && $body['qbo_account_id'] !== '' && $body['qbo_account_id'] !== null
                     ? (string) $body['qbo_account_id']
                     : null;
$mappingId       = isset($body['mapping_id']) && (int) $body['mapping_id'] > 0
                     ? (int) $body['mapping_id']
                     : null;
$criticalReason  = isset($body['critical_reason']) && trim((string) $body['critical_reason']) !== ''
                     ? trim((string) $body['critical_reason'])
                     : null;
$notes           = isset($body['notes']) ? (string) $body['notes'] : null;

$validActions = ['link', 'unlink', 'ignore', 'unignore', 'mark_critical', 'unmark_critical'];
if (!in_array($action, $validActions, true)) {
    json_error('VALIDATION_ERROR', 'action must be one of: ' . implode(', ', $validActions), 422);
}

switch ($action) {
    case 'link':
        if ($ffAcctId === null || $qboAcctId === null) {
            json_error('VALIDATION_ERROR', 'link requires both ff_account_id and qbo_account_id', 422);
        }
        break;
    case 'unlink':
        if ($mappingId === null && $ffAcctId === null && $qboAcctId === null) {
            json_error('VALIDATION_ERROR', 'unlink requires mapping_id, ff_account_id, or qbo_account_id', 422);
        }
        break;
    case 'ignore':
    case 'unignore':
    case 'mark_critical':
    case 'unmark_critical':
        if ($mappingId === null && $ffAcctId === null) {
            json_error('VALIDATION_ERROR', $action . ' requires mapping_id or ff_account_id', 422);
        }
        break;
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

try {
    $result = db_transaction(function () use (
        $action, $ffAcctId, $qboAcctId, $mappingId, $criticalReason, $notes, $userId, $now
    ): array {

        if ($action === 'link') {
            // Find the qbo_only row carrying the QBO snapshot first
            // (mirror of customer/vendor save_mapping discipline).
            $qboOnly = db_row(
                "SELECT id FROM acc_qbo_account_map
                  WHERE qbo_account_id = ? AND ff_account_id IS NULL",
                [$qboAcctId]
            );
            db_execute(
                "DELETE FROM acc_qbo_account_map
                  WHERE ff_account_id = ? AND qbo_account_id IS NULL",
                [$ffAcctId]
            );
            if ($qboOnly !== null) {
                $id = (int) $qboOnly['id'];
                db_execute(
                    "UPDATE acc_qbo_account_map SET
                        ff_account_id    = ?,
                        mapping_status   = 'mapped',
                        match_confidence = 'manual',
                        match_notes      = ?,
                        last_synced_at   = ?
                      WHERE id = ?",
                    [$ffAcctId, $notes, $now, $id]
                );
            } else {
                $id = db_insert('acc_qbo_account_map', [
                    'ff_account_id'      => $ffAcctId,
                    'qbo_account_id'     => $qboAcctId,
                    'mapping_status'     => 'mapped',
                    'match_confidence'   => 'manual',
                    'match_notes'        => $notes,
                    'last_synced_at'     => $now,
                    'created_by_user_id' => $userId,
                ]);
            }
            return ['id' => $id, 'status' => 'mapped'];
        }

        if ($action === 'unlink') {
            $row = $mappingId !== null
                ? db_row("SELECT * FROM acc_qbo_account_map WHERE id = ?", [$mappingId])
                : ($ffAcctId !== null
                    ? db_row("SELECT * FROM acc_qbo_account_map WHERE ff_account_id = ?", [$ffAcctId])
                    : db_row("SELECT * FROM acc_qbo_account_map WHERE qbo_account_id = ?", [(string) $qboAcctId]));
            if ($row === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }
            $id     = (int) $row['id'];
            $hadFf  = $row['ff_account_id']  !== null;
            $hadQbo = $row['qbo_account_id'] !== null;
            if ($hadFf && $hadQbo) {
                db_execute(
                    "UPDATE acc_qbo_account_map SET
                        ff_account_id    = NULL,
                        mapping_status   = 'qbo_only',
                        match_confidence = NULL,
                        match_notes      = ?
                      WHERE id = ?",
                    [$notes, $id]
                );
                // Re-create FF side as fresh ff_only row.
                db_insert('acc_qbo_account_map', [
                    'ff_account_id'      => (int) $row['ff_account_id'],
                    'mapping_status'     => 'ff_only',
                    'is_critical'        => (int) $row['is_critical'],
                    'critical_reason'    => $row['critical_reason'],
                    'created_by_user_id' => $userId,
                ]);
                return ['id' => $id, 'status' => 'qbo_only'];
            }
            db_execute("DELETE FROM acc_qbo_account_map WHERE id = ?", [$id]);
            return ['id' => $id, 'status' => 'deleted'];
        }

        if ($action === 'ignore') {
            $resolvedId = self_resolve_mapping_id($mappingId, $ffAcctId);
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    mapping_status = 'ignored',
                    match_notes    = ?
                  WHERE id = ?",
                [$notes, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => 'ignored'];
        }

        if ($action === 'unignore') {
            $resolvedId = self_resolve_mapping_id($mappingId, $ffAcctId);
            $row = db_row("SELECT * FROM acc_qbo_account_map WHERE id = ?", [$resolvedId]);
            $hadFf  = $row['ff_account_id']  !== null;
            $hadQbo = $row['qbo_account_id'] !== null;
            $newStatus = match (true) {
                $hadFf && $hadQbo => 'mapped',
                $hadFf            => 'ff_only',
                default           => 'qbo_only',
            };
            db_execute(
                "UPDATE acc_qbo_account_map SET mapping_status = ? WHERE id = ?",
                [$newStatus, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => $newStatus];
        }

        if ($action === 'mark_critical' || $action === 'unmark_critical') {
            $resolvedId = self_resolve_mapping_id($mappingId, $ffAcctId);
            $flag = $action === 'mark_critical' ? 1 : 0;
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    is_critical     = ?,
                    critical_reason = ?
                  WHERE id = ?",
                [$flag, $criticalReason, $resolvedId]
            );
            return ['id' => $resolvedId, 'status' => $action];
        }

        throw new \RuntimeException('UNREACHABLE: unknown action');
    });

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_account_map',
        'entity_id'    => $result['id'],
        'entity_label' => 'AccountMapping #' . $result['id'],
        'notes'        => "Action={$action}"
                          . ($criticalReason !== null ? "; critical_reason={$criticalReason}" : '')
                          . ($notes !== null && $notes !== '' ? "; notes={$notes}" : ''),
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
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $message, 500);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $e->getMessage(), 500);
}

/**
 * Resolve a mapping_id from either an explicit mapping_id OR an
 * ff_account_id. Throws NOT_FOUND inside the transaction body if
 * neither resolves.
 */
function self_resolve_mapping_id(?int $mappingId, ?int $ffAcctId): int
{
    if ($mappingId !== null) {
        $row = db_row("SELECT id FROM acc_qbo_account_map WHERE id = ?", [$mappingId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found');
        }
        return (int) $row['id'];
    }
    if ($ffAcctId !== null) {
        $row = db_row("SELECT id FROM acc_qbo_account_map WHERE ff_account_id = ?", [$ffAcctId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found for ff_account_id');
        }
        return (int) $row['id'];
    }
    throw new \RuntimeException('NOT_FOUND: Could not resolve mapping_id');
}
