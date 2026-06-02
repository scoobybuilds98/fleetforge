<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_queue.php
 *
 * S-QBO-3 — Static + behavioral smoke for sync infrastructure.
 * Offline (no real QBO HTTP). Self-cleaning — any test artifact
 * (queue row, audit_log row, settings flip) is reverted before exit.
 *
 * 13 sub-checks (C10–C13 added by S-CRON-FIX-3 — QBO worker hardening):
 *   C1: acc_qbo_sync_queue table exists with spec §6.4 columns + indexes
 *   C2: acc_qbo_drift_events table exists with spec §15 derivation
 *   C3: 15 quickbooks.sync_mode.* settings keys present with expected defaults
 *   C4: QboPusherDispatcher class exists with public methods dispatch + hasImplementation + classNameFor
 *   C5: QuickBooksSync class exists with public methods enqueue + syncDispatch + isEnabled + syncMode
 *   C6: PusherNotImplementedException class exists, extends QuickBooksException
 *   C7: cron/qbo_sync_worker.php exists and lints clean (php -l)
 *   C8: hasImplementation returns false for entity_types whose Pusher class
 *       hasn't shipped yet (invoice/vendor/journal_entry). 'customer' was
 *       in this list at S-QBO-3 ship but moved to "shipped" at S-QBO-6 —
 *       expect hasImplementation('customer','create') === true now.
 *   C9: Worker pusher_not_implemented pathway — insert fake queue row, flip sync_enabled=1, run worker,
 *       confirm row marked 'failed' with error_code='pusher_not_implemented' AND no notification dispatched
 *       (verified by counting notifications rows pre + post). Restore sync_enabled=0, delete all artifacts.
 *   C10: error_code overflow guard — qbo_safe_error_code() caps at 50 chars;
 *        a raw >50-char error_code write throws under strict mode (cron-audit HIGH-3 fix).
 *   C11: stale-processing reaper requeues an orphaned 'processing' row older than
 *        10min under the retry cap (→ queued, retry_count+1) + skips fresh rows.
 *   C12: stale-processing reaper fails a poison orphan (retry_count >= max_retries).
 *   C13: top-level-catch rescue marks THIS worker's mid-processing rows failed
 *        (worker_id-scoped) so a fatal can't orphan them.
 *
 * Exit 0 on all PASS; exit 1 with failure list.
 *
 * @session  S-QBO-3
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPusherDispatcher;
use FleetForge\QuickBooksSync;
use FleetForge\Exceptions\PusherNotImplementedException;
use FleetForge\Exceptions\QuickBooksException;

// S-CRON-FIX-3: load the worker's helper functions (qbo_safe_error_code /
// qbo_worker_reap_stale / qbo_worker_rescue_processing) WITHOUT running the
// worker body (FF_QBO_WORKER_INCLUDE early-return guard), so C10–C13 can
// exercise them directly inside BEGIN/ROLLBACK.
define('FF_QBO_WORKER_INCLUDE', true);
require_once FF_ROOT . '/cron/qbo_sync_worker.php';

$failures = [];
$pass     = 0;
$total    = 13;

// Helper — array of error messages for a check
$check = function (string $label, array $errs) use (&$pass, &$failures): void {
    if (empty($errs)) {
        echo "PASS {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$label}  " . implode('; ', $errs) . "\n";
        $failures[] = $label;
    }
};

// ── C1: sync_queue table shape ────────────────────────────────
$c1Errs = [];
try {
    $tbl = db_select("SHOW TABLES LIKE 'acc_qbo_sync_queue'", []);
    if (empty($tbl)) {
        $c1Errs[] = 'table missing';
    } else {
        $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_sync_queue", []), 'Field');
        $required = ['id','entity_type','entity_id','operation','status','priority','retry_count',
                     'max_retries','next_retry_at','error_message','error_code','enqueued_at',
                     'picked_up_at','completed_at','worker_id','payload_snapshot'];
        foreach ($required as $r) {
            if (!in_array($r, $cols, true)) { $c1Errs[] = "missing column: {$r}"; }
        }
        $idx = array_column(db_select("SHOW INDEX FROM acc_qbo_sync_queue", []), 'Key_name');
        foreach (['idx_status_priority','idx_entity','idx_retry'] as $r) {
            if (!in_array($r, $idx, true)) { $c1Errs[] = "missing index: {$r}"; }
        }
    }
} catch (Throwable $e) { $c1Errs[] = $e->getMessage(); }
$check('C1  acc_qbo_sync_queue table per spec §6.4', $c1Errs);

// ── C2: drift_events table shape ──────────────────────────────
$c2Errs = [];
try {
    $tbl = db_select("SHOW TABLES LIKE 'acc_qbo_drift_events'", []);
    if (empty($tbl)) {
        $c2Errs[] = 'table missing';
    } else {
        $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_drift_events", []), 'Field');
        $required = ['id','detected_at','detection_source','category','entity_type','entity_id',
                     'qbo_entity_id','ff_value','qbo_value','drift_amount','description','queue_id',
                     'resolved_at','resolved_by_user_id','resolution_note','realm_id','environment'];
        foreach ($required as $r) {
            if (!in_array($r, $cols, true)) { $c2Errs[] = "missing column: {$r}"; }
        }
        $idx = array_column(db_select("SHOW INDEX FROM acc_qbo_drift_events", []), 'Key_name');
        foreach (['idx_category_detected','idx_entity','idx_unresolved','idx_queue'] as $r) {
            if (!in_array($r, $idx, true)) { $c2Errs[] = "missing index: {$r}"; }
        }
    }
} catch (Throwable $e) { $c2Errs[] = $e->getMessage(); }
$check('C2  acc_qbo_drift_events table per spec §15', $c2Errs);

// ── C3: 13 sync_mode settings with expected defaults ──────────
$c3Errs  = [];
$expected = [
    'quickbooks.sync_mode.customer'            => 'sync',
    'quickbooks.sync_mode.vendor'              => 'sync',
    'quickbooks.sync_mode.invoice'             => 'sync',
    'quickbooks.sync_mode.payment'             => 'sync',
    'quickbooks.sync_mode.credit_memo'         => 'sync',
    'quickbooks.sync_mode.refund_receipt'      => 'sync',
    'quickbooks.sync_mode.bill'                => 'queue',
    'quickbooks.sync_mode.bill_payment'        => 'queue',
    'quickbooks.sync_mode.journal_entry'       => 'sync',
    'quickbooks.sync_mode.depreciation_je'     => 'queue',
    'quickbooks.sync_mode.recurring_je'        => 'queue',
    'quickbooks.sync_mode.tax_remittance_je'   => 'sync',
    'quickbooks.sync_mode.year_end_closing_je' => 'sync',
    'quickbooks.sync_mode.credit_application'  => 'sync', // S-QBO-CREDIT-MEMO-APPLY (F25) — separate from credit_memo so apply propagation can be paused independently
    'quickbooks.sync_mode.refund_receipt'      => 'sync', // S-QBO-17 (closes Phase QBO-7) — cash refund→RefundReceipt push
];
foreach ($expected as $k => $expectedVal) {
    $actual = settings_get($k);
    if ($actual !== $expectedVal) {
        $c3Errs[] = "{$k}: expected '{$expectedVal}', got " . var_export($actual, true);
    }
}
$check('C3  15 sync_mode settings keys with expected defaults', $c3Errs);

// ── C4: dispatcher class surface ──────────────────────────────
$c4Errs = [];
if (!class_exists(QboPusherDispatcher::class)) {
    $c4Errs[] = 'QboPusherDispatcher class not loaded';
} else {
    foreach (['dispatch','hasImplementation','classNameFor'] as $m) {
        if (!method_exists(QboPusherDispatcher::class, $m)) {
            $c4Errs[] = "missing method: {$m}";
        }
    }
    // Spot-check classNameFor produces PascalCase
    if (QboPusherDispatcher::classNameFor('credit_memo') !== 'CreditMemoPusher') {
        $c4Errs[] = "classNameFor('credit_memo') wrong: " . QboPusherDispatcher::classNameFor('credit_memo');
    }
    if (QboPusherDispatcher::classNameFor('journal_entry') !== 'JournalEntryPusher') {
        $c4Errs[] = "classNameFor('journal_entry') wrong";
    }
}
$check('C4  QboPusherDispatcher class + classNameFor snake→PascalCase', $c4Errs);

// ── C5: facade class surface ──────────────────────────────────
$c5Errs = [];
if (!class_exists(QuickBooksSync::class)) {
    $c5Errs[] = 'QuickBooksSync class not loaded';
} else {
    foreach (['enqueue','syncDispatch','isEnabled','syncMode'] as $m) {
        if (!method_exists(QuickBooksSync::class, $m)) {
            $c5Errs[] = "missing method: {$m}";
        }
    }
    // isEnabled should return false because sync_enabled='0' per D-CPA-5
    if (QuickBooksSync::isEnabled() !== false) {
        $c5Errs[] = 'isEnabled() should be false while quickbooks.sync_enabled=\'0\'';
    }
    // syncMode lookups
    if (QuickBooksSync::syncMode('customer') !== 'sync') {
        $c5Errs[] = "syncMode('customer') expected 'sync'";
    }
    if (QuickBooksSync::syncMode('bill') !== 'queue') {
        $c5Errs[] = "syncMode('bill') expected 'queue'";
    }
}
$check('C5  QuickBooksSync facade + isEnabled + syncMode lookups', $c5Errs);

// ── C6: PusherNotImplementedException ─────────────────────────
$c6Errs = [];
if (!class_exists(PusherNotImplementedException::class)) {
    $c6Errs[] = 'PusherNotImplementedException class not loaded';
} elseif (!is_subclass_of(PusherNotImplementedException::class, QuickBooksException::class)) {
    $c6Errs[] = 'PusherNotImplementedException must extend QuickBooksException';
}
$check('C6  PusherNotImplementedException extends QuickBooksException', $c6Errs);

// ── C7: worker cron exists + lints clean ──────────────────────
$c7Errs    = [];
$workerSrc = FF_ROOT . '/cron/qbo_sync_worker.php';
if (!is_file($workerSrc)) {
    $c7Errs[] = "worker file missing: {$workerSrc}";
} else {
    $lintOut = [];
    $lintCode = 0;
    exec('php -l ' . escapeshellarg($workerSrc) . ' 2>&1', $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $c7Errs[] = 'php -l failed: ' . implode("\n", $lintOut);
    }
}
$check('C7  cron/qbo_sync_worker.php exists and lints clean', $c7Errs);

// ── C8: hasImplementation true for shipped Pushers + false for unbuilt
// 'customer' shipped at S-QBO-6 (CustomerPusher exists).
// 'vendor'   shipped at S-QBO-7 (VendorPusher exists).
// 'invoice'  shipped at S-QBO-11 (InvoicePusher exists; pushUpdate is
//            a stub returning 'unsupported_in_session' per D-QBO-11-4
//            but method_exists() returns true — dispatcher considers it
//            "implemented"; the stub dead-letters the queue row cleanly).
// 'bill'     shipped at S-QBO-18 (BillPusher exists; pushUpdate stub
//            per D-QBO-18-5 — same stub-then-implement pattern).
// 'payment'  shipped at S-QBO-14 (PaymentPusher exists; pushUpdate
//            stub per D-QBO-14-5 — same pattern).
// Remaining entity types in the dispatcher's convention map are still
// pre-implementation as of S-QBO-14.
$c8Errs = [];
foreach ([
    ['customer', 'create'],
    ['customer', 'update'],
    ['vendor',   'create'],
    ['vendor',   'update'],
    ['invoice',  'create'],
    ['invoice',  'update'],
    ['bill',     'create'],
    ['bill',     'update'],
    ['payment',  'create'],
    ['payment',  'update'],
    ['credit_memo', 'create'],   // S-QBO-16: CreditMemoPusher shipped (pushCreate)
    ['credit_memo', 'update'],   // S-QBO-16: pushUpdate stub method exists (→ F20)
    ['credit_application', 'create'], // S-QBO-CREDIT-MEMO-APPLY (F25): CreditApplicationPusher::pushCreate
    ['credit_application', 'void'],   // F27 S-QBO-CREDIT-APP-UNAPPLY: CreditApplicationPusher::pushVoid
    ['refund_receipt', 'create'],     // S-QBO-17 (closes Phase QBO-7): RefundReceiptPusher::pushCreate
] as $pair) {
    if (QboPusherDispatcher::hasImplementation($pair[0], $pair[1]) !== true) {
        $c8Errs[] = "hasImplementation('{$pair[0]}','{$pair[1]}') should be true post-shipped";
    }
}
// item has no Pusher (S-QBO-10 was Puller+Creator only); journal_entry has
// no pushVoid (S-QBO-21 create+update only); credit_application has no
// pushUpdate/pushVoid (S-QBO-CREDIT-MEMO-APPLY v1 = forward-create only;
// un-apply is a follow-up per D-QBO-CREDIT-MEMO-APPLY-4). All three remain false.
// refund_receipt is create-only too (v1; a reversed refund is a fresh event).
// credit_application now has create + void (F27 pushVoid), still no update.
foreach ([['journal_entry','void'], ['item','create'], ['credit_application','update'], ['refund_receipt','update'], ['refund_receipt','void']] as $pair) {
    if (QboPusherDispatcher::hasImplementation($pair[0], $pair[1]) !== false) {
        $c8Errs[] = "hasImplementation('{$pair[0]}','{$pair[1]}') should be false pre-Pusher-session";
    }
}
$check('C8  hasImplementation true for customer+vendor+invoice+bill+payment+credit_memo+credit_application(create+void)+refund_receipt (S-QBO-6/7/11/18/14/16/CREDIT-MEMO-APPLY/CREDIT-APP-UNAPPLY/17 shipped), false for item + journal_entry-void + credit_application-update + refund_receipt update/void', $c8Errs);

// ── C9: worker pusher_not_implemented pathway (SELF-CLEANING) ──
// CRITICAL: every artifact created in this check MUST be reverted
// before exit so a re-run is clean. Use a try/finally + collect IDs
// for deletion at the end regardless of outcome.
$c9Errs       = [];
$createdQueue = null;
$createdAudit = [];
$origSyncFlag = (string) settings_get('quickbooks.sync_enabled', '0');
$notifBefore  = 0;
$notifAfter   = 0;

try {
    // Snapshot notifications row count before — to verify suppression
    $notifBefore = (int) db_count("SELECT COUNT(*) FROM notifications WHERE type LIKE 'quickbooks.%'", []);

    // Insert a fake queue row for an entity type with NO Pusher yet.
    // 'item' is an unbuilt Pusher (S-QBO-10 shipped ItemPuller + ItemCreator
    // but NO ItemPusher — items are operator-authored, not queue-pushed).
    // Migration history of this fixture as each prior entity got a Pusher:
    // 'customer' (S-QBO-3) → 'invoice' (S-QBO-11) → 'payment' (S-QBO-14)
    // → 'credit_memo' (S-QBO-16 shipped it 2026-05-29) → 'item' (current).
    $createdQueue = db_insert('acc_qbo_sync_queue', [
        'entity_type' => 'item',
        'entity_id'   => 999999, // sentinel ID — no real entity
        'operation'   => 'create',
        'status'      => 'queued',
        'priority'    => 5,
    ]);

    // Flip the kill-switch ON temporarily so the worker actually processes
    // (we still won't make any QBO calls — the Pusher doesn't exist yet,
    // which is exactly what we're testing).
    \FleetForge\QuickBooksClient::settings_write_qbo('sync_enabled', '1');

    // Run the worker as a subprocess — captures stdout for inspection
    $workerOut = [];
    exec('php ' . escapeshellarg(FF_ROOT . '/cron/qbo_sync_worker.php') . ' 2>&1', $workerOut);

    // Inspect queue row state
    $finalRow = db_row("SELECT status, error_code FROM acc_qbo_sync_queue WHERE id = ?", [$createdQueue]);
    if (!$finalRow) {
        $c9Errs[] = 'queue row vanished after worker run';
    } else {
        if ($finalRow['status'] !== 'failed') {
            $c9Errs[] = "expected status='failed', got '{$finalRow['status']}'";
        }
        if ($finalRow['error_code'] !== 'pusher_not_implemented') {
            $c9Errs[] = "expected error_code='pusher_not_implemented', got '" . ($finalRow['error_code'] ?? 'NULL') . "'";
        }
    }

    // Verify notification suppression — no new quickbooks.* notifications
    $notifAfter = (int) db_count("SELECT COUNT(*) FROM notifications WHERE type LIKE 'quickbooks.%'", []);
    if ($notifAfter !== $notifBefore) {
        $c9Errs[] = "notification suppression failed: pre={$notifBefore} post={$notifAfter} (pusher_not_implemented should NOT dispatch)";
    }

    // Verify NO drift event was inserted for this row either (suppression
    // applies symmetrically — operator already knows about pre-S-QBO-5 state)
    $driftCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events WHERE queue_id = ?",
        [$createdQueue]
    );
    if ($driftCount !== 0) {
        $c9Errs[] = "drift_events insert was NOT suppressed for pusher_not_implemented (count={$driftCount})";
    }
} catch (Throwable $e) {
    $c9Errs[] = "unexpected: " . $e->getMessage();
} finally {
    // ── SELF-CLEANUP — must run even on test failure ──────────
    // 1. Restore kill-switch
    try {
        \FleetForge\QuickBooksClient::settings_write_qbo('sync_enabled', $origSyncFlag);
    } catch (Throwable $cleanErr) {
        $c9Errs[] = 'cleanup: failed to restore sync_enabled: ' . $cleanErr->getMessage();
    }

    // 2. Delete the test queue row
    if ($createdQueue !== null) {
        try {
            db_execute("DELETE FROM acc_qbo_sync_queue WHERE id = ?", [$createdQueue]);
        } catch (Throwable $cleanErr) {
            $c9Errs[] = 'cleanup: failed to delete queue row: ' . $cleanErr->getMessage();
        }
    }

    // 3. Delete any drift_events associated (defensive — there shouldn't be any)
    if ($createdQueue !== null) {
        try {
            db_execute("DELETE FROM acc_qbo_drift_events WHERE queue_id = ?", [$createdQueue]);
        } catch (Throwable $cleanErr) {
            $c9Errs[] = 'cleanup: failed to delete drift events: ' . $cleanErr->getMessage();
        }
    }

    // 4. Delete any audit_log rows the worker may have written for this queue
    if ($createdQueue !== null) {
        try {
            db_execute(
                "DELETE FROM audit_log
                  WHERE module='quickbooks' AND entity_type='qbo_sync_queue' AND entity_id=?",
                [$createdQueue]
            );
        } catch (Throwable $cleanErr) {
            $c9Errs[] = 'cleanup: failed to delete audit_log rows: ' . $cleanErr->getMessage();
        }
    }
}
$check('C9  worker pusher_not_implemented pathway + suppression (self-cleaning)', $c9Errs);

// ══════════════════════════════════════════════════════════════
// S-CRON-FIX-3 — QBO worker hardening (cron-audit HIGH-3)
// error_code truncation + stale-processing reaper + top-level-catch rescue.
// Each check is hermetic: seed → exercise → assert inside BEGIN/ROLLBACK.
// Queue rows set picked_up_at via SQL NOW()/DATE_SUB so they match the worker's
// UTC NOW() stamping (PHP date() would be tz-skewed — that's a test footgun,
// not a worker bug: the reaper compares MySQL NOW() against picked_up_at).
// ══════════════════════════════════════════════════════════════

// Insert a synthetic queue row; picked_up_at is an inline SQL expression
// (test constant, not user input). Returns the new id.
$insQ = function (int $eid, string $status, int $rc, int $mr, string $puaSql, ?string $wid = null): int {
    db_execute(
        "INSERT INTO acc_qbo_sync_queue (entity_type,entity_id,operation,status,retry_count,max_retries,picked_up_at,worker_id)
         VALUES ('customer',?,'create',?,?,?,{$puaSql},?)",
        [$eid, $status, $rc, $mr, $wid]
    );
    return (int) db_row("SELECT LAST_INSERT_ID() AS id", [])['id'];
};
$qRow = fn(int $id) => db_row("SELECT status,retry_count,error_code,picked_up_at,worker_id FROM acc_qbo_sync_queue WHERE id=?", [$id]);

// ── C10: error_code overflow guard ────────────────────────────
$c10Errs = [];
db_execute('BEGIN');
try {
    $long = str_repeat('X', 80);
    if (strlen(qbo_safe_error_code($long)) !== 50) { $c10Errs[] = 'safeErrorCode(80) length=' . strlen(qbo_safe_error_code($long)) . ' (expect 50)'; }
    if (qbo_safe_error_code(null) !== 'unknown')   { $c10Errs[] = "safeErrorCode(null) !== 'unknown'"; }
    $id       = $insQ(990010, 'processing', 0, 5, 'NOW()');
    $rawThrew = false;
    try { db_execute("UPDATE acc_qbo_sync_queue SET error_code=? WHERE id=?", [$long, $id]); }
    catch (\Throwable $e) { $rawThrew = true; }
    if (!$rawThrew) { $c10Errs[] = 'raw 80-char error_code write did NOT throw (strict mode expected)'; }
    db_execute("UPDATE acc_qbo_sync_queue SET error_code=? WHERE id=?", [qbo_safe_error_code($long), $id]);
    $stored = (string) $qRow($id)['error_code'];
    if (strlen($stored) !== 50) { $c10Errs[] = 'capped write stored length=' . strlen($stored) . ' (expect 50)'; }
} catch (\Throwable $e) { $c10Errs[] = 'unexpected: ' . $e->getMessage(); }
finally { db_execute('ROLLBACK'); }
$check('C10 error_code overflow guard (safeErrorCode caps 50; raw >50 throws)', $c10Errs);

// ── C11: reaper requeues stale under cap + skips fresh ─────────
$c11Errs = [];
db_execute('BEGIN');
try {
    $stale = $insQ(990011, 'processing', 1, 5, 'DATE_SUB(NOW(), INTERVAL 20 MINUTE)');
    $fresh = $insQ(990012, 'processing', 0, 5, 'NOW()');
    qbo_worker_reap_stale(10);
    $rs = $qRow($stale);
    if ($rs['status'] !== 'queued')            { $c11Errs[] = "stale status='{$rs['status']}' (expect queued)"; }
    if ((int) $rs['retry_count'] !== 2)        { $c11Errs[] = "stale retry_count={$rs['retry_count']} (expect 2)"; }
    if ($rs['error_code'] !== 'stale_reclaim') { $c11Errs[] = "stale error_code='{$rs['error_code']}' (expect stale_reclaim)"; }
    if ($rs['picked_up_at'] !== null)          { $c11Errs[] = 'stale picked_up_at not cleared'; }
    if ($rs['worker_id'] !== null)             { $c11Errs[] = 'stale worker_id not cleared'; }
    if ($qRow($fresh)['status'] !== 'processing') { $c11Errs[] = 'fresh row wrongly reaped (expect still processing)'; }
} catch (\Throwable $e) { $c11Errs[] = 'unexpected: ' . $e->getMessage(); }
finally { db_execute('ROLLBACK'); }
$check('C11 reaper requeues stale processing under cap + skips fresh', $c11Errs);

// ── C12: reaper fails poison (retry exhausted) ────────────────
$c12Errs = [];
db_execute('BEGIN');
try {
    $poison = $insQ(990013, 'processing', 5, 5, 'DATE_SUB(NOW(), INTERVAL 20 MINUTE)');
    qbo_worker_reap_stale(10);
    $rp = $qRow($poison);
    if ($rp['status'] !== 'failed')              { $c12Errs[] = "poison status='{$rp['status']}' (expect failed)"; }
    if ($rp['error_code'] !== 'stale_exhausted') { $c12Errs[] = "poison error_code='{$rp['error_code']}' (expect stale_exhausted)"; }
} catch (\Throwable $e) { $c12Errs[] = 'unexpected: ' . $e->getMessage(); }
finally { db_execute('ROLLBACK'); }
$check('C12 reaper fails poison orphan (retry_count >= max_retries)', $c12Errs);

// ── C13: top-level-catch rescue (worker_id-scoped) ────────────
$c13Errs = [];
db_execute('BEGIN');
try {
    $mine  = $insQ(990014, 'processing', 0, 5, 'NOW()', 'unit-rescue-worker');
    $other = $insQ(990015, 'processing', 0, 5, 'NOW()', 'some-other-worker');
    $rescued = qbo_worker_rescue_processing('unit-rescue-worker', 'worker_fatal', str_repeat('Z', 700));
    if ($rescued !== 1) { $c13Errs[] = "rescued count={$rescued} (expect 1)"; }
    $rm = $qRow($mine);
    if ($rm['status'] !== 'failed')           { $c13Errs[] = "my row status='{$rm['status']}' (expect failed — not orphaned)"; }
    if ($rm['error_code'] !== 'worker_fatal') { $c13Errs[] = "my row error_code='{$rm['error_code']}' (expect worker_fatal)"; }
    if ($qRow($other)['status'] !== 'processing') { $c13Errs[] = 'other-worker row was touched (expect untouched)'; }
} catch (\Throwable $e) { $c13Errs[] = 'unexpected: ' . $e->getMessage(); }
finally { db_execute('ROLLBACK'); }
$check('C13 top-level-catch rescue marks own processing rows failed (worker_id-scoped)', $c13Errs);

// ── Final cleanup verification ────────────────────────────────
$leftoverQueue = (int) db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = 999999", []);
$finalFlag     = (string) settings_get('quickbooks.sync_enabled', '0');
if ($leftoverQueue !== 0) {
    echo "WARN C9 cleanup left {$leftoverQueue} queue rows with entity_id=999999\n";
}
if ($finalFlag !== $origSyncFlag) {
    echo "WARN C9 cleanup did not restore sync_enabled (now='{$finalFlag}', orig='{$origSyncFlag}')\n";
}

// ── Summary ───────────────────────────────────────────────────
echo "\nqbo_queue_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures);
    echo "\n";
    exit(1);
}
echo "\n";
exit(0);
