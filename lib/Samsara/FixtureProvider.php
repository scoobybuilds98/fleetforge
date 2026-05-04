<?php
declare(strict_types=1);

namespace FleetForge\Samsara;

/**
 * lib/Samsara/FixtureProvider.php
 *
 * Hermetic fixture data for SamsaraClient::getDistanceForPeriod
 * (S-MILEAGE-1B / D-G). When the settings flag
 * `samsara.fixture_mode` is '1', SamsaraClient dispatches to
 * FixtureProvider::getDistanceForPeriod() instead of hitting the
 * real Samsara API.
 *
 * 6 canned scenarios cover the warning paths in S-MILEAGE-1B / D-F:
 *   FIX_STD     — Standard 30-day period, daily readings, ~1234 km
 *   FIX_PARKED  — Same first/last reading value, distance=0
 *   FIX_NONE    — Zero readings even in wider window → no_readings_in_period
 *   FIX_RESET   — Last reading < first → gateway_reset_detected
 *   FIX_SPARSE  — 1 reading in period → sparse_readings warning
 *   FIX_GAP     — 7-day gap mid-period → large_gap_detected warning
 *
 * The provider returns the same shape as SamsaraClient's success
 * and failure responses (D-D), so callers cannot tell the
 * difference between fixture and live mode.
 *
 * Used by:
 *   • SamsaraClient::getDistanceForPeriod (when fixture_mode=1)
 *   • tests/_smoke_samsara_distance.php
 *   • Future S-MILEAGE-2 stress tests
 *   • Future S-MILEAGE-5 scenario test pass
 */
class FixtureProvider
{
    /**
     * Dispatch entry point — same signature as SamsaraClient's
     * production method. Falls back to FIX_NONE if the vehicleId
     * doesn't match a known scenario, so unknown IDs degrade to
     * the "unmonitored" failure path.
     */
    public static function getDistanceForPeriod(
        string $samsaraVehicleId,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit = 'km'
    ): array {
        $utcZone   = new \DateTimeZone('UTC');
        $startUtc  = $startUtc->setTimezone($utcZone);
        $endUtc    = $endUtc->setTimezone($utcZone);
        $queriedAt = (new \DateTimeImmutable('now', $utcZone))->format('Y-m-d\TH:i:s\Z');

        if ($unit !== 'km' && $unit !== 'miles') {
            $unit = 'km';
        }

        // Validate range cap — fixture mode must mirror the production
        // contract so tests of period_too_long can pass without HTTP.
        $rangeSeconds = $endUtc->getTimestamp() - $startUtc->getTimestamp();
        if ($rangeSeconds <= 0) {
            return self::failure($unit, 'api_error',
                'endTime must be strictly after startTime.', $queriedAt);
        }
        if ($rangeSeconds > 90 * 86400) {
            $days = (int) ceil($rangeSeconds / 86400);
            return self::failure($unit, 'period_too_long',
                "Period exceeds 90-day cap (got {$days} days).", $queriedAt);
        }
        if ($samsaraVehicleId === '') {
            return self::failure($unit, 'unit_not_in_samsara',
                'Empty samsaraVehicleId.', $queriedAt);
        }

        return match ($samsaraVehicleId) {
            'FIX_STD'    => self::scenarioStandard($startUtc, $endUtc, $unit, $queriedAt),
            'FIX_PARKED' => self::scenarioParked($startUtc, $endUtc, $unit, $queriedAt),
            'FIX_NONE'   => self::failure($unit, 'no_readings_in_period',
                'No obdOdometerMeters or gpsDistanceMeters readings found in period (incl. ±24h wider window). [fixture: FIX_NONE]',
                $queriedAt),
            'FIX_RESET'  => self::scenarioGatewayReset($startUtc, $endUtc, $unit, $queriedAt),
            'FIX_SPARSE' => self::scenarioSparse($startUtc, $endUtc, $unit, $queriedAt),
            'FIX_GAP'    => self::scenarioLargeGap($startUtc, $endUtc, $unit, $queriedAt),
            default      => self::failure($unit, 'unit_not_in_samsara',
                "Vehicle/trailer ID '{$samsaraVehicleId}' not in fixture catalog. Known IDs: FIX_STD, FIX_PARKED, FIX_NONE, FIX_RESET, FIX_SPARSE, FIX_GAP.",
                $queriedAt),
        };
    }

    // --------------------------------------------------------
    // Scenario 1: Standard 30-day period, daily readings,
    // 1234.56 km traveled. No warnings. Source = gps (trailer case).
    // --------------------------------------------------------
    private static function scenarioStandard(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt
    ): array {
        // 1234.56 km = 1,234,560 m
        $distanceMeters = '1234560';
        return self::success(
            $startUtc, $endUtc, $unit, $queriedAt,
            distanceMeters: $distanceMeters,
            source: 'gps',
            firstReadingAt: $startUtc->format('Y-m-d\TH:i:s.000\Z'),
            lastReadingAt:  $endUtc->format('Y-m-d\TH:i:s.000\Z'),
            readingCount:   31,
            warnings:       []
        );
    }

    // --------------------------------------------------------
    // Scenario 2: Parked unit. Same first/last reading. Distance=0.
    // --------------------------------------------------------
    private static function scenarioParked(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt
    ): array {
        return self::success(
            $startUtc, $endUtc, $unit, $queriedAt,
            distanceMeters: '0',
            source: 'gps',
            firstReadingAt: $startUtc->format('Y-m-d\TH:i:s.000\Z'),
            lastReadingAt:  $endUtc->format('Y-m-d\TH:i:s.000\Z'),
            readingCount:   30,
            warnings:       []
        );
    }

    // --------------------------------------------------------
    // Scenario 4: Gateway reset mid-period — last < first.
    // The production code clamps to 0 distance and warns.
    // --------------------------------------------------------
    private static function scenarioGatewayReset(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt
    ): array {
        return self::success(
            $startUtc, $endUtc, $unit, $queriedAt,
            distanceMeters: '0', // production clamps negative to 0
            source: 'gps',
            firstReadingAt: $startUtc->format('Y-m-d\TH:i:s.000\Z'),
            lastReadingAt:  $endUtc->format('Y-m-d\TH:i:s.000\Z'),
            readingCount:   15,
            warnings:       ['gateway_reset_detected']
        );
    }

    // --------------------------------------------------------
    // Scenario 5: Sparse — 1 in-period reading; bookends from the
    // wider window resolve. Distance is real (500 km) but the
    // 'sparse_readings' warning fires.
    // --------------------------------------------------------
    private static function scenarioSparse(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt
    ): array {
        // 500 km = 500,000 m
        return self::success(
            $startUtc, $endUtc, $unit, $queriedAt,
            distanceMeters: '500000',
            source: 'gps',
            // bookendLow from wider window (12h before start)
            firstReadingAt: $startUtc->modify('-12 hours')->format('Y-m-d\TH:i:s.000\Z'),
            // bookendHigh from wider window (12h after end)
            lastReadingAt:  $endUtc->modify('+12 hours')->format('Y-m-d\TH:i:s.000\Z'),
            readingCount:   3, // 1 inside, 2 in wider window bookends
            warnings:       ['reading_outside_period', 'sparse_readings']
        );
    }

    // --------------------------------------------------------
    // Scenario 6: 7-day gap mid-period. Many readings on either
    // side, none in the middle week. 2300 km traveled.
    // --------------------------------------------------------
    private static function scenarioLargeGap(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt
    ): array {
        return self::success(
            $startUtc, $endUtc, $unit, $queriedAt,
            distanceMeters: '2300000',
            source: 'gps',
            firstReadingAt: $startUtc->format('Y-m-d\TH:i:s.000\Z'),
            lastReadingAt:  $endUtc->format('Y-m-d\TH:i:s.000\Z'),
            readingCount:   24,
            warnings:       ['large_gap_detected']
        );
    }

    /**
     * Build a success-shape return value. Distance is converted
     * from meters to the requested unit via bcmath — same code
     * path as production so we exercise D-C precision in tests.
     */
    private static function success(
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $unit,
        string $queriedAt,
        string $distanceMeters,
        string $source,
        string $firstReadingAt,
        string $lastReadingAt,
        int    $readingCount,
        array  $warnings
    ): array {
        $distance = ($unit === 'km')
            ? bcdiv($distanceMeters, '1000',     2)
            : bcdiv($distanceMeters, '1609.344', 2);

        return [
            'distance'         => $distance,
            'unit'             => $unit,
            'source'           => $source,
            'first_reading_at' => $firstReadingAt,
            'last_reading_at'  => $lastReadingAt,
            'reading_count'    => $readingCount,
            'warnings'         => $warnings,
            'queried_at'       => $queriedAt,
        ];
    }

    /**
     * Build a failure-shape return value matching SamsaraClient's
     * production contract.
     */
    private static function failure(
        string $unit,
        string $reason,
        string $detail,
        string $queriedAt
    ): array {
        return [
            'distance'   => null,
            'unit'       => $unit,
            'source'     => 'unavailable',
            'reason'     => $reason,
            'detail'     => $detail,
            'queried_at' => $queriedAt,
        ];
    }
}
