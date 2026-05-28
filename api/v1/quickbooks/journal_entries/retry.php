<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/journal_entries/retry.php
 *
 * Re-enqueue a failed or failed_preflight journal entry for QBO push.
 * Operator-initiated retry. Falls through JournalEntryEnqueuer gate-0
 * + 4-step gating same as the original create-time enqueue.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { id: int }  acc_qbo_journal_entry_map.id
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session  S-QBO-21
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body  = json_body();
$mapId = (int) ($body['id'] ?? 0);
if ($mapId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required (acc_qbo_journal_entry_map.id)', 422);
}

try {
    $row = db_row(
        "SELECT id, ff_journal_entry_id, push_status, qbo_journal_entry_id
           FROM acc_qbo_journal_entry_map
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

    $enqueued = \FleetForge\QboPushers\JournalEntryEnqueuer::enqueue(
        (int) $row['ff_journal_entry_id'],
        'create'
    );

    if (!$enqueued) {
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.journal_entry', 'queue');
        $je = db_row(
            "SELECT entry_status, source_type FROM acc_journal_entries WHERE id = ?",
            [(int) $row['ff_journal_entry_id']]
        );
        // Bridge-derived filter list per D-QBO-21-1 + spec §8.10
        $bridgeTypes = ['invoice','payment','credit_note','ap_bill','ap_payment'];
        $isBridge = $je && in_array($je['source_type'] ?? '', $bridgeTypes, true);

        $reason = $isBridge
            ? "JE source_type='{$je['source_type']}' is bridge-derived per spec §8.10 (QBO derives JE from parent entity sync). D-QBO-21-1."
            : ($syncEnabled !== '1'
                ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
                : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                    ? "sync_mode.journal_entry='{$syncMode}' rejects push direction"
                    : "JE entry_status='" . ($je['entry_status'] ?? 'unknown') . "' not eligible (need 'posted')"));

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
        'entity_type'  => 'qbo_journal_entry_retry',
        'entity_id'    => (int) $row['ff_journal_entry_id'],
        'entity_label' => "Journal entry mapping id={$mapId}",
        'notes'        => "Re-enqueued failed QBO journal entry push (was push_status='{$row['push_status']}')",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
