<?php
declare(strict_types=1);

/**
 * cron/compliance_alerts.php
 *
 * Compliance alerts cron — runs nightly at 6:00 AM.
 *
 * Scans all active (non-deleted, non-inactive/decommissioned) equipment units
 * and creates in-app notifications for:
 *   - Expired documents (past today)
 *   - Documents expiring within 7 days  → critical severity
 *   - Documents expiring within 30 days → warning severity
 *
 * Multiple expiring docs on the same unit are grouped into a single notification.
 * Deduplication: does not create a new notification for a unit+type combination
 * that was already notified within the past 24 hours (checks notification_log).
 *
 * Notifications are written to the `notifications` table (in-app) AND one digest
 * email is sent per affected customer via Mailer (customer email branch — CVI-EMAIL-1).
 *
 * Customer email branch:
 *   - Joins equipment_units → active leases → customers → primary portal users.
 *   - Groups all affected units into one digest per customer.
 *   - Respects portal_users.notification_preferences.compliance_expiring (null = opt-in, EC5).
 *   - Dedup: notification_type = 'customer_compliance_alert' (separate from staff branch).
 *   - Requires: ALTER TABLE portal_users ADD COLUMN notification_preferences JSON NULL DEFAULT NULL AFTER is_primary;
 *
 * Crontab (production): 0 6 * * * php /var/www/fleetforge/cron/compliance_alerts.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/compliance_alerts.php
 *
 * Requires: config/app.php, includes/db.php
 * Decisions: D21 (GET_LOCK advisory lock prevents duplicate runs)
 * Spec ref: §7.9 Compliance & Expiry, §Notifications (nightly compliance alerts)
 * Session: S020, CVI-EMAIL-1
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// D21: Advisory lock prevents overlapping runs from creating duplicate notifications
$lock = db_row("SELECT GET_LOCK('ff_cron_compliance', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0); // Another instance is running — exit silently
}

$notified = 0;
$skipped  = 0;   // deduplication skips
$errors   = 0;

try {
    $today       = date('Y-m-d');
    // WHY: Read thresholds from settings so admins can tune alert windows
    $critDays    = (int) settings_get('alerts.compliance_critical_days', '7');
    $warnDays    = (int) settings_get('alerts.compliance_warning_days', '30');
    $inCritical  = date('Y-m-d', strtotime("+{$critDays} days"));
    $inWarning   = date('Y-m-d', strtotime("+{$warnDays} days"));

    // -----------------------------------------------------------------------
    // Fetch all units that have at least one expiry within 30 days OR already expired.
    // Indexes idx_cvi_expiry / idx_reg_expiry / idx_mvi_expiry / idx_ins_expiry
    // make these comparisons efficient (created in PASS-10 schema migration).
    // -----------------------------------------------------------------------
    $units = db_select(
        "SELECT
             eu.id,
             eu.unit_number,
             eu.status,
             eu.cvi_expiry,
             eu.registration_expiry,
             eu.mvi_expiry,
             eu.insurance_expiry
         FROM equipment_units eu
         WHERE eu.deleted_at IS NULL
           AND eu.status NOT IN ('inactive','decommissioned')
           AND (
               (eu.cvi_expiry IS NOT NULL          AND eu.cvi_expiry          <= ?)
               OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry <= ?)
               OR (eu.mvi_expiry IS NOT NULL          AND eu.mvi_expiry          <= ?)
               OR (eu.insurance_expiry IS NOT NULL     AND eu.insurance_expiry     <= ?)
           )
         ORDER BY eu.unit_number ASC",
        [$inWarning, $inWarning, $inWarning, $inWarning]
    );

    foreach ($units as $unit) {
        $unitId     = (int)$unit['id'];
        $unitNumber = $unit['unit_number'];

        // -----------------------------------------------------------------------
        // Build the list of expiring/expired documents for this unit
        // -----------------------------------------------------------------------
        $docChecks = [
            'CVI'          => $unit['cvi_expiry'],
            'Registration' => $unit['registration_expiry'],
            'MVI'          => $unit['mvi_expiry'],
            'Insurance'    => $unit['insurance_expiry'],
        ];

        $alertDocs = [];    // ['doc' => 'CVI', 'expiry' => '2026-01-15', 'urgency' => 'expired|critical|warning']
        $maxSeverity = 'info';

        foreach ($docChecks as $docName => $expiryDate) {
            if ($expiryDate === null) continue;
            if ($expiryDate > $inWarning) continue;   // Only care about ≤30d or already expired

            if ($expiryDate <= $today) {
                $urgency     = 'expired';
                $maxSeverity = 'critical';
            } elseif ($expiryDate <= $inCritical) {
                $urgency     = 'critical';
                if ($maxSeverity !== 'critical') $maxSeverity = 'critical';
            } else {
                $urgency     = 'warning';
                if ($maxSeverity === 'info') $maxSeverity = 'warning';
            }
            $alertDocs[] = ['doc' => $docName, 'expiry' => $expiryDate, 'urgency' => $urgency];
        }

        if (empty($alertDocs)) continue;   // Nothing actionable for this unit

        // -----------------------------------------------------------------------
        // Deduplication: skip if we already notified for this unit+type within 24h.
        // notification_type = 'compliance_alert', entity_type = 'equipment_unit', entity_id = unit id.
        // -----------------------------------------------------------------------
        $recentLog = db_row(
            "SELECT id FROM notification_log
             WHERE entity_type = 'equipment_unit'
               AND entity_id = ?
               AND notification_type = 'compliance_alert'
               AND created_at >= NOW() - INTERVAL 24 HOUR
             LIMIT 1",
            [$unitId]
        );

        if ($recentLog) {
            $skipped++;
            continue;
        }

        // -----------------------------------------------------------------------
        // Build notification message — groups all affected docs for this unit
        // -----------------------------------------------------------------------
        $docLines = [];
        foreach ($alertDocs as $ad) {
            $tag = match($ad['urgency']) {
                'expired'  => '[EXPIRED]',
                'critical' => '[CRITICAL]',
                default    => '[WARNING]',
            };
            $docLines[] = "{$tag} {$ad['doc']}: {$ad['expiry']}";
        }

        $docCount = count($alertDocs);
        $title    = "Compliance: Unit {$unitNumber} — {$docCount} doc" . ($docCount > 1 ? 's' : '') . " require attention";
        $message  = "Unit {$unitNumber} has the following compliance document(s) requiring action:\n" . implode("\n", $docLines);
        $url      = '/compliance?q=' . urlencode($unitNumber);

        // -----------------------------------------------------------------------
        // [NOTIF-1] Fan out via NotificationService — one row per user with
        // compliance.view permission, then write the dedup log entry.
        // -----------------------------------------------------------------------
        try {
            db_transaction(function() use ($unitId, $unitNumber, $title, $message, $url, $maxSeverity) {
                // Pick the right type from severity so the UI can use the
                // correct icon color (compliance.expired = red, expiring_7 = orange,
                // expiring_30 = yellow).
                $type = match($maxSeverity) {
                    'critical' => 'compliance.expired',
                    'warning'  => 'compliance.expiring_7',
                    default    => 'compliance.expiring_30',
                };

                \FleetForge\Notifications\NotificationService::notify(
                    type:       $type,
                    title:      $title,
                    message:    $message,
                    entityType: 'equipment_unit',
                    entityId:   $unitId,
                    url:        '/fleetforge/compliance?q=' . urlencode($unitNumber),
                    severity:   $maxSeverity
                );

                // Write deduplication log entry so we don't re-alert within 24h
                db_insert('notification_log', [
                    'rule_id'           => null,
                    'channel'           => 'in_app',
                    'recipient'         => 'all_staff',
                    'subject'           => $title,
                    'body'              => $message,
                    'entity_type'       => 'equipment_unit',
                    'entity_id'         => $unitId,
                    'notification_type' => 'compliance_alert',
                    'status'            => 'sent',
                    'sent_at'           => date('Y-m-d H:i:s'),
                ]);
            });

            $notified++;

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON compliance_alerts] Unit #{$unitId} ({$unitNumber}): " . $e->getMessage());

            // Per-unit failure should not abort the rest of the run
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'compliance',
                'entity_type'  => 'equipment_unit',
                'entity_id'    => $unitId,
                'entity_label' => $unitNumber,
                'notes'        => "compliance_alerts failed for unit #{$unitId}: " . $e->getMessage(),
                'ip_address'   => '127.0.0.1',
            ]);
        }
    }

    // ===================================================================
    // [CVI-EMAIL-1] Customer email branch
    //
    // One digest email per customer per day grouping all affected units.
    // notification_type = 'customer_compliance_alert' — independent dedup
    // from the staff in-app branch above.
    // ===================================================================
    require_once FF_ROOT . '/lib/Email/templates/customer_compliance_expiring.php';

    $emailNotified = 0;
    $emailSkipped  = 0;
    $emailErrors   = 0;

    // Fetch all affected units that are on an active lease, with customer + primary portal user.
    $customerRows = db_select(
        "SELECT
             eu.id AS unit_id,
             eu.unit_number,
             eu.cvi_expiry,
             eu.registration_expiry,
             eu.mvi_expiry,
             eu.insurance_expiry,
             c.id AS customer_id,
             c.company_name,
             c.email AS customer_email,
             l.contract_number,
             pu.id AS portal_user_id,
             pu.email AS portal_email,
             pu.name AS portal_name,
             pu.notification_preferences
         FROM equipment_units eu
         JOIN leases l
              ON l.equipment_unit_id = eu.id
             AND l.deleted_at IS NULL
             AND l.status = 'active'
         JOIN customers c
              ON c.id = l.customer_id
             AND c.deleted_at IS NULL
         LEFT JOIN portal_users pu
              ON pu.customer_id = c.id
             AND pu.is_primary = 1
             AND pu.status = 'active'
         WHERE eu.deleted_at IS NULL
           AND eu.status NOT IN ('inactive','decommissioned')
           AND (
               (eu.cvi_expiry IS NOT NULL          AND eu.cvi_expiry          <= ?)
               OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry <= ?)
               OR (eu.mvi_expiry IS NOT NULL          AND eu.mvi_expiry          <= ?)
               OR (eu.insurance_expiry IS NOT NULL    AND eu.insurance_expiry    <= ?)
           )
         ORDER BY c.id ASC, eu.unit_number ASC",
        [$inWarning, $inWarning, $inWarning, $inWarning]
    );

    // Group rows by customer_id; collect per-unit affected-doc lists.
    // _unitKeys tracks unit_id → index in 'units' to dedup units that
    // appear on multiple active leases (edge case) and merge their
    // contract numbers rather than emitting a duplicate unit block.
    $byCustomer = [];
    foreach ($customerRows as $row) {
        $cid = (int) $row['customer_id'];

        if (!isset($byCustomer[$cid])) {
            $byCustomer[$cid] = [
                'customer_id'              => $cid,
                'company_name'             => (string) $row['company_name'],
                'customer_email'           => $row['customer_email'],
                'portal_email'             => $row['portal_email'],
                'portal_name'              => $row['portal_name'],
                'notification_preferences' => $row['notification_preferences'],
                'units'                    => [],
                '_unitKeys'                => [],  // unit_id => index in 'units'
            ];
        }

        $docChecks = [
            'CVI'          => $row['cvi_expiry'],
            'Registration' => $row['registration_expiry'],
            'MVI'          => $row['mvi_expiry'],
            'Insurance'    => $row['insurance_expiry'],
        ];
        $unitDocs = [];
        foreach ($docChecks as $docName => $expiryDate) {
            if ($expiryDate === null || $expiryDate > $inWarning) continue;
            $unitDocs[] = ['name' => $docName, 'expiry' => $expiryDate];
        }

        if (!empty($unitDocs)) {
            $unitId = (int) $row['unit_id'];
            if (!isset($byCustomer[$cid]['_unitKeys'][$unitId])) {
                $byCustomer[$cid]['_unitKeys'][$unitId] = count($byCustomer[$cid]['units']);
                $byCustomer[$cid]['units'][] = [
                    'unit_number'      => $row['unit_number'],
                    'unit_id'          => $unitId,
                    'contract_numbers' => [],
                    'docs'             => $unitDocs,
                ];
            }
            $idx = $byCustomer[$cid]['_unitKeys'][$unitId];
            $cn  = (string) ($row['contract_number'] ?? '');
            if ($cn !== '' && !in_array($cn, $byCustomer[$cid]['units'][$idx]['contract_numbers'], true)) {
                $byCustomer[$cid]['units'][$idx]['contract_numbers'][] = $cn;
            }
        }
    }

    // Flatten contract_numbers array → contract_number string, drop internal key
    foreach ($byCustomer as $cid => $cdata) {
        foreach ($byCustomer[$cid]['units'] as $i => $unit) {
            $byCustomer[$cid]['units'][$i]['contract_number'] = implode(', ', $unit['contract_numbers']);
            unset($byCustomer[$cid]['units'][$i]['contract_numbers']);
        }
        unset($byCustomer[$cid]['_unitKeys']);
    }

    $companyName = (string) settings_get('company.name', 'FleetForge');
    $appUrl      = rtrim((string) settings_get('app.url', ''), '/');

    foreach ($byCustomer as $custData) {
        $customerId = $custData['customer_id'];

        // EC2: Skip if no deliverable email address
        $toEmail   = (string) ($custData['portal_email']  ?: $custData['customer_email']);
        $toName    = (string) ($custData['portal_name']   ?: $custData['company_name']);
        if ($toEmail === '') {
            $emailSkipped++;
            continue;
        }

        // EC3: Skip if no actionable units remain after doc filtering
        if (empty($custData['units'])) {
            $emailSkipped++;
            continue;
        }

        // EC5: null notification_preferences = default opt-in
        // EC7: explicit compliance_expiring=false → skip
        if ($custData['notification_preferences'] !== null) {
            $prefs = json_decode((string) $custData['notification_preferences'], true);
            if (is_array($prefs) && ($prefs['compliance_expiring'] ?? true) === false) {
                $emailSkipped++;
                continue;
            }
        }

        // Dedup: skip if we already emailed this customer today
        $recentEmail = db_row(
            "SELECT id FROM notification_log
             WHERE entity_type = 'customer'
               AND entity_id = ?
               AND notification_type = 'customer_compliance_alert'
               AND created_at >= NOW() - INTERVAL 24 HOUR
             LIMIT 1",
            [$customerId]
        );
        if ($recentEmail) {
            $emailSkipped++;
            continue;
        }

        // Determine urgency label for subject line by re-deriving from expiry dates
        $hasExpired  = false;
        $hasCritical = false;
        foreach ($custData['units'] as $u) {
            foreach ($u['docs'] as $d) {
                $expiryDate = (string) ($d['expiry'] ?? '');
                if ($expiryDate <= $today)      $hasExpired  = true;
                elseif ($expiryDate <= $inCritical) $hasCritical = true;
            }
        }
        $urgencyWord = $hasExpired ? 'Expired' : ($hasCritical ? 'Expiring Soon' : 'Upcoming Expiry');
        $unitCount   = count($custData['units']);
        $subject     = "[Action Required] Compliance Documents {$urgencyWord}"
                     . " — {$unitCount} Unit" . ($unitCount > 1 ? 's' : '');

        // Build and wrap email body; customer_name = company_name for formal greeting
        $bodyHtml    = render_customer_compliance_email([
            'customer_name' => $custData['company_name'],
            'company_name'  => $companyName,
            'units'         => $custData['units'],
            'portal_url'    => $appUrl . '/portal/documents',
        ]);
        $wrappedHtml = \FleetForge\Email\EmailService::renderEmailHtml($bodyHtml);

        try {
            $sent = \FleetForge\Notifications\Mailer::send(
                toEmail:  $toEmail,
                toName:   $toName,
                subject:  $subject,
                htmlBody: $wrappedHtml,
            );

            // Always write the dedup log entry regardless of send success
            db_insert('notification_log', [
                'rule_id'           => null,
                'channel'           => 'email',
                'recipient'         => $toEmail,
                'subject'           => $subject,
                'body'              => mb_substr(strip_tags($bodyHtml), 0, 2000),
                'entity_type'       => 'customer',
                'entity_id'         => $customerId,
                'notification_type' => 'customer_compliance_alert',
                'status'            => $sent ? 'sent' : 'failed',
                'error_message'     => $sent ? null : 'Mailer::send() returned false',
                'sent_at'           => $sent ? date('Y-m-d H:i:s') : null,
            ]);

            if ($sent) {
                $emailNotified++;
            } else {
                $emailErrors++;
                error_log("[CRON compliance_alerts] Customer #{$customerId} email send failed");
            }
        } catch (\Throwable $e) {
            $emailErrors++;
            error_log("[CRON compliance_alerts] Customer #{$customerId} email exception: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Summary audit log entry
    // -----------------------------------------------------------------------
    $summary = "compliance_alerts completed: {$notified} notified, {$skipped} skipped (dedup), {$errors} errors"
             . " | customer emails: {$emailNotified} sent, {$emailSkipped} skipped, {$emailErrors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'compliance',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'compliance_alerts',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON compliance_alerts] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'compliance',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'compliance_alerts',
        'notes'        => 'compliance_alerts fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_compliance')", []);
}
