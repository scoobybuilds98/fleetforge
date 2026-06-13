<?php
declare(strict_types=1);

/**
 * scripts/billing_rate_tier_backfill_2026_06_13.php
 *
 * One-shot remediation of a rate-tier hole on six hand-entered demo leases
 * (LSE-2026-00001..00006, created 2026-06-07) that carried monthly_rate > 0 with
 * daily_rate = 0 AND weekly_rate = 0 — the exact D132 hole the earlier
 * scripts/billing_rate_fix_2026_05_06.php remediated for the weekly tier.
 *
 * Downstream symptom (caught by tests/_smoke_billing_invariants.php):
 *   - I2: the six leases violate D132 (a monthly lease with any tier > 0 must
 *     have all of daily/weekly/monthly > 0).
 *   - I1: lease 2618's partial_end period went through the holistic engine,
 *     which prorates partials off the daily/weekly tiers. With both at 0 it
 *     computed cumulative rental = $0.00 ("lease day 181 cumulative $0.00 vs
 *     already billed $24000.00") and emitted a bogus base_rental_reconciliation_
 *     credit that zeroed the draft's subtotal (INV-2026-00062, $0).
 *
 * FIX (data-only; these leases were typed in via the UI, not produced by a seed
 * script, so there is no generator to correct):
 *   1. Backfill the zero tiers using the same convention as the 2026-05-06
 *      remediation: weekly_rate = monthly_rate / 4.33 (52 weeks ÷ 12 months);
 *      daily_rate = monthly_rate / 30 (consistent with the engine's
 *      "30+ days: full months + remainder × monthly/30" path). Only zero tiers
 *      are filled — any populated tier is preserved.
 *   2. Soft-delete the stale $0 drafts whose subtotal was zeroed by a
 *      base_rental_reconciliation_credit produced under the zero-rate math.
 *      They are drafts (never sent, no payments, no GL/QBO impact) and can be
 *      regenerated correctly now that the tiers are complete.
 *
 * Idempotent: re-running finds nothing to fix. Each write carries an audit_log
 * row (retroactive trail, matching the D-H precedent). Local dev DB only.
 *
 * Usage:  php scripts/billing_rate_tier_backfill_2026_06_13.php [--execute]
 *         (default is a dry-run that writes nothing)
 */

require_once dirname(__DIR__) . '/config/app.php';

$execute = in_array('--execute', $argv, true);
$mode    = $execute ? 'EXECUTE' : 'DRY-RUN';

$actorId   = (int) (db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                            WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1")['id'] ?? 0);
$actorName = 'rate-tier backfill (2026-06-13)';

echo "billing_rate_tier_backfill_2026_06_13  [{$mode}]\n";
echo str_repeat('─', 72) . "\n";

// ── 1. Leases with a monthly rate but a zero daily and/or weekly tier ────────
$leases = db_select(
    "SELECT id, contract_number, daily_rate, weekly_rate, monthly_rate
       FROM leases
      WHERE deleted_at IS NULL
        AND billing_cycle = 'monthly'
        AND monthly_rate > 0
        AND (daily_rate = 0 OR weekly_rate = 0)
      ORDER BY id"
);

echo count($leases) . " lease(s) with a rate-tier hole:\n";
foreach ($leases as $l) {
    $monthly = (string) $l['monthly_rate'];
    $newDaily  = bccomp((string) $l['daily_rate'], '0', 2) === 0
        ? bcround(bcdiv($monthly, '30', 6), 2) : (string) $l['daily_rate'];
    $newWeekly = bccomp((string) $l['weekly_rate'], '0', 2) === 0
        ? bcround(bcdiv($monthly, '4.33', 6), 2) : (string) $l['weekly_rate'];

    printf("  lease %d %-16s monthly=%s  daily %s→%s  weekly %s→%s\n",
        $l['id'], $l['contract_number'], $monthly,
        (string) $l['daily_rate'], $newDaily, (string) $l['weekly_rate'], $newWeekly);

    if ($execute) {
        db_transaction(function () use ($l, $newDaily, $newWeekly, $actorId, $actorName) {
            db_update('leases',
                ['daily_rate' => $newDaily, 'weekly_rate' => $newWeekly, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $l['id']]);
            db_insert('audit_log', [
                'user_id'      => $actorId,
                'user_name'    => $actorName,
                'action'       => 'update',
                'module'       => 'leases',
                'entity_type'  => 'lease',
                'entity_id'    => (int) $l['id'],
                'entity_label' => $l['contract_number'],
                'old_values'   => json_encode(['daily_rate' => $l['daily_rate'], 'weekly_rate' => $l['weekly_rate']]),
                'new_values'   => json_encode(['daily_rate' => $newDaily, 'weekly_rate' => $newWeekly]),
                'notes'        => 'D132 rate-tier backfill: filled zero daily/weekly from monthly (daily=monthly/30, weekly=monthly/4.33).',
                'ip_address'   => '127.0.0.1',
            ]);
        });
    }
}

// ── 2. Stale $0 drafts zeroed by a reconciliation credit under the hole ──────
$drafts = db_select(
    "SELECT i.id, i.invoice_number, i.lease_id
       FROM invoices i
      WHERE i.status = 'draft'
        AND i.deleted_at IS NULL
        AND i.subtotal = 0
        AND EXISTS (
            SELECT 1 FROM invoice_line_items li
            WHERE li.invoice_id = i.id
              AND li.item_type = 'base_rental_reconciliation_credit'
        )
      ORDER BY i.id"
);

echo "\n" . count($drafts) . " stale \$0 reconciliation draft(s) to soft-delete:\n";
foreach ($drafts as $d) {
    printf("  invoice %d %-16s lease=%s\n", $d['id'], $d['invoice_number'], (string) $d['lease_id']);
    if ($execute) {
        db_transaction(function () use ($d, $actorId, $actorName) {
            db_update('invoices',
                ['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $d['id']]);
            db_insert('audit_log', [
                'user_id'      => $actorId,
                'user_name'    => $actorName,
                'action'       => 'delete',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => (int) $d['id'],
                'entity_label' => $d['invoice_number'],
                'notes'        => 'Soft-deleted stale $0 draft: subtotal zeroed by a base_rental_reconciliation_credit computed under the zero-rate hole; regenerate after the rate-tier backfill if still needed.',
                'ip_address'   => '127.0.0.1',
            ]);
        });
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
if ($execute) {
    echo "DONE — backfilled " . count($leases) . " lease(s), soft-deleted " . count($drafts) . " draft(s).\n";
} else {
    echo "DRY-RUN — nothing written. Re-run with --execute to apply.\n";
}
