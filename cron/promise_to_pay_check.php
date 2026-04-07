<?php
declare(strict_types=1);

/**
 * cron/promise_to_pay_check.php
 *
 * Promise-to-pay daily checker — runs nightly.
 *
 * Scans all pending promises whose promise_date has passed without
 * a corresponding payment and marks them as 'broken'. Creates
 * in-app notifications so AR staff can follow up.
 *
 * WHY: Spec §5 Collections workflow requires daily cron to detect
 * broken promises and alert staff. A promise is considered broken
 * when promise_date < today and status is still 'pending'.
 *
 * Crontab (production): 30 7 * * * php /var/www/fleetforge/cron/promise_to_pay_check.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/promise_to_pay_check.php
 *
 * @session S031
 */

require_once dirname(__DIR__) . '/config/app.php';

// Advisory lock prevents overlapping runs
$lock = db_row("SELECT GET_LOCK('ff_cron_promise_check', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$broken   = 0;
$notified = 0;
$errors   = 0;

try {
    // -----------------------------------------------------------------------
    // Find all pending promises whose promise_date has passed.
    // WHY: If promise_date < today and status is still 'pending', the
    // customer did not pay as promised. Mark as 'broken' so AR can act.
    // -----------------------------------------------------------------------
    $expiredPromises = db_select(
        "SELECT p.*, c.company_name, i.invoice_number
         FROM acc_promise_to_pay p
         JOIN customers c ON c.id = p.customer_id
         LEFT JOIN invoices i ON i.id = p.invoice_id
         WHERE p.status = 'pending'
           AND p.promise_date < CURDATE()",
        []
    );

    foreach ($expiredPromises as $promise) {
        $promiseId = (int)$promise['id'];

        try {
            // Mark as broken
            db_update('acc_promise_to_pay', [
                'status' => 'broken',
            ], 'id = ?', [$promiseId]);

            db_insert('audit_log', [
                'user_id'     => null,
                'user_name'   => 'system',
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'promise_to_pay',
                'entity_id'   => $promiseId,
                'old_values'  => json_encode(['status' => 'pending']),
                'new_values'  => json_encode(['status' => 'broken']),
                'notes'       => "Promise broken — {$promise['company_name']} promised \${$promise['promised_amount']} by {$promise['promise_date']}"
                               . ($promise['invoice_number'] ? " for {$promise['invoice_number']}" : ''),
                'ip_address'  => '127.0.0.1',
            ]);

            $broken++;

            // Create notification for AR staff
            $invoiceRef = $promise['invoice_number'] ? " ({$promise['invoice_number']})" : '';
            db_insert('notifications', [
                'user_id'     => null,
                'rule_id'     => null,
                'title'       => "Broken Promise: {$promise['company_name']}",
                'message'     => "{$promise['company_name']} promised \${$promise['promised_amount']} by {$promise['promise_date']}{$invoiceRef} — payment not received.",
                'url'         => '/accounting/collections?customer_id=' . $promise['customer_id'],
                'entity_type' => 'promise_to_pay',
                'entity_id'   => $promiseId,
                'severity'    => 'warning',
                'is_read'     => 0,
            ]);

            $notified++;

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON promise_to_pay_check] Promise #{$promiseId}: " . $e->getMessage());
        }
    }

    // Summary
    $summary = "promise_to_pay_check completed: {$broken} marked broken, {$notified} notified, {$errors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'promise_to_pay_check',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    error_log("[CRON promise_to_pay_check] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'promise_to_pay_check',
        'notes'        => 'promise_to_pay_check fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_promise_check')", []);
}
