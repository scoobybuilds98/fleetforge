<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BillPaymentWebhookHandler.php
 *
 * S-QBO-BILLPAY-MIRROR — bills paid in QuickBooks mirror into FleetForge.
 *
 * Operators enter the business's bills in FleetForge (per-unit costing) and
 * FF pushes them to QuickBooks — or links the ones QuickBooks already had at
 * go-live. The accountant then PAYS them in QuickBooks. Before this class,
 * FleetForge never heard about those payments (a BillPayment webhook was
 * dropped; the payment catch-up only looked at invoices), so every bill
 * paid in QuickBooks stayed "approved" in FF and FF's AP / bank GL drifted
 * from the books.
 *
 * This is PaymentWebhookHandler's twin for the AP side. It receives a
 * BillPayment id + operation (from the webhook, or from InvoiceLinker's
 * go-live import / "Check QuickBooks for payments" catch-up), pulls the
 * BillPayment, and mirrors it with the SAME books an operator's AP payment
 * produces (ApPaymentService: DR AP / CR bank, bill amount_paid /
 * balance_due / status):
 *   Create        → one FF AP payment (origin qbo_payments_webhook /
 *                   qbo_other) allocated across every FF bill it pays;
 *   Update        → a QuickBooks-origin copy whose amounts changed is
 *                   re-mirrored (FF copy voided + recreated); an FF-pushed
 *                   payment edited in QuickBooks raises a drift event;
 *   Void / Delete → the FF copy is voided (JE reversed, bills reopened).
 *
 * What FF can't represent is flagged on the Drift page instead of guessed:
 * a vendor credit applied inside QuickBooks, money left unapplied, a
 * payment made from a QuickBooks account that isn't linked to an FF bank
 * account, or a bill FF already shows as paid.
 *
 * Idempotency: acc_qbo_webhook_events.uq_webhook_event_id (receiver) +
 * acc_qbo_bill_payment_map.uq_qbo_bill_payment (this class) +
 * acc_ap_payments.payment_number unique. FF's own pushes are recognised by
 * the map row or by BillPaymentPusher's PrivateNote stamp
 * ("FF ap_payment #APAY-…"), so they are never imported back.
 *
 * Result codes (acc_qbo_webhook_events.processing_result):
 *   wrong_realm, already_mapped, qbo_pull_failed, qbo_bill_payment_not_found,
 *   ff_origin_echo, zero_amount, no_linked_bill, no_ff_bill_mapped,
 *   bank_unmapped, bill_not_payable, currency_mismatch, vendor_mismatch,
 *   error, payment_created, payment_voided, already_voided,
 *   void_not_mirrored, void_blocked, unchanged, payment_resynced,
 *   resync_blocked, drift_recorded.
 *
 * @session  S-QBO-BILLPAY-MIRROR
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §12.4 (webhook handler logic)
 * @decision D-QBO-BILLPAY-MIRROR-1 (bill payments made in QuickBooks are
 *           mirrored into FF, never pushed back — acc_ap_payments.origin)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\ApPaymentService;

class BillPaymentWebhookHandler
{
    /**
     * FF bill statuses a QuickBooks payment can land on. Wider than the
     * Bill Payments page (approved / partially_paid): a bill an operator
     * marked 'scheduled' for payment and the accountant then paid is
     * exactly the case this class exists for.
     */
    public const PAYABLE_BILL_STATUSES = ['approved', 'scheduled', 'partially_paid'];

    /** PrivateNote prefix BillPaymentPusher::buildQboPayload stamps on FF's own pushes. */
    public const FF_NOTE_PREFIX = 'FF ap_payment #';

    /** QuickBooks-owned provenance values (acc_ap_payments.origin). */
    private const QBO_ORIGINS = ['qbo_payments_webhook', 'qbo_other'];

    /**
     * Main entry — webhook receiver, InvoiceLinker import and catch-up.
     *
     * @param string $qboBillPaymentId Intuit BillPayment.Id
     * @param string $operation        'Create' | 'Update' | 'Void' | 'Delete'
     * @param string $realmId          Intuit realm the event belongs to
     * @param string $webhookEventId   webhook event id, or 'cutover-import'
     * @param string $origin           'qbo_payments_webhook' (live webhook) | 'qbo_other' (import / catch-up)
     * @return array{result: string, detail?: string, ff_ap_payment_id?: int}
     */
    public static function handle(string $qboBillPaymentId, string $operation, string $realmId, string $webhookEventId, string $origin = 'qbo_payments_webhook'): array
    {
        if (!in_array($origin, self::QBO_ORIGINS, true)) {
            $origin = 'qbo_payments_webhook';
        }

        // 1. Realm guard — an event for another company is never applied.
        $connectedRealm = (string) settings_get('quickbooks.realm_id', '');
        if ($connectedRealm === '' || $realmId !== $connectedRealm) {
            return ['result' => 'wrong_realm', 'detail' => "received realm='{$realmId}'; connected to '{$connectedRealm}'"];
        }

        // 2. Void / Delete in QuickBooks → void the FF copy.
        if (in_array($operation, ['Void', 'Delete'], true)) {
            return self::handleVoid($qboBillPaymentId, $operation);
        }

        // 3. Already mirrored (or FF's own push)? An Update re-checks it; a
        //    replayed Create is a no-op.
        $existingMap = db_row(
            "SELECT id, ff_ap_payment_id, origin, push_status, qbo_sync_token
               FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = ?",
            [$qboBillPaymentId]
        );
        if ($existingMap) {
            if ($operation === 'Update') {
                return self::handleUpdate($existingMap, $qboBillPaymentId, $webhookEventId, $realmId);
            }
            return [
                'result'           => 'already_mapped',
                'detail'           => "qbo_bill_payment_id={$qboBillPaymentId} → ff_ap_payment_id={$existingMap['ff_ap_payment_id']}",
                'ff_ap_payment_id' => (int) $existingMap['ff_ap_payment_id'],
            ];
        }

        // 4. Pull the BillPayment (the webhook only carries its id).
        $qboBp = self::fetchQboBillPayment($qboBillPaymentId);
        if (isset($qboBp['result'])) {
            return $qboBp;
        }

        // 4b. FF's own push echoing back — possibly before BillPaymentPusher
        //     wrote its map row. The PrivateNote stamp is authoritative.
        if (str_starts_with((string) ($qboBp['PrivateNote'] ?? ''), self::FF_NOTE_PREFIX)) {
            return ['result' => 'ff_origin_echo', 'detail' => "qbo_bill_payment_id={$qboBillPaymentId} was pushed by FleetForge (PrivateNote carries its FF number)"];
        }

        $plan = self::planFromQbo($qboBp);

        // 5. A $0 BillPayment moves no money — in QuickBooks it only applies
        //    a vendor credit to a bill. FF can't see that credit: flag it.
        if (bccomp((string) ($qboBp['TotalAmt'] ?? '0'), '0', 2) === 0) {
            if ($plan['targets'] !== [] || $plan['ff_bill_numbers'] !== []) {
                self::flag(null, $qboBillPaymentId,
                    'A vendor credit was applied to FleetForge bill(s) ' . implode(', ', $plan['ff_bill_numbers'])
                    . ' inside QuickBooks. FleetForge still shows the full balance — apply the matching vendor credit in FleetForge.');
            }
            return ['result' => 'zero_amount', 'detail' => "qbo_bill_payment_id={$qboBillPaymentId} TotalAmt=0 (vendor credit application, no money paid)"];
        }

        // 6. Nothing FF can mirror (no bill, or only bills FF doesn't know —
        //    another business's in a shared file, or never entered in FF).
        if ($plan['targets'] === []) {
            return [
                'result' => $plan['linked_bills'] === 0 ? 'no_linked_bill' : 'no_ff_bill_mapped',
                'detail' => $plan['linked_bills'] === 0
                    ? "QuickBooks bill payment {$qboBillPaymentId} is not applied to any bill"
                    : "QuickBooks bill payment {$qboBillPaymentId} pays only bills FleetForge does not have",
            ];
        }

        // 7. Which FF bank account did the money leave from? Without one the
        //    payment can't be booked — ask for the account link, don't guess.
        $bank = self::resolveBankAccount($qboBp);
        if ($bank === null) {
            $acct = self::qboPayFromAccount($qboBp);
            self::flag(null, $qboBillPaymentId,
                'Bill(s) ' . implode(', ', $plan['ff_bill_numbers']) . " were paid in QuickBooks from account {$acct['name']} (id {$acct['id']}), "
                . 'which is not linked to a FleetForge bank account. Link it on QuickBooks → Accounts (or Bank Accounts), then press '
                . '"Check QuickBooks for payments" so FleetForge records the payment.');
            return ['result' => 'bank_unmapped', 'detail' => "QBO account {$acct['id']} has no FF bank account"];
        }

        try {
            $result = db_transaction(function () use ($qboBp, $plan, $qboBillPaymentId, $webhookEventId, $realmId, $origin, $bank) {
                return self::recordFromQbo($qboBp, $plan['targets'], $qboBillPaymentId, $webhookEventId, $realmId, $origin, $bank);
            });
        } catch (\Throwable $e) {
            error_log("[BillPaymentWebhookHandler] transaction threw for qbo_bill_payment={$qboBillPaymentId}: " . $e->getMessage());
            self::flag(null, $qboBillPaymentId,
                'A bill payment made in QuickBooks for FleetForge bill(s) ' . implode(', ', $plan['ff_bill_numbers'])
                . ' could not be recorded in FleetForge (' . $e->getMessage() . '). Fix the cause, then press "Check QuickBooks for payments".');
            return ['result' => 'error', 'detail' => $e->getMessage()];
        }

        $ffId = isset($result['ff_ap_payment_id']) ? (int) $result['ff_ap_payment_id'] : null;
        foreach (array_merge($plan['warnings'], $result['warnings'] ?? []) as $warning) {
            self::flag($ffId, $qboBillPaymentId, $warning);
        }
        unset($result['warnings']);
        // Nothing recorded (bill already paid / void / other currency or
        // vendor in FF): QuickBooks and FleetForge now disagree about the
        // bill — a person decides which side is right.
        if (($result['result'] ?? '') !== 'payment_created') {
            self::flag(null, $qboBillPaymentId,
                'A bill payment made in QuickBooks for FleetForge bill(s) ' . implode(', ', $plan['ff_bill_numbers'])
                . ' was not recorded in FleetForge: ' . ($result['detail'] ?? $result['result'] ?? '?')
                . '. If FleetForge already has this payment, one of the two is a duplicate; otherwise correct the bill in FleetForge and press "Check QuickBooks for payments".');
        }
        return $result;
    }

    /**
     * GET the BillPayment from QuickBooks. Returns the entity, or a handler
     * result array (has a 'result' key) on failure.
     */
    private static function fetchQboBillPayment(string $qboBillPaymentId): array
    {
        try {
            $resp = (new QuickBooksClient())->getEntity('billpayment', $qboBillPaymentId);
        } catch (\Throwable $e) {
            return ['result' => 'qbo_pull_failed', 'detail' => $e->getMessage()];
        }
        $qboBp = $resp['BillPayment'] ?? null;
        if (!is_array($qboBp) || empty($qboBp['Id'])) {
            return ['result' => 'qbo_bill_payment_not_found', 'detail' => 'getEntity returned no BillPayment'];
        }
        return $qboBp;
    }

    /**
     * Which FF bills a QuickBooks BillPayment pays, and how much CASH lands
     * on each. Pure apart from the bill-map lookups (the smoke drives it).
     *
     * BillPayment shape: TotalAmt = money paid; Line[] each carry an Amount
     * + LinkedTxn (Bill, or VendorCredit applied in the same payment). So
     *   cash applied to bills = Σ bill lines − Σ vendor-credit lines.
     * Bills FF doesn't have take their full line first and are left out;
     * the remaining cash goes to FF bills in line order. A vendor credit or
     * unapplied money can't be represented in FF — warnings say so.
     *
     * @return array{targets: list<array{ff_bill_id:int, qbo_bill_id:string, amount:string}>,
     *               ff_amount: string, linked_bills: int, ff_bill_numbers: list<string>, warnings: list<string>}
     */
    public static function planFromQbo(array $qboBp): array
    {
        $total     = bcadd((string) ($qboBp['TotalAmt'] ?? '0'), '0', 2);
        $billLines = [];      // qbo bill id => amount (line order)
        $creditSum = '0.00';
        foreach ((array) ($qboBp['Line'] ?? []) as $line) {
            $amt = bcadd((string) ($line['Amount'] ?? '0'), '0', 2);
            foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
                $tid  = (string) ($lt['TxnId'] ?? '');
                $type = (string) ($lt['TxnType'] ?? '');
                if ($tid === '') {
                    continue;
                }
                if ($type === 'Bill') {
                    $billLines[$tid] = bcadd($billLines[$tid] ?? '0.00', $amt, 2);
                } elseif ($type === 'VendorCredit') {
                    $creditSum = bcadd($creditSum, $amt, 2);
                }
            }
        }

        $ffLines  = [];
        $nonFfSum = '0.00';
        $billSum  = '0.00';
        $numbers  = [];
        foreach ($billLines as $tid => $amt) {
            $billSum = bcadd($billSum, $amt, 2);
            $m = db_row(
                "SELECT m.ff_bill_id, b.bill_number
                   FROM acc_qbo_bill_map m
                   JOIN acc_bills b ON b.id = m.ff_bill_id
                  WHERE m.qbo_bill_id = ?",
                [(string) $tid]
            );
            if ($m) {
                $ffLines[] = ['ff_bill_id' => (int) $m['ff_bill_id'], 'qbo_bill_id' => (string) $tid, 'amount' => $amt];
                $numbers[] = (string) $m['bill_number'];
            } else {
                $nonFfSum = bcadd($nonFfSum, $amt, 2);
            }
        }

        $cashToBills = bcsub($billSum, $creditSum, 2);
        if (bccomp($cashToBills, '0', 2) < 0) {
            $cashToBills = '0.00';
        }
        $unapplied = bcsub($total, $cashToBills, 2);
        if (bccomp($unapplied, '0', 2) < 0) {
            $unapplied = '0.00';
        }
        $ffCash = bcsub(bcsub($total, $unapplied, 2), $nonFfSum, 2);
        if (bccomp($ffCash, '0', 2) < 0) {
            $ffCash = '0.00';
        }

        $targets = [];
        $placed  = '0.00';
        foreach ($ffLines as $l) {
            $left = bcsub($ffCash, $placed, 2);
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $amt = bccomp($l['amount'], $left, 2) > 0 ? $left : $l['amount'];
            $targets[] = ['ff_bill_id' => $l['ff_bill_id'], 'qbo_bill_id' => $l['qbo_bill_id'], 'amount' => $amt];
            $placed = bcadd($placed, $amt, 2);
        }

        $warnings = [];
        if ($ffLines !== []) {
            if (bccomp($unapplied, '0', 2) > 0) {
                $warnings[] = "Part of this QuickBooks bill payment ({$unapplied}) was not applied to any bill — FleetForge has nowhere to record it, so it was left out.";
            }
            if (bccomp($creditSum, '0', 2) > 0) {
                $warnings[] = 'A vendor credit (' . $creditSum . ') was applied inside QuickBooks as part of this payment to FleetForge bill(s) '
                    . implode(', ', $numbers) . '. FleetForge recorded only the money paid — apply the matching vendor credit in FleetForge.';
            }
        }

        return [
            'targets'         => $targets,
            'ff_amount'       => $placed,
            'linked_bills'    => count($billLines),
            'ff_bill_numbers' => $numbers,
            'warnings'        => $warnings,
        ];
    }

    /**
     * The QuickBooks account the money left from: CheckPayment.BankAccountRef,
     * or CreditCardPayment.CCAccountRef for a card payment.
     *
     * @return array{id:string, name:string}
     */
    private static function qboPayFromAccount(array $qboBp): array
    {
        $ref = ($qboBp['PayType'] ?? '') === 'CreditCard'
            ? ($qboBp['CreditCardPayment']['CCAccountRef'] ?? [])
            : ($qboBp['CheckPayment']['BankAccountRef'] ?? []);
        return ['id' => (string) ($ref['value'] ?? ''), 'name' => (string) ($ref['name'] ?? '?')];
    }

    /**
     * FF bank account for the QuickBooks pay-from account. Tries the direct
     * bank-account mapping (QuickBooks → Bank Accounts) first, then the GL
     * pivot BillPaymentPusher uses in the other direction (FF bank account →
     * its GL account → acc_qbo_account_map).
     *
     * @return array{id:int, gl_account_id:int, name:string}|null
     */
    public static function resolveBankAccount(array $qboBp): ?array
    {
        $qboAccountId = self::qboPayFromAccount($qboBp)['id'];
        if ($qboAccountId === '') {
            return null;
        }
        $row = db_row(
            "SELECT ba.id, ba.gl_account_id, ba.name
               FROM acc_qbo_bank_account_map bm
               JOIN acc_bank_accounts ba ON ba.id = bm.ff_bank_account_id
              WHERE bm.qbo_bank_account_id = ? AND bm.mapping_status = 'mapped' AND ba.is_active = 1",
            [$qboAccountId]
        );
        if ($row === null) {
            $row = db_row(
                "SELECT ba.id, ba.gl_account_id, ba.name
                   FROM acc_qbo_account_map am
                   JOIN acc_bank_accounts ba ON ba.gl_account_id = am.ff_account_id
                  WHERE am.qbo_account_id = ? AND am.mapping_status = 'mapped' AND ba.is_active = 1
                  ORDER BY ba.is_default DESC, ba.id ASC
                  LIMIT 1",
                [$qboAccountId]
            );
        }
        return $row === null ? null : ['id' => (int) $row['id'], 'gl_account_id' => (int) $row['gl_account_id'], 'name' => (string) $row['name']];
    }

    /**
     * Void the FF copy of a bill payment voided / deleted in QuickBooks —
     * the same reversal as the Bill Payments page (ApPaymentService::void).
     */
    private static function handleVoid(string $qboBillPaymentId, string $operation): array
    {
        $map = db_row(
            "SELECT id, ff_ap_payment_id, push_status FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = ?",
            [$qboBillPaymentId]
        );
        if (!$map) {
            return ['result' => 'void_not_mirrored', 'detail' => "QuickBooks bill payment {$qboBillPaymentId} was never mirrored in FleetForge"];
        }
        $pay = db_row("SELECT id, payment_number, status FROM acc_ap_payments WHERE id = ?", [(int) $map['ff_ap_payment_id']]);
        if (!$pay || $pay['status'] === 'void') {
            db_update('acc_qbo_bill_payment_map', ['push_status' => 'voided', 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
            return ['result' => 'already_voided', 'detail' => "FF copy of QuickBooks bill payment {$qboBillPaymentId} is already void"];
        }

        // Map row → 'voided' first: QuickBooks already voided it, so a later
        // push-side void of an FF-pushed payment is an idempotent no-op.
        $previousStatus = (string) $map['push_status'];
        db_update('acc_qbo_bill_payment_map', ['push_status' => 'voided', 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
        try {
            db_transaction(fn() => ApPaymentService::void(
                (int) $pay['id'],
                "Voided in QuickBooks (QBO bill payment {$qboBillPaymentId}, {$operation})",
                null,
                '127.0.0.1',
                'QuickBooks'
            ));
        } catch (\Throwable $e) {
            db_update('acc_qbo_bill_payment_map', ['push_status' => $previousStatus], 'id = ?', [(int) $map['id']]);
            self::flag((int) $pay['id'], $qboBillPaymentId,
                "Bill payment {$pay['payment_number']} was " . strtolower($operation) . 'd in QuickBooks, but FleetForge could not void it automatically ('
                . $e->getMessage() . '). Void it in FleetForge by hand so both systems agree.');
            return ['result' => 'void_blocked', 'detail' => $e->getMessage(), 'ff_ap_payment_id' => (int) $pay['id']];
        }
        return ['result' => 'payment_voided', 'detail' => "FF bill payment {$pay['payment_number']} voided to match QuickBooks", 'ff_ap_payment_id' => (int) $pay['id']];
    }

    /**
     * A mirrored (or FF-pushed) bill payment changed in QuickBooks.
     * An unchanged SyncToken means nothing changed — the go-live import and
     * catch-up call this for every payment on a bill, so that check keeps
     * them from churning. Otherwise: FF-pushed payments are FF's (drift on
     * a money change); QuickBooks-origin copies are re-mirrored.
     */
    private static function handleUpdate(array $map, string $qboBillPaymentId, string $webhookEventId, string $realmId): array
    {
        $ffPay = db_row(
            "SELECT id, payment_number, amount, status, origin FROM acc_ap_payments WHERE id = ?",
            [(int) $map['ff_ap_payment_id']]
        );
        if (!$ffPay || $ffPay['status'] === 'void') {
            return ['result' => 'already_voided', 'detail' => "FF copy of QuickBooks bill payment {$qboBillPaymentId} is void"];
        }
        $qboBp = self::fetchQboBillPayment($qboBillPaymentId);
        if (isset($qboBp['result'])) {
            return $qboBp;
        }
        $syncToken = (string) ($qboBp['SyncToken'] ?? '');
        if ($syncToken !== '' && $syncToken === (string) ($map['qbo_sync_token'] ?? '')) {
            return ['result' => 'unchanged', 'detail' => "QuickBooks bill payment {$qboBillPaymentId} unchanged", 'ff_ap_payment_id' => (int) $ffPay['id']];
        }

        $plan    = self::planFromQbo($qboBp);
        $current = [];
        foreach (db_select("SELECT bill_id, amount_applied FROM acc_ap_payment_allocations WHERE ap_payment_id = ?", [(int) $ffPay['id']]) as $a) {
            $k = (int) $a['bill_id'];
            $current[$k] = bcadd($current[$k] ?? '0.00', bcadd((string) $a['amount_applied'], '0', 2), 2);
        }
        $wanted = [];
        foreach ($plan['targets'] as $t) {
            $k = (int) $t['ff_bill_id'];
            $wanted[$k] = bcadd($wanted[$k] ?? '0.00', bcadd((string) $t['amount'], '0', 2), 2);
        }
        ksort($current);
        ksort($wanted);
        $unchanged = bccomp((string) $ffPay['amount'], $plan['ff_amount'], 2) === 0 && array_keys($current) === array_keys($wanted);
        foreach ($wanted as $k => $amt) {
            if ($unchanged && bccomp($amt, $current[$k] ?? '0.00', 2) !== 0) {
                $unchanged = false;
            }
        }

        if ($unchanged) {
            // A memo / date-only edit: remember the new token so the next
            // import sees it as unchanged.
            db_update('acc_qbo_bill_payment_map', ['qbo_sync_token' => $syncToken, 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
            return ['result' => ($ffPay['origin'] === 'ff_native' ? 'already_mapped' : 'unchanged'),
                    'detail' => "QuickBooks bill payment {$qboBillPaymentId} amounts unchanged", 'ff_ap_payment_id' => (int) $ffPay['id']];
        }

        if ($ffPay['origin'] === 'ff_native') {
            self::flag((int) $ffPay['id'], $qboBillPaymentId,
                "Bill payment {$ffPay['payment_number']} was edited in QuickBooks (QuickBooks now shows {$plan['ff_amount']} on FleetForge bills; FleetForge has {$ffPay['amount']}). "
                . 'FleetForge recorded this payment — change it in FleetForge instead, or void and re-enter it.');
            return ['result' => 'drift_recorded', 'detail' => 'FF-pushed bill payment edited in QuickBooks', 'ff_ap_payment_id' => (int) $ffPay['id']];
        }

        try {
            db_transaction(fn() => ApPaymentService::void(
                (int) $ffPay['id'],
                "Re-synced: QBO bill payment {$qboBillPaymentId} was edited in QuickBooks",
                null,
                '127.0.0.1',
                'QuickBooks'
            ));
        } catch (\Throwable $e) {
            self::flag((int) $ffPay['id'], $qboBillPaymentId,
                "Bill payment {$ffPay['payment_number']} was edited in QuickBooks, but FleetForge could not re-sync it (" . $e->getMessage() . '). Update it in FleetForge by hand.');
            return ['result' => 'resync_blocked', 'detail' => $e->getMessage(), 'ff_ap_payment_id' => (int) $ffPay['id']];
        }
        if ($plan['targets'] === []) {
            db_update('acc_qbo_bill_payment_map', ['push_status' => 'voided', 'qbo_sync_token' => $syncToken, 'last_synced_at' => ff_now_utc()], 'id = ?', [(int) $map['id']]);
            return ['result' => 'payment_voided', 'detail' => 'QuickBooks bill payment no longer pays any FleetForge bill'];
        }
        $bank = self::resolveBankAccount($qboBp);
        if ($bank === null) {
            $acct = self::qboPayFromAccount($qboBp);
            self::flag((int) $ffPay['id'], $qboBillPaymentId,
                "Bill payment {$ffPay['payment_number']} was edited in QuickBooks and now pays from account {$acct['name']} (id {$acct['id']}), which is not linked to a FleetForge bank account. "
                . 'FleetForge voided its copy — link the account, then press "Check QuickBooks for payments".');
            return ['result' => 'bank_unmapped', 'detail' => "QBO account {$acct['id']} has no FF bank account"];
        }
        $origin = (string) $ffPay['origin'];   // a re-sync keeps the copy's provenance
        try {
            $result = db_transaction(fn() => self::recordFromQbo($qboBp, $plan['targets'], $qboBillPaymentId, $webhookEventId, $realmId, $origin, $bank));
        } catch (\Throwable $e) {
            error_log("[BillPaymentWebhookHandler] re-sync create threw for qbo_bill_payment={$qboBillPaymentId}: " . $e->getMessage());
            self::flag((int) $ffPay['id'], $qboBillPaymentId,
                "Bill payment {$ffPay['payment_number']} was edited in QuickBooks; FleetForge voided its copy but could not record the new version ("
                . $e->getMessage() . '). Fix the cause, then press "Check QuickBooks for payments".');
            return ['result' => 'error', 'detail' => $e->getMessage()];
        }
        foreach (array_merge($plan['warnings'], $result['warnings'] ?? []) as $warning) {
            self::flag(isset($result['ff_ap_payment_id']) ? (int) $result['ff_ap_payment_id'] : null, $qboBillPaymentId, $warning);
        }
        unset($result['warnings']);
        $result['result'] = ($result['result'] ?? '') === 'payment_created' ? 'payment_resynced' : ($result['result'] ?? 'error');
        return $result;
    }

    /**
     * Atomic FF AP payment from a QuickBooks BillPayment: payment row, one
     * allocation per FF bill, the AP payment JE, audit row and map row —
     * the same books ap-payments/create.php produces. Caller wraps in
     * db_transaction.
     *
     * A target that can't take money (void / not payable / other currency /
     * other vendor) is skipped; if none survive, the first reason is
     * returned. Money beyond a bill's FF balance is not recorded (FF has no
     * vendor prepayment) — a warning says so unless it is tax-rounding
     * sized (InvoiceLinker::AMOUNT_TOLERANCE).
     *
     * @param list<array{ff_bill_id:int, qbo_bill_id:string, amount:string}> $targets
     * @param array{id:int, gl_account_id:int, name:string} $bank
     * @return array{result:string, detail:string, ff_ap_payment_id?:int, allocations?:int, warnings?:list<string>}
     */
    public static function recordFromQbo(
        array $qboBp,
        array $targets,
        string $qboBillPaymentId,
        string $webhookEventId,
        string $realmId,
        string $origin,
        array $bank
    ): array {
        $currency = strtoupper((string) ($qboBp['CurrencyRef']['value'] ?? 'CAD'));
        $txnDate  = substr((string) ($qboBp['TxnDate'] ?? date('Y-m-d')), 0, 10);

        // a. Lock + validate every target bill (FOR UPDATE — D20).
        $valid = [];
        $firstError = null;
        foreach ($targets as $t) {
            $bill = db_row(
                "SELECT id, bill_number, vendor_id, status, currency, balance_due, exchange_rate_to_cad
                   FROM acc_bills WHERE id = ? FOR UPDATE",
                [(int) $t['ff_bill_id']]
            );
            $err = null;
            if (!$bill) {
                $err = ['result' => 'error', 'detail' => "FF bill id={$t['ff_bill_id']} not found at transaction time"];
            } elseif (!in_array($bill['status'], self::PAYABLE_BILL_STATUSES, true)) {
                $err = ['result' => 'bill_not_payable', 'detail' => "FF bill {$bill['bill_number']} is '{$bill['status']}'"];
            } elseif ($currency !== strtoupper((string) $bill['currency'])) {
                $err = ['result' => 'currency_mismatch', 'detail' => "QuickBooks payment is {$currency}; FF bill {$bill['bill_number']} is {$bill['currency']}"];
            } elseif ($valid !== [] && (int) $bill['vendor_id'] !== (int) $valid[0]['bill']['vendor_id']) {
                $err = ['result' => 'vendor_mismatch', 'detail' => "FF bill {$bill['bill_number']} belongs to a different vendor"];
            }
            if ($err !== null) {
                $firstError ??= $err;
                continue;
            }
            $valid[] = ['bill' => $bill, 'requested' => bcadd((string) $t['amount'], '0', 2)];
        }
        if ($valid === []) {
            return $firstError ?? ['result' => 'error', 'detail' => 'no FleetForge bill to allocate'];
        }
        $lead = $valid[0]['bill'];

        // b. Allocation per bill, capped at its FF balance.
        $allocs   = [];
        $assigned = '0.00';
        $warnings = [];
        foreach ($valid as $v) {
            $bal = bcadd((string) $v['bill']['balance_due'], '0', 2);
            $a   = bccomp($v['requested'], $bal, 2) > 0 ? $bal : $v['requested'];
            $over = bcsub($v['requested'], $a, 2);
            if (bccomp($over, InvoiceLinker::AMOUNT_TOLERANCE, 2) > 0) {
                $warnings[] = "QuickBooks paid {$v['requested']} on bill {$v['bill']['bill_number']}, but FleetForge only had {$bal} left on it. "
                    . "FleetForge recorded {$a}; check the bill amount in FleetForge against QuickBooks.";
            }
            if (bccomp($a, '0', 2) <= 0) {
                continue;
            }
            $allocs[] = ['bill' => $v['bill'], 'amount' => $a];
            $assigned = bcadd($assigned, $a, 2);
        }
        if ($allocs === []) {
            return ['result' => 'bill_not_payable', 'detail' => 'FF bill(s) have no balance left to pay'];
        }

        // c. Payment row + the AP payment JE (DR AP / CR bank) — shared with
        //    the Bill Payments page so both produce identical books.
        $vendor = db_row("SELECT id, name FROM vendors WHERE id = ?", [(int) $lead['vendor_id']]);
        $paymentNumber = AccountingService::nextApPaymentNumber(substr($txnDate, 0, 4));
        $je = ApPaymentService::postPaymentJournalEntry(
            $paymentNumber, $txnDate, (int) $lead['vendor_id'], (string) ($vendor['name'] ?? ''),
            (int) $bank['gl_account_id'], $assigned, null
        );

        $docNumber = trim((string) ($qboBp['DocNumber'] ?? ''));
        $isCard    = ($qboBp['PayType'] ?? '') === 'CreditCard';
        // QuickBooks' "Check" pay type covers any bank-account payment; only
        // one with a cheque number is recorded as a cheque.
        $method = $isCard ? 'credit_card' : ($docNumber !== '' ? 'check' : 'other');
        $exchangeRateToCad = $currency === 'CAD' ? null : ($lead['exchange_rate_to_cad'] ?? null);

        $apPaymentId = db_insert('acc_ap_payments', [
            'payment_number'       => $paymentNumber,
            'vendor_id'            => (int) $lead['vendor_id'],
            'bank_account_id'      => (int) $bank['id'],
            'payment_date'         => $txnDate,
            'payment_method'       => $method,
            'reference_number'     => substr($docNumber !== '' ? $docNumber : "QBO-{$qboBillPaymentId}", 0, 100),
            'check_number'         => $method === 'check' ? substr($docNumber, 0, 50) : null,
            'amount'               => $assigned,
            'currency'             => $currency,
            'exchange_rate_to_cad' => $exchangeRateToCad,
            'status'               => 'cleared',       // money already left the account in QuickBooks
            'origin'               => $origin,          // D-QBO-BILLPAY-MIRROR-1 — never pushed back
            'journal_entry_id'     => (int) $je['id'],
            'notes'                => "QuickBooks bill payment — qbo_bill_payment_id={$qboBillPaymentId}",
            'created_by'           => null,             // system origin; no FF user
        ]);

        foreach ($allocs as $al) {
            ApPaymentService::applyToBill($apPaymentId, (int) $al['bill']['id'], $al['amount']);
        }

        $numbers = implode(', ', array_map(static fn($al) => (string) $al['bill']['bill_number'], $allocs));
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'QuickBooks',
            'action'      => 'create',
            'module'      => 'accounting',
            'entity_type' => 'ap_payment',
            'entity_id'   => $apPaymentId,
            'notes'       => "AP Payment {$paymentNumber} — \${$assigned} to " . ($vendor['name'] ?? '?') . " mirrored from QuickBooks bill payment {$qboBillPaymentId} ({$numbers})",
            'ip_address'  => '127.0.0.1',
        ]);

        // d. Map row. pulled_from_qbo is terminal — never pushed. Upsert: an
        //    Update re-sync re-points the existing row at the new FF payment.
        $payFrom = self::qboPayFromAccount($qboBp);
        $mapFields = [
            'ff_ap_payment_id'          => $apPaymentId,
            'qbo_sync_token'            => (string) ($qboBp['SyncToken'] ?? '0'),
            'qbo_vendor_id'             => (string) ($qboBp['VendorRef']['value'] ?? '') ?: null,
            'qbo_bank_account_id'       => $payFrom['id'] !== '' ? $payFrom['id'] : null,
            'qbo_pay_type'              => substr((string) ($qboBp['PayType'] ?? ''), 0, 20) ?: null,
            'qbo_total_amt'             => bcadd((string) ($qboBp['TotalAmt'] ?? '0'), '0', 2),
            'qbo_currency'              => $currency,
            'qbo_exchange_rate'         => isset($qboBp['ExchangeRate']) ? (string) $qboBp['ExchangeRate'] : null,
            'qbo_txn_date'              => $txnDate,
            'qbo_doc_number'            => $docNumber !== '' ? substr($docNumber, 0, 100) : null,
            'ff_payment_snapshot_total' => $assigned,
            'origin'                    => $origin,
            'webhook_event_id'          => $webhookEventId,
            'realm_id'                  => $realmId,
            'push_status'               => 'pulled_from_qbo',
            'push_error'                => null,
            'pulled_at'                 => ff_now_utc(),   // S-UTC-STAMPS
            'last_synced_at'            => ff_now_utc(),
        ];
        $mapRow = db_row("SELECT id FROM acc_qbo_bill_payment_map WHERE qbo_bill_payment_id = ?", [$qboBillPaymentId]);
        if ($mapRow) {
            db_update('acc_qbo_bill_payment_map', $mapFields, 'id = ?', [(int) $mapRow['id']]);
        } else {
            db_insert('acc_qbo_bill_payment_map', ['qbo_bill_payment_id' => $qboBillPaymentId] + $mapFields);
        }

        return [
            'result'           => 'payment_created',
            'detail'           => "ff_ap_payment={$apPaymentId} ({$paymentNumber}); paid {$assigned} {$currency} on {$numbers} from {$bank['name']}"
                . ($firstError !== null ? '; skipped: ' . ($firstError['detail'] ?? '') : ''),
            'ff_ap_payment_id' => (int) $apPaymentId,
            'allocations'      => count($allocs),
            'warnings'         => $firstError !== null
                ? array_merge($warnings, ['Part of QuickBooks bill payment ' . $qboBillPaymentId . ' was not recorded in FleetForge: ' . ($firstError['detail'] ?? '')])
                : $warnings,
        ];
    }

    /**
     * Drift event for something FF can't apply, at most once while open:
     * the go-live import and catch-up re-run the same payments, and an
     * unlinked bank account would otherwise raise the same event each time.
     */
    private static function flag(?int $ffApPaymentId, string $qboBillPaymentId, string $description): void
    {
        $description = substr($description, 0, 1000);
        $open = db_row(
            "SELECT id FROM acc_qbo_drift_events
              WHERE entity_type = 'bill_payment' AND qbo_entity_id = ? AND description = ? AND resolved_at IS NULL
              LIMIT 1",
            [$qboBillPaymentId, $description]
        );
        if ($open) {
            return;
        }
        PaymentWebhookHandler::recordQboSideChange('bill_payment', $ffApPaymentId, $qboBillPaymentId, $description);
    }
}
