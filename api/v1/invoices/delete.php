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

// Only draft invoices can be deleted — super_admin may delete any status
if ($invoice['status'] !== 'draft' && !is_super_admin()) {
    json_error('IMMUTABLE_RECORD', 'Only draft invoices can be deleted. Use void for sent invoices.', 422);
}

// FIX #19: audit_log moved inside transaction
db_transaction(function () use ($id, $invoice) {
    // Soft-delete the invoice
    db_update('invoices', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

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
            [$decOb, $decTotalInvoiced, $invoice['customer_id']]
        );
    }
    // total_revenue mirrors $decTotalInvoiced — zero for void/written_off (already reversed)
    if ($decTotalInvoiced !== '0.00' && $invoice['lease_id']) {
        db_execute(
            "UPDATE equipment_units eu
               JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                SET eu.total_revenue = eu.total_revenue - ?, eu.updated_at = NOW()",
            [$invoice['lease_id'], $decTotalInvoiced]
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
});

invalidate_dashboard_cache();

json_success(['id' => $id, 'deleted' => true]);
