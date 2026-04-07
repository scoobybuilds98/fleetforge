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
 * SAMSARA-3: cron now pulls cron-cached samsara_odometer_km from the linked
 *            equipment unit and passes it to the generator as period-end
 *            odometer, with period-start auto-derived from either the
 *            previous invoice's end odometer or lease.odometer_start_km.
 *            The Samsara odometer used here is the one kept fresh by the
 *            5-minute samsara_sync cron — no extra live API calls, no
 *            rate-limit risk. Source is marked 'gps' when samsara_odometer_km
 *            is populated, and odometer_fetched_at is stamped with the unit's
 *            samsara_last_synced_at so there's an audit trail for exactly
 *            which sync tick the value came from. The cron does NOT care
 *            whether the business bills in advance or in arrears — it just
 *            captures whatever the current cached odometer reads and labels
 *            it accurately. Users who bill in advance will see period_distance
 *            represent "miles accrued since last invoice" rather than "miles
 *            in the billed month"; users who bill in arrears get dead-accurate
 *            in-month mileage. Either way the cumulative_distance_km is
 *            always correct (end - lease.odometer_start_km).
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
    // SAMSARA-3: LEFT JOIN equipment_units to pull the cron-cached odometer + last sync
    // so we can stamp per-invoice odometer + distance in the same loop. LEFT JOIN (not
    // INNER) so leases whose unit was deleted after creation still bill — they'll just
    // skip the odometer fields and behave exactly as before this change.
    $leases = db_select(
        "SELECT l.id, l.contract_number, l.company_name_snapshot, l.next_billing_date,
                l.odometer_start_km,
                u.samsara_odometer_km AS unit_samsara_odometer_km,
                u.samsara_last_synced_at AS unit_samsara_last_synced_at
         FROM leases l
         LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
         WHERE l.status         = 'active'
           AND l.billing_cycle  = 'monthly'
           AND l.next_billing_date = ?
           AND l.deleted_at     IS NULL",
        [$today]
    );

    $generator = new InvoiceGenerator();

    foreach ($leases as $lease) {
        $leaseId   = (int)$lease['id'];
        $billingDt = new \DateTimeImmutable($lease['next_billing_date']);

        // Full-month period: 1st of month to last day of same month
        $periodStart = $billingDt->format('Y-m-01');
        $periodEnd   = $billingDt->format('Y-m-t');

        // ── SAMSARA-3: resolve odometer inputs for this lease ─────
        // Only capture odometer data when the linked unit actually has a
        // cached samsara_odometer_km value — leases without GPS-tracked
        // units stay odometer-less exactly as before.
        $odoPeriodEnd   = null;
        $odoPeriodStart = null;
        $odoSource      = null;
        $odoFetchedAt   = null;

        if ($lease['unit_samsara_odometer_km'] !== null) {
            // Period-end = whatever the unit's cron-cached odometer reads
            // right now. Semantically this is "odometer at the moment the
            // billing cron ran" — accurate for arrears billing, still
            // useful (== miles accrued since last invoice) for advance.
            $odoPeriodEnd = (string) $lease['unit_samsara_odometer_km'];
            $odoSource    = 'gps';
            $odoFetchedAt = $lease['unit_samsara_last_synced_at'] ?: null;

            // Period-start priority (same rule as manual invoice create + lease close):
            //   1. Previous invoice's odometer_at_period_end_km
            //   2. Else lease.odometer_start_km
            //   3. Else null (first invoice, no starting odo captured)
            $prev = db_row(
                "SELECT odometer_at_period_end_km
                   FROM invoices
                  WHERE lease_id = ? AND deleted_at IS NULL
                    AND odometer_at_period_end_km IS NOT NULL
                  ORDER BY billing_period_end DESC, id DESC LIMIT 1",
                [$leaseId]
            );
            if ($prev && $prev['odometer_at_period_end_km'] !== null) {
                $odoPeriodStart = (string) $prev['odometer_at_period_end_km'];
            } elseif ($lease['odometer_start_km'] !== null) {
                $odoPeriodStart = (string) $lease['odometer_start_km'];
            }
        }

        try {
            // Single outer transaction: invoice creation + next_billing_date advance.
            // InvoiceGenerator::createFromLease() has its own internal db_transaction()
            // call, but the nesting guard in includes/db.php makes it continue the outer
            // transaction safely — both the invoice and the date advance commit together.
            $result = db_transaction(function () use (
                $generator, $leaseId, $periodStart, $periodEnd, $today,
                $odoPeriodStart, $odoPeriodEnd, $odoSource, $odoFetchedAt
            ) {

                $inv = $generator->createFromLease([
                    'lease_id'          => $leaseId,
                    'period_start'      => $periodStart,
                    'period_end'        => $periodEnd,
                    'billing_type'      => 'full_month',  // flat monthly rate — no pro-rating formula
                    'invoice_type'      => 'regular',
                    'generation_source' => 'cron',
                    'auto_generated'    => 1,
                    'created_by'        => null,           // system — no user_id for cron
                    // SAMSARA-3: odometer + distance (all null when unit isn't Samsara-linked)
                    'odometer_at_period_start_km' => $odoPeriodStart,
                    'odometer_at_period_end_km'   => $odoPeriodEnd,
                    'odometer_source'             => $odoSource,
                    'odometer_fetched_at'         => $odoFetchedAt,
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
