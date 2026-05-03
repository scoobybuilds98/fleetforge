<?php
declare(strict_types=1);

namespace FleetForge\GPS;

/**
 * lib/GPS/OdometerService.php
 *
 * Single source of truth for odometer reads across FleetForge.
 *
 * Wraps SamsaraClient with:
 *   • Staleness detection (>24h triggers manager warning)
 *   • Unified result shape so callers (activate, close, monthly cron,
 *     manual fallback) all branch on identical fields
 *   • Path B enforcement (READ ONLY — never writes to Samsara)
 *
 * Capture is NEVER blocking. If Samsara is unreachable, the unit is
 * unlinked, or the API returns garbage, captureCurrentReading() returns
 * success=false with an error message. The caller continues anyway and
 * surfaces a manual-entry prompt.
 *
 * Result shape from captureCurrentReading():
 * {
 *     success: bool,
 *     odometer_km: ?float,           // populated only on success
 *     source: 'gps'|'manual'|'unavailable',
 *     fetched_at: ?string,           // 'Y-m-d H:i:s' server time
 *     samsara_reading_at: ?string,   // 'Y-m-d H:i:s' if Samsara provided it
 *     is_stale: bool,                // true when reading > 24h old
 *     reading_age_seconds: ?int,
 *     error: ?string,
 * }
 *
 * @session  S-LEASE-MILEAGE
 * @decision Path B (read only — never call Samsara write endpoints)
 */
class OdometerService
{
    /** Reading older than this many hours flags as stale. */
    private const STALENESS_THRESHOLD_HOURS = 24;

    /** Sanity bound — Samsara odometer values outside this range are rejected. */
    private const MIN_ODOMETER_KM = 0;
    private const MAX_ODOMETER_KM = 10_000_000;  // 10M km — spec T19 sanity cap

    private SamsaraClient $client;

    public function __construct(?SamsaraClient $client = null)
    {
        $this->client = $client ?? new SamsaraClient();
    }

    /**
     * Capture the current odometer reading for an equipment unit.
     *
     * @param int $equipmentUnitId
     * @return array  result shape — see file docblock
     */
    public function captureCurrentReading(int $equipmentUnitId): array
    {
        $unit = db_row(
            "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type
               FROM equipment_units
              WHERE id = ? AND deleted_at IS NULL",
            [$equipmentUnitId]
        );

        if (!$unit) {
            return $this->failResult('Equipment unit not found');
        }

        if (empty($unit['samsara_vehicle_id'])) {
            return $this->failResult('Unit not linked to Samsara — manual entry required');
        }

        // Path B: getEntityStats() is the read-only bulk-stats endpoint.
        // No method here ever calls createTrailer / updateTrailer / any
        // POST or PATCH against Samsara — odometer captures are read-only
        // by design and uniformly across all callers.
        $stats = $this->client->getEntityStats(
            (string) ($unit['samsara_entity_type'] ?? 'vehicle'),
            (string) $unit['samsara_vehicle_id']
        );

        $odometerKm = $stats['odometer_km'] ?? null;
        if ($odometerKm === null) {
            return $this->failResult('Samsara unreachable or no odometer data — manual entry required');
        }

        // T19: validate range. Negative = corrupt data, > 10M km = wildly
        // implausible. Either way refuse to persist — fall through to
        // manual entry rather than poison the lease record.
        $odometerKm = (float) $odometerKm;
        if ($odometerKm < self::MIN_ODOMETER_KM || $odometerKm > self::MAX_ODOMETER_KM) {
            return $this->failResult("Samsara returned implausible odometer ({$odometerKm} km) — manual entry required");
        }

        $now = time();

        // Samsara timestamp comes through as ISO8601 in last_connected_at.
        // Parse defensively — a missing or unparseable timestamp counts as
        // stale, since we can't prove freshness.
        $samsaraReadingAt = null;
        $ageSeconds       = null;
        $isStale          = true;

        $rawTime = $stats['last_connected_at'] ?? null;
        if ($rawTime) {
            try {
                $dt = new \DateTime((string) $rawTime);
                $samsaraReadingAt = $dt->format('Y-m-d H:i:s');
                $ageSeconds       = max(0, $now - $dt->getTimestamp());
                $isStale          = $ageSeconds > (self::STALENESS_THRESHOLD_HOURS * 3600);
            } catch (\Throwable) {
                // Parse failure → leave is_stale=true so caller surfaces the warning
            }
        }

        return [
            'success'             => true,
            'odometer_km'         => round($odometerKm, 2),
            'source'              => 'gps',
            'fetched_at'          => date('Y-m-d H:i:s', $now),
            'samsara_reading_at'  => $samsaraReadingAt,
            'is_stale'            => $isStale,
            'reading_age_seconds' => $ageSeconds,
            'error'               => null,
        ];
    }

    /**
     * Return the failure shape with a consistent set of keys so callers
     * can rely on `success === false` and have all other fields present.
     */
    private function failResult(string $reason): array
    {
        return [
            'success'             => false,
            'odometer_km'         => null,
            'source'              => 'unavailable',
            'fetched_at'          => null,
            'samsara_reading_at'  => null,
            'is_stale'            => false,
            'reading_age_seconds' => null,
            'error'               => $reason,
        ];
    }
}
