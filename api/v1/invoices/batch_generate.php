<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_generate.php
 *
 * S-BATCH-INVOICING — generate one DRAFT invoice per selected lease for an
 * operator-chosen period (a full calendar month or a custom range). Each
 * lease is processed independently so one bad lease never aborts the rest
 * of the batch — same isolation model as cron/invoice_generate_monthly.php
 * and api/v1/leases/bulk_close.php.
 *
 * ── Why this calls InvoiceGenerator::createFromLease() directly, NOT
 *    InvoiceGenerator::generateForLease() ──────────────────────────────
 * generateForLease() (the method api/v1/invoices/create.php uses for a
 * single manual invoice) calls json_error() UNCONDITIONALLY — not gated
 * behind a function_exists() check — on several failure paths: overlap
 * (assertNoOverlap(), line ~2735), lease-not-found (~2534), inverted
 * period (~2675). json_error() echoes JSON and calls exit(), which
 * PHP cannot catch with try/catch. Called from inside this loop, the
 * FIRST already-billed lease in the batch would silently terminate the
 * entire HTTP response — every lease after it in iteration order would
 * simply never run, with no per-lease error reported. cron/
 * invoice_generate_monthly.php avoids this because crons never load
 * api/bootstrap.php, so json_error() is undefined there and every one of
 * these paths throws a catchable exception instead.
 *
 * createFromLease() (the lower-level single-invoice writer) has the same
 * class of risk on exactly ONE unconditional json_error() call (the
 * credit-overflow-cap invariant refusal, when capping every cappable
 * credit line still leaves a negative subtotal) plus a lease-not-found
 * check that is ALSO unconditional once json_error() is defined. We
 * neutralise both:
 *   1. Lease-not-found: this endpoint re-verifies existence + status
 *      immediately before each call (belt-and-suspenders around the
 *      eligibility check the caller already ran).
 *   2. Overlap: we replicate generateForLease()'s own overlap guard via
 *      the public InvoiceGenerator::findOverlappingInvoice() BEFORE
 *      calling createFromLease() — an overlapping lease is routed to
 *      $skipped with a reason, never reaching the generator at all.
 *   3. Credit-overflow-cap edge case: genuinely rare (only reachable when
 *      existing credit lines already make a fresh period's subtotal
 *      negative) and NOT something this endpoint can safely route around
 *      without duplicating InvoiceGenerator's internal capping math. If
 *      it fires, the request terminates with that lease's leases already
 *      committed (each createFromLease() call commits its own
 *      transaction independently) — re-running the batch is safe and
 *      idempotent (already-billed leases are skipped on the next pass
 *      via the same overlap check). Documented here rather than silently
 *      risked — do not "fix" by wrapping this in a blanket try/catch;
 *      exit() cannot be caught.
 *
 * @method  POST
 * @body    { period_start, period_end, lease_ids: [int, ...] }  (max 200)
 * @auth    Session required; require_permission('invoices','create')
 * @returns 200 { actioned, skipped, errors: [{lease_id, reason}],
 *                invoices: [{lease_id, invoice_id, invoice_number, total_amount, customer_id}] }
 *
 * @depends lib/Billing/Cycle/CycleGenerator.php (the shared per-lease loop — see there for
 *          why createFromLease() and not generateForLease())
 * @decisions D14 (inclusive days), D16 (bcmath)
 * @session S-BATCH-INVOICING, S-BILLING-MODULE (holds, cycle readings, closed-cycle lock)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\Cycle\CycleClose;
use FleetForge\Billing\Cycle\CycleGenerator;

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
    $fields['lease_ids'] = 'Select at least one lease to bill.';
} elseif (count($rawIds) > 200) {
    $fields['lease_ids'] = 'A maximum of 200 leases can be billed per batch run.';
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

// ── Approval gate (S-BATCH-APPROVAL) ────────────────────────────────
// Billing → Settings → "Require approval before batch
// billing". When on, this direct path is closed and billing must go through
// a submitted+approved run (api/v1/invoices/batch_runs/*), which is what
// makes the approval workflow enforceable rather than advisory.
// Deliberately scoped to BATCH generation only: single-invoice creation
// (invoices/create) and the monthly cron are untouched, so turning this on
// cannot stop routine billing.
// The generate-from-run endpoint does NOT come through here, so an approved
// run still generates normally.
if ((string) settings_get('invoices.approval_required', '0') === '1') {
    json_error(
        'APPROVAL_REQUIRED',
        'Approval is required before batch billing. Submit these leases for approval instead — '
        . 'once a run is approved it can be generated from its own page.',
        409
    );
}

// ── Closed-cycle lock (S-BILLING-MODULE) ─────────────────────────────
// A closed billing cycle means that month's billing is signed off. The
// workbench may not add to it; reopening the cycle (Billing, "approve"
// permission, reason required) is the way back. A lease's own Generate
// Invoice / close is deliberately NOT locked — see lib/Billing/Cycle/CycleClose.php.
if ($closedCycle = CycleClose::closedCycleFor($periodStart, $periodEnd)) {
    json_error(
        'CYCLE_CLOSED',
        "Billing cycle {$closedCycle['reference']} is closed, so the workbench cannot bill that month. "
        . 'Reopen the cycle on Billing to bill more leases for it.',
        409
    );
}

// ── Generate (S-BILLING-MODULE-2: the shared per-lease loop) ─────────
// CycleGenerator::run() is the ONE implementation of batch generation —
// re-verify active + monthly, billing hold, overlap guard, createFromLease
// with the cycle's readings (queued charges added by the generator), audit +
// notify, then flag skips / clear successes in the exceptions queue. The
// approved-run endpoint calls the same method.
$result = CycleGenerator::run($leaseIds, $periodStart, $periodEnd, [
    'user_id'           => current_user_id(),
    'user_name'         => current_user()['name'] ?? 'System',
    'generation_source' => 'manual',
    'exception_source'  => 'batch_generate',
    'label'             => 'Batch Invoicing',
    'internal_notes'    => 'Generated via Batch Invoicing (' . $periodStart . ' to ' . $periodEnd . ').',
]);

json_success([
    'actioned' => $result['actioned'],
    'skipped'  => $result['skipped'],
    'errors'   => $result['errors'],
    'invoices' => $result['invoices'],
    // How many of the skips are now sitting in the review queue (holds are not).
    'flagged'  => $result['flagged'],
], 201);
