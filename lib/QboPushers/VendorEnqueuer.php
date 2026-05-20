<?php
declare(strict_types=1);

/**
 * lib/QboPushers/VendorEnqueuer.php
 *
 * Single-responsibility helper that inserts a vendor sync job into
 * `acc_qbo_sync_queue` from the FF vendor API endpoints. Called by
 * `api/v1/vendors/create.php` and `api/v1/vendors/update.php` AFTER
 * the FF DB write succeeds, but before the JSON success response is
 * returned to the client.
 *
 * Mirrors CustomerEnqueuer (S-QBO-6) per §6.9 Enqueuer Integration
 * Contract locked in D-PUSHER-PATTERNS-DOCS-LOCK (2026-05-21).
 *
 * Gating layers (any one returns false; no insert, no throw):
 *   1. Master kill — `quickbooks.sync_enabled` must be '1'
 *      (defaults '0' per D-CPA-5; operator flips temporarily for
 *      live verification only).
 *   2. Mode kill — `quickbooks.sync_mode.vendor` must allow FF→QBO
 *      pushes. 'sync' (default) + 'ff_to_qbo' allow; 'qbo_to_ff'
 *      + 'disabled' refuse.
 *   3. Operation whitelist — only 'create' and 'update' enqueue.
 *      No 'delete' per D-QBO-6-1 carry-over (FF soft-delete does NOT
 *      propagate; QBO Vendor stays Active=true).
 *
 * Best-effort: this helper MUST NOT throw on enqueue failure. If the
 * acc_qbo_sync_queue write fails (table missing, FK violation, DB
 * down, etc.), we swallow the exception so the FF vendor create
 * still succeeds for the operator. The drift-detection cron
 * (S-QBO-24) will eventually flag any FF vendors without mapping rows.
 *
 * Schema notes (K-22 Trap #62): status defaults to 'queued' (NOT
 * 'pending'); next_retry_at is NOT written at enqueue (column is
 * nullable; worker computes on retry per spec §6.7).
 *
 * @session  S-QBO-7
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract)
 * @decision D-QBO-6-1 (no delete enqueue — carries to vendors),
 *           D-QBO-6-3 (enqueue from API endpoints, not DB triggers)
 */

namespace FleetForge\QboPushers;

class VendorEnqueuer
{
    /**
     * Enqueue a vendor sync job. No-op (returns false) if any gate
     * refuses; never throws.
     *
     * @param int    $ffVendorId  vendors.id of the vendor to sync
     * @param string $operation   'create' or 'update' (others rejected)
     * @return bool               true on successful INSERT, false otherwise
     */
    public static function enqueue(int $ffVendorId, string $operation): bool
    {
        // Gate 1: master kill switch (D-CPA-5).
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            return false;
        }

        // Gate 2: mode kill.
        $mode = (string) settings_get('quickbooks.sync_mode.vendor', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return false;
        }

        // Gate 3: operation whitelist (no delete per D-QBO-6-1).
        if (!in_array($operation, ['create', 'update'], true)) {
            return false;
        }

        // Best-effort INSERT. acc_qbo_sync_queue.status defaults to
        // 'queued' per S-QBO-3 schema (K-22 Trap #62 — NOT 'pending').
        // Priority 100 = normal (worker sorts ASC; <100 reserved for
        // future high-urgency). next_retry_at intentionally omitted —
        // column is nullable, worker computes on retry per spec §6.7.
        try {
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'vendor',
                'entity_id'   => $ffVendorId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Sync is best-effort. Log to stderr for forensic visibility
            // but never block the FF flow. Sentry capture deferred per
            // §6.9 reference impl rationale (1 enqueue per vendor write
            // = too noisy to capture every failure).
            error_log(
                "[VendorEnqueuer] failed to enqueue vendor={$ffVendorId} op={$operation}: "
                . $e->getMessage()
            );
            return false;
        }
    }
}
