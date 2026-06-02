<?php
/**
 * FleetForge Demo — post-process invoices
 *
 * Backdates invoices so the billing history looks realistic:
 *   - invoice_date becomes the billing period end (or close enough)
 *   - due_date = invoice_date + customer payment_terms (30/15/45 days)
 *   - draft → sent when invoice_date is in the past (simulates mailing)
 *   - sent → overdue when past due_date and still unpaid
 *
 * Also updates created_at to match invoice_date so the UI list views
 * show invoices in a logical timeline.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

echo "=== Backdating invoices for demo realism ===\n\n";

$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.billing_period_end, i.customer_id, i.balance_due, i.amount_paid, i.total_amount, i.status,
            c.payment_terms
       FROM invoices i
       JOIN customers c ON c.id = i.customer_id
      WHERE i.deleted_at IS NULL
      ORDER BY i.id",
    []
);

$updated = 0;
foreach ($invoices as $inv) {
    // Invoice date = end of billing period (realistic — carriers bill at period end)
    $invoiceDate = $inv['billing_period_end'];

    // Due days from payment_terms string ("Net 30" → 30)
    $dueDays = 30;
    if ($inv['payment_terms'] && preg_match('/(\d+)/', $inv['payment_terms'], $m)) {
        $dueDays = (int) $m[1];
    }
    $dueDate = date('Y-m-d', strtotime("{$invoiceDate} +{$dueDays} days"));

    // Figure out the new status based on payment state + due date
    $paid    = (float) $inv['amount_paid'];
    $balance = (float) $inv['balance_due'];
    $total   = (float) $inv['total_amount'];

    if ($paid >= $total && $balance <= 0.01) {
        $newStatus = 'paid';
    } elseif ($paid > 0) {
        // Partially paid — if past due and still owing, overdue
        $newStatus = ($dueDate < date('Y-m-d')) ? 'overdue' : 'partially_paid';
    } else {
        // Nothing paid yet
        if ($dueDate < date('Y-m-d')) {
            $newStatus = 'overdue';
        } elseif ($invoiceDate <= date('Y-m-d')) {
            $newStatus = 'sent';       // issued in the past
        } else {
            $newStatus = 'draft';      // still in the future
        }
    }

    db_execute(
        "UPDATE invoices
            SET invoice_date = ?,
                due_date     = ?,
                sent_date    = CASE WHEN ? IN ('sent','partially_paid','paid','overdue') THEN ? ELSE sent_date END,
                status       = ?,
                created_at   = ?,
                updated_at   = ?
          WHERE id = ?",
        [
            $invoiceDate,
            $dueDate,
            $newStatus, $invoiceDate,
            $newStatus,
            $invoiceDate . ' 09:00:00',
            $invoiceDate . ' 09:00:00',
            $inv['id']
        ]
    );
    $updated++;
}

echo "  Updated {$updated} invoices\n";

// Re-count by status
$statuses = db_select("SELECT status, COUNT(*) as n, SUM(total_amount) as total, SUM(balance_due) as balance FROM invoices WHERE deleted_at IS NULL GROUP BY status ORDER BY FIELD(status, 'draft','sent','partially_paid','paid','overdue','void','written_off')", []);
echo "\nPost-backdate invoice distribution:\n";
foreach ($statuses as $s) {
    printf("  %-16s n=%-3d  total=\$%-14s  balance=\$%s\n",
        $s['status'], $s['n'],
        number_format((float)$s['total'], 2),
        number_format((float)$s['balance'], 2)
    );
}

// Refresh customer outstanding_balance counters.
// S-FIX-2 Path B: filter by status (sent/partially_paid/overdue), not
// `balance_due > 0`. The legacy filter inflated OB with draft invoices;
// Path B excludes drafts and voids from the customer-facing AR figure.
db_execute(
    "UPDATE customers c SET
        outstanding_balance = COALESCE((SELECT SUM(balance_due) FROM invoices WHERE customer_id = c.id AND deleted_at IS NULL AND status IN ('sent','partially_paid','overdue')), 0)
      WHERE deleted_at IS NULL",
    []
);

echo "\nCustomer outstanding balances refreshed.\n";
