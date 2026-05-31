<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PaymentEnqueuer.php
 *
 * Phase QBO-6 / 2 of 3 (S-QBO-14) — paired with PaymentPusher.
 *
 * Best-effort enqueuer for FF payment push events. Mirrors BillEnqueuer
 * + S-QBO-ENQUEUER-ELIGIBILITY-GATE gate-0 discipline with payment-
 * specific eligibility rules.
 *
 * CRITICAL — bidirectional dedup gate 0 per D-QBO-14-1 (closing
 * D-QBO-13-1/2 invariant at the enqueue layer):
 *   - payment.origin = 'ff_native' required
 *   - origin='qbo_payments_webhook' rows are already in QBO (received
 *     via S-QBO-13 webhook handler); pushing them back creates a
 *     DUPLICATE QBO Payment with auto-discriminated id
 *   - origin='qbo_other' rows are accountant-created in QBO directly
 *     (pulled by a future S-QBO-26 path); same skip semantics
 *
 * Defense-in-depth: PaymentPusher::pushImpl ALSO performs this check
 * (catches CLI invocations + races + manual queue inserts).
 *
 * Per §6.9 D-ENQUEUER-CONTRACT: silent reject + error_log diagnostics;
 * never throws. Production canonical caller is api/v1/payments/create.php
 * (mirrors S-QBO-11 send.php / S-QBO-18 approve.php hook pattern).
 *
 * @session  S-QBO-14
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract)
 * @decision D-QBO-14-1 (origin='ff_native' filter; closes D-QBO-13-1/2
 *               invariant at enqueue layer),
 *           D-QBO-14-2 (payment.status='cleared' eligibility),
 *           D-QBO-14-5 (pushUpdate stub — CLOSED by S-QBO-PAYMENT-UPDATE;
 *               gate-3 allowlist now ['create','update']; 'void' still
 *               deferred to a separate pushVoid slice — see OPERATOR_FOLLOWUPS F7),
 *           D-ENQUEUER-CONTRACT (best-effort discipline),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (eligibility gate-0 before
 *               existing 4-step gating)
 */

namespace FleetForge\QboPushers;

class PaymentEnqueuer
{
    /**
     * Eligible payment statuses for push per operation. 'create' requires
     * status='cleared' (default for FF payments). pending/failed/voided/
     * refunded/returned payments do not push.
     */
    private const OPERATION_STATUS_REQUIREMENTS = [
        'create' => ['cleared'],
        // 'update' now SUPPORTED — D-QBO-14-5 stub closed by S-QBO-PAYMENT-UPDATE.
        // Same status requirement as create (pushUpdate is just a full-
        // payload re-send via QuickBooksClient::updateEntity).
        'update' => ['cleared'],
        // 'void' SUPPORTED since S-QBO-PUSHVOID-TRIO — eligibility is the
        // soft-delete signal (deleted_at IS NOT NULL), checked in gate-0
        // below; [] here just keeps the unknown-operation guard from firing.
        'void'   => [],
    ];

    /**
     * Eligible payment origins. Only ff_native payments are pushed —
     * webhook-originated and qbo_other are already in QBO. This is the
     * D-QBO-14-1 invariant at gate 0.
     */
    private const ALLOWED_ORIGINS = ['ff_native'];

    /**
     * Best-effort enqueue. Returns true on successful queue insert,
     * false on any rejection (silent — error_log captures the reason
     * but never throws).
     *
     * Per §6.9 best-effort contract: a sync failure must never break
     * the calling FF flow (payment create must succeed even if QBO
     * enqueue rejects).
     *
     * @param int    $paymentId FF payments.id
     * @param string $operation 'create' / 'update' / 'void' (S-QBO-PUSHVOID-TRIO
     *                          added 'void' — gate-3 allowlist now all three)
     * @return bool             true on enqueue success
     */
    public static function enqueue(int $paymentId, string $operation): bool
    {
        try {
            // ────────────────────────────────────────────────────────
            // Gate 0: Eligibility check.
            //
            // Verifies payment exists + status='cleared' + origin='ff_native'.
            // The origin check is CRITICAL — it closes the bidirectional
            // dedup loop with D-QBO-13. Without it, webhook-originated
            // payments (origin='qbo_payments_webhook') would be re-pushed
            // and create duplicate QBO Payments.
            // ────────────────────────────────────────────────────────
            $ff = db_row(
                "SELECT id, status, origin, deleted_at FROM payments WHERE id = ?",
                [$paymentId]
            );
            if ($ff === null) {
                error_log("[PaymentEnqueuer] gate-0 reject: payment id {$paymentId} not found");
                return false;
            }

            // origin gate (D-QBO-14-1) — fail fast before status check
            // because origin mismatch is a stronger signal than status.
            if (!in_array($ff['origin'], self::ALLOWED_ORIGINS, true)) {
                error_log(
                    "[PaymentEnqueuer] gate-0 reject: payment {$paymentId} origin='{$ff['origin']}' " .
                    "not in allowlist [" . implode(',', self::ALLOWED_ORIGINS) . "] " .
                    "(D-QBO-14-1 bidirectional dedup with D-QBO-13)"
                );
                return false;
            }

            $allowedStatuses = self::OPERATION_STATUS_REQUIREMENTS[$operation] ?? null;
            if ($allowedStatuses === null) {
                error_log("[PaymentEnqueuer] gate-0 reject: unknown operation '{$operation}' for payment {$paymentId}");
                return false;
            }
            if ($operation === 'void') {
                // Void eligibility is the soft-delete signal (D-QBO-PUSHVOID-TRIO-1),
                // not a status value — canonical trigger is payments/delete.php
                // post-commit, where deleted_at is set.
                if ($ff['deleted_at'] === null) {
                    error_log("[PaymentEnqueuer] gate-0 reject: payment {$paymentId} op='void' but deleted_at IS NULL (not soft-deleted/voided)");
                    return false;
                }
            } elseif (!in_array($ff['status'], $allowedStatuses, true)) {
                error_log(
                    "[PaymentEnqueuer] gate-0 reject: payment {$paymentId} status='{$ff['status']}' not in allowlist " .
                    "[" . implode(',', $allowedStatuses) . "] for operation='{$operation}' (D-QBO-14-2)"
                );
                return false;
            }

            // ────────────────────────────────────────────────────────
            // Gate 1: Master sync kill-switch (D-CPA-5).
            // ────────────────────────────────────────────────────────
            $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
            if ($syncEnabled !== '1') {
                return false;  // silent — master switch off is the default state
            }

            // ────────────────────────────────────────────────────────
            // Gate 2: Per-entity sync mode kill.
            // ────────────────────────────────────────────────────────
            $mode = (string) settings_get('quickbooks.sync_mode.payment', 'queue');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;  // silent
            }

            // ────────────────────────────────────────────────────────
            // Gate 3: Operation allowlist. 'create' + 'update' supported
            // since S-QBO-PAYMENT-UPDATE (D-QBO-14-5 closed). 'void' still
            // deferred to the separate pushVoid trio slice (OPERATOR_FOLLOWUPS F7).
            // ────────────────────────────────────────────────────────
            if (!in_array($operation, ['create', 'update', 'void'], true)) {
                error_log("[PaymentEnqueuer] gate-3 reject: operation '{$operation}' not in allowlist [create,update,void]");
                return false;
            }

            // ────────────────────────────────────────────────────────
            // Gate 4: INSERT queue row.
            // ────────────────────────────────────────────────────────
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'payment',
                'entity_id'   => $paymentId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,    // matches BillEnqueuer / InvoiceEnqueuer default
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[PaymentEnqueuer] enqueue failed for payment {$paymentId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
