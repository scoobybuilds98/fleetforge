<?php
declare(strict_types=1);

/**
 * tests/_smoke_audit_lifecycle_fixes.php
 *
 * S-AUDIT-LIFECYCLE-1 — hermetic regression coverage for the full lease +
 * invoice lifecycle audit AND its fixes. Every scenario runs inside
 * BEGIN/ROLLBACK — no writes survive. Pattern cloned from
 * tests/_smoke_model_b_lifecycle.php (mbcron_bump_counter, D-SMOKE-HERMETIC).
 *
 * Engine-math scenarios (S1-S6, S9, S11) assert against an INDEPENDENT bcmath
 * implementation of the R2 tier ladder (my_* helpers below), written from the
 * spec — a second implementation, not the engine checking itself:
 *   S1  full life: activation → month 2 → close final; audit columns; lifetime
 *       total == independent cumulative ($1,900.00)
 *   S2  tier-boundary (4-day partial + 30-day full @ $50/$350/$700) → $770,
 *       never the naive $900
 *   S3  Model B: D137 init → precharge line → drawdown 180/180/320
 *   S4  Model B Lite bills from km 0, no credit/precharge
 *   S5  vestigial engine_version='period_independent' still bills holistic
 *   S6  void mid-lease: already_billed excludes void; next invoice self-heals
 *   S6x void of a drawdown invoice RESTORES precharge_balance (fix #1/F33) +
 *       audit row lease_precharge_balance_drawdown_reversal
 *   S6y void of SENT Invoice 1 un-stamps precharge_invoiced_at and the next
 *       invoice re-emits the mileage_precharge line (fix #2)
 *   S9  D-GL-REVREC-1 future-guard clamps the JE to business-local today
 *   S10 zero-basis lease → BillingRateException, never an empty $0 draft
 *       (fix #4 — the 'none' emit path backstop)
 *   S11 rate amendment retroactivity: cumulative re-prices billed history at
 *       the NEW rate (documented behaviour per operator 2026-07-09)
 *   S12 createFromLease require_lease_status throws on mismatch (fix #19d)
 *   S13 ff_next_credit_note_number mints sequentially under the txn (fix #24e)
 *   S14 CreditApplicationReversal refuses written_off/void invoices (fix #24a)
 *
 * Sent-immutability via the REAL endpoints and FOR UPDATE serialization need
 * cross-connection visibility (committed fixtures) — covered by the audit's
 * S7/S8 driver, not here.
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

// Cloned verbatim from tests/_smoke_model_b_lifecycle.php:1370 (D-SMOKE-HERMETIC-MODELB).
function mbcron_bump_counter(): void {
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 50)]
    );
}

$results = [];

function rec(string $case, bool $ok, string $msg): void {
    global $results;
    $results[] = ['case' => $case, 'ok' => $ok, 'msg' => $msg];
    printf("  %s %s — %s\n", $ok ? 'PASS' : 'FAIL', $case, $msg);
}

function scenario(string $label, callable $fn): void {
    db_execute('BEGIN');
    try {
        mbcron_bump_counter();
        $fn();
    } catch (\Throwable $e) {
        rec($label, false, 'EXCEPTION ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        db_execute('ROLLBACK');
    }
}

function make_unit(int $userId): int {
    $tpl = db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    return db_insert('equipment_units', [
        'template_id' => (int) $tpl['id'],
        'unit_number' => 'AUD1-' . substr(md5((string) microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10),
        'samsara_vehicle_id' => null,
        'tracking_provider' => 'none',
        'ownership_type' => 'owned',
        'status' => 'available',
        'created_by' => $userId, 'updated_by' => $userId,
    ]);
}

function make_lease(array $o, int $customerId, int $unitId, int $userId): int {
    $d = [
        'contract_number' => 'AUD1-' . substr(md5((string) microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12),
        'customer_id' => $customerId,
        'equipment_unit_id' => $unitId,
        'start_date' => '2026-01-15',
        'status' => 'active',
        'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
        'mileage_rate_km' => null, 'mileage_unit' => 'km',
        'estimated_mileage_km' => '0',
        'currency' => 'CAD', 'billing_cycle' => 'monthly',
        'mileage_tracking_mode' => 'off',
        'gps_opt_in' => 0, 'gps_cost' => '0.00',
        'insurance_opt_in' => 0, 'warranty_opt_in' => 0,
        'precharge_enabled' => 0, 'precharge_amount' => null, 'precharge_balance' => null,
        'precharge_invoiced_at' => null,
        'created_by' => $userId, 'updated_by' => $userId,
    ];
    return db_insert('leases', array_merge($d, $o));
}

function gen(array $p): array {
    $g = new InvoiceGenerator();
    return $g->createFromLease($p);
}

function base_line(int $invoiceId, string $type): ?array {
    return db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type=?", [$invoiceId, $type]);
}

function inv_row(int $invoiceId): array {
    return db_row("SELECT subtotal, total_amount, status, billing_period_start, billing_period_end,
                          total_days_at_period_end, cumulative_correct_amount, already_billed_before_this
                     FROM invoices WHERE id=?", [$invoiceId]) ?? [];
}

/* ── Independent R2 tier math (bcmath, written from the spec) ─────────── */

function my_days(string $a, string $b): int {
    $x = new DateTimeImmutable($a); $y = new DateTimeImmutable($b);
    if ($y < $x) return 0;
    return (int) $x->diff($y)->days + 1;
}
function my_wm(int $n, string $w): string {
    if ($n <= 0) return '0.000000';
    return bcadd(bcmul($w, (string) intdiv($n, 7), 6), bcmul(bcdiv($w, '7', 6), (string) ($n % 7), 6), 6);
}
function my_round2(string $v): string { // banker-free round-half-up like bcround
    $neg = bccomp($v, '0', 6) < 0;
    $adj = $neg ? '-0.005' : '0.005';
    return bcadd(bcadd($v, $adj, 6), '0', 2);
}
function my_within_one_month(string $start, string $end): bool {
    $mv = (new DateTimeImmutable($start))->add(new DateInterval('P1M'));
    return new DateTimeImmutable($end) < $mv;
}
function my_segments(string $start, string $through, string $m): string {
    $md = bcdiv($m, '30', 6);
    $sum = '0';
    $cur = new DateTimeImmutable((new DateTimeImmutable($start))->format('Y-m-01'));
    $end = new DateTimeImmutable($through);
    while ($cur <= $end) {
        $first = $cur;
        $last = $cur->modify('last day of this month');
        $segStart = max($first, new DateTimeImmutable($start));
        $segEnd = min($last, $end);
        if ($segStart <= $segEnd) {
            $complete = ($segStart == $first && $segEnd == $last);
            if ($complete) {
                $sum = bcadd($sum, $m, 6);
            } else {
                $d = (int) $segStart->diff($segEnd)->days + 1;
                $sum = bcadd($sum, bcmul($md, (string) $d, 6), 6);
            }
        }
        $cur = $first->modify('first day of next month');
    }
    return my_round2($sum);
}
function my_cumulative(string $start, string $through, string $extent, string $d, string $w, string $m): string {
    if (strtotime($through) > strtotime($extent)) $through = $extent;
    $total = my_days($start, $extent);
    $n = my_days($start, $through);
    if ($total <= 0) return '0.00';
    $monthlyApplies = $total > 7 && bccomp($m, '0', 6) > 0 && bccomp(my_wm($total, $w), $m, 6) > 0;
    if (!$monthlyApplies) {
        $n = max(1, $n);
        if ($n <= 5) return my_round2(bcmul($d, (string) $n, 6));
        if ($n <= 7) return my_round2($w);
        return my_round2(my_wm($n, $w));
    }
    if ((new DateTimeImmutable($start))->format('Y-m') === (new DateTimeImmutable($extent))->format('Y-m')) {
        return my_round2($m);
    }
    if ($total <= 30 || my_within_one_month($start, $extent)) {
        return my_round2($m);
    }
    return my_segments($start, $through, $m);
}

/* ── FK parents ───────────────────────────────────────────────────────── */

$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId = 22; // K-19 persistent dev user (claude-t1-...@fleetforge.test)
if (!db_row("SELECT id FROM users WHERE id=22")) {
    $userId = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
}
if (!$customerId || !$userId) { echo "SETUP FAIL: no FK parents\n"; exit(2); }

echo "S-AUDIT-LIFECYCLE-1 Phase 2 — hermetic runtime scenarios\n";
echo str_repeat('=', 76), "\n";

/* ══ S1 — holistic full life: activation → month 2 → close final ═══════ */
scenario('S1 holistic full life', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([], $customerId, $unit, $userId);

    // Invoice 1: activation partial_start Jan 15-31.
    $r1 = gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
               'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $e1 = my_cumulative('2026-01-15', '2026-01-31', '2026-01-31', '50.00', '300.00', '1000.00'); // expect 728.57
    $l1 = base_line($r1['invoice_id'], 'base_rental');
    rec('S1a inv1 weekly_math', $l1 !== null && bccomp((string) $l1['amount'], $e1, 2) === 0,
        "inv1 base=" . ($l1['amount'] ?? 'none') . " expect {$e1} (independent recompute)");

    // Invoice 2: full month Feb.
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
               'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $cum2 = my_cumulative('2026-01-15', '2026-02-28', '2026-02-28', '50.00', '300.00', '1000.00'); // 1566.67
    $e2 = bcsub($cum2, $e1, 2); // 838.10
    $l2 = base_line($r2['invoice_id'], 'base_rental');
    $h2 = inv_row($r2['invoice_id']);
    rec('S1b inv2 reconciliation delta', $l2 !== null && bccomp((string) $l2['amount'], $e2, 2) === 0,
        "inv2 base=" . ($l2['amount'] ?? 'none') . " expect {$e2} (cum {$cum2} − billed {$e1})");
    rec('S1c inv2 audit columns',
        (int) $h2['total_days_at_period_end'] === 45
        && bccomp((string) $h2['cumulative_correct_amount'], $cum2, 2) === 0
        && bccomp((string) $h2['already_billed_before_this'], $e1, 2) === 0,
        "days={$h2['total_days_at_period_end']}/45 cum={$h2['cumulative_correct_amount']}/{$cum2} already={$h2['already_billed_before_this']}/{$e1}");
    $al = db_row("SELECT COUNT(*) n FROM audit_log WHERE entity_type='invoice_holistic_reconciliation' AND entity_id=?", [$r2['invoice_id']]);
    rec('S1d holistic audit_log row', (int) $al['n'] === 1, "audit rows={$al['n']} expect 1");

    // Close at Mar 10: mirror close ordering — set actual_return, then final invoice.
    db_execute("UPDATE leases SET actual_return_date='2026-03-10' WHERE id=?", [$lid]);
    $r3 = gen(['lease_id' => $lid, 'period_start' => '2026-03-01', 'period_end' => '2026-03-10',
               'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $userId]);
    $cum3 = my_cumulative('2026-01-15', '2026-03-10', '2026-03-10', '50.00', '300.00', '1000.00'); // 1900.00
    $e3 = bcsub($cum3, $cum2, 2); // 333.33
    $l3 = base_line($r3['invoice_id'], 'base_rental');
    rec('S1e final invoice delta', $l3 !== null && bccomp((string) $l3['amount'], $e3, 2) === 0,
        "final base=" . ($l3['amount'] ?? 'none') . " expect {$e3}");
    $sum = db_row("SELECT COALESCE(SUM(CASE WHEN li.is_credit=1 THEN -li.amount ELSE li.amount END),0) s
                     FROM invoice_line_items li JOIN invoices i ON i.id=li.invoice_id
                    WHERE i.lease_id=? AND i.status<>'void' AND i.deleted_at IS NULL
                      AND li.item_type IN ('base_rental','base_rental_reconciliation_credit')", [$lid]);
    rec('S1f lifetime total == independent cumulative', bccomp((string) $sum['s'], $cum3, 2) === 0,
        "lifetime billed={$sum['s']} expect {$cum3}");
});

/* ══ S2 — tier boundary (boss's case): 4-day partial + 30-day full ═════ */
scenario('S2 tier boundary 50/350/700', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease(['start_date' => '2026-05-01', 'daily_rate' => '50.00',
                       'weekly_rate' => '350.00', 'monthly_rate' => '700.00'], $customerId, $unit, $userId);
    $r1 = gen(['lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-04',
               'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $l1 = base_line($r1['invoice_id'], 'base_rental');
    rec('S2a 4-day partial = 4×daily', $l1 !== null && bccomp((string) $l1['amount'], '200.00', 2) === 0,
        "inv1=" . ($l1['amount'] ?? 'none') . " expect 200.00");
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-05-05', 'period_end' => '2026-06-03',
               'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $cum = my_cumulative('2026-05-01', '2026-06-03', '2026-06-03', '50.00', '350.00', '700.00'); // 770.00
    $e2 = bcsub($cum, '200.00', 2); // 570.00
    $l2 = base_line($r2['invoice_id'], 'base_rental');
    rec('S2b delta exact, no overcharge', $l2 !== null && bccomp((string) $l2['amount'], $e2, 2) === 0,
        "inv2=" . ($l2['amount'] ?? 'none') . " expect {$e2} (cum {$cum}; naive old-engine would total 900.00)");
    $tot = bcadd((string) ($l1['amount'] ?? '0'), (string) ($l2['amount'] ?? '0'), 2);
    rec('S2c lifetime == 770 not 900', bccomp($tot, '770.00', 2) === 0, "total={$tot}");
});

/* ══ S3 — Model B precharge: emit → stamp → drawdown ═══════════════════ */
scenario('S3 Model B precharge lifecycle', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([
        'start_date' => '2026-04-01', 'mileage_rate_km' => '0.18',
        'mileage_tracking_mode' => 'samsara', 'odometer_start_km' => '0.00',
        'precharge_enabled' => 1, 'precharge_amount' => '500.00',
        'precharge_balance' => null, 'status' => 'pending',
    ], $customerId, $unit, $userId);
    // Mirror activate.php D137 init (idempotent form).
    db_execute("UPDATE leases SET precharge_balance=precharge_amount, status='active'
                 WHERE id=? AND precharge_enabled=1 AND precharge_amount IS NOT NULL AND precharge_balance IS NULL", [$lid]);
    $b0 = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$lid]);
    rec('S3a D137 init', bccomp((string) $b0['precharge_balance'], '500.00', 2) === 0, "balance={$b0['precharge_balance']}");

    $r1 = gen(['lease_id' => $lid, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
               'billing_type' => 'full_month', 'invoice_type' => 'regular',
               'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '0.00',
               'created_by' => $userId]);
    $pre = base_line($r1['invoice_id'], 'mileage_precharge');
    rec('S3b inv1 precharge line', $pre !== null && bccomp((string) $pre['amount'], '500.00', 2) === 0,
        "precharge=" . ($pre['amount'] ?? 'none') . " expect 500.00");

    // Mirror send stamp (D140) so the D148 drawdown gate opens.
    db_execute("UPDATE leases SET precharge_invoiced_at=NOW() WHERE id=?", [$lid]);
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
               'billing_type' => 'full_month', 'invoice_type' => 'regular',
               'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '1000.00',
               'created_by' => $userId]);
    $use = base_line($r2['invoice_id'], 'mileage_usage');
    $dd  = base_line($r2['invoice_id'], 'mileage_drawdown_credit');
    $b1  = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$lid]);
    $ok = $use !== null && bccomp((string) $use['amount'], '180.00', 2) === 0
       && $dd !== null && bccomp((string) $dd['amount'], '180.00', 2) === 0 && (int) $dd['is_credit'] === 1
       && bccomp((string) $b1['precharge_balance'], '320.00', 2) === 0;
    rec('S3c drawdown math + balance', $ok,
        "usage=" . ($use['amount'] ?? '∅') . " credit=" . ($dd['amount'] ?? '∅')
        . " balance={$b1['precharge_balance']} (expect 180/180/320)");
});

/* ══ S4 — Model B Lite: rate>0, precharge off → bills from km 0 ════════ */
scenario('S4 Model B Lite', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([
        'start_date' => '2026-04-01', 'mileage_rate_km' => '0.18',
        'mileage_tracking_mode' => 'samsara', 'odometer_start_km' => '0.00',
        'precharge_enabled' => 0,
    ], $customerId, $unit, $userId);
    $r = gen(['lease_id' => $lid, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
              'billing_type' => 'full_month', 'invoice_type' => 'regular',
              'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '500.00',
              'created_by' => $userId]);
    $use = base_line($r['invoice_id'], 'mileage_usage');
    $dd  = base_line($r['invoice_id'], 'mileage_drawdown_credit');
    $pre = base_line($r['invoice_id'], 'mileage_precharge');
    rec('S4 lite bills from km0, no credit/precharge',
        $use !== null && bccomp((string) $use['amount'], '90.00', 2) === 0 && $dd === null && $pre === null,
        "usage=" . ($use['amount'] ?? '∅') . " expect 90.00; drawdown=" . ($dd ? 'PRESENT' : '∅') . " precharge=" . ($pre ? 'PRESENT' : '∅'));
});

/* ══ S5 — legacy engine_version lease bills via holistic ═══════════════ */
scenario('S5 period_independent lease → holistic', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease(['engine_version' => 'period_independent'], $customerId, $unit, $userId);
    $r = gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
              'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $h = inv_row($r['invoice_id']);
    rec('S5 vestigial engine_version ignored',
        $h !== [] && $h['cumulative_correct_amount'] !== null && $h['already_billed_before_this'] !== null,
        "holistic audit cols populated (cum={$h['cumulative_correct_amount']}) — no legacy dispatch");
});

/* ══ S6 — VOID mid-lease: self-heal + drawdown behavior ════════════════ */
scenario('S6 void mid-lease self-heal', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([], $customerId, $unit, $userId);
    $r1 = gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
               'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
               'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);

    // Void invoice 2 through the shared action service (the real void path).
    \FleetForge\AI\Actions\FinancialActions::voidInvoice(
        (int) $r2['invoice_id'], 'S-AUDIT-LIFECYCLE-1 hermetic void', $userId, 'Audit', '127.0.0.1');
    $st = db_row("SELECT status FROM invoices WHERE id=?", [$r2['invoice_id']]);
    rec('S6a draft void lands', ($st['status'] ?? '') === 'void', "status={$st['status']}");

    // Invoice 3 (March) must re-bill February's coverage: delta vs inv1 only.
    $r3 = gen(['lease_id' => $lid, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
               'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $cum3 = my_cumulative('2026-01-15', '2026-03-31', '2026-03-31', '50.00', '300.00', '1000.00'); // 2566.67
    $e1 = my_cumulative('2026-01-15', '2026-01-31', '2026-01-31', '50.00', '300.00', '1000.00');   // 728.57
    $e3 = bcsub($cum3, $e1, 2); // includes Feb re-bill
    $l3 = base_line($r3['invoice_id'], 'base_rental');
    $h3 = inv_row($r3['invoice_id']);
    rec('S6b inv3 self-heals voided coverage',
        $l3 !== null && bccomp((string) $l3['amount'], $e3, 2) === 0
        && bccomp((string) $h3['already_billed_before_this'], $e1, 2) === 0,
        "inv3=" . ($l3['amount'] ?? '∅') . " expect {$e3}; already={$h3['already_billed_before_this']} expect {$e1} (void excluded)");
});

/* ══ S6x — VOID of a drawdown-carrying invoice: is precharge restored? ═ */
scenario('S6x void drawdown invoice → precharge_balance?', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([
        'start_date' => '2026-04-01', 'mileage_rate_km' => '0.18',
        'mileage_tracking_mode' => 'samsara', 'odometer_start_km' => '0.00',
        'precharge_enabled' => 1, 'precharge_amount' => '500.00', 'precharge_balance' => '500.00',
        'precharge_invoiced_at' => '2026-04-01 00:00:00',
    ], $customerId, $unit, $userId);
    $r = gen(['lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
              'billing_type' => 'full_month', 'invoice_type' => 'regular',
              'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '1000.00',
              'created_by' => $userId]);
    $b1 = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$lid]);
    \FleetForge\AI\Actions\FinancialActions::voidInvoice(
        (int) $r['invoice_id'], 'S-AUDIT-LIFECYCLE-1 hermetic void', $userId, 'Audit', '127.0.0.1');
    $b2 = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$lid]);
    $rev = db_row("SELECT COUNT(*) n FROM audit_log WHERE entity_type='lease_precharge_balance_drawdown_reversal' AND entity_id=?", [$lid]);
    rec('S6x drawdown RESTORED on void (fix #1/F33)',
        bccomp((string) $b1['precharge_balance'], '320.00', 2) === 0
        && bccomp((string) $b2['precharge_balance'], '500.00', 2) === 0
        && (int) $rev['n'] === 1,
        "after drawdown={$b1['precharge_balance']}/320.00, after void={$b2['precharge_balance']}/500.00, reversal audit rows={$rev['n']}/1");
});

/* ══ S6y — void SENT Invoice 1 → unstamp + re-emit (fix #2) ═══════════ */
scenario('S6y void sent Invoice 1 unstamps precharge', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([
        'start_date' => '2026-04-01', 'mileage_rate_km' => '0.18',
        'mileage_tracking_mode' => 'samsara', 'odometer_start_km' => '0.00',
        'precharge_enabled' => 1, 'precharge_amount' => '500.00', 'precharge_balance' => '500.00',
        'precharge_invoiced_at' => null,
    ], $customerId, $unit, $userId);
    $r1 = gen(['lease_id' => $lid, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
               'billing_type' => 'full_month', 'invoice_type' => 'regular',
               'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '0.00',
               'created_by' => $userId]);
    // Mirror send: flip to sent + stamp (D140), then void through the real path.
    db_execute("UPDATE invoices SET status='sent', sent_date=CURDATE() WHERE id=?", [$r1['invoice_id']]);
    db_execute("UPDATE leases SET precharge_invoiced_at=NOW() WHERE id=?", [$lid]);
    \FleetForge\AI\Actions\FinancialActions::voidInvoice(
        (int) $r1['invoice_id'], 'S6y hermetic void', $userId, 'Audit', '127.0.0.1');
    $stamp = db_row("SELECT precharge_invoiced_at FROM leases WHERE id=?", [$lid])['precharge_invoiced_at'];
    // The D138 gate re-opened → the next invoice re-emits the precharge charge.
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
               'billing_type' => 'full_month', 'invoice_type' => 'regular',
               'odometer_at_period_start_km' => '0.00', 'odometer_at_period_end_km' => '0.00',
               'created_by' => $userId]);
    $pre = base_line($r2['invoice_id'], 'mileage_precharge');
    rec('S6y unstamp + re-emit (fix #2)',
        $stamp === null && $pre !== null && bccomp((string) $pre['amount'], '500.00', 2) === 0,
        'stamp=' . var_export($stamp, true) . ' re-emitted=' . ($pre['amount'] ?? 'none') . ' (expect NULL / 500.00)');
});

/* ══ S9 — GL revenue recognition future-guard ══════════════════════════ */
scenario('S9 D-GL-REVREC-1 future-guard', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease(['start_date' => '2026-08-01'], $customerId, $unit, $userId);
    $r = gen(['lease_id' => $lid, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
              'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $inv = db_row("SELECT invoice_date, billing_period_start, status FROM invoices WHERE id=?", [$r['invoice_id']]);
    // GL posts on SEND (AutoEntryBridge::onInvoiceSent). Drive it in-txn.
    $je0 = (int) db_row("SELECT COUNT(*) n FROM acc_journal_entries WHERE source_type='invoice' AND source_id=?", [$r['invoice_id']])['n'];
    \FleetForge\Accounting\AutoEntryBridge::onInvoiceSent((int) $r['invoice_id']);
    $je = db_row("SELECT entry_date, status FROM acc_journal_entries WHERE source_type='invoice' AND source_id=? ORDER BY id DESC LIMIT 1", [$r['invoice_id']]);
    $todayLocal = (new DateTimeImmutable('now'))->format('Y-m-d'); // compare loosely: entry_date must NOT be in the future
    rec('S9 future period clamps to today',
        $je !== null && $je['entry_date'] <= $todayLocal,
        "invoice_date={$inv['invoice_date']} period_start={$inv['billing_period_start']} je_entry_date=" . ($je['entry_date'] ?? '∅ (pre=' . $je0 . ')') . " today={$todayLocal}");
});

/* ══ S10 — zero-rate lease → BillingRateException, not $0 invoice ══════ */
scenario('S10 zero-rate backstop', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease(['daily_rate' => '0.00', 'weekly_rate' => '0.00', 'monthly_rate' => '0.00'],
        $customerId, $unit, $userId);
    try {
        gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
             'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
        $n = db_row("SELECT COUNT(*) n FROM invoices WHERE lease_id=?", [$lid]);
        rec('S10 zero-rate refused', false, "NO exception — invoices created={$n['n']} (should be BillingRateException)");
    } catch (\FleetForge\Billing\BillingRateException $e) {
        rec('S10 zero-rate refused', true, 'BillingRateException: ' . substr($e->getMessage(), 0, 80));
    }
});

/* ══ S11 — amend-rate retroactivity check (F1 runtime confirmation) ════ */
scenario('S11 rate amendment re-prices history', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([], $customerId, $unit, $userId); // 50/300/1000, start Jan 15
    gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
         'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    // Mirror amend_rate.php: direct UPDATE of the live rate column.
    db_execute("UPDATE leases SET monthly_rate='1500.00' WHERE id=?", [$lid]);
    $r2 = gen(['lease_id' => $lid, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
               'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId]);
    $h2 = inv_row($r2['invoice_id']);
    $cumNew = my_cumulative('2026-01-15', '2026-02-28', '2026-02-28', '50.00', '300.00', '1500.00'); // whole-lease at NEW rate
    rec('S11 cumulative uses NEW rate for billed history',
        bccomp((string) $h2['cumulative_correct_amount'], $cumNew, 2) === 0,
        "cumulative_correct={$h2['cumulative_correct_amount']} == whole-lease-at-new-rate {$cumNew} → amendment is retroactive in effect (docblock says prospective-only)");
});

/* ══ S12 — require_lease_status post-lock gate (fix #19d) ══════════════ */
scenario('S12 require_lease_status throws on mismatch', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease(['status' => 'completed', 'actual_return_date' => '2026-02-10'], $customerId, $unit, $userId);
    try {
        gen(['lease_id' => $lid, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
             'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $userId,
             'require_lease_status' => 'active']);
        rec('S12 status gate', false, 'NO exception — a completed lease was billed by a cron-shaped call');
    } catch (\RuntimeException $e) {
        rec('S12 status gate', str_starts_with($e->getMessage(), 'LEASE_STATUS_CHANGED'),
            substr($e->getMessage(), 0, 80));
    }
});

/* ══ S13 — shared CN-number minting (fix #24e) ═════════════════════════ */
scenario('S13 ff_next_credit_note_number sequential', function () {
    $a = ff_next_credit_note_number();
    $b = ff_next_credit_note_number();
    $na = (int) substr(strrchr($a, '-'), 1);
    $nb = (int) substr(strrchr($b, '-'), 1);
    rec('S13 gap-free mint', $nb === $na + 1 && preg_match('/^\S+-\d{4}-\d{5}$/', $a) === 1,
        "{$a} then {$b} (expect consecutive)");
});

/* ══ S14 — written_off/void terminal guard on credit unapply (fix #24a) ═ */
scenario('S14 unapply refused on written_off invoice', function () use ($customerId, $userId) {
    $unit = make_unit($userId);
    $lid = make_lease([], $customerId, $unit, $userId);
    $r = gen(['lease_id' => $lid, 'period_start' => '2026-01-15', 'period_end' => '2026-01-31',
              'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => $userId]);
    // Synthetic CN + application, then write the invoice off.
    $cnId = db_insert('credit_notes', [
        'credit_note_number' => 'AUD-S14-' . substr(md5((string) microtime(true)), 0, 8),
        'customer_id' => $customerId, 'lease_id' => $lid,
        'source' => 'goodwill', 'amount' => '50.00', 'amount_remaining' => '0.00',
        'status' => 'fully_used', 'reason' => 'S14 hermetic', 'created_by' => $userId,
    ]);
    $appId = db_insert('credit_note_applications', [
        'credit_note_id' => $cnId, 'invoice_id' => (int) $r['invoice_id'],
        'amount_applied' => '50.00', 'applied_by' => $userId, 'status' => 'applied',
    ]);
    db_execute("UPDATE invoices SET status='written_off' WHERE id=?", [$r['invoice_id']]);
    try {
        \FleetForge\CreditApplicationReversal::reverse((int) $appId, $userId);
        rec('S14 terminal guard', false, 'NO exception — unapply resurrected a written_off invoice');
    } catch (\RuntimeException $e) {
        rec('S14 terminal guard', str_starts_with($e->getMessage(), 'TERMINAL_STATUS'),
            substr($e->getMessage(), 0, 90));
    }
});

echo str_repeat('=', 76), "\n";
$p = count(array_filter($results, fn ($r) => $r['ok']));
$f = count($results) - $p;
echo "RESULT: {$p} PASS / {$f} FAIL\n";
exit($f ? 1 : 0);
