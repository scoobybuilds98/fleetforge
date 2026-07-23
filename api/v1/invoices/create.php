<?php
declare(strict_types=1);

/**
 * api/v1/invoices/create.php
 *
 * Create a manual invoice for a lease. Uses InvoiceGenerator to calculate
 * pro-rated charges, tax, and generate a gap-free invoice number.
 *
 * @method  POST
 * @body    lease_id (required), period_start (required), period_end (required),
 *          billing_type, invoice_type, po_number, notes, internal_notes,
 *          odometer_at_period_start_km, odometer_at_period_end_km (SAMSARA-3),
 *          odometer_source, odometer_fetched_at (SAMSARA-3)
 * @auth    Session required; require_permission('invoices','create')
 * @returns 201 { id, invoice_number, total_amount, balance_due }
 *
 * Decisions: D14 (inclusive days), D15 (sequential numbers), D16 (bcmath),
 *            D20 (FOR UPDATE on number gen)
 * Session: S008, SAMSARA-3 (odometer/distance tracking)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

// --- Input validation ---
// VALID-2: collect every error into $fields, one 422 response at the end.
$body   = json_body();
$fields = [];

$leaseId = clean_int($body['lease_id'] ?? null);
if (!$leaseId) {
    $fields['lease_id'] = 'Please select a lease.';
}

$periodStart = clean_date($body['period_start'] ?? null);
if (!$periodStart) {
    $fields['period_start'] = 'Invoice date (period start) is required.';
}

$periodEnd = clean_date($body['period_end'] ?? null);
if (!$periodEnd) {
    $fields['period_end'] = 'Due date (period end) is required.';
}

// Validate end >= start
if ($periodStart && $periodEnd && $periodEnd < $periodStart) {
    $fields['period_end'] = 'Due date cannot be before invoice date.';
}

// FLEETFORGE-14: clean_date() proves the date is a real calendar date but NOT
// that the year is plausible — checkdate() accepts year 1, so a mistyped
// '0001-03-02' (for 2026-03-02) was stored verbatim. The row then won the
// close-time coverage anchor on its lease and the derived final period
// (0001-04-01 → 2026-03-13) overflowed billing_period_days, blocking the close
// with an opaque PDO 22003. Reject the typo at the point of entry — this is the
// only operator-facing path that writes a hand-keyed billing period.
if ($periodStart && $periodEnd && !isset($fields['period_end'])) {
    if ($periodErr = ff_billing_period_error($periodStart, $periodEnd)) {
        $fields['period_start'] = $periodErr;
    }
}

if ($fields) {
    json_validation_error($fields);
}

// Verify lease exists and is active
$lease = db_row(
    "SELECT id, status, customer_id, contract_number, company_name_snapshot
       FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_validation_error(['lease_id' => 'Lease not found.'], 'Lease not found.');
}
if (!in_array($lease['status'], ['active', 'completed'])) {
    json_error(
        'LEASE_NOT_ACTIVE',
        'Invoices can only be created for active or completed leases.',
        409,
        ['fields' => ['lease_id' => 'Invoices can only be created for active or completed leases.']]
    );
}

// S-AUDIT-LIFECYCLE-1 #23: out-of-enum values are 422s, not silent coercions
// — a mis-sent billing_type silently became 'partial_start' and billed a
// different period shape than the caller asked for.
$billingType = clean_string($body['billing_type'] ?? 'partial_start');
$validBillingTypes = ['partial_start', 'full_month', 'partial_end', 'single_period'];
if (!in_array($billingType, $validBillingTypes, true)) {
    json_validation_error(['billing_type' => 'Invalid billing_type — must be one of: ' . implode(', ', $validBillingTypes) . '.']);
}

$invoiceType = clean_string($body['invoice_type'] ?? 'regular');
$validInvoiceTypes = ['regular', 'final', 'mileage_only', 'adjustment'];
if (!in_array($invoiceType, $validInvoiceTypes, true)) {
    json_validation_error(['invoice_type' => 'Invalid invoice_type — must be one of: ' . implode(', ', $validInvoiceTypes) . '.']);
}

// ── Overlapping-period guard (§11) ─────────────────────────────
// WHY: the holistic running-reconciliation engine is blind to overlap and will
// silently bill only the cumulative base-rental delta when a manual invoice
// re-covers days an existing non-void invoice already billed. That is almost
// always an operator mistake, so block it by default. An operator who genuinely
// intends a reconciliation can resend with allow_overlap=true.
//
// Revision 2: the gate now lives in InvoiceGenerator::generateForLease (via
// assertNoOverlap), which checks EVERY calendar-month segment of a fan-out
// before any insert — not just the submitted first segment — and distinguishes
// void-before-regenerate (an immutable non-void overlap) from a draft overlap.
// We only forward the operator's allow_overlap intent here.
$allowOverlap = !empty($body['allow_overlap']);

// ── SAMSARA-3: optional odometer fields ────────────────────────
// All four are optional — invoices can still be created without any
// odometer data. The UI populates these when the user fills the
// Odometer & Distance section; InvoiceGenerator handles the rest.
$odoStart = null;
if (isset($body['odometer_at_period_start_km']) && $body['odometer_at_period_start_km'] !== '' && $body['odometer_at_period_start_km'] !== null) {
    $dec = clean_decimal($body['odometer_at_period_start_km']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        $fields['odometer_at_period_start_km'] = 'Starting odometer cannot be negative.';
    } else {
        $odoStart = $dec;
    }
}
$odoEnd = null;
if (isset($body['odometer_at_period_end_km']) && $body['odometer_at_period_end_km'] !== '' && $body['odometer_at_period_end_km'] !== null) {
    $dec = clean_decimal($body['odometer_at_period_end_km']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        $fields['odometer_at_period_end_km'] = 'Ending odometer cannot be negative.';
    } else {
        $odoEnd = $dec;
    }
}
if ($odoStart !== null && $odoEnd !== null && bccomp($odoEnd, $odoStart, 2) < 0) {
    $fields['odometer_at_period_end_km'] = 'Ending odometer cannot be less than starting odometer.';
}

$odoSourceRaw = $body['odometer_source'] ?? null;
$odoSource    = in_array($odoSourceRaw, ['gps', 'manual', 'estimated'], true) ? $odoSourceRaw : null;

$odoFetchedAt = null;
if (!empty($body['odometer_fetched_at'])) {
    try {
        $dt = new DateTime((string) $body['odometer_fetched_at']);
        $odoFetchedAt = $dt->format('Y-m-d H:i:s');
    } catch (\Throwable) {
        $odoFetchedAt = null;
    }
}

// S-LEASE-HOURLY-BILLING: optional per-period engine/reefer hours (manual).
// When the lease has an hourly_rate, the engine bills (end - start) × rate.
$hoursStart = null;
if (isset($body['engine_hours_at_period_start']) && $body['engine_hours_at_period_start'] !== '' && $body['engine_hours_at_period_start'] !== null) {
    $dec = clean_decimal($body['engine_hours_at_period_start']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        $fields['engine_hours_at_period_start'] = 'Starting engine hours cannot be negative.';
    } else {
        $hoursStart = $dec;
    }
}
$hoursEnd = null;
if (isset($body['engine_hours_at_period_end']) && $body['engine_hours_at_period_end'] !== '' && $body['engine_hours_at_period_end'] !== null) {
    $dec = clean_decimal($body['engine_hours_at_period_end']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        $fields['engine_hours_at_period_end'] = 'Ending engine hours cannot be negative.';
    } else {
        $hoursEnd = $dec;
    }
}
if ($hoursStart !== null && $hoursEnd !== null && bccomp($hoursEnd, $hoursStart, 2) < 0) {
    $fields['engine_hours_at_period_end'] = 'Ending engine hours cannot be less than starting engine hours.';
}

if ($fields) {
    json_validation_error($fields);
}

// --- Generate invoice(s) + audit_log in single transaction (FIX #19) ---
// Revision 2 §9: a spanning monthly lease fans out into one invoice per
// calendar-month segment; everything else is a single invoice. The whole
// batch (every segment + every audit_log row) commits or rolls back together
// via the db_transaction nesting guard (§11 Atomic batch).
$generator = new \FleetForge\Billing\InvoiceGenerator();
$batch     = null;

db_transaction(function () use (
    $generator, $leaseId, $periodStart, $periodEnd, $billingType, $invoiceType, $body,
    $odoStart, $odoEnd, $odoSource, $odoFetchedAt, $hoursStart, $hoursEnd, $allowOverlap, $lease,
    &$batch
) {
    $batch = $generator->generateForLease([
        'lease_id'          => $leaseId,
        'period_start'      => $periodStart,
        'period_end'        => $periodEnd,
        'billing_type'      => $billingType,
        'invoice_type'      => $invoiceType,
        'allow_overlap'     => $allowOverlap,
        // R2 §3.6: the in-order month picker bills exactly ONE calendar-month
        // segment (the submitted period) instead of fanning out to the extent.
        'single_segment'    => !empty($body['single_segment']),
        'po_number'         => clean_string($body['po_number'] ?? null),
        'notes'             => clean_string($body['notes'] ?? null, 2000),
        'internal_notes'    => clean_string($body['internal_notes'] ?? null, 2000),
        'created_by'        => current_user_id(),
        'generation_source' => 'manual',
        // SAMSARA-3 — applied to the final segment only by the orchestrator.
        'odometer_at_period_start_km' => $odoStart,
        'odometer_at_period_end_km'   => $odoEnd,
        'odometer_source'             => $odoSource,
        'odometer_fetched_at'         => $odoFetchedAt,
        // S-LEASE-HOURLY-BILLING — manual engine hours (final segment only).
        'engine_hours_at_period_start' => $hoursStart,
        'engine_hours_at_period_end'   => $hoursEnd,
    ]);

    $companyName = $lease['company_name_snapshot'] ?? 'customer';
    foreach ($batch['invoices'] as $inv) {
        // Audit log inside same transaction (FIX #19) — one row per invoice.
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'System',
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $inv['invoice_id'],
            'entity_label' => $inv['invoice_number'],
            'notes'        => "Invoice {$inv['invoice_number']} created for lease #{$leaseId}",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        // ── In-app notification (NOTIF-1) ──────────────────────
        try {
            \FleetForge\Notifications\NotificationService::notify(
                type:       'invoice.created',
                title:      "New invoice {$inv['invoice_number']}",
                message:    "Invoice {$inv['invoice_number']} created for {$companyName} — \$" . number_format((float) $inv['total_amount'], 2),
                entityType: 'invoice',
                entityId:   (int) $inv['invoice_id'],
                url:        '/fleetforge/invoices/show?id=' . $inv['invoice_id']
            );
        } catch (\Throwable $e) {
            error_log('[NOTIF invoice.created] ' . $e->getMessage());
        }
    }
});

// The "primary" invoice for the legacy single-invoice response shape is the
// first (earliest) one created; the full set is returned under `invoices`.
$result = $batch['invoices'][0];

// S-ACCT-POS: derive POS province from the customer + transaction date and
// attach an informational warning when the customer is out of the default
// province. This does NOT block invoice creation or override TaxCalculator —
// it's a heads-up surface for the operator. Full POS-driven tax wiring is
// S-ACCT-GST34 scope.
$response = [
    'id'             => $result['invoice_id'],
    'invoice_number' => $result['invoice_number'],
    'total_amount'   => $result['total_amount'],
    'balance_due'    => $result['balance_due'],
    // Revision 2 §9: a spanning monthly lease produces a SEQUENCE of invoices
    // in one generation. `invoices` is always present (length 1 for the common
    // single-invoice case) so the form can summarise the whole batch.
    'fanned'         => (bool) $batch['fanned'],
    'invoice_count'  => (int) $batch['count'],
    'invoices'       => array_map(static fn ($inv) => [
        'id'             => $inv['invoice_id'],
        'invoice_number' => $inv['invoice_number'],
        'total_amount'   => $inv['total_amount'],
        'balance_due'    => $inv['balance_due'],
    ], $batch['invoices']),
];

try {
    $pos = \FleetForge\Accounting\PlaceOfSupplyService::resolve([
        'transaction_type' => 'short_lease',
        'transaction_date' => $periodStart,
        'customer_id'      => (int) $lease['customer_id'],
    ]);
    if ($pos['is_out_of_province']) {
        $response['pos_warning'] = [
            'message' => "Customer place of supply derived as {$pos['resolved_province']}. "
                       . 'Correct tax rates for short-lease have been applied per spec §23.6.',
            'resolved_province' => $pos['resolved_province'],
            'applied_rates'     => $pos['applicable_rates'],
            'derivation_trail'  => $pos['derivation_trail'],
        ];
    }
} catch (\Throwable $e) {
    // POS warning is non-critical — log + continue.
    error_log('[POS-warning] ' . $e->getMessage());
}

json_success($response, 201);
