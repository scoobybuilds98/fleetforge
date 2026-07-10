<?php
declare(strict_types=1);

/**
 * cron/invoice_overdue.php
 *
 * Overdue invoice cron — runs daily at 07:00 UTC (prod crontab verified 2026-07-10).
 * Finds all 'sent' and 'partially_paid' invoices whose due_date is in the past
 * and flips them to 'overdue'. Each status change is wrapped in its own transaction
 * with an audit_log entry. Also expires credit notes past their expires_at
 * (S-AUDIT-BILLING-ENGINE-1 #20) so stale credit can't keep being applied.
 *
 * Crontab (production): 0 7 * * * php /var/www/fleetforge/cron/invoice_overdue.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/invoice_overdue.php
 *
 * Requires: config/app.php, includes/db.php
 * Decisions: D21 (GET_LOCK advisory lock prevents duplicate runs)
 * Spec ref: §17 Invoice State Machine — sent/partially_paid → overdue (nightly cron)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('invoice_overdue')) { error_log('[CRON] invoice_overdue disabled - skipping.'); exit(0); }

// D21: Advisory lock prevents overlapping runs from marking the same invoices twice
$lock = db_row("SELECT GET_LOCK('ff_cron_invoice_overdue', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$marked = 0;
$errors = 0;

try {
    $today = \FleetForge\Accounting\AccountingService::businessToday(); // S-AUDIT-BILLING-ENGINE-1 #18: business tz (was server tz — diverged from the grace math when company.timezone ≠ APP_TIMEZONE)

    // Fetch all invoices that should flip to overdue.
    // Uses index idx_status_due_deleted (status, due_date, deleted_at).
    // partially_paid → overdue is a valid transition per the invoice state machine.
    $invoices = db_select(
        "SELECT id, invoice_number, customer_id, lease_id, status, due_date
         FROM invoices
         WHERE status      IN ('sent', 'partially_paid')
           AND due_date     < ?
           AND deleted_at   IS NULL",
        [$today]
    );

    foreach ($invoices as $invoice) {
        $invoiceId = (int)$invoice['id'];

        try {
            db_transaction(function () use ($invoice, $invoiceId, $today) {
                // Update invoice status → overdue
                db_execute(
                    "UPDATE invoices
                     SET status     = 'overdue',
                         updated_at = NOW()
                     WHERE id = ?
                       AND status IN ('sent', 'partially_paid')
                       AND deleted_at IS NULL",
                    [$invoiceId]
                );

                // Audit log inside the same transaction (FIX #19 pattern: audit stays atomic)
                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'status_change',
                    'module'       => 'invoices',
                    'entity_type'  => 'invoice',
                    'entity_id'    => $invoiceId,
                    'entity_label' => $invoice['invoice_number'],
                    'old_values'   => json_encode(['status' => $invoice['status']]),
                    'new_values'   => json_encode(['status' => 'overdue']),
                    'notes'        => "Invoice {$invoice['invoice_number']} marked overdue (due {$invoice['due_date']}, status was {$invoice['status']})",
                    'ip_address'   => '127.0.0.1',
                ]);

                // [NOTIF-1] Fan out invoice.overdue to staff with invoice view
                try {
                    $daysPast = max(1, (int) floor((strtotime($today) - strtotime($invoice['due_date'])) / 86400));
                    \FleetForge\Notifications\NotificationService::notify(
                        type:       'invoice.overdue',
                        title:      "Invoice {$invoice['invoice_number']} is overdue",
                        message:    "Invoice {$invoice['invoice_number']} is overdue — {$daysPast} day" . ($daysPast === 1 ? '' : 's') . " past due",
                        entityType: 'invoice',
                        entityId:   $invoiceId,
                        url:        '/fleetforge/invoices/show?id=' . $invoiceId,
                        severity:   'warning'
                    );
                    if (!empty($invoice['customer_id'])) {
                        \FleetForge\Notifications\NotificationService::notifyPortal(
                            type:       'invoice.overdue',
                            customerId: (int) $invoice['customer_id'],
                            title:      "Invoice overdue — please contact us",
                            message:    "Invoice {$invoice['invoice_number']} is overdue. Please contact us to arrange payment.",
                            entityType: 'invoice',
                            entityId:   $invoiceId,
                            url:        '/fleetforge/portal/invoices/view?id=' . $invoiceId,
                            severity:   'warning'
                        );
                    }
                } catch (\Throwable $notifErr) {
                    error_log('[NOTIF invoice.overdue] ' . $notifErr->getMessage());
                }
            });

            $marked++;

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON invoice_overdue] Invoice #{$invoiceId} ({$invoice['invoice_number']}): " . $e->getMessage());

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'system',
                'entity_type'  => 'invoice',
                'entity_id'    => $invoiceId,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "invoice_overdue failed for invoice #{$invoiceId}: " . $e->getMessage(),
                'ip_address'   => '127.0.0.1',
            ]);
            // Continue — one invoice failure must not block the rest
        }
    }

    $summary = "invoice_overdue completed: {$marked} marked overdue, {$errors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'invoice_overdue',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON invoice_overdue] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'invoice_overdue',
        'notes'        => 'invoice_overdue fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_invoice_overdue')", []);
}

// ── S-AUDIT-BILLING-ENGINE-1 #20: expire past-date credit notes ─────────────
// status='expired' had NO writer anywhere — the ENUM value, the index filter,
// and the KPI count were all decorative while expired-by-date CNs stayed
// 'active'. Flip live CNs whose expires_at has passed (apply.php also refuses
// them at apply time; this keeps the list/KPI surfaces honest).
try {
    $expired = db_execute(
        "UPDATE credit_notes SET status = 'expired', updated_at = NOW()
          WHERE status IN ('active','partially_used')
            AND expires_at IS NOT NULL AND expires_at < ?
            AND voided_at IS NULL AND deleted_at IS NULL",
        [$today]
    );
    if ($expired > 0) {
        error_log("[CRON invoice_overdue] expired {$expired} past-date credit note(s)");
    }
} catch (\Throwable $e) {
    error_log('[CRON invoice_overdue] credit-note expiry flip failed: ' . $e->getMessage());
}

