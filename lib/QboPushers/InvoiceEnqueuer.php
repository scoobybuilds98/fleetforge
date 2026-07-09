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
 *   3. Operation whitelist — 'create' (S-QBO-11) and 'void' (S-QBO-12,
 *      InvoicePusher::pushVoid; widened in C6) are accepted. 'update' returns
 *      false silently (deferred per D-QBO-11-4); unknown operations refused.
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
     * @param  string $operation    'create' | 'void' (gate-0 enforces the status
     *                              each requires). 'update' is deferred at the
     *                              enqueuer; unknown operations are rejected.
     * @return bool   true if a queue row was inserted; false otherwise
     */
    public static function enqueue(int $ffInvoiceId, string $operation): bool
    {
        // Gate 0: entity eligibility (D-ENQUEUER-GATE-0-ELIGIBILITY).
        //
        // Closes the upstream side of the bug class surfaced in
        // S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS: queue row 180 (invoice 42,
        // status='draft', soft-deleted) was accepted by this enqueuer because
        // the prior 4-step gating only checked settings + operation, never
        // the entity's own state. The canonical production path (api/v1/
        // invoices/send.php) only fires on draft→sent commit and is
        // eligible-by-construction, but manual CLI calls + future
        // non-canonical call sites + enqueue↔dispatch races need this
        // defensive guard.
        //
        // Best-effort: silent reject + error_log per the §6.9 contract.
        // Never throws.
        $invoice = db_row(
            "SELECT id, status, deleted_at FROM invoices WHERE id = ?",
            [$ffInvoiceId]
        );
        if ($invoice === null) {
            error_log("[InvoiceEnqueuer] gate-0 reject: invoice id {$ffInvoiceId} not found");
            return false;
        }
        if ($invoice['deleted_at'] !== null) {
            error_log("[InvoiceEnqueuer] gate-0 reject: invoice id {$ffInvoiceId} is soft-deleted (deleted_at={$invoice['deleted_at']})");
            return false;
        }
        // Per-operation status eligibility. Canonical push lifecycle:
        //   create → invoice must be 'sent' (per D12 immutability — QBO
        //            never sees drafts; canonical send.php trigger).
        //   update → invoice must be sent/paid/partially_paid (any state
        //            where QBO already has a copy that may need updating;
        //            S-QBO-12 will exercise this).
        //   void   → invoice must be 'void' (mirror of create's
        //            send-then-push pattern; S-QBO-12 scope).
        //   other  → fall through to gate 3 allowlist for rejection.
        $createValid = ($operation === 'create' && $invoice['status'] === 'sent');
        $updateValid = ($operation === 'update' && in_array($invoice['status'], ['sent', 'paid', 'partially_paid'], true));
        $voidValid   = ($operation === 'void'   && $invoice['status'] === 'void');
        if ($operation === 'create' && !$createValid) {
            error_log("[InvoiceEnqueuer] gate-0 reject: invoice id {$ffInvoiceId} op=create requires status='sent', got '{$invoice['status']}'");
            return false;
        }
        if ($operation === 'update' && !$updateValid) {
            error_log("[InvoiceEnqueuer] gate-0 reject: invoice id {$ffInvoiceId} op=update requires status in {sent,paid,partially_paid}, got '{$invoice['status']}'");
            return false;
        }
        if ($operation === 'void' && !$voidValid) {
            error_log("[InvoiceEnqueuer] gate-0 reject: invoice id {$ffInvoiceId} op=void requires status='void', got '{$invoice['status']}'");
            return false;
        }
        // Unknown operations fall through; gate 3 below rejects via the
        // existing operation allowlist.

        // Gate 1: master kill switch (D-CPA-5).
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            return false;
        }

        // Gate 2: mode kill.
        $mode = (string) settings_get('quickbooks.sync_mode.invoice', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return false;
        }

        // Gate 3: operation whitelist. 'create' (S-QBO-11) and 'void' (S-QBO-12,
        // InvoicePusher::pushVoid) are enqueued by real call sites — send.php
        // enqueues 'create', void.php / bulk_void.php enqueue 'void'. This gate
        // previously hard-rejected everything but 'create', so those void calls
        // were silently dropped (gate-0 accepted them, gate-3 killed them) and a
        // voided FF invoice left a stale OPEN invoice in QBO (C6).
        // S-AUDIT-LIFECYCLE-1 #24b: 'update' un-deferred — invoices/update.php
        // now enqueues it when post-send metadata (po_number/billing email)
        // changes, so the QBO mirror stops drifting from FF (D-QBO-CORE-1).
        // InvoicePusher::pushUpdate already existed (gate-5 demotes unmapped).
        if (!in_array($operation, ['create', 'void', 'update'], true)) {
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
