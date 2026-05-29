<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/credit_memos/retry.php
 *
 * Re-enqueue a failed or failed_preflight credit memo for QBO push.
 * Operator-initiated retry. Falls through CreditMemoEnqueuer gate-0 +
 * 4-step gating same as the original create-time enqueue.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_credit_memo_map.id
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-16
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_credit_memo_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_credit_note_id, push_status, qbo_credit_memo_id
           FROM acc_qbo_credit_memo_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    $retryableStatuses = ['failed', 'failed_preflight', 'failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight* rows are retryable.",
            409
        );
    }

    $enqueued = \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue(
        (int) $row['ff_credit_note_id'],
        'create'
    );

    if (!$enqueued) {
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.credit_memo', 'sync');
        $cn = db_row(
            "SELECT status, deleted_at FROM credit_notes WHERE id = ?",
            [(int) $row['ff_credit_note_id']]
        );
        $reason = !$cn
            ? "FF credit note {$row['ff_credit_note_id']} not found"
            : ((($cn['deleted_at'] ?? null) !== null)
                ? "FF credit note is soft-deleted"
                : (($cn['status'] ?? '') !== 'active'
                    ? "FF credit note status='" . ($cn['status'] ?? 'unknown') . "' not eligible (need 'active')"
                    : ($syncEnabled !== '1'
                        ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
                        : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                            ? "sync_mode.credit_memo='{$syncMode}' rejects push direction"
                            : "enqueue gate refused"))));

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
        'entity_type'  => 'qbo_credit_memo_retry',
        'entity_id'    => (int) $row['ff_credit_note_id'],
        'entity_label' => "Credit memo mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO credit memo push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
