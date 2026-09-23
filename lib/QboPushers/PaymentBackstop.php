<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PaymentBackstop.php
 *
 * S-QBO-INVOICE-PAYNOW — customer payments made in QuickBooks reach
 * FleetForge even when QuickBooks' webhook never arrives.
 *
 * A customer who presses "Pay now" pays on QuickBooks' page; QuickBooks
 * records the Payment against the invoice and notifies FleetForge through
 * the Payment webhook (PaymentWebhookHandler), which marks the FF invoice
 * paid and posts FF's own entry. Webhooks can be late, dropped, or not
 * subscribed yet — until now the only fallback was a person pressing
 * "Check QuickBooks for payments".
 *
 * Every 10 minutes (cron/qbo_sync_worker.php, which runs each minute) this
 * asks QuickBooks' change feed (CDC) for Payments changed since the last
 * look — ONE API call — and hands each relevant one to the same
 * PaymentWebhookHandler the webhook uses. The handler is idempotent
 * (already_mapped / unchanged), so a payment the webhook already brought in
 * is a no-op here, and vice versa.
 *
 * Relevant = touches a FleetForge invoice (LinkedTxn → acc_qbo_invoice_map)
 * or is a payment FleetForge already mirrors (edits / voids / deletes).
 * Payments on the other businesses' invoices in the shared company file are
 * never looked at twice.
 *
 * Window: from the last successful look (with a 5-minute overlap for
 * clock skew / QuickBooks' own lag) — never further back than 29 days, the
 * CDC limit. First run looks back 1 hour; older history is the go-live
 * linker's job.
 *
 * @session  S-QBO-INVOICE-PAYNOW
 * @decision D-QBO-INVOICE-PAYNOW-3
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

final class PaymentBackstop
{
    /** Minutes between looks. */
    public const INTERVAL_MINUTES = 10;

    private const SINCE_KEY   = 'quickbooks.payments.backstop_since';
    private const LAST_RUN_KEY = 'quickbooks.payments.backstop_last_run';

    /**
     * Run when the interval has passed; null when it is not due yet.
     *
     * @return array{checked:int, relevant:int, results:array<string,int>, since:string, until:string}|null
     */
    public static function runIfDue(?QuickBooksClient $client = null): ?array
    {
        $last = (string) settings_get(self::LAST_RUN_KEY, '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - self::INTERVAL_MINUTES * 60) {
            return null;
        }
        return self::run($client);
    }

    /**
     * One look at QuickBooks' Payment changes.
     *
     * @return array{checked:int, relevant:int, results:array<string,int>, since:string, until:string}
     * @throws \Throwable when QuickBooks can't be reached — the window is NOT advanced, so the next look covers it
     */
    public static function run(?QuickBooksClient $client = null): array
    {
        $client ??= new QuickBooksClient();
        $realm = (string) settings_get('quickbooks.realm_id', '');
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        QuickBooksClient::settings_write_qbo('payments.backstop_last_run', $now->format('Y-m-d H:i:s'));

        $sinceRaw = (string) settings_get(self::SINCE_KEY, '');
        $since    = $sinceRaw !== '' ? new \DateTimeImmutable($sinceRaw) : $now->modify('-1 hour');
        $floor    = $now->modify('-29 days');
        if ($since < $floor) {
            $since = $floor;
        }
        $since = $since->modify('-5 minutes');   // overlap: QuickBooks' own lag + clock skew

        $resp = $client->get('cdc', [
            'entities'     => 'Payment',
            'changedSince' => $since->format('Y-m-d\TH:i:sP'),
        ], ['entity_type' => 'payment', 'operation' => 'payment_backstop']);

        $out = ['checked' => 0, 'relevant' => 0, 'results' => [], 'since' => $since->format('c'), 'until' => $now->format('c')];
        foreach (self::paymentsFrom($resp) as $p) {
            $out['checked']++;
            $op = self::operationFor($p);
            if ($op === null) {
                continue;
            }
            $out['relevant']++;
            $pid = (string) $p['Id'];
            try {
                $r = PaymentWebhookHandler::handle($pid, $op, $realm, 'backstop-' . $pid . '-' . ($p['SyncToken'] ?? 'x'), 'qbo_other');
                $key = (string) ($r['result'] ?? 'unknown');
            } catch (\Throwable $e) {
                error_log("[PaymentBackstop] QuickBooks payment {$pid}: " . $e->getMessage());
                $key = 'error';
            }
            $out['results'][$key] = ($out['results'][$key] ?? 0) + 1;
        }

        // Advance the window only after a successful look.
        QuickBooksClient::settings_write_qbo('payments.backstop_since', $now->format('c'));
        return $out;
    }

    /**
     * Payment objects from a CDC response (QuickBooks nests them
     * CDCResponse[].QueryResponse[].Payment[]).
     *
     * @return list<array<string,mixed>>
     */
    public static function paymentsFrom(array $resp): array
    {
        $out = [];
        foreach ((array) ($resp['CDCResponse'] ?? []) as $cdc) {
            foreach ((array) ($cdc['QueryResponse'] ?? []) as $qr) {
                foreach ((array) ($qr['Payment'] ?? []) as $p) {
                    if (is_array($p) && !empty($p['Id'])) {
                        $out[] = $p;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * What to tell the handler about this payment, or null when it isn't
     * FleetForge's business:
     *   deleted in QuickBooks                → Delete
     *   voided (QuickBooks zeroes it, notes "Voided") → Void
     *   already mirrored, SyncToken changed  → Update
     *   already mirrored, same SyncToken     → null (nothing new)
     *   new, pays a FleetForge invoice       → Create
     *   new, pays someone else's invoice     → null
     */
    public static function operationFor(array $p): ?string
    {
        $id  = (string) $p['Id'];
        $map = db_row("SELECT qbo_sync_token FROM acc_qbo_payment_map WHERE qbo_payment_id = ?", [$id]);

        if (($p['status'] ?? '') === 'Deleted') {
            return $map ? 'Delete' : null;
        }
        if ($map) {
            if (self::isVoided($p)) {
                return 'Void';
            }
            return (string) ($map['qbo_sync_token'] ?? '') === (string) ($p['SyncToken'] ?? '') ? null : 'Update';
        }
        if (self::isVoided($p)) {
            return null;   // never mirrored, now void — nothing to bring in
        }
        foreach (self::linkedInvoiceIds($p) as $qboInvoiceId) {
            if (db_row("SELECT 1 FROM acc_qbo_invoice_map WHERE qbo_invoice_id = ?", [$qboInvoiceId])) {
                return 'Create';
            }
        }
        return null;
    }

    /** QuickBooks keeps a voided payment with a zero total and "Voided" in its note. */
    private static function isVoided(array $p): bool
    {
        return isset($p['TotalAmt']) && bccomp((string) $p['TotalAmt'], '0', 2) === 0
            && stripos((string) ($p['PrivateNote'] ?? ''), 'voided') !== false;
    }

    /** @return list<string> QuickBooks invoice ids this payment is applied to */
    private static function linkedInvoiceIds(array $p): array
    {
        $ids = [];
        foreach ((array) ($p['Line'] ?? []) as $line) {
            foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
                if (($lt['TxnType'] ?? '') === 'Invoice' && !empty($lt['TxnId'])) {
                    $ids[] = (string) $lt['TxnId'];
                }
            }
        }
        return array_values(array_unique($ids));
    }
}
