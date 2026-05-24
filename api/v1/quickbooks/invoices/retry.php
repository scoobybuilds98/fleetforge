<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/invoices/retry.php
 *
 * Re-enqueue a failed or failed_preflight invoice for QBO push.
 * Operator-initiated retry from the admin UI. Falls through the
 * InvoiceEnqueuer 4-step gate same as the original send-time
 * enqueue — if sync_enabled=0 or sync_mode rejects, the retry
 * silently no-ops (operator gets a 200 with action='skipped').
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_invoice_map.id (NOT ff_invoice_id)
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-11
 * @decision D-QBO-11-1 (queued path; retry uses same enqueuer)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_invoice_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_invoice_id, push_status, qbo_invoice_id
           FROM acc_qbo_invoice_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    // Only retry failure states. Pushed rows are already in QBO; pending rows
    // are already in queue. Typed preflight failures are retryable once the
    // operator fixes the underlying issue (shorten invoice_number, remap
    // customer currency, etc.) — D-QBO-FIXPACK-5.
    $retryableStatuses = ['failed', 'failed_preflight', 'failed_preflight_field_too_long', 'failed_preflight_currency_mismatch'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight* rows are retryable.",
            409
        );
    }

    // Already-mapped rows shouldn't retry create — would create a duplicate.
    // (failed rows may still have qbo_invoice_id from a prior partial success;
    // the enqueuer + pushImpl idempotency check catches this safely.)
    $enqueued = \FleetForge\QboPushers\InvoiceEnqueuer::enqueue(
        (int) $row['ff_invoice_id'],
        'create'
    );

    if (!$enqueued) {
        // Gate refused — surface why. Most common: sync_enabled='0'.
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode = (string) settings_get('quickbooks.sync_mode.invoice', 'sync');
        $reason = $syncEnabled !== '1'
            ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
            : "sync_mode.invoice='{$syncMode}' rejects push direction";

        json_success([
            'action' => 'skipped',
            'reason' => $reason,
        ]);
    }

    // Audit the retry action for forensic trace.
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_invoice_retry',
        'entity_id'    => (int) $row['ff_invoice_id'],
        'entity_label' => "Invoice mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO invoice push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
