<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/sync_queue_retry.php
 *
 * Reset queue row(s) back to 'queued' so the worker re-attempts the
 * push. Used by operators clearing a failed batch — typically after
 * resolving the upstream cause (e.g. fixing a mapping, reseating
 * credentials, etc.).
 *
 * Side effects per row:
 *   status         → 'queued'
 *   retry_count    → 0
 *   next_retry_at  → NULL  (worker picks immediately on next tick)
 *   picked_up_at   → NULL
 *   worker_id      → NULL
 *   error_code     → NULL
 *   error_message  → NULL
 *
 * Only failed/skipped rows can be retried — queued/processing/completed
 * rows are intentionally rejected with a 422 to avoid clobbering an
 * in-flight worker (and to avoid resurrecting completed work).
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'force_resync')
 * @body    JSON: { id: int }  OR  { ids: int[] }
 * @returns 200 { reset_count: int, skipped_ids: int[] }
 *
 * Spec ref: §6.4 (queue states), D-PERM-EXPAND-5 (force_resync action)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'force_resync');

$body = json_body();
$ids  = [];

if (isset($body['id'])) {
    $ids[] = (int) $body['id'];
}
if (isset($body['ids']) && is_array($body['ids'])) {
    foreach ($body['ids'] as $i) {
        $ids[] = (int) $i;
    }
}
$ids = array_values(array_unique(array_filter($ids, static fn($i) => $i > 0)));

if ($ids === []) {
    json_validation_error(['id' => 'Provide id (single) or ids (array of integers).']);
}

$resetCount  = 0;
$skippedIds  = [];

try {
    $user = current_user();
    db_transaction(function () use ($ids, &$resetCount, &$skippedIds, $user): void {
        foreach ($ids as $id) {
            $row = db_row(
                "SELECT id, status, entity_type, entity_id, operation
                   FROM acc_qbo_sync_queue
                  WHERE id = ?
                  FOR UPDATE",
                [$id]
            );
            if (!$row) {
                $skippedIds[] = $id;
                continue;
            }
            if (!in_array($row['status'], ['failed', 'skipped'], true)) {
                $skippedIds[] = $id;
                continue;
            }

            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='queued',
                        retry_count=0,
                        next_retry_at=NULL,
                        picked_up_at=NULL,
                        worker_id=NULL,
                        error_code=NULL,
                        error_message=NULL
                  WHERE id = ?",
                [$id]
            );
            $resetCount++;

            db_insert('audit_log', [
                'user_id'      => $user['id'] ?? null,
                'user_name'    => $user['name'] ?? 'system',
                'action'       => 'update',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_sync_queue',
                'entity_id'    => $id,
                'entity_label' => "{$row['entity_type']}#{$row['entity_id']} {$row['operation']}",
                'notes'        => 'Manual retry — queue row reset to queued via Sync Queue UI.',
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }
    });

    json_success([
        'reset_count' => $resetCount,
        'skipped_ids' => $skippedIds,
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
