<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/show.php
 *
 * Get a single AP bill with all line items, vendor info, and payment history.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { bill with lines[], payments[], credits[] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$bill = db_row(
    "SELECT b.*, v.name AS vendor_name, v.vendor_type, v.email AS vendor_email,
            p.name AS period_name
     FROM acc_bills b
     JOIN vendors v ON v.id = b.vendor_id AND v.deleted_at IS NULL
     JOIN acc_periods p ON p.id = b.period_id
     WHERE b.id = ?",
    [$id]
);
if (!$bill) json_error('NOT_FOUND', 'Bill not found.', 404);

// Line items with GL account info
$lines = db_select(
    "SELECT bl.*, a.code AS account_code, a.name AS account_name
     FROM acc_bill_lines bl
     JOIN acc_accounts a ON a.id = bl.account_id
     WHERE bl.bill_id = ?
     ORDER BY bl.sort_order, bl.id",
    [$id]
);

// Payment allocations
$payments = db_select(
    "SELECT pa.amount_applied, ap.payment_number, ap.payment_date,
            ap.payment_method, ap.check_number, ap.status AS payment_status
     FROM acc_ap_payment_allocations pa
     JOIN acc_ap_payments ap ON ap.id = pa.ap_payment_id
     WHERE pa.bill_id = ?
     ORDER BY ap.payment_date DESC",
    [$id]
);

// Vendor credit applications
$credits = db_select(
    "SELECT vca.amount_applied, vca.applied_at,
            vc.credit_number, vc.reason AS credit_reason
     FROM acc_vendor_credit_applications vca
     JOIN acc_vendor_credits vc ON vc.id = vca.vendor_credit_id
     WHERE vca.bill_id = ?
     ORDER BY vca.applied_at DESC",
    [$id]
);

$bill['lines'] = $lines;
$bill['payments'] = $payments;
$bill['credits'] = $credits;

// Strip internal fields
unset($bill['internal_notes']);

json_success($bill);
