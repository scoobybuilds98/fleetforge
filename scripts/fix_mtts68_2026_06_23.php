<?php
/**
 * scripts/fix_mtts68_2026_06_23.php
 *
 * One-time cleanup + correct mileage billing for lease MTTS68 (id 74). Two
 * reopen→reclose cycles used the close modal's DEFAULT return date (today,
 * 2026-06-23) instead of the real return (2025-07-25), producing two bogus DRAFT
 * invoices:
 *   - INV-2026-00166  partial_end 2025-07-26 → 2026-06-23  $6,771.78  (≈11 months)
 *   - INV-2026-00167  adjustment  2026-06-23              $12.08      (mileage + stray GPS)
 * The correct 8-day rental INV-2026-00150 ($368.40, 2025-07-18→07-25) is kept.
 *
 * This script (idempotent, DRY RUN by default):
 *   1. Voids every DRAFT invoice on the lease whose billing_period_start is AFTER the
 *      real return 2025-07-25 — exactly the wrong-date-close artifacts (166 + 167).
 *      INV-2026-00150 (starts 07-18) is untouched. Uses tested adv_void_invoice()
 *      (Path-B counter reversal + auto-JE).
 *   2. Resets the lease: actual_return_date → 2025-07-25, mileage_tracking_mode →
 *      manual, mileage_at_end → 175 (so the manual-mileage bridge applies).
 *   3. Bills the mileage ONCE: 175 km × $0.06/km = $10.50 (+tax) on a clean
 *      'adjustment' invoice dated 2025-07-25, via the SAME primitives the close
 *      flow uses (ff_close_manual_mileage_bridge_line + InvoiceGenerator::createFromLease).
 *      Guarded by $priorMileageBilled so re-running never double-bills.
 *
 * USAGE (operator, on prod):
 *   php scripts/fix_mtts68_2026_06_23.php                       # dry run
 *   php scripts/fix_mtts68_2026_06_23.php --apply --user-id=1   # execute (Avi=1)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php';

$FIX_LEASE_ID = 74;
const REAL_RETURN  = '2025-07-25';
const MILEAGE_END  = 175;   // operator-entered actual mileage (km) to bill

// Optional --lease= override (used only for dev-replica testing; defaults to 74).
foreach ($argv as $a) {
    if (str_starts_with($a, '--lease=')) $FIX_LEASE_ID = (int) substr($a, 8);
}

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

$lease = db_row("SELECT * FROM leases WHERE id = ? AND deleted_at IS NULL", [$FIX_LEASE_ID]);
if (!$lease) { fwrite(STDERR, "Lease {$FIX_LEASE_ID} not found.\n"); exit(1); }
echo "Lease {$lease['contract_number']} (#{$lease['id']}) status={$lease['status']} actual_return_date={$lease['actual_return_date']}\n";

// ── 1. Void post-return draft invoices (the wrong-date-close artifacts) ──
$bogus = db_select(
    "SELECT id, invoice_number, status, total_amount, balance_due, billing_type,
            billing_period_start, billing_period_end, customer_id, lease_id
       FROM invoices
      WHERE lease_id = ? AND deleted_at IS NULL AND status = 'draft'
        AND billing_period_start > ?
      ORDER BY id",
    [$FIX_LEASE_ID, REAL_RETURN]
);
if (!$bogus) {
    echo "  (no post-return draft invoices to void)\n";
}
foreach ($bogus as $inv) {
    echo sprintf("  VOID %s [%s] %s..%s  \$%s\n", $inv['invoice_number'], $inv['billing_type'],
        $inv['billing_period_start'], $inv['billing_period_end'], $inv['total_amount']);
    if ($apply) {
        db_transaction(function () use ($inv, $lease) {
            adv_void_invoice($inv, $lease, 'MTTS68 cleanup: invoice billed past the real return ' . REAL_RETURN . ' (wrong-date reclose artifact).');
        });
    }
}

// ── 2. Reset lease return date + put it in manual-mileage mode ──
echo "  SET actual_return_date {$lease['actual_return_date']} -> " . REAL_RETURN
   . "; mileage_tracking_mode {$lease['mileage_tracking_mode']} -> manual; mileage_at_end -> " . MILEAGE_END . "\n";
if ($apply) {
    db_execute(
        "UPDATE leases SET actual_return_date = ?, actual_return_time = NULL,
                mileage_tracking_mode = 'manual', mileage_at_end = ?, updated_at = NOW()
          WHERE id = ?",
        [REAL_RETURN, MILEAGE_END, $FIX_LEASE_ID]
    );
    $lease = db_row("SELECT * FROM leases WHERE id = ?", [$FIX_LEASE_ID]); // reload for the bridge
}

// ── 3. Bill the mileage once: 175 km × rate, on a clean adjustment invoice ──
$priorMileageBilled = (int) db_count(
    "SELECT COUNT(*) FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
      WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
        AND li.item_type IN ('mileage_usage','mileage')",
    [$FIX_LEASE_ID]
) > 0;

$bridgeLine = ff_close_manual_mileage_bridge_line(
    $apply ? $lease : array_merge($lease, ['mileage_tracking_mode' => 'manual']),
    MILEAGE_END, null, $priorMileageBilled
);

if ($priorMileageBilled) {
    echo "  (mileage already billed on a live invoice — skipping, no double-bill)\n";
} elseif ($bridgeLine === null) {
    echo "  (mileage bridge inapplicable — no mileage line added)\n";
} else {
    echo "  ADD mileage invoice: {$bridgeLine['description']} = \${$bridgeLine['amount']} (+tax), adjustment dated " . REAL_RETURN . "\n";
    if ($apply) {
        $gen = new \FleetForge\Billing\InvoiceGenerator();
        $adj = $gen->createFromLease([
            'lease_id'          => $FIX_LEASE_ID,
            'period_start'      => REAL_RETURN,
            'period_end'        => REAL_RETURN,
            'billing_type'      => 'adjustment',
            'invoice_type'      => 'final',
            'notes'             => 'MTTS68 mileage (manual): ' . MILEAGE_END . ' km.',
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
            'extra_lines'       => [$bridgeLine],
        ]);
        echo "  -> created {$adj['invoice_number']} (#{$adj['invoice_id']})\n";
    }
}

echo str_repeat('=', 80) . "\n";
$live = db_select(
    "SELECT invoice_number, status, billing_type, billing_period_start, billing_period_end, total_amount
       FROM invoices WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY id",
    [$FIX_LEASE_ID]
);
echo ($apply ? "AFTER" : "WOULD REMAIN (pre-mileage; apply to generate it)") . " — live invoices:\n";
$sum = '0.00';
foreach ($live as $i) {
    echo sprintf("  %s [%s/%s] %s..%s  \$%s\n", $i['invoice_number'], $i['status'], $i['billing_type'],
        $i['billing_period_start'], $i['billing_period_end'], $i['total_amount']);
    $sum = bcadd($sum, (string) $i['total_amount'], 2);
}
echo "  TOTAL live: \${$sum}\n";
echo $apply ? "DONE.\n" : "Re-run with --apply --user-id=1 to execute.\n";
