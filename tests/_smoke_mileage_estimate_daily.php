<?php
declare(strict_types=1);

/**
 * tests/_smoke_mileage_estimate_daily.php
 *
 * S-MILEAGE-EST-DAILY — estimated daily mileage billing + running true-up.
 *
 * A lease carries estimated_mileage_per_day. Each recurring invoice bills an
 * ESTIMATE (billable days × per-day × mileage_rate) as a `mileage_estimate` line;
 * a running cumulative true-up reconciles that estimate against ACTUAL distance
 * (odometer / Samsara / operator close reading) via a `mileage_adjustment`
 * (under-billed → charge) or `mileage_credit` (over-billed → refund). The rate
 * still comes from the customer rate card (snapshotted onto mileage_rate_km).
 *
 * Scenarios (all hermetic, BEGIN/ROLLBACK — km leases so rate_km == rate):
 *   A  Estimate cadence, no reading: 31-day month × 40 km/day × $0.50 = $620.00
 *      estimate line, and NO true-up (nothing to reconcile without a reading).
 *   B  Under-estimate → charge: estimate $620 billed, then a close carrier
 *      (mileage_only) with cumulative_actual = 1500 km → $750 target, true-up
 *      = +$130.00 mileage_adjustment.
 *   C  Over-estimate → credit: estimate $620 billed, close carrier with
 *      cumulative_actual = 1000 km → $500 target, true-up = −$120.00 mileage_credit.
 *   D  Legacy path preserved: a per-day = 0 lease still bills a distance×rate
 *      `mileage_usage` line (NOT an estimate/true-up), unchanged behavior.
 *   E  Precharge estimate lease: the estimate charge draws down precharge_balance
 *      (mileage_drawdown_credit) and decrements the balance.
 *   F  QBO line invariant: the estimate line's quantity × unit_price == amount.
 *   C2 Overflow idempotency (S-CLOSE-NO-ESTIMATE): a second settlement after a
 *      mileage_overpayment credit_note produces a ZERO true-up — issued CNs
 *      count as mileage already credited in the billed-to-date sum.
 *   G  Final-settlement estimate suppression (S-CLOSE-NO-ESTIMATE): a close-
 *      generated invoice (invoice_type=final + cumulative_actual_km, exactly
 *      what close.php passes) emits NO stub-period estimate; the true-up alone
 *      settles the lease to actual, and its description shows the arithmetic.
 *   H  Regenerate parity (S-CLOSE-NO-ESTIMATE): a COMPLETED manual lease's
 *      stored mileage_at_end feeds the true-up on a final invoice even without
 *      the close.php override (the generateForLease regeneration path).
 *
 * Run: php tests/_smoke_mileage_estimate_daily.php
 *
 * @session S-MILEAGE-EST-DAILY (extended by S-CLOSE-NO-ESTIMATE)
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-MILEAGE-EST-DAILY — estimated daily mileage + true-up\n" . str_repeat('=', 72) . "\n";

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
        ["invoice.next_number.{$yr}", (string) ($maxNum + 120)]);

    $seq = 0;
    $makeLease = function (array $over) use ($cust, $unit, $user, &$seq): int {
        $seq++;
        return db_insert('leases', array_merge([
            'contract_number' => 'SMOKE-ESTD-' . getmypid() . '-' . $seq,
            'customer_id' => $cust, 'equipment_unit_id' => $unit,
            'start_date' => '2026-05-01', 'status' => 'active',
            'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
            'currency' => 'CAD', 'billing_cycle' => 'monthly',
            'mileage_unit' => 'km', 'mileage_rate' => '0.5000', 'mileage_rate_km' => '0.5000',
            'mileage_rate_miles' => '0.8047',
            'mileage_tracking_mode' => 'manual', 'precharge_enabled' => 0,
            'estimated_mileage' => '0', 'estimated_mileage_km' => '0.000',
            'estimated_mileage_per_day' => '0.00', 'estimated_mileage_per_day_km' => '0.0000',
            'km_to_miles_conversion' => '0.621371', 'miles_to_km_conversion' => '1.609344',
            'created_by' => $user, 'updated_by' => $user,
        ], $over));
    };
    $gen = new InvoiceGenerator();
    $line = fn (int $iv, string $type) => db_row(
        "SELECT amount, quantity, unit, unit_price, description FROM invoice_line_items WHERE invoice_id=? AND item_type=?",
        [$iv, $type]
    );

    // ── A — estimate cadence, no reading ──────────────────────────────────
    $lA = $makeLease(['estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000']);
    $ivA = $gen->createFromLease([
        'lease_id' => $lA, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $estA = $line($ivA['invoice_id'], 'mileage_estimate');
    $adjA = $line($ivA['invoice_id'], 'mileage_adjustment');
    $crA  = $line($ivA['invoice_id'], 'mileage_credit');
    ck('A1', $estA !== null && $estA['amount'] === '620.00',
        "31 days × 40 km/day × \$0.50 = \$" . ($estA['amount'] ?? 'none') . " (expect 620.00)");
    ck('A2', $adjA === null && $crA === null,
        "no true-up without a reading (adj=" . ($adjA ? 'present' : 'none') . " credit=" . ($crA ? 'present' : 'none') . ")");
    ck('F1 (invariant)', $estA !== null
        && bccomp(bcround(bcmul((string) $estA['quantity'], (string) $estA['unit_price'], 6), 2), (string) $estA['amount'], 2) === 0,
        "qty×price==amount: {$estA['quantity']}×{$estA['unit_price']} vs {$estA['amount']}");

    // ── B — under-estimate → true-up charge ───────────────────────────────
    $lB = $makeLease(['estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000']);
    $gen->createFromLease([ // period 1: estimate $620 billed
        'lease_id' => $lB, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivB = $gen->createFromLease([ // close carrier: actual 1500 km lifetime
        'lease_id' => $lB, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1500',
    ]);
    $adjB = $line($ivB['invoice_id'], 'mileage_adjustment');
    $estB2 = $line($ivB['invoice_id'], 'mileage_estimate');
    // target 1500×0.5=750, billed estimate 620 → +130
    ck('B1', $adjB !== null && $adjB['amount'] === '130.00',
        "1500 km actual × \$0.50 = \$750 − \$620 estimate = +\$" . ($adjB['amount'] ?? 'none') . " (expect 130.00 charge)");
    ck('B2', $estB2 === null, "carrier invoice emits NO new estimate line (est already billed on rental invoice)");

    // ── C — over-estimate → true-up credit (standalone carrier) ───────────
    // On a carrier invoice with no other charges, the true-up credit exceeds the
    // subtotal (which can't go negative) so the excess is routed to a credit_note
    // — a real customer account credit. This is the existing mileage-overflow
    // machinery (S-FIX-2 Bug #3), now fed by the estimate true-up.
    $lC = $makeLease(['estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000']);
    $gen->createFromLease([
        'lease_id' => $lC, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivC = $gen->createFromLease([
        'lease_id' => $lC, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1000',
    ]);
    // target 1000×0.5=500, billed 620 → −120 credit → routed to a credit_note.
    $cnC = db_row(
        "SELECT amount, source FROM credit_notes WHERE lease_id=? AND source_invoice_id=? AND source='mileage_overpayment'",
        [$lC, $ivC['invoice_id']]
    );
    ck('C1', $cnC !== null && bccomp((string) $cnC['amount'], '120.00', 2) === 0,
        "1000 km actual × \$0.50 = \$500 − \$620 estimate → \$" . ($cnC['amount'] ?? 'none') . " credit_note (expect 120.00 mileage_overpayment)");

    // ── C2 — overflow idempotency: a SECOND settlement invoice after the
    // overflow CN must produce a ZERO true-up (no fresh credit, no new CN).
    // The billed-to-date sum subtracts issued mileage_overpayment credit_notes;
    // without that, every reclose/regenerate re-credited the same overflow.
    $ivC2 = $gen->createFromLease([
        'lease_id' => $lC, 'period_start' => '2026-06-02', 'period_end' => '2026-06-02',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1000',
    ]);
    $crC2  = $line($ivC2['invoice_id'], 'mileage_credit');
    $adjC2 = $line($ivC2['invoice_id'], 'mileage_adjustment');
    $cnCnt = (int) db_row("SELECT COUNT(*) c FROM credit_notes WHERE lease_id=? AND source='mileage_overpayment'", [$lC])['c'];
    ck('C2', $crC2 === null && $adjC2 === null && $cnCnt === 1,
        "re-settlement after overflow CN is idempotent: credit=" . ($crC2 ? 'present' : 'none')
        . " adj=" . ($adjC2 ? 'present' : 'none') . " CN count={$cnCnt} (expect none/none/1)");

    // ── D — legacy per-day = 0 path preserved (distance × rate usage line) ─
    $lD = $makeLease(['estimated_mileage_per_day' => '0.00', 'estimated_mileage_per_day_km' => '0.0000',
                      'odometer_start_km' => '1000.00']);
    $ivD = $gen->createFromLease([
        'lease_id' => $lD, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        'odometer_at_period_start_km' => '1000.00', 'odometer_at_period_end_km' => '1200.00', // 200 km
    ]);
    $useD = $line($ivD['invoice_id'], 'mileage_usage');
    $estD = $line($ivD['invoice_id'], 'mileage_estimate');
    ck('D1', $useD !== null && $useD['amount'] === '100.00' && $estD === null,
        "per-day=0 lease still bills 200 km × \$0.50 = \$" . ($useD['amount'] ?? 'none') . " as mileage_usage, no estimate line");

    // ── E — precharge estimate lease: estimate draws down the balance ─────
    $lE = $makeLease([
        'estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000',
        'precharge_enabled' => 1, 'precharge_amount' => '500.00', 'precharge_balance' => '500.00',
        'precharge_invoiced_at' => '2026-04-30 12:00:00', // invoice 1 precharge already billed
    ]);
    $ivE = $gen->createFromLease([
        'lease_id' => $lE, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $estE  = $line($ivE['invoice_id'], 'mileage_estimate');
    $drawE = $line($ivE['invoice_id'], 'mileage_drawdown_credit');
    $balE  = (string) db_row("SELECT precharge_balance FROM leases WHERE id=?", [$lE])['precharge_balance'];
    ck('E1', $estE !== null && $estE['amount'] === '620.00' && $drawE !== null && $drawE['amount'] === '500.00',
        "estimate \$" . ($estE['amount'] ?? '?') . " draws down \$" . ($drawE['amount'] ?? 'none') . " (min of charge, \$500 balance)");
    ck('E2', bccomp($balE, '0.00', 2) === 0, "precharge_balance decremented to \$$balE (expect 0.00)");

    // ── MILES — the estimate bills in MILES at the per-mile rate ──────────
    // (The portal/show surfaces print the line quantity directly, so the
    // quantity MUST already be in the lease's unit — this locks that.)
    $lM = $makeLease([
        'mileage_unit' => 'miles', 'mileage_rate' => '1.0000',
        'mileage_rate_km' => '0.6214', 'mileage_rate_miles' => '1.0000',
        'estimated_mileage_per_day' => '50.00', 'estimated_mileage_per_day_km' => '80.4672',
        'estimated_mileage_per_day_miles' => '50.0000',
    ]);
    $ivM = $gen->createFromLease([
        'lease_id' => $lM, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $estM = $line($ivM['invoice_id'], 'mileage_estimate');
    $expM = 31 * 50 * 1.0; // 31 days × 50 mi/day × $1.00/mi = $1550
    ck('MILES', $estM !== null && $estM['unit'] === 'miles'
            && bccomp((string) $estM['unit_price'], '1.0000', 4) === 0
            && abs((float) $estM['amount'] - $expM) < 0.10
            && bccomp(bcround(bcmul((string) $estM['quantity'], (string) $estM['unit_price'], 6), 2), (string) $estM['amount'], 2) === 0,
        "miles lease: 31 × 50 mi/day × \$1.00/mi = \$" . ($estM['amount'] ?? 'none') . ", qty=" . ($estM['quantity'] ?? '?') . " " . ($estM['unit'] ?? '?') . " (expect ~1550 miles, qty×price==amount)");

    // ── G — S-CLOSE-NO-ESTIMATE: final invoice with a closing reading skips
    // the estimate line; the true-up alone settles the lease to actual.
    // Mirrors the MTTS403 close: estimates billed on prior months, then the
    // close-generated partial_end (invoice_type=final, cumulative_actual_km
    // passed by close.php) must NOT add a stub-period estimate on top.
    $lG = $makeLease(['estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000']);
    $gen->createFromLease([ // May: estimate $620 billed
        'lease_id' => $lG, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivG = $gen->createFromLease([ // close: Jun 1–2 stub, actual 1300 km lifetime
        'lease_id' => $lG, 'period_start' => '2026-06-01', 'period_end' => '2026-06-02',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1300',
    ]);
    $estG = $line($ivG['invoice_id'], 'mileage_estimate');
    $adjG = $line($ivG['invoice_id'], 'mileage_adjustment');
    // target 1300×0.5=650, billed 620 (May estimate only — no stub estimate) → +30
    ck('G1', $estG === null, "final invoice with closing reading emits NO stub-period estimate line");
    ck('G2', $adjG !== null && $adjG['amount'] === '30.00',
        "true-up settles to actual: 1300 km × \$0.50 = \$650 − \$620 billed = +\$" . ($adjG['amount'] ?? 'none') . " (expect 30.00)");
    ck('G3', $adjG !== null && str_contains((string) $adjG['description'], '650.00')
            && str_contains((string) $adjG['description'], '620.00'),
        "true-up description shows the arithmetic (\$650.00 target, \$620.00 billed): " . ($adjG['description'] ?? 'none'));

    // ── H — regenerate parity: COMPLETED manual lease's mileage_at_end feeds
    // the true-up on a final invoice even when no override is passed (the
    // operator "regenerate invoices after close" path goes through
    // generateForLease, which never passes cumulative_actual_km).
    $lH = $makeLease([
        'estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000',
        'status' => 'completed', 'actual_return_date' => '2026-06-02', 'mileage_at_end' => 1300,
    ]);
    $gen->createFromLease([
        'lease_id' => $lH, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $ivH = $gen->createFromLease([ // NO cumulative_actual_km — fallback must kick in
        'lease_id' => $lH, 'period_start' => '2026-06-01', 'period_end' => '2026-06-02',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
    ]);
    $estH = $line($ivH['invoice_id'], 'mileage_estimate');
    $adjH = $line($ivH['invoice_id'], 'mileage_adjustment');
    ck('H1', $estH === null && $adjH !== null && $adjH['amount'] === '30.00',
        "regenerated final invoice reads lease.mileage_at_end (1300 km): est=" . ($estH ? 'present' : 'none')
        . " true-up=+\$" . ($adjH['amount'] ?? 'none') . " (expect none / 30.00)");

    // ── J — S-REGEN-PRESERVE-ESTIMATE: distance override wins over per-day ─
    // regenerate.php passes the OLD draft's billed estimate distance so a
    // per-day edit made after billing doesn't re-price the historical line.
    $lJ = $makeLease(['estimated_mileage_per_day' => '100.00', 'estimated_mileage_per_day_km' => '100.0000']);
    $ivJ1 = $gen->createFromLease([ // override: preserve a 1240 km estimate (billed at 40/day)
        'lease_id' => $lJ, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        'estimate_distance_km_override' => '1240.0000',
    ]);
    $estJ1 = $line($ivJ1['invoice_id'], 'mileage_estimate');
    ck('J1', $estJ1 !== null && $estJ1['amount'] === '620.00' && bccomp((string) $estJ1['quantity'], '1240', 2) === 0,
        "override 1240 km preserved: \$" . ($estJ1['amount'] ?? 'none') . " qty=" . ($estJ1['quantity'] ?? '?')
        . " (expect 620.00 / 1240 — NOT 31×100/day = \$1550)");
    $ivJ2 = $gen->createFromLease([ // control: no override → current per-day re-derives
        'lease_id' => $lJ, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $estJ2 = $line($ivJ2['invoice_id'], 'mileage_estimate');
    ck('J2', $estJ2 !== null && $estJ2['amount'] === '1500.00',
        "no override → 30 × 100 km/day × \$0.50 = \$" . ($estJ2['amount'] ?? 'none') . " (expect 1500.00)");

    // ── I — TWO-credit-line overflow spill (S-CAP-MULTILINE regression) ────
    // A final settlement can carry BOTH a mileage_credit (over-estimate) AND
    // a base_rental_reconciliation_credit (prior over-billing) whose COMBINED
    // total exceeds the invoice's charges. The overflow cap must drain the
    // overflow across BOTH cappable lines and floor the subtotal at exactly
    // $0 — routing to account credit only what it actually removed.
    //
    // This reproduces prod INV-2026-01760 (lease 358): the old cap capped only
    // the FIRST cappable line then `break 2`'d, so when the overflow exceeded
    // the mileage credit it clamped that line to $0, bailed, and left the
    // residual in the subtotal — a NEGATIVE-total invoice with a $0 mileage
    // line — while routing the full abs(subtotal) to account credit.
    //
    // S-AUDIT-BILLING-ENGINE-1 #14 REWRITE: the old trigger (daily ≫ monthly →
    // partial_start over-billed at the daily tier) is gone — D-R2-2 cheaper-of
    // caps short-period billing at the flat weekly. The R2-native over-bill is
    // an EXTENT SHRINK: bill the flat single-calendar month, then the lease
    // returns early → cumulative drops below already_billed → big
    // reconciliation credit; the low actual reading forces the mileage credit.
    $lI = $makeLease([
        'daily_rate' => '100.00', 'weekly_rate' => '500.00', 'monthly_rate' => '2000.00',
        'estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000',
    ]);
    $gen->createFromLease([ // full May at the single-calendar-month flat $2000 (+ 31-day estimate line)
        'lease_id' => $lI, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    // Early return May 10 → extent shrinks: cumulative = wm(10) = $714.29 vs
    // already_billed $2000 → recon credit $1285.71; actual 50 km vs the 1240 km
    // billed estimate → mileage credit; the final carries no charges → both
    // credits overflow and the cap must drain BOTH lines to $0.
    db_execute("UPDATE leases SET actual_return_date = '2026-05-10' WHERE id = ?", [$lI]);
    $ivI = $gen->createFromLease([
        'lease_id' => $lI, 'period_start' => '2026-05-10', 'period_end' => '2026-05-10',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '50',
    ]);
    $hdrI  = db_row("SELECT subtotal FROM invoices WHERE id=?", [$ivI['invoice_id']]);
    $reconI = $line($ivI['invoice_id'], 'base_rental_reconciliation_credit');
    $mileI  = $line($ivI['invoice_id'], 'mileage_credit');
    $cnI    = db_row("SELECT amount FROM credit_notes WHERE source_invoice_id=? AND source='mileage_overpayment'", [$ivI['invoice_id']]);
    // Both credit lines present, overflow spilled from mileage into reconciliation.
    ck('I1 (subtotal floor)', $hdrI !== null && bccomp((string) $hdrI['subtotal'], '0.00', 2) === 0,
        "combined-credit-overflow final invoice floors subtotal at \$" . ($hdrI['subtotal'] ?? 'none') . " (expect 0.00 — was NEGATIVE pre-fix)");
    ck('I2 (both capped)', $reconI !== null && $mileI !== null
            && bccomp((string) $mileI['amount'], '0', 2) >= 0 && bccomp((string) $reconI['amount'], '0', 2) >= 0
            && bccomp((string) $mileI['amount'], '0.00', 2) === 0,
        "mileage fully drained + reconciliation partially capped: mileage=\$" . ($mileI['amount'] ?? '?') . " recon=\$" . ($reconI['amount'] ?? '?') . " (both ≥ 0, no \$0-line-with-negative-subtotal)");
    // Money conservation: routed CN == exactly what was removed from the two lines.
    // (Each capped line records its own original amount in detail_lines.)
    $sumRemoved = '0.00';
    foreach (db_select(
        "SELECT amount, detail_lines FROM invoice_line_items
          WHERE invoice_id=? AND item_type IN ('base_rental_reconciliation_credit','mileage_credit')",
        [$ivI['invoice_id']]
    ) as $capLn) {
        $d = json_decode((string) ($capLn['detail_lines'] ?? '{}'), true);
        if (is_array($d) && isset($d['original_credit_amount'])) {
            $sumRemoved = bcadd($sumRemoved, bcsub((string) $d['original_credit_amount'], (string) $capLn['amount'], 2), 2);
        }
    }
    ck('I3 (conservation)', $cnI !== null && bccomp((string) $cnI['amount'], $sumRemoved, 2) === 0,
        "account-credit CN (\$" . ($cnI['amount'] ?? 'none') . ") == total removed from capped lines (\$" . $sumRemoved . ") — no over-routing");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    ck('FATAL', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — estimate + true-up model bills correctly\033[0m\n";
exit(0);
