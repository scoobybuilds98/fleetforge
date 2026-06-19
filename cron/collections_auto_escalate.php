<?php
declare(strict_types=1);

/**
 * cron/collections_auto_escalate.php
 *
 * Collections auto-escalation cron — runs nightly.
 *
 * Scans customers with overdue invoices and ESCALATES their collection_status
 * one tier when threshold is crossed:
 *   - oldest overdue ≥ 15 days AND current_status = 'current'  →  'watch'
 *   - oldest overdue ≥ 45 days AND current_status = 'watch'    →  'collections'
 *
 * NEVER auto-reverts. Per D-I (S-CRON-3 / D57), de-escalation is a manual
 * decision — a manager reviews the customer relationship before un-flagging
 * even if AR clears overnight. 90+ day notification still fires (collections
 * → legal is also manual).
 *
 * Per transition (inside one db_transaction):
 *   1. UPDATE customers SET collection_status = ?
 *   2. INSERT acc_collection_notes with [AUTO-ESCALATION] prefix
 *   3. INSERT audit_log status_change entry
 *
 * Notification fan-out: NotificationService::notify (replaces the pre-fix
 * raw db_insert into notifications). Routes to managers + accountants +
 * super_admins via the 'accounting.*' type prefix mapping.
 *
 * S-FIX-3 changes (this session):
 *   • Removed all auto-revert paths (D-I compliance)
 *   • Added acc_collection_notes write per transition
 *   • Replaced raw notifications inserts with NotificationService::notify
 *   • Wrapped each transition in db_transaction
 *
 * Crontab (production): 0 8 * * * php /var/www/fleetforge/cron/collections_auto_escalate.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/collections_auto_escalate.php
 *
 * @session S031 (original) / S-FIX-3 (D-I compliance + acc_collection_notes + NotificationService)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('collections_auto_escalate')) { error_log('[CRON] collections_auto_escalate disabled - skipping.'); exit(0); }

// Advisory lock prevents overlapping runs
$lock = db_row("SELECT GET_LOCK('ff_cron_collections_escalate', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$escalated  = 0;
$notified   = 0;
$errors     = 0;
$skipped90  = 0;

try {
    // -----------------------------------------------------------------------
    // Find all active customers and their max days overdue across unpaid
    // invoices. WHY: max determines tier (a single 90-day invoice means
    // 'collections' regardless of newer invoices' ages).
    // -----------------------------------------------------------------------
    $customers = db_select(
        "SELECT c.id, c.company_name, c.collection_status,
                COALESCE(MAX(DATEDIFF(CURDATE(), i.due_date)), 0) AS max_days_overdue,
                COALESCE(SUM(i.balance_due), 0) AS total_ar
         FROM customers c
         LEFT JOIN invoices i ON i.customer_id = c.id
             AND i.deleted_at IS NULL
             AND i.status IN ('sent','overdue','partially_paid')
             AND i.balance_due > 0
             AND i.due_date < CURDATE()
         WHERE c.deleted_at IS NULL AND c.status = 'active'
         GROUP BY c.id, c.company_name, c.collection_status",
        []
    );

    foreach ($customers as $cust) {
        $custId        = (int)$cust['id'];
        $name          = (string)$cust['company_name'];
        $currentStatus = (string)$cust['collection_status'];
        $maxDays       = (int)$cust['max_days_overdue'];
        $totalAR       = (string)$cust['total_ar'];

        try {
            // ---------------------------------------------------------------
            // Skip terminal-or-manual statuses. legal and written_off are
            // manager-set only; never touched by the cron.
            // ---------------------------------------------------------------
            if (in_array($currentStatus, ['legal', 'written_off'], true)) {
                // Continue to 90-day notification check below.
                $targetStatus = $currentStatus;
            }

            // ---------------------------------------------------------------
            // Determine ESCALATION target. The cron only steps the ladder
            // upward; reverts are manual. (S-FIX-3 / D-I)
            // ---------------------------------------------------------------
            $targetStatus = match (true) {
                $currentStatus === 'current' && $maxDays >= 15 && $maxDays <  45 => 'watch',
                $currentStatus === 'current' && $maxDays >= 45                   => 'watch',          // Step once per run; next run promotes to collections
                $currentStatus === 'watch'   && $maxDays >= 45                   => 'collections',
                default                                                          => $currentStatus,   // No transition (includes any "would-revert" path)
            };

            // ---------------------------------------------------------------
            // Apply transition if status moved. Single db_transaction for
            // the customer UPDATE + acc_collection_notes + audit_log.
            // ---------------------------------------------------------------
            if ($targetStatus !== $currentStatus) {
                db_transaction(function () use ($custId, $name, $currentStatus, $targetStatus, $maxDays, $totalAR) {
                    db_update('customers', [
                        'collection_status' => $targetStatus,
                    ], 'id = ?', [$custId]);

                    // [AUTO-ESCALATION] prefix marks system-generated notes
                    // for UI differentiation. Do not change without updating
                    // the customer profile collections-tab renderer.
                    $noteText = "[AUTO-ESCALATION] Collection status auto-changed from "
                              . "{$currentStatus} to {$targetStatus} by nightly cron. "
                              . "Trigger: oldest overdue invoice {$maxDays} days. "
                              . "Total AR: \${$totalAR}.";
                    db_insert('acc_collection_notes', [
                        'customer_id'    => $custId,
                        'invoice_id'     => null,
                        'note_date'      => date('Y-m-d'),
                        'contact_method' => 'other',
                        'contact_person' => null,
                        'note'           => $noteText,
                        'outcome'        => 'other',
                        'follow_up_date' => null,
                        'created_by'     => null,
                    ]);

                    db_insert('audit_log', [
                        'user_id'      => null,
                        'user_name'    => 'system',
                        'action'       => 'status_change',
                        'module'       => 'customers',
                        'entity_type'  => 'customer',
                        'entity_id'    => $custId,
                        'entity_label' => $name,
                        'old_values'   => json_encode(['collection_status' => $currentStatus]),
                        'new_values'   => json_encode(['collection_status' => $targetStatus]),
                        'notes'        => "Auto-escalated by collections_auto_escalate cron — oldest invoice overdue {$maxDays} days, total AR \${$totalAR}",
                        'ip_address'   => '127.0.0.1',
                    ]);
                });

                $escalated++;

                // -----------------------------------------------------------
                // Fan out via NotificationService — routes to accountants +
                // super_admins via the 'accounting.*' type prefix mapping
                // in lib/Notifications/NotificationService.php. Severity
                // escalates with the new status.
                // -----------------------------------------------------------
                $severity = $targetStatus === 'collections' ? 'critical' : 'warning';
                \FleetForge\Notifications\NotificationService::notify(
                    type:       "accounting.collection_status_change",
                    title:      "Collection status: {$name} → {$targetStatus}",
                    message:    "{$name} auto-escalated from {$currentStatus} to {$targetStatus}. "
                              . "Oldest overdue: {$maxDays} days. Total AR: \${$totalAR}.",
                    entityType: 'customer',
                    entityId:   $custId,
                    url:        '/fleetforge/accounting/collections?customer_id=' . $custId,
                    severity:   $severity
                );
                $notified++;
            }

            // ---------------------------------------------------------------
            // 90+ day notification (regardless of status change). Manager
            // decides legal escalation; cron just keeps the warning visible.
            // 7-day dedup via notification_log.
            // ---------------------------------------------------------------
            if ($maxDays >= 90) {
                $recentNotif = db_row(
                    "SELECT id FROM notification_log
                     WHERE entity_type = 'customer'
                       AND entity_id = ?
                       AND notification_type = 'collections_90day'
                       AND created_at >= NOW() - INTERVAL 7 DAY
                     LIMIT 1",
                    [$custId]
                );

                if (!$recentNotif) {
                    \FleetForge\Notifications\NotificationService::notify(
                        type:       'accounting.collections_90day',
                        title:      "90+ Days Overdue: {$name}",
                        message:    "{$name} has invoices {$maxDays} days overdue. "
                                  . "Consider legal action or bad debt write-off. Total AR: \${$totalAR}.",
                        entityType: 'customer',
                        entityId:   $custId,
                        url:        '/fleetforge/accounting/collections?customer_id=' . $custId,
                        severity:   'critical'
                    );

                    db_insert('notification_log', [
                        'rule_id'           => null,
                        'channel'           => 'in_app',
                        'recipient'         => 'all_staff',
                        'subject'           => "90+ Days Overdue: {$name}",
                        'body'              => "Customer {$custId} has invoices {$maxDays} days overdue.",
                        'entity_type'       => 'customer',
                        'entity_id'         => $custId,
                        'notification_type' => 'collections_90day',
                        'status'            => 'sent',
                        'sent_at'           => date('Y-m-d H:i:s'),
                    ]);
                    $notified++;
                } else {
                    $skipped90++;
                }
            }

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON collections_auto_escalate] Customer #{$custId}: " . $e->getMessage());
        }
    }

    $summary = "collections_auto_escalate completed: escalated={$escalated} notified={$notified} "
             . "skipped_90day_dedup={$skipped90} errors={$errors}";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'collections_auto_escalate',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON collections_auto_escalate] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'collections_auto_escalate',
        'notes'        => 'collections_auto_escalate fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_collections_escalate')", []);
}
