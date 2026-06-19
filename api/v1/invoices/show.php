<?php
declare(strict_types=1);

/**
 * api/v1/invoices/show.php
 *
 * Returns full invoice detail including line items.
 * Strips pdf_path from output (Trap 7: never expose server paths).
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 invoice + line_items | 404 NOT_FOUND
 *
 * Decisions: D5 (soft-delete), Trap 7 (no pdf_path)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$invoice = db_row(
    "SELECT
        i.id,
        i.invoice_number,
        i.invoice_type,
        i.customer_id,
        i.lease_id,
        i.billing_period_id,
        i.customer_name_snapshot,
        i.company_name_snapshot,
        i.contract_number_snapshot,
        i.unit_number_invoice_snapshot,
        i.billing_address_snapshot,
        i.customer_email_snapshot,
        i.gst_exempt_snapshot,
        i.pst_exempt_snapshot,
        i.tax_exempt_snapshot,
        i.po_number,
        i.currency,
        i.exchange_rate_to_cad,
        i.billing_period_start,
        i.billing_period_end,
        i.billing_period_days,
        i.billing_type,
        i.rate_method_used,
        i.rate_method_explanation,
        i.invoice_date,
        i.due_date,
        i.paid_date,
        i.sent_date,
        i.voided_date,
        i.status,
        i.subtotal,
        i.discount_type,
        i.discount_value,
        i.discount_amount,
        i.subtotal_after_discount,
        i.tax_gst_rate,
        i.tax_pst_rate,
        i.tax_hst_rate,
        i.tax_gst_amount,
        i.tax_pst_amount,
        i.tax_hst_amount,
        i.tax_total,
        i.total_amount,
        i.amount_paid,
        i.credits_applied,
        i.balance_due,
        i.late_fee_applied,
        i.late_fee_amount,
        i.notes,
        i.internal_notes,
        i.void_reason,
        i.auto_generated,
        i.generation_source,
        i.delivery_method,
        i.sent_to_email,
        i.created_by,
        i.created_at,
        i.updated_at
     FROM invoices i
     WHERE i.id = ? AND i.deleted_at IS NULL",
    [$id]
);

if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// Fetch line items
$lineItems = db_select(
    "SELECT
        id,
        sort_order,
        item_type,
        description,
        detail_lines,
        quantity,
        unit,
        unit_price,
        amount,
        is_credit,
        taxable,
        tax_gst_amount,
        tax_pst_amount,
        tax_hst_amount,
        mileage_distance,
        mileage_unit,
        mileage_rate,
        mileage_estimated,
        mileage_actual,
        billing_days,
        rate_method,
        period_start,
        period_end,
        created_at
     FROM invoice_line_items
     WHERE invoice_id = ?
     ORDER BY sort_order ASC",
    [$id]
);

// Decode JSON fields
if ($invoice['rate_method_explanation']) {
    $invoice['rate_method_explanation'] = json_decode($invoice['rate_method_explanation'], true);
}
foreach ($lineItems as &$item) {
    if ($item['detail_lines']) {
        $item['detail_lines'] = json_decode($item['detail_lines'], true);
    }
}
unset($item);

$invoice['line_items'] = $lineItems;

// I03: dispatchers (invoices:view, payments:NONE) see the operational invoice —
// number, status, dates, customer/unit, line-item descriptions — but NOT dollar
// amounts (the documented "status + dates only — no amounts" contract). Strip the
// invoice-level money fields and the per-line dollar columns; keep descriptions,
// item types, periods, and tax/discount RATES (not amounts) for context.
if (!can_view_financials()) {
    $invoice = redact_keys($invoice, [
        'subtotal', 'discount_value', 'discount_amount', 'subtotal_after_discount',
        'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount', 'tax_total',
        'total_amount', 'amount_paid', 'credits_applied', 'balance_due',
        'late_fee_amount',
    ]);
    if (isset($invoice['line_items']) && is_array($invoice['line_items'])) {
        $invoice['line_items'] = redact_rows($invoice['line_items'], [
            'unit_price', 'amount',
            'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount', 'mileage_rate',
        ]);
    }
}

json_success($invoice);
