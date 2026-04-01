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
 *          billing_type, invoice_type, po_number, notes, internal_notes
 * @auth    Session required; require_permission('invoices','create')
 * @returns 201 { id, invoice_number, total_amount, balance_due }
 *
 * Decisions: D14 (inclusive days), D15 (sequential numbers), D16 (bcmath),
 *            D20 (FOR UPDATE on number gen)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

// --- Input validation ---
$body = json_body();

$leaseId = clean_int($body['lease_id'] ?? null);
if (!$leaseId) {
    json_error('VALIDATION_ERROR', 'lease_id is required.', 422);
}

$periodStart = clean_date($body['period_start'] ?? null);
if (!$periodStart) {
    json_error('VALIDATION_ERROR', 'period_start is required (YYYY-MM-DD).', 422);
}

$periodEnd = clean_date($body['period_end'] ?? null);
if (!$periodEnd) {
    json_error('VALIDATION_ERROR', 'period_end is required (YYYY-MM-DD).', 422);
}

// Validate end >= start
if ($periodEnd < $periodStart) {
    json_error('VALIDATION_ERROR', 'period_end must be on or after period_start.', 422);
}

// Verify lease exists and is active
$lease = db_row(
    "SELECT id, status FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}
if (!in_array($lease['status'], ['active', 'completed'])) {
    json_error('LEASE_NOT_ACTIVE', 'Invoices can only be created for active or completed leases.', 409);
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

// --- Generate invoice + audit_log in single transaction (FIX #19) ---
// db_transaction nesting guard ensures InvoiceGenerator's inner db_transaction
// runs in this outer transaction — audit_log and invoice commit/rollback together.
$generator = new \FleetForge\Billing\InvoiceGenerator();
$result    = null;

db_transaction(function () use (
    $generator, $leaseId, $periodStart, $periodEnd, $billingType, $invoiceType, $body, &$result
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
});

json_success([
    'id'             => $result['invoice_id'],
    'invoice_number' => $result['invoice_number'],
    'total_amount'   => $result['total_amount'],
    'balance_due'    => $result['balance_due'],
], 201);
