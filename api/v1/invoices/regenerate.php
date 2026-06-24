<?php
declare(strict_types=1);

/**
 * api/v1/invoices/regenerate.php
 *
 * Rebuild a DRAFT invoice from its lease's CURRENT state, in place (same invoice
 * number). This is how a lease edit reaches an already-created draft: edit the
 * lease, then regenerate the draft and it picks up the new dates/add-ons/rates.
 *
 * Mechanism (S-INVOICE-DRAFT-EDIT): hard-delete the draft (its lines + billing
 * period + the row), then re-run InvoiceGenerator::createFromLease() for the same
 * period/billing_type with `force_invoice_number` so the SAME number is reused
 * (no gap, no counter bump). The fresh computation reflects current lease state.
 *
 * SAFETY — createFromLease is stateful, so regenerate is scoped to the cases
 * where re-running it is side-effect-safe:
 *   • DRAFT only (sent/paid/void are immutable, D14) + non-advance + has a lease.
 *   • invoice_type = 'regular' (credit notes / late fees aren't lease-regenerable
 *     and a credit-note regen would bump the CN counter).
 *   • Lease must NOT use a mileage precharge: the Model B drawdown mutates
 *     leases.precharge_balance non-idempotently (InvoiceGenerator ~860), so those
 *     are blocked with guidance (void + recreate) rather than risked.
 *   Cartage re-bills correctly (its emit gate is live-aware, S-CARTAGE-VOID-REBILL)
 *   and last_billed_date is GREATEST()-monotonic — both regenerate-safe.
 *
 * Counter-safe: AR counters only fire at draft→send (D45), so a draft carries no
 * delta. The whole operation is one transaction (createFromLease nests cleanly).
 *
 * @method  POST
 * @body    id (required), updated_at (optional, D19 optimistic lock)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, invoice_number, total_amount, updated_at }
 *          | 404 NOT_FOUND | 409 STALE_DATA
 *          | 422 NOT_DRAFT / ADVANCE_LOCKED / NO_LEASE / NOT_REGULAR /
 *                PRECHARGE_REGENERATE_UNSUPPORTED
 *
 * @decisions D14 (draft-only), D15/D20 (gap-free numbering), D45 (counters), D19
 * @session   S-INVOICE-DRAFT-EDIT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Billing\InvoiceGenerator;

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Invoice ID is required.']);
}

$invoice = db_row(
    "SELECT id, lease_id, invoice_number, status, generation_source, invoice_type,
            updated_at, created_at, created_by,
            billing_period_start, billing_period_end, billing_type,
            po_number, notes, internal_notes, total_amount
       FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// ── Eligibility gates ─────────────────────────────────────────────────────
if ($invoice['status'] !== 'draft') {
    json_error('NOT_DRAFT',
        "Invoice {$invoice['invoice_number']} is {$invoice['status']} and can no longer be regenerated. "
        . 'Only draft invoices are regenerable; correct a sent invoice with a credit note.', 422);
}
if (($invoice['generation_source'] ?? '') === 'advance') {
    json_error('ADVANCE_LOCKED',
        'Advance-billing invoices are frozen at batch creation and cannot be regenerated.', 422);
}
if (!$invoice['lease_id']) {
    json_error('NO_LEASE',
        'This invoice is not linked to a lease, so there is nothing to regenerate it from.', 422);
}
if (($invoice['invoice_type'] ?? 'regular') !== 'regular') {
    json_error('NOT_REGULAR',
        'Only regular invoices can be regenerated from the lease. '
        . 'Credit notes and late-fee invoices are managed separately.', 422);
}

$lease = db_row(
    "SELECT id, precharge_enabled FROM leases WHERE id = ? AND deleted_at IS NULL",
    [(int) $invoice['lease_id']]
);
if (!$lease) {
    json_error('NO_LEASE', 'The lease for this invoice no longer exists.', 422);
}
if (!empty($lease['precharge_enabled'])) {
    json_error('PRECHARGE_REGENERATE_UNSUPPORTED',
        'This lease uses a mileage precharge, whose balance is drawn down as invoices are created — '
        . 'regenerating in place could corrupt it. Void this draft and generate a fresh invoice from the lease instead.',
        422);
}

// ── D19 optimistic lock (advisory; disabled app-wide returns true) ────────
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if ($submittedUpdatedAt && !optimistic_lock_matches($submittedUpdatedAt, $invoice['updated_at'])) {
    json_error('STALE_DATA',
        'This invoice was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This invoice was modified by another user. Refresh and try again.']]);
}

// ── Regenerate: delete the draft, recreate with the same number ───────────
$generator = new InvoiceGenerator();
$number    = (string) $invoice['invoice_number'];

$result = db_transaction(function () use ($id, $invoice, $generator, $number) {
    // Explicitly remove the billing-period row first (the FK is ON DELETE SET
    // NULL, which would otherwise orphan it with invoice_id = NULL).
    db_execute("DELETE FROM lease_billing_periods WHERE invoice_id = ?", [$id]);
    db_execute("DELETE FROM invoice_line_items   WHERE invoice_id = ?", [$id]);
    db_execute("DELETE FROM invoices             WHERE id = ?",        [$id]);

    $created = $generator->createFromLease([
        'lease_id'             => (int) $invoice['lease_id'],
        'period_start'         => $invoice['billing_period_start'],
        'period_end'           => $invoice['billing_period_end'],
        'billing_type'         => $invoice['billing_type'],
        'invoice_type'         => 'regular',
        'force_invoice_number' => $number,
        'single_segment'       => true,
        // Preserve the operator-entered metadata + provenance.
        'po_number'            => $invoice['po_number'],
        'notes'                => $invoice['notes'],
        'internal_notes'       => $invoice['internal_notes'],
        'generation_source'    => $invoice['generation_source'] ?: 'manual',
        'created_by'           => current_user_id(),
    ]);

    $newId = (int) $created['invoice_id'];

    // Keep the original creation timestamp — this is the same invoice, re-derived.
    db_update('invoices', ['created_at' => $invoice['created_at']], 'id = ?', [$newId]);

    $newRow = db_row("SELECT total_amount FROM invoices WHERE id = ?", [$newId]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $newId,
        'entity_label' => $number,
        'notes'        => "Draft invoice {$number} regenerated from lease #{$invoice['lease_id']} (current state).",
        'old_values'   => json_encode(['total_amount' => $invoice['total_amount']]),
        'new_values'   => json_encode(['total_amount' => $newRow['total_amount'] ?? null]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $newId, 'invoice_number' => $created['invoice_number'], 'total_amount' => $newRow['total_amount'] ?? null];
});

$fresh = db_row("SELECT updated_at FROM invoices WHERE id = ?", [$result['id']]);

json_success([
    'id'             => $result['id'],
    'invoice_number' => $result['invoice_number'],
    'total_amount'   => $result['total_amount'],
    'updated_at'     => $fresh['updated_at'] ?? null,
]);
