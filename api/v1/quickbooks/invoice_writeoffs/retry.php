<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/invoice_writeoffs/retry.php
 *
 * Queue (again) an FF invoice write-off for QuickBooks — the credit memo on
 * the Bad-debt item applied to the invoice (InvoiceWriteoffPusher). Used by
 * the "Invoice write-offs" section of QuickBooks → Credit Memos for a
 * failed / blocked push, or one that was never queued (QuickBooks sync was
 * off when the invoice was written off).
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    { writeoff_id: int }  acc_bad_debt_writeoffs.id
 * @returns 200 { action: 'enqueued'|'skipped', reason?: string }
 *
 * @session S-QBO-INVOICE-WRITEOFF
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body       = json_body();
$writeoffId = (int) ($body['writeoff_id'] ?? 0);
if ($writeoffId <= 0) {
    json_error('MISSING_REQUIRED', 'writeoff_id is required (acc_bad_debt_writeoffs.id)', 422);
}

try {
    $w = db_row(
        "SELECT w.id, w.recovered, i.invoice_number, m.push_status
           FROM acc_bad_debt_writeoffs w
           JOIN invoices i ON i.id = w.invoice_id
      LEFT JOIN acc_qbo_invoice_writeoff_map m ON m.ff_writeoff_id = w.id
          WHERE w.id = ?",
        [$writeoffId]
    );
    if (!$w) {
        json_error('NOT_FOUND', "Write-off {$writeoffId} not found", 404);
    }
    // Never queued, or stopped: failed / blocked / skipped by mode. A pushed
    // (or in-flight) write-off must not be sent twice.
    $retryable = [null, 'failed', 'failed_preflight', 'skipped_by_mode'];
    if (!in_array($w['push_status'], $retryable, true)) {
        json_error('INVALID_STATE', "The write-off of {$w['invoice_number']} is '{$w['push_status']}' in QuickBooks — nothing to retry.", 409);
    }

    if (!\FleetForge\QboPushers\InvoiceWriteoffEnqueuer::enqueue($writeoffId, 'create')) {
        $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
        $syncMode    = (string) settings_get('quickbooks.sync_mode.invoice_writeoff', 'queue');
        $reason = !empty($w['recovered'])
            ? 'The write-off was recovered in FleetForge — nothing to send.'
            : ($syncEnabled !== '1'
                ? 'QuickBooks sync is off (Settings → Master Controls).'
                : (in_array($syncMode, ['qbo_to_ff', 'disabled'], true)
                    ? "sync_mode.invoice_writeoff='{$syncMode}' blocks pushes."
                    : 'The queue refused it (see the error log).'));
        json_success(['action' => 'skipped', 'reason' => $reason]);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_invoice_writeoff_retry',
        'entity_id'    => $writeoffId,
        'entity_label' => "Write-off of {$w['invoice_number']}",
        'notes'        => 'Queued the QuickBooks write-off credit memo for ' . $w['invoice_number'] . ' (was ' . ($w['push_status'] ?? 'never queued') . ')',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['action' => 'enqueued']);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Retry failed: ' . $e->getMessage(), 500);
}
