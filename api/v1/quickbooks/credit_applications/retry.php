<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/credit_applications/retry.php
 *
 * Re-enqueue a failed or failed_preflight credit-application for QBO push.
 * Operator-initiated retry. Falls through CreditApplicationEnqueuer gate-0 +
 * 4-step gating same as the original apply-time enqueue.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_credit_application_map.id
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-CREDIT-MEMO-APPLY
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_credit_application_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_credit_application_id, push_status, qbo_payment_id
           FROM acc_qbo_credit_application_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    $retryableStatuses = ['failed', 'failed_preflight'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight rows are retryable.",
            409
        );
    }

    $enqueued = \FleetForge\QboPushers\CreditApplicationEnqueuer::enqueue(
        (int) $row['ff_credit_application_id'],
        'create'
    );

    if (!$enqueued) {
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.credit_application', 'sync');
        $app = db_row(
            "SELECT id FROM credit_note_applications WHERE id = ?",
            [(int) $row['ff_credit_application_id']]
        );
        $reason = !$app
            ? "FF credit application {$row['ff_credit_application_id']} not found"
            : ($syncEnabled !== '1'
                ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
                : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                    ? "sync_mode.credit_application='{$syncMode}' rejects push direction"
                    : "enqueue gate refused"));

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
        'entity_type'  => 'qbo_credit_application_retry',
        'entity_id'    => (int) $row['ff_credit_application_id'],
        'entity_label' => "Credit application mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO credit-application push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
