<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bills/retry.php
 *
 * Re-enqueue a failed or failed_preflight bill for QBO push.
 * Operator-initiated retry from the admin UI. Falls through the
 * BillEnqueuer gate-0 + 4-step gating same as the original
 * approve-time enqueue — if sync_enabled=0 or sync_mode rejects,
 * the retry silently no-ops (operator gets a 200 with action='skipped').
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_bill_map.id (NOT ff_bill_id)
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-BILL-SYNC-UI (follow-up to S-QBO-18)
 * @decision D-QBO-18-1 (queued path; retry uses same enqueuer)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_bill_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_bill_id, push_status, qbo_bill_id
           FROM acc_qbo_bill_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    // Only retry failure states. Pushed rows are already in QBO; pending rows
    // are already in queue. Voided/skipped_* states aren't retryable —
    // operator must un-void the bill or change the sync_mode setting first.
    $retryableStatuses = ['failed', 'failed_preflight'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight rows are retryable.",
            409
        );
    }

    // Already-mapped rows shouldn't retry create — would create a duplicate.
    // (failed rows may still have qbo_bill_id from a prior partial success;
    // the BillEnqueuer + pushImpl idempotency check catches this safely.)
    $enqueued = \FleetForge\QboPushers\BillEnqueuer::enqueue(
        (int) $row['ff_bill_id'],
        'create'
    );

    if (!$enqueued) {
        // Gate refused — surface why. Most common: sync_enabled='0' or bill
        // status no longer in eligibility allowlist (e.g. operator voided
        // the bill between approve.php fire and the retry click).
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.bill', 'queue');
        $billStatus  = db_row("SELECT status FROM acc_bills WHERE id = ?", [(int) $row['ff_bill_id']]);
        $reason = $syncEnabled !== '1'
            ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
            : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                ? "sync_mode.bill='{$syncMode}' rejects push direction"
                : "bill status='" . ($billStatus['status'] ?? 'unknown') . "' not eligible (need approved/scheduled/partially_paid/paid)");

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
        'entity_type'  => 'qbo_bill_retry',
        'entity_id'    => (int) $row['ff_bill_id'],
        'entity_label' => "Bill mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO bill push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
