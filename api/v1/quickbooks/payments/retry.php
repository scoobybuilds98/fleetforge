<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/payments/retry.php
 *
 * Re-enqueue a failed or failed_preflight payment for QBO push.
 * Operator-initiated retry from the admin UI. Falls through the
 * PaymentEnqueuer gate-0 + 4-step gating same as the original
 * create-time enqueue.
 *
 * Critical guard (D-QBO-14-1, closes D-QBO-13-1/2 invariant at the
 * admin layer): rows with origin != 'ff_native' OR push_status =
 * 'pulled_from_qbo' are REJECTED with INVALID_STATE. Webhook-originated
 * payments are already in QBO; re-pushing them would create duplicates.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_payment_map.id (NOT ff_payment_id)
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-PAYMENT-SYNC-UI (follow-up to S-QBO-13 + S-QBO-14)
 * @decision D-QBO-14-1 (bidirectional dedup — ff_native only at retry layer)
 *           D-QBO-13-2 (pulled_from_qbo terminal — never re-push)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_payment_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_payment_id, push_status, qbo_payment_id, origin
           FROM acc_qbo_payment_map
          WHERE id = ?",
        [$mapId]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Mapping row {$mapId} not found", 404);
    }

    // CRITICAL D-QBO-14-1 + D-QBO-13-2 invariant at admin layer.
    // Rows with origin != 'ff_native' OR push_status='pulled_from_qbo' are
    // webhook-originated; re-pushing creates a duplicate QBO Payment.
    if ($row['origin'] !== 'ff_native') {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with origin='{$row['origin']}' — webhook-originated payments are already in QBO and must not be re-pushed (D-QBO-14-1 bidirectional dedup invariant; closes D-QBO-13-1/2 loop).",
            409
        );
    }
    if ($row['push_status'] === 'pulled_from_qbo') {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='pulled_from_qbo' — this is the terminal state for webhook-originated payments per D-QBO-13-2.",
            409
        );
    }

    // Only retry failure states. Pushed rows are already in QBO; pending
    // rows are already in queue. Voided/skipped_voided/skipped_by_mode
    // aren't retryable — operator must change payment state or
    // sync_mode setting first.
    $retryableStatuses = ['failed', 'failed_preflight'];
    if (!in_array($row['push_status'], $retryableStatuses, true)) {
        json_error(
            'INVALID_STATE',
            "Cannot retry row with push_status='{$row['push_status']}'. Only failed/failed_preflight rows are retryable.",
            409
        );
    }

    $enqueued = \FleetForge\QboPushers\PaymentEnqueuer::enqueue(
        (int) $row['ff_payment_id'],
        'create'
    );

    if (!$enqueued) {
        // Gate refused — surface why. Most common: sync_enabled='0', or
        // payment.status no longer 'cleared' (operator voided/refunded
        // between create.php fire and the retry click), or payment.origin
        // got mutated to non-ff_native.
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.payment', 'queue');
        $payRow      = db_row("SELECT status, origin FROM payments WHERE id = ?", [(int) $row['ff_payment_id']]);
        $reason = $syncEnabled !== '1'
            ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
            : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                ? "sync_mode.payment='{$syncMode}' rejects push direction"
                : (($payRow && $payRow['origin'] !== 'ff_native')
                    ? "payment.origin='{$payRow['origin']}' — only ff_native payments can be pushed (D-QBO-14-1)"
                    : "payment status='" . ($payRow['status'] ?? 'unknown') . "' not eligible (need 'cleared' per D-QBO-14-2)"));

        json_success([
            'action' => 'skipped',
            'reason' => $reason,
        ]);
    }

    // Audit the retry for forensic trace.
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_payment_retry',
        'entity_id'    => (int) $row['ff_payment_id'],
        'entity_label' => "Payment mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO payment push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
