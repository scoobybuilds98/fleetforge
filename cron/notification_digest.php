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
 * @return array{0:int,1:int,2:int}  [sent, skipped, errors]
 */
function run_morning_digest_emails(): array
{
    $sent = $skipped = $errors = 0;

    // Recipients: managers, super_admins, accountants. WHY: these are the
    // roles that need a daily AR + compliance + risk overview. Dispatchers
    // and read-only users opt out by default.
    $recipients = db_select(
        "SELECT u.id, u.name, u.email, ur.slug
         FROM users u
         JOIN user_roles ur ON ur.id = u.role_id
         WHERE u.deleted_at IS NULL AND u.status = 'active'
           AND ur.slug IN ('super_admin','manager','accountant')"
    );

    if (empty($recipients)) {
        return [0, 0, 0];
    }

    $payload = build_digest_payload();

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

            $bodyHtml    = render_digest_body($user['name'] ?? 'Team', $payload);
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
 * build_digest_payload() — collect all the numbers + lists rendered in the
 * digest email body. Computed once; rendered per-recipient.
 *
 * @return array<string,mixed>
 */
function build_digest_payload(): array
{
    $cutoff24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $in7d      = date('Y-m-d', strtotime('+7 days'));

    // Today's overdue
    $overdueRow = db_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS total
         FROM invoices
         WHERE deleted_at IS NULL
           AND status IN ('sent','overdue','partially_paid')
           AND balance_due > 0 AND due_date < CURDATE()"
    );

    $topOverdue = db_select(
        "SELECT c.company_name,
                COALESCE(SUM(i.balance_due), 0) AS total,
                MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_days
         FROM customers c
         JOIN invoices i ON i.customer_id = c.id
         WHERE c.deleted_at IS NULL AND i.deleted_at IS NULL
           AND i.status IN ('sent','overdue','partially_paid')
           AND i.balance_due > 0 AND i.due_date < CURDATE()
         GROUP BY c.id, c.company_name
         ORDER BY total DESC
         LIMIT 5"
    );

    // Compliance expiring within 7 days
    $complianceUnits = db_select(
        "SELECT id, unit_number,
                LEAST(
                    IFNULL(cvi_expiry, '9999-12-31'),
                    IFNULL(registration_expiry, '9999-12-31'),
                    IFNULL(mvi_expiry, '9999-12-31'),
                    IFNULL(insurance_expiry, '9999-12-31')
                ) AS earliest
         FROM equipment_units
         WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
           AND ((cvi_expiry IS NOT NULL AND cvi_expiry <= ?)
             OR (registration_expiry IS NOT NULL AND registration_expiry <= ?)
             OR (mvi_expiry IS NOT NULL AND mvi_expiry <= ?)
             OR (insurance_expiry IS NOT NULL AND insurance_expiry <= ?))
         ORDER BY earliest ASC
         LIMIT 10",
        [$in7d, $in7d, $in7d, $in7d]
    );

    // Open damage claims
    $damage = db_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(estimated_repair_cost), 0) AS total_cost
         FROM damage_claims
         WHERE deleted_at IS NULL AND status NOT IN ('resolved','written_off')"
    );

    // Customers transitioned to HIGH overnight (audit_log of risk_score change)
    $riskHighChanges = db_select(
        "SELECT a.entity_id, a.entity_label, a.notes
         FROM audit_log a
         WHERE a.action = 'status_change'
           AND a.module = 'customers'
           AND a.created_at >= ?
           AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.risk_score')) = 'high'
         ORDER BY a.created_at DESC
         LIMIT 10",
        [$cutoff24h]
    );

    // Equipment that dropped to red/orange overnight
    $healthDrops = db_select(
        "SELECT a.entity_id, a.entity_label,
                JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.color')) AS new_color,
                JSON_UNQUOTE(JSON_EXTRACT(a.old_values, '$.color')) AS old_color,
                JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.health_score')) AS new_score
         FROM audit_log a
         WHERE a.action = 'update'
           AND a.module = 'equipment'
           AND a.created_at >= ?
           AND JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.color')) IN ('orange','red')
           AND JSON_UNQUOTE(JSON_EXTRACT(a.old_values, '$.color')) IN ('green','yellow','unknown')
         ORDER BY a.created_at DESC
         LIMIT 10",
        [$cutoff24h]
    );

    // Yesterday's AI fleet brief (first paragraph)
    $brief = db_row(
        "SELECT result_data FROM report_cache
         WHERE report_type = 'ai_fleet_brief' AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $briefFirstPara = '';
    $briefStale     = false;
    if ($brief) {
        $payload = json_decode((string)$brief['result_data'], true);
        $text    = (string)($payload['brief'] ?? '');
        $briefFirstPara = trim(explode("\n\n", $text, 2)[0] ?? '');
        $briefStale = !empty($payload['generated_at'])
            && strtotime((string)$payload['generated_at']) < strtotime('-20 hours');
    }

    return [
        'overdue' => [
            'count' => (int)($overdueRow['n'] ?? 0),
            'total' => (string)($overdueRow['total'] ?? '0.00'),
            'top'   => $topOverdue,
        ],
        'compliance' => $complianceUnits,
        'damage' => [
            'count'      => (int)($damage['n'] ?? 0),
            'total_cost' => (string)($damage['total_cost'] ?? '0.00'),
        ],
        'risk_high'    => $riskHighChanges,
        'health_drops' => $healthDrops,
        'brief'        => [
            'paragraph' => $briefFirstPara,
            'stale'     => $briefStale,
        ],
    ];
}

/**
 * render_digest_body() — build the inline-styled HTML body for one digest
 * email. Wrapped by EmailService::renderEmailHtml() at send time.
 */
function render_digest_body(string $userName, array $p): string
{
    $sym = (string) settings_get('company.currency_symbol', '$');
    $fmt = fn(string $v) => $sym . number_format((float)$v, 2);
    $appUrl = rtrim((string) settings_get('app.url', ''), '/');

    $html = '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:24px;background:#ffffff;">';
    $html .= '<tr><td style="font-family:Arial,sans-serif;color:#1c1c1a;">';

    $html .= '<h1 style="margin:0 0 16px;font-size:18px;">Good morning, ' . e($userName) . '</h1>';
    $html .= '<p style="font-size:13px;color:#555;margin:0 0 24px;">Here is your fleet briefing for ' . e(date('l, F j, Y')) . '.</p>';

    // Section 1 — overdue invoices
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'Overdue Invoices</h2>';
    $html .= '<p style="font-size:13px;margin:0 0 8px;">'
           . '<strong>' . $p['overdue']['count'] . '</strong> invoice(s) past due totalling <strong>' . e($fmt($p['overdue']['total'])) . '</strong>.</p>';
    if (!empty($p['overdue']['top'])) {
        $html .= '<table cellpadding="6" cellspacing="0" border="0" style="font-size:12px;border-collapse:collapse;margin-bottom:20px;">';
        $html .= '<tr style="background:#f5f5f4;"><th align="left">Customer</th><th align="right">Owed</th><th align="right">Max Days</th></tr>';
        foreach ($p['overdue']['top'] as $row) {
            $html .= '<tr><td>' . e($row['company_name']) . '</td>'
                  .  '<td align="right">' . e($fmt((string)$row['total'])) . '</td>'
                  .  '<td align="right">' . (int)$row['max_days'] . '</td></tr>';
        }
        $html .= '</table>';
    }

    // Section 2 — compliance expiring this week
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'Compliance Expiring This Week</h2>';
    if (!empty($p['compliance'])) {
        $count = count($p['compliance']);
        $html .= '<p style="font-size:13px;margin:0 0 8px;"><strong>' . $count . '</strong> unit(s) with documents expiring within 7 days:</p>';
        $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
        foreach ($p['compliance'] as $u) {
            $html .= '<li>Unit ' . e($u['unit_number']) . ' — earliest expiry ' . e($u['earliest']) . '</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No compliance documents expiring this week.</p>';
    }

    // Section 3 — open damage claims
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'Open Damage Claims</h2>';
    $html .= '<p style="font-size:13px;margin:0 0 20px;">'
           . '<strong>' . $p['damage']['count'] . '</strong> open claim(s), estimated repair cost <strong>' . e($fmt($p['damage']['total_cost'])) . '</strong>.</p>';

    // Section 4 — customer risk to HIGH overnight
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'Customer Risk Changes Overnight</h2>';
    if (!empty($p['risk_high'])) {
        $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
        foreach ($p['risk_high'] as $r) {
            $html .= '<li>' . e($r['entity_label'] ?: ('Customer #' . (int)$r['entity_id'])) . ' — promoted to HIGH risk</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No customers transitioned to HIGH risk overnight.</p>';
    }

    // Section 5 — equipment health drops
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'Equipment Health Drops Overnight</h2>';
    if (!empty($p['health_drops'])) {
        $html .= '<ul style="font-size:12px;margin:0 0 20px;padding-left:18px;">';
        foreach ($p['health_drops'] as $d) {
            $html .= '<li>Unit ' . e($d['entity_label'] ?: ('#' . (int)$d['entity_id']))
                  .  ' — dropped to ' . e($d['new_color']) . ' (score ' . (int)$d['new_score'] . ')</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No units dropped to orange/red overnight.</p>';
    }

    // Section 6 — AI fleet brief excerpt
    $html .= '<h2 style="font-size:14px;color:#1c1c1a;border-bottom:1px solid #e5e5e2;padding-bottom:6px;margin:0 0 8px;">'
           . 'AI Fleet Brief</h2>';
    if (!empty($p['brief']['paragraph'])) {
        $html .= '<p style="font-size:13px;margin:0 0 8px;">' . e($p['brief']['paragraph']) . '</p>';
        if ($p['brief']['stale']) {
            $html .= '<p style="font-size:11px;color:#999;font-style:italic;margin:0 0 12px;">Brief is from a prior day and may be outdated.</p>';
        }
        if ($appUrl !== '') {
            $html .= '<p style="font-size:12px;margin:0 0 20px;"><a href="' . e($appUrl) . '/admin/dashboard" style="color:#1c1c1a;">View full briefing on the dashboard →</a></p>';
        }
    } else {
        $html .= '<p style="font-size:13px;margin:0 0 20px;color:#666;">No AI brief available yet today.</p>';
    }

    $html .= '</td></tr></table>';
    return $html;
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
