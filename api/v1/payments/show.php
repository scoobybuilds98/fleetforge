<?php
declare(strict_types=1);

/**
 * api/v1/payments/show.php
 *
 * Fetch a single payment with its allocation detail (which invoices it was applied to).
 * Provides the full picture needed for a payment receipt or detail view.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('payments','view')
 * @returns 200 { payment + allocations[] }
 *
 * Decisions: D5/D13 (soft-delete filter), D12 (permission matrix)
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('payments', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// --- Load payment with customer join ---
$payment = db_row(
    "SELECT
        p.id,
        p.payment_number,
        p.customer_id,
        c.company_name,
        p.amount,
        p.currency,
        p.exchange_rate_to_cad,
        p.amount_in_cad,
        p.payment_method,
        p.reference_number,
        p.bank_name,
        p.check_number,
        p.card_last_four,
        p.payment_date,
        p.received_at,
        p.deposited_date,
        p.cleared_date,
        p.status,
        p.failure_reason,
        p.returned_reason,
        p.returned_date,
        p.overpayment_amount,
        p.overpayment_action,
        p.overpayment_resolved,
        p.refund_amount,
        p.refund_date,
        p.refund_method,
        p.refund_reference,
        p.notes,
        p.internal_notes,
        p.recorded_by,
        p.verified_by,
        p.verified_at,
        p.created_at,
        p.updated_at
     FROM payments p
     LEFT JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
     WHERE p.id = ? AND p.deleted_at IS NULL",
    [$id]
);

if (!$payment) {
    json_error('NOT_FOUND', 'Payment not found.', 404);
}

// --- Load allocation detail (which invoices this payment was applied to) ---
$allocations = db_select(
    "SELECT
        pa.id,
        pa.invoice_id,
        i.invoice_number,
        i.billing_period_start,
        i.billing_period_end,
        i.total_amount      AS invoice_total,
        i.balance_due       AS invoice_balance_due,
        i.status            AS invoice_status,
        pa.amount,
        pa.currency,
        pa.allocation_type,
        pa.notes,
        pa.allocated_by,
        pa.created_at
     FROM payment_allocations pa
     JOIN invoices i ON i.id = pa.invoice_id
     WHERE pa.payment_id = ?
     ORDER BY pa.created_at ASC",
    [$id]
);

$payment['allocations'] = $allocations;

json_success($payment);
