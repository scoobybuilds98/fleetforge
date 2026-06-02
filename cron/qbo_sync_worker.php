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
use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\PusherNotImplementedException;
use FleetForge\Exceptions\QuickBooksTransientException;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Notifications\NotificationService;

\FleetForge\Observability\Sentry::init();

// ── Testability seam (S-CRON-FIX-3) ───────────────────────────
// When required by a smoke (FF_QBO_WORKER_INCLUDE defined), expose the helper
// functions at the bottom of this file WITHOUT running the worker body. Those
// top-level function declarations are hoisted, so they remain available to the
// includer even though this early return skips the executable body below.
if (defined('FF_QBO_WORKER_INCLUDE')) {
    return;
}

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
$workerId  = null;   // set once sync is enabled (below); referenced by the top-level catch
$fatal     = false;  // set by the top-level catch so we exit non-zero AFTER finally runs

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

    // ── Stale-processing reaper (S-CRON-FIX-3, D-QBO-WORKER-STALE-REAPER) ──
    // Reclaim rows orphaned in 'processing' by a crashed worker BEFORE claiming
    // new work. Runs under the advisory lock acquired above (so two workers
    // can't both reap), and before the claim loop. Requeues rows still under
    // the retry cap; fails poison rows that exhausted retries.
    $reap = qbo_worker_reap_stale(10);
    if ($reap['requeued'] > 0 || $reap['failed'] > 0) {
        echo "[{$startedAt}] REAP reclaimed {$reap['requeued']} stale row(s) → queued; failed {$reap['failed']} exhausted.\n";
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
            // D-QBO-FIXPACK-15 (Bug C): Set static worker context so
            // QuickBooksClient::writeSyncLog() can annotate sync_log
            // rows with queue_id + entity_id even when the Pusher
            // doesn't carry the queue context (Pushers only receive
            // $entityId + optional $payloadSnapshot, not the queue row).
            QuickBooksClient::setWorkerContext($qid, $entityType, $entityId);

            $result = QboPusherDispatcher::dispatch($entityType, $operation, $entityId, $payloadSnap);

            // S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE (D-WORKER-OUTCOME-DISPATCH-3):
            // Worker now branches on the explicit `outcome` field
            // ('created' | 'updated' | 'skipped' | 'failed') so silent-success
            // skip paths get tallied under skipped= (NOT completed=) and the
            // queue row's status reflects reality.
            //
            // Backward-compat shim: until ALL Pushers (Account/Item/TaxCode
            // and any future) emit `outcome` on every path, infer from
            // existing fields. This is transitional — remove the shim once
            // every Pusher's return shape includes outcome.
            $outcome = $result['outcome'] ?? null;
            if ($outcome === null) {
                $status = (string) ($result['status'] ?? '');
                if ($status !== '' && str_starts_with($status, 'skipped_')) {
                    $outcome = 'skipped';
                } elseif (empty($result['success'])) {
                    $outcome = 'failed';
                } else {
                    $outcome = 'created';
                }
            }

            if ($outcome === 'created' || $outcome === 'updated') {
                // ── Real push succeeded (or replay no-op via already_mapped) ──
                // D-QBO-FIXPACK-14 (Bug B) + D-QBO-FIXPACK-16 (Bug A) discipline:
                // queue.status='completed' only when Pusher actually pushed
                // (or confirmed prior push via already_mapped).
                db_execute(
                    "UPDATE acc_qbo_sync_queue
                        SET status='completed', completed_at=NOW(),
                            error_code=NULL, error_message=NULL
                      WHERE id=?",
                    [$qid]
                );
                $completed++; // D-QBO-FIXPACK-16: only increment when push actually succeeded

                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'update',
                    'module'       => 'quickbooks',
                    'entity_type'  => 'qbo_sync_queue',
                    'entity_id'    => $qid,
                    'entity_label' => "{$entityType}#{$entityId} {$operation}",
                    'notes'        => "QBO push completed by worker (status=" . ($result['status'] ?? 'ok') . ", outcome={$outcome}).",
                    'ip_address'   => '127.0.0.1',
                ]);

                echo "[{$startedAt}] OK   queue#{$qid} {$entityType}#{$entityId} {$operation}\n";
            } elseif ($outcome === 'skipped') {
                // ── Pusher decided not to push (mode/voided/soft-deleted) ──
                // S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE: queue.status='skipped'
                // (not 'completed') so the QBO admin queue table tells the
                // truth. Pusher's recordSkipped (invoice path) already wrote
                // the map row + sync_log entry; worker just records the
                // queue-level outcome here.
                $typedCode = qbo_safe_error_code((string) ($result['status'] ?? 'skipped'));
                $skipMsg   = substr("Skipped: " . ($result['status'] ?? 'unknown'), 0, 500);
                db_execute(
                    "UPDATE acc_qbo_sync_queue
                        SET status='skipped', completed_at=NOW(),
                            error_code=?,
                            error_message=?
                      WHERE id=?",
                    [$typedCode, $skipMsg, $qid]
                );
                $skipped++;

                echo "[{$startedAt}] SKIP queue#{$qid} {$entityType}#{$entityId} {$operation} ({$typedCode})\n";
            } else {
                // ── Pusher returned outcome='failed' (or implicit failure) ──
                // D-QBO-FIXPACK-14 (Bug B): Pusher returned success=false without
                // throwing. Mark queue row 'failed' with the error from result.
                $errorMsg  = substr((string) ($result['error'] ?? $result['status'] ?? 'Pusher returned success=false'), 0, 500);
                $errorCode = qbo_safe_error_code((string) ($result['error_code'] ?? $result['status'] ?? 'pusher_failed'));
                db_execute(
                    "UPDATE acc_qbo_sync_queue
                        SET status='failed', completed_at=NOW(),
                            error_code=?,
                            error_message=?
                      WHERE id=?",
                    [$errorCode, $errorMsg, $qid]
                );
                $failed++; // D-QBO-FIXPACK-16 (Bug A): increment failed, not completed

                // Dispatch failure notification + drift event (same as caught-exception path).
                // Build a synthetic QuickBooksException so the helper signatures match.
                $syntheticErr = new QuickBooksException($errorMsg, $errorCode, null);
                dispatchFailureNotification($row, $syntheticErr, $notifyUserIds);
                insertDriftEvent($row, 'push_failed', $syntheticErr, $realmId, $environment);

                echo "[{$startedAt}] FAIL queue#{$qid} {$entityType}#{$entityId} {$operation} — {$errorCode} (pusher returned success=false)\n";
            }
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
                        qbo_safe_error_code($e->errorCode ?? 'transient_exhausted'),
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
                        qbo_safe_error_code($e->errorCode ?? 'transient'),
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
                    qbo_safe_error_code($e->errorCode ?? 'qbo_permanent'),
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
        } finally {
            // D-QBO-FIXPACK-15 (Bug C): Clear static worker context after each
            // queue row so the next row doesn't inherit a stale queue_id/entity_id.
            // Using finally ensures context is always cleared even if an uncaught
            // exception propagates (which shouldn't happen — every \Throwable is
            // caught above — but defensive-in-depth).
            QuickBooksClient::setWorkerContext(null, null, null);
        }
    }
} catch (\Throwable $fatalErr) {
    // ── Last line of defense (S-CRON-FIX-3, D-QBO-WORKER-TOPLEVEL-CATCH) ──
    // A throw that escaped the per-row arms — e.g. one thrown INSIDE a catch
    // arm (sibling arms can't catch it), in a pre-dispatch check, or in the
    // claim transaction — must NOT die without a record nor orphan the row it
    // was mid-processing. We deliberately do NOT exit() here so the finally
    // below still runs (PHP skips finally on exit()); $fatal drives a non-zero
    // exit AFTER finally releases the lock.
    $fatal = true;
    error_log('cron/qbo_sync_worker: FATAL — ' . $fatalErr->getMessage()
        . ($workerId !== null ? " (worker_id={$workerId})" : ''));
    // Sentry may be a no-op here (cron-audit MED-8 — Sentry::init() wiring is a
    // separate session); make the call regardless so it reports once that lands.
    \FleetForge\Observability\Sentry::captureException($fatalErr);

    if ($workerId !== null) {
        try {
            // Mark every row THIS worker left in 'processing' as failed so none
            // orphan. worker_id is unique per process, so this never touches
            // another worker's in-flight rows.
            $rescued = qbo_worker_rescue_processing(
                $workerId,
                'worker_fatal',
                'Worker fatal before row reached a terminal state: ' . $fatalErr->getMessage()
            );
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_sync_queue',
                'entity_id'    => null,
                'entity_label' => 'qbo_sync_worker',
                'notes'        => "qbo_sync_worker FATAL — rescued {$rescued} mid-processing row(s) → failed. " . substr($fatalErr->getMessage(), 0, 300),
                'ip_address'   => '127.0.0.1',
            ]);
            echo "[{$startedAt}] FATAL worker error; rescued {$rescued} mid-processing row(s) → failed: " . substr($fatalErr->getMessage(), 0, 120) . "\n";
        } catch (\Throwable $rescueErr) {
            error_log('cron/qbo_sync_worker: rescue after fatal FAILED — ' . $rescueErr->getMessage());
        }
    }
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_qbo_sync_worker')", []);
}

if ($fatal) {
    // Non-zero exit AFTER finally released the lock (the catch above set the
    // flag instead of exiting, precisely because finally is skipped on exit()).
    exit(1);
}

echo sprintf(
    "[%s] QBO sync done: processed=%d completed=%d skipped=%d failed=%d deferred=%d\n",
    date('c'), $processed, $completed, $skipped, $failed, $deferred
);

// ============================================================
// Helpers
// ============================================================

/**
 * qbo_safe_error_code — clamp any error_code to the
 * acc_qbo_sync_queue.error_code column width (varchar(50)). Only the SHORT
 * typed code is truncated; the FULL human-readable detail always goes to
 * error_message (TEXT) separately, so there is no information loss overall.
 * Single point to update if the column ever widens.
 * (S-CRON-FIX-3, D-QBO-WORKER-ERRORCODE-TRUNCATE)
 */
function qbo_safe_error_code(?string $code): string
{
    return substr((string) ($code ?? 'unknown'), 0, 50);
}

/**
 * qbo_worker_reap_stale — reclaim rows orphaned in 'processing' by a crashed
 * worker (picked_up_at older than $thresholdMinutes). Requeues rows still under
 * the retry cap (status='queued', retry_count+1, picked_up_at/worker_id cleared
 * so they re-pick cleanly); fails poison rows that already hit max_retries.
 * Respects the max_retries cap — never infinite-loops a poison row.
 * (S-CRON-FIX-3, D-QBO-WORKER-STALE-REAPER)
 *
 * @return array{requeued:int,failed:int}
 */
function qbo_worker_reap_stale(int $thresholdMinutes = 10): array
{
    $requeued = db_execute(
        "UPDATE acc_qbo_sync_queue
            SET status='queued',
                retry_count=retry_count+1,
                picked_up_at=NULL,
                worker_id=NULL,
                error_code=?,
                error_message='Reclaimed orphaned processing row (worker crash); requeued.'
          WHERE status='processing'
            AND picked_up_at IS NOT NULL
            AND picked_up_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND retry_count < max_retries",
        [qbo_safe_error_code('stale_reclaim'), $thresholdMinutes]
    );

    $failed = db_execute(
        "UPDATE acc_qbo_sync_queue
            SET status='failed',
                completed_at=NOW(),
                error_code=?,
                error_message='Orphaned processing row exceeded max_retries; failed.'
          WHERE status='processing'
            AND picked_up_at IS NOT NULL
            AND picked_up_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND retry_count >= max_retries",
        [qbo_safe_error_code('stale_exhausted'), $thresholdMinutes]
    );

    return ['requeued' => (int) $requeued, 'failed' => (int) $failed];
}

/**
 * qbo_worker_rescue_processing — mark every row a given worker_id left in
 * 'processing' as failed. Invoked by the top-level catch so a fatal mid-run
 * doesn't orphan the row being processed. worker_id is unique per process, so
 * this never touches another worker's in-flight rows.
 * (S-CRON-FIX-3, D-QBO-WORKER-TOPLEVEL-CATCH)
 *
 * @return int rows rescued
 */
function qbo_worker_rescue_processing(string $workerId, string $errorCode, string $errorMessage): int
{
    return (int) db_execute(
        "UPDATE acc_qbo_sync_queue
            SET status='failed', completed_at=NOW(),
                error_code=?, error_message=?
          WHERE worker_id=? AND status='processing'",
        [qbo_safe_error_code($errorCode), substr($errorMessage, 0, 500), $workerId]
    );
}

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
