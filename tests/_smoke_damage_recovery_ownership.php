<?php
declare(strict_types=1);

/**
 * tests/_smoke_damage_recovery_ownership.php
 *
 * WAVE 5 [Codex CRITICAL] — damage-claim arbitrary invoice linkage.
 *
 * AutoEntryBridge::onDamageRecoveryBilled($claimId, $invoiceId) loaded the claim
 * and the invoice (each carries customer_id) but never compared them before
 * retagging the invoice's journal entry from source_type='invoice' to
 * 'damage_recovery'. The damage-claim update path only validates that the
 * invoice exists — so a claim for customer A could reclassify customer B's
 * invoice JE, contaminating AR subledger lineage. Fixed with an ownership guard
 * (claim.customer_id must equal invoice.customer_id) that refuses + logs on
 * mismatch, before any JE is touched.
 *
 * Exercises the REAL bridge against the real schema. An existing JE is
 * repurposed as an "invoice JE" (source_type='invoice') for the target invoice,
 * then restored in finally (seed has no invoice JEs):
 *   1. mismatch — claim(customer B) + invoice(customer A) → returns null AND the
 *      invoice JE is NOT retagged (stays source_type='invoice').
 *   2. match    — claim(customer A) + invoice(customer A) → proceeds and retags
 *      the JE to 'damage_recovery' (guard allows same-customer).
 *
 * PRE-FIX  : case 1 retags the unrelated invoice's JE → FAIL.
 * POST-FIX : case 1 blocked; case 2 still links.
 *
 * Run:  php tests/_smoke_damage_recovery_ownership.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-DAMAGE-RECOVERY-OWNERSHIP
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\AutoEntryBridge;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$jeId = null; $jeSnap = null;        // JE we repurpose + its original (source_type, source_id)
$claimMismatch = null; $claimMatch = null;

$cleanup = static function () use (&$jeId, &$jeSnap, &$claimMismatch, &$claimMatch) {
    if ($jeId !== null && $jeSnap !== null) {
        db_execute("UPDATE acc_journal_entries SET source_type = ?, source_id = ? WHERE id = ?",
            [$jeSnap['source_type'], $jeSnap['source_id'], $jeId]);
    }
    foreach ([$claimMismatch, $claimMatch] as $cid) {
        if ($cid) db_execute("DELETE FROM damage_claims WHERE id = ?", [$cid]);
    }
};

try {
    if (!(bool) \FleetForge\Accounting\AccountingService::setting('accounting.enabled', false)) {
        echo "SETUP FAIL accounting.enabled is off (bridge would no-op)\n"; exit(2);
    }

    $inv = db_row("SELECT id, customer_id FROM invoices WHERE deleted_at IS NULL AND customer_id IS NOT NULL ORDER BY id LIMIT 1");
    if (!$inv) { echo "SETUP FAIL no invoice with a customer\n"; exit(2); }
    $invoiceId   = (int) $inv['id'];
    $ownerCust   = (int) $inv['customer_id'];

    $other = db_row("SELECT id FROM customers WHERE deleted_at IS NULL AND id <> ? LIMIT 1", [$ownerCust]);
    if (!$other) { echo "SETUP FAIL need a second customer\n"; exit(2); }
    $otherCust = (int) $other['id'];

    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$unit) { echo "SETUP FAIL no equipment unit\n"; exit(2); }
    $unitId = (int) $unit['id'];

    // Repurpose an existing JE as the invoice's JE (seed has no invoice JEs).
    $je = db_row("SELECT id, source_type, source_id FROM acc_journal_entries ORDER BY id LIMIT 1");
    if (!$je) { echo "SETUP FAIL no journal entries\n"; exit(2); }
    $jeId   = (int) $je['id'];
    $jeSnap = ['source_type' => $je['source_type'], 'source_id' => $je['source_id']];
    db_execute("UPDATE acc_journal_entries SET source_type='invoice', source_id=? WHERE id=?", [$invoiceId, $jeId]);

    $mkClaim = static function (int $cust, int $unitId, string $tag) use ($PID): int {
        return db_insert('damage_claims', [
            'claim_number'           => "DMG-OWN-{$PID}-{$tag}",
            'description'            => 'ownership smoke fixture',
            'equipment_unit_id'      => $unitId,
            'customer_id'            => $cust,
            'customer_liable_amount' => '250.00',
            'status'                 => 'invoiced',
        ]);
    };
    $claimMismatch = $mkClaim($otherCust, $unitId, 'wrong');
    $claimMatch    = $mkClaim($ownerCust, $unitId, 'right');

    $jeType = static fn(int $id): string => (string) (db_row("SELECT source_type FROM acc_journal_entries WHERE id=?", [$id])['source_type'] ?? '');

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 5 [Codex] DAMAGE RECOVERY OWNERSHIP — cross-customer JE retag\n";
    echo str_repeat('─', 72) . "\n";

    // ── CASE 1: mismatch must be refused, invoice JE untouched ──────────────
    $r1 = AutoEntryBridge::onDamageRecoveryBilled($claimMismatch, $invoiceId, null);
    $typeAfter1 = $jeType($jeId);
    if ($r1 === null && $typeAfter1 === 'invoice') {
        $pass("1 mismatch — refused (null); invoice JE NOT retagged (source_type='invoice')");
    } else {
        $fail("1 mismatch — r=" . var_export($r1, true) . " je_source_type='{$typeAfter1}' "
            . "(pre-fix: retagged customer {$ownerCust}'s invoice JE for customer {$otherCust}'s claim)");
    }

    // ── CASE 2: same-customer link still works ──────────────────────────────
    $r2 = AutoEntryBridge::onDamageRecoveryBilled($claimMatch, $invoiceId, null);
    $typeAfter2 = $jeType($jeId);
    if ($r2 !== null && $typeAfter2 === 'damage_recovery') {
        $pass("2 match — same-customer claim links; JE retagged to 'damage_recovery'");
    } else {
        $fail("2 match — r=" . var_export($r2, true) . " je_source_type='{$typeAfter2}' (guard over-blocked a valid link)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  restored JE {$jeId} + removed smoke claims\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DAMAGE RECOVERY OWNERSHIP — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
