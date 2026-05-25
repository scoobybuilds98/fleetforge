<?php
declare(strict_types=1);

/**
 * api/v1/admin/integrations/test_email.php
 *
 * Diagnoses email delivery configuration and optionally sends a test email
 * to the authenticated operator's own address via SES.
 *
 * Returns a structured diagnostic payload covering:
 *   - Whether isLogMode is active (and why)
 *   - What credentials are present (masked)
 *   - What from-address would be used
 *   - The outcome of the send attempt (if send=1)
 *
 * @method  POST
 * @auth    require_permission('settings', 'edit')
 * @body    JSON optional: { send: 0|1 }
 * @returns 200 { success, mode, from_email, from_name, to_email,
 *               aws_key_source, key_present, secret_present,
 *               send_attempted, send_result, send_error }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;

$body   = json_body();
$doSend = (int) ($body['send'] ?? 0) === 1;

// ── Resolve credentials (same priority as Mailer) ──────────────────────────
$region = (string) (settings_get('aws.region') ?: env('AWS_REGION', 'us-west-2'));
$key    = (string) (settings_get('aws.access_key_id')     ?: env('AWS_ACCESS_KEY_ID', ''));
$secret = (string) (settings_get('aws.secret_access_key') ?: env('AWS_SECRET_ACCESS_KEY', ''));

$keyDbSet    = (string) settings_get('aws.access_key_id')     !== '';
$secretDbSet = (string) settings_get('aws.secret_access_key') !== '';
$keyEnvSet   = env('AWS_ACCESS_KEY_ID', '')     !== '';
$secretEnvSet= env('AWS_SECRET_ACCESS_KEY', '') !== '';

$keySource = $keyDbSet ? 'settings_db' : ($keyEnvSet ? '.env' : 'none');

// ── isLogMode mirror ────────────────────────────────────────────────────────
$appEnv  = APP_ENV;
$isLogMode = false;
$logModeReason = '';
if ($appEnv === 'production') {
    $isLogMode = false;
    $logModeReason = 'APP_ENV=production → always send';
} elseif ($key === '' || $secret === '') {
    $isLogMode = true;
    $logModeReason = 'APP_ENV=' . $appEnv . ' and AWS credentials missing → log-only mode';
} else {
    $isLogMode = false;
    $logModeReason = 'APP_ENV=' . $appEnv . ' but credentials are set → SES mode active';
}

// ── From address ────────────────────────────────────────────────────────────
$fromEmail = (string) (settings_get('email.from_email') ?: env('SMTP_FROM_EMAIL', ''));
$fromName  = (string) (settings_get('email.from_name')  ?: env('SMTP_FROM_NAME', 'FleetForge'));
$fromIsPlaceholder = str_contains($fromEmail, 'yourdomain.com') || $fromEmail === '';

// ── Build diagnostic without sending ───────────────────────────────────────
$diag = [
    'mode'              => $isLogMode ? 'log_file' : 'ses',
    'mode_reason'       => $logModeReason,
    'app_env'           => $appEnv,
    'key_present'       => $key !== '',
    'secret_present'    => $secret !== '',
    'key_source'        => $keySource,
    'key_preview'       => $key !== '' ? (substr($key, 0, 4) . '****' . substr($key, -4)) : null,
    'region'            => $region,
    'from_email'        => $fromEmail,
    'from_name'         => $fromName,
    'from_is_placeholder' => $fromIsPlaceholder,
    'send_attempted'    => false,
    'send_result'       => null,
    'send_error'        => null,
    'log_mode_note'     => $isLogMode
        ? 'Emails are currently written to logs/mail.log, NOT delivered to real inboxes.'
        : null,
    'warnings'          => [],
];

if ($fromIsPlaceholder) {
    $diag['warnings'][] = 'from_email is a placeholder ("' . $fromEmail . '"). SES will reject sends from unverified addresses. Set a real, SES-verified address in Settings → Integrations → Email.';
}
if (!$diag['key_present'] || !$diag['secret_present']) {
    $diag['warnings'][] = 'AWS credentials are not configured. Enter your IAM Access Key ID and Secret in Settings → Integrations → AWS Credentials, then save.';
}

// ── Optionally attempt a real send ─────────────────────────────────────────
if ($doSend) {
    $diag['send_attempted'] = true;
    $me = current_user();
    $toEmail = $me['email'] ?? '';

    if ($isLogMode) {
        $diag['send_result'] = 'skipped';
        $diag['send_error']  = 'Log mode is active — test send would only write to mail.log. Provide real AWS credentials first.';
    } elseif (empty($toEmail)) {
        $diag['send_result'] = 'skipped';
        $diag['send_error']  = 'Could not determine your email address. Cannot send test.';
    } elseif ($fromIsPlaceholder || $fromEmail === '') {
        $diag['send_result'] = 'skipped';
        $diag['send_error']  = 'from_email is a placeholder. Set a real verified from-address first.';
    } else {
        try {
            $ses = new SesClient([
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => ['key' => $key, 'secret' => $secret],
            ]);

            $html = '<p style="font-family:sans-serif;">This is a test email from <strong>FleetForge</strong>.<br>'
                  . 'Your SES configuration is working correctly.</p>'
                  . '<p style="font-family:sans-serif;color:#888;font-size:13px;">Sent at ' . date('Y-m-d H:i:s T') . '</p>';

            $ses->sendEmail([
                'Destination' => ['ToAddresses' => [$toEmail]],
                'Message'     => [
                    'Subject' => ['Charset' => 'UTF-8', 'Data' => 'FleetForge — SES Test Email'],
                    'Body'    => [
                        'Html' => ['Charset' => 'UTF-8', 'Data' => $html],
                        'Text' => ['Charset' => 'UTF-8', 'Data' => 'FleetForge SES test. Sent at ' . date('Y-m-d H:i:s T')],
                    ],
                ],
                'Source' => $fromName !== '' ? "\"{$fromName}\" <{$fromEmail}>" : $fromEmail,
            ]);

            $diag['send_result'] = 'sent';
            $diag['to_email']    = $toEmail;

            db_insert('audit_log', [
                'user_id'      => current_user_id(),
                'user_name'    => $me['name'] ?? 'system',
                'action'       => 'create',
                'module'       => 'settings',
                'entity_type'  => 'ses_test_email',
                'entity_label' => 'SES test email to ' . $toEmail,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

        } catch (AwsException $e) {
            $diag['send_result'] = 'failed';
            $diag['send_error']  = $e->getAwsErrorCode() . ': ' . $e->getAwsErrorMessage();
        } catch (\Throwable $e) {
            $diag['send_result'] = 'failed';
            $diag['send_error']  = $e->getMessage();
        }
    }
}

json_success($diag);
