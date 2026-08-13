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
 * @depends lib/Billing/InvoiceGenerator.php (createFromLease, findOverlappingInvoice)
 * @decisions D14 (inclusive days), D16 (bcmath)
 * @session S-BATCH-INVOICING
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;

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

// A full-calendar-month selection bills as 'full_month' (matches how the
// monthly cron labels the same shape); anything else is a general-purpose
// 'single_period' span — see InvoiceGenerator's billing_type doc comment.
$isFullCalendarMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                    && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));
$billingType = $isFullCalendarMonth ? 'full_month' : 'single_period';

$generator = new InvoiceGenerator();
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$actioned = 0;
$skipped  = 0;
$errors   = [];
$invoices = [];

foreach ($leaseIds as $leaseId) {
    // ── Re-verify existence + active + monthly right before generating
    // (belt-and-suspenders — see docblock #1: createFromLease()'s own
    // lease-not-found guard exits via json_error() in this API context,
    // so we must never let it reach that check on a bad ID). ─────────
    $lease = db_row(
        "SELECT l.id, l.contract_number, l.customer_id, l.status, l.billing_cycle,
                c.company_name
           FROM leases l
           JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
          WHERE l.id = ? AND l.deleted_at IS NULL",
        [$leaseId]
    );
    if (!$lease) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => 'Lease not found or deleted.'];
        continue;
    }
    if ($lease['status'] !== 'active') {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease is '{$lease['status']}', not active."];
        continue;
    }
    if ($lease['billing_cycle'] !== 'monthly') {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease billing_cycle is '{$lease['billing_cycle']}' — batch invoicing only bills monthly leases."];
        continue;
    }

    // ── Overlap guard — replicates generateForLease()'s assertNoOverlap()
    // without its unconditional json_error() exit (see docblock #2). ──
    $overlap = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
    if ($overlap) {
        $skipped++;
        $errors[] = [
            'lease_id' => $leaseId,
            'reason'   => "Already covered by invoice {$overlap['invoice_number']} ({$overlap['status']}).",
        ];
        continue;
    }

    try {
        $result = $generator->createFromLease([
            'lease_id'              => $leaseId,
            'period_start'          => $periodStart,
            'period_end'            => $periodEnd,
            'billing_type'          => $billingType,
            'invoice_type'          => 'regular',
            'generation_source'     => 'manual', // enum has no 'batch' value; audit_log below carries the batch provenance
            'auto_generated'        => 0,
            'created_by'            => $userId,
            'require_lease_status'  => 'active',
            'internal_notes'        => 'Generated via Batch Invoicing (' . $periodStart . ' to ' . $periodEnd . ').',
        ]);

        $actioned++;
        $invoices[] = [
            'lease_id'       => $leaseId,
            'customer_id'    => (int) $lease['customer_id'],
            'invoice_id'     => (int) $result['invoice_id'],
            'invoice_number' => (string) $result['invoice_number'],
            'total_amount'   => (string) $result['total_amount'],
        ];

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $result['invoice_id'],
            'entity_label' => $result['invoice_number'],
            'notes'        => "Batch Invoicing: created {$result['invoice_number']} for lease #{$leaseId} ({$lease['contract_number']}, {$lease['company_name']}), period {$periodStart}..{$periodEnd}.",
            'ip_address'   => $ipAddress,
        ]);

        try {
            \FleetForge\Notifications\NotificationService::notify(
                type:       'invoice.created',
                title:      "New invoice {$result['invoice_number']}",
                message:    "Invoice {$result['invoice_number']} created for {$lease['company_name']} via Batch Invoicing — \$" . number_format((float) $result['total_amount'], 2),
                entityType: 'invoice',
                entityId:   (int) $result['invoice_id'],
                url:        '/fleetforge/invoices/show?id=' . $result['invoice_id']
            );
        } catch (\Throwable $e) {
            error_log('[NOTIF invoice.created] ' . $e->getMessage());
        }

    } catch (BillingRateException $e) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => 'No billable rate configured for this period: ' . $e->getMessage()];
    } catch (\Throwable $e) {
        $skipped++;
        $errors[] = ['lease_id' => $leaseId, 'reason' => $e->getMessage()];
        error_log("[batch_generate] Lease #{$leaseId}: " . $e->getMessage());
    }
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'invoices' => $invoices,
], 201);
