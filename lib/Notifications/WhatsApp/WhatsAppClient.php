<?php
declare(strict_types=1);

/**
 * lib/Notifications/WhatsApp/WhatsAppClient.php
 *
 * Sends one approved template message through the official WhatsApp
 * Business Platform (Meta Cloud API) (S-ATTENTION-WHATSAPP).
 *
 *   POST https://graph.facebook.com/{version}/{phone_number_id}/messages
 *   { messaging_product: whatsapp, to, type: template,
 *     template: { name, language: {code}, components: [{type: body, parameters}] } }
 *
 * WHY templates only: a business may start a conversation (or message
 * someone who hasn't written in the last 24h) ONLY with a template Meta has
 * approved. Staff rarely write to the business number, so every message
 * FleetForge sends is a template (Utility category).
 *
 * Template parameter rules enforced by cleanParam(): no newlines or tabs, no
 * more than 4 spaces in a row, never empty (Meta rejects the whole message
 * otherwise).
 *
 * Settings (group 'whatsapp'): enabled, phone_number_id, access_token
 * (ENC:, MfaService), app_secret (ENC:), verify_token, api_version,
 * template_alert, template_summary, template_language.
 *
 * Testing seam: setTransportForTesting(fn(url, headers, body) => [code, body])
 * replaces the HTTP call (same pattern as SamsaraClient).
 *
 * Required by: lib/Notifications/WhatsApp/WhatsAppDeliveries.php,
 *              api/v1/attention/whatsapp_test.php, api/v1/webhooks/whatsapp.php
 * Defines:     FleetForge\Notifications\WhatsApp\WhatsAppClient
 *
 * @session S-ATTENTION-WHATSAPP
 */

namespace FleetForge\Notifications\WhatsApp;

use FleetForge\Auth\MfaService;

final class WhatsAppClient
{
    /** Meta error codes worth retrying (rate limits, temporary faults). */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    /** @var callable|null fn(string $url, array $headers, string $body): array{0:int,1:string} */
    private static $transport = null;

    /**
     * Replace the HTTP call (tests). Pass null to restore curl.
     *
     * @param  callable|null $fn
     * @return void
     */
    public static function setTransportForTesting(?callable $fn): void
    {
        self::$transport = $fn;
    }

    /**
     * Master switch on AND the minimum credentials present.
     *
     * @return bool
     */
    public static function configured(): bool
    {
        return (string) \settings_get('whatsapp.enabled', '0') === '1'
            && trim((string) \settings_get('whatsapp.phone_number_id', '')) !== ''
            && self::secret('whatsapp.access_token') !== '';
    }

    /**
     * Human status for the Settings card.
     *
     * @return string 'on' | 'off' | 'incomplete'
     */
    public static function status(): string
    {
        $on   = (string) \settings_get('whatsapp.enabled', '0') === '1';
        $full = trim((string) \settings_get('whatsapp.phone_number_id', '')) !== '' && self::secret('whatsapp.access_token') !== '';
        return $on ? ($full ? 'on' : 'incomplete') : 'off';
    }

    /**
     * Decrypted secret setting ('' when unset or undecryptable).
     *
     * @param  string $key
     * @return string
     */
    public static function secret(string $key): string
    {
        $raw = (string) \settings_get($key, '');
        if ($raw === '') {
            return '';
        }
        // Stored encrypted (ENC:); tolerate a plain value pasted by hand.
        return str_starts_with($raw, 'ENC:') ? (string) (MfaService::decryptSecret($raw) ?? '') : $raw;
    }

    /**
     * Make a value safe as a template parameter.
     *
     * @param  string $s
     * @param  int    $max  Characters kept (Meta caps a body at 1024 in total)
     * @return string       Never empty
     */
    public static function cleanParam(string $s, int $max = 250): string
    {
        $s = preg_replace('/[\r\n\t]+/', ' · ', $s) ?? '';
        $s = preg_replace('/ {4,}/', '   ', $s) ?? '';
        $s = trim($s, " ·");
        if (mb_strlen($s) > $max) {
            $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
        }
        return $s === '' ? '—' : $s;
    }

    /**
     * "+16045550142" → "16045550142" (Meta wants digits only).
     *
     * @param  string $e164
     * @return string|null  null when not a plausible E.164 number
     */
    public static function toWaId(string $e164): ?string
    {
        $e164 = trim($e164);
        return preg_match('/^\+[1-9]\d{6,14}$/', $e164) ? substr($e164, 1) : null;
    }

    /**
     * Normalise what a person typed into E.164. North American 10-digit
     * numbers get +1; anything else must already include its country code.
     *
     * @param  string $input
     * @return string|null
     */
    public static function normalizePhone(string $input): ?string
    {
        $plus   = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if ($digits === '') {
            return null;
        }
        if (!$plus && strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        $e164 = '+' . $digits;
        return self::toWaId($e164) !== null ? $e164 : null;
    }

    /**
     * Send one template message.
     *
     * @param  string   $toE164
     * @param  string   $template  Approved template name
     * @param  string[] $params    Body parameters, in order ({{1}}, {{2}}, …)
     * @return array{ok: bool, id?: string, error?: string, code?: int|string, retryable?: bool}
     */
    public static function sendTemplate(string $toE164, string $template, array $params): array
    {
        $to = self::toWaId($toE164);
        if ($to === null) {
            return ['ok' => false, 'error' => 'Not a valid phone number: ' . $toE164, 'retryable' => false];
        }
        $phoneId = trim((string) \settings_get('whatsapp.phone_number_id', ''));
        $token   = self::secret('whatsapp.access_token');
        if ($phoneId === '' || $token === '') {
            return ['ok' => false, 'error' => 'WhatsApp isn\'t connected (phone number ID or access token missing).', 'retryable' => false];
        }
        $version = preg_replace('/[^v0-9.]/', '', (string) \settings_get('whatsapp.api_version', 'v23.0')) ?: 'v23.0';
        $lang    = trim((string) \settings_get('whatsapp.template_language', 'en')) ?: 'en';

        $body = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => $lang],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => array_map(
                        static fn($p) => ['type' => 'text', 'text' => self::cleanParam((string) $p, 400)],
                        array_values($params)
                    ),
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url     = "https://graph.facebook.com/{$version}/" . rawurlencode($phoneId) . '/messages';
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

        [$code, $resp] = self::http($url, $headers, (string) $body);
        $json = json_decode($resp, true);

        if ($code >= 200 && $code < 300 && isset($json['messages'][0]['id'])) {
            return ['ok' => true, 'id' => (string) $json['messages'][0]['id']];
        }

        $err     = is_array($json['error'] ?? null) ? $json['error'] : [];
        $errCode = $err['code'] ?? ($code > 0 ? 'HTTP ' . $code : 'network');
        $message = (string) ($err['error_data']['details'] ?? $err['message'] ?? ($code === 0 ? $resp : 'HTTP ' . $code));
        $retry   = $code === 0 || $code >= 500 || $code === 429 || in_array((int) ($err['code'] ?? 0), self::RETRYABLE_CODES, true);

        return [
            'ok'        => false,
            'error'     => mb_substr(self::explain((int) ($err['code'] ?? 0), $message), 0, 480),
            'code'      => $errCode,
            'retryable' => $retry,
        ];
    }

    /**
     * Verify Meta's X-Hub-Signature-256 over the raw webhook body.
     *
     * @param  string $rawBody
     * @param  string $header  "sha256=<hex>"
     * @return bool            false when no app secret is set (fail closed)
     */
    public static function verifySignature(string $rawBody, string $header): bool
    {
        $secret = self::secret('whatsapp.app_secret');
        if ($secret === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /**
     * Plain-words version of the Meta errors people actually hit.
     *
     * @param  int    $code
     * @param  string $fallback
     * @return string
     */
    private static function explain(int $code, string $fallback): string
    {
        return match ($code) {
            190            => 'The access token is invalid or expired. Paste a new permanent token in Settings → Notifications.',
            132000, 132012 => 'The template\'s parameters don\'t match what Meta approved (check the template text and its 4/5 variables). ' . $fallback,
            132001         => 'That template name isn\'t approved in this language. ' . $fallback,
            131026         => 'WhatsApp couldn\'t deliver to this number (no WhatsApp on it, or the person blocked the business).',
            131047         => 'More than 24 hours since the person last wrote; only approved templates can be sent. ' . $fallback,
            130429, 80007  => 'Meta rate limit reached; will retry.',
            default        => $fallback,
        };
    }

    /**
     * POST JSON (or call the test transport).
     *
     * @param  string   $url
     * @param  string[] $headers
     * @param  string   $body
     * @return array{0: int, 1: string}  [http code (0 = network error), response body]
     */
    private static function http(string $url, array $headers, string $body): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $err !== '') {
            return [0, 'Network error: ' . $err];
        }
        return [$code, (string) $resp];
    }
}
