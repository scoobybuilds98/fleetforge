<?php
declare(strict_types=1);

/**
 * lib/Notifications/SlackPoster.php
 *
 * Slack webhook poster for the briefing's "slack" channel (F6).
 *
 * Gracefully degrades:
 *   - If settings.slack.enabled='0' → no-op, returns ['ok'=>true,
 *     'skipped'=>true, 'reason'=>'disabled'].
 *   - If webhook_url empty → no-op, logs to notification_log as
 *     'failed' with reason='no_webhook_url'.
 *
 * Per D-INTEL-V2-6, this is delivery infrastructure; the cron's
 * dispatch fan-out is responsible for deciding which users get
 * Slack delivery (based on their briefing_channels JSON).
 *
 * @session  S-INTEL-V2 Phase D
 * @decision D-INTEL-V2-6
 */

namespace FleetForge\Notifications;

class SlackPoster
{
    /**
     * Post a message to the configured Slack workspace.
     *
     * @param string  $text       Plain-text message body (Slack will
     *                            apply markdown rendering automatically).
     * @param ?string $userIdHint Slack user ID for DM routing. Without
     *                            this the message posts to the webhook
     *                            channel.
     * @param string  $title      Optional subject/title rendered as a
     *                            bold first line.
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, http_status?: int, response?: string}
     */
    public static function post(string $text, ?string $userIdHint = null, string $title = ''): array
    {
        if ((string) settings_get('slack.enabled', '0') !== '1') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'slack_disabled'];
        }
        $webhook = trim((string) settings_get('slack.webhook_url', ''));
        if ($webhook === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_webhook_url'];
        }

        // Build payload — Slack incoming-webhook block format. If
        // $userIdHint is set we mention them via Slack mrkdwn syntax;
        // the actual DM-routing requires Slack chat.postMessage API
        // with bot token rather than incoming webhook. For v1 we just
        // mention the user in the channel — operator can wire up
        // proper DM bot tokens later if needed.
        $message = '';
        if ($title !== '') {
            $message .= "*{$title}*\n\n";
        }
        if ($userIdHint !== null && $userIdHint !== '') {
            $message = "<@{$userIdHint}>\n" . $message;
        }
        $message .= $text;

        $payload = json_encode(['text' => $message]);
        $ch = curl_init($webhook);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'reason' => 'curl_error: ' . $err];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'http_status' => $httpCode, 'response' => $response];
        }
        return ['ok' => false, 'http_status' => $httpCode, 'response' => $response];
    }

    /**
     * Convenience: produce a Slack-friendly plain-text summary of the
     * briefing payload for users on the slack channel. Skips the
     * lengthy HTML and instead produces a digest summary.
     *
     * @param array $p MorningBriefingRenderer payload shape
     */
    public static function summarizePayload(array $p): string
    {
        $lines = [];
        $lines[] = '*Today\'s fleet briefing*';
        $lines[] = "• Overdue invoices: *{$p['overdue']['count']}* totalling \${$p['overdue']['total']}";
        $lines[] = "• Compliance expiring (7d): *" . count($p['compliance'] ?? []) . "*";
        $lines[] = "• Open damage claims: *{$p['damage']['count']}*";
        $lines[] = "• Risk transitions to HIGH: *" . count($p['risk_high'] ?? []) . "*";
        $lines[] = "• Health drops to orange/red: *" . count($p['health_drops'] ?? []) . "*";
        if (!empty($p['brief']['paragraph'])) {
            $lines[] = '';
            $lines[] = '_AI brief excerpt:_';
            $lines[] = mb_substr((string) $p['brief']['paragraph'], 0, 360) . '…';
        }
        return implode("\n", $lines);
    }
}
