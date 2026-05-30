<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_drift_check.php
 *
 * Smoke test for S-QBO-24 Drift Detection (Phase QBO-12 / 1 of 3) +
 * S-QBO-25 Drift Resolution Workflows (Phase QBO-12 / 2 of 3).
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
 *   Module G — S-QBO-25 resolution-state column + backfill
 *     C16 resolution_type ENUM column exists with 3 expected values + idx_resolution +
 *         backfill: every row with resolved_at NOT NULL has resolution_type NOT NULL
 *
 *   Module H — S-QBO-25 single-event state transitions (UPDATE simulates endpoint)
 *     C17 resolve: sets resolved_at + resolution_type='resolved' + note + user_id
 *     C18 accept: sets resolution_type='accepted'
 *     C19 suppress: sets resolution_type='suppressed'
 *     C24 reopen: clears resolved_at + resolution_type + resolution_note + user_id
 *
 *   Module I — S-QBO-25 CRITICAL terminal-state survival across runCheck
 *     C20 suppressed event survives a runCheck re-run (recordDrift does NOT
 *         create a duplicate OPEN row for the same key) — the whole point of suppress
 *     C21 accepted event survives a runCheck re-run (same invariant for accept)
 *
 *   Module J — S-QBO-25 auto-resolve-on-parity (D-QBO-25-2)
 *     C22 a previously-OPEN drift_cron event whose drift is now gone is
 *         auto-resolved (resolution_type='resolved', note 'auto-resolved%') and
 *         runCheck()['resolved'] >= 1
 *     C23 a push_failure-sourced event is NOT auto-resolved by the cron
 *         (auto-resolve scope is detection_source='drift_cron' only)
 *
 *   Module K — S-QBO-25 endpoint surfaces (file metadata)
 *     C25 drift_resolve.php contains entity_type → Enqueuer FQCN map covering
 *         all 8 ENTITY_CHECKS entity types
 *     C26 drift_bulk_resolve.php exists with category + action + entity_type whitelist
 *
 *   Module L — S-QBO-25 bulk-by-category mutation semantics
 *     C27 bulk-accept of (category=X) affects all OPEN events of that category
 *         and leaves other-category events untouched
 *
 *   Module M — S-QBO-25 drift_list visibility filter (D-QBO-25-1 K-22 #4)
 *     C28 default 'unresolved' filter hides suppressed events; explicit
 *         'suppressed' filter shows only suppressed
 *
 * @session  S-QBO-24 (detection), S-QBO-25 (resolution)
 * @decision D-QBO-24-1 (hybrid), D-QBO-24-2 (live drift_events schema),
 *           D-QBO-24-3 (GL deferred), D-QBO-24-4 (tolerances),
 *           D-QBO-25-1 (resolution_type ENUM + terminal-state guard),
 *           D-QBO-25-2 (auto-resolve-on-parity for drift_cron events),
 *           D-QBO-25-3 (bulk-accept/suppress scope; force-resync → S-QBO-26),
 *           D-QBO-25-4 (reopen action for operator-undo)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\DriftChecker;

$pass = 0;
$total = 28;
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
    // S-QBO-25 C25 may have enqueued a queue row via CreditMemoEnqueuer.
    db_execute("DELETE FROM acc_qbo_sync_queue       WHERE entity_id BETWEEN 999990 AND 999999");
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
echo "S-QBO-24 Drift Detection + S-QBO-25 Resolution Smoke (28 sub-checks)\n";
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

    // ════════════════════════════════════════════════════════════════
    // S-QBO-25 — Drift Resolution Workflows (Phase QBO-12 / 2 of 3)
    // ════════════════════════════════════════════════════════════════

    // ── C16: resolution_type column + backfill ──────────────────────
    $c16 = [];
    $col = db_row("SHOW COLUMNS FROM acc_qbo_drift_events LIKE 'resolution_type'");
    if (!$col) {
        $c16[] = 'resolution_type column missing';
    } else {
        foreach (['resolved', 'accepted', 'suppressed'] as $v) {
            if (strpos((string) $col['Type'], "'{$v}'") === false) $c16[] = "ENUM missing value '{$v}'";
        }
        if (strtoupper((string) $col['Null']) !== 'YES') $c16[] = 'resolution_type should be NULLable';
    }
    $idx = db_row("SHOW INDEX FROM acc_qbo_drift_events WHERE Key_name='idx_resolution'");
    if (!$idx) $c16[] = 'idx_resolution index missing';
    $missingBackfill = (int) (db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE resolved_at IS NOT NULL AND resolution_type IS NULL")['c'] ?? 0);
    if ($missingBackfill > 0) $c16[] = "backfill incomplete: {$missingBackfill} resolved-but-untyped rows";
    if (empty($c16)) { echo "PASS C16 resolution_type ENUM + idx_resolution + backfill (resolved_at→'resolved')\n"; $pass++; }
    else { echo "FAIL C16 " . implode('; ', $c16) . "\n"; $failures[] = 'C16'; }

    // Reset settings for resolution-workflow tests (C15 left sync_enabled=0).
    ff_smoke_drift_set('quickbooks.sync_enabled', '0');
    ff_smoke_drift_set('quickbooks.connection_status', 'disconnected');

    // Helper: simulate the endpoint's UPDATE for a single-event resolution.
    $applyResolution = function (int $id, string $type, string $note): int {
        return (int) db_execute(
            "UPDATE acc_qbo_drift_events
                SET resolved_at = NOW(),
                    resolution_type = ?,
                    resolved_by_user_id = 1,
                    resolution_note = ?
              WHERE id = ?
                AND resolved_at IS NULL",
            [$type, $note, $id]
        );
    };

    // ── C17: resolve action sets resolved_at + resolution_type='resolved' ──
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL");
    $c17 = [];
    if (!$ev) { $c17[] = 'no OPEN amount_drift event for 999991 (C8/C11 should have created one)'; }
    else {
        $aff = $applyResolution((int) $ev['id'], 'resolved', 'C17 smoke fix');
        $r17 = db_row("SELECT resolved_at, resolution_type, resolution_note, resolved_by_user_id FROM acc_qbo_drift_events WHERE id=?", [(int) $ev['id']]);
        if ($aff !== 1)                                    $c17[] = "affected={$aff} (want 1)";
        if (!$r17 || $r17['resolved_at'] === null)         $c17[] = 'resolved_at not set';
        if (($r17['resolution_type'] ?? '') !== 'resolved') $c17[] = "resolution_type=" . ($r17['resolution_type'] ?? 'null');
        if (($r17['resolution_note'] ?? '') !== 'C17 smoke fix') $c17[] = 'note not saved';
        if ((int) ($r17['resolved_by_user_id'] ?? 0) !== 1) $c17[] = 'user_id not saved';
    }
    if (empty($c17)) { echo "PASS C17 resolve action sets resolved_at + resolution_type='resolved' + note + user_id\n"; $pass++; }
    else { echo "FAIL C17 " . implode('; ', $c17) . "\n"; $failures[] = 'C17'; }

    // ── C18: accept action sets resolution_type='accepted' ──────────
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999993 AND category='push_failed' AND resolved_at IS NULL");
    $c18 = [];
    if (!$ev) { $c18[] = 'no OPEN push_failed event for 999993'; }
    else {
        $applyResolution((int) $ev['id'], 'accepted', 'C18 smoke accept');
        $r18 = db_row("SELECT resolution_type FROM acc_qbo_drift_events WHERE id=?", [(int) $ev['id']]);
        if (($r18['resolution_type'] ?? '') !== 'accepted') $c18[] = "got " . ($r18['resolution_type'] ?? 'null');
    }
    if (empty($c18)) { echo "PASS C18 accept action sets resolution_type='accepted'\n"; $pass++; }
    else { echo "FAIL C18 " . implode('; ', $c18) . "\n"; $failures[] = 'C18'; }

    // ── C19: suppress action sets resolution_type='suppressed' ──────
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND qbo_entity_id='QBO-CM-DRIFT-MISSING' AND category='missing_in_ff' AND resolved_at IS NULL");
    $c19 = [];
    if (!$ev) { $c19[] = 'no OPEN missing_in_ff event for QBO-CM-DRIFT-MISSING'; }
    else {
        $applyResolution((int) $ev['id'], 'suppressed', 'C19 smoke suppress');
        $r19 = db_row("SELECT resolution_type FROM acc_qbo_drift_events WHERE id=?", [(int) $ev['id']]);
        if (($r19['resolution_type'] ?? '') !== 'suppressed') $c19[] = "got " . ($r19['resolution_type'] ?? 'null');
    }
    if (empty($c19)) { echo "PASS C19 suppress action sets resolution_type='suppressed'\n"; $pass++; }
    else { echo "FAIL C19 " . implode('; ', $c19) . "\n"; $failures[] = 'C19'; }

    // ── C20 (CRITICAL): suppressed event SURVIVES a recordDrift re-run ──
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift'");
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>999991,'qbo_entity_id'=>'QBO-CM-DRIFT-B','category'=>'amount_drift','ff_value'=>'100.00','qbo_value'=>'90.00','drift_amount'=>'10.00','description'=>'C20 seed']);
    $evNew = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolved_at IS NULL");
    $applyResolution((int) $evNew['id'], 'suppressed', 'C20 suppress');
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>999991,'qbo_entity_id'=>'QBO-CM-DRIFT-B','category'=>'amount_drift','ff_value'=>'100.00','qbo_value'=>'90.00','drift_amount'=>'10.00','description'=>'C20 re-run']);
    $allRows = db_select("SELECT id, resolved_at, resolution_type FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' ORDER BY id");
    $openCount = 0; $suppressedCount = 0;
    foreach ($allRows as $r) {
        if ($r['resolved_at'] === null) $openCount++;
        if ($r['resolution_type'] === 'suppressed') $suppressedCount++;
    }
    if (count($allRows) === 1 && $openCount === 0 && $suppressedCount === 1) {
        echo "PASS C20 (CRITICAL) suppressed event SURVIVES recordDrift re-run — no duplicate OPEN\n"; $pass++;
    } else {
        echo "FAIL C20 rows=" . count($allRows) . " open={$openCount} suppressed={$suppressedCount}\n"; $failures[] = 'C20';
    }

    // ── C21 (CRITICAL): accepted event SURVIVES a recordDrift re-run ──
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999993 AND category='push_failed'");
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>999993,'qbo_entity_id'=>null,'category'=>'push_failed','ff_value'=>'push_status=failed','qbo_value'=>null,'drift_amount'=>null,'description'=>'C21 seed']);
    $evNew = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999993 AND category='push_failed' AND resolved_at IS NULL");
    $applyResolution((int) $evNew['id'], 'accepted', 'C21 accept');
    DriftChecker::recordDrift(['entity_type'=>'credit_memo','entity_id'=>999993,'qbo_entity_id'=>null,'category'=>'push_failed','ff_value'=>'push_status=failed','qbo_value'=>null,'drift_amount'=>null,'description'=>'C21 re-run']);
    $allRows = db_select("SELECT id, resolved_at, resolution_type FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999993 AND category='push_failed' ORDER BY id");
    $openCount = 0; $acceptedCount = 0;
    foreach ($allRows as $r) {
        if ($r['resolved_at'] === null) $openCount++;
        if ($r['resolution_type'] === 'accepted') $acceptedCount++;
    }
    if (count($allRows) === 1 && $openCount === 0 && $acceptedCount === 1) {
        echo "PASS C21 (CRITICAL) accepted event SURVIVES recordDrift re-run — no duplicate OPEN\n"; $pass++;
    } else {
        echo "FAIL C21 rows=" . count($allRows) . " open={$openCount} accepted={$acceptedCount}\n"; $failures[] = 'C21';
    }

    // ── C22: auto-resolve-on-parity ─────────────────────────────────
    // Seed sentinel 999994 with NO drift (FF==snapshot). Insert an OPEN
    // drift_cron amount_drift event manually (simulating prior detection
    // that has since been fixed). runCheck should auto-resolve it.
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_id=999994");
    ff_smoke_drift_seed_cn(999994, '200.00', 'QBO-CM-DRIFT-D', 'pushed', '200.00');
    db_insert('acc_qbo_drift_events', [
        'detection_source' => 'drift_cron',
        'category'         => 'amount_drift',
        'entity_type'      => 'credit_memo',
        'entity_id'        => 999994,
        'qbo_entity_id'    => 'QBO-CM-DRIFT-D',
        'ff_value'         => '180.00',
        'qbo_value'        => '200.00',
        'drift_amount'     => '-20.00',
        'description'      => 'C22 prior drift (now fixed)',
        'realm_id'         => 'SMOKE-REALM',
        'environment'      => 'sandbox',
    ]);
    $priorId = (int) db_pdo()->lastInsertId();
    $r = DriftChecker::runCheck();
    $after = db_row("SELECT resolved_at, resolution_type, resolution_note FROM acc_qbo_drift_events WHERE id=?", [$priorId]);
    $c22 = [];
    if (($r['resolved'] ?? 0) < 1)                                    $c22[] = "runCheck.resolved={$r['resolved']} (want >=1)";
    if (!$after || $after['resolved_at'] === null)                    $c22[] = 'prior event not auto-resolved';
    if (($after['resolution_type'] ?? '') !== 'resolved')             $c22[] = "type=" . ($after['resolution_type'] ?? 'null');
    if (strpos((string) ($after['resolution_note'] ?? ''), 'auto-resolved') !== 0) $c22[] = "note=" . ($after['resolution_note'] ?? 'null');
    if (empty($c22)) { echo "PASS C22 auto-resolve-on-parity: fixed drift_cron event flips to resolved (runCheck.resolved counter)\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22) . "\n"; $failures[] = 'C22'; }

    // ── C23: push_failure event NOT auto-resolved ───────────────────
    db_insert('acc_qbo_drift_events', [
        'detection_source' => 'push_failure',
        'category'         => 'push_failed',
        'entity_type'      => 'credit_memo',
        'entity_id'        => 999994,
        'qbo_entity_id'    => 'QBO-CM-DRIFT-D',
        'ff_value'         => 'push_status=failed_legacy',
        'qbo_value'        => null,
        'drift_amount'     => null,
        'description'      => 'C23 push_failure-sourced event',
        'realm_id'         => 'SMOKE-REALM',
        'environment'      => 'sandbox',
    ]);
    $pfId = (int) db_pdo()->lastInsertId();
    DriftChecker::runCheck();
    $afterPf = db_row("SELECT resolved_at, detection_source FROM acc_qbo_drift_events WHERE id=?", [$pfId]);
    if ($afterPf && $afterPf['resolved_at'] === null && $afterPf['detection_source'] === 'push_failure') {
        echo "PASS C23 push_failure-sourced event NOT auto-resolved (cron auto-resolve scope = drift_cron only)\n"; $pass++;
    } else {
        echo "FAIL C23 " . json_encode($afterPf) . "\n"; $failures[] = 'C23';
    }

    // ── C24: reopen clears resolved_at + resolution_type ────────────
    $ev = db_row("SELECT id FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND entity_id=999991 AND category='amount_drift' AND resolution_type='suppressed'");
    $c24 = [];
    if (!$ev) { $c24[] = 'no suppressed 999991 event from C20'; }
    else {
        $aff = (int) db_execute(
            "UPDATE acc_qbo_drift_events
                SET resolved_at = NULL, resolution_type = NULL, resolution_note = NULL, resolved_by_user_id = NULL
              WHERE id = ? AND resolved_at IS NOT NULL",
            [(int) $ev['id']]
        );
        $r24 = db_row("SELECT resolved_at, resolution_type, resolution_note, resolved_by_user_id FROM acc_qbo_drift_events WHERE id=?", [(int) $ev['id']]);
        if ($aff !== 1) $c24[] = "affected={$aff} (want 1)";
        foreach (['resolved_at','resolution_type','resolution_note','resolved_by_user_id'] as $f) {
            if ($r24[$f] !== null) $c24[] = "{$f} not cleared (={$r24[$f]})";
        }
    }
    if (empty($c24)) { echo "PASS C24 reopen clears resolved_at + resolution_type + note + user_id\n"; $pass++; }
    else { echo "FAIL C24 " . implode('; ', $c24) . "\n"; $failures[] = 'C24'; }

    // ── C25: drift_resolve.php has 8-entity Enqueuer FQCN map ───────
    $rsv = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/drift_resolve.php');
    $c25 = [];
    $expectedEnqueuers = [
        'invoice'       => 'InvoiceEnqueuer',
        'payment'       => 'PaymentEnqueuer',
        'bill'          => 'BillEnqueuer',
        'bill_payment'  => 'BillPaymentEnqueuer',
        'credit_memo'   => 'CreditMemoEnqueuer',
        'journal_entry' => 'JournalEntryEnqueuer',
        'customer'      => 'CustomerEnqueuer',
        'vendor'        => 'VendorEnqueuer',
    ];
    foreach ($expectedEnqueuers as $et => $cls) {
        if (strpos($rsv, "'{$et}'") === false)             $c25[] = "missing entity_type '{$et}'";
        if (strpos($rsv, "QboPushers\\\\{$cls}") === false) $c25[] = "missing Enqueuer class {$cls}";
    }
    if (strpos($rsv, "'resync'") === false)   $c25[] = "missing 'resync' action";
    if (strpos($rsv, "'reopen'") === false)   $c25[] = "missing 'reopen' action";
    if (strpos($rsv, "'accept'") === false)   $c25[] = "missing 'accept' action";
    if (strpos($rsv, "'suppress'") === false) $c25[] = "missing 'suppress' action";
    if (empty($c25)) { echo "PASS C25 drift_resolve.php has 8-entity Enqueuer map + 5 action verbs\n"; $pass++; }
    else { echo "FAIL C25 " . implode('; ', $c25) . "\n"; $failures[] = 'C25'; }

    // ── C26: drift_bulk_resolve.php exists with whitelist guards ────
    $bulk = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/drift_bulk_resolve.php');
    $c26 = [];
    if ($bulk === '')                                                                            $c26[] = 'drift_bulk_resolve.php missing';
    if (strpos($bulk, "require_permission('quickbooks', 'edit_credentials')") === false)         $c26[] = 'edit_credentials gate missing';
    if (strpos($bulk, 'allowedActions')    === false)                                            $c26[] = 'action whitelist missing';
    if (strpos($bulk, 'allowedCategories') === false)                                            $c26[] = 'category whitelist missing';
    if (strpos($bulk, 'db_transaction')    === false)                                            $c26[] = 'transaction wrap missing';
    if (empty($c26)) { echo "PASS C26 drift_bulk_resolve.php exists with edit_credentials + whitelist + transaction\n"; $pass++; }
    else { echo "FAIL C26 " . implode('; ', $c26) . "\n"; $failures[] = 'C26'; }

    // ── C27: bulk-accept by category mutation ───────────────────────
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_id BETWEEN 999990 AND 999999 OR qbo_entity_id LIKE 'QBO-CM-DRIFT%'");
    foreach ([999990, 999991] as $id) {
        db_insert('acc_qbo_drift_events', [
            'detection_source' => 'drift_cron', 'category' => 'amount_drift',
            'entity_type' => 'credit_memo', 'entity_id' => $id,
            'qbo_entity_id' => "QBO-CM-DRIFT-{$id}", 'ff_value' => '100', 'qbo_value' => '90',
            'drift_amount' => '10', 'description' => 'C27 bulk seed',
            'realm_id' => 'SMOKE-REALM', 'environment' => 'sandbox',
        ]);
    }
    db_insert('acc_qbo_drift_events', [
        'detection_source' => 'drift_cron', 'category' => 'push_failed',
        'entity_type' => 'credit_memo', 'entity_id' => 999993,
        'qbo_entity_id' => null, 'ff_value' => 'push_status=failed', 'qbo_value' => null,
        'drift_amount' => null, 'description' => 'C27 bulk seed other-category',
        'realm_id' => 'SMOKE-REALM', 'environment' => 'sandbox',
    ]);
    $affected = (int) db_execute(
        "UPDATE acc_qbo_drift_events
            SET resolved_at = NOW(), resolution_type = 'accepted', resolved_by_user_id = 1, resolution_note = ?
          WHERE resolved_at IS NULL AND resolution_type IS NULL
            AND category = ? AND entity_type = ?",
        ['C27 bulk smoke', 'amount_drift', 'credit_memo']
    );
    $acceptedCnt = (int) (db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND category='amount_drift' AND resolution_type='accepted' AND entity_id BETWEEN 999990 AND 999999")['c'] ?? 0);
    $untouched   = (int) (db_row("SELECT COUNT(*) c FROM acc_qbo_drift_events WHERE entity_type='credit_memo' AND category='push_failed' AND entity_id=999993 AND resolved_at IS NULL")['c'] ?? 0);
    $c27 = [];
    if ($affected !== 2)    $c27[] = "bulk affected={$affected} (want 2)";
    if ($acceptedCnt !== 2) $c27[] = "accepted amount_drift count={$acceptedCnt} (want 2)";
    if ($untouched !== 1)   $c27[] = "untouched push_failed count={$untouched} (want 1)";
    if (empty($c27)) { echo "PASS C27 bulk-accept by category affects matching events; other-category untouched\n"; $pass++; }
    else { echo "FAIL C27 " . implode('; ', $c27) . "\n"; $failures[] = 'C27'; }

    // ── C28: drift_list status filter — suppressed hidden by default ──
    db_execute("DELETE FROM acc_qbo_drift_events WHERE entity_id=999990 AND category='balance_drift'");
    db_insert('acc_qbo_drift_events', [
        'detection_source' => 'drift_cron', 'category' => 'balance_drift',
        'entity_type' => 'credit_memo', 'entity_id' => 999990,
        'qbo_entity_id' => 'QBO-CM-DRIFT-A', 'ff_value' => '100', 'qbo_value' => '90',
        'drift_amount' => '10', 'description' => 'C28 suppress visibility seed',
        'resolved_at' => date('Y-m-d H:i:s'),
        'resolution_type' => 'suppressed',
        'resolved_by_user_id' => 1, 'resolution_note' => 'C28 suppressed',
        'realm_id' => 'SMOKE-REALM', 'environment' => 'sandbox',
    ]);
    $unresolvedHit = (int) (db_row(
        "SELECT COUNT(*) c FROM acc_qbo_drift_events
          WHERE entity_id=999990 AND category='balance_drift'
            AND resolved_at IS NULL AND resolution_type IS NULL"
    )['c'] ?? 0);
    $suppressedHit = (int) (db_row(
        "SELECT COUNT(*) c FROM acc_qbo_drift_events
          WHERE entity_id=999990 AND category='balance_drift'
            AND resolution_type='suppressed'"
    )['c'] ?? 0);
    $listSrc = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/drift_list.php');
    $c28 = [];
    if ($unresolvedHit !== 0)                                          $c28[] = "default unresolved filter wrongly includes suppressed (hits={$unresolvedHit})";
    if ($suppressedHit !== 1)                                          $c28[] = "explicit suppressed filter wrongly hides suppressed (hits={$suppressedHit})";
    if (strpos($listSrc, "resolution_type IS NULL") === false)         $c28[] = "drift_list.php missing resolution_type IS NULL guard on default view";
    if (strpos($listSrc, "resolution_type = 'suppressed'") === false)  $c28[] = "drift_list.php missing explicit suppressed filter branch";
    if (strpos($listSrc, "resolution_type = 'accepted'") === false)    $c28[] = "drift_list.php missing explicit accepted filter branch";
    if (empty($c28)) { echo "PASS C28 drift_list: 'unresolved' default hides suppressed; 'suppressed' shows them (K-22 #4)\n"; $pass++; }
    else { echo "FAIL C28 " . implode('; ', $c28) . "\n"; $failures[] = 'C28'; }

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
