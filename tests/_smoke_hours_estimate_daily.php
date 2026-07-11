<?php
declare(strict_types=1);

/**
 * tests/_smoke_hours_estimate_daily.php
 *
 * S-HOURS-EST-DAILY — estimated daily engine-hours billing + running true-up.
 *
 * The engine-hours parallel of S-MILEAGE-EST-DAILY. A lease carries
 * estimated_engine_hours_per_day (+ hourly_rate). Each recurring invoice bills
 * an ESTIMATE (billable days × per-day × hourly_rate) as a `hours_estimate`
 * line; a running cumulative true-up reconciles that estimate against ACTUAL
 * hours (engine_hours_at_end − engine_hours_at_start, supplied at close via the
 * cumulative_actual_hours param) with a `hours_adjustment` (under-billed →
 * charge) or `hours_credit` (over-billed → refund). Hours have no unit duality
 * and no precharge — simpler than mileage.
 *
 * Scenarios (all hermetic, BEGIN/ROLLBACK):
 *   A  Estimate cadence, no reading: 31-day month × 8 hrs/day × $12 = $2976.00
 *      estimate line, and NO true-up (nothing to reconcile without a reading).
 *   B  Under-estimate → charge: estimate $2976 billed, then a close carrier
 *      (mileage_only) with cumulative_actual_hours = 300 → $3600 target, true-up
 *      = +$624.00 hours_adjustment.
 *   C  Over-estimate → credit: estimate $2976 billed, close carrier with
 *      cumulative_actual_hours = 200 → $2400 target, true-up = −$576.00 hours_credit.
 *   D  Legacy path preserved: a per-day = 0 lease with per-period readings still
 *      bills a `hourly_usage` line (NOT an estimate/true-up), unchanged behavior.
 *   F  QBO line invariant: the estimate line's quantity × unit_price == amount.
 *   G  Final-settlement estimate suppression: a close-generated invoice
 *      (invoice_type=final + cumulative_actual_hours) emits NO stub estimate;
 *      the true-up alone settles the lease to actual.
 *   H  Overflow cap: a full over-estimate credit on a charge-free carrier floors
 *      the subtotal at $0.00 and routes the overflow to a `hours_overpayment`
 *      credit_note; a second settlement then nets a ZERO true-up (the CN counts
 *      as hours already credited in the billed-to-date sum — idempotency).
 *   I  Guard: per-day estimate with no hourly rate throws BillingRateException.
 *
 * Run: php tests/_smoke_hours_estimate_daily.php
 *
 * @session S-HOURS-EST-DAILY
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-HOURS-EST-DAILY — estimated daily engine hours + true-up\n" . str_repeat('=', 72) . "\n";

db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) throw new RuntimeException("missing seed (cust=$cust unit=$unit user=$user)");

    // Reserve a block of invoice numbers so createFromLease never collides.
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 140)]);

    $seq = 0;
    $makeLease = function (array $over) use ($cust, $unit, $user, &$seq): int {
        $seq++;
        return db_insert('leases', array_merge([
            'contract_number' => 'SMOKE-HRSD-' . getmypid() . '-' . $seq,
            'customer_id' => $cust, 'equipment_unit_id' => $unit,
            'start_date' => '2026-05-01', 'status' => 'active',
            'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
            'currency' => 'CAD', 'billing_cycle' => 'monthly',
            'mileage_unit' => 'km', 'mileage_rate' => '0.0000', 'mileage_rate_km' => '0.0000',
            'mileage_tracking_mode' => 'off', 'precharge_enabled' => 0,
            'estimated_mileage_per_day' => '0.00', 'estimated_mileage_per_day_km' => '0.0000',
            'km_to_miles_conversion' => '0.621371', 'miles_to_km_conversion' => '1.609344',
            // hourly defaults: no hourly billing unless overridden.
            'hourly_rate' => null, 'engine_hours_at_start' => null, 'engine_hours_at_end' => null,
            'estimated_engine_hours_per_day' => '0.00',
            'created_by' => $user, 'updated_by' => $user,
        ], $over));
    };
    $gen  = new InvoiceGenerator();
    $line = fn (int $iv, string $type) => db_row(
        "SELECT amount, quantity, unit, unit_price, description FROM invoice_line_items WHERE invoice_id=? AND item_type=?",
        [$iv, $type]
    );

    // ── A — estimate cadence, no reading ──────────────────────────────────
    $lA  = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '100.00']);
    $ivA = $gen->createFromLease([
        'lease_id' => $lA, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $estA = $line($ivA['invoice_id'], 'hours_estimate');
    $adjA = $line($ivA['invoice_id'], 'hours_adjustment');
    $crA  = $line($ivA['invoice_id'], 'hours_credit');
    $usgA = $line($ivA['invoice_id'], 'hourly_usage');
    ck('A1', $estA !== null && $estA['amount'] === '2976.00',
        "31 days × 8 hrs/day × \$12 = \$" . ($estA['amount'] ?? 'none') . " (expect 2976.00)");
    ck('A2', $adjA === null && $crA === null,
        "no true-up without a reading (adj=" . ($adjA ? 'present' : 'none') . " credit=" . ($crA ? 'present' : 'none') . ")");
    ck('A3', $usgA === null, "estimate model suppresses legacy hourly_usage (usage=" . ($usgA ? 'present' : 'none') . ")");
    ck('F1 (invariant)', $estA !== null
        && bccomp(bcround(bcmul((string) $estA['quantity'], (string) $estA['unit_price'], 6), 2), (string) $estA['amount'], 2) === 0,
        "qty×price==amount: {$estA['quantity']}×{$estA['unit_price']} vs {$estA['amount']}");

    // ── B — under-estimate → true-up charge ───────────────────────────────
    $lB = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '0.00']);
    $gen->createFromLease([ // period 1: estimate $2976 billed
        'lease_id' => $lB, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivB = $gen->createFromLease([ // close carrier: actual 300 hrs lifetime
        'lease_id' => $lB, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_hours' => '300',
    ]);
    $adjB  = $line($ivB['invoice_id'], 'hours_adjustment');
    $estB2 = $line($ivB['invoice_id'], 'hours_estimate');
    // target 300×12=3600, billed estimate 2976 → +624
    ck('B1', $adjB !== null && $adjB['amount'] === '624.00',
        "under-estimate true-up = \$" . ($adjB['amount'] ?? 'none') . " (expect +624.00)");
    ck('B2', $estB2 === null, "no estimate line on the mileage_only carrier (est=" . ($estB2 ? 'present' : 'none') . ")");

    // ── C — over-estimate → clean true-up credit on a partial_end final ───
    // The credit lands on an invoice WITH base rental (a partial_end final), so
    // it is NOT capped (base rental absorbs it). A charge-free mileage_only
    // carrier would instead cap the credit → that path is scenario H.
    $lC = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '0.00',
        'actual_return_date' => '2026-05-15', 'status' => 'completed', 'engine_hours_at_end' => '70.00']);
    $gen->createFromLease([ // period 1: May 1-10 (10 days) → 10×8×12 = $960 estimate
        'lease_id' => $lC, 'period_start' => '2026-05-01', 'period_end' => '2026-05-10',
        'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivC = $gen->createFromLease([ // final stub May 11-15 (5 days base rental) + actual 70 hrs
        'lease_id' => $lC, 'period_start' => '2026-05-11', 'period_end' => '2026-05-15',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_hours' => '70',
    ]);
    $crC   = $line($ivC['invoice_id'], 'hours_credit');
    $crCrow = db_row("SELECT is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='hours_credit' LIMIT 1", [$ivC['invoice_id']]);
    $subC  = db_row("SELECT subtotal FROM invoices WHERE id=?", [$ivC['invoice_id']]);
    // target 70×12=840, billed estimate 960 → −120 credit; base rental (5 days)
    // absorbs it so subtotal stays positive (not capped).
    ck('C1', $crC !== null && $crC['amount'] === '120.00' && (int) ($crCrow['is_credit'] ?? 0) === 1,
        "over-estimate true-up credit = \$" . ($crC['amount'] ?? 'none') . " is_credit=1 (expect 120.00)");
    ck('C2', $subC !== null && bccomp((string) $subC['subtotal'], '0', 2) > 0,
        "credit NOT capped — base rental absorbs it (subtotal=" . ($subC['subtotal'] ?? '?') . " > 0)");

    // ── D — legacy per-period hourly_usage preserved (per-day == 0) ────────
    $lD  = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '0.00', 'engine_hours_at_start' => '100.00']);
    $ivD = $gen->createFromLease([
        'lease_id' => $lD, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        'engine_hours_at_period_start' => '100.00', 'engine_hours_at_period_end' => '140.00',
    ]);
    $usgD = $line($ivD['invoice_id'], 'hourly_usage');
    $estD = $line($ivD['invoice_id'], 'hours_estimate');
    ck('D1', $usgD !== null && $usgD['amount'] === '480.00',
        "legacy per-day=0 bills hourly_usage 40 hrs × \$12 = \$" . ($usgD['amount'] ?? 'none') . " (expect 480.00)");
    ck('D2', $estD === null, "legacy lease emits NO estimate line (est=" . ($estD ? 'present' : 'none') . ")");

    // ── G — final-settlement estimate suppression on a partial_end final ──
    $lG = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '0.00',
        'actual_return_date' => '2026-06-05', 'status' => 'completed', 'engine_hours_at_end' => '300.00']);
    $gen->createFromLease([ // interim estimate
        'lease_id' => $lG, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivG = $gen->createFromLease([
        'lease_id' => $lG, 'period_start' => '2026-06-01', 'period_end' => '2026-06-05',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_hours' => '300',
    ]);
    $estG = $line($ivG['invoice_id'], 'hours_estimate');
    $adjG = $line($ivG['invoice_id'], 'hours_adjustment');
    ck('G1', $estG === null, "final invoice suppresses the stub estimate (est=" . ($estG ? 'present' : 'none') . ")");
    ck('G2', $adjG !== null && $adjG['amount'] === '624.00',
        "final true-up = \$" . ($adjG['amount'] ?? 'none') . " (target 3600 − billed 2976 = +624.00)");

    // ── H — overflow cap + idempotency ────────────────────────────────────
    $lH = $makeLease(['hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '0.00']);
    $gen->createFromLease([ // estimate $2976 billed
        'lease_id' => $lH, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivH = $gen->createFromLease([ // actual 0 hrs → full $2976 over-estimate credit on a charge-free carrier
        'lease_id' => $lH, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_hours' => '0',
    ]);
    $crH   = $line($ivH['invoice_id'], 'hours_credit');
    $subH  = db_row("SELECT subtotal, total_amount FROM invoices WHERE id=?", [$ivH['invoice_id']]);
    $cnH   = db_row("SELECT amount, source FROM credit_notes WHERE lease_id=? AND source='hours_overpayment' AND deleted_at IS NULL", [$lH]);
    ck('H1', $subH !== null && bccomp((string) $subH['subtotal'], '0', 2) === 0,
        "subtotal floored at \$0.00 after cap (subtotal=" . ($subH['subtotal'] ?? '?') . ")");
    ck('H2', $crH !== null && $crH['amount'] === '0.00',
        "hours_credit line capped to \$0.00 (line=" . ($crH['amount'] ?? 'none') . ")");
    ck('H3', $cnH !== null && $cnH['amount'] === '2976.00',
        "overflow routed to hours_overpayment CN \$" . ($cnH['amount'] ?? 'none') . " (expect 2976.00)");
    // Idempotency: a SECOND settlement (still actual 0) must net ZERO true-up —
    // the issued CN counts as hours already credited in billed-to-date.
    $ivH2 = $gen->createFromLease([
        'lease_id' => $lH, 'period_start' => '2026-06-02', 'period_end' => '2026-06-02',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_hours' => '0',
    ]);
    $crH2  = $line($ivH2['invoice_id'], 'hours_credit');
    $adjH2 = $line($ivH2['invoice_id'], 'hours_adjustment');
    ck('H4', $crH2 === null && $adjH2 === null,
        "second settlement nets ZERO true-up (credit=" . ($crH2 ? 'present' : 'none') . " adj=" . ($adjH2 ? 'present' : 'none') . ")");

    // ── I — hard guard: per-day estimate with no rate throws ──────────────
    $lI = $makeLease(['hourly_rate' => null, 'estimated_engine_hours_per_day' => '8.00']);
    $threw = false;
    try {
        $gen->createFromLease([
            'lease_id' => $lI, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        ]);
    } catch (BillingRateException $e) {
        $threw = strpos($e->getMessage(), 'estimated engine hours') !== false;
    }
    ck('I1', $threw, "per-day hours with no hourly_rate → BillingRateException");

    // ── J — mixed mileage + hours overflow → TWO per-source CNs (review fix #1) ──
    // A lease billing BOTH estimated mileage AND estimated hours, both fully
    // over-estimated, closes on a charge-free mileage_only carrier. Both credits
    // overflow the $0 cap; each MUST route to its OWN credit_note source so each
    // family's later true-up subtracts only its own overflow (no cross-contamination).
    $lJ = $makeLease([
        'hourly_rate' => '12.0000', 'estimated_engine_hours_per_day' => '8.00', 'engine_hours_at_start' => '0.00',
        'mileage_rate' => '0.5000', 'mileage_rate_km' => '0.5000', 'mileage_rate_miles' => '0.8047',
        'mileage_tracking_mode' => 'manual',
        'estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000',
    ]);
    $gen->createFromLease([ // period 1: mileage estimate $620 + hours estimate $2976
        'lease_id' => $lJ, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivJ = $gen->createFromLease([ // charge-free carrier, both actuals = 0
        'lease_id' => $lJ, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '0', 'cumulative_actual_hours' => '0',
    ]);
    $mCn = db_row("SELECT amount FROM credit_notes WHERE lease_id=? AND source='mileage_overpayment' AND deleted_at IS NULL", [$lJ]);
    $hCn = db_row("SELECT amount FROM credit_notes WHERE lease_id=? AND source='hours_overpayment' AND deleted_at IS NULL", [$lJ]);
    $subJ = db_row("SELECT subtotal FROM invoices WHERE id=?", [$ivJ['invoice_id']]);
    ck('J1', $mCn !== null && $mCn['amount'] === '620.00',
        "mileage overflow → OWN mileage_overpayment CN \$" . ($mCn['amount'] ?? 'none') . " (expect 620.00)");
    ck('J2', $hCn !== null && $hCn['amount'] === '2976.00',
        "hours overflow → OWN hours_overpayment CN \$" . ($hCn['amount'] ?? 'none') . " (expect 2976.00 — NOT consolidated into mileage CN)");
    ck('J3', $subJ !== null && bccomp((string) $subJ['subtotal'], '0', 2) === 0,
        "carrier subtotal floored at \$0.00 (subtotal=" . ($subJ['subtotal'] ?? '?') . ")");

} catch (\Throwable $e) {
    $fail++;
    echo "  \033[31mFATAL\033[0m — " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    db_execute("ROLLBACK");
}

echo str_repeat('=', 72) . "\n";
echo ($fail === 0)
    ? "\033[32mRESULT: ALL {$pass} PASS — estimated engine-hours estimate + true-up bills correctly\033[0m\n"
    : "\033[31mRESULT: {$pass} pass / {$fail} FAIL\033[0m\n";
exit($fail === 0 ? 0 : 1);
