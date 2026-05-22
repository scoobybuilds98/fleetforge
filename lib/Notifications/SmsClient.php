<?php
declare(strict_types=1);

/**
 * lib/Notifications/SmsClient.php
 *
 * Twilio SMS adapter for the briefing's "sms" channel (F6).
 *
 * Gracefully degrades:
 *   - settings.twilio.enabled='0' → returns skipped:true reason:disabled
 *   - account_sid OR auth_token empty → skipped:true reason:no_creds
 *   - from_phone empty → skipped:true reason:no_from_phone
 *   - target phone_e164 empty → skipped:true reason:no_recipient_phone
 *
 * SMS body is intentionally terse — 160 char target. Long-form data
 * lives in the email channel; SMS is a "heads up" with a deeplink to
 * the dashboard.
 *
 * @session  S-INTEL-V2 Phase D
 * @decision D-INTEL-V2-6
 */

namespace FleetForge\Notifications;

class SmsClient
{
    /**
     * Send an SMS via Twilio. Returns an outcome array with ok/skipped/
     * reason — caller logs to notification_log as appropriate.
     *
     * @param string $toE164 Destination phone, E.164 format
     * @param string $body   SMS body, ideally < 160 chars
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, http_status?: int, response?: string, sid?: string}
     */
    public static function send(string $toE164, string $body): array
    {
        if ((string) settings_get('twilio.enabled', '0') !== '1') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'sms_disabled'];
        }
        $sid       = trim((string) settings_get('twilio.account_sid', ''));
        $authToken = trim((string) settings_get('twilio.auth_token', ''));
        $fromPhone = trim((string) settings_get('twilio.from_phone', ''));

        if ($sid === '' || $authToken === '')  return ['ok' => false, 'skipped' => true, 'reason' => 'no_creds'];
        if ($fromPhone === '')                 return ['ok' => false, 'skipped' => true, 'reason' => 'no_from_phone'];
        if ($toE164 === '')                    return ['ok' => false, 'skipped' => true, 'reason' => 'no_recipient_phone'];

        // E.164 sanity check (+ followed by 7-15 digits).
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $toE164)) {
            return ['ok' => false, 'reason' => 'invalid_to_e164: ' . $toE164];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $postData = http_build_query([
            'From' => $fromPhone,
            'To'   => $toE164,
            'Body' => mb_substr($body, 0, 1500), // Twilio supports up to 1600
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $authToken);
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
            $decoded = json_decode($response, true);
            return [
                'ok'          => true,
                'http_status' => $httpCode,
                'sid'         => is_array($decoded) ? (string) ($decoded['sid'] ?? '') : '',
                'response'    => $response,
            ];
        }
        return ['ok' => false, 'http_status' => $httpCode, 'response' => $response];
    }

    /**
     * Produce a terse SMS summary (~150 chars) of the briefing payload.
     */
    public static function summarizePayload(array $p): string
    {
        $appUrl = rtrim((string) settings_get('app.url', ''), '/');
        $parts = [
            'FleetForge brief',
            'Overdue: ' . ($p['overdue']['count'] ?? 0),
            'Compliance(7d): ' . count($p['compliance'] ?? []),
            'Risk↑: ' . count($p['risk_high'] ?? []),
            'Health↓: ' . count($p['health_drops'] ?? []),
        ];
        $text = implode('; ', $parts);
        if ($appUrl !== '') {
            $text .= '. ' . $appUrl . '/dashboard';
        }
        return mb_substr($text, 0, 160);
    }
}
