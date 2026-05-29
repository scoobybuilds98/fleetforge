<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_drift_check.php
 *
 * Smoke test for S-QBO-24 Drift Detection (Phase QBO-12 / 1 of 3).
 *
 * Per [[extensive-test-and-full-report]]: PER-FUNCTION coverage — each
 * public method on DriftChecker gets named sub-checks for happy path +
 * edge cases + invariants. Uses credit_memo sentinels (header-only FF
 * table = cheapest fixture) in id range 999990-999999, cleaned in finally.
 *
 * Sub-check map:
 *   Module A — surfaces + config
 *     C1  DriftChecker class + public methods (runCheck/checkPushFailed/
 *         checkSnapshotAmountDrift/checkLive/recordDrift/tolerance/
 *         liveModeAvailable/qboEntityName) + ENTITY_CHECKS (8) + FAILED_STATUSES
 *     C2  acc_qbo_drift_events reachable + drift_run.php endpoint + cron exist
 *
 *   Module B — tolerance() + qboEntityName() + liveModeAvailable()
 *     C3  tolerance() returns per-entity settings values + defaults
 *     C4  qboEntityName maps all 8 FF types → Intuit PascalCase
 *     C5  liveModeAvailable() false when sync_enabled=0 (pre-cutover)
 *
 *   Module C — checkPushFailed (snapshot layer)
 *     C6  a failed map row → push_failed drift event recorded
 *     C7  a non-failed (pushed) map row → NO push_failed event
 *
 *   Module D — checkSnapshotAmountDrift (snapshot layer + tolerance)
 *     C8  FF total ≠ qbo snapshot beyond tolerance → amount_drift + drift_amount
 *     C9  FF total within tolerance → NO amount_drift event
 *     C10 aggregate_drift accumulates |delta| across drifted rows
 *
 *   Module E — recordDrift idempotency
 *     C11 same (entity_type, entity_id, category) run twice → 1 open row (refreshed)
 *     C12 recordDrift with entity_id=NULL (missing_in_ff) keys on qbo_entity_id
 *
 *   Module F — runCheck orchestration
 *     C13 disabled toggle → {skipped:'disabled'}
 *     C14 enabled snapshot-only run → live=false, checked=8, no throw, stamps last_check_at
 *     C15 liveModeAvailable() gate flips true when sync_enabled=1 + connected
 *         (the decision that gates the live HTTP layer — tested deterministically
 *         without a real QBO call; the live HTTP path itself is operator-verified
 *         at S-QBO-30 cutover per F22, since no fixture/mock HTTP layer exists)
 *
 * @session  S-QBO-24
 * @decision D-QBO-24-1 (hybrid), D-QBO-24-2 (live drift_events schema),
 *           D-QBO-24-3 (GL deferred), D-QBO-24-4 (tolerances)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\DriftChecker;

$pass = 0;
$total = 15;
$failures = [];

function ff_smoke_drift_set(string $k, string $v): void
{
    db_execute(
        "INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES (?,?, 'string','quickbooks',0,0)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k, $v]
    );
}
function ff_smoke_drift_get(string $k): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key`=?", [$k]);
    return $r['value'] ?? null;
}

function ff_smoke_drift_cleanup(): void
{
    // Sentinel-range drift events across ALL entity types — runCheck() in
    // C14 scans every map table, so a leftover sentinel map row from another
    // smoke could produce a sentinel-range drift event under any entity_type.
    // The 999990-999999 range is reserved for tests, so this is safe.
    db_execute("DELETE FROM acc_qbo_drift_events     WHERE entity_id BETWEEN 999990 AND 999999 OR qbo_entity_id LIKE 'QBO-CM-DRIFT%'");
    db_execute("DELETE FROM acc_qbo_credit_memo_map  WHERE ff_credit_note_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM credit_notes             WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers                WHERE id BETWEEN 999990 AND 999999");
}

/** Seed a sentinel credit_note + its map row. */
function ff_smoke_drift_seed_cn(int $id, string $amount, ?string $qboId, string $pushStatus, ?string $qboSnapshot): void
{
    db_execute(
        "INSERT INTO credit_notes (id, credit_note_number, customer_id, source, amount, currency, amount_remaining, status, reason, created_at)
         VALUES (?, ?, 999990, 'other', ?, 'CAD', ?, 'active', 'drift smoke', NOW())",
        [$id, "CN-DRIFT-{$id}", $amount, $amount]
    );
    db_execute(
        "INSERT INTO acc_qbo_credit_memo_map (ff_credit_note_id, qbo_credit_memo_id, qbo_total_amt, push_status)
         VALUES (?, ?, ?, ?)",
        [$id, $qboId, $qboSnapshot, $pushStatus]
    );
}

$snapKeys = ['quickbooks.drift.enabled','quickbooks.sync_enabled','quickbooks.connection_status','quickbooks.drift.tolerance.credit_memo','quickbooks.drift.notify_threshold','quickbooks.realm_id'];
$snap = [];
foreach ($snapKeys as $k) { $snap[$k] = ff_smoke_drift_get($k); }

$CM = DriftChecker::ENTITY_CHECKS['credit_memo'];

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-24 Drift Detection Smoke (15 sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_drift_cleanup();
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'Smoke Drift Customer', 'CAD', NOW())");
    ff_smoke_drift_set('quickbooks.drift.enabled', '1');
    ff_smoke_drift_set('quickbooks.sync_enabled', '0');
    ff_smoke_drift_set('quickbooks.connection_status', 'disconnected');
    ff_smoke_drift_set('quickbooks.drift.tolerance.credit_memo', '0.05');
    ff_smoke_drift_set('quickbooks.drift.notify_threshold', '10.00');
    ff_smoke_drift_set('quickbooks.realm_id', 'SMOKE-REALM');

    // ── C1: class surfaces ──────────────────────────────────────────
    $c1 = [];
    foreach (['runCheck','checkPushFailed','checkSnapshotAmountDrift','checkLive','recordDrift','tolerance','liveModeAvailable','qboEntityName'] as $m) {
        if (!method_exists(DriftChecker::class, $m)) $c1[] = "missing {$m}";
    }
    if (count(DriftChecker::ENTITY_CHECKS) !== 8) $c1[] = 'ENTITY_CHECKS != 8';
    if (count(DriftChecker::FAILED_STATUSES) < 4) $c1[] = 'FAILED_STATUSES < 4';
    if (empty($c1)) { echo "PASS C1 DriftChecker surfaces (8 methods + ENTITY_CHECKS[8] + FAILED_STATUSES)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    // ── C2: infra exists ────────────────────────────────────────────
    $c2 = [];
    if (!db_row("SHOW TABLES LIKE 'acc_qbo_drift_events'")) $c2[] = 'drift_events table missing';
    if (!file_exists(__DIR__ . '/../cron/qbo_drift_check.php')) $c2[] = 'cron missing';
    if (!file_exists(__DIR__ . '/../api/v1/quickbooks/drift_run.php')) $c2[] = 'drift_run.php missing';
    if (empty($c2)) { echo "PASS C2 drift_events table + cron + drift_run.php endpoint exist\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2) . "\n"; $failures[] = 'C2'; }

    // ── C3: tolerance() ─────────────────────────────────────────────
    $c3 = [];
    if (DriftChecker::tolerance('credit_memo') !== '0.05') $c3[] = 'credit_memo tol wrong: ' . DriftChecker::tolerance('credit_memo');
    if (DriftChecker::tolerance('payment') !== '0.01') $c3[] = 'payment tol wrong';
    if (DriftChecker::tolerance('customer') !== '0.00') $c3[] = 'customer tol wrong';
    if (empty($c3)) { echo "PASS C3 tolerance() per-entity settings (§15.5)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    // ── C4: qboEntityName() ─────────────────────────────────────────
    $c4 = [];
    $expect = ['invoice'=>'Invoice','payment'=>'Payment','bill'=>'Bill','bill_payment'=>'BillPayment','credit_memo'=>'CreditMemo','journal_entry'=>'JournalEntry','customer'=>'Customer','vendor'=>'Vendor'];
    foreach ($expect as $ff => $qbo) {
        if (DriftChecker::qboEntityName($ff) !== $qbo) $c4[] = "{$ff}→".DriftChecker::qboEntityName($ff)." (want {$qbo})";
    }
    if (empty($c4)) { echo "PASS C4 qboEntityName maps 8 FF types → Intuit PascalCase\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4) . "\n"; $failures[] = 'C4'; }

    // ── C5: liveModeAvailable false pre-cutover ─────────────────────
    if (DriftChecker::liveModeAvailable() === false) { echo "PASS C5 liveModeAvailable()=false when sync_enabled=0 (pre-cutover)\n"; $pass++; }
    else { echo "FAIL C5 expected false\n"; $failures[] = 'C5'; }

    // ── C6: checkPushFailed records push_failed ─────────────────────
    ff_smoke_drift_seed_cn(999993, '50.00', null, 'failed', null);
    $stats = ['push_failed'=>0,'amount_drift'=>0,'missing_in_qbo'=>0,'missing_in_ff'=>0,'aggregate_drift'=>'0.00'];
    DriftChecker::checkPushFailed('credit_memo', $CM, $stats);
    $ev = db_row("SELECT category, description FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999993 AND category='push_failed' AND resolved_at IS NULL");
    if ($stats['push_failed'] >= 1 && $ev) { echo "PASS C6 checkPushFailed records push_failed drift for failed map row\n"; $pass++; }
    else { echo "FAIL C6 stats=" . json_encode($stats) . " ev=" . json_encode($ev) . "\n"; $failures[] = 'C6'; }

    // ── C7: pushed map row → no push_failed ─────────────────────────
    ff_smoke_drift_seed_cn(999990, '100.00', 'QBO-CM-DRIFT-A', 'pushed', '100.00');
    $stats = ['push_failed'=>0,'amount_drift'=>0,'missing_in_qbo'=>0,'missing_in_ff'=>0,'aggregate_drift'=>'0.00'];
    DriftChecker::checkPushFailed('credit_memo', $CM, $stats);
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999990 AND category='push_failed'");
    // 999990 is pushed; only 999993 is failed → 999990 must NOT get a push_failed event
    if (!$ev) { echo "PASS C7 pushed map row gets NO push_failed event\n"; $pass++; }
    else { echo "FAIL C7 pushed row wrongly flagged push_failed\n"; $failures[] = 'C7'; }

    // ── C8: amount_drift beyond tolerance ───────────────────────────
    // 999991: FF amount 100.00 vs qbo snapshot 90.00 → Δ10 > 0.05 → drift
    ff_smoke_drift_seed_cn(999991, '100.00', 'QBO-CM-DRIFT-B', 'pushed', '90.00');
    $stats = ['push_failed'=>0,'amount_drift'=>0,'missing_in_qbo'=>0,'missing_in_ff'=>0,'aggregate_drift'=>'0.00'];
    DriftChecker::checkSnapshotAmountDrift('credit_memo', $CM, $stats);
    $ev = db_row("SELECT drift_amount, ff_value, qbo_value FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL");
    if ($stats['amount_drift'] >= 1 && $ev && (string)$ev['drift_amount'] === '10.00' && (string)$ev['ff_value'] === '100.00' && (string)$ev['qbo_value'] === '90.00') {
        echo "PASS C8 checkSnapshotAmountDrift flags FF≠QBO beyond tolerance (Δ recorded)\n"; $pass++;
    } else { echo "FAIL C8 stats=" . json_encode($stats) . " ev=" . json_encode($ev) . "\n"; $failures[] = 'C8'; }

    // ── C9: within tolerance → no drift ─────────────────────────────
    // 999992: FF 100.03 vs snapshot 100.00 → Δ0.03 < 0.05 → no event
    ff_smoke_drift_seed_cn(999992, '100.03', 'QBO-CM-DRIFT-C', 'pushed', '100.00');
    $stats = ['push_failed'=>0,'amount_drift'=>0,'missing_in_qbo'=>0,'missing_in_ff'=>0,'aggregate_drift'=>'0.00'];
    DriftChecker::checkSnapshotAmountDrift('credit_memo', $CM, $stats);
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999992 AND category='amount_drift'");
    if (!$ev) { echo "PASS C9 within-tolerance drift (Δ0.03 < 0.05) → NO event\n"; $pass++; }
    else { echo "FAIL C9 within-tolerance wrongly flagged\n"; $failures[] = 'C9'; }

    // ── C10: aggregate_drift accumulates ────────────────────────────
    // Re-run over all sentinels: 999991 (Δ10) drifts; 999990 (0) + 999992 (0.03) don't.
    $stats = ['push_failed'=>0,'amount_drift'=>0,'missing_in_qbo'=>0,'missing_in_ff'=>0,'aggregate_drift'=>'0.00'];
    DriftChecker::checkSnapshotAmountDrift('credit_memo', $CM, $stats);
    if ((string)$stats['aggregate_drift'] === '10.00' && $stats['amount_drift'] === 1) { echo "PASS C10 aggregate_drift accumulates |delta| ({$stats['aggregate_drift']})\n"; $pass++; }
    else { echo "FAIL C10 aggregate=" . $stats['aggregate_drift'] . " count=" . $stats['amount_drift'] . "\n"; $failures[] = 'C10'; }

    // ── C11: recordDrift idempotency ────────────────────────────────
    $before = (int)(db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL")['c'] ?? 0);
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>999991,'qbo_entity_id'=>'QBO-CM-DRIFT-B','category'=>'amount_drift','ff_value'=>'101.00','qbo_value'=>'90.00','drift_amount'=>'11.00','description'=>'re-run']);
    $after = (int)(db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL")['c'] ?? 0);
    $refreshed = db_row("SELECT ff_value FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL ORDER BY id DESC LIMIT 1");
    if ($before === 1 && $after === 1 && (string)$refreshed['ff_value'] === '101.00') { echo "PASS C11 recordDrift idempotent — open event refreshed not duplicated\n"; $pass++; }
    else { echo "FAIL C11 before={$before} after={$after} ff_value=" . ($refreshed['ff_value'] ?? '?') . "\n"; $failures[] = 'C11'; }

    // ── C12: recordDrift with NULL entity_id keys on qbo_entity_id ──
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>null,'qbo_entity_id'=>'QBO-CM-DRIFT-MISSING','category'=>'missing_in_ff','ff_value'=>null,'qbo_value'=>'500.00','drift_amount'=>null,'description'=>'qbo-only']);
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>null,'qbo_entity_id'=>'QBO-CM-DRIFT-MISSING','category'=>'missing_in_ff','ff_value'=>null,'qbo_value'=>'500.00','drift_amount'=>null,'description'=>'qbo-only-again']);
    $cnt = (int)(db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND qbo_entity_id='QBO-CM-DRIFT-MISSING' AND category='missing_in_ff' AND resolved_at IS NULL")['c'] ?? 0);
    if ($cnt === 1) { echo "PASS C12 recordDrift(entity_id=NULL) keys on qbo_entity_id — idempotent\n"; $pass++; }
    else { echo "FAIL C12 expected 1 missing_in_ff row, got {$cnt}\n"; $failures[] = 'C12'; }

    // ── C13: disabled toggle ────────────────────────────────────────
    ff_smoke_drift_set('quickbooks.drift.enabled', '0');
    $r = DriftChecker::runCheck();
    if (($r['skipped'] ?? '') === 'disabled') { echo "PASS C13 runCheck disabled → {skipped:'disabled'}\n"; $pass++; }
    else { echo "FAIL C13 got " . json_encode($r) . "\n"; $failures[] = 'C13'; }
    ff_smoke_drift_set('quickbooks.drift.enabled', '1');

    // ── C14: enabled snapshot-only run ──────────────────────────────
    ff_smoke_drift_set('quickbooks.drift.last_check_at', '');
    $r = DriftChecker::runCheck();
    $stamp = ff_smoke_drift_get('quickbooks.drift.last_check_at');
    if (($r['live'] ?? null) === false && ($r['checked'] ?? 0) === 8 && !empty($stamp)) { echo "PASS C14 runCheck snapshot-only — live=false, checked=8, last_check_at stamped\n"; $pass++; }
    else { echo "FAIL C14 r=" . json_encode(['live'=>$r['live']??null,'checked'=>$r['checked']??null]) . " stamp=" . var_export($stamp,true) . "\n"; $failures[] = 'C14'; }

    // ── C15: live-mode gate flips true when connected ───────────────
    // NOTE: we test the GATE decision (liveModeAvailable) deterministically,
    // NOT a real runCheck(forceLive=true) — that would issue live
    // QuickBooksClient::query() HTTP with multi-minute retry backoff × 8
    // entities (no fixture/mock HTTP layer exists). The live HTTP path is
    // operator-verified at S-QBO-30 cutover (F22). Here we prove the gate
    // that decides whether the live layer runs is correct.
    $c15 = [];
    ff_smoke_drift_set('quickbooks.sync_enabled', '1');
    ff_smoke_drift_set('quickbooks.connection_status', 'connected');
    if (DriftChecker::liveModeAvailable() !== true) $c15[] = 'gate should be true when sync_enabled=1 + connected';
    ff_smoke_drift_set('quickbooks.connection_status', 'disconnected');
    if (DriftChecker::liveModeAvailable() !== false) $c15[] = 'gate should be false when disconnected';
    ff_smoke_drift_set('quickbooks.sync_enabled', '0');
    if (DriftChecker::liveModeAvailable() !== false) $c15[] = 'gate should be false when sync_enabled=0';
    if (empty($c15)) { echo "PASS C15 liveModeAvailable() gate: true iff sync_enabled=1 + connected (live HTTP path → F22 cutover verify)\n"; $pass++; }
    else { echo "FAIL C15 " . implode('; ', $c15) . "\n"; $failures[] = 'C15'; }

} finally {
    ff_smoke_drift_cleanup();
    foreach ($snap as $k => $v) {
        if ($v === null) db_execute("DELETE FROM settings WHERE `key`=?", [$k]);
        else ff_smoke_drift_set($k, $v);
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "RESULT: {$pass}/{$total} PASS\n";
if (!empty($failures)) { echo "FAILED: " . implode(', ', $failures) . "\n"; exit(1); }
exit(0);
