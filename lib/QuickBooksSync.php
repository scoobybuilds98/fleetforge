<?php
declare(strict_types=1);

/**
 * lib/QuickBooksSync.php
 *
 * Sync-enrollment facade. The ONE function future FF event handlers
 * call when they want a QBO push. Encapsulates:
 *   - Master kill-switch check (D-CPA-5 quickbooks.sync_enabled)
 *   - Per-entity sync_mode lookup (sync | queue | off)
 *   - Queue row insertion (for 'queue' mode)
 *   - Synchronous dispatch passthrough (for 'sync' mode)
 *
 * Future event handlers (S-QBO-5+ Invoice Sent, S-QBO-7+ Vendor
 * Updated, etc.) invoke this facade — they don't touch the queue
 * table directly, and they don't import QboPusherDispatcher
 * directly. The facade keeps the call surface narrow + lets the
 * sync_mode policy evolve without rewriting every caller.
 *
 * Note: this session creates the facade. NO event handlers are
 * wired to it yet — each Pusher session (S-QBO-5+) wires its
 * domain's event handlers.
 *
 * @session  S-QBO-3
 * @spec     §6.1 (push pipeline entry point), §6.2 (sync mode)
 */

namespace FleetForge;

use FleetForge\Exceptions\PusherNotImplementedException;
use FleetForge\Exceptions\QuickBooksException;

class QuickBooksSync
{
    /**
     * Enqueue a QBO push job — OR, for sync-mode entity types,
     * invoke the Pusher synchronously in the same request and
     * return its result wrapped as a queue-shape result.
     *
     * Decision tree:
     *   - If isEnabled() === false (master kill-switch off):
     *       Return ['status' => 'skipped_master_off', 'queue_id' => null].
     *   - If sync_mode === 'off':
     *       Return ['status' => 'skipped_mode_off', 'queue_id' => null].
     *   - If sync_mode === 'sync':
     *       Invoke QboPusherDispatcher::dispatch() inline; return
     *       ['status' => 'synced', 'queue_id' => null, 'pusher_response' => ...].
     *       On exception: rethrow (caller decides how to surface).
     *   - If sync_mode === 'queue':
     *       INSERT into acc_qbo_sync_queue with status='queued';
     *       return ['status' => 'queued', 'queue_id' => <new id>].
     *
     * @param  string     $entityType One of acc_qbo_sync_queue.entity_type ENUM
     * @param  int        $entityId   FF entity ID
     * @param  string     $operation  'create' | 'update' | 'void' | 'delete'
     * @param  int        $priority   1-9 (lower = higher priority). Default 5.
     * @param  array|null $payloadSnapshot Persisted on the queue row; used by
     *                                     void/delete pushers when the FF entity
     *                                     may have been hard-deleted by dispatch time.
     * @return array{status:string, queue_id:?int, pusher_response?:array}
     *
     * @throws QuickBooksException family from synchronous Pusher invocations
     */
    public static function enqueue(
        string $entityType,
        int $entityId,
        string $operation,
        int $priority = 5,
        ?array $payloadSnapshot = null
    ): array {
        if (!self::isEnabled()) {
            return ['status' => 'skipped_master_off', 'queue_id' => null];
        }

        $mode = self::syncMode($entityType);

        if ($mode === 'off') {
            return ['status' => 'skipped_mode_off', 'queue_id' => null];
        }

        if ($mode === 'sync') {
            // Synchronous path — Pusher invoked in-line. Caller's
            // request thread pays the QBO latency cost (~300-800ms
            // per spec §6.2). Exception bubbles up.
            return [
                'status'          => 'synced',
                'queue_id'        => null,
                'pusher_response' => self::syncDispatch($entityType, $entityId, $operation, $payloadSnapshot),
            ];
        }

        // Default + 'queue': INSERT row, let worker pick up.
        $queueId = (int) db_insert('acc_qbo_sync_queue', [
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'operation'        => $operation,
            'status'           => 'queued',
            'priority'         => max(1, min(9, $priority)),
            'payload_snapshot' => $payloadSnapshot !== null ? json_encode($payloadSnapshot) : null,
        ]);

        return ['status' => 'queued', 'queue_id' => $queueId];
    }

    /**
     * Direct synchronous dispatch — bypasses sync_mode lookup. Used by
     * the sync-mode branch of enqueue() above + by callers (rare) that
     * explicitly want sync regardless of setting (e.g. a sync_log
     * Retry button in the UI). Caller catches QuickBooksException
     * family + decides how to surface failures.
     *
     * @throws QuickBooksException family from the Pusher
     * @throws PusherNotImplementedException if no Pusher exists yet
     */
    public static function syncDispatch(
        string $entityType,
        int $entityId,
        string $operation,
        ?array $payloadSnapshot = null
    ): array {
        return QboPusherDispatcher::dispatch($entityType, $operation, $entityId, $payloadSnapshot);
    }

    /**
     * Master kill-switch check. Reads quickbooks.sync_enabled and
     * returns true ONLY when it is the literal string '1'.
     *
     * Per D-CPA-5: stays '0' until S-QBO-30 production cutover. While
     * '0', every enqueue() returns 'skipped_master_off' and the worker
     * cron exits immediately.
     */
    public static function isEnabled(): bool
    {
        return (string) settings_get('quickbooks.sync_enabled', '0') === '1';
    }

    /**
     * Read the per-entity sync_mode setting. Defaults to 'queue' if
     * the key is missing (safest fallback — never bypass the queue
     * silently). Valid values: 'sync' | 'queue' | 'off'.
     */
    public static function syncMode(string $entityType): string
    {
        $value = (string) settings_get('quickbooks.sync_mode.' . $entityType, 'queue');
        return in_array($value, ['sync', 'queue', 'off'], true) ? $value : 'queue';
    }
}
