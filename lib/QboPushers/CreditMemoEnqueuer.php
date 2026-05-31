<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CreditMemoEnqueuer.php
 *
 * Inserts a credit-memo sync job into `acc_qbo_sync_queue` from
 * `api/v1/credit_notes/create.php`, called AFTER the FF credit-note insert
 * commits but BEFORE the JSON success response. Same best-effort 4-step
 * gating (+ gate-0 eligibility) as InvoiceEnqueuer (S-QBO-11) /
 * JournalEntryEnqueuer (S-QBO-21).
 *
 * Gates:
 *   0. Entity eligibility — credit note exists, not soft-deleted, and (for
 *      'create') status='active' (credit_notes are created active; QBO
 *      should mirror live credits, not voided/expired ones).
 *   1. Master kill — quickbooks.sync_enabled must be '1' (default '0' per D-CPA-5).
 *   2. Mode kill — quickbooks.sync_mode.credit_memo ('sync' default). 'qbo_to_ff'
 *      + 'disabled' refuse.
 *   3. Operation whitelist — 'create' + 'update' since S-QBO-CREDIT-MEMO-UPDATE
 *      (D-QBO-16-2 pushUpdate stub CLOSED). 'void' still deferred to the
 *      pushVoid trio (OPERATOR_FOLLOWUPS F7). NOTE: no enqueue('update') hook
 *      is wired into a credit_notes endpoint (credit notes have no editable
 *      header) — the mechanical update rides manual-sync force_resync; the
 *      credit-APPLICATION propagation (apply→LinkedTxn) is a carved-out
 *      follow-up (F25 / D-QBO-CREDIT-MEMO-UPDATE-1). Gate-0's active-only
 *      status check applies to 'create'; 'update' may re-sync any live credit
 *      (active/partially_used/fully_used — the Pusher skips void/deleted).
 *   4. INSERT into acc_qbo_sync_queue (entity_type='credit_memo').
 *
 * Best-effort per D-ENQUEUER-CONTRACT — never throws; the FF credit-note
 * create flow always succeeds even if enqueue fails.
 *
 * @session  S-QBO-16 (create) + S-QBO-CREDIT-MEMO-UPDATE (update)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract), §8.6
 * @decision D-QBO-16-2 (pushUpdate stub CLOSED by S-QBO-CREDIT-MEMO-UPDATE;
 *               gate-3 now allows 'update'; pushVoid still stubbed → F7),
 *           D-QBO-CREDIT-MEMO-UPDATE-1 (mechanical update; apply flow → F25),
 *           D-ENQUEUER-CONTRACT (best-effort 4-step gating),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (gate-0 entity-state check)
 */

namespace FleetForge\QboPushers;

class CreditMemoEnqueuer
{
    /**
     * Enqueue a credit-memo sync job. Returns true if a queue row was
     * inserted; false on any gate refusal. Never throws.
     *
     * @param  int    $ffCreditNoteId  credit_notes.id
     * @param  string $operation       'create' + 'update' allowed (S-QBO-CREDIT-MEMO-UPDATE); 'void' rejected (F7)
     * @return bool
     */
    public static function enqueue(int $ffCreditNoteId, string $operation): bool
    {
        try {
            // Gate 0: entity eligibility.
            $cn = db_row(
                "SELECT id, status, deleted_at FROM credit_notes WHERE id = ?",
                [$ffCreditNoteId]
            );
            if ($cn === null) {
                error_log("[CreditMemoEnqueuer] gate-0 reject: credit note id {$ffCreditNoteId} not found");
                return false;
            }
            if ($cn['deleted_at'] !== null) {
                error_log("[CreditMemoEnqueuer] gate-0 reject: credit note id {$ffCreditNoteId} is soft-deleted");
                return false;
            }
            // create requires a live credit (status='active'). partially_used/
            // fully_used credits can still be pushed via S-QBO-26 manual sync,
            // but the canonical create.php trigger fires on a freshly-active
            // credit note.
            if ($operation === 'create' && $cn['status'] !== 'active') {
                error_log("[CreditMemoEnqueuer] gate-0 reject: credit note id {$ffCreditNoteId} op=create requires status='active', got '{$cn['status']}'");
                return false;
            }
            // 'void' requires the INVERTED status invariant: status='void'
            // (canonical trigger credit_notes/void.php). D-QBO-PUSHVOID-TRIO-1.
            if ($operation === 'void' && $cn['status'] !== 'void') {
                error_log("[CreditMemoEnqueuer] gate-0 reject: credit note id {$ffCreditNoteId} op=void requires status='void', got '{$cn['status']}'");
                return false;
            }

            // Gate 1: master kill switch (D-CPA-5).
            if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
                return false;
            }

            // Gate 2: mode kill.
            $mode = (string) settings_get('quickbooks.sync_mode.credit_memo', 'sync');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation whitelist. 'create' + 'update' + 'void' all
            // supported — pushUpdate closed by S-QBO-CREDIT-MEMO-UPDATE,
            // pushVoid closed by S-QBO-PUSHVOID-TRIO (D-QBO-PUSHVOID-TRIO-1).
            if (!in_array($operation, ['create', 'update', 'void'], true)) {
                return false;
            }

            // Gate 4: best-effort INSERT.
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'credit_memo',
                'entity_id'   => $ffCreditNoteId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[CreditMemoEnqueuer] failed to enqueue credit_note={$ffCreditNoteId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
