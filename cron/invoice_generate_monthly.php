<?php
declare(strict_types=1);

/**
 * cron/invoice_generate_monthly.php
 *
 * Monthly billing cron — runs 1st of each month at 6:00 AM.
 * Finds all active leases with billing_cycle='monthly' whose next_billing_date falls
 * today (the 1st), generates a full-month draft invoice via InvoiceGenerator, and
 * advances next_billing_date by one month.
 *
 * Crontab (production): 0 6 1 * * php /var/www/fleetforge/cron/invoice_generate_monthly.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/invoice_generate_monthly.php
 *
 * Requires: config/app.php, includes/db.php, lib/Billing/InvoiceGenerator.php
 * Decisions: D3 (InvoiceGenerator is the only DB writer), D16 (bcmath),
 *            D21 (GET_LOCK advisory lock prevents duplicate runs)
 * Spec ref: §9 Monthly Billing Cron
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

// D21: Advisory lock prevents two overlapping cron executions from double-invoicing
$lock = db_row("SELECT GET_LOCK('ff_cron_invoice_generate_monthly', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    // Another instance is running — exit silently (not an error)
    exit(0);
}

$generated = 0;
$skipped   = 0;
$errors    = 0;

try {
    $today = date('Y-m-d');

    // Find all active monthly leases that are due to be billed today.
    // Uses composite index idx_active_billing (status, billing_cycle, next_billing_date, deleted_at)
    $leases = db_select(
        "SELECT id, contract_number, company_name_snapshot, next_billing_date
         FROM leases
         WHERE status         = 'active'
           AND billing_cycle  = 'monthly'
           AND next_billing_date = ?
           AND deleted_at     IS NULL",
        [$today]
    );

    $generator = new InvoiceGenerator();

    foreach ($leases as $lease) {
        $leaseId   = (int)$lease['id'];
        $billingDt = new \DateTimeImmutable($lease['next_billing_date']);

        // Full-month period: 1st of month to last day of same month
        $periodStart = $billingDt->format('Y-m-01');
        $periodEnd   = $billingDt->format('Y-m-t');

        try {
            // Single outer transaction: invoice creation + next_billing_date advance.
            // InvoiceGenerator::createFromLease() has its own internal db_transaction()
            // call, but the nesting guard in includes/db.php makes it continue the outer
            // transaction safely — both the invoice and the date advance commit together.
            $result = db_transaction(function () use ($generator, $leaseId, $periodStart, $periodEnd, $today) {

                $inv = $generator->createFromLease([
                    'lease_id'          => $leaseId,
                    'period_start'      => $periodStart,
                    'period_end'        => $periodEnd,
                    'billing_type'      => 'full_month',  // flat monthly rate — no pro-rating formula
                    'invoice_type'      => 'regular',
                    'generation_source' => 'cron',
                    'auto_generated'    => 1,
                    'created_by'        => null,           // system — no user_id for cron
                ]);

                // Advance next_billing_date by exactly one month (e.g. Apr 1 → May 1)
                db_execute(
                    "UPDATE leases
                     SET next_billing_date = DATE_ADD(next_billing_date, INTERVAL 1 MONTH),
                         updated_at        = NOW()
                     WHERE id = ?",
                    [$leaseId]
                );

                return $inv;
            });

            $generated++;

            // Per-invoice audit entry (outside transaction — informational only)
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'create',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $result['invoice_id'],
                'entity_label' => $result['invoice_number'],
                'notes'        => "Cron generated monthly invoice {$result['invoice_number']} for lease #{$leaseId} ({$periodStart} to {$periodEnd})",
                'ip_address'   => '127.0.0.1',
            ]);

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON invoice_generate_monthly] Lease #{$leaseId} ({$lease['contract_number']}): " . $e->getMessage());

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'system',
                'entity_type'  => 'lease',
                'entity_id'    => $leaseId,
                'entity_label' => $lease['contract_number'],
                'notes'        => "invoice_generate_monthly failed for lease #{$leaseId}: " . $e->getMessage(),
                'ip_address'   => '127.0.0.1',
            ]);
            // Continue to next lease — one failure must not block the rest
        }
    }

    $summary = "invoice_generate_monthly completed: {$generated} generated, {$skipped} skipped, {$errors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'invoice_generate_monthly',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    error_log("[CRON invoice_generate_monthly] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'invoice_generate_monthly',
        'notes'        => 'invoice_generate_monthly fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    // Always release the advisory lock, even on fatal error (D21)
    db_execute("SELECT RELEASE_LOCK('ff_cron_invoice_generate_monthly')", []);
}
