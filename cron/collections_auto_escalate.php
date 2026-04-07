<?php
declare(strict_types=1);

/**
 * cron/collections_auto_escalate.php
 *
 * Collections auto-escalation cron — runs nightly.
 *
 * Scans customers with overdue invoices and auto-escalates their
 * collection_status based on the longest overdue invoice:
 *   - 15+ days overdue → 'watch'
 *   - 45+ days overdue → 'collections' (with notification to manager)
 *   - 90+ days overdue → notification only (legal status is manual)
 *
 * Also de-escalates customers back to 'current' when they no longer
 * have any overdue invoices (all paid or written off).
 *
 * WHY: Spec §5 Collections workflow requires automatic escalation so
 * overdue accounts don't fall through the cracks. Legal status is
 * intentionally manual (legal/compliance decision, not automatable).
 *
 * Crontab (production): 0 7 * * * php /var/www/fleetforge/cron/collections_auto_escalate.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/collections_auto_escalate.php
 *
 * @session S031
 */

require_once dirname(__DIR__) . '/config/app.php';

// Advisory lock prevents overlapping runs
$lock = db_row("SELECT GET_LOCK('ff_cron_collections_escalate', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$escalated   = 0;
$deescalated = 0;
$notified    = 0;
$errors      = 0;

try {
    $today = date('Y-m-d');

    // -----------------------------------------------------------------------
    // Find all active customers and their max days overdue across all unpaid invoices.
    // WHY: We compute max_days_overdue per customer to determine the correct
    // escalation tier. A single 90-day invoice means 'collections', not 'watch'.
    // -----------------------------------------------------------------------
    $customers = db_select(
        "SELECT c.id, c.company_name, c.collection_status,
                MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_days_overdue
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
        $currentStatus = $cust['collection_status'];
        $maxDays       = (int)($cust['max_days_overdue'] ?? 0);

        try {
            // ---------------------------------------------------------------
            // Determine target status based on max days overdue
            // WHY: Legal and written_off are manual-only — never auto-set.
            // ---------------------------------------------------------------
            if ($maxDays >= 45) {
                $targetStatus = 'collections';
            } elseif ($maxDays >= 15) {
                $targetStatus = 'watch';
            } else {
                $targetStatus = 'current';
            }

            // Skip if already at legal/written_off (manual-only statuses)
            if (in_array($currentStatus, ['legal', 'written_off'], true)) {
                continue;
            }

            // Don't de-escalate from collections to watch (only to current)
            if ($currentStatus === 'collections' && $targetStatus === 'watch') {
                $targetStatus = 'collections';
            }

            // Apply status change if different from current
            $statusChanged = ($currentStatus !== $targetStatus);
            if ($statusChanged) {
                db_update('customers', [
                    'collection_status' => $targetStatus,
                ], 'id = ?', [$custId]);

                db_insert('audit_log', [
                    'user_id'     => null,
                    'user_name'   => 'system',
                    'action'      => 'status_change',
                    'module'      => 'accounting',
                    'entity_type' => 'customer',
                    'entity_id'   => $custId,
                    'old_values'  => json_encode(['collection_status' => $currentStatus]),
                    'new_values'  => json_encode(['collection_status' => $targetStatus]),
                    'notes'       => "Auto-escalated {$cust['company_name']}: {$currentStatus} → {$targetStatus} ({$maxDays} days overdue)",
                    'ip_address'  => '127.0.0.1',
                ]);

                if ($targetStatus === 'current') {
                    $deescalated++;
                } else {
                    $escalated++;
                }
            }

            // Notification for collections escalation (45+ days, only on status change)
            if ($statusChanged && $targetStatus === 'collections') {
                db_insert('notifications', [
                    'user_id'     => null,
                    'rule_id'     => null,
                    'title'       => "Collections: {$cust['company_name']} escalated",
                    'message'     => "{$cust['company_name']} has been auto-escalated to 'collections' status. "
                                   . "Oldest overdue invoice is {$maxDays} days past due.",
                    'url'         => '/accounting/collections?customer_id=' . $custId,
                    'entity_type' => 'customer',
                    'entity_id'   => $custId,
                    'severity'    => 'warning',
                    'is_read'     => 0,
                ]);
                $notified++;
            }

            // 90-day notification (regardless of status change)
            if ($maxDays >= 90) {
                // Deduplication: skip if we already notified within 7 days
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
                    db_insert('notifications', [
                        'user_id'     => null,
                        'rule_id'     => null,
                        'title'       => "90+ Days Overdue: {$cust['company_name']}",
                        'message'     => "{$cust['company_name']} has invoices {$maxDays} days overdue. "
                                       . "Consider legal action or bad debt write-off.",
                        'url'         => '/accounting/collections?customer_id=' . $custId,
                        'entity_type' => 'customer',
                        'entity_id'   => $custId,
                        'severity'    => 'critical',
                        'is_read'     => 0,
                    ]);

                    db_insert('notification_log', [
                        'rule_id'           => null,
                        'channel'           => 'in_app',
                        'recipient'         => 'all_staff',
                        'subject'           => "90+ Days Overdue: {$cust['company_name']}",
                        'body'              => "Customer {$custId} has invoices {$maxDays} days overdue.",
                        'entity_type'       => 'customer',
                        'entity_id'         => $custId,
                        'notification_type' => 'collections_90day',
                        'status'            => 'sent',
                        'sent_at'           => date('Y-m-d H:i:s'),
                    ]);
                    $notified++;
                }
            }

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON collections_auto_escalate] Customer #{$custId}: " . $e->getMessage());
        }
    }

    // Summary
    $summary = "collections_auto_escalate completed: {$escalated} escalated, {$deescalated} de-escalated, {$notified} notified, {$errors} errors";
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
