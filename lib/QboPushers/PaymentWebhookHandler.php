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
 *   - ff_origin_echo        — QBO echoing a Payment FF itself pushed (credit
 *                             application / write-off map hit, or PrivateNote
 *                             carries an FF id) — never re-imported
 *                             (S-QBO-GOLIVE-AUDIT; write-offs S-QBO-INVOICE-WRITEOFF)
 *   - zero_amount           — $0 Payment (credit-memo link, no money moved)
 *   - error                 — unexpected throw (Sentry-captured)
 *   - payment_created       — happy path; FF payment + allocations + map row + JEs
 *   S-QBO-GOLIVE-AUDIT lifecycle results:
 *   - payment_voided        — Void/Delete in QBO → FF payment voided (GL reversed)
 *   - already_voided / void_not_mirrored — nothing left to void in FF
 *   - void_blocked / resync_blocked — FF refused (closed period, credit
 *                             already applied): a drift event asks for a hand fix
 *   - unchanged / payment_resynced — Update on a QBO-originated payment
 *   - drift_recorded        — QBO-side change FF must not apply (FF-pushed
 *                             payment edited in QBO, FF credit application removed)
 *
 * payment_method comes from QBO PaymentMethodRef (card / ACH / cheque …)
 * via mapPaymentMethod() — D-QBO-13-5's constant 'other' is now the fallback.
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
     * Flatten either Intuit webhook envelope into one event list.
     *
     *   Legacy (retired 2026):  {"eventNotifications":[{"realmId":"…",
     *       "dataChangeEvent":{"entities":[{"name":"Payment","id":"…",
     *       "operation":"Create"}]}}]}
     *   CloudEvents (current):  [{"specversion":"1.0","id":"…",
     *       "type":"qbo.payment.created.v1","intuitaccountid":"<realm>",
     *       "intuitentityid":"<id>", …}]
     *
     * CloudEvent verbs map onto the legacy operation names the handler
     * already speaks (created→Create, updated→Update, voided→Void, …).
     *
     * @return list<array{realm_id: string, name: string, entity_id: string, operation: string, event_id: string}>
     * @session S-QBO-GOLIVE-AUDIT
     */
    public static function normalizeEvents(array $payload): array
    {
        $out = [];

        // Legacy envelope.
        foreach ($payload['eventNotifications'] ?? [] as $notif) {
            foreach ($notif['dataChangeEvent']['entities'] ?? [] as $entity) {
                $out[] = [
                    'realm_id'  => (string) ($notif['realmId'] ?? ''),
                    'name'      => (string) ($entity['name'] ?? ''),
                    'entity_id' => (string) ($entity['id'] ?? ''),
                    'operation' => (string) ($entity['operation'] ?? ''),
                    'event_id'  => '',
                ];
            }
        }

        // CloudEvents: a top-level JSON array (a single bare event object is
        // accepted too, defensively).
        $ceList = array_is_list($payload) ? $payload : (isset($payload['specversion']) ? [$payload] : []);
        $verbs  = [
            'created' => 'Create', 'updated' => 'Update', 'deleted' => 'Delete',
            'voided'  => 'Void',   'merged'  => 'Merge',  'emailed' => 'Emailed',
        ];
        foreach ($ceList as $ce) {
            if (!is_array($ce) || !isset($ce['type'])) {
                continue;
            }
            // type = "qbo.<entity>.<verb>.v<n>"
            $parts = explode('.', (string) $ce['type']);
            if (count($parts) < 3 || strtolower($parts[0]) !== 'qbo') {
                continue;
            }
            $verb  = strtolower($parts[2]);
            $out[] = [
                'realm_id'  => (string) ($ce['intuitaccountid'] ?? ''),
                'name'      => ucfirst(strtolower($parts[1])),
                'entity_id' => (string) ($ce['intuitentityid'] ?? ''),
                'operation' => $verbs[$verb] ?? ucfirst($verb),
                'event_id'  => (string) ($ce['id'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Main entry. Called from api/v1/webhooks/qbo_payment_notifications.php
     * after signature verification + webhook event log row written.
     *
     * S-QBO-GOLIVE-AUDIT: customers pay THROUGH QuickBooks (QuickBooks
     * Payments email links + the portal), so this is the primary way
     * FleetForge learns an invoice is paid. It mirrors the whole QBO payment
     * lifecycle into FF's records AND FF's books:
     *   Create        → ONE FF payment allocated across every FF invoice the
     *                   QBO payment links (was: the whole amount on Line[0],
     *                   so invoices 2…n of a multi-invoice cheque stayed
     *                   overdue in FF while paid in QBO);
     *   Update        → a QBO-originated payment whose amount / invoices
     *                   changed is re-mirrored (FF copy voided + recreated);
     *                   an FF-pushed payment edited in QBO raises a drift
     *                   event (FF owns it);
     *   Void / Delete → the FF payment is voided through the normal void path
     *                   (allocations, invoice status, counters and payment
     *                   JEs reversed) — was silently ignored.
     *
     * @param  string $qboPaymentId    Intuit Payment.Id from event entity
     * @param  string $operation       'Create' | 'Update' | 'Void' | 'Delete'
     * @param  string $realmId         Intuit realm from webhook payload
     * @param  string $webhookEventId  For audit trail link
     * @return array{result: string, detail?: string, ff_payment_id?: int}
     */
    public static function handle(string $qboPaymentId, string $operation, string $realmId, string $webhookEventId, string $origin = 'qbo_payments_webhook'): array
    {
        // $origin: 'qbo_payments_webhook' for the live webhook; 'qbo_other'
        // when InvoiceLinker imports go-live history / catches up missed
        // webhooks. Both are QuickBooks-owned copies (re-synced on edit).
        if (!in_array($origin, ['qbo_payments_webhook', 'qbo_other'], true)) {
            $origin = 'qbo_payments_webhook';
        }
        // 1. Realm guard — webhooks from a different realm could happen if
        //    operator reconnected to a new QBO company between webhook
        //    registration and delivery. Don't process them.
        $connectedRealm = (string) settings_get('quickbooks.realm_id', '');
        if ($connectedRealm === '' || $realmId !== $connectedRealm) {
            return ['result' => 'wrong_realm', 'detail' => "received realm='{$realmId}'; connected to '{$connectedRealm}'"];
        }

        // 2. FF's own credit applications (S-QBO-GOLIVE-AUDIT). Credit-memo
        //    applies are pushed as $0 QBO Payments (CreditApplicationPusher)
        //    and tracked in acc_qbo_credit_application_map, NOT the payment
        //    map — without this check QBO's echo could be imported back as a
        //    spurious FF payment against the invoice.
        $appEcho = db_row(
            "SELECT ff_credit_application_id FROM acc_qbo_credit_application_map WHERE qbo_payment_id = ?",
            [$qboPaymentId]
        );
        if ($appEcho) {
            if (in_array($operation, ['Void', 'Delete'], true)) {
                self::recordQboSideChange(
                    'payment', null, $qboPaymentId,
                    "A FleetForge credit application (#{$appEcho['ff_credit_application_id']}) was " . strtolower($operation)
                    . "d in QuickBooks. FleetForge still shows the credit applied — unapply it on the credit note in FleetForge so both agree."
                );
                return ['result' => 'drift_recorded', 'detail' => "credit application #{$appEcho['ff_credit_application_id']} {$operation} in QBO"];
            }
            return [
                'result' => 'ff_origin_echo',
                'detail' => "qbo_payment_id={$qboPaymentId} is FF credit application #{$appEcho['ff_credit_application_id']}",
            ];
        }

        // 2b. S-QBO-INVOICE-WRITEOFF: the $0 Payment applying a write-off's
        //     Bad-debt CreditMemo to its invoice (InvoiceWriteoffPusher) is
        //     FF's own — its echo is not a credit applied inside QuickBooks.
        $woEcho = db_row("SELECT ff_writeoff_id FROM acc_qbo_invoice_writeoff_map WHERE qbo_payment_id = ?", [$qboPaymentId]);
        if ($woEcho) {
            if (in_array($operation, ['Void', 'Delete'], true)) {
                self::recordQboSideChange(
                    'payment', null, $qboPaymentId,
                    "The QuickBooks credit that closes a FleetForge bad-debt write-off (write-off #{$woEcho['ff_writeoff_id']}) was " . strtolower($operation)
                    . "d in QuickBooks, so that invoice is open there again while FleetForge shows it written off. Re-apply the write-off credit memo in QuickBooks, or recover the write-off in FleetForge."
                );
                return ['result' => 'drift_recorded', 'detail' => "write-off #{$woEcho['ff_writeoff_id']} apply Payment {$operation} in QBO"];
            }
            return ['result' => 'ff_origin_echo', 'detail' => "qbo_payment_id={$qboPaymentId} applies FF write-off #{$woEcho['ff_writeoff_id']}"];
        }

        // 3. Void / Delete in QuickBooks → void the FF copy.
        if (in_array($operation, ['Void', 'Delete'], true)) {
            return self::handleVoid($qboPaymentId, $operation);
        }

        // 4. Already mirrored? An Update re-checks it; a replayed Create is a no-op.
        $existingMap = db_row(
            "SELECT id, ff_payment_id, origin, push_status FROM acc_qbo_payment_map WHERE qbo_payment_id = ?",
            [$qboPaymentId]
        );
        if ($existingMap && $existingMap['ff_payment_id'] !== null) {
            if ($operation === 'Update') {
                return self::handleUpdate($existingMap, $qboPaymentId, $webhookEventId, $realmId);
            }
            return [
                'result' => 'already_mapped',
                'detail' => "qbo_payment_id={$qboPaymentId} → ff_payment_id={$existingMap['ff_payment_id']}",
                'ff_payment_id' => (int) $existingMap['ff_payment_id'],
            ];
        }

        // 5. Pull QBO payment. Webhook delivers a notification only; we
        //    must fetch the actual Payment entity per QBO_SPEC §12.3.
        $qboPayment = self::fetchQboPayment($qboPaymentId);
        if (isset($qboPayment['result'])) {
            return $qboPayment; // qbo_pull_failed / qbo_payment_not_found
        }

        // 5b. FF-pushed payment echo. PaymentPusher / CreditApplicationPusher
        //     stamp PrivateNote with their FF ids; Intuit fires the Create
        //     webhook for FF's OWN push, possibly before the pusher persisted
        //     its map row. The stamp is authoritative either way.
        $note = json_decode((string) ($qboPayment['PrivateNote'] ?? ''), true);
        if (is_array($note) && (isset($note['ff_payment_id']) || isset($note['ff_credit_application_id']) || isset($note['ff_writeoff_id']))) {
            return [
                'result' => 'ff_origin_echo',
                'detail' => "qbo_payment_id={$qboPaymentId} was pushed by FleetForge (PrivateNote carries its FF id)",
            ];
        }

        $plan = self::planFromQbo($qboPayment);

        // 5c. A $0 Payment moves no money — in QBO it only links a credit memo
        //     to an invoice. Never mirror it as an FF payment, but if it
        //     touches an FF invoice the accountant applied a QBO credit to an
        //     FF invoice, which FF cannot see: flag it.
        if (bccomp((string) ($qboPayment['TotalAmt'] ?? '0'), '0', 2) === 0) {
            if ($plan['targets'] !== []) {
                self::recordQboSideChange(
                    'payment', null, $qboPaymentId,
                    'A credit was applied to FleetForge invoice(s) ' . implode(', ', $plan['ff_invoice_numbers'])
                    . ' inside QuickBooks. FleetForge still shows the full balance — apply the matching credit note in FleetForge.'
                );
            }
            return ['result' => 'zero_amount', 'detail' => "qbo_payment_id={$qboPaymentId} TotalAmt=0 (credit application, not money received)"];
        }

        // 6. Nothing FF can mirror (no linked invoice, or only invoices FF
        //    didn't push — another business's, or pre-go-live history).
        if ($plan['targets'] === []) {
            return [
                'result' => $plan['linked_invoices'] === 0 ? 'no_linked_invoice' : 'no_ff_invoice_mapped',
                'detail' => $plan['linked_invoices'] === 0
                    ? "QBO payment {$qboPaymentId} is not applied to any invoice (unapplied / deposit)"
                    : "QBO payment {$qboPaymentId} applies only to invoices FleetForge did not push",
            ];
        }

        // 6.5. S-QBO-15 initiation handshake per D-QBO-15-4 — portal
        //      "Pay Online" rows are matched by QBO invoice (any of them).
        $initiationMatch = null;
        foreach ($plan['qbo_invoice_ids'] as $qboInvoiceId) {
            $m = PaymentInitiator::matchByQboInvoice((string) $qboInvoiceId, $qboPaymentId);
            if ($m !== null && $initiationMatch === null) {
                $initiationMatch = $m;
            }
        }

        // 7. Create FF payment + allocations + map row + JEs — one
        //    db_transaction for atomicity (D-QBO-13-4).
        try {
            $result = db_transaction(function () use ($qboPayment, $plan, $qboPaymentId, $webhookEventId, $realmId, $origin) {
                return self::recordFromQbo($qboPayment, $plan['targets'], $plan['ff_amount'], $qboPaymentId, $webhookEventId, $realmId, $origin);
            });
            if ($initiationMatch !== null && is_array($result)) {
                $result['initiation_id'] = (int) $initiationMatch['id'];
                $result['initiation_handshook'] = true;
            }
            foreach ($plan['warnings'] as $warning) {
                self::recordQboSideChange('payment', isset($result['ff_payment_id']) ? (int) $result['ff_payment_id'] : null, $qboPaymentId, $warning);
            }
            // S-QBO-GOLIVE-AUDIT: close a per-line-vs-per-invoice tax rounding
            // residual once QuickBooks shows the invoice paid (RoundingSettler).
            if (($result['result'] ?? '') === 'payment_created') {
                foreach ($plan['targets'] as $t) {
                    try {
                        RoundingSettler::settleIfQboPaid((int) $t['ff_invoice_id']);
                    } catch (\Throwable $e) {
                        error_log('[PaymentWebhookHandler] rounding settle failed: ' . $e->getMessage());
                    }
                }
            }
            return $result;
        } catch (\Throwable $e) {
            error_log("[PaymentWebhookHandler] transaction threw for qbo_payment={$qboPaymentId}: " . $e->getMessage());
            return ['result' => 'error', 'detail' => $e->getMessage()];
        }
    }

    /**
     * GET the Payment from QBO. Returns the Payment entity, or a handler
     * result array (has a 'result' key) on failure.
     */
    private static function fetchQboPayment(string $qboPaymentId): array
    {
        try {
            $resp = (new QuickBooksClient())->getEntity('payment', $qboPaymentId);
        } catch (\Throwable $e) {
            return ['result' => 'qbo_pull_failed', 'detail' => $e->getMessage()];
        }
        $qboPayment = $resp['Payment'] ?? null;
        if (!is_array($qboPayment) || empty($qboPayment['Id'])) {
            return ['result' => 'qbo_payment_not_found', 'detail' => 'getEntity returned no Payment'];
        }
        return $qboPayment;
    }

    /**
     * planFromQbo — work out which FF invoices a QBO payment pays, and how
     * much CASH lands on each (S-QBO-GOLIVE-AUDIT).
     *
     * QBO Payment shape: TotalAmt = cash received; Line[] each carry an
     * Amount + LinkedTxn (Invoice or CreditMemo). Invoice line amounts
     * include any credit applied in the same payment, so
     *   cash applied to invoices = Σ invoice lines − Σ credit-memo lines
     *   unapplied cash           = TotalAmt − that.
     * FF mirrors only what it can prove belongs to FF invoices:
     *   - invoices FF didn't push (another business in a shared file, or
     *     pre-go-live history) take their full line first and are left out;
     *   - a credit applied inside QBO is not reproduced (FF can't apply a
     *     QBO-only credit) — the cash is placed on FF invoices in line order,
     *     so the FF invoice keeps the credited part open and a warning says so;
     *   - unapplied cash becomes an FF account credit only when the payment
     *     touches FF invoices exclusively (otherwise it can't be attributed).
     *
     * @return array{targets: list<array{ff_invoice_id:int, qbo_invoice_id:string, amount:string}>,
     *               ff_amount: string, linked_invoices: int, qbo_invoice_ids: list<string>,
     *               ff_invoice_numbers: list<string>, warnings: list<string>}
     */
    public static function planFromQbo(array $qboPayment): array
    {
        $total        = bcadd((string) ($qboPayment['TotalAmt'] ?? '0'), '0', 2);
        $invoiceLines = [];   // qbo invoice id => amount (in line order)
        $creditSum    = '0.00';
        foreach ((array) ($qboPayment['Line'] ?? []) as $line) {
            $amt = bcadd((string) ($line['Amount'] ?? '0'), '0', 2);
            foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
                $tid  = (string) ($lt['TxnId'] ?? '');
                $type = (string) ($lt['TxnType'] ?? '');
                if ($tid === '') {
                    continue;
                }
                if ($type === 'Invoice') {
                    $invoiceLines[$tid] = bcadd($invoiceLines[$tid] ?? '0.00', $amt, 2);
                } elseif ($type === 'CreditMemo') {
                    $creditSum = bcadd($creditSum, $amt, 2);
                }
            }
        }

        $ffLines = [];
        $nonFfSum = '0.00';
        $invoiceSum = '0.00';
        $numbers = [];
        foreach ($invoiceLines as $tid => $amt) {
            $invoiceSum = bcadd($invoiceSum, $amt, 2);
            $m = db_row(
                "SELECT m.ff_invoice_id, i.invoice_number
                   FROM acc_qbo_invoice_map m
                   JOIN invoices i ON i.id = m.ff_invoice_id
                  WHERE m.qbo_invoice_id = ? AND m.ff_invoice_id IS NOT NULL",
                [(string) $tid]
            );
            if ($m) {
                $ffLines[] = ['ff_invoice_id' => (int) $m['ff_invoice_id'], 'qbo_invoice_id' => (string) $tid, 'amount' => $amt];
                $numbers[] = (string) $m['invoice_number'];
            } else {
                $nonFfSum = bcadd($nonFfSum, $amt, 2);
            }
        }

        $cashToInvoices = bcsub($invoiceSum, $creditSum, 2);
        if (bccomp($cashToInvoices, '0', 2) < 0) {
            $cashToInvoices = '0.00';
        }
        $unapplied = bcsub($total, $cashToInvoices, 2);
        if (bccomp($unapplied, '0', 2) < 0) {
            $unapplied = '0.00';
        }

        // Cash available to FF invoices: what reached invoices, minus the
        // non-FF invoices' full lines.
        $ffCash = bcsub(bcsub($total, $unapplied, 2), $nonFfSum, 2);
        if (bccomp($ffCash, '0', 2) < 0) {
            $ffCash = '0.00';
        }
        $targets = [];
        $placed = '0.00';
        foreach ($ffLines as $l) {
            $left = bcsub($ffCash, $placed, 2);
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $amt = bccomp($l['amount'], $left, 2) > 0 ? $left : $l['amount'];
            $targets[] = ['ff_invoice_id' => $l['ff_invoice_id'], 'qbo_invoice_id' => $l['qbo_invoice_id'], 'amount' => $amt];
            $placed = bcadd($placed, $amt, 2);
        }

        $warnings = [];
        $ffUnapplied = '0.00';
        if (bccomp($unapplied, '0', 2) > 0) {
            if (bccomp($nonFfSum, '0', 2) === 0 && $ffLines !== []) {
                $ffUnapplied = $unapplied;   // becomes an FF account credit
            } elseif ($ffLines !== []) {
                $warnings[] = "Part of this QuickBooks payment ({$unapplied}) was left unapplied alongside invoices FleetForge doesn't track — it was not recorded in FleetForge.";
            }
        }
        if (bccomp($creditSum, '0', 2) > 0 && $ffLines !== []) {
            $warnings[] = 'A credit (' . $creditSum . ') was applied inside QuickBooks as part of this payment to FleetForge invoice(s) '
                . implode(', ', $numbers) . '. FleetForge recorded only the cash — apply the matching credit note in FleetForge.';
        }

        return [
            'targets'            => $targets,
            'ff_amount'          => bcadd($placed, $ffUnapplied, 2),
            'linked_invoices'    => count($invoiceLines),
            'qbo_invoice_ids'    => array_map(static fn($l) => $l['qbo_invoice_id'], $ffLines),
            'ff_invoice_numbers' => $numbers,
            'warnings'           => $warnings,
        ];
    }

    /**
     * Void the FF mirror of a payment voided / deleted in QuickBooks.
     * Uses FinancialActions::voidPayment — the same path as the Payments
     * page — so allocations, invoice statuses, counters, overpayment credits
     * and the payment JEs all reverse together.
     */
    private static function handleVoid(string $qboPaymentId, string $operation): array
    {
        $map = db_row(
            "SELECT id, ff_payment_id, origin, push_status FROM acc_qbo_payment_map WHERE qbo_payment_id = ?",
            [$qboPaymentId]
        );
        if (!$map || $map['ff_payment_id'] === null) {
            return ['result' => 'void_not_mirrored', 'detail' => "QBO payment {$qboPaymentId} was never mirrored in FleetForge"];
        }
        $pay = db_row("SELECT id, payment_number, deleted_at FROM payments WHERE id = ?", [(int) $map['ff_payment_id']]);
        if (!$pay || $pay['deleted_at'] !== null) {
            db_update('acc_qbo_payment_map', ['push_status' => 'voided', 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
            return ['result' => 'already_voided', 'detail' => "FF payment for QBO payment {$qboPaymentId} is already voided"];
        }

        // Mark the map voided FIRST: for an FF-pushed payment, voidPayment
        // enqueues a QBO void, which then becomes an idempotent no-op
        // (already_voided) instead of voiding QBO a second time.
        $previousStatus = (string) $map['push_status'];
        db_update('acc_qbo_payment_map', ['push_status' => 'voided', 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
        try {
            \FleetForge\AI\Actions\FinancialActions::voidPayment(
                (int) $pay['id'],
                "Voided in QuickBooks (QBO payment {$qboPaymentId}, {$operation})",
                null,
                'QuickBooks',
                '127.0.0.1'
            );
        } catch (\Throwable $e) {
            db_update('acc_qbo_payment_map', ['push_status' => $previousStatus], 'id = ?', [(int) $map['id']]);
            self::recordQboSideChange(
                'payment', (int) $pay['id'], $qboPaymentId,
                "Payment {$pay['payment_number']} was " . strtolower($operation) . "d in QuickBooks, but FleetForge could not void it automatically ("
                . $e->getMessage() . "). Void it in FleetForge by hand so both systems agree."
            );
            return ['result' => 'void_blocked', 'detail' => $e->getMessage(), 'ff_payment_id' => (int) $pay['id']];
        }
        return ['result' => 'payment_voided', 'detail' => "FF payment {$pay['payment_number']} voided to match QuickBooks", 'ff_payment_id' => (int) $pay['id']];
    }

    /**
     * A mirrored payment was edited in QuickBooks. QBO-originated payments
     * are re-mirrored from QBO's current state (FF copy voided, then
     * recreated); FF-pushed payments are FF's to own, so a QBO-side edit
     * raises a drift event instead of silently rewriting FF.
     */
    private static function handleUpdate(array $map, string $qboPaymentId, string $webhookEventId, string $realmId): array
    {
        $ffPay = db_row(
            "SELECT id, payment_number, amount, origin, deleted_at FROM payments WHERE id = ?",
            [(int) $map['ff_payment_id']]
        );
        if (!$ffPay || $ffPay['deleted_at'] !== null) {
            return ['result' => 'already_voided', 'detail' => "FF payment for QBO payment {$qboPaymentId} is voided"];
        }
        $qboPayment = self::fetchQboPayment($qboPaymentId);
        if (isset($qboPayment['result'])) {
            return $qboPayment;
        }
        $plan = self::planFromQbo($qboPayment);

        // Per-invoice comparison (S-QBO-GOLIVE-AUDIT): what FF has put on
        // each invoice from this payment = its allocations PLUS applications
        // of the payment's own overpayment credit (those are pushed as extra
        // lines on this same QBO Payment — CreditApplicationPusher). Without
        // counting them, applying an overpayment credit in FF made the next
        // QBO Update look like an edit: a QBO-origin payment was re-mirrored
        // (crediting that invoice twice) and an FF-native one raised drift.
        $current = self::ffAmountsByInvoice((int) $ffPay['id']);
        $wanted  = [];
        foreach ($plan['targets'] as $t) {
            $k = (int) $t['ff_invoice_id'];
            $wanted[$k] = bcadd($wanted[$k] ?? '0.00', bcadd((string) $t['amount'], '0', 2), 2);
        }
        ksort($current);
        ksort($wanted);
        $unchanged = bccomp((string) $ffPay['amount'], $plan['ff_amount'], 2) === 0
            && array_keys($current) === array_keys($wanted);
        foreach ($wanted as $k => $amt) {
            if ($unchanged && bccomp($amt, $current[$k] ?? '0.00', 2) !== 0) {
                $unchanged = false;
            }
        }

        if ($ffPay['origin'] === 'ff_native') {
            if ($unchanged) {
                return ['result' => 'already_mapped', 'detail' => "FF-pushed payment {$ffPay['payment_number']} unchanged in QBO", 'ff_payment_id' => (int) $ffPay['id']];
            }
            self::recordQboSideChange(
                'payment', (int) $ffPay['id'], $qboPaymentId,
                "Payment {$ffPay['payment_number']} was edited in QuickBooks (QuickBooks now shows {$plan['ff_amount']} on FleetForge invoices; FleetForge has {$ffPay['amount']}). "
                . 'FleetForge recorded this payment — change it in FleetForge instead, or void and re-enter it.'
            );
            return ['result' => 'drift_recorded', 'detail' => 'FF-pushed payment edited in QBO', 'ff_payment_id' => (int) $ffPay['id']];
        }

        if ($unchanged) {
            return ['result' => 'unchanged', 'detail' => "QBO payment {$qboPaymentId} unchanged", 'ff_payment_id' => (int) $ffPay['id']];
        }

        try {
            \FleetForge\AI\Actions\FinancialActions::voidPayment(
                (int) $ffPay['id'],
                "Re-synced: QBO payment {$qboPaymentId} was edited in QuickBooks",
                null,
                'QuickBooks',
                '127.0.0.1'
            );
        } catch (\Throwable $e) {
            self::recordQboSideChange(
                'payment', (int) $ffPay['id'], $qboPaymentId,
                "Payment {$ffPay['payment_number']} was edited in QuickBooks, but FleetForge could not re-sync it ("
                . $e->getMessage() . '). Update it in FleetForge by hand.'
            );
            return ['result' => 'resync_blocked', 'detail' => $e->getMessage(), 'ff_payment_id' => (int) $ffPay['id']];
        }
        if ($plan['targets'] === []) {
            return ['result' => 'payment_voided', 'detail' => 'QBO payment no longer applies to any FleetForge invoice'];
        }
        $origin = (string) $ffPay['origin'];   // a re-sync keeps the copy's provenance
        try {
            $result = db_transaction(function () use ($qboPayment, $plan, $qboPaymentId, $webhookEventId, $realmId, $origin) {
                return self::recordFromQbo($qboPayment, $plan['targets'], $plan['ff_amount'], $qboPaymentId, $webhookEventId, $realmId, $origin);
            });
        } catch (\Throwable $e) {
            error_log("[PaymentWebhookHandler] re-sync create threw for qbo_payment={$qboPaymentId}: " . $e->getMessage());
            return ['result' => 'error', 'detail' => $e->getMessage()];
        }
        $result['result'] = ($result['result'] ?? '') === 'payment_created' ? 'payment_resynced' : ($result['result'] ?? 'error');
        return $result;
    }

    /**
     * FF invoice id → amount this payment has put on it in FF: allocations
     * plus live applications of the payment's own overpayment credit(s).
     *
     * @return array<int,string>
     * @session S-QBO-GOLIVE-AUDIT
     */
    public static function ffAmountsByInvoice(int $ffPaymentId): array
    {
        $out = [];
        foreach (db_select("SELECT invoice_id, amount FROM payment_allocations WHERE payment_id = ?", [$ffPaymentId]) as $a) {
            $k = (int) $a['invoice_id'];
            $out[$k] = bcadd($out[$k] ?? '0.00', bcadd((string) $a['amount'], '0', 2), 2);
        }
        foreach (db_select(
            "SELECT cna.invoice_id, cna.amount_applied
               FROM credit_note_applications cna
               JOIN credit_notes cn ON cn.id = cna.credit_note_id
              WHERE cn.source = 'overpayment' AND cn.source_payment_id = ?
                AND cna.status = 'applied' AND cn.status <> 'void'",
            [$ffPaymentId]
        ) as $a) {
            $k = (int) $a['invoice_id'];
            $out[$k] = bcadd($out[$k] ?? '0.00', bcadd((string) $a['amount_applied'], '0', 2), 2);
        }
        return $out;
    }

    /**
     * Record a QuickBooks-side change FleetForge could not (or must not)
     * apply automatically, so it shows on the Drift page and in the bell.
     * detection_source='pull_failure' keeps it out of the nightly
     * auto-resolve (which only closes drift_cron events).
     *
     * @session S-QBO-GOLIVE-AUDIT
     */
    public static function recordQboSideChange(string $entityType, ?int $ffEntityId, string $qboEntityId, string $description): void
    {
        try {
            $id = db_insert('acc_qbo_drift_events', [
                'detection_source' => 'pull_failure',
                'category'         => 'field_mismatch',
                'entity_type'      => $entityType,
                'entity_id'        => $ffEntityId,
                'qbo_entity_id'    => $qboEntityId,
                'description'      => substr($description, 0, 1000),
                'realm_id'         => (string) settings_get('quickbooks.realm_id', 'unknown') ?: 'unknown',
                'environment'      => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
            if (class_exists('\\FleetForge\\Notifications\\NotificationService')) {
                \FleetForge\Notifications\NotificationService::notify(
                    type:       'quickbooks.drift',
                    title:      'Changed in QuickBooks — needs attention',
                    message:    substr($description, 0, 300),
                    entityType: 'qbo_drift',
                    entityId:   (int) $id,
                    url:        base_url('quickbooks/drift_show') . '?id=' . (int) $id,
                    severity:   'warning'
                );
            }
        } catch (\Throwable $e) {
            error_log('[PaymentWebhookHandler] recordQboSideChange failed: ' . $e->getMessage());
        }
    }

    /**
     * FF payment_method for a QBO PaymentMethodRef name (QuickBooks Payments
     * reports card brand / ACH; accountant entries use the company's list).
     */
    public static function mapPaymentMethod(?string $qboName): string
    {
        $n = strtolower(trim((string) $qboName));
        if ($n === '') {
            return 'other';
        }
        return match (true) {
            str_contains($n, 'interac') || str_contains($n, 'e-transfer') || str_contains($n, 'etransfer') => 'e_transfer',
            str_contains($n, 'visa') || str_contains($n, 'master') || str_contains($n, 'amex')
                || str_contains($n, 'american express') || str_contains($n, 'discover') || str_contains($n, 'card')
                || str_contains($n, 'credit') => 'credit_card',
            str_contains($n, 'ach') || str_contains($n, 'eft') || str_contains($n, 'bank') || str_contains($n, 'check') && str_contains($n, 'e-') => 'ach',
            str_contains($n, 'cheque') || str_contains($n, 'check') => 'check',
            str_contains($n, 'wire') => 'wire',
            str_contains($n, 'cash') => 'cash',
            default => 'other',
        };
    }

    /**
     * Single-invoice entry point kept for callers + the smoke: mirrors a QBO
     * payment onto ONE FF invoice (the whole TotalAmt; excess over the
     * balance becomes an account credit). Caller wraps in db_transaction.
     */
    public static function createFfPaymentFromQbo(
        array $qboPayment,
        int $ffInvoiceId,
        string $qboPaymentId,
        string $webhookEventId,
        string $realmId
    ): array {
        $total = bcadd((string) ($qboPayment['TotalAmt'] ?? '0.00'), '0', 2);
        return self::recordFromQbo(
            $qboPayment,
            [['ff_invoice_id' => $ffInvoiceId, 'qbo_invoice_id' => (string) ($qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'] ?? ''), 'amount' => $total]],
            $total,
            $qboPaymentId,
            $webhookEventId,
            $realmId
        );
    }

    /**
     * Atomic FF payment creation from a QBO Payment: one FF payment, one
     * allocation per target invoice, invoice/customer/lease counters, an
     * account-credit note for any excess, the map row, and the JEs — the
     * same books create.php + allocate.php produce. Caller wraps in
     * db_transaction (Trap 6 / D-QBO-13-4).
     *
     * @param list<array{ff_invoice_id:int, qbo_invoice_id?:string, amount:string}> $targets
     * @param string $ffAmount cash FF mirrors (Σ targets + any FF-attributable unapplied)
     */
    public static function recordFromQbo(
        array $qboPayment,
        array $targets,
        string $ffAmount,
        string $qboPaymentId,
        string $webhookEventId,
        string $realmId,
        string $origin = 'qbo_payments_webhook'
    ): array {
        $currency = strtoupper((string) ($qboPayment['CurrencyRef']['value'] ?? 'CAD'));
        $txnDate  = substr((string) ($qboPayment['TxnDate'] ?? date('Y-m-d')), 0, 10);

        // a. Lock + validate every target invoice. A bad target is skipped
        //    (its cash is not mirrored); if none survive, the first reason is
        //    returned (single-invoice callers see the original result codes).
        $valid = [];
        $firstError = null;
        $skipped = '0.00';
        foreach ($targets as $t) {
            $inv = db_row(
                "SELECT id, invoice_number, customer_id, currency, status, balance_due, amount_paid, total_amount,
                        credits_applied, lease_id, exchange_rate_to_cad, currency_markup_pct
                   FROM invoices WHERE id = ? FOR UPDATE",
                [(int) $t['ff_invoice_id']]
            );
            $err = null;
            if (!$inv) {
                $err = ['result' => 'error', 'detail' => "FF invoice id={$t['ff_invoice_id']} not found at transaction time (race)"];
            } elseif ($inv['status'] === 'void') {
                $err = ['result' => 'invoice_void', 'detail' => "FF invoice {$t['ff_invoice_id']} is voided"];
            } elseif (!in_array($inv['status'], ['sent', 'partially_paid', 'overdue'], true)) {
                // S-AUDIT-BILLING-ENGINE-1 #12: payable-status gate.
                $err = ['result' => 'invoice_not_payable',
                        'detail' => "FF invoice {$t['ff_invoice_id']} is '{$inv['status']}' — only sent/partially_paid/overdue are payable"];
            } elseif ($currency !== strtoupper((string) $inv['currency'])) {
                // D18 currency match — payment currency must equal invoice currency.
                $err = ['result' => 'currency_mismatch',
                        'detail' => "QBO payment currency={$currency} ≠ FF invoice currency={$inv['currency']} (D18 invariant)"];
            } elseif ($valid !== [] && (int) $inv['customer_id'] !== (int) $valid[0]['invoice']['customer_id']) {
                $err = ['result' => 'customer_mismatch', 'detail' => "FF invoice {$t['ff_invoice_id']} belongs to a different customer"];
            }
            if ($err !== null) {
                $firstError ??= $err;
                $skipped = bcadd($skipped, bcadd((string) $t['amount'], '0', 2), 2);
                continue;
            }
            $valid[] = ['invoice' => $inv, 'requested' => bcadd((string) $t['amount'], '0', 2)];
        }
        if ($valid === []) {
            return $firstError ?? ['result' => 'error', 'detail' => 'no FleetForge invoice to allocate'];
        }
        $lead   = $valid[0]['invoice'];
        $amount = bcsub(bcadd($ffAmount, '0', 2), $skipped, 2);

        // b. Split-allocation math (S-FIX-2 Bug #2 pattern), per invoice.
        $allocs   = [];
        $assigned = '0.00';
        foreach ($valid as $v) {
            $bal = (string) $v['invoice']['balance_due'];
            $a   = bccomp($v['requested'], $bal, 6) > 0 ? bcround($bal, 2) : bcround($v['requested'], 2);
            if (bccomp($a, '0', 2) <= 0) {
                continue;
            }
            $allocs[]  = ['invoice' => $v['invoice'], 'amount' => $a];
            $assigned  = bcadd($assigned, $a, 2);
        }
        $overpayment = bcsub($amount, $assigned, 2);
        if (bccomp($overpayment, '0', 2) < 0) {
            $overpayment = '0.00';
        }
        if ($allocs === []) {
            return ['result' => 'invoice_not_payable', 'detail' => 'FF invoice(s) have no balance left to pay'];
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

        // d. Insert payment row. origin carries provenance (D-QBO-13-1);
        //    payment_method now from QBO PaymentMethodRef (was always 'other').
        //    reference_number prefers QBO's PaymentRefNum; falls back to
        //    "QBO-{id}" per D-QBO-13-6.
        $referenceNumber = (string) ($qboPayment['PaymentRefNum'] ?? '');
        if ($referenceNumber === '') {
            $referenceNumber = "QBO-{$qboPaymentId}";
        }
        $method = self::mapPaymentMethod($qboPayment['PaymentMethodRef']['name'] ?? null);
        // S-AUDIT-BILLING-ENGINE-1 #12: USD webhook payments carry the lead
        // invoice's frozen rate (same-currency D18 guarantee) so the
        // CAD-canonical GL (#21) can convert.
        $exchangeRateToCad = $currency === 'USD' ? ($lead['exchange_rate_to_cad'] ?? null) : null;
        $amountInCad = $exchangeRateToCad !== null
            ? bcround(bcmul($amount, (string) $exchangeRateToCad, 6), 2)
            : ($currency === 'CAD' ? bcround($amount, 2) : null);
        $ffPaymentId = db_insert('payments', [
            'payment_number'       => $paymentNumber,
            'customer_id'          => (int) $lead['customer_id'],
            'amount'               => $amount,
            'exchange_rate_to_cad' => $exchangeRateToCad,
            'amount_in_cad'        => $amountInCad,
            'currency'             => $currency,
            'payment_method'       => $method,
            'origin'               => $origin,  // D-QBO-13-1 (webhook, or go-live import = qbo_other)
            'reference_number'     => substr($referenceNumber, 0, 100),
            'check_number'         => $method === 'check' && $referenceNumber !== "QBO-{$qboPaymentId}" ? substr($referenceNumber, 0, 50) : null,
            'payment_date'         => $txnDate,
            'received_at'          => \ff_now_utc(),   // UTC like every DATETIME (S-UTC-STAMPS)
            'status'               => 'cleared',   // money received in QuickBooks
            'overpayment_amount'   => $overpayment,
            'overpayment_action'   => bccomp($overpayment, '0', 2) > 0 ? 'credit_to_account' : null,
            'overpayment_resolved' => 0,
            'notes'                => "QuickBooks payment — qbo_payment_id={$qboPaymentId}",
            'recorded_by'          => null,        // system origin; no FF user
        ]);

        // e + f. Allocation rows + invoice / customer / lease counters.
        foreach ($allocs as $al) {
            $inv = $al['invoice'];
            $allocated = $al['amount'];
            db_insert('payment_allocations', [
                'payment_id'      => $ffPaymentId,
                'invoice_id'      => (int) $inv['id'],
                'amount'          => $allocated,
                'currency'        => $currency,
                'allocation_type' => 'auto',
                'allocated_by'    => null,             // system origin
            ]);
            // S-AUDIT-BILLING-ENGINE-1 #12: recompute from source INCLUDING
            // credits_applied (total − paid resurrected applied credits).
            $newAmountPaid = bcadd((string) $inv['amount_paid'], $allocated, 2);
            $newBalanceDue = bcsub(bcsub((string) $inv['total_amount'], (string) $inv['credits_applied'], 2), $newAmountPaid, 2);
            if (bccomp($newBalanceDue, '0', 2) <= 0) {
                $newStatus = 'paid';
                $newBalanceDue = '0.00';
            } elseif (bccomp($newAmountPaid, '0', 2) > 0) {
                $newStatus = 'partially_paid';
            } else {
                $newStatus = (string) $inv['status'];
            }
            db_execute(
                "UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ?, paid_date = CASE WHEN ? = 'paid' THEN ? ELSE paid_date END WHERE id = ?",
                // paid_date = the day QuickBooks received the money (a go-live
                // import of a March payment is paid in March, not today).
                [$newAmountPaid, $newBalanceDue, $newStatus, $newStatus, $txnDate, (int) $inv['id']]
            );
            // Customer outstanding_balance counter (Trap 6 / D45).
            db_execute(
                "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id = ?",
                [$allocated, (int) $inv['customer_id']]
            );
            // S-AUDIT-BILLING-ENGINE-1 #12: leases.total_paid on the webhook path.
            if (!empty($inv['lease_id'])) {
                db_execute(
                    "UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?",
                    [$allocated, (int) $inv['lease_id']]
                );
            }
        }

        // S-AUDIT-BILLING-ENGINE-1 #12: the excess becomes an account-credit
        // note (create.php's contract). Not pushed to QBO — the QBO payment
        // already carries it as unapplied credit.
        if (bccomp($overpayment, '0', 2) > 0) {
            db_insert('credit_notes', [
                'credit_note_number'   => ff_next_credit_note_number(),
                'customer_id'          => (int) $lead['customer_id'],
                'lease_id'             => $lead['lease_id'] ?: null,
                'source'               => 'overpayment',
                'source_invoice_id'    => (int) $lead['id'],
                'source_payment_id'    => $ffPaymentId,
                'amount'               => $overpayment,
                'exchange_rate_to_cad' => $exchangeRateToCad,
                'currency'             => $currency,
                'amount_remaining'     => $overpayment,
                'status'               => 'active',
                'reason'               => "Overpayment from QuickBooks payment {$paymentNumber} (received {$currency} {$amount}, applied {$currency} {$assigned})",
                'created_by'           => null,
            ]);
            db_execute("UPDATE payments SET overpayment_resolved = 1 WHERE id = ?", [$ffPaymentId]);
        }

        // h. Map row. push_status='pulled_from_qbo' is the terminal state for
        //    QBO-originated payments (D-QBO-13-2) — never pushed back. Upsert:
        //    an Update re-sync re-points the existing row at the new FF payment.
        $mapFields = [
            'ff_payment_id'         => $ffPaymentId,
            'qbo_sync_token'        => (string) ($qboPayment['SyncToken'] ?? '0'),
            'qbo_total_amt'         => bcadd((string) ($qboPayment['TotalAmt'] ?? '0'), '0', 2),
            'qbo_currency'          => $currency,
            'qbo_txn_date'          => $txnDate,
            'qbo_linked_invoice_id' => (string) ($targets[0]['qbo_invoice_id'] ?? ''),
            'origin'                => $origin,
            'webhook_event_id'      => $webhookEventId,
            'realm_id'              => $realmId,
            'push_status'           => 'pulled_from_qbo',
            // S-UTC-STAMPS: map stamps are UTC (like every pusher's $now).
            'pulled_at'             => ff_now_utc(),
            'last_synced_at'        => ff_now_utc(),
        ];
        $mapRow = db_row("SELECT id FROM acc_qbo_payment_map WHERE qbo_payment_id = ?", [$qboPaymentId]);
        if ($mapRow) {
            db_update('acc_qbo_payment_map', $mapFields, 'id = ?', [(int) $mapRow['id']]);
        } else {
            db_insert('acc_qbo_payment_map', ['qbo_payment_id' => $qboPaymentId] + $mapFields);
        }

        // i. FF books: DR Cash / CR AR per allocation; the last allocation
        //    carries the excess as the 3-line overpayment JE (DR Cash full /
        //    CR AR / CR 2060). Wrapped so a JE failure doesn't lose the
        //    payment (operator can re-post; reconciliation catches the gap).
        try {
            $lastIdx = count($allocs) - 1;
            foreach ($allocs as $i => $al) {
                if ($i === $lastIdx && bccomp($overpayment, '0', 2) > 0) {
                    AutoEntryBridge::onOverpaymentReceived($ffPaymentId, (int) $al['invoice']['id'], $al['amount'], $overpayment, null);
                } else {
                    AutoEntryBridge::onPaymentReceived($ffPaymentId, (int) $al['invoice']['id'], $al['amount'], null);
                }
            }
        } catch (\Throwable $e) {
            error_log("[PaymentWebhookHandler] AutoEntryBridge JE post failed for ff_payment={$ffPaymentId}: " . $e->getMessage());
        }

        $numbers = implode(', ', array_map(static fn($al) => (string) $al['invoice']['invoice_number'], $allocs));
        return [
            'result'        => 'payment_created',
            'detail'        => "ff_payment={$ffPaymentId} ({$paymentNumber}); allocated {$assigned} {$currency} to {$numbers}"
                . (bccomp($overpayment, '0', 2) > 0 ? "; {$overpayment} to account credit" : '')
                . ($firstError !== null ? '; skipped: ' . ($firstError['detail'] ?? '') : ''),
            'ff_payment_id' => (int) $ffPaymentId,
            'allocations'   => count($allocs),
        ];
    }
}
