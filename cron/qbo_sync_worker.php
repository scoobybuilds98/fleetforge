<?php
declare(strict_types=1);

/**
 * cron/qbo_sync_worker.php
 *
 * QBO sync worker. Runs every 1 minute; picks up to 10 due items
 * from acc_qbo_sync_queue and dispatches each through
 * QboPusherDispatcher.
 *
 * Crontab: * * * * * php /var/www/fleetforge/cron/qbo_sync_worker.php
 *   (Do NOT install crontab in S-QBO-3 — operator wires this during
 *    S-QBO-30 production cutover alongside the other QBO crons.)
 *
 * Decision tree per worker run:
 *
 *   GET_LOCK('ff_qbo_sync_worker', 0)              ── single-host advisory lock per D21
 *     │
 *     ├─ already held → exit 0 silently
 *     │
 *     ▼
 *   Read quickbooks.sync_enabled                   ── master kill-switch per D-CPA-5
 *     │
 *     ├─ '0' → log + RELEASE_LOCK + exit 0
 *     │
 *     ▼
 *   Read quickbooks.dry_run_mode                   ── safe-mode flag
 *     │
 *     ▼
 *   SELECT … FROM acc_qbo_sync_queue
 *     WHERE status='queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW())
 *     ORDER BY priority ASC, enqueued_at ASC
 *     LIMIT 10 FOR UPDATE                          ── batch claim with row lock per D20
 *
 *   For each row:
 *     ┌─ Check sync_mode.<entity_type>:
 *     │    'off' → mark 'skipped'; continue
 *     ├─ Check QboPusherDispatcher::hasImplementation():
 *     │    no  → mark 'failed' with error_code='pusher_not_implemented';
 *     │          do NOT notify (expected pre-S-QBO-5); continue
 *     ├─ If dry_run_mode='1':
 *     │    log only, mark 'completed' with error_message='[DRY RUN]'; continue
 *     ├─ Try QboPusherDispatcher::dispatch(...):
 *     │    success                    → mark 'completed'
 *     │    QuickBooksTransientException → if retry budget remains, requeue
 *     │                                   with next_retry_at = NOW() + 2^retry minutes;
 *     │                                   else mark 'failed' + notify + drift event
 *     │    QuickBooksException (other) → mark 'failed' + notify + drift event
 *     │    Throwable (unexpected)     → mark 'failed' + capture to Sentry + notify
 *
 *   RELEASE_LOCK
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §6.7 + S-QBO-3 extensions
 *           (D-QBO-3-1: kill-switch + dry-run + sync_mode.off + drift_events
 *            insertion + pusher_not_implemented notification suppression)
 *
 * @session  S-QBO-3
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPusherDispatcher;
use FleetForge\Exceptions\PusherNotImplementedException;
use FleetForge\Exceptions\QuickBooksTransientException;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Notifications\NotificationService;

\FleetForge\Observability\Sentry::init();

// ── D21 advisory lock ─────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_qbo_sync_worker', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    // Another worker is running. Silent exit — cron will fire us
    // again next minute.
    exit(0);
}

$startedAt = date('c');
$processed = 0;
$completed = 0;
$skipped   = 0;
$failed    = 0;
$deferred  = 0;

try {
    // ── Master kill-switch (D-CPA-5) ──────────────────────────
    $masterEnabled = (string) settings_get('quickbooks.sync_enabled', '0') === '1';
    if (!$masterEnabled) {
        echo "[{$startedAt}] QBO sync disabled (quickbooks.sync_enabled='0'); exiting.\n";
        exit(0); // finally{} block releases lock
    }

    $dryRun      = (string) settings_get('quickbooks.dry_run_mode', '0') === '1';
    $workerId    = gethostname() . '-' . getmypid() . '-' . substr(uniqid(), -6);
    $environment = (string) settings_get('quickbooks.environment', 'sandbox');
    $realmId     = (string) settings_get('quickbooks.realm_id', '');

    // ── Pre-resolve notification audience (super_admin + accountant) ──
    // Per S-QBO-1 precedent (cron/qbo_token_refresh.php) we pass
    // $specificUserIds explicitly because user_permissions has no
    // 'quickbooks' rows yet. See D-QBO-3-3 in PROGRESS.md DECISIONS.
    $notifyUserIds = [];
    try {
        $rows = db_select(
            "SELECT u.id
               FROM users u
               JOIN user_roles r ON r.id = u.role_id
              WHERE r.slug IN ('super_admin','accountant')
                AND u.status = 'active'
                AND u.deleted_at IS NULL",
            []
        );
        $notifyUserIds = array_map(static fn($r) => (int) $r['id'], $rows);
    } catch (\Throwable $audErr) {
        error_log('cron/qbo_sync_worker: audience resolution failed — ' . $audErr->getMessage());
    }

    // ── Batch claim under FOR UPDATE (D20) ────────────────────
    // WHY: even with the advisory lock above, FOR UPDATE is a
    // belt-and-suspenders guard against the (impossible-under-D21)
    // scenario of two workers racing. Worth the trivial cost.
    $picked = db_transaction(function () use ($workerId): array {
        $rows = db_select(
            "SELECT id, entity_type, entity_id, operation, retry_count, max_retries, payload_snapshot
               FROM acc_qbo_sync_queue
              WHERE status = 'queued'
                AND (next_retry_at IS NULL OR next_retry_at <= NOW())
              ORDER BY priority ASC, enqueued_at ASC
              LIMIT 10
              FOR UPDATE",
            []
        );

        // Stamp each picked row processing IN-TRANSACTION so the
        // next worker run can't re-pick them even if the advisory
        // lock somehow released between the SELECT and the UPDATE.
        foreach ($rows as $r) {
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='processing', picked_up_at=NOW(), worker_id=?
                  WHERE id=? AND status='queued'",
                [$workerId, (int) $r['id']]
            );
        }
        return $rows;
    });

    if (empty($picked)) {
        echo "[{$startedAt}] QBO sync: 0 due items; exiting.\n";
        exit(0);
    }

    // ── Process picked rows OUTSIDE the claim transaction ─────
    // WHY: real QBO HTTP calls take 300-800ms (per spec §6.2);
    // holding row locks across that latency would block the
    // queue. Each row is independent now — the 'processing'
    // status stamp acts as a logical lock.
    foreach ($picked as $row) {
        $processed++;
        $qid           = (int) $row['id'];
        $entityType    = (string) $row['entity_type'];
        $entityId      = (int) $row['entity_id'];
        $operation     = (string) $row['operation'];
        $retryCount    = (int) $row['retry_count'];
        $maxRetries    = (int) $row['max_retries'];
        $payloadSnap   = $row['payload_snapshot'] !== null
            ? json_decode((string) $row['payload_snapshot'], true)
            : null;

        // ── Per-entity sync_mode.off check ────────────────────
        $mode = (string) settings_get('quickbooks.sync_mode.' . $entityType, 'queue');
        if ($mode === 'off') {
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='skipped', completed_at=NOW(),
                        error_code='sync_mode_off',
                        error_message='Entity type sync_mode is off; not pushed.'
                  WHERE id=?",
                [$qid]
            );
            $skipped++;
            echo "[{$startedAt}] SKIP queue#{$qid} {$entityType}#{$entityId} — sync_mode=off\n";
            continue;
        }

        // ── Pusher availability check ─────────────────────────
        if (!QboPusherDispatcher::hasImplementation($entityType, $operation)) {
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='failed', completed_at=NOW(),
                        error_code='pusher_not_implemented',
                        error_message=?
                  WHERE id=?",
                [
                    "No Pusher registered for {$entityType}.{$operation} (expected pre-S-QBO-5+).",
                    $qid,
                ]
            );
            $failed++;
            // D-QBO-3-1: NO notification dispatched for pusher_not_implemented.
            // Operator knows this is the pre-Pusher state; spamming
            // during build-out is counterproductive.
            echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — pusher_not_implemented (suppressed notification)\n";
            continue;
        }

        // ── Dry-run short-circuit ─────────────────────────────
        if ($dryRun) {
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='completed', completed_at=NOW(),
                        error_message='[DRY RUN] would push'
                  WHERE id=?",
                [$qid]
            );
            $completed++;
            echo "[{$startedAt}] DRY  queue#{$qid} {$entityType}#{$entityId} {$operation}\n";
            continue;
        }

        // ── Real dispatch ─────────────────────────────────────
        try {
            QboPusherDispatcher::dispatch($entityType, $operation, $entityId, $payloadSnap);
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='completed', completed_at=NOW(),
                        error_code=NULL, error_message=NULL
                  WHERE id=?",
                [$qid]
            );
            $completed++;

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'update',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_sync_queue',
                'entity_id'    => $qid,
                'entity_label' => "{$entityType}#{$entityId} {$operation}",
                'notes'        => "QBO push completed by worker.",
                'ip_address'   => '127.0.0.1',
            ]);

            echo "[{$startedAt}] OK   queue#{$qid} {$entityType}#{$entityId} {$operation}\n";
        } catch (PusherNotImplementedException $e) {
            // Shouldn't happen — hasImplementation() above guards
            // against it. But the race window between check + invoke
            // exists; handle gracefully.
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='failed', completed_at=NOW(),
                        error_code='pusher_not_implemented',
                        error_message=?
                  WHERE id=?",
                [substr($e->getMessage(), 0, 500), $qid]
            );
            $failed++;
            echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — pusher_not_implemented (race; suppressed)\n";
        } catch (QuickBooksTransientException $e) {
            // Retryable — back off and requeue if budget remains.
            $newRetry = $retryCount + 1;
            if ($newRetry > $maxRetries) {
                // Budget exhausted — mark failed + notify + drift.
                db_execute(
                    "UPDATE acc_qbo_sync_queue
                        SET status='failed', completed_at=NOW(),
                            retry_count=?,
                            error_code=?,
                            error_message=?
                      WHERE id=?",
                    [
                        $newRetry,
                        $e->errorCode ?? 'transient_exhausted',
                        substr($e->getMessage(), 0, 500),
                        $qid,
                    ]
                );
                $failed++;
                dispatchFailureNotification($row, $e, $notifyUserIds);
                insertDriftEvent($row, 'push_failed', $e, $realmId, $environment);
                echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — transient exhausted ({$newRetry}/{$maxRetries})\n";
            } else {
                // Backoff: 2^n minutes (n=newRetry → 2, 4, 8, 16, 32 mins).
                $backoffMinutes = (int) pow(2, $newRetry);
                db_execute(
                    "UPDATE acc_qbo_sync_queue
                        SET status='queued',
                            retry_count=?,
                            next_retry_at=DATE_ADD(NOW(), INTERVAL ? MINUTE),
                            error_code=?,
                            error_message=?,
                            picked_up_at=NULL,
                            worker_id=NULL
                      WHERE id=?",
                    [
                        $newRetry,
                        $backoffMinutes,
                        $e->errorCode ?? 'transient',
                        substr($e->getMessage(), 0, 500),
                        $qid,
                    ]
                );
                $deferred++;
                echo "[{$startedAt}] DEFER queue#{$qid} {$entityType}#{$entityId} {$operation} — retry {$newRetry}/{$maxRetries} in {$backoffMinutes}m\n";
            }
        } catch (QuickBooksException $e) {
            // Permanent QBO failure (validation, stale_object,
            // duplicate_name, forbidden, not_found, auth_expired).
            // Mark failed + notify + drift.
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='failed', completed_at=NOW(),
                        error_code=?,
                        error_message=?
                  WHERE id=?",
                [
                    $e->errorCode ?? 'qbo_permanent',
                    substr($e->getMessage(), 0, 500),
                    $qid,
                ]
            );
            $failed++;
            dispatchFailureNotification($row, $e, $notifyUserIds);
            insertDriftEvent($row, 'push_failed', $e, $realmId, $environment);
            echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — " . ($e->errorCode ?? 'permanent') . "\n";
        } catch (\Throwable $e) {
            // Unexpected — treat as permanent, capture to Sentry,
            // notify operators. Should be rare.
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status='failed', completed_at=NOW(),
                        error_code='unexpected_error',
                        error_message=?
                  WHERE id=?",
                [substr($e->getMessage(), 0, 500), $qid]
            );
            $failed++;
            \FleetForge\Observability\Sentry::captureException($e);
            dispatchFailureNotification($row, $e, $notifyUserIds);
            echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — unexpected: " . substr($e->getMessage(), 0, 100) . "\n";
        }
    }
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_qbo_sync_worker')", []);
}

echo sprintf(
    "[%s] QBO sync done: processed=%d completed=%d skipped=%d failed=%d deferred=%d\n",
    date('c'), $processed, $completed, $skipped, $failed, $deferred
);

// ============================================================
// Helpers
// ============================================================

/**
 * dispatchFailureNotification — send a 'quickbooks.push_failed'
 * notification to the pre-resolved audience (super_admin + accountant).
 *
 * Per D-QBO-3-1: suppressed for error_code='pusher_not_implemented'
 * (the worker code path already routes around this — the helper is
 * called only after that path). Defensive re-check here in case a
 * caller forgets the suppression.
 */
function dispatchFailureNotification(array $row, \Throwable $e, array $userIds): void
{
    $errorCode = ($e instanceof \FleetForge\Exceptions\QuickBooksException)
        ? ($e->errorCode ?? '')
        : '';

    if ($errorCode === 'pusher_not_implemented') {
        return;
    }

    if (empty($userIds)) {
        // No audience resolved — log so the operator sees the gap.
        error_log('cron/qbo_sync_worker: no notification audience available (super_admin + accountant); failure not dispatched.');
        return;
    }

    try {
        NotificationService::notify(
            'quickbooks.push_failed',
            "QBO sync failed: {$row['entity_type']} #{$row['entity_id']}",
            "Reason: " . substr($e->getMessage(), 0, 300) . " Click to view the sync log entry.",
            $row['entity_type'],
            (int) $row['entity_id'],
            '/quickbooks/sync_log?queue_id=' . (int) $row['id'],
            $userIds,
            'critical'
        );
    } catch (\Throwable $notifErr) {
        error_log('cron/qbo_sync_worker: notification dispatch failed — ' . $notifErr->getMessage());
    }
}

/**
 * insertDriftEvent — log a row into acc_qbo_drift_events so the
 * Drift Dashboard (S-QBO-4) can surface push failures alongside the
 * drift cron's per-entity comparison findings.
 *
 * Called only on PERMANENT failure (transient exhausted OR
 * non-retryable category). Pusher_not_implemented is suppressed at
 * the worker level before this helper is reached.
 */
function insertDriftEvent(array $row, string $category, \Throwable $e, string $realmId, string $environment): void
{
    try {
        db_insert('acc_qbo_drift_events', [
            'detection_source' => 'push_failure',
            'category'         => $category,
            'entity_type'      => (string) $row['entity_type'],
            'entity_id'        => (int) $row['entity_id'],
            'description'      => substr($e->getMessage(), 0, 1000),
            'queue_id'         => (int) $row['id'],
            'realm_id'         => $realmId !== '' ? $realmId : 'unknown',
            'environment'      => $environment,
        ]);
    } catch (\Throwable $driftErr) {
        error_log('cron/qbo_sync_worker: drift_event insert failed — ' . $driftErr->getMessage());
    }
}
