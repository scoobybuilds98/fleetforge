<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_runs/generate.php
 *
 * S-BATCH-APPROVAL — generate the invoices for an APPROVED batch run.
 *
 * ── Staleness is the whole risk here ────────────────────────────────
 * The snapshot froze at submit time; approval happened later; generation
 * happens later still. In between, live data can move — a lease may have
 * been closed, or billed for the same period by the monthly cron or
 * another operator, or its rates edited. Generating blindly from the
 * snapshot could double-bill or bill an amount nobody approved.
 *
 * So each lease is re-verified against live data immediately before it is
 * created:
 *   - lease still exists, still active, still monthly            → else SKIP
 *   - no non-void invoice already overlaps the period            → else SKIP
 * and after creation the REAL total is compared to the approved total;
 * any difference is reported per-lease as drift (the invoice is a real
 * draft either way — it reflects current truth — but the operator and the
 * audit trail see exactly where reality diverged from what was signed off).
 *
 * Per-lease isolation matches batch_generate.php: one bad lease never
 * aborts the rest, and createFromLease() is called directly rather than
 * generateForLease() to avoid the latter's unconditional json_error()
 * (echo+exit, uncatchable) on overlap when running under api/bootstrap.
 *
 * @method  POST
 * @body    { id }
 * @auth    Session required; require_permission('invoices','create')
 * @returns 200 { id, reference, status, actioned, skipped, drifted,
 *                invoices: [...], errors: [...] }
 *          409 INVALID_TRANSITION (not approved / already generated)
 *
 * @session S-BATCH-APPROVAL, S-BILLING-MODULE (closed-cycle lock, holds, readings)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;
use FleetForge\Billing\BillingExceptions;
use FleetForge\Billing\Cycle\BillingHolds;
use FleetForge\Billing\Cycle\CycleClose;
use FleetForge\Billing\Cycle\CycleReadings;

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$run = db_row("SELECT * FROM invoice_batch_runs WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$run) {
    json_error('NOT_FOUND', 'Batch run not found.', 404);
}
if ($run['status'] !== 'approved') {
    json_error(
        'INVALID_TRANSITION',
        $run['status'] === 'generated'
            ? "Batch run {$run['reference']} has already been generated."
            : "Batch run {$run['reference']} is '{$run['status']}' — it must be approved before generating.",
        409
    );
}

// S-BILLING-MODULE: a closed billing cycle locks the workbench out of its
// month. Checked BEFORE the claim below so a refused run stays 'approved'
// and can be generated once the cycle is reopened.
if ($closedCycle = CycleClose::closedCycleFor((string) $run['period_start'], (string) $run['period_end'])) {
    json_error(
        'CYCLE_CLOSED',
        "Billing cycle {$closedCycle['reference']} is closed. Reopen it on Billing to generate this run.",
        409
    );
}

// Claim the run BEFORE doing any work so two concurrent clicks can't both
// generate. The loser affects 0 rows and bails out.
$claimed = db_execute(
    "UPDATE invoice_batch_runs SET status = 'generated', updated_at = NOW()
      WHERE id = ? AND status = 'approved'",
    [$id]
);
if ($claimed === 0) {
    json_error('INVALID_TRANSITION', 'This run is already being generated.', 409);
}

$snapshot    = json_decode((string) $run['snapshot'], true) ?: [];
$periodStart = (string) $run['period_start'];
$periodEnd   = (string) $run['period_end'];

$isFullCalendarMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                    && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));
$billingType = $isFullCalendarMonth ? 'full_month' : 'single_period';

// Approved amount per lease, for drift detection.
$approvedTotals = [];
foreach (($snapshot['previews'] ?? []) as $p) {
    if (!empty($p['ok'])) {
        $approvedTotals[(int) $p['lease_id']] = (string) $p['total_amount'];
    }
}

$generator = new InvoiceGenerator();
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Who signed this off — resolved once and stamped onto EVERY invoice the run
// creates, so an individual invoice answers "who approved this?" on its own
// without anyone having to trace it back to the run record.
$approver = $run['decided_by']
    ? db_row("SELECT name, email FROM users WHERE id = ?", [(int) $run['decided_by']])
    : null;
$approvedByLabel = $approver['name'] ?? 'unknown';
// S-UTC-STAMPS: decided_at is stored UTC — the label lands in invoice
// internal_notes + audit text, so render it as company-local wall time.
$approvedAtLabel = !empty($run['decided_at']) ? ff_utc_to_local((string) $run['decided_at'], 'Y-m-d H:i:s') : '';

$actioned = 0;
$skipped  = 0;
$errors   = [];
$invoices = [];
$drifted  = [];

// Only leases that were APPROVED (ok in the snapshot) are generated — a
// lease the snapshot already flagged as unbillable was never signed off.
foreach (array_keys($approvedTotals) as $leaseId) {
    $lease = db_row(
        "SELECT l.id, l.contract_number, l.customer_id, l.status, l.billing_cycle, c.company_name
           FROM leases l
           JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
          WHERE l.id = ? AND l.deleted_at IS NULL",
        [$leaseId]
    );
    if (!$lease) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => 'Lease no longer exists — skipped.'];
        continue;
    }
    if ($lease['status'] !== 'active') {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease is now '{$lease['status']}' — skipped."];
        continue;
    }
    if ($lease['billing_cycle'] !== 'monthly') {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease billing cycle is now '{$lease['billing_cycle']}' — skipped."];
        continue;
    }

    // S-BILLING-MODULE: placed on a billing hold since approval — honour it.
    if ($hold = BillingHolds::activeFor($leaseId, $periodStart, $periodEnd)) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => BillingHolds::describe($hold) . ' — skipped.', 'held' => true];
        continue;
    }

    // The critical double-bill guard: something may have billed this period
    // since approval (monthly cron, another operator, another batch run).
    $overlap = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
    if ($overlap) {
        $skipped++;
        $errors[] = [
            'lease_id' => $leaseId,
            'reason'   => "Billed since approval by invoice {$overlap['invoice_number']} ({$overlap['status']}) — skipped to avoid double-billing.",
        ];
        continue;
    }

    try {
        // S-BILLING-MODULE: the cycle's period-end readings — the same
        // parameters the approved snapshot was priced with (BatchPreviewService).
        $result = $generator->createFromLease(CycleReadings::generatorParams($leaseId, $periodStart, $periodEnd) + [
            'lease_id'             => $leaseId,
            'period_start'         => $periodStart,
            'period_end'           => $periodEnd,
            'billing_type'         => $billingType,
            'invoice_type'         => 'regular',
            'generation_source'    => 'manual',
            'auto_generated'       => 0,
            'created_by'           => $userId,
            'require_lease_status' => 'active',
            'internal_notes'       => "Batch run {$run['reference']} ({$periodStart} to {$periodEnd}). "
                                    . "Approved by {$approvedByLabel}"
                                    . ($approvedAtLabel ? " on {$approvedAtLabel}" : '')
                                    . ". Generated by {$userName}.",
        ]);

        $actioned++;
        $actual   = (string) $result['total_amount'];
        $approved = $approvedTotals[$leaseId];
        $isDrift  = bccomp($actual, $approved, 2) !== 0;

        if ($isDrift) {
            $drifted[] = [
                'lease_id'        => $leaseId,
                'invoice_number'  => $result['invoice_number'],
                'approved_total'  => $approved,
                'actual_total'    => $actual,
            ];
        }

        $invoices[] = [
            'lease_id'       => $leaseId,
            'customer_id'    => (int) $lease['customer_id'],
            'invoice_id'     => (int) $result['invoice_id'],
            'invoice_number' => (string) $result['invoice_number'],
            'total_amount'   => $actual,
            'approved_total' => $approved,
            'drifted'        => $isDrift,
        ];

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $result['invoice_id'],
            'entity_label' => $result['invoice_number'],
            'notes'        => "Batch run {$run['reference']}: created {$result['invoice_number']} for lease #{$leaseId} "
                            . "({$lease['contract_number']}, {$lease['company_name']}); approved by {$approvedByLabel}"
                            . ($approvedAtLabel ? " on {$approvedAtLabel}" : '')
                            . ($isDrift ? " — TOTAL DRIFTED from approved {$approved} to {$actual}" : '') . '.',
            'ip_address'   => $ipAddress,
        ]);

    } catch (BillingRateException $e) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => 'No billable rate configured: ' . $e->getMessage()];
    } catch (\Throwable $e) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => $e->getMessage()];
        error_log("[batch_runs/generate] Run {$run['reference']} lease #{$leaseId}: " . $e->getMessage());
    }
}

// ── S-BILLING-EXCEPTIONS ────────────────────────────────────────────────
// Same durable flagging as the direct batch path, so an approved run that
// partially fails leaves its problem leases in the review queue instead of
// only in this one HTTP response. Sourced as 'batch_run' with the run id as
// a breadcrumb.
$bexCustomer = [];
if ($errors) {
    $ids = array_values(array_unique(array_column($errors, 'lease_id')));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_select("SELECT id, customer_id FROM leases WHERE id IN ({$ph})", $ids) as $row) {
        $bexCustomer[(int) $row['id']] = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
    }
}
foreach ($errors as $err) {
    if (!empty($err['held'])) {
        continue; // a billing hold is intentional, not an exception
    }
    BillingExceptions::flag(
        (int) $err['lease_id'],
        $bexCustomer[(int) $err['lease_id']] ?? null,
        $periodStart, $periodEnd,
        (string) $err['reason'],
        'batch_run', $id, $userId
    );
}
foreach ($invoices as $inv) {
    BillingExceptions::clear((int) $inv['lease_id'], $periodStart, $periodEnd, $userId);
}

$generationResult = [
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'drifted'  => $drifted,
    'invoices' => $invoices,
];

db_execute(
    "UPDATE invoice_batch_runs
        SET generated_by = ?, generated_at = ?, generated_invoice_ids = ?, generation_result = ?, updated_at = NOW()
      WHERE id = ?",
    [
        $userId,
        ff_now_utc(), // S-UTC-STAMPS: generated_at is UTC (rendered via format_datetime)
        json_encode(array_column($invoices, 'invoice_id')),
        json_encode($generationResult, JSON_UNESCAPED_UNICODE),
        $id,
    ]
);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $userName,
    'action'       => 'update',
    'module'       => 'invoices',
    'entity_type'  => 'batch_run',
    'entity_id'    => $id,
    'entity_label' => $run['reference'],
    'notes'        => "Batch run {$run['reference']} generated: {$actioned} created, {$skipped} skipped"
                    . (count($drifted) ? ', ' . count($drifted) . ' drifted from approved totals' : '') . '.',
    'ip_address'   => $ipAddress,
]);

json_success([
    'id'        => $id,
    'reference' => $run['reference'],
    'status'    => 'generated',
    'actioned'  => $actioned,
    'skipped'   => $skipped,
    'drifted'   => $drifted,
    'invoices'  => $invoices,
    'errors'    => $errors,
]);
