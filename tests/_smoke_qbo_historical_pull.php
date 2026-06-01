<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_historical_pull.php
 *
 * Smoke test for S-QBO-27 historical-backfill MACHINERY (Phase QBO-13).
 * Exercises the offline-testable orchestration + the H5/H6 AR-drift detection;
 * the live pull + JE posting are gated seams (F29) and are asserted to REFUSE
 * to run under the dry-run gate.
 *
 * Sub-check map:
 *   Module A — surfaces + schema + order
 *     C1  HistoricalPuller surfaces (orchestration methods + consts)
 *     C2  ArDriftRemediator surfaces (detect/buildPlan/detectAndReport/post + consts)
 *     C3  acc_qbo_historical_pull_runs schema (cols)
 *     C4  ENTITY_ORDER correctness (12 entries; reference-first; TRANSACTIONAL ⊂ ORDER)
 *
 *   Module B — orchestration (offline)
 *     C5  startRun(dry_run) creates row; getRun returns it (mode/status)
 *     C6  startRun('live') refused under dry-run gate
 *     C7  isDryRun true by default; batchSize=100
 *     C8  assertLiveAllowed throws under dry-run
 *     C9  mapMeta resolves invoice + refund_receipt; null for account
 *     C10 resumePoint = MAX(pushed_at) of the entity map
 *     C11 alreadyMapped true for mapped qbo id, false otherwise
 *     C12 tallyCounts + recordCheckpoint merge JSON
 *     C13 pullTransactional rejects non-transactional; writeFfRowFromQbo refuses (F29)
 *
 *   Module C — H5/H6 detection (sentinel-scoped; robust to real data)
 *     C14 H5 invoice (no DR-AR JE) detected with missing_dr_ar = total
 *     C15 H6 invoice (DR-AR = 1.375x total) detected with excess
 *     C16 clean invoice (DR-AR = total) flagged in NEITHER bucket
 *     C17 buildPlan emits H5 DR + H6 CR entries with [A1-FIX-invoice-N] tags
 *     C18 detectAndReport sets remediation_status='reported' + stores plan; posts NOTHING
 *     C19 postApprovedPlan refused (gated; not approved + dry-run)
 *
 *   Module D — endpoint integration
 *     C20 start.php has detectAndReport + force_full_resync gate
 *     C21 status.php + remediation_plan.php exist + lint clean
 *     C22 manual_sync.php hosts the qboHistoricalPull section (D-QBO-27-7)
 *
 * Sentinel IDs 999990-999999; cleaned in finally. Uses real AR account
 * (code 1030) + a valid period; detection is asserted at the sentinel-invoice
 * level (search result arrays), never on global counts.
 *
 * @session  S-QBO-27
 * @decision D-QBO-27-1..7
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\HistoricalPuller;
use FleetForge\QboPushers\ArDriftRemediator;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 22;
$failures = [];

function ff_smoke_hp_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}
function ff_smoke_hp_get(string $key): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $r['value'] ?? null;
}

function ff_smoke_hp_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_historical_pull_runs WHERE realm_id IN ('SMOKE-REALM','unknown') AND started_by IS NULL AND id >= 1 AND id IN (SELECT id FROM (SELECT id FROM acc_qbo_historical_pull_runs WHERE error_message LIKE 'SMOKE-HP%' OR remediation_plan LIKE '%A1-FIX-invoice-99999%') t)");
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entries WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM invoices WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers WHERE id BETWEEN 999990 AND 999999");
}

$snapshotKeys = ['quickbooks.historical_pull.dry_run', 'quickbooks.historical_pull.batch_size', 'quickbooks.sync_enabled', 'quickbooks.realm_id'];
$snapshot = [];
foreach ($snapshotKeys as $k) { $snapshot[$k] = ff_smoke_hp_get($k); }
$createdRunIds = [];

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-27 Historical Pull (machinery) Smoke ({$total} sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_hp_cleanup();

    ff_smoke_hp_set('quickbooks.historical_pull.dry_run', '1');
    ff_smoke_hp_set('quickbooks.historical_pull.batch_size', '100');
    ff_smoke_hp_set('quickbooks.sync_enabled', '0');

    $arId = (int) (db_row("SELECT id FROM acc_accounts WHERE code = '1030'")['id'] ?? 0);
    $periodId = (int) (db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1")['id'] ?? 0);

    // Sentinel customer + 3 invoices.
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'Smoke HP Customer', 'CAD', NOW())");
    $invCols = "(id, invoice_number, customer_id, currency, status, billing_period_start, billing_period_end, billing_period_days, billing_type, invoice_date, due_date, total_amount, amount_paid, credits_applied, balance_due, created_at)";
    // H5 — total 100, no JE.
    db_execute("INSERT INTO invoices {$invCols} VALUES (999991,'INV-HP-999991',999990,'CAD','sent','2026-04-01','2026-04-30',30,'full_month','2026-04-01','2026-04-30',100.00,0,0,100.00,NOW())");
    // H6 — total 80, JE DR-AR=110 (=1.375*80).
    db_execute("INSERT INTO invoices {$invCols} VALUES (999992,'INV-HP-999992',999990,'CAD','sent','2026-04-01','2026-04-30',30,'full_month','2026-04-01','2026-04-30',80.00,0,0,80.00,NOW())");
    // clean — total 50, JE DR-AR=50.
    db_execute("INSERT INTO invoices {$invCols} VALUES (999993,'INV-HP-999993',999990,'CAD','sent','2026-04-01','2026-04-30',30,'full_month','2026-04-01','2026-04-30',50.00,0,0,50.00,NOW())");

    // JE for H6 invoice (inflated DR-AR=110) + clean invoice (DR-AR=50).
    db_execute("INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, description, status, source_type, source_id, created_at) VALUES (999990,'JE-HP-999990',?, '2026-04-01','Smoke HP H6 JE','posted','invoice',999992,NOW())", [$periodId]);
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999990, ?, 110.00, 0.00)", [$arId]);
    db_execute("INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, description, status, source_type, source_id, created_at) VALUES (999991,'JE-HP-999991',?, '2026-04-01','Smoke HP clean JE','posted','invoice',999993,NOW())", [$periodId]);
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (999991, ?, 50.00, 0.00)", [$arId]);

    // ══ Module A ══════════════════════════════════════════════════════════
    $c1 = [];
    foreach (['startRun','getRun','resumePoint','alreadyMapped','tallyCounts','recordCheckpoint','batchSize','isDryRun','assertLiveAllowed','mapMeta','pullTransactional','qboEntityName','writeFfRowFromQbo','pullReferenceData'] as $m) {
        if (!method_exists(HistoricalPuller::class, $m)) $c1[] = "missing {$m}";
    }
    $rp = new ReflectionClass(HistoricalPuller::class);
    foreach (['ENTITY_ORDER','REFERENCE_PULLERS','TRANSACTIONAL_TYPES'] as $k) {
        if (!$rp->hasConstant($k)) $c1[] = "missing const {$k}";
    }
    if (empty($c1)) { echo "PASS C1 HistoricalPuller surfaces\n"; $pass++; } else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    $c2 = [];
    foreach (['arAccountId','detect','buildPlan','detectAndReport','postApprovedPlan'] as $m) {
        if (!method_exists(ArDriftRemediator::class, $m)) $c2[] = "missing {$m}";
    }
    $rr = new ReflectionClass(ArDriftRemediator::class);
    foreach (['AR_ACCOUNT_CODE','H6_RATIO'] as $k) { if (!$rr->hasConstant($k)) $c2[] = "missing const {$k}"; }
    if (empty($c2)) { echo "PASS C2 ArDriftRemediator surfaces\n"; $pass++; } else { echo "FAIL C2 " . implode('; ', $c2) . "\n"; $failures[] = 'C2'; }

    $c3 = [];
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_historical_pull_runs"), 'Field');
    foreach (['id','realm_id','mode','phase','status','entity_counts','checkpoints','ar_drift_before','ar_drift_after','remediation_status','remediation_plan','started_by','started_at','finished_at'] as $col) {
        if (!in_array($col, $cols, true)) $c3[] = "missing col {$col}";
    }
    if (empty($c3)) { echo "PASS C3 acc_qbo_historical_pull_runs schema\n"; $pass++; } else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    $order = HistoricalPuller::ENTITY_ORDER;
    $c4 = [];
    if (count($order) !== 12) $c4[] = 'expected 12 entries, got ' . count($order);
    if (array_slice($order, 0, 5) !== ['account','tax_code','item','customer','vendor']) $c4[] = 'reference-first order wrong';
    foreach (HistoricalPuller::TRANSACTIONAL_TYPES as $t) { if (!in_array($t, $order, true)) $c4[] = "transactional {$t} not in order"; }
    foreach (array_keys(HistoricalPuller::REFERENCE_PULLERS) as $t) { if (!in_array($t, $order, true)) $c4[] = "reference {$t} not in order"; }
    if (empty($c4)) { echo "PASS C4 ENTITY_ORDER correctness (12; reference-first; transactional subset)\n"; $pass++; } else { echo "FAIL C4 " . implode('; ', $c4) . "\n"; $failures[] = 'C4'; }

    // ══ Module B ══════════════════════════════════════════════════════════
    $runId = HistoricalPuller::startRun('dry_run', null);
    $createdRunIds[] = $runId;
    $run = HistoricalPuller::getRun($runId);
    if ($run && $run['mode'] === 'dry_run' && $run['status'] === 'pending') { echo "PASS C5 startRun(dry_run) creates row\n"; $pass++; }
    else { echo "FAIL C5 " . json_encode($run) . "\n"; $failures[] = 'C5'; }

    try { HistoricalPuller::startRun('live', null); echo "FAIL C6 startRun('live') should be refused\n"; $failures[] = 'C6'; }
    catch (QuickBooksException $e) { echo "PASS C6 startRun('live') refused under dry-run gate\n"; $pass++; }

    if (HistoricalPuller::isDryRun() === true && HistoricalPuller::batchSize() === 100) { echo "PASS C7 isDryRun true + batchSize 100\n"; $pass++; }
    else { echo "FAIL C7 dry=" . var_export(HistoricalPuller::isDryRun(), true) . " batch=" . HistoricalPuller::batchSize() . "\n"; $failures[] = 'C7'; }

    try { HistoricalPuller::assertLiveAllowed(); echo "FAIL C8 assertLiveAllowed should throw\n"; $failures[] = 'C8'; }
    catch (QuickBooksException $e) { echo "PASS C8 assertLiveAllowed throws under dry-run\n"; $pass++; }

    $c9 = [];
    $mi = HistoricalPuller::mapMeta('invoice');
    if (($mi['map'] ?? '') !== 'acc_qbo_invoice_map' || ($mi['ff_fk'] ?? '') !== 'ff_invoice_id') $c9[] = 'invoice meta wrong';
    $mr = HistoricalPuller::mapMeta('refund_receipt');
    if (($mr['map'] ?? '') !== 'acc_qbo_refund_receipt_map') $c9[] = 'refund_receipt meta wrong';
    if (HistoricalPuller::mapMeta('account') !== null) $c9[] = 'account meta should be null';
    if (empty($c9)) { echo "PASS C9 mapMeta resolves invoice+refund_receipt; null for account\n"; $pass++; } else { echo "FAIL C9 " . implode('; ', $c9) . "\n"; $failures[] = 'C9'; }

    // C10 resumePoint = MAX(pushed_at). acc_qbo_invoice_map has no realm_id →
    // global MAX; seed a guaranteed-max future-dated sentinel so the assertion
    // is robust against whatever real map rows already exist.
    db_execute("INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at, last_synced_at) VALUES (999991,'QBO-HP-INV-1','0','pushed','2099-01-01 00:00:00',NOW())");
    $rpoint = HistoricalPuller::resumePoint('invoice');
    if ($rpoint !== null && strpos((string) $rpoint, '2099-01-01') === 0) { echo "PASS C10 resumePoint = MAX(pushed_at)\n"; $pass++; }
    else { echo "FAIL C10 got " . var_export($rpoint, true) . "\n"; $failures[] = 'C10'; }

    // C11 alreadyMapped
    if (HistoricalPuller::alreadyMapped('invoice', 'QBO-HP-INV-1') === true && HistoricalPuller::alreadyMapped('invoice', 'NOPE-999') === false) {
        echo "PASS C11 alreadyMapped true for mapped, false otherwise\n"; $pass++;
    } else { echo "FAIL C11\n"; $failures[] = 'C11'; }

    // C12 tally + checkpoint merge
    HistoricalPuller::tallyCounts($runId, 'invoice', ['pulled' => 5, 'skipped' => 2]);
    HistoricalPuller::tallyCounts($runId, 'invoice', ['pulled' => 3, 'inserted' => 1]);
    HistoricalPuller::recordCheckpoint($runId, 'invoice', '2026-05-15 10:00:00');
    $run2 = HistoricalPuller::getRun($runId);
    $counts = json_decode((string) $run2['entity_counts'], true);
    $cps = json_decode((string) $run2['checkpoints'], true);
    if (($counts['invoice']['pulled'] ?? 0) === 8 && ($counts['invoice']['skipped'] ?? 0) === 2 && ($counts['invoice']['inserted'] ?? 0) === 1 && ($cps['invoice'] ?? '') === '2026-05-15 10:00:00') {
        echo "PASS C12 tallyCounts + recordCheckpoint merge JSON\n"; $pass++;
    } else { echo "FAIL C12 counts=" . json_encode($counts) . " cps=" . json_encode($cps) . "\n"; $failures[] = 'C12'; }

    $c13 = [];
    try { HistoricalPuller::pullTransactional($runId, 'customer'); $c13[] = 'pullTransactional should reject customer'; }
    catch (QuickBooksException $e) { /* expected */ }
    try { HistoricalPuller::writeFfRowFromQbo('invoice', ['Id' => '1']); $c13[] = 'writeFfRowFromQbo should refuse'; }
    catch (QuickBooksException $e) { /* expected (F29 seam) */ }
    if (empty($c13)) { echo "PASS C13 pullTransactional rejects non-transactional; writeFfRowFromQbo refuses (F29)\n"; $pass++; }
    else { echo "FAIL C13 " . implode('; ', $c13) . "\n"; $failures[] = 'C13'; }

    // ══ Module C — H5/H6 detection ════════════════════════════════════════
    $det = ArDriftRemediator::detect();
    $h5ids = array_column($det['h5'] ?? [], 'invoice_id');
    $h6ids = array_column($det['h6'] ?? [], 'invoice_id');

    $h5row = null; foreach ($det['h5'] ?? [] as $h) { if ((int) $h['invoice_id'] === 999991) $h5row = $h; }
    if ($h5row && bccomp((string) $h5row['missing_dr_ar'], '100.00', 2) === 0) { echo "PASS C14 H5 invoice detected (missing_dr_ar=100.00)\n"; $pass++; }
    else { echo "FAIL C14 h5row=" . json_encode($h5row) . "\n"; $failures[] = 'C14'; }

    $h6row = null; foreach ($det['h6'] ?? [] as $h) { if ((int) $h['invoice_id'] === 999992) $h6row = $h; }
    if ($h6row && bccomp((string) $h6row['excess'], '30.00', 2) === 0 && bccomp((string) $h6row['posted_dr_ar'], '110.00', 2) === 0) { echo "PASS C15 H6 invoice detected (excess=30.00, posted=110.00)\n"; $pass++; }
    else { echo "FAIL C15 h6row=" . json_encode($h6row) . "\n"; $failures[] = 'C15'; }

    if (!in_array(999993, $h5ids, true) && !in_array(999993, $h6ids, true)) { echo "PASS C16 clean invoice flagged in neither bucket\n"; $pass++; }
    else { echo "FAIL C16 clean invoice 999993 wrongly flagged\n"; $failures[] = 'C16'; }

    $plan = ArDriftRemediator::buildPlan($det);
    $planH5 = null; $planH6 = null;
    foreach ($plan as $p) { if ((int) $p['invoice_id'] === 999991) $planH5 = $p; if ((int) $p['invoice_id'] === 999992) $planH6 = $p; }
    $c17 = [];
    if (!$planH5 || $planH5['case'] !== 'H5' || $planH5['direction'] !== 'DR' || bccomp((string) $planH5['amount'], '100.00', 2) !== 0 || $planH5['tag'] !== '[A1-FIX-invoice-999991]') $c17[] = 'H5 plan entry wrong';
    if (!$planH6 || $planH6['case'] !== 'H6' || $planH6['direction'] !== 'CR' || bccomp((string) $planH6['amount'], '30.00', 2) !== 0 || $planH6['tag'] !== '[A1-FIX-invoice-999992]') $c17[] = 'H6 plan entry wrong';
    if (empty($c17)) { echo "PASS C17 buildPlan emits tagged H5 DR + H6 CR entries\n"; $pass++; } else { echo "FAIL C17 " . implode('; ', $c17) . " plan=" . json_encode([$planH5,$planH6]) . "\n"; $failures[] = 'C17'; }

    // C18 detectAndReport — reported + plan stored; NOTHING posted (no new JE rows).
    $jeBefore = (int) db_count("SELECT COUNT(*) FROM acc_journal_entries");
    $rep = ArDriftRemediator::detectAndReport($runId);
    $jeAfter = (int) db_count("SELECT COUNT(*) FROM acc_journal_entries");
    $runRep = HistoricalPuller::getRun($runId);
    if (($rep['remediation_status'] ?? '') === 'reported' && ($runRep['remediation_status'] ?? '') === 'reported' && !empty($runRep['remediation_plan']) && $jeAfter === $jeBefore) {
        echo "PASS C18 detectAndReport → 'reported' + plan stored + ZERO JEs posted\n"; $pass++;
    } else { echo "FAIL C18 status=" . ($rep['remediation_status'] ?? '?') . " jeBefore={$jeBefore} jeAfter={$jeAfter}\n"; $failures[] = 'C18'; }

    // C19 postApprovedPlan refused (status not 'approved' + dry-run).
    try { ArDriftRemediator::postApprovedPlan($runId); echo "FAIL C19 postApprovedPlan should refuse\n"; $failures[] = 'C19'; }
    catch (QuickBooksException $e) { echo "PASS C19 postApprovedPlan refused (gated)\n"; $pass++; }

    // ══ Module D — endpoint integration ══════════════════════════════════
    $startSrc = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/historical_pull/start.php');
    if (strpos($startSrc, 'detectAndReport') !== false && strpos($startSrc, "require_permission('quickbooks', 'force_full_resync')") !== false) {
        echo "PASS C20 start.php has detectAndReport + force_full_resync gate\n"; $pass++;
    } else { echo "FAIL C20 start.php missing pieces\n"; $failures[] = 'C20'; }

    $statusOk = is_file(__DIR__ . '/../api/v1/quickbooks/historical_pull/status.php');
    $planOk = is_file(__DIR__ . '/../api/v1/quickbooks/historical_pull/remediation_plan.php');
    $lintS = trim((string) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/v1/quickbooks/historical_pull/status.php') . ' 2>&1'));
    $lintP = trim((string) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/v1/quickbooks/historical_pull/remediation_plan.php') . ' 2>&1'));
    if ($statusOk && $planOk && strpos($lintS, 'No syntax errors') !== false && strpos($lintP, 'No syntax errors') !== false) {
        echo "PASS C21 status.php + remediation_plan.php exist + lint clean\n"; $pass++;
    } else { echo "FAIL C21 status={$statusOk} plan={$planOk}\n"; $failures[] = 'C21'; }

    $msSrc = (string) file_get_contents(__DIR__ . '/../app/admin/quickbooks/manual_sync.php');
    if (strpos($msSrc, 'qboHistoricalPull') !== false && strpos($msSrc, 'historical_pull/start.php') !== false) {
        echo "PASS C22 manual_sync.php hosts qboHistoricalPull section (D-QBO-27-7)\n"; $pass++;
    } else { echo "FAIL C22 manual_sync missing historical-pull section\n"; $failures[] = 'C22'; }

} finally {
    foreach ($createdRunIds as $rid) { db_execute("DELETE FROM acc_qbo_historical_pull_runs WHERE id = ?", [$rid]); }
    ff_smoke_hp_cleanup();
    foreach ($snapshot as $k => $v) {
        if ($v === null) { db_execute("DELETE FROM settings WHERE `key` = ?", [$k]); }
        else { ff_smoke_hp_set($k, (string) $v); }
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "historical_pull_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total ? 0 : 1);
