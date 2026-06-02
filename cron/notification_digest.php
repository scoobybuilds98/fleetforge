<?php
declare(strict_types=1);

/**
 * cron/notification_digest.php
 *
 * Morning notification digest — runs hourly on the server crontab; only
 * actually does work when the LOCAL hour in company.timezone is 07:00.
 *
 * Three responsibilities (S-CRON-3):
 *   4a — Morning digest emails to managers / accountants / super_admins
 *   4c — Auto-generate dunning letters at 30/60/90-day buckets
 *   4e — Dispatch any scheduled_reports whose next_send_at has passed
 *
 * NOT IN SCOPE for this cron (handled by existing crons; surfaced in
 * S-CRON-3 brief):
 *   4b AR escalation ladder       → cron/collections_auto_escalate.php
 *   4d Promise-to-pay broken      → cron/promise_to_pay_check.php
 *
 * Crontab (production): 0 * * * * php /var/www/fleetforge/cron/notification_digest.php
 * Local test (forces the work to run regardless of hour):
 *   FF_CRON_FORCE=1 php /Users/avi/Documents/fleetforge/cron/notification_digest.php
 *
 * Timezone gate (D-H):
 *   Server crontab is UTC. Cron reads settings.company.timezone (default
 *   America/Vancouver) and exits silently when local hour != 7. Survives
 *   DST without manual crontab edits.
 *
 * @session S-CRON-3
 * @audit   #3 (missing crons), #23 (orphan scheduled_reports), #26 (AR escalation)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Testability seam (S-CRON-FIX-NOTIFICATION): when required by a smoke
// (FF_NOTIFICATION_DIGEST_INCLUDE defined), expose the helper functions at the
// bottom of this file (incl. digest_hour_sections_should_run) WITHOUT running
// the cron body. Top-level function declarations are hoisted, so they remain
// available to the includer even though this early return skips the body.
if (defined('FF_NOTIFICATION_DIGEST_INCLUDE')) {
    return;
}

// -----------------------------------------------------------------------
// Timezone gate. S-INTEL-V2 / D-INTEL-V2-2: per-user briefing_hour means
// the cron now runs every hour and dispatches only to users matching
// THIS hour. Set FF_CRON_FORCE=1 to bypass for local testing.
//
// Computation: localHour = NOW() in company.timezone, expressed as 0..23.
// Sub-section 4a uses $GLOBALS['ff_current_local_hour'] to filter users.
// Other sub-sections (4c dunning, 4e scheduled reports) gate themselves
// on the global digest_hour (no per-user concept).
// -----------------------------------------------------------------------
$forced = (string)(getenv('FF_CRON_FORCE') ?: '') === '1';
$companyTz = (string) settings_get('company.timezone', 'America/Vancouver');
try {
    $localNow = new DateTime('now', new DateTimeZone($companyTz));
} catch (\Throwable $e) {
    error_log("[CRON notification_digest] invalid timezone '{$companyTz}', falling back to UTC");
    $localNow = new DateTime('now', new DateTimeZone('UTC'));
}
$digestHour = (int) settings_get('notifications.digest_hour', 7);
$localHour  = (int) $localNow->format('G');
$GLOBALS['ff_current_local_hour']  = $localHour;
$GLOBALS['ff_global_digest_hour']  = $digestHour;
$GLOBALS['ff_force']               = $forced;

// 4a (morning briefing) always runs — it self-filters per-user briefing hour
// via $GLOBALS['ff_current_local_hour']. 4c (dunning) + 4e (scheduled reports)
// have no per-user hour concept, so they are gated at their call site below via
// digest_hour_sections_should_run() (S-CRON-FIX-NOTIFICATION — MED-5). Before
// this fix the gate here was an empty block, so 4c/4e fired on every hourly
// tick instead of only at the digest hour.

// -----------------------------------------------------------------------
// Advisory lock. One lock guards all 3 subsections so a long subsection
// can't overlap into the next hour's tick.
// -----------------------------------------------------------------------
$lock = db_row("SELECT GET_LOCK('ff_cron_notification_digest', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$digestEmailsSent       = 0;
$digestEmailsSkipped    = 0;
$digestEmailsErrors     = 0;
$dunningCounts          = ['reminder_30' => 0, 'reminder_60' => 0, 'warning_90' => 0];
$dunningSkipped         = 0;
$dunningErrors          = 0;
$reportsDispatched      = 0;
$reportsSkipped         = 0;

try {
    // ===================================================================
    // 4a — Morning digest emails
    // ===================================================================
    [$digestEmailsSent, $digestEmailsSkipped, $digestEmailsErrors] = run_morning_digest_emails();

    // 4c + 4e fire ONLY at the digest hour (S-CRON-FIX-NOTIFICATION — MED-5).
    // $forced (manual trigger) bypasses the gate. When the hour doesn't match,
    // the pre-initialized zero counters above flow into the summary unchanged.
    if (digest_hour_sections_should_run($forced, $localHour, $digestHour)) {
        // ===============================================================
        // 4c — Dunning letter generation
        // ===============================================================
        [$dunningCounts, $dunningSkipped, $dunningErrors] = run_dunning_letters();

        // ===============================================================
        // 4e — Scheduled reports dispatch (orphan table per audit #23 — most
        // runs will skip silently with no work to do).
        // ===============================================================
        [$reportsDispatched, $reportsSkipped] = run_scheduled_reports();
    }

    $summary = sprintf(
        'Digest cron complete. emails(sent=%d skipped=%d errors=%d) dunning(r30=%d r60=%d w90=%d skipped=%d errors=%d) reports(sent=%d skipped=%d). [4b/4d run by collections_auto_escalate + promise_to_pay_check.]',
        $digestEmailsSent, $digestEmailsSkipped, $digestEmailsErrors,
        $dunningCounts['reminder_30'], $dunningCounts['reminder_60'], $dunningCounts['warning_90'],
        $dunningSkipped, $dunningErrors,
        $reportsDispatched, $reportsSkipped,
    );
    error_log('[CRON] ' . $summary);

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'notification_digest',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON notification_digest] Fatal: ' . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'notification_digest',
        'notes'        => 'notification_digest fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_notification_digest')", []);
}

// =======================================================================
// 4a — Morning digest emails
// =======================================================================

/**
 * S-INTEL-TAB / D-INTEL-2 — Three-gate filter precedence:
 *   (1) settings.ai.briefing_enabled — master toggle (separate from
 *       ai.enabled so the briefing can pause without taking down AI
 *       chat or anomaly scan)
 *   (2) settings.ai.briefing_recipient_roles — JSON array of
 *       user_roles.slug values; defaults to
 *       ["super_admin","manager","accountant"]. Operator can edit via
 *       the Intelligence settings tab to add/remove role-level
 *       eligibility without touching this cron.
 *   (3) users.morning_briefing_opt_in = 1 — each user's per-row
 *       opt-in flag. Backfilled by the S-INTEL-TAB migration to
 *       preserve current behavior (super_admin / manager / accountant
 *       users default to opt_in=1; new users to 0).
 *
 * All three must pass for a user to receive the briefing.
 *
 * @return array{0:int,1:int,2:int}  [sent, skipped, errors]
 */
function run_morning_digest_emails(): array
{
    $sent = $skipped = $errors = 0;

    // ── Gate 1: master briefing kill switch (D-INTEL-2). ─────────
    if ((string) settings_get('ai.briefing_enabled', '1') !== '1') {
        return [0, 0, 0];
    }

    // ── Gate 2: role-level allow list (D-INTEL-2). ───────────────
    // JSON array of user_roles.slug values. Falls back to the
    // original hardcoded list if the setting is malformed.
    $rolesJson = (string) settings_get(
        'ai.briefing_recipient_roles',
        '["super_admin","manager","accountant"]'
    );
    $roles = json_decode($rolesJson, true);
    if (!is_array($roles) || empty($roles)) {
        $roles = ['super_admin', 'manager', 'accountant'];
    }
    // Sanitize — drop non-strings; cap at user_roles values via the JOIN below.
    $roles = array_values(array_filter($roles, 'is_string'));
    if (empty($roles)) {
        return [0, 0, 0];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));

    // ── Gate 3: per-user opt-in (D-INTEL-2). ─────────────────────
    // morning_briefing_opt_in column added by S-INTEL-TAB migration;
    // existing super_admin/manager/accountant users were backfilled
    // to 1 in the same migration.
    //
    // ── Gate 3b (S-INTEL-V2 / D-INTEL-V2-5): snooze. ─────────────
    // Users with briefing_snoozed_until > NOW() are temporarily
    // unsubscribed (vacation mode). Expired snooze auto-resumes.
    //
    // ── Gate 3c (S-INTEL-V2 / D-INTEL-V2-2): per-user hour. ──────
    // Each user's preferred hour: briefing_hour column, or NULL
    // meaning "use the global digest_hour". The cron runs every
    // hour now — only users whose effective hour matches THIS run
    // get included. $GLOBALS['ff_force']==true bypasses the hour
    // gate for local testing.
    $force      = (bool) ($GLOBALS['ff_force'] ?? false);
    $localHour  = (int)  ($GLOBALS['ff_current_local_hour'] ?? 0);
    $globalHour = (int)  ($GLOBALS['ff_global_digest_hour'] ?? 7);

    $hourGate = $force
        ? '1=1'
        : "(u.briefing_hour = ? OR (u.briefing_hour IS NULL AND ? = ?))";

    $sqlParams = $roles;
    if (!$force) {
        // Append the 3 hour params (briefing_hour=?, ?, ?=?) → (localHour, localHour, globalHour).
        // SQL: (u.briefing_hour = ? OR (u.briefing_hour IS NULL AND ? = ?))
        //                          ↑ localHour              ↑ localHour ↑ globalHour
        $sqlParams[] = $localHour;
        $sqlParams[] = $localHour;
        $sqlParams[] = $globalHour;
    }

    // S-INTEL-V2 Phase D: pull per-user channels + slack/sms identifiers.
    $recipients = db_select(
        "SELECT u.id, u.name, u.email, u.briefing_sections,
                u.briefing_channels, u.slack_user_id, u.phone_e164, ur.slug
         FROM users u
         JOIN user_roles ur ON ur.id = u.role_id
         WHERE u.deleted_at IS NULL AND u.status = 'active'
           AND u.morning_briefing_opt_in = 1
           AND (u.briefing_snoozed_until IS NULL OR u.briefing_snoozed_until <= NOW())
           AND {$hourGate}
           AND ur.slug IN ({$placeholders})",
        $sqlParams
    );

    if (empty($recipients)) {
        return [0, 0, 0];
    }

    $payload = \FleetForge\Notifications\MorningBriefingRenderer::buildPayload();

    foreach ($recipients as $user) {
        $userId = (int)$user['id'];
        $email  = (string)$user['email'];

        try {
            // Dedup: one digest per user per day. notification_log is the
            // shared dedup table — entity_type='user', notification_type='morning_digest'.
            $recent = db_row(
                "SELECT id FROM notification_log
                 WHERE entity_type = 'user'
                   AND entity_id = ?
                   AND notification_type = 'morning_digest'
                   AND created_at >= NOW() - INTERVAL 23 HOUR
                 LIMIT 1",
                [$userId]
            );
            if ($recent) {
                $skipped++;
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            // S-INTEL-V2 / D-INTEL-V2-4: honor per-user briefing_sections.
            $userSections = null;
            if (!empty($user['briefing_sections'])) {
                $decoded = json_decode((string) $user['briefing_sections'], true);
                if (is_array($decoded)) { $userSections = $decoded; }
            }
            $subject  = 'Morning Briefing — ' . date('M j, Y');
            $name     = (string) ($user['name'] ?? '');
            $bodyHtml = \FleetForge\Notifications\MorningBriefingRenderer::renderBody($user['name'] ?? 'Team', $payload, $userSections);

            // S-INTEL-V2 / D-INTEL-V2-6: parse per-user channel list.
            $channels = ['email']; // default — matches the column DEFAULT
            if (!empty($user['briefing_channels'])) {
                $decoded = json_decode((string) $user['briefing_channels'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $channels = array_values(array_filter($decoded, 'is_string'));
                }
            }

            $anySent = false;
            foreach ($channels as $channel) {
                if ($channel === 'email') {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                    $wrappedHtml = \FleetForge\Email\EmailService::renderEmailHtml($bodyHtml);
                    $okCh = \FleetForge\Notifications\Mailer::send(
                        toEmail:  $email,
                        toName:   $name,
                        subject:  $subject,
                        htmlBody: $wrappedHtml,
                    );
                    db_insert('notification_log', [
                        'channel'           => 'email',
                        'recipient'         => $email,
                        'subject'           => $subject,
                        'body'              => mb_substr(strip_tags($bodyHtml), 0, 2000),
                        'entity_type'       => 'user',
                        'entity_id'         => $userId,
                        'notification_type' => 'morning_digest',
                        'status'            => $okCh ? 'sent' : 'failed',
                        'error_message'     => $okCh ? null : 'Mailer::send returned false',
                        'sent_at'           => $okCh ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($okCh) $anySent = true;
                } elseif ($channel === 'slack') {
                    $slackText = \FleetForge\Notifications\SlackPoster::summarizePayload($payload);
                    $result = \FleetForge\Notifications\SlackPoster::post(
                        $slackText,
                        $user['slack_user_id'] ?? null,
                        $subject
                    );
                    $statusStr = $result['ok'] ? 'sent' : ($result['skipped'] ?? false ? 'skipped' : 'failed');
                    db_insert('notification_log', [
                        'channel'           => 'slack',
                        'recipient'         => (string) ($user['slack_user_id'] ?? 'channel-webhook'),
                        'subject'           => $subject,
                        'body'              => mb_substr($slackText, 0, 2000),
                        'entity_type'       => 'user',
                        'entity_id'         => $userId,
                        'notification_type' => 'morning_digest',
                        'status'            => $statusStr,
                        'error_message'     => $result['ok'] ? null : (string) ($result['reason'] ?? 'slack_failed'),
                        'sent_at'           => $result['ok'] ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($result['ok']) $anySent = true;
                } elseif ($channel === 'sms') {
                    $smsBody = \FleetForge\Notifications\SmsClient::summarizePayload($payload);
                    $result  = \FleetForge\Notifications\SmsClient::send((string) ($user['phone_e164'] ?? ''), $smsBody);
                    $statusStr = $result['ok'] ? 'sent' : ($result['skipped'] ?? false ? 'skipped' : 'failed');
                    db_insert('notification_log', [
                        'channel'           => 'sms',
                        'recipient'         => (string) ($user['phone_e164'] ?? '(no phone)'),
                        'subject'           => $subject,
                        'body'              => mb_substr($smsBody, 0, 2000),
                        'entity_type'       => 'user',
                        'entity_id'         => $userId,
                        'notification_type' => 'morning_digest',
                        'status'            => $statusStr,
                        'error_message'     => $result['ok'] ? null : (string) ($result['reason'] ?? 'sms_failed'),
                        'sent_at'           => $result['ok'] ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($result['ok']) $anySent = true;
                }
            }

            if ($anySent) $sent++;
            else          $errors++;

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON notification_digest] User #{$userId} digest failed: " . $e->getMessage());
        }
    }

    return [$sent, $skipped, $errors];
}

/**
 * S-INTEL-TAB: build_digest_payload() and render_digest_body() were
 * extracted to lib/Notifications/MorningBriefingRenderer.php so the
 * Intelligence settings tab's "Send test briefing" endpoint and this
 * cron both produce identical HTML output. Cron now calls
 * MorningBriefingRenderer::buildPayload() / ::renderBody() directly
 * (lines 157 + 228). The functions below remain as thin shims that
 * delegate to the class to keep BC if anything else references them.
 */
function build_digest_payload(): array
{
    return \FleetForge\Notifications\MorningBriefingRenderer::buildPayload();
}

function render_digest_body(string $userName, array $p): string
{
    return \FleetForge\Notifications\MorningBriefingRenderer::renderBody($userName, $p);
}


// =======================================================================
// 4c — Dunning letter generation
// =======================================================================

/**
 * @return array{0:array<string,int>,1:int,2:int}  [counts, skipped, errors]
 */
function run_dunning_letters(): array
{
    $counts  = ['reminder_30' => 0, 'reminder_60' => 0, 'warning_90' => 0];
    $skipped = 0;
    $errors  = 0;

    // For each customer with overdue invoices, find their max days overdue
    // and pick the appropriate letter type. Schema dedup: per CUSTOMER per
    // letter_type, with a 30-day cooldown (one cycle of monthly dunning).
    // The schema does not store letters per-invoice (acc_dunning_letters
    // has customer_id + invoice_count + total_overdue), so a single letter
    // covers all of a customer's overdue invoices.
    $candidates = db_select(
        "SELECT c.id, c.company_name, c.email,
                MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_days
         FROM customers c
         JOIN invoices i ON i.customer_id = c.id
         WHERE c.deleted_at IS NULL AND i.deleted_at IS NULL
           AND i.status IN ('sent','overdue','partially_paid')
           AND i.balance_due > 0 AND i.due_date < CURDATE()
         GROUP BY c.id, c.company_name, c.email
         HAVING max_days >= 30"
    );

    foreach ($candidates as $cust) {
        $custId   = (int)$cust['id'];
        $name     = (string)$cust['company_name'];
        $email    = (string)$cust['email'];
        $maxDays  = (int)$cust['max_days'];

        $letterType = match (true) {
            $maxDays >= 90 => 'warning_90',
            $maxDays >= 60 => 'reminder_60',
            default        => 'reminder_30',
        };

        try {
            // Dedup: skip if this letter_type was already sent to this
            // customer within the last 30 days. WHY: dunning is a monthly
            // cycle; resending the same stage letter every day would
            // become harassment. If they've not paid in 60 days they get
            // reminder_60 once, then warning_90 30 days later, etc.
            $recent = db_row(
                "SELECT id FROM acc_dunning_letters
                 WHERE customer_id = ?
                   AND letter_type = ?
                   AND sent_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 LIMIT 1",
                [$custId, $letterType]
            );
            if ($recent) {
                $skipped++;
                continue;
            }

            $result = \FleetForge\Accounting\DunningLetterGenerator::generate(
                customerId: $custId,
                letterType: $letterType,
                sentMethod: 'email',
                createdBy:  null,
            );

            // Email the customer if we have a valid address.
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                \FleetForge\Notifications\Mailer::send(
                    toEmail:  $email,
                    toName:   $name,
                    subject:  $result['subject'],
                    htmlBody: $result['html_body'],
                );
            }

            // Notify accounting team in-app.
            \FleetForge\Notifications\NotificationService::notify(
                type:       'accounting.dunning_sent',
                title:      "Dunning letter sent: {$name}",
                message:    "Sent {$letterType} to {$name} — \${$result['total_overdue']} overdue across {$result['invoice_count']} invoice(s).",
                entityType: 'dunning_letter',
                entityId:   $result['id'],
                url:        '/fleetforge/accounting/collections?customer_id=' . $custId,
                severity:   'warning'
            );

            $counts[$letterType]++;
        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON notification_digest dunning] Customer #{$custId}: " . $e->getMessage());
        }
    }

    return [$counts, $skipped, $errors];
}

// =======================================================================
// 4e — Scheduled reports dispatch
// =======================================================================

/**
 * @return array{0:int,1:int}  [dispatched, skipped]
 *
 * Most runs return [0, 0]. The scheduled_reports table is currently empty
 * (audit #23 orphan); when populated by a future feature, this loop will
 * dispatch them.
 */
function run_scheduled_reports(): array
{
    $dispatched = 0;
    $skipped    = 0;

    $due = db_select(
        "SELECT id, name, report_type, parameters, recipients, format, frequency, send_day, send_time
         FROM scheduled_reports
         WHERE is_active = 1
           AND (next_send_at IS NULL OR next_send_at <= NOW())"
    );

    if (empty($due)) {
        return [0, 0];
    }

    foreach ($due as $r) {
        try {
            // No report dispatch executor exists yet (audit #23 — table
            // is orphaned). For now we just bump next_send_at and log
            // the skip so future implementers can wire the executor in
            // one place.
            $next = compute_next_send_at($r);
            db_update('scheduled_reports', [
                'next_send_at' => $next,
                'last_sent_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int)$r['id']]);

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'system',
                'entity_type'  => 'scheduled_report',
                'entity_id'    => (int)$r['id'],
                'entity_label' => (string)$r['name'],
                'notes'        => "Scheduled report '{$r['name']}' due but executor not implemented (audit #23). next_send_at advanced to {$next}.",
                'ip_address'   => '127.0.0.1',
            ]);
            $skipped++;
        } catch (\Throwable $e) {
            error_log("[CRON notification_digest scheduled_reports] #{$r['id']}: " . $e->getMessage());
        }
    }

    return [$dispatched, $skipped];
}

/**
 * compute_next_send_at() — advance next_send_at by the row's frequency.
 *
 * @param array<string,mixed> $row
 */
function compute_next_send_at(array $row): string
{
    $freq = (string)($row['frequency'] ?? 'daily');
    $base = strtotime('+1 day');
    return match ($freq) {
        'weekly'  => date('Y-m-d H:i:s', strtotime('+1 week')),
        'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
        default   => date('Y-m-d H:i:s', $base),
    };
}

/**
 * digest_hour_sections_should_run — gate for the hour-dependent digest
 * sub-sections (4c dunning + 4e scheduled reports). They have no per-user hour
 * concept (unlike 4a, which self-filters per user), so they run only at the
 * digest hour. $forced (manual trigger) bypasses the gate.
 * (S-CRON-FIX-NOTIFICATION — MED-5, D-DIGEST-HOUR-GATE)
 */
function digest_hour_sections_should_run(bool $forced, int $localHour, int $digestHour): bool
{
    return $forced || $localHour === $digestHour;
}
