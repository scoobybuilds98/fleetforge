<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_queue.php
 *
 * S-QBO-3 — Static + behavioral smoke for sync infrastructure.
 * Offline (no real QBO HTTP). Self-cleaning — any test artifact
 * (queue row, audit_log row, settings flip) is reverted before exit.
 *
 * 9 sub-checks:
 *   C1: acc_qbo_sync_queue table exists with spec §6.4 columns + indexes
 *   C2: acc_qbo_drift_events table exists with spec §15 derivation
 *   C3: 13 quickbooks.sync_mode.* settings keys present with expected defaults
 *   C4: QboPusherDispatcher class exists with public methods dispatch + hasImplementation + classNameFor
 *   C5: QuickBooksSync class exists with public methods enqueue + syncDispatch + isEnabled + syncMode
 *   C6: PusherNotImplementedException class exists, extends QuickBooksException
 *   C7: cron/qbo_sync_worker.php exists and lints clean (php -l)
 *   C8: hasImplementation('customer','create') === false (no Pushers yet — expected pre-S-QBO-5)
 *   C9: Worker pusher_not_implemented pathway — insert fake queue row, flip sync_enabled=1, run worker,
 *       confirm row marked 'failed' with error_code='pusher_not_implemented' AND no notification dispatched
 *       (verified by counting notifications rows pre + post). Restore sync_enabled=0, delete all artifacts.
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

$failures = [];
$pass     = 0;
$total    = 9;

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
];
foreach ($expected as $k => $expectedVal) {
    $actual = settings_get($k);
    if ($actual !== $expectedVal) {
        $c3Errs[] = "{$k}: expected '{$expectedVal}', got " . var_export($actual, true);
    }
}
$check('C3  13 sync_mode settings keys with expected defaults', $c3Errs);

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

// ── C8: hasImplementation false for unbuilt Pushers ───────────
$c8Errs = [];
foreach ([['customer','create'], ['invoice','update'], ['journal_entry','void']] as $pair) {
    if (QboPusherDispatcher::hasImplementation($pair[0], $pair[1]) !== false) {
        $c8Errs[] = "hasImplementation('{$pair[0]}','{$pair[1]}') should be false pre-S-QBO-5";
    }
}
$check('C8  hasImplementation returns false for unbuilt Pushers', $c8Errs);

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

    // Insert a fake queue row for an entity type with NO Pusher yet
    $createdQueue = db_insert('acc_qbo_sync_queue', [
        'entity_type' => 'customer',
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
