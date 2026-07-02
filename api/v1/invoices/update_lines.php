<?php
declare(strict_types=1);

/**
 * api/v1/invoices/update_lines.php
 *
 * Replace the line items of a DRAFT invoice and recompute its totals.
 *
 * This is the manual draft-invoice editor's write path (S-INVOICE-DRAFT-EDIT).
 * It is the ONE place a draft's money is hand-editable — and only a draft: a
 * `sent`/`paid`/etc. invoice stays immutable (D14/CRA; correct via a credit
 * note). Counters are untouched because denormalized AR (customers/leases) only
 * counts invoices at draft→sent (D45 / project_path_b_counter_semantics), so a
 * draft carries no counter delta to reconcile.
 *
 * Totals are NEVER trusted from the client: the endpoint stores the submitted
 * lines, then derives subtotal/tax/total/balance with InvoiceRecalc::recalc()
 * from the invoice's frozen tax-rate + exemption snapshots. `amount` is the
 * authoritative per-line value (quantity/unit_price are display helpers) so an
 * engine-generated line round-trips exactly when saved unchanged.
 *
 * @method  POST
 * @body    id (required), updated_at (required, D19 optimistic lock),
 *          lines: [{item_type, description, amount, quantity?, unit_price?,
 *                   unit?, is_credit?, taxable?}, ...]
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, updated_at, subtotal, tax_total, total_amount, balance_due }
 *          | 404 NOT_FOUND | 409 STALE_DATA | 422 NOT_DRAFT / ADVANCE_LOCKED / VALIDATION_ERROR
 *
 * @decisions D14 (immutability — draft-only exception), D19 (optimistic lock),
 *            D45 (counters exclude drafts)
 * @session   S-INVOICE-DRAFT-EDIT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Billing\InvoiceRecalc;

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Invoice ID is required.']);
}

$invoice = db_row(
    "SELECT id, status, updated_at, invoice_number, generation_source
       FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// ── DRAFT-ONLY ────────────────────────────────────────────────────────────
// A sent/paid/void invoice is an issued financial record — frozen (D14). Line
// editing is a draft-authoring affordance only.
if ($invoice['status'] !== 'draft') {
    json_error(
        'NOT_DRAFT',
        "Invoice {$invoice['invoice_number']} is {$invoice['status']} and its line items can no longer be edited. "
        . 'Only draft invoices are editable — issue a credit note or adjustment to correct a sent invoice.',
        422
    );
}

// Advance-batch (prepaid) invoices are computed as a locked set at batch
// creation; hand-editing one line would desync the prepaid schedule. Leave
// those to the batch flow.
if (($invoice['generation_source'] ?? '') === 'advance') {
    json_error(
        'ADVANCE_LOCKED',
        'Advance-billing invoices are frozen at batch creation and cannot be line-edited.',
        422
    );
}

// ── D19 optimistic lock ───────────────────────────────────────────────────
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_validation_error(['updated_at' => 'Optimistic lock token is required.']);
}
if (!optimistic_lock_matches($submittedUpdatedAt, $invoice['updated_at'])) {
    json_error(
        'STALE_DATA',
        'This invoice was modified by another user. Refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This invoice was modified by another user. Refresh and try again.']]
    );
}

// ── Validate lines ────────────────────────────────────────────────────────
$ITEM_TYPES = [
    'base_rental', 'mileage_precharge', 'mileage_adjustment', 'mileage_credit',
    'insurance', 'warranty', 'late_fee', 'early_return_credit', 'manual_adjustment',
    'damage', 'discount', 'account_credit_applied', 'other', 'gps', 'mileage_usage',
    'mileage_drawdown_credit', 'base_rental_reconciliation_credit', 'mileage',
    'hourly_usage', 'cartage', 'sweep', 'wash', 'fuel', 'mileage_estimate',
];

$rawLines = $body['lines'] ?? null;
if (!is_array($rawLines)) {
    json_validation_error(['lines' => 'lines must be an array.']);
}
if (count($rawLines) === 0) {
    json_validation_error(['lines' => 'An invoice must have at least one line item.']);
}
if (count($rawLines) > 100) {
    json_validation_error(['lines' => 'An invoice cannot have more than 100 line items.']);
}

$fields = [];
$clean  = [];
foreach ($rawLines as $i => $ln) {
    if (!is_array($ln)) { $fields["lines.$i"] = 'Malformed line.'; continue; }

    $type = clean_string($ln['item_type'] ?? 'manual_adjustment');
    if (!in_array($type, $ITEM_TYPES, true)) {
        $fields["lines.$i.item_type"] = 'Invalid line type.';
    }

    $desc = clean_string($ln['description'] ?? '', 500);
    if ($desc === null || $desc === '') {
        $fields["lines.$i.description"] = 'Description is required.';
    }

    // amount is authoritative (>= 0; direction is carried by is_credit).
    $amount = clean_decimal((string)($ln['amount'] ?? ''));
    if ($amount === null) {
        $fields["lines.$i.amount"] = 'Amount must be a number.';
    } elseif (bccomp($amount, '0', 2) < 0) {
        $fields["lines.$i.amount"] = 'Amount cannot be negative — use the credit toggle for a reduction.';
    }

    // quantity / unit_price are optional display helpers; default qty=1 and
    // unit_price=amount so a flat line round-trips.
    $qtyRaw = $ln['quantity'] ?? null;
    $qty = ($qtyRaw === null || $qtyRaw === '') ? '1.0000' : clean_decimal((string)$qtyRaw);
    if ($qty === null || bccomp($qty, '0', 4) < 0) {
        $fields["lines.$i.quantity"] = 'Quantity cannot be negative.';
        $qty = '1.0000';
    }
    $upRaw = $ln['unit_price'] ?? null;
    $unitPrice = ($upRaw === null || $upRaw === '') ? ($amount ?? '0.00') : clean_decimal((string)$upRaw);
    if ($unitPrice === null) { $unitPrice = $amount ?? '0.00'; }

    $clean[] = [
        'item_type'  => $type ?: 'manual_adjustment',
        'description'=> $desc ?? '',
        'quantity'   => $qty,
        'unit'       => clean_string($ln['unit'] ?? null, 50),
        'unit_price' => $unitPrice,
        'amount'     => $amount ?? '0.00',
        'is_credit'  => !empty($ln['is_credit']) ? 1 : 0,
        'taxable'    => array_key_exists('taxable', $ln) ? (!empty($ln['taxable']) ? 1 : 0) : 1,
        'sort_order' => $i,
    ];
}

if ($fields) {
    json_validation_error($fields);
}

// ── Persist: replace lines, recompute, audit (one transaction) ────────────
$result = db_transaction(function () use ($id, $invoice, $clean) {
    // Capture the pre-edit total for the audit trail.
    $oldTotal = db_row("SELECT total_amount FROM invoices WHERE id = ?", [$id]);

    db_execute("DELETE FROM invoice_line_items WHERE invoice_id = ?", [$id]);

    foreach ($clean as $ln) {
        db_insert('invoice_line_items', [
            'invoice_id' => $id,
            'sort_order' => $ln['sort_order'],
            'item_type'  => $ln['item_type'],
            'description'=> $ln['description'],
            'quantity'   => $ln['quantity'],
            'unit'       => $ln['unit'],
            'unit_price' => $ln['unit_price'],
            'amount'     => $ln['amount'],
            'is_credit'  => $ln['is_credit'],
            'taxable'    => $ln['taxable'],
        ]);
    }

    // Touch updated_by (and bump updated_at) then recompute authoritatively.
    db_update('invoices', ['updated_by' => current_user_id()], 'id = ?', [$id]);
    $totals = InvoiceRecalc::recalc($id);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "Draft invoice {$invoice['invoice_number']} line items edited (" . count($clean) . " line(s)).",
        'old_values'   => json_encode(['total_amount' => $oldTotal['total_amount'] ?? null]),
        'new_values'   => json_encode(['total_amount' => $totals['total_amount']]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $totals;
});

$fresh = db_row("SELECT updated_at FROM invoices WHERE id = ?", [$id]);

json_success([
    'id'           => $id,
    'updated_at'   => $fresh['updated_at'] ?? null,
    'subtotal'     => $result['subtotal'],
    'tax_total'    => $result['tax_total'],
    'total_amount' => $result['total_amount'],
    'balance_due'  => $result['balance_due'],
]);
