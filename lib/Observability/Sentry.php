<?php
declare(strict_types=1);

namespace FleetForge\Observability;

/**
 * lib/Observability/Sentry.php
 *
 * Sentry error-monitoring integration for FleetForge (S-PROD-2 / #19).
 *
 * Usage:
 *   Sentry::init();                         // at every entry-point top
 *   Sentry::captureException($e);           // in every catch block
 *   Sentry::captureMessage('Alert text');   // for non-exception alerts
 *
 * Configuration (.env):
 *   SENTRY_DSN=                  blank → no-op in dev
 *   SENTRY_ENVIRONMENT=production
 *   SENTRY_TRACES_SAMPLE_RATE=0.1
 *
 * D-B PII scrub: before_send strips passwords, MFA secrets, tokens,
 * bcrypt hashes, FleetForge AES-encrypted values, and CSRF tokens
 * from every event before it leaves the server.
 */
class Sentry
{
    private static bool $initialized = false;

    // Keys whose values are redacted in every Sentry event (D-B)
    private const SCRUB_KEYS = [
        'password',
        'password_hash',
        'password_reset_token',
        'mfa_secret',
        'mfa_backup_code',
        'totp_code',
        'backup_code',
        'csrf_token',
        'Authorization',
        'authorization',
        'PHPSESSID',
    ];

    // ----------------------------------------------------------------
    // init() — call at the top of every entry-point.
    // Safe to call multiple times — only initializes once per process.
    // ----------------------------------------------------------------
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $dsn = defined('FF_SENTRY_DSN') ? FF_SENTRY_DSN : (function_exists('env') ? env('SENTRY_DSN', '') : ($_ENV['SENTRY_DSN'] ?? getenv('SENTRY_DSN') ?: ''));

        if ($dsn === '') {
            // No DSN configured — silent no-op (dev mode).
            return;
        }

        $environment = function_exists('env')
            ? env('SENTRY_ENVIRONMENT', 'production')
            : ($_ENV['SENTRY_ENVIRONMENT'] ?? getenv('SENTRY_ENVIRONMENT') ?: 'production');

        $sampleRate = (float) (function_exists('env')
            ? env('SENTRY_TRACES_SAMPLE_RATE', '0.1')
            : ($_ENV['SENTRY_TRACES_SAMPLE_RATE'] ?? getenv('SENTRY_TRACES_SAMPLE_RATE') ?: '0.1'));

        // Use FF_ASSET_VERSION as the release identifier if available.
        $release = defined('FF_ASSET_VERSION') ? 'fleetforge@' . FF_ASSET_VERSION : null;

        \Sentry\init([
            'dsn'                  => $dsn,
            'environment'          => $environment,
            'release'              => $release,
            'traces_sample_rate'   => $sampleRate,
            'before_send'          => [self::class, 'scrubEvent'],
            'send_default_pii'     => false,
        ]);
    }

    // ----------------------------------------------------------------
    // captureException() — forward a caught Throwable to Sentry.
    // Call inside every catch block at entry-points.
    // ----------------------------------------------------------------
    public static function captureException(\Throwable $e): void
    {
        if (!self::$initialized) {
            return;
        }
        \Sentry\captureException($e);
    }

    // ----------------------------------------------------------------
    // captureMessage() — forward a string alert to Sentry.
    // Use for non-exception signals (e.g. cron completed with errors).
    // ----------------------------------------------------------------
    public static function captureMessage(string $message, string $level = 'error'): void
    {
        if (!self::$initialized) {
            return;
        }
        $sentryLevel = \Sentry\Severity::fromError(new \ErrorException($message));
        \Sentry\captureMessage($message, $sentryLevel);
    }

    // ----------------------------------------------------------------
    // scrubEvent() — Sentry before_send callback (D-B PII scrub).
    //
    // Walks every frame's local variables, request data, and extra
    // context, replacing sensitive values with [REDACTED].
    // Also strips bcrypt hashes ($2y$) and AES-encrypted values (ENC:).
    // ----------------------------------------------------------------
    public static function scrubEvent(\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event
    {
        // Scrub request body
        $request = $event->getRequest();
        if ($request !== null) {
            $data = $request->getData();
            if (is_array($data)) {
                $event->getRequest()->setData(self::scrubArray($data));
            }
            // Scrub cookies
            $cookies = $request->getCookies();
            if (!empty($cookies)) {
                $event->getRequest()->setCookies(self::scrubArray($cookies));
            }
            // Scrub headers
            $headers = $request->getHeaders();
            if (!empty($headers)) {
                $event->getRequest()->setHeaders(self::scrubArray($headers));
            }
        }

        // Scrub stack frame local variables
        $exception = $event->getExceptions();
        if (!empty($exception)) {
            foreach ($exception as $ex) {
                $stacktrace = $ex->getStacktrace();
                if ($stacktrace === null) continue;
                foreach ($stacktrace->getFrames() as $frame) {
                    $vars = $frame->getVars();
                    if (!empty($vars)) {
                        $frame->setVars(self::scrubArray($vars));
                    }
                }
            }
        }

        // Scrub extra context
        $extra = $event->getExtra();
        if (!empty($extra)) {
            $event->setExtra(self::scrubArray($extra));
        }

        return $event;
    }

    // ----------------------------------------------------------------
    // scrubArray() — recursively redact sensitive keys and patterns.
    // ----------------------------------------------------------------
    private static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            // Key-based redaction
            if (in_array((string) $key, self::SCRUB_KEYS, true)) {
                $data[$key] = '[REDACTED]';
                continue;
            }

            // Pattern-based redaction on string values
            if (is_string($value)) {
                // bcrypt hash: $2y$10$...
                if (str_starts_with($value, '$2y$') || str_starts_with($value, '$2b$')) {
                    $data[$key] = '[REDACTED:BCRYPT]';
                    continue;
                }
                // FleetForge AES-256-CBC encrypted secret
                if (str_starts_with($value, 'ENC:')) {
                    $data[$key] = '[REDACTED:ENCRYPTED]';
                    continue;
                }
            }

            // Recurse into nested arrays
            if (is_array($value)) {
                $data[$key] = self::scrubArray($value);
            }
        }

        return $data;
    }
}
