<?php
declare(strict_types=1);

/**
 * tests/_smoke_vendor_spend_and_bill_numbering.php
 *
 * Schema-real, hermetic smoke for the AP / maintenance money fixes found while
 * scripting the staff-training videos:
 *
 *   #7  vendors.total_spent double count — the same repair was added at work
 *       order completion AND again at bill approval. VendorSpend is now the one
 *       rule: counted bills + completed work orders no counted bill (same
 *       vendor) covers. Exercised through the REAL StatusActions work-order
 *       completion path + VendorSpend::recompute / recomputeAll.
 *   #10 work-order completed_date is the company-local day (was CURDATE() = UTC).
 *   #9  AccountingService::nextSequenceNumber drift guard — a stale counter
 *       produced BILL-2026-00005 while BILL-2026-00059 existed; a missing
 *       year counter restarted at 00001 (UNIQUE collision). Plus
 *       findDuplicateVendorBill (same supplier invoice # per vendor).
 *   #8  AutoEntryBridge::onDamageRecoveryBilled must NOT post its fallback
 *       revenue JE for a DRAFT invoice (draft ≠ revenue; would double-post at send).
 *
 * Everything runs inside ONE transaction that is rolled back in `finally`
 * (settings counters, fixtures, notifications, audit rows all disappear).
 *
 * PRE-FIX expectations: #7 step "bill replaces WO" reads baseline+2130 (FAIL),
 * #9 numbering returns the stale counter (FAIL), #10 completed_date may be the
 * UTC day after 5pm Pacific, #8 draft guard posts a JE (FAIL).
 *
 * Run:  php tests/_smoke_vendor_spend_and_bill_numbering.php   Exit 0/1 (2 = setup).
 *
 * @session bugs #7 #8 #9 #10 (training-video bug sweep)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\AutoEntryBridge;
use FleetForge\Accounting\VendorSpend;
use FleetForge\AI\Actions\StatusActions;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$eq   = static function (string $label, string $expected, string $actual) use ($pass, $fail): void {
    bccomp($expected, $actual, 2) === 0 ? $pass("{$label} = {$actual}") : $fail("{$label}: expected {$expected}, got {$actual}");
};

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$PID = getmypid();

echo str_repeat('─', 72) . "\n";
echo "AP / MAINTENANCE MONEY FIXES — vendor spend, bill numbering, WO dates, draft recovery guard\n";
echo str_repeat('─', 72) . "\n";

$vendors = db_select("SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY id LIMIT 2");
$unit    = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
$period  = db_row("SELECT id FROM acc_periods ORDER BY id DESC LIMIT 1");
$user    = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
if (count($vendors) < 2 || !$unit || !$period || !$user) {
    echo "SETUP FAIL — need 2 vendors, a unit, an accounting period and a user\n";
    exit(2);
}
$V = (int) $vendors[0]['id'];
$W = (int) $vendors[1]['id'];
$unitId   = (int) $unit['id'];
$periodId = (int) $period['id'];
$userId   = (int) $user['id'];

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    /** Insert a work order fixture and return its id. */
    $mkWo = static function (int $vendorId, string $status, string $cost, string $tag) use ($unitId, $PID): int {
        return db_insert('maintenance_work_orders', [
            'work_order_number' => "SMK-WO-{$PID}-{$tag}", 'equipment_unit_id' => $unitId, 'vendor_id' => $vendorId,
            'work_type' => 'repair', 'title' => "smoke {$tag}", 'requested_date' => date('Y-m-d'),
            'status' => $status, 'total_cost' => $cost,
        ]);
    };
    /** Insert an AP bill fixture and return its id. */
    $mkBill = static function (int $vendorId, string $status, string $total, ?int $woId, string $tag, string $currency = 'CAD', ?string $rate = null, ?string $vendorRef = null) use ($periodId, $PID): int {
        return db_insert('acc_bills', [
            'bill_number' => "SMK-BILL-{$PID}-{$tag}", 'vendor_id' => $vendorId, 'vendor_bill_number' => $vendorRef,
            'bill_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'period_id' => $periodId,
            'status' => $status, 'currency' => $currency, 'exchange_rate_to_cad' => $rate,
            'subtotal' => $total, 'total_amount' => $total, 'balance_due' => $total, 'work_order_id' => $woId,
        ]);
    };
    $stored = static fn(int $vid): string => bcadd((string) db_row("SELECT total_spent FROM vendors WHERE id = ?", [$vid])['total_spent'], '0', 2);

    // ── #7 vendor spend ──────────────────────────────────────────────────────
    echo "\n#7 vendor Total Spent (bills-first, no double count)\n";
    VendorSpend::recompute($V);
    VendorSpend::recompute($W);
    $baseV = VendorSpend::compute($V);
    $baseW = VendorSpend::compute($W);
    $eq('baseline stored = canonical', $baseV, $stored($V));

    $wo1 = $mkWo($V, 'in_progress', '1000.00', 'A');
    StatusActions::changeWorkOrderStatus($wo1, 'completed', null, 'smoke', $userId, 'Smoke', '127.0.0.1');
    $eq('WO completed → spend +1000 (unbilled WO counts)', bcadd($baseV, '1000.00', 2), $stored($V));

    // #10: the completion stamp is the company-local business day.
    $cd = (string) db_row("SELECT completed_date FROM maintenance_work_orders WHERE id = ?", [$wo1])['completed_date'];
    $cd === date('Y-m-d')
        ? $pass("#10 completed_date = company-local day ({$cd}; MySQL CURDATE() is " . db_row("SELECT CURDATE() d")['d'] . ")")
        : $fail("#10 completed_date {$cd} ≠ local " . date('Y-m-d'));

    $billA = $mkBill($V, 'approved', '1130.00', $wo1, 'A');
    VendorSpend::recompute($V);
    $eq('approved bill for that WO REPLACES its cost (pre-fix +2130)', bcadd($baseV, '1130.00', 2), $stored($V));

    $mkBill($W, 'approved', '200.00', $wo1, 'W');
    VendorSpend::recompute($V);
    VendorSpend::recompute($W);
    $eq("another vendor's bill on the same WO does not un-count it", bcadd($baseV, '1130.00', 2), $stored($V));
    $eq('…and counts for that other vendor', bcadd($baseW, '200.00', 2), $stored($W));

    $wo2 = $mkWo($V, 'completed', '500.00', 'B');
    $mkBill($V, 'draft', '520.00', $wo2, 'B');
    VendorSpend::recompute($V);
    $eq('draft bill is not spend and does not cover its WO', bcadd($baseV, '1630.00', 2), $stored($V));

    db_execute("UPDATE acc_bills SET status = 'void' WHERE id = ?", [$billA]);
    VendorSpend::recompute($V);
    $eq('voiding the bill hands the WO cost back (1000 + 500)', bcadd($baseV, '1500.00', 2), $stored($V));

    $mkBill($V, 'paid', '100.00', null, 'USD', 'USD', '1.350000');
    VendorSpend::recompute($V);
    $eq('USD bill converts to CAD at its frozen rate (+135.00)', bcadd($baseV, '1635.00', 2), $stored($V));

    db_execute("UPDATE vendors SET total_spent = 999999.99 WHERE id = ?", [$V]);
    $dry   = VendorSpend::recomputeAll(false);
    $rowV  = array_values(array_filter($dry, static fn($r) => $r['id'] === $V))[0] ?? null;
    ($rowV && $rowV['before'] === '999999.99' && bccomp($rowV['after'], bcadd($baseV, '1635.00', 2), 2) === 0 && $stored($V) === '999999.99')
        ? $pass('recomputeAll dry-run reports drift without writing')
        : $fail('recomputeAll dry-run wrong: ' . json_encode($rowV) . ' stored=' . $stored($V));
    VendorSpend::recomputeAll(true);
    $again = array_filter(VendorSpend::recomputeAll(true), static fn($r) => $r['id'] === $V);
    ($stored($V) === bcadd($baseV, '1635.00', 2) && !$again)
        ? $pass('recomputeAll(apply) repairs drift and is idempotent')
        : $fail('recomputeAll apply/idempotency failed; stored=' . $stored($V));

    // ── #9 numbering drift guard ─────────────────────────────────────────────
    echo "\n#9 bill / AP sequence numbering\n";
    $year   = date('Y');
    $maxFor = static function (string $table, string $col, string $prefix, string $yr): int {
        return (int) (db_row(
            "SELECT MAX(CAST(SUBSTRING_INDEX({$col}, '-', -1) AS UNSIGNED)) m FROM {$table} WHERE {$col} LIKE ?",
            ["{$prefix}-{$yr}-%"]
        )['m'] ?? 0);
    };
    // Seed a known high bill so the guard has something to beat regardless of dataset.
    db_insert('acc_bills', [
        'bill_number' => sprintf('BILL-%s-%05d', $year, 90000 + ($PID % 9000)), 'vendor_id' => $V,
        'bill_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'period_id' => $periodId, 'status' => 'draft',
    ]);
    $max = $maxFor('acc_bills', 'bill_number', 'BILL', $year);
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name) VALUES (?, '5', 'integer', 'accounting')
         ON DUPLICATE KEY UPDATE `value` = '5'",
        ["accounting.bill_next_number.{$year}"]
    );
    $n = AccountingService::nextBillNumber($year);
    $n === sprintf('BILL-%s-%05d', $year, $max + 1)
        ? $pass("stale counter (5) → {$n} (max issued was {$max})")
        : $fail("stale counter → {$n}, expected " . sprintf('BILL-%s-%05d', $year, $max + 1));
    $n2 = AccountingService::nextBillNumber($year);
    $n2 === sprintf('BILL-%s-%05d', $year, $max + 2) ? $pass("next call stays monotonic → {$n2}") : $fail("second call → {$n2}");

    $oldYear = '2019';
    db_insert('acc_bills', [
        'bill_number' => "BILL-{$oldYear}-00007", 'vendor_id' => $V, 'bill_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d'), 'period_id' => $periodId, 'status' => 'void',
    ]);
    db_execute("DELETE FROM settings WHERE `key` = ?", ["accounting.bill_next_number.{$oldYear}"]);
    $n3 = AccountingService::nextBillNumber($oldYear);
    $n3 === "BILL-{$oldYear}-00008"
        ? $pass("missing year counter + existing (void) row → {$n3} (no UNIQUE collision)")
        : $fail("missing year counter → {$n3}, expected BILL-{$oldYear}-00008");

    $apMax = $maxFor('acc_ap_payments', 'payment_number', 'APAY', $year);
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name) VALUES (?, '1', 'integer', 'accounting')
         ON DUPLICATE KEY UPDATE `value` = '1'",
        ["accounting.ap_payment_next_number.{$year}"]
    );
    $ap = AccountingService::nextApPaymentNumber($year);
    $ap === sprintf('APAY-%s-%05d', $year, $apMax + 1) ? $pass("sibling AP payment counter guarded → {$ap}") : $fail("AP payment → {$ap}, max {$apMax}");

    // Duplicate supplier invoice # (per vendor, case-insensitive, void excluded).
    $dupBill = $mkBill($V, 'draft', '10.00', null, 'DUP', 'CAD', null, "SMK-INV-{$PID}");
    $d1 = AccountingService::findDuplicateVendorBill($V, strtolower("SMK-INV-{$PID}"));
    ($d1 && $d1['id'] === $dupBill) ? $pass('same vendor + same supplier invoice # (case-insensitive) → duplicate') : $fail('duplicate not detected');
    AccountingService::findDuplicateVendorBill($W, "SMK-INV-{$PID}") === null
        ? $pass('different vendor may reuse the number') : $fail('cross-vendor wrongly flagged');
    AccountingService::findDuplicateVendorBill($V, "SMK-INV-{$PID}", $dupBill) === null
        ? $pass('editing the bill itself is not a duplicate') : $fail('self flagged on update');
    AccountingService::findDuplicateVendorBill($V, '   ') === null
        ? $pass('blank supplier invoice # is never a duplicate') : $fail('blank flagged');
    db_execute("UPDATE acc_bills SET status = 'void' WHERE id = ?", [$dupBill]);
    AccountingService::findDuplicateVendorBill($V, "SMK-INV-{$PID}") === null
        ? $pass('a VOID bill does not block re-entry') : $fail('void bill still blocks');

    // ── #8 draft recovery invoice guard ──────────────────────────────────────
    echo "\n#8 damage recovery bridge — draft invoice fallback guard\n";
    if (!(bool) AccountingService::setting('accounting.enabled', false)) {
        echo "  (skip — accounting.enabled is off, bridge no-ops)\n";
    } else {
        $draft = db_row(
            "SELECT i.id, i.customer_id FROM invoices i
              WHERE i.status = 'draft' AND i.deleted_at IS NULL AND i.customer_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM acc_journal_entries je WHERE je.source_type = 'invoice' AND je.source_id = i.id)
              LIMIT 1"
        );
        if (!$draft) {
            echo "  (skip — no draft invoice without a JE in this dataset)\n";
        } else {
            $claimId = db_insert('damage_claims', [
                'claim_number' => "SMK-DMG-{$PID}", 'equipment_unit_id' => $unitId, 'customer_id' => (int) $draft['customer_id'],
                'description' => 'draft guard smoke', 'customer_liable_amount' => '250.00', 'status' => 'invoiced',
                'invoice_id' => (int) $draft['id'],
            ]);
            $r = AutoEntryBridge::onDamageRecoveryBilled($claimId, (int) $draft['id'], $userId);
            $jeCount = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE source_type = 'damage_recovery' AND source_id = ?", [$claimId]);
            ($r === null && $jeCount === 0)
                ? $pass('draft invoice → no fallback revenue JE (draft ≠ revenue; no double post at send)')
                : $fail("draft invoice posted a recovery JE (r=" . json_encode($r) . ", count={$jeCount})");
        }
    }
} catch (\Throwable $e) {
    $fail('smoke threw — ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n=== ROLLBACK — all fixtures, counters, notifications discarded ===\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("VENDOR SPEND / BILL NUMBERING — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
