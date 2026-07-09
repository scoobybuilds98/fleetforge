<?php
declare(strict_types=1);

/**
 * api/v1/invoices/delete.php
 *
 * Soft-delete a draft invoice. Non-draft invoices cannot be deleted (D12).
 * Sent/paid invoices must be voided instead. Gap-free numbering preserved (D15).
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('invoices','delete')
 * @returns 200 success | 422 IMMUTABLE_RECORD
 *
 * Decisions: D5 (soft-delete), D12 (immutability), D15 (gap-free numbers)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'delete');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$invoice = db_row(
    "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// Only draft invoices can be deleted — NO super_admin exemption
// (S-AUDIT-LIFECYCLE-1 #3). The old bypass soft-deleted sent/paid invoices
// while leaving the revenue JE posted, the QBO mirror open, and payment/CN
// applications pointing at a tombstone. Matches update.php's D-C posture:
// CRA immutability has no role escape hatch — void (draft/sent) or credit
// note (paid) instead.
if ($invoice['status'] !== 'draft') {
    json_error('IMMUTABLE_RECORD', 'Only draft invoices can be deleted. Use void for sent invoices; paid invoices need a credit note.', 422);
}

// S-ORPHAN-OVERFLOW-CN: an invoice and its auto-created overflow credit note
// (mileage_overpayment / base_rental_reconciliation_overflow) live and die
// together — a stranded active CN poisons every later mileage true-up. An
// already-APPLIED overflow CN blocks the delete instead (the customer spent
// credit that traces to this invoice).
$cnBlockers = \FleetForge\Billing\OverflowCreditNotes::findBlockers($id);
if ($cnBlockers) {
    json_error(
        'CREDIT_NOTE_APPLIED',
        \FleetForge\Billing\OverflowCreditNotes::blockerMessage($cnBlockers, 'delete'),
        422
    );
}

$voidedCns = [];

// FIX #19: audit_log moved inside transaction
try {
db_transaction(function () use ($id, $invoice, &$voidedCns) {
    // S-AUDIT-LIFECYCLE-1 #21: the status gate above ran on an unlocked
    // pre-txn read (D19 locking is off) — a send racing this delete could
    // flip the row to 'sent' before we soft-delete it. Re-check under lock
    // and predicate the write on the pre-read status; 0 rows → abort before
    // any counter is touched (I05 pattern).
    db_row("SELECT id FROM invoices WHERE id = ? FOR UPDATE", [$id]);
    $affected = db_update(
        'invoices',
        ['deleted_at' => date('Y-m-d H:i:s')],
        'id = ? AND deleted_at IS NULL AND status = ?',
        [$id, $invoice['status']]
    );
    if ($affected === 0) {
        throw new \RuntimeException(
            "CONCURRENT_MODIFICATION: invoice {$invoice['invoice_number']} changed status/deleted while this delete was in flight. Refresh and retry."
        );
    }

    // S-AUDIT-LIFECYCLE-1 (closes F33 at the delete site): restore any
    // precharge drawdown this draft consumed before it disappears.
    ff_reverse_precharge_on_invoice_removal(
        $id, current_user_id(), current_user()['name'] ?? 'System', 'deleted'
    );

    // S-FIX-2 Path B: status-aware counter logic.
    //   draft         -> deleted: total_invoiced -= total_amount; OB unchanged
    //   sent/partial/overdue -> deleted (super_admin):
    //                          total_invoiced -= total_amount; OB -= balance_due
    //   paid          -> deleted (super_admin): total_invoiced -= total_amount; OB unchanged
    //                          (OB was already decremented when paid)
    //   void          -> deleted (super_admin): total_invoiced unchanged; OB unchanged
    //                          (void already reversed both counters)
    //   written_off   -> deleted (super_admin): total_invoiced unchanged; OB unchanged
    //                          (writeoff already cleared OB; total_invoiced should
    //                           stay because the invoice was real revenue)
    //
    // Backwards-compat (Phase 0.5 Bug B): void invoices may have stale balance_due
    // if voided BEFORE the S-FIX-2 void.php update. Treat OB delta as 0 whenever
    // status='void' regardless of the balance_due value on the row — this protects
    // pre-fix void rows from a second DEC at delete time.
    $totalAmount = (string) $invoice['total_amount'];
    $balanceDue  = (string) $invoice['balance_due'];
    $status      = $invoice['status'];

    $decTotalInvoiced = $totalAmount;
    $decOb            = $balanceDue;

    if ($status === 'draft') {
        $decOb = '0.00';
    } elseif ($status === 'paid') {
        $decOb = '0.00';
    } elseif ($status === 'void') {
        $decTotalInvoiced = '0.00';
        $decOb            = '0.00';
    } elseif ($status === 'written_off') {
        $decTotalInvoiced = '0.00';
        $decOb            = '0.00';
    }
    // Otherwise sent / partially_paid / overdue → both decrements stand.

    // total_revenue (customers + equipment_units) is booked ONLY at send
    // (send.php), NOT at draft creation. So it must NOT mirror $decTotalInvoiced
    // (which includes drafts): decrementing a never-booked draft would drive
    // total_revenue negative. Reverse revenue only for statuses where it was
    // net-booked and not already reversed — sent / partially_paid / overdue /
    // paid. (void already reversed it; written_off deliberately preserves it.)
    $decRevenue = in_array($status, ['sent', 'partially_paid', 'overdue', 'paid'], true)
        ? $totalAmount
        : '0.00';

    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
            [$decTotalInvoiced, $decOb, $invoice['lease_id']]
        );

        // Walk the billing-coverage anchor back to the latest STILL-LIVE invoice.
        // last_billed_date is monotonic on create (GREATEST in InvoiceGenerator),
        // so soft-deleting the most-recent invoice would otherwise leave it pointing
        // PAST real coverage. Mirrors api/v1/invoices/void.php. close.php already
        // recomputes coverage from live invoices (deleted_at IS NULL), but keep the
        // denormalized anchor honest for reports/other readers. The just-deleted row
        // now has deleted_at set above, so it is excluded. NULL when none remain.
        $cov = db_row(
            "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
               FROM invoices i2
              WHERE i2.lease_id = ?
                AND i2.deleted_at IS NULL
                AND i2.status <> 'void'
                AND i2.billing_period_end IS NOT NULL
              ORDER BY i2.billing_period_end DESC, i2.id DESC
              LIMIT 1",
            [$invoice['lease_id']]
        );
        db_execute(
            "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
            [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $invoice['lease_id']]
        );
    }
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance - ?,
                                  total_revenue       = total_revenue       - ?,
                                  updated_at = NOW() WHERE id = ?",
            [$decOb, $decRevenue, $invoice['customer_id']]
        );
    }
    // Equipment total_revenue follows the same send-booked rule as customers.
    if ($decRevenue !== '0.00' && $invoice['lease_id']) {
        db_execute(
            "UPDATE equipment_units eu
               JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                SET eu.total_revenue = eu.total_revenue - ?, eu.updated_at = NOW()",
            [$invoice['lease_id'], $decRevenue]
        );
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'delete',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "Invoice {$invoice['invoice_number']} soft-deleted (was {$status}). Counter delta: total_invoiced -= {$decTotalInvoiced}, outstanding_balance -= {$decOb} (Path B).",
        'old_values'   => json_encode(['status' => $status, 'balance_due' => $balanceDue]),
        'new_values'   => json_encode(['deleted_at' => date('Y-m-d H:i:s')]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // S-ORPHAN-OVERFLOW-CN: void the invoice's auto-created overflow CNs in
    // the SAME transaction (unapplied-only; blockers were refused above).
    $voidedCns = \FleetForge\Billing\OverflowCreditNotes::voidForInvoice(
        $id, current_user_id(), current_user()['name'] ?? 'System',
        "source invoice {$invoice['invoice_number']} deleted"
    );
});
} catch (\RuntimeException $e) {
    if (str_starts_with($e->getMessage(), 'CONCURRENT_MODIFICATION')) {
        json_error('CONCURRENT_MODIFICATION', substr($e->getMessage(), strlen('CONCURRENT_MODIFICATION: ')), 409);
    }
    throw $e;
}

// Best-effort QBO void propagation AFTER commit (D-ENQUEUER-CONTRACT).
foreach ($voidedCns as $cn) {
    \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cn['id'], 'void');
}

invalidate_dashboard_cache();

json_success(['id' => $id, 'deleted' => true]);
