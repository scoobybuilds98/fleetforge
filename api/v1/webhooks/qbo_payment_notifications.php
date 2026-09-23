<?php
declare(strict_types=1);

/**
 * api/v1/webhooks/qbo_payment_notifications.php
 *
 * Phase QBO-6 / 1 of 3 (S-QBO-13) — QBO Payments webhook receiver.
 *
 * Public endpoint — auth via HMAC-SHA256 signature only (no session,
 * no permission gate). Receives Intuit-hosted Payment.Create /
 * Payment.Update / Payment.Void events. Mirrors S-PROD-2 SES webhook
 * pattern (api/v1/webhooks/ses_notifications.php).
 *
 * Flow:
 *   1. POST-only; reject other methods with 405
 *   2. Read raw body
 *   3. Verify intuit-signature header via HMAC-SHA256
 *      (D-QBO-13-3 — fail-closed on missing verifier_token, return 403)
 *   4. Parse JSON payload
 *   5. Extract webhook_event_id from header (or synthesize)
 *   6. Idempotency check on acc_qbo_webhook_events.webhook_event_id
 *      — replays return 200 immediately without re-processing
 *   7. INSERT acc_qbo_webhook_events row
 *   8. Return 200 to Intuit IMMEDIATELY (don't make Intuit wait on
 *      our processing — they have aggressive retry policies)
 *   9. fastcgi_finish_request() to release the HTTP connection
 *  10. Process each event via PaymentWebhookHandler::handle() (Payment),
 *      BillPaymentWebhookHandler::handle() (BillPayment — bills paid in
 *      QuickBooks, S-QBO-BILLPAY-MIRROR) or DocumentWebhookHandler
 *      (Invoice / CreditMemo edits made in QuickBooks)
 *  11. UPDATE acc_qbo_webhook_events.processed_at + processing_result
 *
 * Always returns:
 *   - 200 on signature valid + payload accepted (regardless of
 *     processing outcome — Intuit retries on non-200 which would
 *     create a thundering-herd; the webhook event row + map row
 *     hold the operator-visible state for ops review)
 *   - 403 on signature failure
 *   - 405 on non-POST
 *   - 400 on malformed body
 *
 * @method POST
 * @auth   HMAC-SHA256 signature verification (intuit-signature header)
 *
 * @session  S-QBO-13
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §12.4 (handler logic)
 * @decision D-QBO-13-3 (signature fail-closed),
 *           D-QBO-13-4 (atomic transactional payment creation)
 */

require_once dirname(__DIR__, 3) . '/config/app.php';

use FleetForge\QboPushers\QboWebhookSignature;
use FleetForge\QboPushers\PaymentWebhookHandler;

// ── 1. POST-only ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── 2. Raw body ───────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    exit;
}

// ── 3. Signature verification ─────────────────────────────────────
$verifierToken = \FleetForge\QuickBooksClient::secret('webhook_verifier_token'); // S-QBO-GOLIVE-AUDIT: encrypted at rest
$receivedSig   = $_SERVER['HTTP_INTUIT_SIGNATURE'] ?? '';

if (!QboWebhookSignature::verify($rawBody, $verifierToken, (string) $receivedSig)) {
    error_log('[qbo_payment_webhook] Signature verification failed; rejecting with 403');
    http_response_code(403);
    exit;
}

// ── 4. Parse payload ──────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// ── 4b. Normalize both Intuit envelopes (S-QBO-GOLIVE-AUDIT) ──────
// Intuit retired the legacy {eventNotifications:[…]} envelope in 2026
// (US cutover June 30 → July 31, 2026). Deliveries now arrive as a JSON
// ARRAY of CloudEvents: {id, type:"qbo.payment.created.v1",
// intuitaccountid:<realm>, intuitentityid:<entity id>, …}. This endpoint
// only understood the legacy shape, so every current delivery parsed as
// "no payment event" and QBO-side payments never reached FF. Both shapes
// reduce to the same list; the handler below is unchanged.
$events = PaymentWebhookHandler::normalizeEvents($payload);

// ── 5. Webhook event id ───────────────────────────────────────────
// Legacy deliveries carry intuit-webhook-event-id. CloudEvents carry a
// per-event `id`; a re-delivery repeats the same ids, so their hash is a
// stable delivery key. Otherwise (rare; defensive) synthesize a random id
// so idempotency still works at the event level.
$webhookEventId = (string) ($_SERVER['HTTP_INTUIT_WEBHOOK_EVENT_ID'] ?? '');
if ($webhookEventId === '') {
    $ceIds = array_values(array_filter(array_column($events, 'event_id')));
    $webhookEventId = $ceIds !== []
        ? 'ce-' . hash('sha256', implode('|', $ceIds))
        : 'syn-' . bin2hex(random_bytes(16));
}

// ── 6. Idempotency check on webhook event (outcome-aware) ─────────
// MEDIUM [01e]: a prior delivery that FAILED (processing_result='error') must be
// reprocessable — Intuit re-delivers failed webhooks, and the old "any existing
// row → 200 duplicate" short-circuit dropped the retry, so a payment recorded in
// QBO stayed unpaid in FF forever. Skip only when the prior attempt SUCCEEDED
// (or is still in-flight: processing_result NULL → avoid double-processing a
// concurrent delivery). Reprocess only confirmed 'error' rows.
$existingEvent = db_row(
    "SELECT id, processing_result FROM acc_qbo_webhook_events WHERE webhook_event_id = ?",
    [$webhookEventId]
);
$reprocessEventId = null;
if ($existingEvent !== null) {
    if (($existingEvent['processing_result'] ?? null) === 'error') {
        // Retry of a previously-errored event — fall through and reprocess,
        // reusing the existing row (step 11 overwrites its outcome).
        $reprocessEventId = (int) $existingEvent['id'];
    } else {
        http_response_code(200);
        echo json_encode(['status' => 'duplicate', 'webhook_event_id' => $webhookEventId]);
        exit;
    }
}

// ── 7. Insert webhook event row ───────────────────────────────────
$realmId   = (string) ($events[0]['realm_id'] ?? '');
$eventType = (string) ($events[0]['name'] ?? 'unknown');

try {
    // Reprocessing a prior 'error' event reuses its row — only INSERT for a
    // genuinely new event (else the UNIQUE webhook_event_id would 1062).
    if ($reprocessEventId === null) {
        db_insert('acc_qbo_webhook_events', [
            'webhook_event_id'   => $webhookEventId,
            'event_type'         => $eventType,
            'realm_id'           => $realmId !== '' ? $realmId : null,
            'payload'            => $rawBody,
            'signature_verified' => 1,
        ]);
    }
} catch (\Throwable $e) {
    // Most likely cause: UNIQUE violation on webhook_event_id (race
    // between the SELECT above and this INSERT — another concurrent
    // delivery of the same event won the race). Return 200 — the
    // other handler will process it.
    error_log('[qbo_payment_webhook] event INSERT failed (likely concurrent duplicate): ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'duplicate_race', 'webhook_event_id' => $webhookEventId]);
    exit;
}

// ── 8. Return 200 IMMEDIATELY ─────────────────────────────────────
http_response_code(200);
echo json_encode(['status' => 'received', 'webhook_event_id' => $webhookEventId]);

// ── 9. Release HTTP connection so Intuit doesn't wait on processing ─
//      Only works under FPM; gracefully no-op under CLI/built-in server.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ── 10. Process each Payment event ────────────────────────────────
$lastResult = null;
$lastError  = null;
foreach ($events as $ev) {
    if ($ev['entity_id'] === '') {
        continue;
    }
    // S-QBO-GOLIVE-AUDIT: Invoice / CreditMemo events for documents FF
    // pushed — a void / delete / edit made in QuickBooks becomes a drift
    // event instead of an invisible disagreement. Other entities are ignored.
    // S-QBO-BILLPAY-MIRROR: BillPayment events (a bill the accountant paid
    // in QuickBooks) mirror into FF's AP. Intuit names the entity
    // "BillPayment" (legacy envelope) / "Billpayment" (CloudEvents type
    // qbo.billpayment.*) — compared case-insensitively.
    $isPayment     = strcasecmp($ev['name'], 'Payment') === 0;
    $isBillPayment = strcasecmp($ev['name'], 'BillPayment') === 0;
    if (!$isPayment && !$isBillPayment && !\FleetForge\QboPushers\DocumentWebhookHandler::handles($ev['name'])) {
        continue;
    }

    try {
        if ($isPayment) {
            $res = PaymentWebhookHandler::handle($ev['entity_id'], $ev['operation'], $ev['realm_id'], $webhookEventId);
        } elseif ($isBillPayment) {
            $res = \FleetForge\QboPushers\BillPaymentWebhookHandler::handle($ev['entity_id'], $ev['operation'], $ev['realm_id'], $webhookEventId);
        } else {
            $res = \FleetForge\QboPushers\DocumentWebhookHandler::handle($ev['name'], $ev['entity_id'], $ev['operation'], $ev['realm_id']);
        }
        $lastResult = (string) ($res['result'] ?? 'unknown');
    } catch (\Throwable $e) {
        $lastResult = 'error';
        $lastError  = $e->getMessage();
        error_log("[qbo_payment_webhook] {$ev['name']} handler threw: " . $e->getMessage());
    }
}

// ── 11. UPDATE webhook event row with outcome ─────────────────────
try {
    db_execute(
        "UPDATE acc_qbo_webhook_events
            SET processed_at = NOW(),
                processing_result = ?,
                error_message = ?
          WHERE webhook_event_id = ?",
        [$lastResult ?? 'no_payment_event', $lastError, $webhookEventId]
    );
} catch (\Throwable $e) {
    error_log('[qbo_payment_webhook] failed to update event status: ' . $e->getMessage());
}
