<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * lib/Billing/Mileage.php
 *
 * Historical home of Model A / Model C mileage math helpers. Model B
 * (S-MILEAGE-1 / -2A / -2B / -3 / -3-FIX-0 / -5) replaced the entire
 * surface; every method that lived here has been retired in order:
 *
 *   • periodExcess       — retired in S-MILEAGE-2B C4 (D-H);
 *                          Model B drawdown emit replaces per-period
 *                          excess calculation directly in InvoiceGenerator.
 *   • monthlyAllowance   — retired in S-PORTAL-MILEAGE-MODEL-B C2
 *                          (D154 + D167); portal flipped from Model C
 *                          allowance card to Model B precharge / usage-
 *                          only card. Last caller was app/portal/leases/
 *                          view.php:80; deleted in same commit.
 *   • leaseDurationMonths — retired in S-MILEAGE-HELPERS-CLEANUP C2
 *                          (2026-05-14). Was an internal helper of
 *                          monthlyAllowance; orphaned after its parent's
 *                          deletion. Zero external callers verified via
 *                          K-15 grep pre + post.
 *   • toDisplayUnit       — retired in S-MILEAGE-HELPERS-CLEANUP C2
 *                          (2026-05-14). Was a km↔miles renderer for
 *                          Model C; Model B reports distance values
 *                          directly from invoice_line_items.quantity
 *                          which already store the lease's primary unit.
 *                          Zero external callers verified.
 *   • formatDistance      — retired in S-MILEAGE-HELPERS-CLEANUP C2
 *                          (2026-05-14). Wrapped toDisplayUnit with a
 *                          number_format() call for human display; same
 *                          retirement rationale. Zero external callers
 *                          verified.
 *
 * Class also held 3 private/public constants (MONEY_SCALE,
 * OPEN_ENDED_FALLBACK_MONTHS, DISTANCE_SCALE) that were used only by
 * the methods listed above. All three deleted alongside their callers
 * in S-MILEAGE-HELPERS-CLEANUP C2 per operator-confirmed clean-cleanup
 * scope expansion. Equivalent values now live inline where needed
 * (e.g., 30.4375 days/month avg in any future date arithmetic;
 * D16 bcmath scale=2 / 6 in TaxCalculator and InvoiceGenerator).
 *
 * The class shell is retained as a tombstone — future code that
 * happens to reference `FleetForge\Billing\Mileage` (e.g., via stale
 * `use` statements, IDE autocomplete, or older git checkouts) gets a
 * recognizable class-still-exists shape rather than a class-not-found
 * fatal. If a future session needs to revive any of these methods, see
 * git history at S-MILEAGE-HELPERS-CLEANUP C2.
 *
 * @session  S-LEASE-MILEAGE (original) / S-MILEAGE-HELPERS-CLEANUP (final retirement)
 * @decision D-E (km canonical, S-LEASE-MILEAGE) / D154 + D167 (monthlyAllowance retirement)
 */
class Mileage
{
    // Intentionally empty — see file-level docblock for retirement history.
    // Model B mileage math lives in:
    //   • lib/Billing/InvoiceGenerator.php (drawdown emit + period math)
    //   • lib/Billing/TaxCalculator.php (per-line tax)
    //   • app/portal/leases/view.php (customer-facing usage display)
}
