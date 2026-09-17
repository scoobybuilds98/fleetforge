<?php
declare(strict_types=1);

namespace FleetForge\GPS;

/**
 * lib/GPS/BusinessDayWindow.php — S-GPS-LOCAL-WINDOW
 *
 * THE one conversion from a business-date range (billing_period_start/end,
 * lease start/return dates — plain `Y-m-d` DATEs that mean a day in the
 * company's local calendar) to the UTC instant pair the Samsara history API
 * is queried with.
 *
 * Why this exists: billing and getMileageForLease() used to anchor the window
 * on UTC midnight (`new DateTimeImmutable($date.' 00:00:00', UTC)`). For a
 * Pacific company, "Sep 1 → Sep 30" then really meant Aug 31 5pm → Sep 30
 * 5pm local, so anything driven on the evening of the last day was billed on
 * the NEXT invoice. Lease totals survived across contiguous periods, but
 * per-invoice mileage (usage/true-up lines, precharge drawdown, the close-time
 * final period) shifted by up to 8 hours of driving.
 *
 * Window shape — half-open [start 00:00 local, (end + 1 day) 00:00 local):
 *   - The exclusive next-midnight end makes contiguous periods partition time
 *     exactly: period N's end instant IS period N+1's start instant, so
 *     SamsaraClient's bookend selection (last reading ≤ start / first reading
 *     ≥ end) hands the same boundary reading to both — no 1-second gap (the
 *     old 23:59:59 end) and no overlap.
 *   - Adding one calendar day in LOCAL time (not +86400s) gives 23h/25h days
 *     on the DST transitions, which is what "the whole local day" means.
 *
 * Timezone source: settings.company.timezone, falling back to APP_TIMEZONE —
 * the same resolution AccountingService::businessToday() and the monthly
 * invoice cron use to decide WHICH period to bill, so the period boundaries
 * and the GPS window can never disagree about what "a day" is.
 *
 * Callers: lib/Billing/InvoiceGenerator.php (Samsara distance fallback),
 * SamsaraClient::getMileageForLease() (api/v1/gps/mileage.php close-form
 * pre-fill), api/v1/samsara/period_distance.php (date-only windows).
 * Guarded by tests/_smoke_samsara_business_day_window.php.
 */
final class BusinessDayWindow
{
    /**
     * Resolve the business timezone (settings.company.timezone → APP_TIMEZONE).
     * An unparseable setting falls back rather than throwing — a typo in
     * Settings must not stop billing.
     */
    public static function timezone(): \DateTimeZone
    {
        $fallback = defined('APP_TIMEZONE') ? (string) \APP_TIMEZONE : 'America/Vancouver';
        $tzName   = function_exists('settings_get')
            ? (string) (\settings_get('company.timezone', $fallback) ?? $fallback)
            : $fallback;

        try {
            return new \DateTimeZone($tzName !== '' ? $tzName : $fallback);
        } catch (\Throwable) {
            return new \DateTimeZone($fallback);
        }
    }

    /**
     * Convert an inclusive business-date range to its UTC instant window.
     *
     * @param  string             $startDate First business day, strict 'Y-m-d'
     * @param  string             $endDate   Last business day (inclusive), strict 'Y-m-d'
     * @param  \DateTimeZone|null $tz        Override zone (tests); null = business tz
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
     *         Both in UTC; `end` is EXCLUSIVE (local midnight after $endDate).
     * @throws \InvalidArgumentException on a non-calendar date or end < start
     */
    public static function toUtc(string $startDate, string $endDate, ?\DateTimeZone $tz = null): array
    {
        $tz  = $tz ?? self::timezone();
        $utc = new \DateTimeZone('UTC');

        $startLocal = self::parseDate($startDate, $tz, 'start');
        $endLocal   = self::parseDate($endDate, $tz, 'end');

        if ($endLocal < $startLocal) {
            throw new \InvalidArgumentException("Business-day window end {$endDate} is before start {$startDate}.");
        }

        return [
            'start' => $startLocal->setTimezone($utc),
            // '+1 day' on a LOCAL datetime keeps wall-clock midnight across DST.
            'end'   => $endLocal->modify('+1 day')->setTimezone($utc),
        ];
    }

    /**
     * Parse a strict 'Y-m-d' as local midnight in $tz. The round-trip check
     * rejects overflow dates PHP would otherwise silently roll ('2026-02-30'
     * → Mar 2), which would move a billing window without anyone noticing.
     */
    private static function parseDate(string $date, \DateTimeZone $tz, string $label): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Business-day window {$label} '{$date}' is not a valid Y-m-d date.");
        }
        return $dt;
    }
}
