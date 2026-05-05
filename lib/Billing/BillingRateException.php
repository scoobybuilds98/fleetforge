<?php
declare(strict_types=1);

/**
 * lib/Billing/BillingRateException.php
 *
 * Domain exception thrown when the billing engine refuses to compute or
 * write a zero base_rental amount from a non-zero billing period.
 *
 * Throwing fail-loud is deliberate: silently producing $0 from a bad rate
 * was the upstream defect found in the 2026-05-06 audit (INV-2026-00086).
 * The lease save validation (api/v1/leases/create.php D132) prevents bad
 * data from entering the system in the normal path; this exception is the
 * seatbelt for any code path that bypasses that validation (cron, fixtures,
 * direct SQL insert, future amendments).
 *
 * Caller contract: InvoiceGenerator catches BillingRateException, logs to
 * the error log + Sentry as ERROR with lease + period context, and refuses
 * to create the invoice. The exception is never silenced.
 *
 * Required by: lib/Billing/ProRateCalculator.php, lib/Billing/InvoiceGenerator.php
 * Defines: FleetForge\Billing\BillingRateException
 *
 * Decisions: D132 (rate-tier-completeness invariant — the upstream rule
 *            this exception backstops)
 * Spec ref:  S-BILLING-RATE-FIX
 */

namespace FleetForge\Billing;

class BillingRateException extends \RuntimeException
{
    /**
     * @param string               $message  Human-readable diagnostic for logs.
     * @param string               $method   Rate method that triggered: 'daily', 'weekly', 'weekly_capped', 'monthly', 'full_month'.
     * @param int                  $days     Day count of the billing period.
     * @param string               $daily    Daily rate (string decimal).
     * @param string               $weekly   Weekly rate (string decimal).
     * @param string               $monthly  Monthly rate (string decimal).
     * @param array<string,mixed>  $context  Caller-supplied extras: lease_id, period_start, period_end, etc.
     */
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly int    $days,
        public readonly string $daily,
        public readonly string $weekly,
        public readonly string $monthly,
        public readonly array  $context = []
    ) {
        parent::__construct($message);
    }
}
