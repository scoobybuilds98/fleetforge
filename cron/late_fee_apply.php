<?php
declare(strict_types=1);

/**
 * cron/late_fee_apply.php
 *
 * Late fee application cron — runs nightly at 6:30 AM.
 * Finds all overdue invoices that have not yet had a late fee applied and calls
 * InvoiceGenerator::generateLateFeeInvoice() for each. The generator handles rule
 * lookup, grace period check, math (via LateFeeEngine), invoice creation, and all
 * denormalized counter updates in a single transaction per invoice.
 *
 * Skipped invoices (no rule, grace period, already applied) are counted but not
 * treated as errors — the generator returns a 'skipped' flag for these cases.
 *
 * Crontab (production): 30 6 * * * php /var/www/fleetforge/cron/late_fee_apply.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/late_fee_apply.php
 *
 * Requires: config/app.php, includes/db.php, lib/Billing/InvoiceGenerator.php
 * Decisions: D3 (InvoiceGenerator is the only DB writer), D16 (bcmath),
 *            D21 (GET_LOCK advisory lock prevents duplicate runs)
 * Spec ref: §9 Late Fees, cron/late_fee_apply.php
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Billing\InvoiceGenerator;

// D21: Advisory lock prevents two concurrent runs from double-charging late fees
$lock = db_row("SELECT GET_LOCK('ff_cron_late_fee_apply', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$applied = 0;
$skipped = 0;
$errors  = 0;

try {
    // Find overdue invoices not yet charged a late fee.
    // late_fee_applied = 0 ensures idempotency: no double-charging if cron re-runs.
    // The FOR UPDATE locking happens inside generateLateFeeInvoice() per invoice.
    $invoices = db_select(
        "SELECT id, invoice_number, customer_id
         FROM invoices
         WHERE status           = 'overdue'
           AND late_fee_applied = 0
           AND deleted_at       IS NULL",
        []
    );

    $generator = new InvoiceGenerator();

    foreach ($invoices as $invoice) {
        $invoiceId = (int)$invoice['id'];

        try {
            // D3: All DB writes happen inside InvoiceGenerator — the cron only orchestrates.
            // Generator returns ['skipped' => true/false, ...]. It never throws for business
            // logic skips (no rule, grace period) — only for unexpected DB/system errors.
            $result = $generator->generateLateFeeInvoice($invoiceId);

            if ($result['skipped']) {
                $skipped++;
                // Debug-level log for skipped invoices (not an error)
                error_log("[CRON late_fee_apply] Invoice #{$invoiceId} ({$invoice['invoice_number']}) skipped: " . ($result['reason'] ?? 'unknown'));
            } else {
                $applied++;

                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'create',
                    'module'       => 'invoices',
                    'entity_type'  => 'invoice',
                    'entity_id'    => $result['invoice_id'],
                    'entity_label' => $result['invoice_number'],
                    'notes'        => "Cron applied late fee: {$result['invoice_number']} (\${$result['fee_amount']}) on overdue invoice #{$invoiceId} ({$invoice['invoice_number']})",
                    'ip_address'   => '127.0.0.1',
                ]);
            }

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON late_fee_apply] Invoice #{$invoiceId} ({$invoice['invoice_number']}): " . $e->getMessage());

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'system',
                'entity_type'  => 'invoice',
                'entity_id'    => $invoiceId,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "late_fee_apply failed for invoice #{$invoiceId}: " . $e->getMessage(),
                'ip_address'   => '127.0.0.1',
            ]);
            // Continue — one invoice failure must not block the rest
        }
    }

    $summary = "late_fee_apply completed: {$applied} applied, {$skipped} skipped, {$errors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'late_fee_apply',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log("[CRON late_fee_apply] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'system',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'late_fee_apply',
        'notes'        => 'late_fee_apply fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_late_fee_apply')", []);
}
