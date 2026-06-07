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

$billingType = clean_string($body['billing_type'] ?? 'partial_start');
$validBillingTypes = ['partial_start', 'full_month', 'partial_end', 'single_period'];
if (!in_array($billingType, $validBillingTypes)) {
    $billingType = 'partial_start';
}

$invoiceType = clean_string($body['invoice_type'] ?? 'regular');
$validInvoiceTypes = ['regular', 'final', 'mileage_only', 'adjustment'];
if (!in_array($invoiceType, $validInvoiceTypes)) {
    $invoiceType = 'regular';
}

// ── Overlapping-period guard ───────────────────────────────────
// WHY: the holistic running-reconciliation engine is blind to overlap and will
// silently bill only the cumulative base-rental delta when a manual invoice
// re-covers days an existing non-void invoice already billed (e.g. activation
// invoice 06-07..06-30 then a manual 06-07..07-07 yielding a confusing $50
// "31 billing days" line). That is almost always an operator mistake, so block
// it by default. An operator who genuinely intends a reconciliation can resend
// with allow_overlap=true.
//
// Scope: only invoice types that actually emit a base_rental line. mileage_only
// carries NO base_rental (immune to the misleading-top-up bug) and overlapping a
// rental period is the NORMAL case for a mileage reconciliation; 'adjustment'
// invoices likewise legitimately share a period. Skip the guard for those.
$allowOverlap   = !empty($body['allow_overlap']);
$guardThisType  = in_array($invoiceType, ['regular', 'final'], true);
if (!$allowOverlap && $guardThisType) {
    $overlap = \FleetForge\Billing\InvoiceGenerator::findOverlappingInvoice(
        $leaseId, $periodStart, $periodEnd
    );
    if ($overlap) {
        json_error(
            'PERIOD_OVERLAP',
            "This period ({$periodStart} to {$periodEnd}) overlaps invoice {$overlap['invoice_number']} "
            . "({$overlap['billing_period_start']} to {$overlap['billing_period_end']}). "
            . 'Creating it will only bill the reconciliation difference, not the full period. '
            . 'Choose a non-overlapping period, or confirm to reconcile intentionally.',
            409,
            [
                'overlap' => [
                    'invoice_id'           => (int) $overlap['id'],
                    'invoice_number'       => $overlap['invoice_number'],
                    'billing_period_start' => $overlap['billing_period_start'],
                    'billing_period_end'   => $overlap['billing_period_end'],
                ],
                'fields' => ['period_start' => "Overlaps invoice {$overlap['invoice_number']}."],
            ]
        );
    }
}

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

if ($fields) {
    json_validation_error($fields);
}

// --- Generate invoice + audit_log in single transaction (FIX #19) ---
// db_transaction nesting guard ensures InvoiceGenerator's inner db_transaction
// runs in this outer transaction — audit_log and invoice commit/rollback together.
$generator = new \FleetForge\Billing\InvoiceGenerator();
$result    = null;

db_transaction(function () use (
    $generator, $leaseId, $periodStart, $periodEnd, $billingType, $invoiceType, $body,
    $odoStart, $odoEnd, $odoSource, $odoFetchedAt,
    &$result
) {
    $result = $generator->createFromLease([
        'lease_id'          => $leaseId,
        'period_start'      => $periodStart,
        'period_end'        => $periodEnd,
        'billing_type'      => $billingType,
        'invoice_type'      => $invoiceType,
        'po_number'         => clean_string($body['po_number'] ?? null),
        'notes'             => clean_string($body['notes'] ?? null, 2000),
        'internal_notes'    => clean_string($body['internal_notes'] ?? null, 2000),
        'created_by'        => current_user_id(),
        'generation_source' => 'manual',
        // SAMSARA-3
        'odometer_at_period_start_km' => $odoStart,
        'odometer_at_period_end_km'   => $odoEnd,
        'odometer_source'             => $odoSource,
        'odometer_fetched_at'         => $odoFetchedAt,
    ]);

    // Audit log inside same transaction (FIX #19)
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $result['invoice_id'],
        'entity_label' => $result['invoice_number'],
        'notes'        => "Invoice {$result['invoice_number']} created for lease #{$leaseId}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        $companyName = $lease['company_name_snapshot'] ?? 'customer';
        \FleetForge\Notifications\NotificationService::notify(
            type:       'invoice.created',
            title:      "New invoice {$result['invoice_number']}",
            message:    "Invoice {$result['invoice_number']} created for {$companyName} — \$" . number_format((float) $result['total_amount'], 2),
            entityType: 'invoice',
            entityId:   (int) $result['invoice_id'],
            url:        '/fleetforge/invoices/show?id=' . $result['invoice_id']
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF invoice.created] ' . $e->getMessage());
    }
});

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
