<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * lib/Billing/BillingExceptions.php
 *
 * S-BILLING-EXCEPTIONS — the durable "couldn't bill this one, look at it
 * later" queue behind batch invoicing.
 *
 * A batch run is normally mixed: most leases bill, a few can't. Before
 * this, those failures existed only in the HTTP response and vanished the
 * moment the operator navigated away — so the practical outcome was that
 * they were forgotten and the customer never got billed. Generation now
 * flags them here and they persist until someone deals with them.
 *
 * Two calls, deliberately symmetric, so a batch never leaves the queue
 * stale in either direction:
 *   flag()    — a lease could NOT be billed for a period.
 *   clear()   — a lease WAS billed for that period, so any open flag is
 *               self-resolved. Without this, fixing a rate and re-running
 *               would bill the lease correctly and STILL leave a scary
 *               open exception sitting there forever.
 *
 * Both are best-effort by design: flagging is bookkeeping ABOUT billing,
 * never a reason to fail billing itself. A failure here is logged and
 * swallowed — an invoice that generated fine must not be rolled back
 * because we couldn't write a note about a different lease.
 *
 * @session S-BILLING-EXCEPTIONS
 */
final class BillingExceptions
{
    private function __construct() {}

    /**
     * Record (or refresh) an open exception for a lease/period.
     *
     * One row per (lease, period) — uq_lease_period. A repeat failure
     * updates the reason and bumps flagged_count rather than duplicating,
     * and a previously resolved/ignored row RE-OPENS so a regression is
     * visible again instead of hiding under an old resolution.
     */
    public static function flag(
        int $leaseId,
        ?int $customerId,
        string $periodStart,
        string $periodEnd,
        string $reason,
        string $source = 'batch_generate',
        ?int $batchRunId = null,
        ?int $userId = null
    ): void {
        try {
            \db_execute(
                "INSERT INTO invoice_billing_exceptions
                    (lease_id, customer_id, period_start, period_end, reason, source,
                     batch_run_id, status, flagged_count, last_flagged_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 1, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                    reason          = VALUES(reason),
                    source          = VALUES(source),
                    batch_run_id    = VALUES(batch_run_id),
                    status          = 'open',
                    resolution_note = NULL,
                    resolved_by     = NULL,
                    resolved_at     = NULL,
                    flagged_count   = flagged_count + 1,
                    last_flagged_at = NOW(),
                    deleted_at      = NULL,
                    updated_at      = NOW()",
                [$leaseId, $customerId, $periodStart, $periodEnd, $reason, $source, $batchRunId, $userId]
            );
        } catch (\Throwable $e) {
            // Never let bookkeeping break billing.
            \error_log("[BillingExceptions::flag] lease #{$leaseId} {$periodStart}: " . $e->getMessage());
        }
    }

    /**
     * Auto-resolve any open exception for a lease/period that has now
     * billed successfully. Silent no-op when there was never a flag.
     */
    public static function clear(int $leaseId, string $periodStart, string $periodEnd, ?int $userId = null): void
    {
        try {
            \db_execute(
                "UPDATE invoice_billing_exceptions
                    SET status          = 'resolved',
                        resolution_note = 'Automatically resolved — the lease billed successfully on a later run.',
                        resolved_by     = ?,
                        resolved_at     = NOW(),
                        updated_at      = NOW()
                  WHERE lease_id = ? AND period_start = ? AND period_end = ?
                    AND status = 'open' AND deleted_at IS NULL",
                [$userId, $leaseId, $periodStart, $periodEnd]
            );
        } catch (\Throwable $e) {
            \error_log("[BillingExceptions::clear] lease #{$leaseId} {$periodStart}: " . $e->getMessage());
        }
    }

    /** Open-exception count, for badges. */
    public static function openCount(): int
    {
        try {
            $r = \db_row("SELECT COUNT(*) AS c FROM invoice_billing_exceptions WHERE status = 'open' AND deleted_at IS NULL");
            return (int) ($r['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
