<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/sync_queue_clear.php
 *
 * Housekeeping endpoint for the Sync Queue admin page. Three scopes:
 *
 *   scope='completed'  → DELETE WHERE status='completed'
 *                          AND completed_at < NOW() - INTERVAL 7 DAY
 *                        (D-QBO-4-1: 7-day retention for completed rows.
 *                         Spec §6.4 does not define auto-cleanup; 7 days
 *                         balances forensic visibility with bloat control.)
 *
 *   scope='failed'     → DELETE WHERE status='failed'
 *                          AND completed_at < NOW() - INTERVAL 30 DAY
 *                        (D-QBO-4-2: 30-day retention for failed rows.
 *                         Longer than completed so operators have a
 *                         working window for forensics before bulk-clear.)
 *
 *   scope='selected'   → DELETE WHERE id IN (...) AND status IN ('completed','failed','skipped')
 *                        Operator-explicit deletion. Refuses to delete
 *                        queued/processing rows (those need retry or
 *                        the worker to finish them first).
 *
 * Audit-log policy (volume-aware): for ≤10 rows, one audit_log row
 * per deletion (full provenance). For >10 rows, one summary audit_log
 * row capturing scope + count + timestamp (avoids audit_log bloat).
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'clear_queue')
 * @body    JSON: { scope: 'completed'|'failed'|'selected', ids?: int[] }
 * @returns 200 { deleted_count: int, scope: string }
 *
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'clear_queue');

$body  = json_body();
$scope = isset($body['scope']) ? (string) $body['scope'] : '';
$ids   = [];
if (isset($body['ids']) && is_array($body['ids'])) {
    foreach ($body['ids'] as $i) {
        if ((int) $i > 0) {
            $ids[] = (int) $i;
        }
    }
    $ids = array_values(array_unique($ids));
}

if (!in_array($scope, ['completed', 'failed', 'selected'], true)) {
    json_validation_error(['scope' => "Must be 'completed', 'failed', or 'selected'."]);
}
if ($scope === 'selected' && $ids === []) {
    json_validation_error(['ids' => 'Provide ids array when scope=selected.']);
}

try {
    $user      = current_user();
    $deleted   = 0;
    $auditRows = [];

    db_transaction(function () use ($scope, $ids, &$deleted, &$auditRows): void {
        if ($scope === 'completed') {
            // Capture IDs about to be deleted so we can audit them.
            $rows = db_select(
                "SELECT id, entity_type, entity_id, operation
                   FROM acc_qbo_sync_queue
                  WHERE status = 'completed'
                    AND completed_at IS NOT NULL
                    AND completed_at < NOW() - INTERVAL 7 DAY",
                []
            );
            foreach ($rows as $r) {
                $auditRows[] = $r;
            }
            $deleted = (int) db_execute(
                "DELETE FROM acc_qbo_sync_queue
                  WHERE status = 'completed'
                    AND completed_at IS NOT NULL
                    AND completed_at < NOW() - INTERVAL 7 DAY",
                []
            );
        } elseif ($scope === 'failed') {
            $rows = db_select(
                "SELECT id, entity_type, entity_id, operation
                   FROM acc_qbo_sync_queue
                  WHERE status = 'failed'
                    AND completed_at IS NOT NULL
                    AND completed_at < NOW() - INTERVAL 30 DAY",
                []
            );
            foreach ($rows as $r) {
                $auditRows[] = $r;
            }
            $deleted = (int) db_execute(
                "DELETE FROM acc_qbo_sync_queue
                  WHERE status = 'failed'
                    AND completed_at IS NOT NULL
                    AND completed_at < NOW() - INTERVAL 30 DAY",
                []
            );
        } else { // 'selected'
            // Filter ids to those that are actually deletable (NEVER drop
            // queued/processing — operator must retry/cancel first).
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = db_select(
                "SELECT id, entity_type, entity_id, operation, status
                   FROM acc_qbo_sync_queue
                  WHERE id IN ({$placeholders})
                    AND status IN ('completed','failed','skipped')",
                $ids
            );
            $deletableIds = array_map(static fn($r) => (int) $r['id'], $rows);
            foreach ($rows as $r) {
                $auditRows[] = $r;
            }
            if ($deletableIds !== []) {
                $delPlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
                $deleted = (int) db_execute(
                    "DELETE FROM acc_qbo_sync_queue WHERE id IN ({$delPlaceholders})",
                    $deletableIds
                );
            }
        }
    });

    // Audit-log writes — volume-aware (10-row threshold).
    if ($deleted > 0) {
        $userName = $user['name'] ?? 'system';
        $userId   = $user['id']   ?? null;
        if ($deleted <= 10) {
            foreach ($auditRows as $r) {
                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'delete',
                    'module'       => 'quickbooks',
                    'entity_type'  => 'qbo_sync_queue',
                    'entity_id'    => (int) $r['id'],
                    'entity_label' => "{$r['entity_type']}#{$r['entity_id']} {$r['operation']}",
                    'notes'        => "Bulk clear (scope={$scope}) via Sync Queue UI.",
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            }
        } else {
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_sync_queue_batch',
                'entity_id'    => null,
                'entity_label' => "scope={$scope} count={$deleted}",
                'notes'        => "Bulk clear of {$deleted} rows (scope={$scope}). Per-row audits omitted above 10-row threshold per S-QBO-4 retention policy.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }
    }

    json_success([
        'deleted_count' => $deleted,
        'scope'         => $scope,
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Clear failed: ' . $e->getMessage(), 500);
}
