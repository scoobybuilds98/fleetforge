<?php
declare(strict_types=1);

/**
 * scripts/recompute_vendor_total_spent.php
 *
 * Bug #7 drift repair — recompute every vendors.total_spent from the canonical
 * rule in FleetForge\Accounting\VendorSpend:
 *
 *   total_spent = Σ counted AP bills (approved / scheduled / partially_paid /
 *                 paid, CAD via frozen exchange_rate_to_cad when non-CAD)
 *               + Σ completed work orders NOT covered by a counted bill of the
 *                 same vendor (acc_bills.work_order_id)
 *
 * WHY: the old counter was bumped at work-order completion AND again at bill
 * approval for the same cost (double count), and void subtracted with a
 * GREATEST(0, …) clamp, so stored values on any existing deployment can be
 * wrong in either direction. The writers now recompute instead of incrementing;
 * this script repairs history once.
 *
 * Idempotent (a second run reports "nothing to change"), audit-logged per
 * vendor when applied, DRY-RUN by default — pass --apply to write.
 * The GL is NOT touched: work-order completion never posted a journal entry, so
 * the double count lived only in this denormalized counter.
 *
 * Run: php scripts/recompute_vendor_total_spent.php [--apply]
 *
 * @session bug #7 (vendor Total Spent double count, found scripting training videos)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\VendorSpend;

$apply = in_array('--apply', $argv, true);

echo ($apply ? 'APPLY' : 'DRY-RUN') . " — vendors.total_spent recompute (VendorSpend rule)\n";
echo str_repeat('=', 96) . "\n";

// Run the whole repair in one transaction so an apply is all-or-nothing.
$changed = db_transaction(static function () use ($apply): array {
    $rows = VendorSpend::recomputeAll($apply);

    if ($apply) {
        foreach ($rows as $r) {
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'update',
                'module'       => 'maintenance',
                'entity_type'  => 'vendor',
                'entity_id'    => $r['id'],
                'entity_label' => $r['name'],
                'old_values'   => json_encode(['total_spent' => $r['before']]),
                'new_values'   => json_encode(['total_spent' => $r['after']]),
                'notes'        => "total_spent recomputed (bug #7 drift repair): {$r['before']} → {$r['after']} "
                                . "(bills {$r['bills']} + unbilled work orders {$r['unbilled_work_orders']})",
                'ip_address'   => '127.0.0.1',
            ]);
        }
    }

    return $rows;
});

if (!$changed) {
    echo "Every vendor's total_spent already matches the canonical rule. Nothing to change.\n";
    exit(0);
}

printf("%-6s %-34s %14s %14s %14s %16s\n", 'id', 'vendor', 'stored', 'canonical', 'bills', 'unbilled WOs');
echo str_repeat('-', 96) . "\n";
foreach ($changed as $r) {
    printf(
        "%-6d %-34s %14s %14s %14s %16s\n",
        $r['id'], mb_strimwidth($r['name'], 0, 34, '…'), $r['before'], $r['after'], $r['bills'], $r['unbilled_work_orders']
    );
}
echo str_repeat('-', 96) . "\n";
echo count($changed) . ' vendor(s) ' . ($apply ? 'updated.' : 'would change — re-run with --apply to write.') . "\n";
