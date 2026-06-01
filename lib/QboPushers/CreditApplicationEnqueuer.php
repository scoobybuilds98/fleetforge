<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CreditApplicationEnqueuer.php
 *
 * Inserts a credit-application sync job into `acc_qbo_sync_queue` from
 * `api/v1/credit_notes/apply.php`, called AFTER the FF apply transaction
 * commits but BEFORE the JSON success response. Best-effort 4-step gating
 * (+ gate-0 eligibility) matching every other Enqueuer (D-ENQUEUER-CONTRACT).
 *
 * Gates:
 *   0. Entity eligibility — credit_note_applications row exists.
 *      credit_note_applications is APPEND-ONLY with no soft-delete + no
 *      status column, so eligibility is just the existence check.
 *   1. Master kill — quickbooks.sync_enabled must be '1' (default '0' per D-CPA-5).
 *   2. Mode kill — quickbooks.sync_mode.credit_application ('sync' default).
 *      Separate from sync_mode.credit_memo so apply propagation can be paused
 *      without disabling credit-memo creation. 'qbo_to_ff' + 'disabled' refuse.
 *   3. Operation whitelist — 'create' only in v1 per D-QBO-CREDIT-MEMO-APPLY-4
 *      (update is unsupported — QBO applies are immutable; void/un-apply is a
 *      follow-up).
 *   4. INSERT into acc_qbo_sync_queue (entity_type='credit_application',
 *      entity_id=credit_note_applications.id).
 *
 * Best-effort per D-ENQUEUER-CONTRACT — never throws; the FF apply flow
 * always succeeds even if enqueue fails.
 *
 * @session  S-QBO-CREDIT-MEMO-APPLY
 * @closes   F25
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract), §8.6
 * @decision D-QBO-CREDIT-MEMO-APPLY-1 (entity_type=credit_application),
 *           D-QBO-CREDIT-MEMO-APPLY-4 (v1 create-only; un-apply→follow-up),
 *           D-ENQUEUER-CONTRACT (best-effort 4-step gating),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (gate-0 entity-state check)
 */

namespace FleetForge\QboPushers;

class CreditApplicationEnqueuer
{
    /**
     * Enqueue a credit-application sync job. Returns true if a queue row
     * was inserted; false on any gate refusal. Never throws.
     *
     * @param  int    $ffApplicationId  credit_note_applications.id
     * @param  string $operation        'create' only in v1
     * @return bool
     */
    public static function enqueue(int $ffApplicationId, string $operation): bool
    {
        try {
            // Gate 0: entity eligibility — per-operation status invariant (F27).
            //   create → application must be live ('applied')
            //   void   → application must be 'reversed' (un-applied via unapply.php)
            $app = db_row(
                "SELECT id, status FROM credit_note_applications WHERE id = ?",
                [$ffApplicationId]
            );
            if ($app === null) {
                error_log("[CreditApplicationEnqueuer] gate-0 reject: application id {$ffApplicationId} not found");
                return false;
            }
            if ($operation === 'create' && ($app['status'] ?? 'applied') !== 'applied') {
                error_log("[CreditApplicationEnqueuer] gate-0 reject: op=create requires status='applied', got '" . ($app['status'] ?? '') . "'");
                return false;
            }
            if ($operation === 'void' && ($app['status'] ?? '') !== 'reversed') {
                error_log("[CreditApplicationEnqueuer] gate-0 reject: op=void requires status='reversed', got '" . ($app['status'] ?? '') . "'");
                return false;
            }

            // Gate 1: master kill switch (D-CPA-5).
            if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
                return false;
            }

            // Gate 2: mode kill (own setting, not credit_memo's).
            $mode = (string) settings_get('quickbooks.sync_mode.credit_application', 'sync');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation whitelist. 'create' (apply) + 'void' (un-apply, F27).
            if (!in_array($operation, ['create', 'void'], true)) {
                return false;
            }

            // Gate 4: best-effort INSERT.
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'credit_application',
                'entity_id'   => $ffApplicationId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[CreditApplicationEnqueuer] failed to enqueue application={$ffApplicationId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
