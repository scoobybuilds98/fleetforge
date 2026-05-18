<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ap-payments/show.php
 *
 * Fetch a single AP payment with its allocations and linked journal entry.
 *
 * @method  GET
 * @query   id  (required)
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { ap_payment, allocations[], linked_je }
 *          404 if not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §15 (subledger drill-down)
 * Session: S-ACCT-FIX-AP
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('NOT_FOUND', 'Payment ID is required.', 404);
}

$payment = db_row(
    "SELECT ap.*, v.name AS vendor_name,
            ba.name AS bank_account_name,
            u.name AS created_by_name,
            uv.name AS voided_by_name
       FROM acc_ap_payments ap
       JOIN vendors v ON v.id = ap.vendor_id
       JOIN acc_bank_accounts ba ON ba.id = ap.bank_account_id
  LEFT JOIN users u ON u.id = ap.created_by
  LEFT JOIN users uv ON uv.id = ap.voided_by
      WHERE ap.id = ?",
    [$id]
);

if (!$payment) {
    json_error('NOT_FOUND', 'AP payment not found.', 404);
}

$allocations = db_select(
    "SELECT apa.id, apa.bill_id, apa.amount_applied, apa.created_at,
            b.bill_number, b.bill_date, b.total_amount, b.balance_due, b.status AS bill_status
       FROM acc_ap_payment_allocations apa
       JOIN acc_bills b ON b.id = apa.bill_id
      WHERE apa.ap_payment_id = ?
      ORDER BY apa.id ASC",
    [$id]
);

$linkedJe = null;
if (!empty($payment['journal_entry_id'])) {
    $linkedJe = db_row(
        "SELECT id, entry_number, entry_date, status, description, is_reversal, reversed_by_id
           FROM acc_journal_entries WHERE id = ?",
        [(int) $payment['journal_entry_id']]
    );
}

json_success([
    'ap_payment'  => $payment,
    'allocations' => $allocations,
    'linked_je'   => $linkedJe,
]);
