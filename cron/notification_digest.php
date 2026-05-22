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

// -----------------------------------------------------------------------
// Timezone gate. Set FF_CRON_FORCE=1 to bypass for local testing.
// -----------------------------------------------------------------------
$forced = (string)(getenv('FF_CRON_FORCE') ?: '') === '1';
if (!$forced) {
    $companyTz = (string) settings_get('company.timezone', 'America/Vancouver');
    try {
        $localNow = new DateTime('now', new DateTimeZone($companyTz));
    } catch (\Throwable $e) {
        error_log("[CRON notification_digest] invalid timezone '{$companyTz}', falling back to UTC");
        $localNow = new DateTime('now', new DateTimeZone('UTC'));
    }
    $digestHour = (int) settings_get('notifications.digest_hour', 7);
    if ((int) $localNow->format('G') !== $digestHour) {
        // Silent exit — 23 hours/day this is a noop.
        exit(0);
    }
}

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

    // ===================================================================
    // 4c — Dunning letter generation
    // ===================================================================
    [$dunningCounts, $dunningSkipped, $dunningErrors] = run_dunning_letters();

    // ===================================================================
    // 4e — Scheduled reports dispatch (orphan table per audit #23 — most
    // runs will skip silently with no work to do).
    // ===================================================================
    [$reportsDispatched, $reportsSkipped] = run_scheduled_reports();

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
    $recipients = db_select(
        "SELECT u.id, u.name, u.email, ur.slug
         FROM users u
         JOIN user_roles ur ON ur.id = u.role_id
         WHERE u.deleted_at IS NULL AND u.status = 'active'
           AND u.morning_briefing_opt_in = 1
           AND ur.slug IN ({$placeholders})",
        $roles
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

            $bodyHtml    = \FleetForge\Notifications\MorningBriefingRenderer::renderBody($user['name'] ?? 'Team', $payload);
            $wrappedHtml = \FleetForge\Email\EmailService::renderEmailHtml($bodyHtml);
            $subject     = 'Morning Briefing — ' . date('M j, Y');

            $ok = \FleetForge\Notifications\Mailer::send(
                toEmail:  $email,
                toName:   (string)($user['name'] ?? ''),
                subject:  $subject,
                htmlBody: $wrappedHtml,
            );

            db_insert('notification_log', [
                'rule_id'           => null,
                'channel'           => 'email',
                'recipient'         => $email,
                'subject'           => $subject,
                'body'              => mb_substr(strip_tags($bodyHtml), 0, 2000),
                'entity_type'       => 'user',
                'entity_id'         => $userId,
                'notification_type' => 'morning_digest',
                'status'            => $ok ? 'sent' : 'failed',
                'error_message'     => $ok ? null : 'Mailer::send returned false',
                'sent_at'           => $ok ? date('Y-m-d H:i:s') : null,
            ]);

            if ($ok) $sent++;
            else     $errors++;

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
