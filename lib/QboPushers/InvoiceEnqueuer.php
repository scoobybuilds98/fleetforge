<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceEnqueuer.php
 *
 * Single-responsibility helper inserting an invoice sync job into
 * `acc_qbo_sync_queue` from `api/v1/invoices/send.php`. Called AFTER the
 * FF draft→sent transition commits, but BEFORE the JSON success
 * response. Same 4-step gating pattern as CustomerEnqueuer (S-QBO-6) /
 * VendorEnqueuer (S-QBO-7).
 *
 * Gates:
 *   1. Master kill — quickbooks.sync_enabled must be '1' (default '0'
 *      per D-CPA-5).
 *   2. Mode kill — quickbooks.sync_mode.invoice must allow FF→QBO
 *      pushes. 'sync' (default) + 'ff_to_qbo' allow; 'qbo_to_ff' +
 *      'disabled' refuse.
 *   3. Operation whitelist — 'create' only in S-QBO-11. 'update'
 *      returns false silently (deferred to S-QBO-12 per D-QBO-11-4).
 *   4. INSERT into acc_qbo_sync_queue. Best-effort: swallows any
 *      exception (DB FK violations, schema drift, etc.) so the FF
 *      send flow always succeeds.
 *
 * Why G.1-only (no G.2): the prompt's PART G.2 suggested enqueue at
 * billing-engine post-INSERT but FF invoices are CREATED as drafts
 * (status='draft'); QBO should only see sent invoices (D12
 * immutability). The single FF→QBO-visible transition is draft→sent
 * via api/v1/invoices/send.php — that's where enqueue belongs.
 * Resolved via AskUserQuestion at session pre-flight per
 * [[feedback_trust_file_over_prompt]].
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract)
 * @decision D-QBO-11-1 (queued path; enqueue at send.php),
 *           D-QBO-11-4 (update path deferred to S-QBO-12),
 *           D-ENQUEUER-CONTRACT (best-effort 4-step gating)
 */

namespace FleetForge\QboPushers;

class InvoiceEnqueuer
{
    /**
     * Enqueue an invoice sync job. No-op (returns false) if any gate
     * refuses; never throws.
     *
     * @param  int    $ffInvoiceId  invoices.id of the invoice to sync
     * @param  string $operation    'create' allowed in S-QBO-11; others rejected
     * @return bool   true if a queue row was inserted; false otherwise
     */
    public static function enqueue(int $ffInvoiceId, string $operation): bool
    {
        // Gate 1: master kill switch (D-CPA-5).
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            return false;
        }

        // Gate 2: mode kill.
        $mode = (string) settings_get('quickbooks.sync_mode.invoice', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return false;
        }

        // Gate 3: operation whitelist. S-QBO-11 ships create only.
        // Update/void deferred to S-QBO-12. Silent refusal (no error)
        // — caller doesn't care.
        if ($operation !== 'create') {
            return false;
        }

        // Gate 4: best-effort INSERT. acc_qbo_sync_queue.status defaults
        // to 'queued' per S-QBO-3 schema; priority=100 = normal (lower
        // numbers fire first; leaves room for high-urgency).
        try {
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'invoice',
                'entity_id'   => $ffInvoiceId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Sync is best-effort. Log for forensic visibility but
            // never block the FF send flow.
            error_log(
                "[InvoiceEnqueuer] failed to enqueue invoice={$ffInvoiceId} op={$operation}: "
                . $e->getMessage()
            );
            return false;
        }
    }
}
