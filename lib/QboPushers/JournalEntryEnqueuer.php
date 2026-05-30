<?php
declare(strict_types=1);

/**
 * lib/QboPushers/JournalEntryEnqueuer.php
 *
 * Phase QBO-10 / 1 of 1 (S-QBO-21) — paired with JournalEntryPusher.
 *
 * Best-effort enqueuer for FF JE push events. Mirrors BillPaymentEnqueuer
 * with JE-specific eligibility:
 *   - create: JE must exist AND entry_status='posted' AND source_type
 *     NOT IN bridge-derived list (defense-in-depth — Pusher pushImpl
 *     step 4 also filters; double-gate prevents queue churn from
 *     bridge-derived inserts that would land in failed_preflight
 *     terminal state).
 *   - update: SUPPORTED since S-QBO-JE-UPDATE (D-QBO-21-5 stub CLOSED);
 *     gate-3 allowlist accepts 'update'; JournalEntryPusher::pushUpdate
 *     full-payload re-send via QuickBooksClient::updateEntity. NOTE: no
 *     enqueue('update') hook is wired into any FF endpoint — JEs are
 *     immutable post-posting (they reverse via a companion JE, not edit;
 *     there is no journal_entries/update.php). The mechanical update rides
 *     manual-sync force_resync / drift resync. The bridge-derived gate-0b
 *     filter below still applies to 'update' (covers the UPDATE verb).
 *   - void: NOT a JE concept (JEs reverse via a companion posted JE that
 *     pushes as its own create); gate-3 allowlist rejects it.
 *
 * Bridge-derived source_type filter list per QBO_SPEC §8.10 verbatim:
 *   ['invoice','payment','credit_note','ap_bill','ap_payment']
 *
 * @session  S-QBO-21 (create) + S-QBO-JE-UPDATE (update)
 * @decision D-QBO-21-1 (bridge-derived filter — defense-in-depth at
 *               Enqueuer gate-0 AND Pusher pushImpl step 4),
 *           D-QBO-21-5 (pushUpdate stub CLOSED by S-QBO-JE-UPDATE; gate-3
 *               now allows 'update'),
 *           D-QBO-JE-UPDATE-1 (mechanical update; no endpoint hook — JEs
 *               are immutable post-posting; rides manual-sync),
 *           D-ENQUEUER-CONTRACT (best-effort discipline),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (eligibility gate-0)
 */

namespace FleetForge\QboPushers;

class JournalEntryEnqueuer
{
    /**
     * Eligible JE entry_status values per operation. v1 only accepts
     * 'posted' — drafts + submitted + approved (AJE workflow mid-flight)
     * are rejected at gate-0 to prevent queue churn.
     */
    private const OPERATION_STATUS_REQUIREMENTS = [
        'create' => ['posted'],
        // 'update' now SUPPORTED — D-QBO-21-5 stub closed by S-QBO-JE-UPDATE.
        // Same eligibility as create (pushUpdate is a full-payload re-send).
        'update' => ['posted'],
    ];

    /**
     * Bridge-derived source_type filter per D-QBO-21-1 + spec §8.10.
     * Mirror of JournalEntryPusher::BRIDGE_DERIVED_SOURCE_TYPES — kept
     * in sync via smoke test (C28 verifies the two constants align).
     */
    private const BRIDGE_DERIVED_SOURCE_TYPES = [
        'invoice',
        'payment',
        'credit_note',
        'ap_bill',
        'ap_payment',
    ];

    /**
     * Best-effort enqueue. Returns true on success, false on rejection.
     *
     * @param int    $jeId      FF acc_journal_entries.id
     * @param string $operation 'create' or 'update' (S-QBO-JE-UPDATE widened
     *                          gate-3); 'void' rejected (not a JE concept)
     * @return bool             true on enqueue success
     */
    public static function enqueue(int $jeId, string $operation): bool
    {
        try {
            // Gate 0a: existence + entry_status eligibility.
            $ff = db_row(
                "SELECT id, entry_number, entry_status, source_type FROM acc_journal_entries WHERE id = ?",
                [$jeId]
            );
            if ($ff === null) {
                error_log("[JournalEntryEnqueuer] gate-0 reject: JE id {$jeId} not found");
                return false;
            }

            $allowedStatuses = self::OPERATION_STATUS_REQUIREMENTS[$operation] ?? null;
            if ($allowedStatuses === null) {
                error_log("[JournalEntryEnqueuer] gate-0 reject: unknown operation '{$operation}' for JE {$jeId}");
                return false;
            }
            if (!in_array($ff['entry_status'], $allowedStatuses, true)) {
                error_log(
                    "[JournalEntryEnqueuer] gate-0 reject: JE {$jeId} ({$ff['entry_number']}) entry_status='{$ff['entry_status']}' not in allowlist " .
                    "[" . implode(',', $allowedStatuses) . "] for operation='{$operation}'"
                );
                return false;
            }

            // Gate 0b: bridge-derived filter (D-QBO-21-1) — defense-in-depth.
            //   Per spec §8.10: 5 source_type ENUM values that QBO derives
            //   JEs from automatically when the parent entity syncs.
            //   Pushing the FF bridge-derived JE would DOUBLE-COUNT.
            if ($ff['source_type'] !== null
                && in_array($ff['source_type'], self::BRIDGE_DERIVED_SOURCE_TYPES, true)) {
                error_log(
                    "[JournalEntryEnqueuer] gate-0 reject: JE {$jeId} ({$ff['entry_number']}) source_type='{$ff['source_type']}' is bridge-derived per spec §8.10 (QBO derives JE from parent entity sync). D-QBO-21-1."
                );
                return false;
            }

            // Gate 1: master sync kill-switch.
            $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
            if ($syncEnabled !== '1') {
                return false;
            }

            // Gate 2: per-entity sync mode.
            $mode = (string) settings_get('quickbooks.sync_mode.journal_entry', 'queue');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation allowlist. 'create' + 'update' supported since
            // S-QBO-JE-UPDATE (D-QBO-21-5 stub closed). 'void' rejected — not a
            // JE concept (JEs reverse via a companion posted JE, pushed as its
            // own create).
            if (!in_array($operation, ['create', 'update'], true)) {
                error_log("[JournalEntryEnqueuer] gate-3 reject: operation '{$operation}' not in allowlist [create,update] (JEs reverse via a companion JE, not void)");
                return false;
            }

            // Gate 4: INSERT.
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'journal_entry',
                'entity_id'   => $jeId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[JournalEntryEnqueuer] enqueue failed for JE {$jeId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
