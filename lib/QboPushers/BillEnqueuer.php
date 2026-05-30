<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BillEnqueuer.php
 *
 * Phase QBO-8 / 1 of 2 (S-QBO-18) — paired with BillPusher.
 *
 * Best-effort enqueuer for FF bill push events. Mirrors S-QBO-7
 * VendorEnqueuer + S-QBO-ENQUEUER-ELIGIBILITY-GATE gate-0 discipline
 * with bill-specific eligibility rules:
 *   - create operation: bill must exist AND status='approved' or later
 *     (NOT 'draft'). Bills in draft aren't yet committed accounting
 *     documents and shouldn't push.
 *   - update operation: SUPPORTED since S-QBO-BILL-UPDATE — gate-3
 *     allowlist accepts 'update'; BillPusher::pushUpdate full-payload
 *     re-sends via updateEntity + SyncToken round-trip (demote-to-create
 *     when unmapped). gate-0 validates bill exists + non-draft.
 *   - void operation: not yet supported (pushVoid absent — see
 *     OPERATOR_FOLLOWUPS F7); gate-3 allowlist rejects it.
 *
 * Per §6.9 D-ENQUEUER-CONTRACT: silent reject + error_log diagnostics;
 * never throws. Production canonical caller is api/v1/accounting/bills/
 * approve.php (D-QBO-18-1 trigger).
 *
 * @session  S-QBO-18
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract)
 * @decision D-QBO-18-1 (trigger: draft→approved via approve.php),
 *           D-QBO-18-5 (pushUpdate stub — CLOSED by S-QBO-BILL-UPDATE /
 *               D-QBO-BILL-UPDATE-1; gate-3 now allows 'update'),
 *           D-ENQUEUER-CONTRACT (best-effort discipline),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (eligibility gate-0 before
 *               existing 4-step gating)
 */

namespace FleetForge\QboPushers;

class BillEnqueuer
{
    /**
     * Eligible bill statuses for push per operation. 'create' requires
     * the draft→approved transition has fired; 'update' permits any
     * post-approval state (mirrors invoice's sent|paid|partially_paid
     * allowance). 'void' is not in v1 scope.
     */
    private const OPERATION_STATUS_REQUIREMENTS = [
        'create' => ['approved', 'scheduled', 'partially_paid', 'paid'],
        'update' => ['approved', 'scheduled', 'partially_paid', 'paid'],
    ];

    /**
     * Best-effort enqueue. Returns true on successful queue insert,
     * false on any rejection (silent — error_log captures the reason
     * but never throws).
     *
     * Per §6.9 best-effort contract: a sync failure must never break
     * the calling FF flow (bill approve must succeed even if QBO
     * enqueue rejects).
     *
     * @param int    $billId    FF acc_bills.id
     * @param string $operation 'create' | 'update' (S-QBO-18 v1)
     * @return bool             true on enqueue success
     */
    public static function enqueue(int $billId, string $operation): bool
    {
        try {
            // ────────────────────────────────────────────────────────
            // Gate 0: Eligibility check (S-QBO-ENQUEUER-ELIGIBILITY-GATE
            // discipline). Verifies bill exists + status matches the
            // operation's allowlist. Catches: orphan queue rows from
            // manual CLI invocations + post-write enqueue↔dispatch
            // races where status changes between approve.php commit
            // and worker pickup.
            // ────────────────────────────────────────────────────────
            $ff = db_row(
                "SELECT id, status FROM acc_bills WHERE id = ?",
                [$billId]
            );
            if ($ff === null) {
                error_log("[BillEnqueuer] gate-0 reject: bill id {$billId} not found");
                return false;
            }

            $allowedStatuses = self::OPERATION_STATUS_REQUIREMENTS[$operation] ?? null;
            if ($allowedStatuses === null) {
                error_log("[BillEnqueuer] gate-0 reject: unknown operation '{$operation}' for bill {$billId}");
                return false;
            }
            if (!in_array($ff['status'], $allowedStatuses, true)) {
                error_log(
                    "[BillEnqueuer] gate-0 reject: bill {$billId} status='{$ff['status']}' not in allowlist " .
                    "[" . implode(',', $allowedStatuses) . "] for operation='{$operation}'"
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
            $mode = (string) settings_get('quickbooks.sync_mode.bill', 'queue');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;  // silent
            }

            // ────────────────────────────────────────────────────────
            // Gate 3: Operation allowlist. create + update supported
            // (update added in S-QBO-BILL-UPDATE); void deferred (F7).
            // ────────────────────────────────────────────────────────
            if (!in_array($operation, ['create', 'update'], true)) {
                error_log("[BillEnqueuer] gate-3 reject: operation '{$operation}' not in allowlist (create + update per S-QBO-BILL-UPDATE; void deferred)");
                return false;
            }

            // ────────────────────────────────────────────────────────
            // Gate 4: INSERT queue row.
            // ────────────────────────────────────────────────────────
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'bill',
                'entity_id'   => $billId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,    // matches InvoiceEnqueuer default
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[BillEnqueuer] enqueue failed for bill {$billId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
