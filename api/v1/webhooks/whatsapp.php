<?php
declare(strict_types=1);

/**
 * api/v1/webhooks/whatsapp.php
 *
 * Meta WhatsApp Cloud API webhook (S-ATTENTION-WHATSAPP): delivery status
 * for the messages FleetForge sends (sent → delivered → read, or failed with
 * Meta's reason), shown in Settings → Notifications → Delivery log.
 *
 * Public endpoint — Meta calls it, not a signed-in user. Two checks:
 *   GET  (one-time subscription check when the webhook is added in Meta):
 *        hub.mode=subscribe + hub.verify_token must equal the saved
 *        whatsapp.verify_token → echo hub.challenge. Anything else → 403.
 *   POST (status callbacks): X-Hub-Signature-256 must be a valid HMAC-SHA256
 *        of the raw body with the app secret. No app secret saved, or a bad
 *        signature → 403 and nothing is written (fail closed).
 * Valid POSTs always get 200 (Meta retries non-2xx for days). Replies staff
 * send TO the business number are acknowledged and ignored.
 *
 * Callback URL to paste in Meta: <APP_URL>/fleetforge/api/v1/webhooks/whatsapp.php
 *
 * @method GET|POST
 * @auth   Meta verify token (GET) / X-Hub-Signature-256 (POST); no session
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__, 3) . '/config/app.php';

use FleetForge\Notifications\WhatsApp\WhatsAppClient;
use FleetForge\Notifications\WhatsApp\WhatsAppDeliveries;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // PHP turns "hub.mode" into "hub_mode" in $_GET.
    $mode      = (string) ($_GET['hub_mode'] ?? '');
    $token     = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? '');
    $saved     = trim((string) settings_get('whatsapp.verify_token', ''));
    if ($mode === 'subscribe' && $saved !== '' && hash_equals($saved, $token) && $challenge !== '') {
        header('Content-Type: text/plain');
        echo preg_replace('/[^A-Za-z0-9_\-.]/', '', $challenge);
        exit;
    }
    http_response_code(403);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (!WhatsAppClient::verifySignature($raw, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    error_log('[whatsapp_webhook] rejected: bad or missing signature (is the app secret saved?)');
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);
$updated = 0;
foreach ((array) ($payload['entry'] ?? []) as $entry) {
    foreach ((array) ($entry['changes'] ?? []) as $change) {
        foreach ((array) ($change['value']['statuses'] ?? []) as $st) {
            $wamid  = (string) ($st['id'] ?? '');
            $status = (string) ($st['status'] ?? '');
            if ($wamid === '' || $status === '') {
                continue;
            }
            $err = null;
            if (!empty($st['errors'][0])) {
                $e0  = $st['errors'][0];
                $err = trim((string) ($e0['error_data']['details'] ?? $e0['message'] ?? $e0['title'] ?? 'Failed'))
                     . (isset($e0['code']) ? ' (code ' . (int) $e0['code'] . ')' : '');
            }
            try {
                if (WhatsAppDeliveries::applyStatus($wamid, $status, $err)) {
                    $updated++;
                }
            } catch (\Throwable $ex) {
                error_log('[whatsapp_webhook] status update failed: ' . $ex->getMessage());
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'updated' => $updated]);
