<?php
declare(strict_types=1);

/**
 * lib/QboPushers/RefundReceiptEnqueuer.php
 *
 * Inserts a refund-receipt sync job into `acc_qbo_sync_queue` from
 * `api/v1/leases/mark_refund_settled.php`, called AFTER the settle transaction
 * commits but BEFORE the JSON success response. Best-effort 4-step gating
 * (+ gate-0 eligibility) matching every other Enqueuer (D-ENQUEUER-CONTRACT).
 *
 * Gates:
 *   0. Entity eligibility (D-QBO-17-1/-2/-5): lease exists, not soft-deleted,
 *      status='completed', precharge_refund_method='cash', and
 *      precharge_refund_settled_at non-null (the settle event is the trigger).
 *   1. Master kill — quickbooks.sync_enabled must be '1' (default '0' per D-CPA-5).
 *   2. Mode kill — quickbooks.sync_mode.refund_receipt ('sync' default).
 *   3. Operation whitelist — 'create' only in v1 (a refund is immutable;
 *      reversal is a future follow-up).
 *   4. INSERT into acc_qbo_sync_queue (entity_type='refund_receipt',
 *      entity_id=lease_id).
 *
 * Best-effort per D-ENQUEUER-CONTRACT — never throws; the FF settle flow always
 * succeeds even if enqueue fails.
 *
 * @session  S-QBO-17
 * @closes   Phase QBO-7 (2/2)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.9 (Enqueuer Contract), §8.7
 * @decision D-QBO-17-1 (trigger=settle), D-QBO-17-2 (cash only), D-QBO-17-5 (key=lease_id)
 */

namespace FleetForge\QboPushers;

class RefundReceiptEnqueuer
{
    /**
     * Enqueue a refund-receipt sync job. Returns true if a queue row was
     * inserted; false on any gate refusal. Never throws.
     *
     * @param  int    $ffLeaseId  leases.id
     * @param  string $operation  'create' only in v1
     * @return bool
     */
    public static function enqueue(int $ffLeaseId, string $operation): bool
    {
        try {
            // Gate 0: entity eligibility — a settled cash refund.
            $lease = db_row(
                "SELECT id, status, deleted_at, precharge_refund_method, precharge_refund_settled_at
                   FROM leases WHERE id = ?",
                [$ffLeaseId]
            );
            if ($lease === null) {
                error_log("[RefundReceiptEnqueuer] gate-0 reject: lease id {$ffLeaseId} not found");
                return false;
            }
            if ($lease['deleted_at'] !== null) {
                error_log("[RefundReceiptEnqueuer] gate-0 reject: lease id {$ffLeaseId} is soft-deleted");
                return false;
            }
            if ($lease['status'] !== 'completed') {
                error_log("[RefundReceiptEnqueuer] gate-0 reject: lease id {$ffLeaseId} status='{$lease['status']}' (need 'completed')");
                return false;
            }
            if ($lease['precharge_refund_method'] !== 'cash') {
                error_log("[RefundReceiptEnqueuer] gate-0 reject: lease id {$ffLeaseId} refund_method='" . ($lease['precharge_refund_method'] ?? 'none') . "' (need 'cash')");
                return false;
            }
            if ($lease['precharge_refund_settled_at'] === null) {
                error_log("[RefundReceiptEnqueuer] gate-0 reject: lease id {$ffLeaseId} cash refund not yet settled");
                return false;
            }

            // Gate 1: master kill switch (D-CPA-5).
            if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
                return false;
            }

            // Gate 2: mode kill.
            $mode = (string) settings_get('quickbooks.sync_mode.refund_receipt', 'sync');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation whitelist. v1 = 'create' only.
            if ($operation !== 'create') {
                return false;
            }

            // Gate 4: best-effort INSERT.
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'refund_receipt',
                'entity_id'   => $ffLeaseId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[RefundReceiptEnqueuer] failed to enqueue lease={$ffLeaseId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
