<?php
declare(strict_types=1);

/**
 * tests/_stress_invoice_generator_precharge.php
 *
 * S-MILEAGE-2A C3 — stress test for the mileage_precharge line emission
 * in lib/Billing/InvoiceGenerator::createFromLease. Verifies the
 * three-clause emission gate:
 *
 *   (i)   precharge_enabled=1 + precharge_invoiced_at IS NULL + no prior
 *         precharge line + billing_type='full_month' → line emitted.
 *   (ii)  Second createFromLease on the same lease (still pre-send) →
 *         (b) uniqueness gate blocks the emission. Belt for the racy
 *         advance-batch case the D-D 409 in send.php suspenders for.
 *   (iii) Regular non-precharge lease (precharge_enabled=0) → no
 *         mileage_precharge line, regression-proof against accidental
 *         universal emission.
 *   (iv)  Lifecycle gate: precharge_invoiced_at NOT NULL → no emission
 *         (post-Invoice-1-send state).
 *   (v)   Voided prior invoice → emission allowed (the (b) NOT EXISTS
 *         filter excludes status='void' invoices so a fresh invoice
 *         can carry the line after the original was voided pre-send).
 *
 * Hermetic via BEGIN/ROLLBACK at top + bottom of run. InvoiceGenerator's
 * internal db_transaction() detects the outer transaction (nesting guard
 * in includes/db.php:198-219) so all writes participate in the rollback.
 *
 * Exit code: 0 on all-pass, 1 on any-fail.
 *
 * Decisions: D-B (emit gate), D-C (line item shape), (b) cross-invoice
 *            uniqueness gate (clarification this session)
 * Spec ref:  S-MILEAGE-2A spec in FLEETFORGE_CURRENT_SESSIONS.md
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

$tStart  = microtime(true);
$results = [];

function recordCase(array &$results, string $id, string $name, bool $passed, string $msg): void
{
    $results[$id] = ['name' => $name, 'passed' => $passed, 'msg' => $msg];
    $tag = $passed ? 'PASS' : 'FAIL';
    printf("  %s %s %-44s — %s\n", $tag, $id, $name, $msg);
}

// ── Resolve FK parents ──────────────────────────────────────
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-2A C3 — InvoiceGenerator mileage_precharge emission stress test\n";
echo "  FK refs: customer_id=$customerId, unit_id=$unitId, user_id=$userId\n";
echo str_repeat('═', 76), "\n";

$periodStart = '2026-05-01';
$periodEnd   = '2026-05-31';

db_execute("BEGIN");

try {
    $generator = new InvoiceGenerator();

    /** Insert a synthetic active lease with a given precharge tuple. */
    $makeLease = function (int $customerId, int $unitId, int $userId, int $enabled, ?string $amount, ?string $balance, ?string $invoicedAt, string $contractSuffix): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-2A-C3-' . $contractSuffix,
            'customer_id'             => $customerId,
            'equipment_unit_id'       => $unitId,
            'start_date'              => '2026-05-01',
            'status'                  => 'active',
            'daily_rate'              => '10.00',
            'weekly_rate'             => '60.00',
            'monthly_rate'            => '250.00',
            'currency'                => 'CAD',
            'billing_cycle'           => 'monthly',
            'precharge_enabled'       => $enabled,
            'precharge_amount'        => $amount,
            'precharge_balance'       => $balance,
            'precharge_invoiced_at'   => $invoicedAt,
            'created_by'              => $userId,
            'updated_by'              => $userId,
        ]);
    };

    /** Count mileage_precharge lines on an invoice. */
    $countPrechargeLines = function (int $invoiceId): array {
        return db_select(
            "SELECT id, amount, description, unit, quantity, is_credit, taxable, mileage_distance, mileage_rate
               FROM invoice_line_items
              WHERE invoice_id = ? AND item_type = 'mileage_precharge'",
            [$invoiceId]
        );
    };

    // === Case (i) — fresh precharge lease → line emitted ===
    $leaseI = $makeLease($customerId, $unitId, $userId, 1, '500.00', '500.00', null, 'i');
    $invI = $generator->createFromLease([
        'lease_id'     => $leaseI,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    $linesI = $countPrechargeLines($invI['invoice_id']);
    $okI = (
        count($linesI) === 1
        && $linesI[0]['amount']        === '500.00'
        && $linesI[0]['description']   === 'Mileage Precharge: $500.00 (covers excess mileage charges throughout lease)'
        && $linesI[0]['unit']          === 'precharge'
        && (string) $linesI[0]['quantity'] === '1.0000'
        && (int) $linesI[0]['is_credit'] === 0
        && (int) $linesI[0]['taxable']   === 1
        && $linesI[0]['mileage_distance'] === null
        && $linesI[0]['mileage_rate']     === null
    );
    recordCase($results, 'i', 'fresh precharge → line emitted',
        $okI,
        $okI
            ? sprintf("lines=1 amount=%s unit=%s taxable=%s mileage_distance/rate=NULL",
                $linesI[0]['amount'], $linesI[0]['unit'], $linesI[0]['taxable'])
            : 'shape mismatch: ' . json_encode($linesI));

    // === Case (ii) — second invoice pre-send → (b) gate blocks ===
    // Same lease as (i); precharge_invoiced_at is still NULL (send hasn't
    // run); a prior non-void invoice already carries the line → (b)
    // uniqueness check must skip emission on this second generation.
    $invII = $generator->createFromLease([
        'lease_id'     => $leaseI,
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    $linesII = $countPrechargeLines($invII['invoice_id']);
    $okII = (count($linesII) === 0);
    recordCase($results, 'ii', '(b) uniqueness blocks second invoice',
        $okII,
        $okII
            ? "lines=0 (gate fired)"
            : 'unexpected line emitted: ' . json_encode($linesII));

    // === Case (iii) — non-precharge lease unaffected ===
    $leaseIII = $makeLease($customerId, $unitId, $userId, 0, null, null, null, 'iii');
    $invIII = $generator->createFromLease([
        'lease_id'     => $leaseIII,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    $linesIII = $countPrechargeLines($invIII['invoice_id']);
    $okIII = (count($linesIII) === 0);
    recordCase($results, 'iii', 'non-precharge lease → no line',
        $okIII,
        $okIII ? "lines=0 (gate skipped)" : 'unexpected line: ' . json_encode($linesIII));

    // === Case (iv) — precharge_invoiced_at NOT NULL → lifecycle gate blocks ===
    $leaseIV = $makeLease($customerId, $unitId, $userId, 1, '300.00', '300.00', '2026-05-01 12:00:00', 'iv');
    $invIV = $generator->createFromLease([
        'lease_id'     => $leaseIV,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    $linesIV = $countPrechargeLines($invIV['invoice_id']);
    $okIV = (count($linesIV) === 0);
    recordCase($results, 'iv', 'precharge_invoiced_at set → no line',
        $okIV,
        $okIV ? "lines=0 (lifecycle gate)" : 'unexpected line: ' . json_encode($linesIV));

    // === Case (v) — voided prior precharge invoice → fresh emit allowed ===
    $leaseV = $makeLease($customerId, $unitId, $userId, 1, '400.00', '400.00', null, 'v');
    $invVa = $generator->createFromLease([
        'lease_id'     => $leaseV,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    db_execute("UPDATE invoices SET status='void', void_reason='stress-test void' WHERE id = ?", [$invVa['invoice_id']]);
    $invVb = $generator->createFromLease([
        'lease_id'     => $leaseV,
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $userId,
    ]);
    $linesVb = $countPrechargeLines($invVb['invoice_id']);
    $okV = (count($linesVb) === 1 && $linesVb[0]['amount'] === '400.00');
    recordCase($results, 'v', 'voided prior → fresh emit allowed',
        $okV,
        $okV
            ? "fresh line=1 amount=400.00 (void excluded)"
            : 'expected 1 line @400.00 after void; got: ' . json_encode($linesVb));

    db_execute("ROLLBACK");
} catch (\Throwable $ex) {
    db_execute("ROLLBACK");
    echo "EXCEPTION — " . $ex->getMessage() . "\n  at " . $ex->getFile() . ":" . $ex->getLine() . "\n";
    exit(1);
}

// ── Leak check ──────────────────────────────────────────────
$leakCount = (int) (db_row(
    "SELECT COUNT(*) AS n FROM leases WHERE contract_number LIKE 'STRESS-2A-C3-%'"
)['n'] ?? -1);
$leakLines = (int) (db_row(
    "SELECT COUNT(*) AS n
       FROM invoice_line_items li
       JOIN invoices i ON i.id = li.invoice_id
       JOIN leases l ON l.id = i.lease_id
      WHERE l.contract_number LIKE 'STRESS-2A-C3-%'"
)['n'] ?? -1);
$leakOk = ($leakCount === 0 && $leakLines === 0);
recordCase($results, 'R', 'rollback-leak-check (leases + lines gone)',
    $leakOk,
    sprintf("leases=%d lines=%d (expected 0,0)", $leakCount, $leakLines));

// ── Summary ─────────────────────────────────────────────────
$elapsed = microtime(true) - $tStart;
$pass    = array_sum(array_map(fn($r) => $r['passed'] ? 1 : 0, $results));
$total   = count($results);
$failed  = array_filter($results, fn($r) => !$r['passed']);

echo "\n";
if ($pass === $total) {
    printf("%d/%d passed in %.1fs\n", $pass, $total, $elapsed);
    exit(0);
}

$failList = [];
foreach ($failed as $id => $row) {
    $failList[] = "{$id} {$row['name']}";
}
printf("%d/%d passed (FAILED: %s) in %.1fs\n", $pass, $total, implode(', ', $failList), $elapsed);
exit(1);
