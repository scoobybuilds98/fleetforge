<?php
declare(strict_types=1);

/**
 * cron/invoice_generate_monthly.php
 *
 * Monthly billing cron — generates a full-month draft invoice for every active
 * monthly lease whose next_billing_date has ARRIVED, then advances
 * next_billing_date one month per invoice.
 *
 * ── Schedule (production crontab) ────────────────────────────────────────────
 *   RECOMMENDED:  0 14 1 * *   php /var/www/fleetforge/cron/invoice_generate_monthly.php
 *   (14:00 UTC on the 1st = 06:00 America/Vancouver on the 1st — i.e. the cron
 *    fires AFTER the billing-timezone midnight has rolled to the 1st.)
 *
 *   The historical `0 6 1 * *` UTC line fired at 22:00/23:00 Vancouver on the
 *   prior month's LAST day, so date('Y-m-d') resolved to that prior day and the
 *   exact-match query billed NOTHING (S-CRON-FIX-1 / cron-audit HIGH-1). This
 *   file no longer depends on that exact timing — see D-BILLING-MATCH-LTE — but
 *   the recommended line is still the correct one to install. The crontab lives
 *   on the server and is the operator's to update (see
 *   docs/FLEETFORGE_OPERATOR_FOLLOWUPS.md).
 *
 *   Because billing is now idempotent + catch-up-safe, running MORE often
 *   (e.g. `0 14 * * *` daily) is also safe and makes a missed/mistimed run
 *   self-heal within a day instead of a month.
 *
 * Local test:  php /Users/avi/Documents/fleetforge/cron/invoice_generate_monthly.php
 *              FF_CRON_TODAY=2026-06-15 php cron/invoice_generate_monthly.php   (non-prod date override)
 *
 * ── Correctness model (S-CRON-FIX-1) ────────────────────────────────────────
 *   D-BILLING-CRON-TZ  — "today" is computed in the business timezone via an
 *     explicit DateTimeZone (settings.company.timezone, the same source the
 *     notification digest uses), NOT the ambient PHP default. The calendar day
 *     is therefore correct regardless of where/when the process fires.
 *   D-BILLING-MATCH-LTE — leases are matched with next_billing_date <= today
 *     (not =), and each lease is billed for EVERY missed period in one run
 *     (catch-up). A lease three months behind recovers in one run, not three.
 *     Three guarantees keep this from ever double-billing:
 *       1. the advisory lock (D21) serialises runs;
 *       2. invoice-create + next_billing_date advance commit in ONE transaction
 *          per period (re-read each iteration), so a billed period always moves
 *          the pointer forward;
 *       3. an explicit idempotency guard refuses to emit a second full_month
 *          invoice for a (lease, period) that already has one (e.g. advance
 *          billing pre-generated it) — it just advances the pointer.
 *
 * Requires: config/app.php, includes/db.php, lib/Billing/InvoiceGenerator.php
 * Decisions: D3 (InvoiceGenerator is the only DB writer), D16 (bcmath),
 *            D21 (GET_LOCK advisory lock prevents duplicate runs),
 *            D-BILLING-CRON-TZ, D-BILLING-MATCH-LTE
 * Spec ref: §9 Monthly Billing Cron
 *
 * SAMSARA-3: the cron stamps the linked unit's cron-cached samsara_odometer_km
 *   (kept fresh by the 5-minute samsara_sync cron — no extra live API calls) as
 *   the period-end odometer, with period-start auto-derived from the previous
 *   invoice's end odometer or lease.odometer_start_km. During a multi-period
 *   catch-up the "now" odometer is only attached to the MOST-RECENT period —
 *   older recovered periods have no historical reading and are billed
 *   odometer-less. cumulative_distance_km stays correct (end - odometer_start).
 *
 * Testability: the billing logic lives in ff_run_monthly_billing(string $today)
 *   and the date resolver in ff_monthly_billing_today(); the script-execution
 *   wrapper at the bottom is skipped when FF_MONTHLY_BILLING_INCLUDE is defined,
 *   so tests/_smoke_model_b_lifecycle.php can require this file and drive the
 *   runner with deterministic dates inside BEGIN/ROLLBACK.
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Billing\InvoiceGenerator;

/**
 * Safety cap on how many missed periods a single lease may catch up in one run.
 * 24 months is far beyond any legitimate backlog; the cap exists only so a
 * corrupt/ancient next_billing_date can't spin the inner loop into a runaway.
 */
const FF_MONTHLY_BILLING_MAX_CATCHUP = 24;

/**
 * Resolve the billing "today" (Y-m-d) in the business timezone.
 *
 * WHY: a bare date('Y-m-d') uses the ambient PHP default; under a UTC cron-fire
 * boundary that resolved to the prior month's last day and silently billed
 * nothing. We compute the calendar day in an explicit business timezone so it is
 * correct regardless of where/when the process fires (D-BILLING-CRON-TZ).
 *
 * A FF_CRON_TODAY=YYYY-MM-DD override is honored ONLY outside production (so a
 * stray env var can never shift real billing); the regression smoke and dated
 * operator catch-up runs use it.
 */
function ff_monthly_billing_today(): string
{
    $override = (string) (getenv('FF_CRON_TODAY') ?: '');
    if ($override !== ''
        && APP_ENV !== 'production'
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $override) === 1) {
        return $override;
    }

    $tzName = (string) settings_get('company.timezone', APP_TIMEZONE);
    try {
        $tz = new \DateTimeZone($tzName);
    } catch (\Throwable $e) {
        // Misconfigured tz must never stop billing — fall back to the app tz.
        $tz = new \DateTimeZone(APP_TIMEZONE);
    }
    return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/**
 * Generate all due monthly invoices as of $today (Y-m-d, business tz).
 *
 * Returns ['generated' => int, 'skipped' => int, 'errors' => int]. Throws only
 * on a structural failure (e.g. the lease SELECT) — per-period failures are
 * isolated, audited, and leave next_billing_date pointing at the failed period
 * for the next run to retry.
 *
 * @param string $today Billing-tz calendar day, 'YYYY-MM-DD'.
 * @return array{generated:int,skipped:int,errors:int}
 */
function ff_run_monthly_billing(string $today): array
{
    $generated = 0;
    $skipped   = 0;
    $errors    = 0;

    // Active monthly leases whose billing date has ARRIVED (<= today, not =).
    // The per-unit Samsara odometer snapshot ("now") is read once here and
    // applied only to the most-recent catch-up period below.
    $leases = db_select(
        "SELECT l.id, l.contract_number, l.odometer_start_km,
                l.mileage_tracking_mode,
                u.samsara_odometer_km    AS unit_samsara_odometer_km,
                u.samsara_last_synced_at AS unit_samsara_last_synced_at
           FROM leases l
           LEFT JOIN equipment_units u
                  ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
          WHERE l.status          = 'active'
            AND l.billing_cycle   = 'monthly'
            AND l.next_billing_date <= ?
            AND l.deleted_at IS NULL
          ORDER BY l.id",
        [$today]
    );

    $generator = new InvoiceGenerator();

    foreach ($leases as $lease) {
        $leaseId = (int) $lease['id'];

        // ── Catch-up loop: bill EVERY missed period for this lease this run ───
        $loop = 0;
        for (; $loop < FF_MONTHLY_BILLING_MAX_CATCHUP; $loop++) {
            // Re-read the pointer + a one-month lookahead. BOTH use MySQL month
            // arithmetic — PHP's "+1 month" clamps differently (Jan 31 -> Mar 3)
            // and would SKIP February; DATE_ADD clamps to the month end.
            $cur = db_row(
                "SELECT next_billing_date AS nbd,
                        DATE_ADD(next_billing_date, INTERVAL 1 MONTH) AS next_after
                   FROM leases
                  WHERE id = ? AND status = 'active'
                    AND billing_cycle = 'monthly' AND deleted_at IS NULL",
                [$leaseId]
            );
            if (!$cur || $cur['nbd'] === null || (string) $cur['nbd'] > $today) {
                break; // caught up (or lease no longer eligible)
            }

            $nbd          = (string) $cur['nbd'];
            $isLastPeriod = ((string) $cur['next_after'] > $today); // most-recent due period
            $periodStart  = date('Y-m-01', strtotime($nbd));
            $periodEnd    = date('Y-m-t', strtotime($nbd));

            try {
                // ── Idempotency guard (D-BILLING-MATCH-LTE) ──────────────────
                // Never emit a second full_month invoice for a (lease, period)
                // that already has one — advance billing may have pre-generated
                // it, or a prior partial run did. Heal the pointer and move on.
                $already = db_row(
                    "SELECT id FROM invoices
                      WHERE lease_id = ? AND billing_period_start = ?
                        AND billing_type = 'full_month'
                        AND status <> 'void' AND deleted_at IS NULL
                      LIMIT 1",
                    [$leaseId, $periodStart]
                );

                if ($already) {
                    db_execute(
                        "UPDATE leases
                            SET next_billing_date = DATE_ADD(next_billing_date, INTERVAL 1 MONTH),
                                updated_at        = NOW()
                          WHERE id = ?",
                        [$leaseId]
                    );
                    $skipped++;
                    db_insert('audit_log', [
                        'user_id'      => null,
                        'user_name'    => 'system',
                        'action'       => 'cron',
                        'module'       => 'invoices',
                        'entity_type'  => 'lease',
                        'entity_id'    => $leaseId,
                        'entity_label' => $lease['contract_number'],
                        'notes'        => "invoice_generate_monthly: skipped already-billed period {$periodStart}..{$periodEnd} for lease #{$leaseId}; advanced next_billing_date",
                        'ip_address'   => '127.0.0.1',
                    ]);
                    continue;
                }

                // ── SAMSARA-3: odometer inputs — only for the most-recent period
                // The cached odometer reads "now"; it only validly maps to the
                // period whose end is nearest now. Older recovered periods are
                // billed without an odometer snapshot.
                $odoPeriodEnd   = null;
                $odoPeriodStart = null;
                $odoSource      = null;
                $odoFetchedAt   = null;

                // S-LEASE-MILEAGE-MODE: only feed the cached Samsara odometer into
                // the invoice when this lease is in 'samsara' mode. 'manual' and
                // 'off' leases must NOT have a GPS snapshot applied here — doing so
                // would bypass InvoiceGenerator's central gate (it only falls back
                // to Samsara when the caller leaves odometer null). Leaving these
                // null lets manual leases bill from operator-entered readings and
                // off leases carry no odometer at all.
                $leaseMileageMode = $lease['mileage_tracking_mode'] ?? 'samsara';
                if ($isLastPeriod
                    && $leaseMileageMode === 'samsara'
                    && $lease['unit_samsara_odometer_km'] !== null
                ) {
                    $odoPeriodEnd = (string) $lease['unit_samsara_odometer_km'];
                    $odoSource    = 'gps';
                    $odoFetchedAt = $lease['unit_samsara_last_synced_at'] ?: null;

                    // Period-start priority: previous invoice's end odometer,
                    // else lease.odometer_start_km, else null (first invoice).
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

                // ── Invoice + pointer advance commit atomically (D-BILLING-MATCH-LTE #2)
                // InvoiceGenerator::createFromLease() runs its own db_transaction();
                // the nesting guard in includes/db.php makes it continue this outer
                // transaction, so the invoice and the advance commit together.
                $result = db_transaction(function () use (
                    $generator, $leaseId, $periodStart, $periodEnd,
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
                        'odometer_at_period_start_km' => $odoPeriodStart,
                        'odometer_at_period_end_km'   => $odoPeriodEnd,
                        'odometer_source'             => $odoSource,
                        'odometer_fetched_at'         => $odoFetchedAt,
                    ]);

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
                error_log("[CRON invoice_generate_monthly] Lease #{$leaseId} ({$lease['contract_number']}) period {$periodStart}: " . $e->getMessage());

                db_insert('audit_log', [
                    'user_id'      => null,
                    'user_name'    => 'system',
                    'action'       => 'cron',
                    'module'       => 'system',
                    'entity_type'  => 'lease',
                    'entity_id'    => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'        => "invoice_generate_monthly failed for lease #{$leaseId} period {$periodStart}: " . $e->getMessage(),
                    'ip_address'   => '127.0.0.1',
                ]);
                // Stop catch-up for THIS lease — the pointer still points at the
                // failed period, so the next run retries it. Move to next lease.
                break;
            }
        }

        // A lease that exhausts the cap and is still due is an anomaly worth a
        // visible audit row (likely a corrupt next_billing_date).
        if ($loop >= FF_MONTHLY_BILLING_MAX_CATCHUP) {
            error_log("[CRON invoice_generate_monthly] Lease #{$leaseId} hit catch-up cap (" . FF_MONTHLY_BILLING_MAX_CATCHUP . "); still behind — inspect next_billing_date.");
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'system',
                'entity_type'  => 'lease',
                'entity_id'    => $leaseId,
                'entity_label' => $lease['contract_number'],
                'notes'        => 'invoice_generate_monthly: lease #' . $leaseId . ' hit ' . FF_MONTHLY_BILLING_MAX_CATCHUP . '-period catch-up cap and is still behind; inspect next_billing_date for corruption.',
                'ip_address'   => '127.0.0.1',
            ]);
        }
    }

    return ['generated' => $generated, 'skipped' => $skipped, 'errors' => $errors];
}

// ─────────────────────────────────────────────────────────────────────────────
// Script execution wrapper. Skipped when the file is required for testing
// (FF_MONTHLY_BILLING_INCLUDE defined) so the runner can be driven directly.
// ─────────────────────────────────────────────────────────────────────────────
if (!defined('FF_MONTHLY_BILLING_INCLUDE')) {

    // Operator on/off switch (settings → Intelligence → Scheduled Jobs). When off,
    // the cron exits cleanly without generating any invoices. Inside the execution
    // wrapper (not the top of file) so a test `require` is never gated. S-CRON-TOGGLES.
    if (!cron_enabled('invoice_generate_monthly')) {
        error_log('[CRON] invoice_generate_monthly disabled — skipping.');
        exit(0);
    }

    // D21: Advisory lock prevents two overlapping cron executions from double-invoicing
    $lock = db_row("SELECT GET_LOCK('ff_cron_invoice_generate_monthly', 0) AS ok", []);
    if (!$lock || (int) $lock['ok'] !== 1) {
        // Another instance is running — exit silently (not an error)
        exit(0);
    }

    $exitCode = 0;
    try {
        $today = ff_monthly_billing_today();
        $r     = ff_run_monthly_billing($today);

        $summary = "invoice_generate_monthly completed (today={$today}): {$r['generated']} generated, {$r['skipped']} skipped, {$r['errors']} errors";
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
        \FleetForge\Observability\Sentry::captureException($e);
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
        $exitCode = 1;

    } finally {
        // Always release the advisory lock (D21). Done outside the catch so the
        // release runs before the process exits with $exitCode below — PHP does
        // NOT run finally on exit(), so we exit AFTER this block, not inside it.
        db_execute("SELECT RELEASE_LOCK('ff_cron_invoice_generate_monthly')", []);
    }

    exit($exitCode);
}
