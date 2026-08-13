<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_runs/create.php
 *
 * S-BATCH-APPROVAL — submit a batch of leases for approval.
 *
 * Runs the dry run ONCE and FREEZES the result as the run's snapshot.
 * The snapshot is what the approver sees and signs off on; it is never
 * recomputed on view. Recomputing would mean the figures could silently
 * change between approval and generation (a rate edit, an invoice created
 * elsewhere for the same period), so what was approved would not be what
 * ships. Drift is instead surfaced AT GENERATION by re-verifying against
 * live data (see generate.php) — the snapshot itself stays immutable.
 *
 * @method  POST
 * @body    { period_start, period_end, lease_ids: [int,...], note? }
 * @auth    Session required; require_permission('invoices','create')
 * @returns 201 { id, reference, status, invoice_count, total_by_currency, url }
 *
 * @depends lib/Billing/BatchPreviewService.php
 * @session S-BATCH-APPROVAL
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\BatchPreviewService;

$body = json_body();

$periodStart = clean_date($body['period_start'] ?? null);
$periodEnd   = clean_date($body['period_end'] ?? null);

$fields = [];
if (!$periodStart) $fields['period_start'] = 'A valid period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'A valid period end date is required.';
if ($periodStart && $periodEnd && $periodEnd < $periodStart) {
    $fields['period_end'] = 'Period end cannot be before period start.';
}
if ($periodStart && $periodEnd && !isset($fields['period_end'])) {
    if ($periodErr = ff_billing_period_error($periodStart, $periodEnd)) {
        $fields['period_start'] = $periodErr;
    }
}

$rawIds = $body['lease_ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    $fields['lease_ids'] = 'Select at least one lease to submit.';
} elseif (count($rawIds) > 200) {
    $fields['lease_ids'] = 'A maximum of 200 leases can be submitted in one run.';
}
if ($fields) {
    json_validation_error($fields);
}

$leaseIds = [];
foreach ($rawIds as $raw) {
    $id = clean_int($raw);
    if ($id && $id > 0) $leaseIds[] = $id;
}
$leaseIds = array_values(array_unique($leaseIds));
if (!$leaseIds) {
    json_validation_error(['lease_ids' => 'No valid lease IDs were submitted.']);
}

$note = clean_string($body['note'] ?? null, 2000);

// ── Freeze the dry run ──────────────────────────────────────────────
$snapshot = BatchPreviewService::run($leaseIds, $periodStart, $periodEnd, current_user_id());

if ((int) $snapshot['totals']['ok_count'] === 0) {
    json_error(
        'NOTHING_TO_BILL',
        'None of the selected leases can be billed for this period — nothing to submit for approval.',
        422,
        ['previews' => $snapshot['previews']]
    );
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

$runId = db_transaction(static function () use ($snapshot, $leaseIds, $periodStart, $periodEnd, $note, $userId, $now): int {
    $id = db_insert('invoice_batch_runs', [
        // Placeholder — the human reference derives from the id, which only
        // exists after insert. Unique index is satisfied by the temp value.
        'reference'         => 'BR-PENDING-' . bin2hex(random_bytes(6)),
        'period_start'      => $periodStart,
        'period_end'        => $periodEnd,
        'status'            => 'pending',
        'lease_ids'         => json_encode($leaseIds),
        'snapshot'          => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        'invoice_count'     => (int) $snapshot['totals']['ok_count'],
        'skipped_count'     => (int) $snapshot['totals']['error_count'],
        'total_by_currency' => json_encode($snapshot['totals']['by_currency']),
        'note'              => $note,
        'submitted_by'      => $userId,
        'submitted_at'      => $now,
    ]);

    $reference = 'BR-' . date('Y', strtotime($periodStart)) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db_execute("UPDATE invoice_batch_runs SET reference = ? WHERE id = ?", [$reference, $id]);

    return (int) $id;
});

$run = db_row("SELECT * FROM invoice_batch_runs WHERE id = ?", [$runId]);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'System',
    'action'       => 'create',
    'module'       => 'invoices',
    'entity_type'  => 'batch_run',
    'entity_id'    => $runId,
    'entity_label' => $run['reference'],
    'notes'        => "Batch run {$run['reference']} submitted for approval — {$run['invoice_count']} invoice(s), "
                    . "period {$periodStart}..{$periodEnd}.",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// Notify anyone who can approve that something is waiting on them.
try {
    \FleetForge\Notifications\NotificationService::notify(
        type:       'invoice.batch_run_submitted',
        title:      "Batch run {$run['reference']} needs approval",
        message:    "{$run['invoice_count']} invoice(s) for {$periodStart} → {$periodEnd} submitted by "
                  . (current_user()['name'] ?? 'a user') . '.',
        entityType: 'batch_run',
        entityId:   $runId,
        url:        '/fleetforge/invoices/batch_run?id=' . $runId
    );
} catch (\Throwable $e) {
    error_log('[NOTIF invoice.batch_run_submitted] ' . $e->getMessage());
}

json_success([
    'id'                => $runId,
    'reference'         => $run['reference'],
    'status'            => $run['status'],
    'invoice_count'     => (int) $run['invoice_count'],
    'skipped_count'     => (int) $run['skipped_count'],
    'total_by_currency' => json_decode((string) $run['total_by_currency'], true),
    'url'               => base_url('invoices/batch_run') . '?id=' . $runId,
], 201);
