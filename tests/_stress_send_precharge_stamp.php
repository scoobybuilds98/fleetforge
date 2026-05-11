<?php
declare(strict_types=1);

/**
 * tests/_stress_send_precharge_stamp.php
 *
 * S-MILEAGE-2A C4 — stress test for the D-D precharge_invoiced_at
 * stamp + PRECHARGE_ALREADY_BILLED 409 in api/v1/invoices/send.php.
 *
 * Replicates the C4 SQL flow directly (rather than calling the API
 * endpoint, which would require auth + would exit on json_error and
 * be untestable inline). Each case sets up synthetic leases + invoices
 * + line items, runs the same SELECT/UPDATE pattern C4 runs, and
 * asserts the post-state + the dispatch outcome (stamp vs 409).
 *
 *   (a) first send: precharge_invoiced_at IS NULL on a lease with one
 *       draft invoice carrying a mileage_precharge line → simulate
 *       send → precharge_invoiced_at gets stamped.
 *   (b) second send: a DIFFERENT invoice on the same lease (also
 *       carrying a mileage_precharge line) attempts to send after (a)
 *       → 409 PRECHARGE_ALREADY_BILLED would fire (check returns
 *       NOT NULL precharge_invoiced_at).
 *   (c) non-precharge invoice send (no mileage_precharge line on the
 *       invoice) → check is skipped entirely, no stamp, no 409 path.
 *   (d) cross-lease isolation: precharge-bearing invoice on a SECOND
 *       lease unaffected by the FIRST lease's stamp — the FOR UPDATE
 *       + precharge_invoiced_at scoping is per-lease.
 *
 * Hermetic via single BEGIN/ROLLBACK. Exit 0 on all-pass, 1 on fail.
 *
 * Decisions: D-D (lifecycle stamp), (D-D clarification) 409 dispatch
 * Spec ref:  S-MILEAGE-2A spec in FLEETFORGE_CURRENT_SESSIONS.md
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$tStart  = microtime(true);
$results = [];

function recordCase(array &$results, string $id, string $name, bool $passed, string $msg): void
{
    $results[$id] = ['name' => $name, 'passed' => $passed, 'msg' => $msg];
    $tag = $passed ? 'PASS' : 'FAIL';
    printf("  %s %s %-42s — %s\n", $tag, $id, $name, $msg);
}

// ── Resolve FK parents ──────────────────────────────────────
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-2A C4 — send.php precharge_invoiced_at stamp + 409 stress test\n";
echo "  FK refs: customer_id=$customerId, unit_id=$unitId, user_id=$userId\n";
echo str_repeat('═', 76), "\n";

db_execute("BEGIN");

try {
    /** Insert a synthetic active lease. */
    $makeLease = function (int $customerId, int $unitId, int $userId, int $enabled, ?string $amount, ?string $balance, ?string $invoicedAt, string $contractSuffix): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-2A-C4-' . $contractSuffix,
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

    /** Insert a synthetic draft invoice (no line items). */
    $makeInvoice = function (int $customerId, int $leaseId, string $invoiceNumber, int $userId): int {
        return db_insert('invoices', [
            'invoice_number'            => $invoiceNumber,
            'invoice_type'              => 'regular',
            'customer_id'               => $customerId,
            'lease_id'                  => $leaseId,
            'billing_period_start'      => '2026-05-01',
            'billing_period_end'        => '2026-05-31',
            'billing_period_days'       => 31,
            'billing_type'              => 'full_month',
            'rate_method_used'          => 'monthly',
            'invoice_date'              => '2026-05-01',
            'due_date'                  => '2026-05-31',
            'status'                    => 'draft',
            'subtotal'                  => '250.00',
            'subtotal_after_discount'   => '250.00',
            'total_amount'              => '250.00',
            'balance_due'               => '250.00',
            'created_by'                => $userId,
            'updated_by'                => $userId,
        ]);
    };

    /** Add a mileage_precharge line item to an invoice. */
    $addPrechargeLine = function (int $invoiceId, string $amount): int {
        return db_insert('invoice_line_items', [
            'invoice_id'   => $invoiceId,
            'sort_order'   => 1,
            'item_type'    => 'mileage_precharge',
            'description'  => "Mileage Precharge: \${$amount} (covers excess mileage charges throughout lease)",
            'quantity'     => '1.0000',
            'unit'         => 'precharge',
            'unit_price'   => $amount,
            'amount'       => $amount,
            'is_credit'    => 0,
            'taxable'      => 1,
        ]);
    };

    /**
     * Run the C4 logic against an invoice and return the dispatch
     * outcome: 'stamp', '409', or 'skip' (no precharge line).
     *
     * Mirrors api/v1/invoices/send.php:71-... (the precharge dispatch
     * portion, plus the stamp UPDATE on success).
     */
    $simulateSend = function (int $invoiceId, int $userId, string $now): string {
        $invoice = db_row(
            "SELECT id, invoice_number, lease_id FROM invoices WHERE id = ?",
            [$invoiceId]
        );
        if (!$invoice || empty($invoice['lease_id'])) return 'skip';

        $prechargeLine = db_row(
            "SELECT 1 FROM invoice_line_items
              WHERE invoice_id = ? AND item_type = 'mileage_precharge'
              LIMIT 1",
            [$invoiceId]
        );
        if (!$prechargeLine) return 'skip';

        $leaseRow = db_row(
            "SELECT id, precharge_invoiced_at
               FROM leases
              WHERE id = ? AND deleted_at IS NULL
              FOR UPDATE",
            [$invoice['lease_id']]
        );
        if (!$leaseRow) return 'skip';

        if ($leaseRow['precharge_invoiced_at'] !== null) {
            // C4: json_error 409 PRECHARGE_ALREADY_BILLED would fire
            return '409';
        }

        db_execute(
            "UPDATE leases
                SET precharge_invoiced_at = ?,
                    updated_at = NOW(),
                    updated_by = ?
              WHERE id = ?",
            [$now, $userId, $invoice['lease_id']]
        );
        return 'stamp';
    };

    $now = date('Y-m-d H:i:s');

    // === Case (a) — first send stamps ===
    $leaseA = $makeLease($customerId, $unitId, $userId, 1, '500.00', '500.00', null, 'a');
    $invA   = $makeInvoice($customerId, $leaseA, 'STRESS-2A-C4-INV-A', $userId);
    $addPrechargeLine($invA, '500.00');
    $outcomeA = $simulateSend($invA, $userId, $now);
    $leaseAAfter = db_row("SELECT precharge_invoiced_at FROM leases WHERE id = ?", [$leaseA]);
    $okA = ($outcomeA === 'stamp' && $leaseAAfter['precharge_invoiced_at'] === $now);
    recordCase($results, 'a', 'first send → stamp',
        $okA,
        $okA
            ? "outcome=stamp precharge_invoiced_at={$leaseAAfter['precharge_invoiced_at']}"
            : "outcome={$outcomeA} stamped={$leaseAAfter['precharge_invoiced_at']}");

    // === Case (b) — second precharge invoice on same lease → 409 ===
    // Synthesize a SECOND draft invoice on the same lease ALSO carrying
    // a mileage_precharge line (e.g. an operator who manually inserted
    // a duplicate line bypassing the C3 emit gate). Sending it must
    // dispatch to the 409 branch — NOT silently stamp again, NOT silently
    // succeed without dispatch.
    $invB = $makeInvoice($customerId, $leaseA, 'STRESS-2A-C4-INV-B', $userId);
    $addPrechargeLine($invB, '500.00');
    $outcomeB = $simulateSend($invB, $userId, $now);
    $okB = ($outcomeB === '409');
    recordCase($results, 'b', 'second precharge invoice → 409',
        $okB,
        $okB
            ? 'outcome=409 (PRECHARGE_ALREADY_BILLED would fire)'
            : "outcome={$outcomeB} (expected 409)");

    // === Case (c) — non-precharge invoice unaffected ===
    $leaseC = $makeLease($customerId, $unitId, $userId, 0, null, null, null, 'c');
    $invC   = $makeInvoice($customerId, $leaseC, 'STRESS-2A-C4-INV-C', $userId);
    // NO mileage_precharge line added
    $outcomeC = $simulateSend($invC, $userId, $now);
    $leaseCAfter = db_row("SELECT precharge_invoiced_at FROM leases WHERE id = ?", [$leaseC]);
    $okC = ($outcomeC === 'skip' && $leaseCAfter['precharge_invoiced_at'] === null);
    recordCase($results, 'c', 'non-precharge invoice → skip',
        $okC,
        $okC
            ? 'outcome=skip stamped=NULL (no precharge line on invoice)'
            : "outcome={$outcomeC} stamped=" . ($leaseCAfter['precharge_invoiced_at'] ?? 'NULL'));

    // === Case (d) — second lease unaffected by first lease's stamp ===
    $leaseD = $makeLease($customerId, $unitId, $userId, 1, '300.00', '300.00', null, 'd');
    $invD   = $makeInvoice($customerId, $leaseD, 'STRESS-2A-C4-INV-D', $userId);
    $addPrechargeLine($invD, '300.00');
    $outcomeD = $simulateSend($invD, $userId, $now);
    $leaseDAfter = db_row("SELECT precharge_invoiced_at FROM leases WHERE id = ?", [$leaseD]);
    $leaseAStillStamped = db_row("SELECT precharge_invoiced_at FROM leases WHERE id = ?", [$leaseA]);
    $okD = (
        $outcomeD === 'stamp'
        && $leaseDAfter['precharge_invoiced_at'] === $now
        && $leaseAStillStamped['precharge_invoiced_at'] === $now
    );
    recordCase($results, 'd', 'cross-lease isolation: lease D stamps independently',
        $okD,
        $okD
            ? "lease D stamped={$leaseDAfter['precharge_invoiced_at']}; lease A stamp unchanged"
            : "lease D outcome={$outcomeD} stamped=" . ($leaseDAfter['precharge_invoiced_at'] ?? 'NULL'));

    db_execute("ROLLBACK");
} catch (\Throwable $ex) {
    db_execute("ROLLBACK");
    echo "EXCEPTION — " . $ex->getMessage() . "\n  at " . $ex->getFile() . ":" . $ex->getLine() . "\n";
    exit(1);
}

// ── Leak check ──────────────────────────────────────────────
$leakLeases   = (int) (db_row("SELECT COUNT(*) AS n FROM leases WHERE contract_number LIKE 'STRESS-2A-C4-%'")['n'] ?? -1);
$leakInvoices = (int) (db_row("SELECT COUNT(*) AS n FROM invoices WHERE invoice_number LIKE 'STRESS-2A-C4-%'")['n'] ?? -1);
$leakOk = ($leakLeases === 0 && $leakInvoices === 0);
recordCase($results, 'R', 'rollback-leak-check (leases + invoices gone)',
    $leakOk,
    sprintf("leases=%d invoices=%d (expected 0,0)", $leakLeases, $leakInvoices));

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
