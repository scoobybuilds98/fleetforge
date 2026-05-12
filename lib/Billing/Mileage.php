<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * lib/Billing/Mileage.php
 *
 * Single source of truth for mileage math:
 *   • Lease duration in months (used to derive monthly allowance)
 *   • Monthly allowance = estimated_mileage_km / lease_months
 *   • Excess distance and excess charge per period
 *   • km ↔ miles display conversion via lease.km_to_miles_conversion
 *
 * Internal canonical unit is KM everywhere (D-E). All callers store km
 * in the database and convert to miles only when rendering for a lease
 * whose mileage_unit='miles'. Excess charges are computed against
 * mileage_rate_km and stored as monetary values in invoice currency.
 *
 * All monetary math uses bcmath (D16) — no float operators on dollars.
 * Distance math uses float internally but rounds to 2dp when storing,
 * matching the (10,2) odometer column precision (D-A).
 *
 * @session  S-LEASE-MILEAGE
 * @decision D-E (km canonical), D-F (monthly_allowance derivation),
 *           D-G (review gate semantics), D16 (bcmath)
 */
class Mileage
{
    /**
     * Monetary precision used by every charge/credit calculation.
     * Matches existing invoices.excess_charge_amount column scale.
     */
    private const MONEY_SCALE = 2;

    /**
     * Distance precision when persisting to (10,2) columns.
     */
    private const DISTANCE_SCALE = 2;

    /**
     * Default duration assumption when a lease has no end_date — surfaced
     * to managers via audit_log so they know to review carefully (D-F).
     */
    public const OPEN_ENDED_FALLBACK_MONTHS = 12;

    /**
     * Compute lease duration in fractional months between two ISO dates.
     *
     * For a 6-week lease (start=2026-04-01, end=2026-05-13), returns ~1.5,
     * so a 30,000 km allowance becomes ~20,000 km/month — proportional to
     * the actual rental window rather than rounded up to a calendar month.
     *
     * Always returns at least 1.0 to avoid divide-by-zero on same-day or
     * one-day leases.
     *
     * @param string|null $startDate ISO Y-m-d
     * @param string|null $endDate   ISO Y-m-d, may be null for open-ended
     * @return array{months: float, was_open_ended: bool}
     *               months: fractional months (>= 1.0)
     *               was_open_ended: true when end_date was missing and
     *                               OPEN_ENDED_FALLBACK_MONTHS was used
     */
    public static function leaseDurationMonths(?string $startDate, ?string $endDate): array
    {
        if (!$startDate) {
            return ['months' => (float) self::OPEN_ENDED_FALLBACK_MONTHS, 'was_open_ended' => true];
        }

        if (!$endDate) {
            return ['months' => (float) self::OPEN_ENDED_FALLBACK_MONTHS, 'was_open_ended' => true];
        }

        $startTs = strtotime($startDate);
        $endTs   = strtotime($endDate);
        if ($startTs === false || $endTs === false || $endTs < $startTs) {
            return ['months' => 1.0, 'was_open_ended' => false];
        }

        // Days / 30.4375 = avg days per month over a 4-year leap cycle.
        // Slightly more accurate than 30 — keeps a 6-week lease at ~1.38
        // rather than 1.4, and a 30-day lease at ~0.98 → floored to 1.0.
        $days   = ($endTs - $startTs) / 86400 + 1;
        $months = $days / 30.4375;

        return ['months' => max(1.0, $months), 'was_open_ended' => false];
    }

    /**
     * Derive monthly allowance in km for a lease.
     *
     * **S-MILEAGE-2B C4 (D-H refined):** retained pending portal refactor in
     * S-PORTAL-MILEAGE-MODEL-B. Active caller: `app/portal/leases/view.php:80`
     * (Model C residue customer-facing). `app/admin/invoices/show.php:1558`
     * caller dies in S-MILEAGE-2B C6 (D-L Mileage Review card → Drawdown
     * Reconciliation panel conversion). Once both callers gone, delete this
     * method per D-H original intent. Companion helper `Mileage::periodExcess`
     * was deleted in C4 (zero callers post-C3 engine retirement).
     *
     * @param array $lease  row from leases table (needs estimated_mileage_km
     *                       OR estimated_mileage, start_date, end_date)
     * @return array{
     *     allowance_km: float,
     *     lease_months: float,
     *     was_open_ended: bool,
     *     total_estimated_km: float
     * }
     */
    public static function monthlyAllowance(array $lease): array
    {
        // Prefer the dual-unit km column from S-LEASE-UNITS; fall back to
        // the legacy single-unit estimated_mileage if the row pre-dates
        // the dual-unit migration.
        $totalKm = isset($lease['estimated_mileage_km']) && $lease['estimated_mileage_km'] !== null
            ? (float) $lease['estimated_mileage_km']
            : (float) ($lease['estimated_mileage'] ?? 0);

        $duration = self::leaseDurationMonths(
            $lease['start_date'] ?? null,
            $lease['end_date']   ?? null
        );

        $allowance = $duration['months'] > 0
            ? $totalKm / $duration['months']
            : $totalKm;

        return [
            'allowance_km'        => round($allowance, self::DISTANCE_SCALE),
            'lease_months'        => $duration['months'],
            'was_open_ended'      => $duration['was_open_ended'],
            'total_estimated_km'  => $totalKm,
        ];
    }

    // ── periodExcess REMOVED in S-MILEAGE-2B C4 ───────────────────────────
    // Was: compute excess distance and dollar charge for a single billing period.
    // Retired per D-H (zero callers post-C3 InvoiceGenerator drawdown emit).
    // Replaced by direct `distance × rate` math in the C3 drawdown emit block —
    // Model B has no allowance subtraction. See FLEETFORGE_PROGRESS.md
    // S-MILEAGE-2B SESSION LOG entry for the full retirement trace.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Convert km to the lease's primary display unit. If the lease is
     * km-based, returns the input unchanged. If miles-based, multiplies
     * by lease.km_to_miles_conversion (frozen at lease creation).
     *
     * @param float $km
     * @param array $lease  row with mileage_unit + km_to_miles_conversion
     * @return array{value: float, unit: string}  unit is 'km' or 'miles'
     */
    public static function toDisplayUnit(float $km, array $lease): array
    {
        $unit = (string) ($lease['mileage_unit'] ?? 'km');
        if ($unit !== 'miles') {
            return ['value' => round($km, self::DISTANCE_SCALE), 'unit' => 'km'];
        }
        $factor = (float) ($lease['km_to_miles_conversion'] ?? 0.621371);
        if ($factor <= 0) $factor = 0.621371;
        return ['value' => round($km * $factor, self::DISTANCE_SCALE), 'unit' => 'miles'];
    }

    /**
     * Format a km distance for human display in the lease's primary unit
     * with thousands separators and unit suffix.
     */
    public static function formatDistance(float $km, array $lease): string
    {
        $disp = self::toDisplayUnit($km, $lease);
        return number_format($disp['value'], 0, '.', ',') . ' ' . $disp['unit'];
    }
}
