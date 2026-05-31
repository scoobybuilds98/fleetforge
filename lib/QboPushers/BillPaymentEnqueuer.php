<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BillPaymentEnqueuer.php
 *
 * Phase QBO-8 / 2 of 2 (S-QBO-19) — paired with BillPaymentPusher.
 *
 * Best-effort enqueuer for FF bill payment push events. Mirrors
 * BillEnqueuer + PaymentEnqueuer with bill-payment-specific eligibility:
 *   - create: ap_payment must exist AND status='cleared' (not pending or
 *     void). FF ap_payments default to 'cleared' at create-time per
 *     api/v1/accounting/ap-payments/create.php.
 *   - update: SUPPORTED since S-QBO-BILL-PAYMENT-UPDATE (D-QBO-19-5 stub
 *     CLOSED); gate-3 allowlist accepts 'update'; BillPaymentPusher::pushUpdate
 *     full-payload re-send via QuickBooksClient::updateEntity. enqueue('update')
 *     wired into api/v1/accounting/ap-payments/update.php post-commit
 *     (3 of 4 editable fields directly affect QBO payload: payment_date →
 *     TxnDate, reference_number + check_number → PrivateNote).
 *   - void: still NOT in v1 (rides the pushVoid trio in OPERATOR_FOLLOWUPS F7);
 *     gate-3 allowlist rejects it.
 *
 * No origin filter (D-QBO-14-1-equivalent) because acc_ap_payments has no
 * origin column — bill payments are FF-native only per D-QBO-19-1.
 *
 * @session  S-QBO-19
 * @decision D-QBO-19-1 (FF-origin only),
 *           D-QBO-19-5 (pushUpdate stub — CLOSED by S-QBO-BILL-PAYMENT-UPDATE;
 *               D-QBO-BILL-PAYMENT-UPDATE-1; gate-3 now allows 'update'),
 *           D-ENQUEUER-CONTRACT (best-effort discipline),
 *           D-ENQUEUER-GATE-0-ELIGIBILITY (eligibility gate-0)
 */

namespace FleetForge\QboPushers;

class BillPaymentEnqueuer
{
    /**
     * Eligible ap_payment statuses per operation.
     */
    private const OPERATION_STATUS_REQUIREMENTS = [
        'create' => ['cleared'],
        // 'update' now SUPPORTED — D-QBO-19-5 stub closed by S-QBO-BILL-PAYMENT-UPDATE.
        // Same status requirement as create (pushUpdate is just a full-payload
        // re-send via QuickBooksClient::updateEntity).
        'update' => ['cleared'],
        // 'void' SUPPORTED since S-QBO-PUSHVOID-TRIO — requires status='void'
        // (the INVERTED invariant; canonical trigger ap-payments/void.php).
        // D-QBO-PUSHVOID-TRIO-1.
        'void'   => ['void'],
    ];

    /**
     * Best-effort enqueue. Returns true on success, false on rejection.
     *
     * @param int    $apPaymentId FF acc_ap_payments.id
     * @param string $operation   'create' / 'update' / 'void' (S-QBO-PUSHVOID-TRIO
     *                            added 'void' — requires status='void')
     * @return bool               true on enqueue success
     */
    public static function enqueue(int $apPaymentId, string $operation): bool
    {
        try {
            // Gate 0: eligibility.
            $ff = db_row(
                "SELECT id, status FROM acc_ap_payments WHERE id = ?",
                [$apPaymentId]
            );
            if ($ff === null) {
                error_log("[BillPaymentEnqueuer] gate-0 reject: ap_payment id {$apPaymentId} not found");
                return false;
            }

            $allowedStatuses = self::OPERATION_STATUS_REQUIREMENTS[$operation] ?? null;
            if ($allowedStatuses === null) {
                error_log("[BillPaymentEnqueuer] gate-0 reject: unknown operation '{$operation}' for ap_payment {$apPaymentId}");
                return false;
            }
            if (!in_array($ff['status'], $allowedStatuses, true)) {
                error_log(
                    "[BillPaymentEnqueuer] gate-0 reject: ap_payment {$apPaymentId} status='{$ff['status']}' not in allowlist " .
                    "[" . implode(',', $allowedStatuses) . "] for operation='{$operation}'"
                );
                return false;
            }

            // Gate 1: master sync kill-switch.
            $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
            if ($syncEnabled !== '1') {
                return false;
            }

            // Gate 2: per-entity sync mode.
            $mode = (string) settings_get('quickbooks.sync_mode.bill_payment', 'queue');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation allowlist. 'create' + 'update' supported since
            // S-QBO-BILL-PAYMENT-UPDATE (D-QBO-19-5 closed). 'void' still
            // deferred to the separate pushVoid trio slice (OPERATOR_FOLLOWUPS F7).
            if (!in_array($operation, ['create', 'update'], true)) {
                error_log("[BillPaymentEnqueuer] gate-3 reject: operation '{$operation}' not in allowlist [create,update] (void deferred to pushVoid trio — F7)");
                return false;
            }

            // Gate 4: INSERT.
            db_insert('acc_qbo_sync_queue', [
                'entity_type' => 'bill_payment',
                'entity_id'   => $apPaymentId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[BillPaymentEnqueuer] enqueue failed for ap_payment {$apPaymentId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
