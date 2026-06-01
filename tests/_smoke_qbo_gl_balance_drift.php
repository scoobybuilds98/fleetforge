<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_gl_balance_drift.php
 *
 * Smoke for F23 / S-QBO-24-GL-BALANCE-FOLLOWUP — DriftChecker GL-account-balance
 * drift check. Verifies the offline-buildable parts: the FF natural-balance
 * computation (sign per account type), the drift decision, and the gated
 * checkGlAccountBalances recording a balance_drift event — plus that the check
 * is a no-op while the default-off gate is closed.
 *
 * Sub-check map:
 *   C1 surfaces (ffAccountNaturalBalance / glBalanceDrifts / checkGlAccountBalances
 *      + DEBIT_NORMAL_TYPES const + 'gl_account' tolerance default)
 *   C2 ffAccountNaturalBalance: debit-normal (asset) = debit−credit
 *   C3 ffAccountNaturalBalance: credit-normal (liability) = credit−debit
 *   C4 ffAccountNaturalBalance: unknown account → null
 *   C5 glBalanceDrifts: within tolerance → false; beyond → true (sign-agnostic)
 *   C6 checkGlAccountBalances no-op while gl_balance_enabled='0'
 *   C7 checkGlAccountBalances records balance_drift when enabled + drift seeded
 *   C8 checkGlAccountBalances records NOTHING when FF balance == QBO snapshot
 *
 * accounting.enabled untouched (JEs seeded directly). gl_balance_enabled
 * snapshot-restored. Sentinel ids 999990-999999; cleaned in finally.
 *
 * @session S-QBO-24-GL-BALANCE-FOLLOWUP (F23)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\DriftChecker;

$pass = 0; $total = 8; $failures = [];

function ff_smoke_gl_set(string $k, string $v): void {
    db_execute("INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES (?,?,'string','quickbooks',0,0) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k,$v]);
}
function ff_smoke_gl_get(string $k): ?string { $r = db_row("SELECT `value` FROM settings WHERE `key`=?", [$k]); return $r['value'] ?? null; }

function ff_smoke_gl_cleanup(): void {
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_type='gl_account' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entries WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts WHERE id BETWEEN 999990 AND 999999");
}

$snapKeys = ['quickbooks.drift.gl_balance_enabled', 'quickbooks.drift.tolerance.gl_account'];
$snap = []; foreach ($snapKeys as $k) { $snap[$k] = ff_smoke_gl_get($k); }

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-24-GL-BALANCE-FOLLOWUP Smoke ({$total} sub-checks; F23)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_gl_cleanup();
    $periodId = (int) (db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1")['id'] ?? 1);

    // Seed an ASSET account (debit-normal) 999990 + a LIABILITY (credit-normal) 999991.
    db_execute("INSERT INTO acc_accounts (id, code, name, account_type) VALUES (999990,'9990-SMK','Smoke Asset','asset')");
    db_execute("INSERT INTO acc_accounts (id, code, name, account_type) VALUES (999991,'9991-SMK','Smoke Liability','liability')");

    // Posted JE with lines: asset DR 100 / CR 30 → natural 70; liability CR 80 / DR 20 → natural 60.
    db_execute("INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, description, status, source_type, source_id, created_at) VALUES (999990,'JE-GL-999990',?, '2026-04-01','smoke gl','posted','manual',NULL,NOW())", [$periodId]);
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999990,999990,100.00,0.00)");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999990,999990,0.00,30.00)");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999990,999991,0.00,80.00)");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999990,999991,20.00,0.00)");

    // ── C1 surfaces ──────────────────────────────────────────────────────
    $ref = new ReflectionClass(DriftChecker::class);
    $c1 = method_exists(DriftChecker::class,'ffAccountNaturalBalance')
       && method_exists(DriftChecker::class,'glBalanceDrifts')
       && method_exists(DriftChecker::class,'checkGlAccountBalances')
       && $ref->hasConstant('DEBIT_NORMAL_TYPES')
       && bccomp(DriftChecker::tolerance('gl_account'),'0',2) > 0;
    if ($c1) { echo "PASS C1 GL-balance surfaces + tolerance default\n"; $pass++; } else { echo "FAIL C1\n"; $failures[]='C1'; }

    // ── C2 debit-normal ──────────────────────────────────────────────────
    $b2 = DriftChecker::ffAccountNaturalBalance(999990);
    if ($b2 !== null && bccomp($b2,'70.00',2)===0) { echo "PASS C2 asset natural balance = 70.00 (D−C)\n"; $pass++; } else { echo "FAIL C2 got ".var_export($b2,true)."\n"; $failures[]='C2'; }

    // ── C3 credit-normal ─────────────────────────────────────────────────
    $b3 = DriftChecker::ffAccountNaturalBalance(999991);
    if ($b3 !== null && bccomp($b3,'60.00',2)===0) { echo "PASS C3 liability natural balance = 60.00 (C−D)\n"; $pass++; } else { echo "FAIL C3 got ".var_export($b3,true)."\n"; $failures[]='C3'; }

    // ── C4 unknown ───────────────────────────────────────────────────────
    if (DriftChecker::ffAccountNaturalBalance(999900) === null) { echo "PASS C4 unknown account → null\n"; $pass++; } else { echo "FAIL C4\n"; $failures[]='C4'; }

    // ── C5 drift decision ────────────────────────────────────────────────
    $c5 = DriftChecker::glBalanceDrifts('70.00','70.50','1.00') === false   // within $1
       && DriftChecker::glBalanceDrifts('70.00','75.00','1.00') === true    // beyond
       && DriftChecker::glBalanceDrifts('60.00','58.00','1.00') === true;   // negative delta beyond
    if ($c5) { echo "PASS C5 glBalanceDrifts tolerance decision (sign-agnostic)\n"; $pass++; } else { echo "FAIL C5\n"; $failures[]='C5'; }

    // Map both accounts; asset has a DRIFTING QBO balance (70 vs 90), liability MATCHES (60 vs 60).
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, qbo_current_balance, mapping_status) VALUES (999990,'QBO-ACCT-9990',90.00,'mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, qbo_current_balance, mapping_status) VALUES (999991,'QBO-ACCT-9991',60.00,'mapped')");

    // ISOLATE: temporarily park real mapped accounts as 'ignored' so the global
    // scan sees ONLY our two sentinels (the check has no per-account scope).
    // Restored in finally.
    $GLOBALS['gl_parked'] = array_column(
        db_select("SELECT id FROM acc_qbo_account_map WHERE mapping_status='mapped' AND (ff_account_id IS NULL OR ff_account_id NOT BETWEEN 999990 AND 999999)"),
        'id'
    );
    if (!empty($GLOBALS['gl_parked'])) {
        $ph = implode(',', array_fill(0, count($GLOBALS['gl_parked']), '?'));
        db_execute("UPDATE acc_qbo_account_map SET mapping_status='ignored' WHERE id IN ({$ph})", $GLOBALS['gl_parked']);
    }

    // ── C6 no-op while gated off ─────────────────────────────────────────
    ff_smoke_gl_set('quickbooks.drift.gl_balance_enabled','0');
    $st6 = ['balance_drift'=>0];
    $n6 = DriftChecker::checkGlAccountBalances($st6, false);
    $ev6 = (int) db_count("SELECT COUNT(*) FROM acc_qbo_drift_events WHERE entity_type='gl_account' AND entity_id BETWEEN 999990 AND 999999");
    if ($n6 === 0 && $ev6 === 0) { echo "PASS C6 no-op while gl_balance_enabled='0'\n"; $pass++; } else { echo "FAIL C6 n={$n6} ev={$ev6}\n"; $failures[]='C6'; }

    // ── C7 records drift on the asset (70 vs 90) when enabled ─────────────
    // With real accounts parked, the scan sees only our 2 sentinels → exactly 1
    // drift (the asset; the liability matches).
    ff_smoke_gl_set('quickbooks.drift.gl_balance_enabled','1');
    ff_smoke_gl_set('quickbooks.drift.tolerance.gl_account','1.00');
    $st7 = ['balance_drift'=>0];
    $n7 = DriftChecker::checkGlAccountBalances($st7, false);
    $assetEv = db_row("SELECT category, ff_value, qbo_value, drift_amount FROM acc_qbo_drift_events WHERE entity_type='gl_account' AND entity_id=999990 AND resolved_at IS NULL ORDER BY id DESC LIMIT 1");
    if ($n7 === 1 && $assetEv && $assetEv['category']==='balance_drift'
        && bccomp((string)$assetEv['ff_value'],'70.00',2)===0
        && bccomp((string)$assetEv['qbo_value'],'90.00',2)===0
        && bccomp((string)$assetEv['drift_amount'],'-20.00',2)===0) {
        echo "PASS C7 records balance_drift on asset (70 vs 90, Δ -20)\n"; $pass++;
    } else { echo "FAIL C7 n={$n7} ev=".json_encode($assetEv)."\n"; $failures[]='C7'; }

    // ── C8 NO drift on the matching liability (60 vs 60) ─────────────────
    $liabEv = (int) db_count("SELECT COUNT(*) FROM acc_qbo_drift_events WHERE entity_type='gl_account' AND entity_id=999991");
    if ($liabEv === 0) { echo "PASS C8 no drift recorded for matching account (60 vs 60)\n"; $pass++; } else { echo "FAIL C8 liab events={$liabEv}\n"; $failures[]='C8'; }

} finally {
    // Restore the real mapped accounts we parked as 'ignored'.
    if (!empty($GLOBALS['gl_parked'])) {
        $ph = implode(',', array_fill(0, count($GLOBALS['gl_parked']), '?'));
        db_execute("UPDATE acc_qbo_account_map SET mapping_status='mapped' WHERE id IN ({$ph})", $GLOBALS['gl_parked']);
    }
    ff_smoke_gl_cleanup();
    foreach ($snap as $k=>$v) { if ($v===null) { db_execute("DELETE FROM settings WHERE `key`=?",[$k]); } else { ff_smoke_gl_set($k,(string)$v); } }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "gl_balance_drift_smoke: {$pass}/{$total} ".($pass===$total?'PASS':'FAIL')."\n";
if (!empty($failures)) { echo "Failed: ".implode(', ',$failures)."\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass===$total?0:1);
