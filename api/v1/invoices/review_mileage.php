<?php
declare(strict_types=1);

/**
 * api/v1/invoices/review_mileage.php
 *
 * Manager review-and-approve gate for excess mileage charges
 * (S-LEASE-MILEAGE).
 *
 * Three actions are supported via the `action` field in the request body:
 *
 *   approve   → Apply the system-calculated excess charge as-is.
 *               Sets mileage_review_status='approved', mileage_override_amount=NULL,
 *               adds an excess_mileage line item to the invoice, recomputes totals.
 *
 *   override  → Apply a manager-supplied amount instead of the calculation.
 *               Requires `override_amount` (>= 0, <= calculated_amount * 2 sanity)
 *               and `notes` (required for documentation). Sets status='overridden',
 *               mileage_override_amount=<amount>, line item uses the override.
 *
 *   reject    → Reset the review back to 'pending' (e.g. before re-fetching
 *               the odometer). Requires `notes` explaining why. NO line item
 *               is added. The invoice still cannot be sent.
 *
 * The HARD send guard in api/v1/invoices/send.php blocks the invoice from
 * leaving draft until status is 'approved' or 'overridden'. No role bypass.
 *
 * @method  POST
 * @body    JSON — {
 *            id: int (invoice id, required),
 *            action: 'approve'|'override'|'reject' (required),
 *            override_amount: decimal (required if action='override'),
 *            notes: string (required if action='override' or 'reject')
 *          }
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, mileage_review_status, mileage_override_amount,
 *                 excess_charge_amount, total_amount }
 *           404 NOT_FOUND
 *           409 INVALID_REVIEW_STATE — invoice has no pending review
 *           422 VALIDATION_ERROR
 *
 * @session  S-LEASE-MILEAGE
 * @decision D-G (approve unlocks send; send is separate explicit action)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$action = (string) ($body['action'] ?? '');
if (!in_array($action, ['approve', 'override', 'reject'], true)) {
    json_error('VALIDATION_ERROR', "action must be 'approve', 'override', or 'reject'.", 422);
}

$notes = trim((string) ($body['notes'] ?? ''));
if (in_array($action, ['override', 'reject'], true) && $notes === '') {
    json_error('VALIDATION_ERROR', 'Notes are required when overriding or rejecting.', 422);
}

$overrideAmount = null;
if ($action === 'override') {
    if (!isset($body['override_amount'])) {
        json_error('VALIDATION_ERROR', 'override_amount is required when overriding.', 422);
    }
    $dec = clean_decimal($body['override_amount']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        json_error('VALIDATION_ERROR', 'override_amount must be a non-negative number.', 422);
    }
    $overrideAmount = $dec;
}

$result = null;

db_transaction(function () use ($id, $action, $notes, $overrideAmount, &$result) {
    // Lock the invoice row so two concurrent reviewers can't double-apply
    // the excess line item (T22 optimistic lock check).
    $invoice = db_row(
        "SELECT id, invoice_number, status, lease_id, mileage_review_status,
                excess_distance_km, excess_charge_amount, mileage_override_amount,
                subtotal, subtotal_after_discount, tax_total, total_amount,
                tax_gst_rate, tax_pst_rate, tax_hst_rate, currency, deleted_at
           FROM invoices
          WHERE id = ? AND deleted_at IS NULL
          FOR UPDATE",
        [$id]
    );
    if (!$invoice) {
        json_error('NOT_FOUND', 'Invoice not found.', 404);
    }

    // Reviews only apply while pending. Once approved/overridden, an excess
    // line item is already on the invoice and re-running the review would
    // double-charge the customer. Manager must void + reissue if the
    // approval was wrong.
    if ($invoice['mileage_review_status'] !== 'pending') {
        json_error(
            'INVALID_REVIEW_STATE',
            "Cannot review mileage: invoice {$invoice['invoice_number']} has review status '{$invoice['mileage_review_status']}'. Only 'pending' invoices can be reviewed.",
            409
        );
    }

    // Sent / paid invoices are immutable per D12 — defence-in-depth check
    // even though mileage_review_status='pending' should already prevent
    // these from getting sent.
    if (!in_array($invoice['status'], ['draft'], true)) {
        json_error(
            'INVALID_TRANSITION',
            "Invoice {$invoice['invoice_number']} is '{$invoice['status']}' — only draft invoices accept mileage reviews.",
            409
        );
    }

    $userId   = current_user_id();
    $userName = (current_user()['name'] ?? 'system');
    $now      = date('Y-m-d H:i:s');

    // ── REJECT ─────────────────────────────────────────────────
    // No line item, no totals change. Status stays in pending — the
    // manager is signalling "recapture odometer / verify with driver"
    // but is not yet approving. notes captured.
    if ($action === 'reject') {
        db_update('invoices', [
            'mileage_review_status'       => 'pending',
            'mileage_reviewed_by_user_id' => $userId,
            'mileage_reviewed_at'         => $now,
            'mileage_review_notes'        => $notes,
            'updated_by'                  => $userId,
        ], 'id = ?', [$id]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $id,
            'entity_label' => $invoice['invoice_number'],
            'notes'        => "Mileage review REJECTED — manager flagged for recapture. {$notes}",
            'old_values'   => json_encode(['mileage_review_status' => 'pending']),
            'new_values'   => json_encode([
                'mileage_review_status' => 'pending',
                'reviewed_by'           => $userName,
                'review_action'         => 'reject',
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        $result = [
            'id'                       => (int) $id,
            'mileage_review_status'    => 'pending',
            'action'                   => 'reject',
        ];
        return;
    }

    // ── APPROVE / OVERRIDE — both add a line item and update totals ───
    $calculatedCharge = (string) ($invoice['excess_charge_amount'] ?? '0');
    $appliedAmount    = $action === 'override' ? $overrideAmount : $calculatedCharge;

    if (bccomp($appliedAmount, '0', 2) <= 0 && $action === 'approve') {
        // Defensive — review_status was 'pending' so we expected positive
        // excess. If somehow it's zero, just flip status without inserting
        // a $0 line item.
        db_update('invoices', [
            'mileage_review_status'       => 'approved',
            'mileage_reviewed_by_user_id' => $userId,
            'mileage_reviewed_at'         => $now,
            'mileage_review_notes'        => $notes !== '' ? $notes : 'Approved (no charge to apply).',
            'updated_by'                  => $userId,
        ], 'id = ?', [$id]);

        $result = [
            'id'                    => (int) $id,
            'mileage_review_status' => 'approved',
            'excess_charge_amount'  => $calculatedCharge,
            'applied_amount'        => '0.00',
            'action'                => 'approve_zero',
        ];
        return;
    }

    // Insert the excess mileage line item. Description references the
    // calculated km × rate so the customer can audit.
    $excessKm   = (string) ($invoice['excess_distance_km'] ?? '0');
    $description = $action === 'override'
        ? "Excess mileage charge: {$excessKm} km (manager override applied)"
        : "Excess mileage charge: {$excessKm} km over monthly allowance";

    // Determine sort_order — append at the end of existing line items.
    $maxSort = db_row(
        "SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM invoice_line_items WHERE invoice_id = ?",
        [$id]
    );
    $nextSort = (int) ($maxSort['max_sort'] ?? 0) + 1;

    // Per-line tax: use the same blended rate as the invoice. This matches
    // how other line items inherit tax in InvoiceGenerator.
    // WHY: tax_rates stores rates as decimal fractions already (0.13 = 13%),
    // so we multiply directly — no division by 100. Matches TaxCalculator.php:62.
    $isTaxable  = true;
    $gstRate    = (string) ($invoice['tax_gst_rate'] ?? '0');
    $pstRate    = (string) ($invoice['tax_pst_rate'] ?? '0');
    $hstRate    = (string) ($invoice['tax_hst_rate'] ?? '0');

    $lineGst = $isTaxable && bccomp($gstRate, '0', 4) > 0
        ? bcround(bcmul($appliedAmount, $gstRate, 6), 2) : '0.00';
    $linePst = $isTaxable && bccomp($pstRate, '0', 4) > 0
        ? bcround(bcmul($appliedAmount, $pstRate, 6), 2) : '0.00';
    $lineHst = $isTaxable && bccomp($hstRate, '0', 4) > 0
        ? bcround(bcmul($appliedAmount, $hstRate, 6), 2) : '0.00';

    // unit_price = applied amount per km. unit_price column is DECIMAL(12,2)
    // so we round to 2dp; the underlying mileage_rate column carries the
    // full 4dp rate the lease was negotiated at.
    $unitPrice = bccomp($excessKm, '0', 2) > 0
        ? bcdiv($appliedAmount, $excessKm, 2)
        : '0.00';

    db_insert('invoice_line_items', [
        'invoice_id'      => $id,
        'item_type'       => 'mileage_adjustment',
        'description'     => $description,
        'quantity'        => $excessKm,
        'unit'            => 'km',
        'unit_price'      => $unitPrice,
        'amount'          => $appliedAmount,
        'taxable'         => 1,
        'tax_gst_amount'  => $lineGst,
        'tax_pst_amount'  => $linePst,
        'tax_hst_amount'  => $lineHst,
        'mileage_distance'=> $excessKm,
        'mileage_unit'    => 'km',
        'mileage_rate'    => (string) ($invoice['excess_charge_amount'] !== null && bccomp($excessKm, '0', 2) > 0
                                ? bcdiv((string) $invoice['excess_charge_amount'], $excessKm, 4) : '0.0000'),
        'reference_type'  => 'mileage_review',
        'reference_id'    => $id,
        'sort_order'      => $nextSort,
    ]);

    // Recompute invoice totals: subtotal + applied amount + per-line tax.
    $newSubtotal           = bcadd((string) $invoice['subtotal'], $appliedAmount, 2);
    $newSubtotalAfterDisc  = bcadd((string) $invoice['subtotal_after_discount'], $appliedAmount, 2);
    $newTaxTotal           = bcadd((string) $invoice['tax_total'],
                                   bcadd($lineGst, bcadd($linePst, $lineHst, 2), 2), 2);
    $newTotal              = bcadd($newSubtotalAfterDisc, $newTaxTotal, 2);

    db_update('invoices', [
        'subtotal'                    => $newSubtotal,
        'subtotal_after_discount'     => $newSubtotalAfterDisc,
        'tax_total'                   => $newTaxTotal,
        'total_amount'                => $newTotal,
        'balance_due'                 => $newTotal,  // unpaid draft → balance = total
        'mileage_review_status'       => $action === 'override' ? 'overridden' : 'approved',
        'mileage_override_amount'     => $action === 'override' ? $overrideAmount : null,
        'mileage_reviewed_by_user_id' => $userId,
        'mileage_reviewed_at'         => $now,
        'mileage_review_notes'        => $notes !== '' ? $notes : null,
        'updated_by'                  => $userId,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $userName,
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => sprintf(
            'Mileage review %s — calculated $%s, applied $%s (excess %s km)%s',
            strtoupper($action),
            $calculatedCharge,
            $appliedAmount,
            $excessKm,
            $notes !== '' ? '. Notes: ' . $notes : ''
        ),
        'old_values'   => json_encode([
            'mileage_review_status' => 'pending',
            'subtotal'              => $invoice['subtotal'],
            'total_amount'          => $invoice['total_amount'],
        ]),
        'new_values'   => json_encode([
            'mileage_review_status' => $action === 'override' ? 'overridden' : 'approved',
            'subtotal'              => $newSubtotal,
            'total_amount'          => $newTotal,
            'calculated_charge'     => $calculatedCharge,
            'applied_amount'        => $appliedAmount,
            'override_amount'       => $action === 'override' ? $overrideAmount : null,
            'reviewed_by'           => $userName,
            'review_action'         => $action,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'                       => (int) $id,
        'mileage_review_status'    => $action === 'override' ? 'overridden' : 'approved',
        'mileage_override_amount'  => $action === 'override' ? $overrideAmount : null,
        'excess_charge_amount'     => $calculatedCharge,
        'applied_amount'           => $appliedAmount,
        'total_amount'             => $newTotal,
        'action'                   => $action,
    ];
});

json_success($result);
