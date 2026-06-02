<?php
declare(strict_types=1);

/**
 * cron/ai_weekly_brief.php
 *
 * Weekly digest cron (F9). Runs hourly on the crontab; only dispatches
 * work when:
 *   - settings.ai.weekly_brief_enabled = '1'
 *   - today's day-of-week matches settings.ai.weekly_brief_day (default 1 = Monday)
 *   - local hour matches settings.ai.weekly_brief_hour (default 9)
 *
 * Recipients: all users with weekly_brief_opt_in=1, applied across
 * each user's briefing_channels (same fan-out as morning).
 *
 * @session  S-INTEL-V2 Phase E
 * @decision D-INTEL-V2-7 (weekly digest, independent opt-in)
 *
 * Crontab: 0 * * * * php /var/www/fleetforge/cron/ai_weekly_brief.php
 * Local force: FF_CRON_FORCE=1 php cron/ai_weekly_brief.php
 */

require_once __DIR__ . '/../config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Notifications\MorningBriefingRenderer;
use FleetForge\Notifications\Mailer;
use FleetForge\Notifications\SlackPoster;
use FleetForge\Notifications\SmsClient;
use FleetForge\Email\EmailService;

$forced = (string)(getenv('FF_CRON_FORCE') ?: '') === '1';

if (!$forced) {
    if ((string) settings_get('ai.weekly_brief_enabled', '1') !== '1') {
        exit(0);
    }
    $companyTz = (string) settings_get('company.timezone', 'America/Vancouver');
    try {
        $localNow = new DateTime('now', new DateTimeZone($companyTz));
    } catch (\Throwable $e) {
        $localNow = new DateTime('now', new DateTimeZone('UTC'));
    }
    $targetDay  = (int) settings_get('ai.weekly_brief_day', 1);
    $targetHour = (int) settings_get('ai.weekly_brief_hour', 9);
    if ((int) $localNow->format('w') !== $targetDay || (int) $localNow->format('G') !== $targetHour) {
        exit(0);
    }
}

$lock = db_row("SELECT GET_LOCK('ff_cron_ai_weekly_brief', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) { exit(0); }

$sent = $skipped = $errors = 0;

try {
    $recipients = db_select(
        "SELECT id, name, email, briefing_channels, slack_user_id, phone_e164
           FROM users
          WHERE deleted_at IS NULL AND status = 'active'
            AND weekly_brief_opt_in = 1"
    );

    if (empty($recipients)) {
        $skipped = 0;
        error_log('[CRON ai_weekly_brief] No recipients opted in.');
        exit(0);
    }

    $payload = MorningBriefingRenderer::buildWeeklyPayload();
    $subject = 'Weekly Fleet Digest — ' . $payload['week_start'] . ' to ' . $payload['week_end'];

    foreach ($recipients as $u) {
        $userId = (int) $u['id'];
        $email  = (string) $u['email'];
        $name   = (string) ($u['name'] ?? 'Team');

        // Per-recipient failure isolation (S-CRON-FIX-NOTIFICATION — HIGH-2),
        // mirroring run_morning_digest_emails() in notification_digest.php: one
        // recipient's failure (a disabled channel, a network/API fault, a bad
        // row) must NOT abort the remaining recipients' weekly digests. This is
        // defense-in-depth on top of the enum fix (status='skipped' +
        // channel='slack' now write cleanly) — any OTHER throw stays contained.
        try {
            // 23-hour dedup so re-runs don't double-send.
            $recent = db_row(
                "SELECT id FROM notification_log
                  WHERE entity_type='user' AND entity_id=?
                    AND notification_type='weekly_digest'
                    AND created_at >= NOW() - INTERVAL 23 HOUR
                  LIMIT 1",
                [$userId]
            );
            if ($recent) { $skipped++; continue; }

            $bodyHtml = MorningBriefingRenderer::renderWeeklyBody($name, $payload);

            $channels = ['email'];
            if (!empty($u['briefing_channels'])) {
                $decoded = json_decode((string) $u['briefing_channels'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $channels = array_values(array_filter($decoded, 'is_string'));
                }
            }

            $anySent = false;
            foreach ($channels as $channel) {
                if ($channel === 'email' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $wrapped = EmailService::renderEmailHtml($bodyHtml);
                    $ok = Mailer::send(toEmail: $email, toName: $name, subject: $subject, htmlBody: $wrapped);
                    db_insert('notification_log', [
                        'channel' => 'email', 'recipient' => $email, 'subject' => $subject,
                        'body' => mb_substr(strip_tags($bodyHtml), 0, 2000),
                        'entity_type' => 'user', 'entity_id' => $userId,
                        'notification_type' => 'weekly_digest',
                        'status' => $ok ? 'sent' : 'failed',
                        'sent_at' => $ok ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($ok) $anySent = true;
                } elseif ($channel === 'slack') {
                    $text = "*Weekly Fleet Digest — " . $payload['week_start'] . " to " . $payload['week_end'] . "*\n"
                          . "Invoices: " . $payload['invoices']['count'] . " ($" . $payload['invoices']['total'] . ")\n"
                          . "Payments: " . $payload['payments']['count'] . " ($" . $payload['payments']['total'] . ")\n"
                          . "Active leases: " . $payload['leases']['active'] . " (" . $payload['leases']['new_7d'] . " new)\n"
                          . "Overdue: " . $payload['overdue']['count'] . " ($" . $payload['overdue']['total'] . ")\n"
                          . "Damage open: " . $payload['damage']['count'];
                    $r = SlackPoster::post($text, $u['slack_user_id'] ?? null, $subject);
                    db_insert('notification_log', [
                        'channel' => 'slack', 'recipient' => (string) ($u['slack_user_id'] ?? 'channel-webhook'),
                        'subject' => $subject, 'body' => mb_substr($text, 0, 2000),
                        'entity_type' => 'user', 'entity_id' => $userId,
                        'notification_type' => 'weekly_digest',
                        'status' => $r['ok'] ? 'sent' : (($r['skipped'] ?? false) ? 'skipped' : 'failed'),
                        'error_message' => $r['ok'] ? null : (string) ($r['reason'] ?? ''),
                        'sent_at' => $r['ok'] ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($r['ok']) $anySent = true;
                } elseif ($channel === 'sms') {
                    $smsBody = 'Weekly: Inv=' . $payload['invoices']['count'] . ', Pmt=' . $payload['payments']['count']
                             . ', Overdue=' . $payload['overdue']['count'] . ', Damage=' . $payload['damage']['count'];
                    $r = SmsClient::send((string) ($u['phone_e164'] ?? ''), $smsBody);
                    db_insert('notification_log', [
                        'channel' => 'sms', 'recipient' => (string) ($u['phone_e164'] ?? '(no phone)'),
                        'subject' => $subject, 'body' => mb_substr($smsBody, 0, 2000),
                        'entity_type' => 'user', 'entity_id' => $userId,
                        'notification_type' => 'weekly_digest',
                        'status' => $r['ok'] ? 'sent' : (($r['skipped'] ?? false) ? 'skipped' : 'failed'),
                        'error_message' => $r['ok'] ? null : (string) ($r['reason'] ?? ''),
                        'sent_at' => $r['ok'] ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($r['ok']) $anySent = true;
                }
            }

            if ($anySent) $sent++;
            else          $errors++;
        } catch (\Throwable $recipErr) {
            // Isolate this recipient; the loop proceeds to the next one.
            $errors++;
            error_log('[CRON ai_weekly_brief] recipient #' . $userId . ' failed: ' . $recipErr->getMessage());
            \FleetForge\Observability\Sentry::captureException($recipErr);
        }
    }

    error_log(sprintf('[CRON ai_weekly_brief] sent=%d skipped=%d errors=%d', $sent, $skipped, $errors));
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON ai_weekly_brief] Fatal: ' . $e->getMessage());
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_ai_weekly_brief')", []);
}
