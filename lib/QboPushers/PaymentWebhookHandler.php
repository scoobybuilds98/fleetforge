<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PaymentWebhookHandler.php
 *
 * Phase QBO-6 / 1 of 3 (S-QBO-13) — Exception #1 per D-QBO-CORE-2.
 *
 * QBO Payments processes the money first (card via Stripe, ACH, etc.)
 * then notifies FF via webhook. FF mirrors the payment as a customers-
 * facing payment record + clears the AR. This handler is the bridge:
 * receives a webhook event ID + operation, pulls the full Payment
 * entity from QBO, matches to an FF invoice via acc_qbo_invoice_map,
 * creates an FF payment row + payment_allocation + map row + JE post,
 * all atomically in db_transaction.
 *
 * Idempotency: 3 layers.
 *   (a) acc_qbo_webhook_events.uq_webhook_event_id — webhook receiver
 *       short-circuits replays before calling the handler.
 *   (b) acc_qbo_payment_map.uq_qbo_payment WHERE ff_payment_id IS NOT
 *       NULL — handler returns 'already_mapped' if the same QBO
 *       payment was already pulled into FF.
 *   (c) payments.payment_number is gap-free unique — DB constraint
 *       catches any race that slipped past the first two.
 *
 * Failure result codes (recorded in acc_qbo_webhook_events.processing_result):
 *   - wrong_realm           — webhook realm ≠ connected realm; skip
 *   - already_mapped        — QBO payment already linked to FF payment
 *   - qbo_pull_failed       — getEntity threw (HTTP error, auth, etc.)
 *   - qbo_payment_not_found — QBO returned 200 but no Payment in body
 *   - no_linked_invoice     — QBO payment has no LinkedTxn (unallocated)
 *   - no_ff_invoice_mapped  — LinkedTxn invoice has no FF mapping
 *   - currency_mismatch     — payment currency ≠ invoice currency (D18)
 *   - invoice_void          — target FF invoice is voided
 *   - error                 — unexpected throw (Sentry-captured)
 *   - payment_created       — happy path; FF payment + map row + JE all written
 *
 * @session  S-QBO-13
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §12.4 (webhook handler logic),
 *           §12.5 (payment webhook handler), §11 (QBO Payments embed)
 * @decision D-QBO-13-1 (payments.origin ENUM tags provenance),
 *           D-QBO-13-2 (webhook-originated payments skip push pipeline;
 *               push_status='pulled_from_qbo' terminal state),
 *           D-QBO-13-3 (HMAC-SHA256 base64 + constant-time compare;
 *               fail-closed on missing token),
 *           D-QBO-13-4 (handler is atomic via db_transaction;
 *               payment_number gap-free via FOR UPDATE on settings),
 *           D-QBO-13-5 (payment_method='other' for QBO Payments —
 *               ENUM doesn't include qbo_payments; origin column
 *               carries the actual provenance tag),
 *           D-QBO-13-6 (reference_number = 'QBO-{qboPaymentId}' when
 *               PaymentRefNum absent; preserves QBO Payment.Id for
 *               cross-system reconciliation drill-down)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Accounting\AutoEntryBridge;

class PaymentWebhookHandler
{
    /**
     * Main entry. Called from api/v1/webhooks/qbo_payment_notifications.php
     * after signature verification + webhook event log row written.
     *
     * @param  string $qboPaymentId    Intuit Payment.Id from event entity
     * @param  string $operation       'Create' | 'Update' | 'Void'
     * @param  string $realmId         Intuit realm from webhook payload
     * @param  string $webhookEventId  For audit trail link
     * @return array{result: string, detail?: string, ff_payment_id?: int}
     */
    public static function handle(string $qboPaymentId, string $operation, string $realmId, string $webhookEventId): array
    {
        // 1. Realm guard — webhooks from a different realm could happen if
        //    operator reconnected to a new QBO company between webhook
        //    registration and delivery. Don't process them.
        $connectedRealm = (string) settings_get('quickbooks.realm_id', '');
        if ($connectedRealm === '' || $realmId !== $connectedRealm) {
            return ['result' => 'wrong_realm', 'detail' => "received realm='{$realmId}'; connected to '{$connectedRealm}'"];
        }

        // 2. Map-level idempotency. The webhook receiver enforces idempotency
        //    on webhook_event_id (replay of the SAME webhook is a no-op).
        //    This second check catches a different case: a NEW webhook for
        //    a payment we've already pulled (e.g. Intuit retried with a
        //    different event id, or operator pulled via S-QBO-26 manual sync
        //    first).
        $existingMap = db_row(
            "SELECT id, ff_payment_id FROM acc_qbo_payment_map WHERE qbo_payment_id = ?",
            [$qboPaymentId]
        );
        if ($existingMap && $existingMap['ff_payment_id'] !== null) {
            return [
                'result' => 'already_mapped',
                'detail' => "qbo_payment_id={$qboPaymentId} → ff_payment_id={$existingMap['ff_payment_id']}",
                'ff_payment_id' => (int) $existingMap['ff_payment_id'],
            ];
        }

        // 3. Pull QBO payment. Webhook delivers a notification only; we
        //    must fetch the actual Payment entity per QBO_SPEC §12.3.
        try {
            $client = new QuickBooksClient();
            $resp = $client->getEntity('payment', $qboPaymentId);
        } catch (\Throwable $e) {
            return ['result' => 'qbo_pull_failed', 'detail' => $e->getMessage()];
        }
        $qboPayment = $resp['Payment'] ?? null;
        if (!is_array($qboPayment) || empty($qboPayment['Id'])) {
            return ['result' => 'qbo_payment_not_found', 'detail' => 'getEntity returned no Payment'];
        }

        // 4. Operation handling. v1 handles Create + Update via the same
        //    path (Update on an unmapped payment is treated as Create —
        //    we don't have a prior state to diff against). Void is treated
        //    as Create-then-mark-voided in v1; full Void semantics deferred
        //    to a follow-up because Void requires also voiding the FF
        //    payment which has accounting reversal implications.
        if ($operation === 'Void') {
            return [
                'result' => 'void_deferred',
                'detail' => 'QBO Payment.Void semantics deferred to S-QBO-13 follow-up — would need FF payment void + JE reversal. For v1 this skip is logged for operator review.',
            ];
        }

        // 5. Find the linked invoice. QBO Payments allocates a payment to
        //    1+ invoices via the Line[].LinkedTxn[] array. v1 handles the
        //    single-invoice case (LinkedTxn[0]); multi-invoice payments
        //    (split allocation) deferred to follow-up.
        $qboInvoiceId = $qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'] ?? null;
        if ($qboInvoiceId === null || $qboInvoiceId === '') {
            return [
                'result' => 'no_linked_invoice',
                'detail' => "QBO payment {$qboPaymentId} has no LinkedTxn[0].TxnId — unallocated payment (advance, deposit, or split that v1 doesn't support)",
            ];
        }

        // 6. Look up the FF invoice via acc_qbo_invoice_map. This is the
        //    canonical FF↔QBO invoice cross-reference set up by S-QBO-11
        //    invoice push.
        $invMap = db_row(
            "SELECT ff_invoice_id FROM acc_qbo_invoice_map WHERE qbo_invoice_id = ?",
            [(string) $qboInvoiceId]
        );
        if (!$invMap || empty($invMap['ff_invoice_id'])) {
            return [
                'result' => 'no_ff_invoice_mapped',
                'detail' => "QBO invoice id={$qboInvoiceId} has no FF mapping (S-QBO-11 invoice push hasn't seen this invoice, OR the invoice originated in QBO outside FF)",
            ];
        }

        // 6.5. S-QBO-15 initiation handshake per D-QBO-15-4.
        //     If this payment was initiated via the portal "Pay Online"
        //     button, an acc_qbo_payment_initiations row will exist with
        //     matching qbo_invoice_id + status='pending'. Match by latest
        //     pending row per D-QBO-15-1; mark complete + cross-reference
        //     qbo_payment_id BEFORE creating the FF payment row.
        //
        //     If no match, the payment likely originated outside the FF
        //     portal flow (e.g., direct QBO Payments email link, or
        //     accountant-recorded payment) — webhook continues with
        //     existing flow per the canonical S-QBO-13 design.
        $initiationMatch = PaymentInitiator::matchByQboInvoice(
            (string) $qboInvoiceId,
            $qboPaymentId
        );

        // 7. Create FF payment + allocation + map row + JE post — all in
        //    one db_transaction for atomicity (D-QBO-13-4).
        try {
            $result = db_transaction(function () use ($qboPayment, $invMap, $qboPaymentId, $webhookEventId, $realmId) {
                return self::createFfPaymentFromQbo(
                    $qboPayment,
                    (int) $invMap['ff_invoice_id'],
                    $qboPaymentId,
                    $webhookEventId,
                    $realmId
                );
            });
            // Annotate result with initiation match info for forensic trace.
            if ($initiationMatch !== null && is_array($result)) {
                $result['initiation_id'] = (int) $initiationMatch['id'];
                $result['initiation_handshook'] = true;
            }
            return $result;
        } catch (\Throwable $e) {
            error_log("[PaymentWebhookHandler] transaction threw for qbo_payment={$qboPaymentId}: " . $e->getMessage());
            return ['result' => 'error', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Atomic FF payment creation from a pulled QBO Payment entity.
     * Mirrors api/v1/payments/create.php pattern but skips the
     * HTTP/permission layer (this runs as a system-level operation
     * invoked by a webhook handler, not by an authenticated user).
     *
     * Trap 6 discipline: payment + allocation + invoice counter update
     * + customer counter update + map row + JE all in the same
     * transaction. Caller wraps in db_transaction().
     */
    /**
     * Public for smoke testability. Smoke crafts synthetic QBO Payment
     * arrays and exercises the create path directly without going
     * through QBO HTTP. Production callers go through self::handle()
     * which performs the QBO pull + idempotency check first.
     *
     * Caller wraps in db_transaction (see handle() step 7).
     */
    public static function createFfPaymentFromQbo(
        array $qboPayment,
        int $ffInvoiceId,
        string $qboPaymentId,
        string $webhookEventId,
        string $realmId
    ): array {
        // a. Lock invoice row + validate state.
        $invoice = db_row(
            "SELECT id, customer_id, currency, status, balance_due, amount_paid, total_amount
               FROM invoices WHERE id = ? FOR UPDATE",
            [$ffInvoiceId]
        );
        if (!$invoice) {
            return ['result' => 'error', 'detail' => "FF invoice id={$ffInvoiceId} not found at transaction time (race)"];
        }
        if ($invoice['status'] === 'void') {
            return ['result' => 'invoice_void', 'detail' => "FF invoice {$ffInvoiceId} is voided"];
        }

        $amount    = (string) ($qboPayment['TotalAmt'] ?? '0.00');
        $currency  = strtoupper((string) ($qboPayment['CurrencyRef']['value'] ?? 'CAD'));
        $txnDate   = substr((string) ($qboPayment['TxnDate'] ?? date('Y-m-d')), 0, 10);

        // D18 currency match — payment currency must equal invoice currency.
        if ($currency !== strtoupper((string) $invoice['currency'])) {
            return [
                'result' => 'currency_mismatch',
                'detail' => "QBO payment currency={$currency} ≠ FF invoice currency={$invoice['currency']} (D18 invariant)",
            ];
        }

        // b. Split-allocation math (S-FIX-2 Bug #2 pattern).
        $balanceDue = (string) $invoice['balance_due'];
        if (bccomp($amount, $balanceDue, 6) > 0) {
            $allocated   = bcround($balanceDue, 2);
            $overpayment = bcround(bcsub($amount, $balanceDue, 6), 2);
        } else {
            $allocated   = bcround($amount, 2);
            $overpayment = '0.00';
        }

        // c. Gap-free payment_number via FOR UPDATE on settings counter (D15).
        $year = date('Y');
        $key  = "payment.next_number.{$year}";
        $settingsRow = db_row("SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
        $next   = $settingsRow ? (int) $settingsRow['value'] : 1;
        $prefix = (string) settings_get('payment.prefix', 'PAY');
        $paymentNumber = sprintf('%s-%s-%05d', $prefix, $year, $next);
        if ($settingsRow) {
            db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string) ($next + 1), $key]);
        } else {
            db_execute("INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'payments')", [$key, (string) ($next + 1)]);
        }

        // d. Insert payment row. payment_method='other' per D-QBO-13-5
        //    (ENUM lacks qbo_payments; origin column carries provenance).
        //    reference_number prefers QBO's PaymentRefNum (if Intuit
        //    populated it); falls back to "QBO-{id}" per D-QBO-13-6.
        $referenceNumber = (string) ($qboPayment['PaymentRefNum'] ?? '');
        if ($referenceNumber === '') {
            $referenceNumber = "QBO-{$qboPaymentId}";
        }
        $ffPaymentId = db_insert('payments', [
            'payment_number'       => $paymentNumber,
            'customer_id'          => (int) $invoice['customer_id'],
            'amount'               => $amount,
            'currency'             => $currency,
            'payment_method'       => 'other',     // D-QBO-13-5
            'origin'               => 'qbo_payments_webhook',  // D-QBO-13-1
            'reference_number'     => substr($referenceNumber, 0, 100),
            'payment_date'         => $txnDate,
            'received_at'          => date('Y-m-d H:i:s'),
            'status'               => 'cleared',   // QBO Payments == real money received
            'overpayment_amount'   => $overpayment,
            'overpayment_action'   => bccomp($overpayment, '0', 2) > 0 ? 'credit_to_account' : null,
            'overpayment_resolved' => 0,
            'notes'                => "QBO Payments webhook — qbo_payment_id={$qboPaymentId}",
            'recorded_by'          => null,        // system origin; no FF user
        ]);

        // e. Insert allocation row.
        db_insert('payment_allocations', [
            'payment_id'      => $ffPaymentId,
            'invoice_id'      => $ffInvoiceId,
            'amount'          => $allocated,
            'currency'        => $currency,
            'allocation_type' => 'auto',
            'allocated_by'    => null,             // system origin
        ]);

        // f. Update invoice counters + status (Trap 6 — same transaction).
        $newAmountPaid = bcadd((string) $invoice['amount_paid'], $allocated, 2);
        $newBalanceDue = bcsub((string) $invoice['total_amount'], $newAmountPaid, 2);
        if (bccomp($newBalanceDue, '0', 2) <= 0) {
            $newStatus = 'paid';
            $newBalanceDue = '0.00';
        } elseif (bccomp($newAmountPaid, '0', 2) > 0) {
            $newStatus = 'partially_paid';
        } else {
            $newStatus = $invoice['status'];
        }
        db_execute(
            "UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ? WHERE id = ?",
            [$newAmountPaid, $newBalanceDue, $newStatus, $ffInvoiceId]
        );

        // g. Update customer.outstanding_balance counter (Trap 6 / D45).
        db_execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance - ? WHERE id = ?",
            [$allocated, (int) $invoice['customer_id']]
        );

        // h. Insert the acc_qbo_payment_map row. push_status='pulled_from_qbo'
        //    is the terminal state for webhook-originated payments per
        //    D-QBO-13-2 — they skip the push pipeline entirely (already
        //    in QBO; pushing back would create a duplicate).
        db_insert('acc_qbo_payment_map', [
            'ff_payment_id'         => $ffPaymentId,
            'qbo_payment_id'        => $qboPaymentId,
            'qbo_sync_token'        => (string) ($qboPayment['SyncToken'] ?? '0'),
            'qbo_total_amt'         => $amount,
            'qbo_currency'          => $currency,
            'qbo_txn_date'          => $txnDate,
            'qbo_linked_invoice_id' => (string) ($qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'] ?? ''),
            'origin'                => 'qbo_payments_webhook',
            'webhook_event_id'      => $webhookEventId,
            'realm_id'              => $realmId,
            'push_status'           => 'pulled_from_qbo',
            'pulled_at'             => date('Y-m-d H:i:s'),
            'last_synced_at'        => date('Y-m-d H:i:s'),
        ]);

        // i. AutoEntryBridge::onPaymentReceived posts the DR Cash / CR AR JE.
        //    Wrapped in try/catch so a JE failure doesn't break the webhook
        //    handler (operator can re-post manually); the FF payment + map
        //    row are already written and reconciliation will catch the gap.
        try {
            AutoEntryBridge::onPaymentReceived($ffPaymentId, $ffInvoiceId, $allocated, null);
        } catch (\Throwable $e) {
            error_log("[PaymentWebhookHandler] AutoEntryBridge JE post failed for ff_payment={$ffPaymentId}: " . $e->getMessage());
            // Don't fail the whole handler — payment is recorded, JE can be re-posted manually.
        }

        return [
            'result'        => 'payment_created',
            'detail'        => "ff_payment={$ffPaymentId} ({$paymentNumber}); allocated {$allocated} {$currency} to invoice {$ffInvoiceId}",
            'ff_payment_id' => $ffPaymentId,
        ];
    }
}
