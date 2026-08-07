<?php
declare(strict_types=1);

namespace FleetForge\Notifications;

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;
use RuntimeException;

// ============================================================
// FleetForge — Mailer [INFRA / D10]
//
// All application email goes through this class.
// Transport: AWS SES API (via aws/aws-sdk-php).
//
// Credentials lookup order [INT-1]:
//   1. settings table FIRST  — settings_get('email.*' / 'aws.*')
//   2. .env file  SECOND     — env('AWS_*' / 'SMTP_*')
// Settings → Integrations UI saves rows, runtime reads them
// without a redeploy. Empty/missing setting → fall through.
//
// Local development without AWS credentials:
//   Emails are written to logs/mail.log instead of sending.
//   Set APP_ENV=local and leave AWS_ACCESS_KEY_ID empty.
//
// Usage:
//   Mailer::send(
//       toEmail: 'john@example.com',
//       toName:  'John Smith',
//       subject: 'Your Invoice is Ready',
//       htmlBody: $html,
//       textBody: $text,   // optional, auto-stripped if omitted
//   );
// ============================================================

class Mailer
{
    /** @var SesClient|null Singleton per process */
    private static ?SesClient $sesInstance = null;

    /**
     * SES sendRawEmail rejects messages larger than 10 MB (the raw,
     * pre-encode size). We check before sending so an oversized
     * attachment fails cleanly instead of erroring out at the API.
     */
    private const MAX_RAW_MESSAGE_BYTES = 10 * 1024 * 1024;

    // Prevent instantiation
    private function __construct() {}

    // ============================================================
    // send() — the primary send method
    //
    // Returns true on success.
    // On failure: logs the error and returns false (does not throw),
    // so a failed notification never crashes a business operation.
    //
    // $attachments — optional list of files to attach. Each item:
    //   [
    //     'content' => <raw bytes>,           // REQUIRED — the file contents
    //     'name'    => 'invoice.pdf',         // REQUIRED — download filename
    //     'type'    => 'application/pdf',      // optional — MIME type
    //   ]
    // When at least one valid attachment is present the message is
    // delivered via SES sendRawEmail (a hand-built multipart/mixed
    // MIME message). With no attachments the plain sendEmail API is
    // used unchanged — keeping the blast radius of this path tiny.
    // I14 fix (CLAUDE_2026-06-20_invoices): SES sendEmail has no
    // attachment support, so PDFs were logged "attached" but never
    // actually delivered until this raw-MIME path existed.
    // ============================================================
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array  $replyTo  = [],  // [['email' => '...', 'name' => '...']]
        array  $attachments = [], // [['content' => bytes, 'name' => '...', 'type' => '...'], ...]
        array  $bcc = []          // ['ops@co.com', ...] envelope BCC (operator copy). S-CUSTOMER-NOTIFICATIONS.
    ): bool {
        // Basic input validation
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log("[Mailer] Invalid recipient email: '{$toEmail}'");
            return false;
        }

        if (trim($subject) === '') {
            error_log("[Mailer] Empty subject — not sending to {$toEmail}");
            return false;
        }

        // D-C: check email_disabled before every send (S-PROD-2 / #21).
        // Permanent SES bounces and complaints auto-disable the address.
        // A disabled address must be re-enabled by a manager before email resumes.
        if (self::isEmailDisabled($toEmail)) {
            error_log("[Mailer] Send blocked — email_disabled=1 for '{$toEmail}'");
            return false;
        }

        // Auto-generate plain-text body if not provided
        if ($textBody === '') {
            $textBody = strip_tags($htmlBody);
        }

        // INT-1: settings table FIRST, .env SECOND.
        $fromEmail = (string) (
            settings_get('email.from_email')
            ?: env('SMTP_FROM_EMAIL', 'noreply@example.com')
        );
        $fromName = (string) (
            settings_get('email.from_name')
            ?: env('SMTP_FROM_NAME', 'FleetForge')
        );

        // Normalize attachments up-front so both the log-mode and SES
        // paths see the same validated, filtered list.
        $attachments = self::normalizeAttachments($attachments);

        // Normalize BCC — valid, unique, never the primary recipient (SES
        // would otherwise double-deliver). Invalid entries are dropped so an
        // operator typo in the "send me a copy" field can never fail a
        // customer send. Empty list → every existing caller is unaffected.
        $bcc = self::normalizeBcc($bcc, $toEmail);

        // Local dev without credentials → write to log file
        if (self::isLogMode()) {
            return self::logToFile($toEmail, $toName, $subject, $htmlBody, $textBody, $fromEmail, $fromName, $attachments, $bcc);
        }

        return self::sendViaSes(
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $textBody,
            $fromEmail,
            $fromName,
            $replyTo,
            $attachments,
            $bcc
        );
    }

    // ============================================================
    // sendViaSes() — send using AWS SES API
    //
    // With attachments → SES sendRawEmail (hand-built MIME, see
    // sendRawViaSes()). Without → the plain sendEmail API, which is
    // simpler and unchanged from the pre-I14 behaviour.
    // ============================================================
    private static function sendViaSes(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        array  $replyTo,
        array  $attachments = [],
        array  $bcc = []
    ): bool {
        // Attachment-bearing mail MUST go through sendRawEmail — the
        // plain sendEmail API has no attachment support (the I14 bug).
        if (!empty($attachments)) {
            return self::sendRawViaSes(
                $toEmail, $toName, $subject, $htmlBody, $textBody,
                $fromEmail, $fromName, $replyTo, $attachments, $bcc
            );
        }

        try {
            $params = [
                'Destination' => [
                    'ToAddresses' => [
                        $toName !== ''
                            ? "\"{$toName}\" <{$toEmail}>"
                            : $toEmail,
                    ],
                ],
                'Message' => [
                    'Body' => [
                        'Html' => [
                            'Charset' => 'UTF-8',
                            'Data'    => $htmlBody,
                        ],
                        'Text' => [
                            'Charset' => 'UTF-8',
                            'Data'    => $textBody,
                        ],
                    ],
                    'Subject' => [
                        'Charset' => 'UTF-8',
                        'Data'    => $subject,
                    ],
                ],
                'Source' => $fromName !== ''
                    ? "\"{$fromName}\" <{$fromEmail}>"
                    : $fromEmail,
            ];

            // Optional reply-to addresses
            if (!empty($replyTo)) {
                $replyToAddresses = [];
                foreach ($replyTo as $r) {
                    $rEmail = $r['email'] ?? '';
                    $rName  = $r['name']  ?? '';
                    if (filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                        $replyToAddresses[] = $rName !== ''
                            ? "\"{$rName}\" <{$rEmail}>"
                            : $rEmail;
                    }
                }
                if (!empty($replyToAddresses)) {
                    $params['ReplyToAddresses'] = $replyToAddresses;
                }
            }

            // Envelope BCC (operator copy). Added to Destination so SES
            // delivers a copy without exposing the address in the headers.
            if (!empty($bcc)) {
                $params['Destination']['BccAddresses'] = $bcc;
            }

            self::ses()->sendEmail($params);
            return true;

        } catch (AwsException $e) {
            error_log(
                "[Mailer] SES send failed to {$toEmail} — " .
                $e->getAwsErrorMessage() . ' (' . $e->getAwsErrorCode() . ')'
            );
            return false;
        } catch (\Throwable $e) {
            error_log("[Mailer] Unexpected error sending to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // sendRawViaSes() — send WITH attachments via SES sendRawEmail
    //
    // SES sendEmail accepts no attachments, so an attachment-bearing
    // message has to be assembled as a raw RFC 5322 / MIME document
    // and handed to sendRawEmail. We build a multipart/mixed envelope
    //   ├─ multipart/alternative  (text/plain + text/html bodies)
    //   └─ application/<type>      (one base64 part per attachment)
    // and pass it as RawMessage.Data (the SDK base64-encodes the blob
    // for the wire). Source/Destinations are also passed explicitly so
    // the SMTP envelope is unambiguous regardless of header parsing.
    // ============================================================
    private static function sendRawViaSes(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        array  $replyTo,
        array  $attachments,
        array  $bcc = []
    ): bool {
        try {
            $rawMessage = self::buildRawMimeMessage(
                $toEmail, $toName, $subject, $htmlBody, $textBody,
                $fromEmail, $fromName, $replyTo, $attachments
            );

            // Guard against SES's 10 MB raw-message ceiling. Better to
            // fail loudly (caller marks the send failed) than to let SES
            // reject it with an opaque error mid-batch.
            if (strlen($rawMessage) > self::MAX_RAW_MESSAGE_BYTES) {
                error_log(
                    "[Mailer] Raw message to {$toEmail} is " . strlen($rawMessage)
                    . ' bytes — exceeds SES ' . self::MAX_RAW_MESSAGE_BYTES . '-byte limit; not sent.'
                );
                return false;
            }

            // BCC addresses ride in the SMTP envelope (Destinations) only —
            // never as a header — so the raw MIME above stays a true BCC.
            self::ses()->sendRawEmail([
                'Source'       => $fromEmail,
                'Destinations' => array_merge([$toEmail], $bcc),
                'RawMessage'   => ['Data' => $rawMessage],
            ]);
            return true;

        } catch (AwsException $e) {
            error_log(
                "[Mailer] SES raw send failed to {$toEmail} — " .
                $e->getAwsErrorMessage() . ' (' . $e->getAwsErrorCode() . ')'
            );
            return false;
        } catch (\Throwable $e) {
            error_log("[Mailer] Unexpected error raw-sending to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // buildRawMimeMessage() — assemble a multipart/mixed MIME document
    //
    // Layout:
    //   multipart/mixed (boundary = $mixed)
    //   ├─ multipart/alternative (boundary = $alt)
    //   │    ├─ text/plain  (base64)
    //   │    └─ text/html   (base64)
    //   └─ per attachment: <mime-type> base64 + Content-Disposition
    //
    // Correctness rules enforced here:
    //   • ALL header values are CR/LF-stripped (header-injection guard).
    //   • Non-ASCII subject / display names use RFC 2047 encoded-words.
    //   • Bodies + attachments are base64 + chunk_split at 76 cols so no
    //     line exceeds the SMTP 998-char limit.
    //   • Boundaries are random (random_bytes) so they cannot collide
    //     with body/attachment content.
    //   • Line endings are CRLF throughout, per RFC 5322.
    // ============================================================
    private static function buildRawMimeMessage(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        array  $replyTo,
        array  $attachments
    ): string {
        $eol   = "\r\n";
        $mixed = 'mixed_'  . bin2hex(random_bytes(16));
        $alt   = 'alt_'    . bin2hex(random_bytes(16));

        // ── Top-level headers ───────────────────────────────────
        $headers   = [];
        $headers[] = 'From: '    . self::formatAddress($fromEmail, $fromName);
        $headers[] = 'To: '      . self::formatAddress($toEmail, $toName);
        $headers[] = 'Subject: ' . self::encodeHeader($subject);

        // Optional Reply-To (first valid address only — matches the
        // single-address envelope the plain path produces)
        foreach ($replyTo as $r) {
            $rEmail = (string) ($r['email'] ?? '');
            $rName  = (string) ($r['name']  ?? '');
            if (filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: ' . self::formatAddress($rEmail, $rName);
                break;
            }
        }

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';

        // ── Body: multipart/alternative (text + html) ───────────
        $body  = '';
        $body .= '--' . $mixed . $eol;
        $body .= 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . $eol . $eol;

        $body .= '--' . $alt . $eol;
        $body .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $body .= chunk_split(base64_encode($textBody), 76, $eol) . $eol;

        $body .= '--' . $alt . $eol;
        $body .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $body .= chunk_split(base64_encode($htmlBody), 76, $eol) . $eol;

        $body .= '--' . $alt . '--' . $eol . $eol;

        // ── Attachment parts ────────────────────────────────────
        foreach ($attachments as $att) {
            $content  = (string) $att['content'];
            $name     = (string) $att['name'];
            $mimeType = (string) ($att['type'] ?? '') ?: 'application/octet-stream';

            $mimeType = self::stripCrlf($mimeType);
            $dispName = self::contentDispositionFilename($name);

            $body .= '--' . $mixed . $eol;
            $body .= 'Content-Type: ' . $mimeType . '; name="' . $dispName['ascii'] . '"' . $eol;
            $body .= 'Content-Transfer-Encoding: base64' . $eol;
            $body .= 'Content-Disposition: attachment; ' . $dispName['param'] . $eol . $eol;
            $body .= chunk_split(base64_encode($content), 76, $eol) . $eol;
        }

        $body .= '--' . $mixed . '--' . $eol;

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    // ============================================================
    // formatAddress() — build a header-safe "Name <email>" value
    //
    // CR/LF stripped from both parts (injection guard). ASCII names
    // are double-quoted (escaping " and \) so commas/specials don't
    // break the address grammar; non-ASCII names use an RFC 2047
    // encoded-word, which needs no quoting.
    // ============================================================
    private static function formatAddress(string $email, string $name): string
    {
        $email = self::stripCrlf($email);
        $name  = self::stripCrlf($name);

        if ($name === '') {
            return $email;
        }

        if (self::isAscii($name)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
            return '"' . $escaped . '" <' . $email . '>';
        }

        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    // ============================================================
    // encodeHeader() — CR/LF-strip + RFC 2047 encode if non-ASCII
    //
    // Pure-ASCII values pass through (after CR/LF stripping); anything
    // with high bytes is wrapped as a base64 encoded-word so SES and
    // downstream MTAs render it correctly. mb_encode_mimeheader folds
    // long values into RFC-compliant continuation lines.
    // ============================================================
    private static function encodeHeader(string $value): string
    {
        $value = self::stripCrlf($value);
        if ($value === '' || self::isAscii($value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    // ============================================================
    // contentDispositionFilename() — safe filename params
    //
    // Returns:
    //   ['ascii' => <quoted-safe ascii>, 'param' => <disposition tail>]
    // ASCII filenames get a simple filename="x". Non-ASCII names also
    // get an RFC 2231 filename*=UTF-8'' form for modern clients, with
    // a transliterated ASCII fallback for older ones.
    // ============================================================
    private static function contentDispositionFilename(string $name): array
    {
        // Strip path separators, CR/LF, and quotes from the filename.
        $name  = self::stripCrlf($name);
        $name  = str_replace(['"', '\\', '/', "\0"], ['', '', '_', ''], $name);
        if ($name === '') {
            $name = 'attachment';
        }

        if (self::isAscii($name)) {
            return [
                'ascii' => $name,
                'param' => 'filename="' . $name . '"',
            ];
        }

        // Non-ASCII: provide a best-effort ASCII fallback plus RFC 2231.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace('"', '', (string) $ascii);
        if ($ascii === '' || $ascii === null) {
            $ascii = 'attachment';
        }
        $encoded = rawurlencode($name);
        return [
            'ascii' => $ascii,
            'param' => 'filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded,
        ];
    }

    // ============================================================
    // normalizeBcc() — validate + de-dupe the BCC list
    //
    // Keeps only RFC-valid addresses, drops the primary recipient (SES
    // rejects / double-delivers a Destination that repeats the To), and
    // de-duplicates case-insensitively. An all-invalid list collapses to
    // [] so the plain/raw send paths run exactly as before.
    // ============================================================
    private static function normalizeBcc(array $bcc, string $toEmail): array
    {
        $seen = [strtolower(trim($toEmail)) => true];
        $out  = [];
        foreach ($bcc as $addr) {
            $addr = trim((string) $addr);
            if ($addr === '' || filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $lc = strtolower($addr);
            if (isset($seen[$lc])) {
                continue;
            }
            $seen[$lc] = true;
            $out[] = $addr;
        }
        return $out;
    }

    // ============================================================
    // normalizeAttachments() — validate + filter the attachment list
    //
    // Drops entries without raw content or a name (the only two fields
    // an attachment truly needs). Keeps just content/name/type so the
    // MIME builder never sees unexpected keys. An all-invalid list
    // collapses to [] → the plain sendEmail path runs unchanged.
    // ============================================================
    private static function normalizeAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $att) {
            if (!is_array($att)) continue;
            $content = $att['content'] ?? null;
            $name    = (string) ($att['name'] ?? '');
            if (!is_string($content) || $content === '' || $name === '') {
                continue;
            }
            $out[] = [
                'content' => $content,
                'name'    => $name,
                'type'    => (string) ($att['type'] ?? '') ?: 'application/octet-stream',
            ];
        }
        return $out;
    }

    // CR/LF strip — single choke-point for header-injection defense.
    private static function stripCrlf(string $v): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $v));
    }

    private static function isAscii(string $v): bool
    {
        return (bool) preg_match('//u', $v) && !preg_match('/[^\x00-\x7F]/', $v);
    }

    // ============================================================
    // logToFile() — dev fallback: write email to logs/mail.log
    //
    // Makes it easy to inspect emails during development without
    // needing real AWS credentials. The log entry includes the
    // full HTML so it can be opened in a browser.
    // ============================================================
    private static function logToFile(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        array  $attachments = [],
        array  $bcc = []
    ): bool {
        $logFile = FF_ROOT . '/logs/mail.log';
        $sep     = str_repeat('=', 72);
        $now     = date('Y-m-d H:i:s T');

        // Summarize attachments so dev/log-mode reflects what WOULD be
        // delivered over SES sendRawEmail (name + decoded byte size).
        $attachLine = 'ATTACHMENTS: (none)';
        if (!empty($attachments)) {
            $bits = [];
            foreach ($attachments as $att) {
                $bits[] = (string) ($att['name'] ?? '?')
                    . ' [' . ((string) ($att['type'] ?? 'application/octet-stream'))
                    . ', ' . strlen((string) ($att['content'] ?? '')) . ' bytes]';
            }
            $attachLine = 'ATTACHMENTS: ' . implode('; ', $bits);
        }

        $entry = implode("\n", [
            $sep,
            "DATE:    {$now}",
            "FROM:    \"{$fromName}\" <{$fromEmail}>",
            "TO:      \"{$toName}\" <{$toEmail}>",
            "BCC:     " . (empty($bcc) ? '(none)' : implode(', ', $bcc)),
            "SUBJECT: {$subject}",
            $attachLine,
            str_repeat('-', 72),
            "TEXT:",
            $textBody,
            str_repeat('-', 72),
            "HTML:",
            $htmlBody,
            $sep,
            '',
        ]);

        // Ensure the logs directory exists
        $logsDir = FF_ROOT . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        $result = file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log("[Mailer] Could not write to {$logFile}");
            return false;
        }

        return true;
    }

    // ============================================================
    // SesClient singleton
    // ============================================================
    private static function ses(): SesClient
    {
        if (self::$sesInstance !== null) {
            return self::$sesInstance;
        }

        // INT-1: settings table FIRST, .env SECOND.
        $region = (string) (
            settings_get('aws.region')
            ?: env('AWS_REGION', 'us-west-2')
        );
        $key = (string) (
            settings_get('aws.access_key_id')
            ?: env('AWS_ACCESS_KEY_ID', '')
        );
        $secret = (string) (
            settings_get('aws.secret_access_key')
            ?: env('AWS_SECRET_ACCESS_KEY', '')
        );

        self::$sesInstance = new SesClient([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ]);

        return self::$sesInstance;
    }

    // ============================================================
    // isEmailDisabled() — returns true if the recipient's address has
    // been auto-disabled by a hard SES bounce or complaint (D-C).
    //
    // Checks both customers and portal_users. If the address appears
    // in neither, or the column doesn't exist yet, returns false
    // (fail-open — never silently block mail for unknown addresses).
    // ============================================================
    private static function isEmailDisabled(string $toEmail): bool
    {
        try {
            $email = strtolower(trim($toEmail));

            $customer = db_row(
                "SELECT email_disabled FROM customers WHERE LOWER(email) = ? AND deleted_at IS NULL LIMIT 1",
                [$email]
            );
            if ($customer && (int) $customer['email_disabled'] === 1) {
                return true;
            }

            $portal = db_row(
                "SELECT email_disabled FROM portal_users WHERE LOWER(email) = ? LIMIT 1",
                [$email]
            );
            if ($portal && (int) $portal['email_disabled'] === 1) {
                return true;
            }
        } catch (\Throwable $e) {
            // Column not yet present (pre-migration): fail-open so mail still works.
            error_log('[Mailer] isEmailDisabled check failed: ' . $e->getMessage());
        }

        return false;
    }

    // ============================================================
    // isLogMode() — true when email should be logged, not sent
    //
    // Conditions: not in production AND no AWS credentials set.
    // This prevents accidents — if you set AWS credentials in
    // .env on a dev machine, real emails will be sent.
    // ============================================================
    private static function isLogMode(): bool
    {
        if (APP_ENV === 'production') {
            return false;
        }

        // INT-1: settings table FIRST, .env SECOND.
        $key = (string) (
            settings_get('aws.access_key_id')
            ?: env('AWS_ACCESS_KEY_ID', '')
        );
        $secret = (string) (
            settings_get('aws.secret_access_key')
            ?: env('AWS_SECRET_ACCESS_KEY', '')
        );

        return $key === '' || $secret === '';
    }
}
