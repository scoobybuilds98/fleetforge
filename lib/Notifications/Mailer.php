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
// NOTE: .env carries AWS_SES_SMTP_* credentials which can be
// used with a third-party SMTP library if needed later.
// For now we use the SES API directly — same account, more
// reliable, no additional Composer dependency.
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

    // Prevent instantiation
    private function __construct() {}

    // ============================================================
    // send() — the primary send method
    //
    // Returns true on success.
    // On failure: logs the error and returns false (does not throw),
    // so a failed notification never crashes a business operation.
    // ============================================================
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array  $replyTo  = []   // [['email' => '...', 'name' => '...']]
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

        // Auto-generate plain-text body if not provided
        if ($textBody === '') {
            $textBody = strip_tags($htmlBody);
        }

        $fromEmail = (string) env('SMTP_FROM_EMAIL', 'noreply@example.com');
        $fromName  = (string) env('SMTP_FROM_NAME', 'FleetForge');

        // Local dev without credentials → write to log file
        if (self::isLogMode()) {
            return self::logToFile($toEmail, $toName, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
        }

        return self::sendViaSes(
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $textBody,
            $fromEmail,
            $fromName,
            $replyTo
        );
    }

    // ============================================================
    // sendViaSes() — send using AWS SES API
    // ============================================================
    private static function sendViaSes(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromEmail,
        string $fromName,
        array  $replyTo
    ): bool {
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
        string $fromName
    ): bool {
        $logFile = FF_ROOT . '/logs/mail.log';
        $sep     = str_repeat('=', 72);
        $now     = date('Y-m-d H:i:s T');

        $entry = implode("\n", [
            $sep,
            "DATE:    {$now}",
            "FROM:    \"{$fromName}\" <{$fromEmail}>",
            "TO:      \"{$toName}\" <{$toEmail}>",
            "SUBJECT: {$subject}",
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

        self::$sesInstance = new SesClient([
            'version'     => 'latest',
            'region'      => (string) env('AWS_REGION', 'us-west-2'),
            'credentials' => [
                'key'    => (string) env('AWS_ACCESS_KEY_ID', ''),
                'secret' => (string) env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ]);

        return self::$sesInstance;
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

        $key    = (string) env('AWS_ACCESS_KEY_ID', '');
        $secret = (string) env('AWS_SECRET_ACCESS_KEY', '');

        return $key === '' || $secret === '';
    }
}
