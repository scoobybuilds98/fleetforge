<?php
/**
 * scripts/fix_mtts68_2026_06_23.php
 *
 * One-time cleanup for lease MTTS68 (id 74). A reopen→reclose cycle was run twice
 * with the close modal's DEFAULT return date (today, 2026-06-23) instead of the real
 * return (2025-07-25), producing two bogus DRAFT invoices:
 *   - INV-2026-00166  partial_end 2025-07-26 → 2026-06-23  $6,771.78  (≈11 months)
 *   - INV-2026-00167  adjustment  2026-06-23              $12.08      (mileage + stray GPS)
 * The correct 8-day rental invoice INV-2026-00150 ($368.40, 2025-07-18→07-25) is kept.
 *
 * Predicate: void every DRAFT invoice on the lease whose billing_period_start is AFTER
 * the real return date 2025-07-25 — that is exactly the set created by the wrong-date
 * closes; INV-2026-00150 (starts 07-18) is untouched. Then reset the lease's
 * actual_return_date back to 2025-07-25. Mileage is intentionally NOT re-billed here
 * (a $10.50 line is not worth re-introducing risk — add it as a deliberate step later).
 *
 * Reuses the tested adv_void_invoice() primitive (Path-B counter reversal + auto-JE).
 * DRY RUN by default. Idempotent: re-running finds nothing once applied.
 *
 * USAGE (operator, on prod):
 *   php scripts/fix_mtts68_2026_06_23.php                       # dry run
 *   php scripts/fix_mtts68_2026_06_23.php --apply --user-id=1   # execute (Avi=1)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php';

const FIX_LEASE_ID    = 74;
const REAL_RETURN     = '2025-07-25';

$apply  = in_array('--apply', $argv, true);
$userId = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user-id=')) $userId = (int) substr($a, 10);
}
if ($apply && !$userId) {
    fwrite(STDERR, "ERROR: --apply requires --user-id=<adminUserId>.\n");
    exit(2);
}
if ($apply) {
    $u = db_row("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$u) { fwrite(STDERR, "ERROR: user #{$userId} not found.\n"); exit(2); }
    $_SESSION['ff_user'] = ['id' => (int) $u['id'], 'name' => $u['name'], 'role_slug' => 'super_admin', 'permissions' => []];
    echo "Actor: #{$u['id']} {$u['name']}\n";
}
echo ($apply ? "APPLY MODE — changes WILL be committed.\n" : "DRY RUN — no changes (pass --apply to execute).\n");
echo str_repeat('=', 80) . "\n";

$lease = db_row("SELECT id, contract_number, customer_id, status, actual_return_date FROM leases WHERE id = ? AND deleted_at IS NULL", [FIX_LEASE_ID]);
if (!$lease) { fwrite(STDERR, "Lease " . FIX_LEASE_ID . " not found.\n"); exit(1); }
echo "Lease {$lease['contract_number']} (#{$lease['id']}) status={$lease['status']} actual_return_date={$lease['actual_return_date']}\n";

// Draft invoices that start AFTER the real return — the wrong-date-close artifacts.
$bogus = db_select(
    "SELECT id, invoice_number, status, total_amount, balance_due, billing_type,
            billing_period_start, billing_period_end, customer_id, lease_id
       FROM invoices
      WHERE lease_id = ? AND deleted_at IS NULL AND status = 'draft'
        AND billing_period_start > ?
      ORDER BY id",
    [FIX_LEASE_ID, REAL_RETURN]
);

if (!$bogus) {
    echo "No post-return draft invoices to void.\n";
} else {
    foreach ($bogus as $inv) {
        echo sprintf("  VOID %s [%s] %s..%s  \$%s\n",
            $inv['invoice_number'], $inv['billing_type'],
            $inv['billing_period_start'], $inv['billing_period_end'], $inv['total_amount']);
        if ($apply) {
            db_transaction(function () use ($inv, $lease) {
                adv_void_invoice($inv, $lease, 'MTTS68 cleanup: invoice billed past the real return ' . REAL_RETURN . ' (wrong-date reclose artifact).');
            });
        }
    }
}

// Reset the return date to the real one.
echo "  SET actual_return_date {$lease['actual_return_date']} -> " . REAL_RETURN . " (actual_return_time -> NULL, mileage_at_end -> NULL)\n";
if ($apply) {
    db_execute(
        "UPDATE leases SET actual_return_date = ?, actual_return_time = NULL, mileage_at_end = NULL, updated_at = NOW() WHERE id = ?",
        [REAL_RETURN, FIX_LEASE_ID]
    );
}

echo str_repeat('=', 80) . "\n";
// Show the resulting live invoice set.
$live = db_select(
    "SELECT invoice_number, status, billing_type, billing_period_start, billing_period_end, total_amount
       FROM invoices WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY id",
    [FIX_LEASE_ID]
);
echo ($apply ? "AFTER" : "WOULD REMAIN") . " — live invoices on MTTS68:\n";
$sum = '0.00';
foreach ($live as $i) {
    echo sprintf("  %s [%s] %s..%s  \$%s\n", $i['invoice_number'], $i['status'], $i['billing_period_start'], $i['billing_period_end'], $i['total_amount']);
    $sum = bcadd($sum, (string) $i['total_amount'], 2);
}
echo "  TOTAL live: \${$sum}\n";
echo $apply ? "DONE.\n" : "Re-run with --apply --user-id=1 to execute.\n";
