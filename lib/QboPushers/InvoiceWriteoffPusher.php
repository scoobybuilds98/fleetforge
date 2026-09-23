<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceWriteoffPusher.php
 *
 * S-QBO-INVOICE-WRITEOFF — an FF invoice write-off closes the invoice in
 * QuickBooks.
 *
 * An FF write-off (acc_bad_debt_writeoffs, written by InvoiceWriteOff from
 * the AR bad-debt path or a damage-claim write-off) becomes, in QuickBooks:
 *   1. a CreditMemo for the written-off amount on the "Bad debt" item (the
 *      operator maps ff_item_type 'bad_debt' to a QuickBooks service item
 *      whose account is Bad Debt Expense — Intuit's own bad-debt recipe);
 *   2. a $0 Payment applying that CreditMemo to the invoice (two LinkedTxn
 *      lines — the shape CreditApplicationPusher uses), so the invoice
 *      closes and QuickBooks aging agrees with FF.
 * Before: a damage write-off reached QuickBooks only as a JournalEntry
 * crediting A/R (invoice left open); an AR bad-debt write-off not at all.
 * The damage_writeoff JE is now bridge-derived (JournalEntryPusher skips it)
 * so A/R is credited once.
 *
 * Two creates per push. The CreditMemo id is stored the moment it exists, so
 * a failed apply step retries only the apply — never a second memo.
 *
 * Refused before any QuickBooks write (failed_preflight, with the fix):
 * single-currency company + foreign invoice (CurrencyGuard), a write-off
 * dated before go-live (the accountant owns that period), no tax-override
 * code, customer / invoice / Bad-debt item not mapped, DocNumber too long.
 *
 * Echoes: the $0 Payment's webhook is recognised by PaymentWebhookHandler
 * (this map's qbo_payment_id, or ff_writeoff_id in the PrivateNote); the
 * live drift layer counts this map's ids as FF's.
 *
 * @session  S-QBO-INVOICE-WRITEOFF
 * @decision D-QBO-INVOICE-WRITEOFF-1
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class InvoiceWriteoffPusher
{
    /** acc_qbo_item_map.ff_item_type of the QuickBooks "Bad debt" item. */
    public const ITEM_TYPE = 'bad_debt';

    /** Canonical §6.8 5-key return shape spread onto every return path. */
    private const RESULT_BASE = [
        'success'    => false,
        'status'     => 'unknown',
        'outcome'    => 'failed',
        'qbo_id'     => null,
        'sync_token' => null,
    ];

    /**
     * Push a write-off: CreditMemo (if not yet created) + the $0 apply
     * Payment. Idempotent — a fully pushed write-off returns already_mapped.
     *
     * @param int        $ffWriteoffId    acc_bad_debt_writeoffs.id
     * @param array|null $payloadSnapshot unused; signature per D-PUSHER-CONTRACT
     */
    public static function pushCreate(int $ffWriteoffId, ?array $payloadSnapshot = null): array
    {
        // 1. Sync mode gate (per call — a mid-queue flip takes effect).
        $mode = (string) settings_get('quickbooks.sync_mode.invoice_writeoff', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            self::record($ffWriteoffId, ['push_status' => 'skipped_by_mode', 'push_error' => "sync_mode.invoice_writeoff={$mode}"]);
            self::writeSyncLog($ffWriteoffId, 'skipped_by_mode', "sync_mode.invoice_writeoff={$mode}");
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load the write-off + its invoice.
        $w = self::load($ffWriteoffId);
        if ($w === null) {
            return ['success' => false, 'status' => 'ff_not_found', 'outcome' => 'failed', 'error' => "FF write-off {$ffWriteoffId} not found"] + self::RESULT_BASE;
        }

        // 3. Idempotency.
        $map = db_row("SELECT * FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$ffWriteoffId]);
        if ($map !== null && !empty($map['qbo_payment_id'])) {
            return ['success' => true, 'status' => 'already_mapped', 'outcome' => 'created', 'qbo_id' => (string) $map['qbo_credit_memo_id']] + self::RESULT_BASE;
        }

        // 4. Pre-flight.
        $gate = self::runPreflight($w);
        if (!$gate['ok']) {
            self::record($ffWriteoffId, ['push_status' => 'failed_preflight', 'push_error' => $gate['reason']]);
            self::writeSyncLog($ffWriteoffId, 'failed_preflight', (string) $gate['reason']);
            return ['success' => false, 'status' => 'failed_preflight', 'outcome' => 'failed', 'error' => $gate['reason']] + self::RESULT_BASE;
        }

        $client = new QuickBooksClient();

        // 5. The CreditMemo — once. Stored before the apply step runs.
        $qboCreditMemoId = (string) ($map['qbo_credit_memo_id'] ?? '');
        if ($qboCreditMemoId === '') {
            try {
                $resp = $client->createEntity('creditmemo', self::buildCreditMemoPayload($w, $gate['qbo_customer_id'], $gate['qbo_item_id']));
            } catch (QuickBooksException $e) {
                return self::failed($ffWriteoffId, $e->getMessage(), $e->httpStatus ?? 0);
            }
            $qboCreditMemoId = (string) ($resp['CreditMemo']['Id'] ?? '');
            if ($qboCreditMemoId === '') {
                return self::failed($ffWriteoffId, 'QBO response missing CreditMemo.Id', 0);
            }
            self::record($ffWriteoffId, [
                'qbo_credit_memo_id' => $qboCreditMemoId,
                'qbo_invoice_id_ref' => $gate['qbo_invoice_id'],
                'amount_snapshot'    => $w['amount'],
                'push_status'        => 'pending',
                'push_error'         => null,
            ]);
        }

        // 6. Apply it to the invoice ($0 Payment, two LinkedTxn lines).
        try {
            $resp = $client->createEntity('payment', self::buildApplyPayload($w, $gate['qbo_customer_id'], $qboCreditMemoId, $gate['qbo_invoice_id']));
        } catch (QuickBooksException $e) {
            return self::failed($ffWriteoffId, 'Credit memo ' . $qboCreditMemoId . ' created but not yet applied to the invoice: ' . $e->getMessage(), $e->httpStatus ?? 0);
        }
        $qboPaymentId = (string) ($resp['Payment']['Id'] ?? '');
        if ($qboPaymentId === '') {
            return self::failed($ffWriteoffId, 'QBO response missing Payment.Id (credit memo ' . $qboCreditMemoId . ' not applied)', 0);
        }

        $now = ff_now_utc();   // S-UTC-STAMPS
        self::record($ffWriteoffId, [
            'qbo_credit_memo_id' => $qboCreditMemoId,
            'qbo_payment_id'     => $qboPaymentId,
            'qbo_invoice_id_ref' => $gate['qbo_invoice_id'],
            'amount_snapshot'    => $w['amount'],
            'push_status'        => 'pushed',
            'push_error'         => null,
            'pushed_at'          => $now,
        ]);
        return [
            'success'    => true,
            'status'     => 'created',
            'outcome'    => 'created',
            'qbo_id'     => $qboCreditMemoId,
            'sync_token' => (string) ($resp['Payment']['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * The write-off, its invoice and customer — what every step needs.
     *
     * @return array<string,mixed>|null
     */
    private static function load(int $ffWriteoffId): ?array
    {
        return db_row(
            "SELECT w.id, w.invoice_id, w.customer_id, w.damage_claim_id, w.writeoff_date, w.amount, w.reason, w.recovered,
                    i.invoice_number, i.currency, i.exchange_rate_to_cad, dc.claim_number
               FROM acc_bad_debt_writeoffs w
               JOIN invoices i ON i.id = w.invoice_id
          LEFT JOIN damage_claims dc ON dc.id = w.damage_claim_id
              WHERE w.id = ?",
            [$ffWriteoffId]
        );
    }

    /**
     * Pre-flight gates — nothing reaches QuickBooks unless all pass.
     *
     * @return array{ok:bool, reason:?string, qbo_customer_id?:string, qbo_invoice_id?:string, qbo_item_id?:string}
     */
    public static function runPreflight(array $w): array
    {
        $label = "Write-off of invoice {$w['invoice_number']}";
        $fail  = static fn(string $reason) => ['ok' => false, 'reason' => $reason];

        if (!empty($w['recovered'])) {
            return $fail("{$label} was recovered in FleetForge before it reached QuickBooks — nothing to push.");
        }
        $block = CurrencyGuard::blockReason($w['currency'] ?? null, $label);
        if ($block !== null) {
            return $fail($block);
        }
        $block = InvoiceLinker::preGoLiveDateReason($label, (string) $w['writeoff_date']);
        if ($block !== null) {
            return $fail($block);
        }
        if ((string) settings_get('quickbooks.tax_override_code_id', '') === '') {
            return $fail('Tax override code not configured (quickbooks.tax_override_code_id). Pull tax codes on QuickBooks → Tax Codes first.');
        }
        $customer = db_row("SELECT qbo_customer_id FROM acc_qbo_customer_map WHERE ff_customer_id = ? AND mapping_status = 'mapped'", [(int) $w['customer_id']]);
        if (empty($customer['qbo_customer_id'])) {
            return $fail("{$label}: the customer is not linked to QuickBooks — map it on QuickBooks → Customers first.");
        }
        $invoice = db_row("SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ? AND qbo_invoice_id IS NOT NULL", [(int) $w['invoice_id']]);
        if (empty($invoice['qbo_invoice_id'])) {
            return $fail("{$label}: the invoice is not in QuickBooks (not pushed or linked) — there is nothing there to close. Link it on QuickBooks → Invoices, or record the write-off in QuickBooks by hand.");
        }
        $item = db_row(
            "SELECT qbo_item_id FROM acc_qbo_item_map WHERE ff_item_type = ? AND mapping_status = 'mapped' ORDER BY id ASC LIMIT 1",
            [self::ITEM_TYPE]
        );
        if (empty($item['qbo_item_id'])) {
            return $fail("{$label}: no QuickBooks item is mapped for \"Bad Debt Write-off\" — on QuickBooks → Items press Pull (the row appears), then map it or Create it in QuickBooks; its account should be Bad Debt Expense.");
        }
        $doc = self::docNumber($w);
        if (strlen($doc) > QboFieldLimits::INVOICE_DOC_NUMBER_MAX) {
            return $fail("{$label}: credit memo number '{$doc}' is longer than QuickBooks allows.");
        }
        return [
            'ok'              => true,
            'reason'          => null,
            'qbo_customer_id' => (string) $customer['qbo_customer_id'],
            'qbo_invoice_id'  => (string) $invoice['qbo_invoice_id'],
            'qbo_item_id'     => (string) $item['qbo_item_id'],
        ];
    }

    /**
     * Credit memo number: "WO-" + the invoice number (the accountant sees
     * which invoice it closes); "WO-" + the write-off id when that is too
     * long for QuickBooks' 21-character DocNumber.
     */
    public static function docNumber(array $w): string
    {
        $doc = 'WO-' . (string) ($w['invoice_number'] ?? '');
        return strlen($doc) <= QboFieldLimits::INVOICE_DOC_NUMBER_MAX ? $doc : 'WO-' . (int) $w['id'];
    }

    /**
     * The CreditMemo: CreditMemoPusher's payload (single line, tax-override
     * code, currency + Class/Location rules) on the Bad-debt item, dated the
     * write-off day, with this write-off's own PrivateNote.
     */
    public static function buildCreditMemoPayload(array $w, string $qboCustomerId, string $qboItemId): array
    {
        $reason = 'Bad debt write-off — invoice ' . $w['invoice_number']
            . (!empty($w['claim_number']) ? ' (damage claim ' . $w['claim_number'] . ')' : '')
            . (trim((string) ($w['reason'] ?? '')) !== '' ? ': ' . trim((string) $w['reason']) : '');
        $payload = CreditMemoPusher::buildQboPayload([
            'id'                   => (int) $w['id'],
            'customer_id'          => (int) $w['customer_id'],
            'amount'               => (string) $w['amount'],
            'credit_note_number'   => self::docNumber($w),
            'created_at'           => null,
            'reason'               => $reason,
            'source'               => self::ITEM_TYPE,
            'currency'             => (string) ($w['currency'] ?? 'CAD'),
            'exchange_rate_to_cad' => $w['exchange_rate_to_cad'] ?? null,
        ], ['qbo_customer_id' => $qboCustomerId], $qboItemId);
        $payload['TxnDate']     = (string) $w['writeoff_date'];
        $payload['PrivateNote'] = self::privateNote($w);
        return $payload;
    }

    /**
     * The $0 Payment applying the credit memo to the invoice — Intuit's
     * documented shape (CreditApplicationPusher::buildQboPayload).
     */
    public static function buildApplyPayload(array $w, string $qboCustomerId, string $qboCreditMemoId, string $qboInvoiceId): array
    {
        $amount = bcadd((string) $w['amount'], '0', 2);
        $payload = [
            'CustomerRef' => ['value' => $qboCustomerId],
            'TotalAmt'    => 0,
            'TxnDate'     => (string) $w['writeoff_date'],
            'Line'        => [
                ['Amount' => $amount, 'LinkedTxn' => [['TxnId' => $qboInvoiceId, 'TxnType' => 'Invoice']]],
                ['Amount' => $amount, 'LinkedTxn' => [['TxnId' => $qboCreditMemoId, 'TxnType' => 'CreditMemo']]],
            ],
            'PrivateNote' => self::privateNote($w),
        ];
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($w['currency'] ?? 'CAD')) ?: 'CAD';
            $payload['CurrencyRef'] = ['value' => $currency];
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';
            } else {
                // The invoice's frozen rate — never par (phantom FX).
                $rate = (string) ($w['exchange_rate_to_cad'] ?? '');
                if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
                    throw new QuickBooksException("Write-off {$w['id']}: {$currency} invoice {$w['invoice_number']} has no exchange_rate_to_cad; cannot value it in QuickBooks.");
                }
                $payload['ExchangeRate'] = $rate;
            }
        }
        return $payload;
    }

    /** PrivateNote JSON — its ff_writeoff_id is how the webhook recognises FF's own apply Payment. */
    public static function privateNote(array $w): string
    {
        return json_encode([
            'ff_writeoff_id'    => (int) $w['id'],
            'ff_invoice_number' => (string) ($w['invoice_number'] ?? ''),
            'kind'              => 'invoice_writeoff',
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers
    // ────────────────────────────────────────────────────────────────────

    /** Record an HTTP / response failure (the client already logged the request). */
    private static function failed(int $ffWriteoffId, string $error, int $httpCode): array
    {
        self::record($ffWriteoffId, ['push_status' => 'failed', 'push_error' => $error]);
        return ['success' => false, 'status' => 'qbo_error', 'outcome' => 'failed', 'error' => $error, 'http_code' => $httpCode] + self::RESULT_BASE;
    }

    /** Upsert the map row (keyed by write-off) with $fields + last_synced_at. */
    private static function record(int $ffWriteoffId, array $fields): void
    {
        $w = db_row("SELECT invoice_id FROM acc_bad_debt_writeoffs WHERE id = ?", [$ffWriteoffId]);
        if ($w === null) {
            return;   // FK target gone — nothing to record against
        }
        $fields['last_synced_at'] = ff_now_utc();
        $existing = db_row("SELECT id FROM acc_qbo_invoice_writeoff_map WHERE ff_writeoff_id = ?", [$ffWriteoffId]);
        if ($existing) {
            db_update('acc_qbo_invoice_writeoff_map', $fields, 'id = ?', [(int) $existing['id']]);
        } else {
            db_insert('acc_qbo_invoice_writeoff_map', $fields + ['ff_writeoff_id' => $ffWriteoffId, 'ff_invoice_id_snapshot' => (int) $w['invoice_id']]);
        }
    }

    /** Sync-log row for an outcome that made no HTTP call (skip / preflight). */
    private static function writeSyncLog(int $ffWriteoffId, string $status, string $message): void
    {
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'invoice_writeoff',
                'entity_id'       => $ffWriteoffId,
                'operation'       => 'create',
                'http_method'     => 'SKIP',
                'endpoint'        => '',
                'response_status' => null,
                'error_code'      => substr($status, 0, 50),
                'error_message'   => substr($message, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("[InvoiceWriteoffPusher] writeSyncLog failed for write-off {$ffWriteoffId}: " . $e->getMessage());
        }
    }
}
