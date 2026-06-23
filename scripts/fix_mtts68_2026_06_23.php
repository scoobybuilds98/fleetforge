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
// mileage_at_start=0 trap: the manual-mileage bridge (ff_close_manual_mileage_bridge_line)
// skips any lease whose mileage_at_start !== null, deferring to the legacy overage path.
// MTTS68 carries mileage_at_start=0 (a meaningless "not recorded" default for a manual
// trailer with no start reading), which silently suppressed the bridge -> no mileage line.
// Normalise 0/null -> NULL so the bridge applies; never clobber a real start reading.
echo "  SET actual_return_date {$lease['actual_return_date']} -> " . REAL_RETURN
   . "; mileage_tracking_mode {$lease['mileage_tracking_mode']} -> manual; mileage_at_end -> " . MILEAGE_END
   . "; mileage_at_start " . var_export($lease['mileage_at_start'], true) . " -> NULL (if 0/empty, so the bridge applies)\n";
if ($apply) {
    db_execute(
        "UPDATE leases SET actual_return_date = ?, actual_return_time = NULL,
                mileage_tracking_mode = 'manual', mileage_at_end = ?,
                mileage_at_start = IF(COALESCE(mileage_at_start, 0) = 0, NULL, mileage_at_start),
                updated_at = NOW()
          WHERE id = ?",
        [REAL_RETURN, MILEAGE_END, $FIX_LEASE_ID]
    );
    $lease = db_row("SELECT * FROM leases WHERE id = ?", [$FIX_LEASE_ID]); // reload for the bridge
}

// ── 3. Bill the mileage ON THE SAME invoice as the rental ──
// We do NOT emit a second invoice for one period (you can't send two invoices for
// the same rental period). Instead, void the single live rental draft and regenerate
// ONE final invoice for the same period with the mileage line merged in — base rental
// + GPS + mileage on one invoice. This mirrors a normal close, where extra_lines ride
// on the rental final invoice. Safe here: MTTS68 is a single 8-day period, all drafts.
// Aborts if the lease has >1 live rental invoice (a multi-period lease needs per-period
// handling, not a single collapsed invoice).
// Live rental invoice(s) — the period to merge the mileage into.
$liveRentals = db_select(
    "SELECT id, invoice_number, status, total_amount, balance_due, billing_type,
            billing_period_start, billing_period_end, customer_id, lease_id
       FROM invoices
      WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void'
        AND billing_type IN ('partial_start','partial_end','full_month','single_period')
      ORDER BY id",
    [$FIX_LEASE_ID]
);
// Standalone closeout/mileage adjustment invoices to FOLD IN — the wrong "second
// invoice for one period" left by an earlier run (e.g. INV-2026-00168, before the
// merge logic existed). They get voided and their mileage re-billed on the merged invoice.
$liveAdjustments = db_select(
    "SELECT id, invoice_number, status, total_amount, balance_due, billing_type,
            billing_period_start, billing_period_end, customer_id, lease_id
       FROM invoices
      WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void'
        AND billing_type IN ('adjustment','mileage_only')
      ORDER BY id",
    [$FIX_LEASE_ID]
);

// Idempotency: is the mileage ALREADY on the single rental invoice? Then we're done.
$mileageOnRental = (count($liveRentals) === 1) && ((int) db_count(
    "SELECT COUNT(*) FROM invoice_line_items
      WHERE invoice_id = ? AND item_type IN ('mileage_usage','mileage')",
    [$liveRentals[0]['id']]
) > 0);

// In DRY RUN $lease is the un-mutated row; mirror step 2's edits (mode=manual,
// mileage_at_start 0->NULL) so the preview reflects what --apply will actually do.
// priorMileageBilled=false: any existing mileage is voided when we merge.
$bridgeLease = $lease;
if (!$apply) {
    $bridgeLease = array_merge($lease, ['mileage_tracking_mode' => 'manual']);
    if ((int) ($bridgeLease['mileage_at_start'] ?? 0) === 0) {
        $bridgeLease['mileage_at_start'] = null;
    }
}
$bridgeLine = ff_close_manual_mileage_bridge_line($bridgeLease, MILEAGE_END, null, false);

if ($mileageOnRental) {
    echo "  (mileage already on the rental invoice {$liveRentals[0]['invoice_number']} — nothing to do)\n";
} elseif ($bridgeLine === null) {
    echo "  (mileage bridge inapplicable — no mileage line added)\n";
} elseif (count($liveRentals) !== 1) {
    echo "  !! ABORT mileage merge: expected exactly 1 live rental invoice, found " . count($liveRentals)
       . " — manual review needed (multi-period lease shouldn't collapse to one invoice).\n";
} else {
    $r       = $liveRentals[0];
    $toVoid  = array_merge($liveAdjustments, [$r]); // fold standalone adjustments + the rental into one
    $voidStr = implode(', ', array_map(static fn($x) => $x['invoice_number'], $toVoid));
    echo "  MERGE into ONE invoice: void [{$voidStr}] and regenerate "
       . "({$r['billing_period_start']}..{$r['billing_period_end']}) = rental + GPS + {$bridgeLine['description']} (\${$bridgeLine['amount']} +tax)\n";
    if ($apply) {
        $newLabel = null;
        db_transaction(function () use ($toVoid, $r, $lease, $bridgeLine, $FIX_LEASE_ID, &$newLabel) {
            foreach ($toVoid as $inv) {
                adv_void_invoice($inv, $lease, 'MTTS68: merging into one final invoice (no second invoice for one period).');
            }
            $gen = new \FleetForge\Billing\InvoiceGenerator();
            $merged = $gen->createFromLease([
                'lease_id'          => $FIX_LEASE_ID,
                'period_start'      => $r['billing_period_start'],
                'period_end'        => $r['billing_period_end'],
                'billing_type'      => $r['billing_type'],
                'invoice_type'      => 'final',
                'notes'             => 'MTTS68 single final invoice: rental + GPS + mileage (' . MILEAGE_END . ' km, manual).',
                'created_by'        => current_user_id(),
                'auto_generated'    => 1,
                'generation_source' => 'lease_close',
                'extra_lines'       => [$bridgeLine],
            ]);
            $newLabel = $merged['invoice_number'] . ' (#' . $merged['invoice_id'] . ')';
        });
        echo "  -> created {$newLabel} — rental + GPS + mileage on ONE invoice\n";
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
