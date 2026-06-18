<?php
/**
 * scripts/diagnose_close_overshoot.php
 *
 * READ-ONLY diagnostic for the S-CLOSE-OVERSHOOT bug: completed leases whose
 * NON-advance rental invoices were billed PAST the lease's billable extent
 * (the days between actual_return_date and the over-billed period_end were
 * charged but the unit was already returned).
 *
 * This MUTATES NOTHING. Run it first to see the affected set; then run
 * scripts/remediate_close_overshoot_2026_06_19.php --apply to fix it.
 *
 * Matches the live fix's predicate exactly (lease_billable_extent +
 * status/generation_source/billing_type exclusions from reconcile_overshoot_invoices).
 *
 * USAGE: php scripts/diagnose_close_overshoot.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php'; // lease_billable_extent()

$leases = db_select(
    "SELECT id, contract_number, start_date, start_time, actual_return_date, actual_return_time
       FROM leases
      WHERE status = 'completed'
        AND actual_return_date IS NOT NULL
        AND deleted_at IS NULL
      ORDER BY id",
    []
);

$rows = [];
$totalOverbilled = '0.00';

foreach ($leases as $l) {
    $extent = lease_billable_extent(
        (string) $l['actual_return_date'],
        $l['actual_return_time'] ?? null,
        $l['start_time'] ?? null,
        (string) $l['start_date']
    );

    $overshoot = db_select(
        "SELECT id, invoice_number, status, billing_type,
                billing_period_start, billing_period_end, billing_period_days, total_amount
           FROM invoices
          WHERE lease_id = ?
            AND deleted_at IS NULL
            AND status NOT IN ('void', 'written_off')
            AND (generation_source IS NULL OR generation_source <> 'advance')
            AND billing_type NOT IN ('full_month', 'mileage_only', 'adjustment', 'credit_note')
            AND billing_period_end IS NOT NULL
            AND billing_period_end > ?
            AND NOT EXISTS (
                SELECT 1 FROM credit_notes cn
                 WHERE cn.source_invoice_id = invoices.id
                   AND cn.source = 'invoice_adjustment'
                   AND cn.status <> 'void' AND cn.deleted_at IS NULL
            )
          ORDER BY billing_period_start ASC, id ASC",
        [$l['id'], $extent]
    );

    foreach ($overshoot as $inv) {
        $totalDays  = (int) ((new DateTimeImmutable((string) $inv['billing_period_end']))
                        ->diff(new DateTimeImmutable((string) $inv['billing_period_start']))->days) + 1;
        $billedTo   = (string) $inv['billing_period_end'];
        $overDays   = (int) ((new DateTimeImmutable($billedTo))
                        ->diff(new DateTimeImmutable($extent))->days);
        $est        = $totalDays > 0
            ? bcround(bcmul((string) $inv['total_amount'], bcdiv((string) $overDays, (string) $totalDays, 6), 6), 2)
            : '0.00';
        $totalOverbilled = bcadd($totalOverbilled, $est, 2);
        $rows[] = [
            'lease'      => $l['contract_number'],
            'invoice'    => $inv['invoice_number'],
            'status'     => $inv['status'],
            'type'       => $inv['billing_type'],
            'return'     => $l['actual_return_date'],
            'extent'     => $extent,
            'billed_end' => $billedTo,
            'over_days'  => $overDays,
            'est_overbilled' => $est,
        ];
    }
}

echo "S-CLOSE-OVERSHOOT diagnostic — invoices billed past the lease extent\n";
echo str_repeat('=', 110) . "\n";
if (!$rows) {
    echo "No overshooting invoices found. Nothing to remediate.\n";
    exit(0);
}
printf("%-14s %-18s %-8s %-14s %-12s %-12s %-9s %12s\n",
    'LEASE', 'INVOICE', 'STATUS', 'TYPE', 'RETURN', 'BILLED→', 'OVERDAYS', 'EST $OVERBILL');
echo str_repeat('-', 110) . "\n";
foreach ($rows as $r) {
    printf("%-14s %-18s %-8s %-14s %-12s %-12s %-9d %12s\n",
        $r['lease'], $r['invoice'], $r['status'], $r['type'],
        $r['return'], $r['billed_end'], $r['over_days'], $r['est_overbilled']);
}
echo str_repeat('-', 110) . "\n";
printf("%d invoice(s) across %d lease(s); est. total over-billed ≈ \$%s\n",
    count($rows), count(array_unique(array_column($rows, 'lease'))), $totalOverbilled);
echo "\nDrafts will be voided + regenerated shortened; sent/paid get a prorated credit note.\n";
echo "Run: php scripts/remediate_close_overshoot_2026_06_19.php --apply --user-id=<adminId>\n";
