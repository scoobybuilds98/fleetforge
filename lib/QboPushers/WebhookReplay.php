<?php
declare(strict_types=1);

/**
 * lib/QboPushers/WebhookReplay.php
 *
 * The QuickBooks "webhook replay" (SOP known issue I25 — referred to in
 * cron/qbo_bank_cdc.php, seeded as quickbooks.webhook.replay_window_hours,
 * but never built).
 *
 * The webhook endpoint (api/v1/webhooks/qbo_payment_notifications.php)
 * stores every delivery in acc_qbo_webhook_events, answers Intuit, then
 * processes the events. Two kinds of row were stranded for good:
 *   - processing_result = 'error' — only retried if Intuit happened to
 *     deliver the same event again;
 *   - processed_at IS NULL — the PHP process died after answering Intuit
 *     (deploy, restart, timeout) and never processed it.
 * PaymentBackstop already re-reads Payments from QuickBooks' change feed,
 * but BillPayment and the Invoice / CreditMemo drift events had no catch-up.
 *
 * replayDue() — run from cron/qbo_sync_worker.php at most every 15 minutes —
 * re-dispatches those rows from their stored payload through the same
 * handlers (all idempotent: they look up their map rows first), within the
 * replay window (quickbooks.webhook.replay_window_hours, default 24). A row
 * older than the window is left for a person (QuickBooks → Drift / logs).
 *
 * dispatch() is the ONE event loop, shared with the webhook endpoint.
 *
 * @session S-SOP-KNOWN-ISSUES
 */

namespace FleetForge\QboPushers;

final class WebhookReplay
{
    private const LAST_RUN_KEY     = 'webhook.replay_last_run';
    private const INTERVAL_MINUTES = 15;
    /** A never-finished row younger than this may still be in flight. */
    private const STALE_MINUTES    = 15;
    private const MAX_ROWS         = 50;

    /**
     * Process a delivery's events through the right handlers.
     *
     * @param array<int,array{name:string,entity_id:string,operation:string,realm_id:string}> $events
     * @return array{result:?string, error:?string}  outcome of the last handled event
     */
    public static function dispatch(array $events, string $webhookEventId): array
    {
        $lastResult = null;
        $lastError  = null;
        foreach ($events as $ev) {
            if (($ev['entity_id'] ?? '') === '') {
                continue;
            }
            // Payment → mirror the payment; BillPayment → mirror into AP
            // (Intuit spells it BillPayment / Billpayment — case-insensitive);
            // Invoice / CreditMemo → drift events. Other entities are ignored.
            $isPayment     = strcasecmp($ev['name'], 'Payment') === 0;
            $isBillPayment = strcasecmp($ev['name'], 'BillPayment') === 0;
            if (!$isPayment && !$isBillPayment && !DocumentWebhookHandler::handles($ev['name'])) {
                continue;
            }
            try {
                if ($isPayment) {
                    $res = PaymentWebhookHandler::handle($ev['entity_id'], $ev['operation'], $ev['realm_id'], $webhookEventId);
                } elseif ($isBillPayment) {
                    $res = BillPaymentWebhookHandler::handle($ev['entity_id'], $ev['operation'], $ev['realm_id'], $webhookEventId);
                } else {
                    $res = DocumentWebhookHandler::handle($ev['name'], $ev['entity_id'], $ev['operation'], $ev['realm_id']);
                }
                $lastResult = (string) ($res['result'] ?? 'unknown');
            } catch (\Throwable $e) {
                $lastResult = 'error';
                $lastError  = $e->getMessage();
                error_log("[qbo_webhook] {$ev['name']} handler threw: " . $e->getMessage());
            }
        }
        return ['result' => $lastResult, 'error' => $lastError];
    }

    /**
     * Replay stranded webhook rows if the interval has passed.
     *
     * @return array{replayed:int, recovered:int, still_failing:int}|null  null = not due
     */
    public static function replayDue(): ?array
    {
        $last = (string) settings_get('quickbooks.' . self::LAST_RUN_KEY, '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - self::INTERVAL_MINUTES * 60) {
            return null;
        }
        \FleetForge\QuickBooksClient::settings_write_qbo(self::LAST_RUN_KEY, \ff_now_utc());
        return self::replay();
    }

    /**
     * Replay every stranded row inside the window (no interval check).
     *
     * @return array{replayed:int, recovered:int, still_failing:int}
     */
    public static function replay(): array
    {
        $windowHours = max(1, (int) settings_get('quickbooks.webhook.replay_window_hours', '24'));
        $rows = \db_select(
            "SELECT id, webhook_event_id, payload
               FROM acc_qbo_webhook_events
              WHERE signature_verified = 1
                AND received_at >= ?
                AND (processing_result = 'error'
                     OR (processed_at IS NULL AND received_at < ?))
              ORDER BY received_at ASC
              LIMIT " . self::MAX_ROWS,
            [\ff_now_utc("-{$windowHours} hours"), \ff_now_utc('-' . self::STALE_MINUTES . ' minutes')]
        );

        $out = ['replayed' => 0, 'recovered' => 0, 'still_failing' => 0];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                \db_execute(
                    "UPDATE acc_qbo_webhook_events SET processed_at = ?, processing_result = 'error', error_message = ? WHERE id = ?",
                    [\ff_now_utc(), 'Replay: stored payload is not valid JSON.', (int) $row['id']]
                );
                continue;
            }
            $out['replayed']++;
            $res = self::dispatch(PaymentWebhookHandler::normalizeEvents($payload), (string) $row['webhook_event_id']);
            $result = $res['result'] ?? 'no_payment_event';
            if ($result === 'error') {
                $out['still_failing']++;
            } else {
                $out['recovered']++;
            }
            \db_execute(
                "UPDATE acc_qbo_webhook_events
                    SET processed_at = ?, processing_result = ?, error_message = ?
                  WHERE id = ?",
                [\ff_now_utc(), $result, $res['error'] !== null ? 'Replay: ' . $res['error'] : null, (int) $row['id']]
            );
        }
        return $out;
    }
}
