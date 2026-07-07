<?php
declare(strict_types=1);

/**
 * scripts/billing_rate_tier_backfill_generic.php
 *
 * Generalized D132 rate-tier-hole remediation (successor to the one-shot
 * scripts/billing_rate_tier_backfill_2026_06_13.php, which hardcoded
 * LSE-2026-00001..00006). Finds EVERY non-deleted monthly-cycle lease where
 * monthly_rate > 0 but daily_rate = 0 and/or weekly_rate = 0 — the exact hole
 * tests/_smoke_billing_invariants.php I2 flags — and fills the zero tiers
 * using the locked convention:
 *
 *   daily_rate  = monthly_rate / 30     (engine's month-proration basis)
 *   weekly_rate = monthly_rate / 4.33   (52 weeks ÷ 12 months)
 *
 * WHY: the holistic engine prorates partial periods off the daily/weekly
 * tiers; with both at 0 a partial period computes cumulative rental $0.00 and
 * can emit a bogus base_rental_reconciliation_credit that zeroes the draft
 * (the INV-2026-00062 symptom). Lease create enforces completeness (D132),
 * but hand-entered/imported/seeded rows can bypass it.
 *
 * Idempotent (only touches rows currently holding the hole), audit-logged per
 * lease, DRY-RUN by default — pass --apply to write.
 *
 * Run: php scripts/billing_rate_tier_backfill_generic.php [--apply]
 *
 * @session S-CLOSE-NO-ESTIMATE (surfaced by the I2 invariant while fixing the
 *          Mander close-time mileage reports)
 */

require_once dirname(__DIR__) . '/config/app.php';

$apply = in_array('--apply', $argv, true);

$rows = db_select(
    "SELECT id, contract_number, status, daily_rate, weekly_rate, monthly_rate
       FROM leases
      WHERE deleted_at IS NULL
        AND billing_cycle = 'monthly'
        AND monthly_rate > 0
        AND (daily_rate = 0 OR weekly_rate = 0)
      ORDER BY id"
);

echo ($apply ? "APPLY" : "DRY-RUN") . " — D132 rate-tier backfill (daily=monthly/30, weekly=monthly/4.33)\n";
echo str_repeat('=', 78) . "\n";

if (!$rows) {
    echo "No monthly-cycle leases with a rate-tier hole. Nothing to do.\n";
    exit(0);
}

foreach ($rows as $l) {
    $monthly = (string) $l['monthly_rate'];
    $daily   = bccomp((string) $l['daily_rate'], '0', 2) === 0
        ? bcround(bcdiv($monthly, '30', 6), 2) : (string) $l['daily_rate'];
    $weekly  = bccomp((string) $l['weekly_rate'], '0', 2) === 0
        ? bcround(bcdiv($monthly, '4.33', 6), 2) : (string) $l['weekly_rate'];

    printf(
        "  id=%-5d %-24s status=%-9s monthly=%-9s daily %s -> %-8s weekly %s -> %s\n",
        $l['id'], $l['contract_number'], $l['status'], $monthly,
        (string) $l['daily_rate'], $daily, (string) $l['weekly_rate'], $weekly
    );

    if (!$apply) {
        continue;
    }

    db_update('leases', [
        'daily_rate'  => $daily,
        'weekly_rate' => $weekly,
    ], 'id = ?', [(int) $l['id']]);

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => (int) $l['id'],
        'entity_label' => $l['contract_number'],
        'notes'        => 'D132 rate-tier backfill (generic): filled zero daily/weekly from monthly (daily=monthly/30, weekly=monthly/4.33).',
        'old_values'   => json_encode(['daily_rate' => (string) $l['daily_rate'], 'weekly_rate' => (string) $l['weekly_rate']]),
        'new_values'   => json_encode(['daily_rate' => $daily, 'weekly_rate' => $weekly]),
        'ip_address'   => '127.0.0.1',
    ]);
}

echo str_repeat('=', 78) . "\n";
echo count($rows) . " lease(s) " . ($apply ? "updated." : "would be updated — re-run with --apply.") . "\n";
exit(0);
