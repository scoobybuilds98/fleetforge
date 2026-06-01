<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/refund_receipts/retry.php
 *
 * Re-enqueue a failed or failed_preflight refund receipt for QBO push.
 * Operator-initiated retry. Falls through RefundReceiptEnqueuer gate-0 +
 * 4-step gating same as the original settle-time enqueue.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_refund_receipt_map.id
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-17
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_refund_receipt_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_lease_id, push_status, qbo_refund_receipt_id
           FROM acc_qbo_refund_receipt_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    $retryableStatuses = ['failed', 'failed_preflight', 'failed_preflight_field_too_long'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight* rows are retryable.",
            409
        );
    }

    $enqueued = \FleetForge\QboPushers\RefundReceiptEnqueuer::enqueue(
        (int) $row['ff_lease_id'],
        'create'
    );

    if (!$enqueued) {
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.refund_receipt', 'sync');
        $lease = db_row(
            "SELECT status, deleted_at, precharge_refund_method, precharge_refund_settled_at
               FROM leases WHERE id = ?",
            [(int) $row['ff_lease_id']]
        );
        $reason = !$lease
            ? "FF lease {$row['ff_lease_id']} not found"
            : ((($lease['deleted_at'] ?? null) !== null)
                ? "FF lease is soft-deleted"
                : (($lease['status'] ?? '') !== 'completed'
                    ? "FF lease status='" . ($lease['status'] ?? 'unknown') . "' not eligible (need 'completed')"
                    : (($lease['precharge_refund_method'] ?? '') !== 'cash'
                        ? "refund method is not 'cash'"
                        : (($lease['precharge_refund_settled_at'] ?? null) === null
                            ? "cash refund not yet settled"
                            : ($syncEnabled !== '1'
                                ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
                                : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                                    ? "sync_mode.refund_receipt='{$syncMode}' rejects push direction"
                                    : "enqueue gate refused"))))));

        json_success([
            'action' => 'skipped',
            'reason' => $reason,
        ]);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_refund_receipt_retry',
        'entity_id'    => (int) $row['ff_lease_id'],
        'entity_label' => "Refund receipt mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO refund receipt push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
