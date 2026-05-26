<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CustomerEnqueuer.php
 *
 * Single-responsibility helper that inserts a customer sync job into
 * `acc_qbo_sync_queue` from the FF customer API endpoints. Called by
 * `api/v1/customers/create.php` and `api/v1/customers/update.php`
 * AFTER the FF DB write succeeds, but before the JSON success
 * response is returned to the client.
 *
 * Gating layers (any one returns false; no insert, no throw):
 *   1. Master kill — `quickbooks.sync_enabled` must be '1'
 *      (defaults '0' per D-CPA-5; operator flips temporarily for
 *      live verification only).
 *   2. Mode kill — `quickbooks.sync_mode.customer` must allow
 *      FF→QBO pushes. 'sync' (default) + 'ff_to_qbo' allow;
 *      'qbo_to_ff' + 'disabled' refuse.
 *   3. Operation whitelist — only 'create' and 'update' enqueue.
 *      No 'delete' per D-QBO-6-1 (FF soft-delete does NOT propagate).
 *
 * Best-effort: this helper MUST NOT throw on enqueue failure. If the
 * acc_qbo_sync_queue write fails (table missing, FK violation, DB
 * down, etc.), we swallow the exception so the FF customer create
 * still succeeds for the operator. The drift-detection cron (S-QBO-24)
 * will eventually flag any FF customers without mapping rows.
 *
 * No DB triggers, no model-class hooks — direct call from the API
 * endpoint per D-QBO-6-3 (explicit + debuggable + matches the
 * established FF pattern of post-commit hooks).
 *
 * @session  S-QBO-6
 * @decision D-QBO-6-3 (enqueue from API endpoints, not DB triggers
 *                      or model hooks)
 * @decision D-QBO-6-4 (mode gating shared with CustomerPusher)
 */

namespace FleetForge\QboPushers;

class CustomerEnqueuer
{
    /**
     * Enqueue a customer sync job. No-op (returns false) if any gate
     * refuses; never throws.
     *
     * @param int    $ffCustomerId  customers.id of the customer to sync
     * @param string $operation     'create' or 'update' (others rejected)
     * @return bool                 true if a queue row was inserted; false otherwise
     */
    public static function enqueue(int $ffCustomerId, string $operation): bool
    {
        // Gate 0: entity eligibility (D-ENQUEUER-GATE-0-ELIGIBILITY).
        //
        // Defensive guard against non-canonical call sites + enqueue↔dispatch
        // races where the entity changes state between API write and worker
        // dispatch. Canonical callers (api/v1/customers/create.php +
        // update.php) are eligible-by-construction; gate 0 protects future
        // call sites + manual CLI invocations.
        //
        // No status column check — `customers.status` is operator-workflow
        // soft-state (active/inactive/pending/suspended/credit_hold); push
        // eligibility is conservatively gated on the hard-deletion column
        // only. Inactive customers may still legitimately need QBO record
        // updates (e.g. marking inactive in QBO mirror).
        //
        // Best-effort: silent reject + error_log per §6.9 contract.
        $customer = db_row(
            "SELECT id, deleted_at FROM customers WHERE id = ?",
            [$ffCustomerId]
        );
        if ($customer === null) {
            error_log("[CustomerEnqueuer] gate-0 reject: customer id {$ffCustomerId} not found");
            return false;
        }
        if ($customer['deleted_at'] !== null) {
            error_log("[CustomerEnqueuer] gate-0 reject: customer id {$ffCustomerId} is soft-deleted (deleted_at={$customer['deleted_at']})");
            return false;
        }

        // Gate 1: master kill switch (D-CPA-5).
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            return false;
        }

        // Gate 2: mode kill (D-QBO-6-4).
        $mode = (string) settings_get('quickbooks.sync_mode.customer', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return false;
        }

        // Gate 3: operation whitelist (D-QBO-6-1 — no delete).
        if (!in_array($operation, ['create', 'update'], true)) {
            return false;
        }

        // Best-effort INSERT. acc_qbo_sync_queue.status defaults to
        // 'queued' (per S-QBO-3 schema); priority defaults to 5 but we
        // pass 100 explicitly to signal "normal priority" (the worker
        // sorts ASC, so lower numbers fire first — 100 leaves room
        // for future high-urgency operations to use priority<100).
        try {
            db_insert('acc_qbo_sync_queue', [
                'entity_type'  => 'customer',
                'entity_id'    => $ffCustomerId,
                'operation'    => $operation,
                'status'       => 'queued',
                'priority'     => 100,
                'retry_count'  => 0,
                'max_retries'  => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Sync is best-effort. Log to stderr for forensic visibility
            // but never block the FF flow. Sentry capture is deferred to
            // a future enhancement once enqueue volume is high enough to
            // matter (today: 1 enqueue per customer create/update — too
            // noisy to capture every failure).
            error_log(
                "[CustomerEnqueuer] failed to enqueue customer={$ffCustomerId} op={$operation}: "
                . $e->getMessage()
            );
            return false;
        }
    }
}
