<?php
declare(strict_types=1);

namespace FleetForge\GPS;

// ============================================================
// FleetForge — SamsaraClient
//
// Provides GPS mileage data from the Samsara API.
// Spec §10: TWO features only — tracking URL button and
// mileage auto-fill at lease close. No webhooks, no polling,
// no live maps, no geofences.
//
// getMileageForLease() is the ONLY public method.
// Returns total km driven for a vehicle over a date range.
// Returns null on ANY failure — GPS is never blocking.
//
// Dev mode: if GPS_SAMSARA_API_KEY is blank (local dev),
// returns null immediately without making any HTTP calls.
//
// All failures logged to logs/gps.log, never thrown to callers.
// ============================================================

class SamsaraClient
{
    /** Samsara API base URL */
    private const API_BASE = 'https://api.samsara.com';

    /** HTTP timeout in seconds — spec §10 */
    private const TIMEOUT_SECONDS = 10;

    /** Path to GPS failure log (relative to project root) */
    private const LOG_FILE = 'logs/gps.log';

    private string $apiKey;
    private string $orgId;
    private string $projectRoot;

    public function __construct()
    {
        // API credentials come from .env constants loaded by config/app.php
        $this->apiKey     = defined('GPS_SAMSARA_API_KEY') ? (string) GPS_SAMSARA_API_KEY : '';
        $this->orgId      = defined('GPS_SAMSARA_ORG_ID')  ? (string) GPS_SAMSARA_ORG_ID  : '';
        $this->projectRoot = dirname(__DIR__, 2); // lib/GPS/ → project root
    }

    // --------------------------------------------------------
    // getMileageForLease()
    //
    // Returns the total distance driven (in km, D34 default)
    // for a Samsara vehicle between two dates (inclusive).
    //
    // Used in two places:
    //   1. api/v1/gps/mileage.php — pre-fills close-lease form
    //   2. cron/gps_mileage_sync.php — daily odometer snapshot
    //
    // @param  string $vehicleId  Samsara vehicle ID (equipment_units.gps_device_id)
    // @param  string $start      Start date 'Y-m-d' (lease start date)
    // @param  string $end        End date   'Y-m-d' (today or return date)
    // @return float|null         km driven as float, or null on any failure
    // --------------------------------------------------------
    public function getMileageForLease(string $vehicleId, string $start, string $end): ?float
    {
        // Dev mode: blank API key → return null without HTTP call
        if ($this->apiKey === '' || $this->orgId === '') {
            $this->log('GPS_SKIP', "API keys not configured — returning null (dev mode). vehicleId=$vehicleId");
            return null;
        }

        if ($vehicleId === '') {
            $this->log('GPS_SKIP', "Empty vehicleId — returning null.");
            return null;
        }

        // Samsara vehicle stats API: returns odometer/mileage stats over a time window
        // Endpoint: GET /fleet/vehicles/{id}/stats/history
        // We use startTime/endTime in RFC3339 format (midnight UTC for each day)
        $startRfc = $start . 'T00:00:00Z';
        $endRfc   = $end   . 'T23:59:59Z';

        $url = self::API_BASE . '/fleet/vehicles/' . urlencode($vehicleId)
             . '/stats/history?types=engineTotalIdleMilliseconds,obdOdometerMeters'
             . '&startTime=' . urlencode($startRfc)
             . '&endTime='   . urlencode($endRfc);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'X-Org-Id: '            . $this->orgId,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $this->log('GPS_CURL_ERROR', "vehicleId=$vehicleId start=$start end=$end error=$err");
            return null;
        }

        if ($code !== 200) {
            $this->log('GPS_HTTP_ERROR', "vehicleId=$vehicleId start=$start end=$end HTTP=$code body=" . substr((string)$raw, 0, 500));
            return null;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->log('GPS_PARSE_ERROR', "vehicleId=$vehicleId start=$start end=$end — invalid JSON");
            return null;
        }

        // Extract odometer readings: find first and last obdOdometerMeters entries
        // Samsara returns an array of time-series data points
        $points = $data['data'] ?? [];
        if (!is_array($points) || count($points) < 2) {
            $this->log('GPS_NO_DATA', "vehicleId=$vehicleId start=$start end=$end — insufficient data points (" . count($points) . ")");
            return null;
        }

        // Find the obdOdometerMeters values at start and end of the period
        $firstMeters = null;
        $lastMeters  = null;

        foreach ($points as $point) {
            $meters = $point['obdOdometerMeters'] ?? null;
            if ($meters === null) continue;

            if ($firstMeters === null) $firstMeters = (float) $meters;
            $lastMeters = (float) $meters;
        }

        if ($firstMeters === null || $lastMeters === null || $lastMeters < $firstMeters) {
            $this->log('GPS_INVALID_RANGE', "vehicleId=$vehicleId start=$start end=$end firstMeters=$firstMeters lastMeters=$lastMeters");
            return null;
        }

        // Convert meters → km (D34: km is FleetForge default unit)
        $distanceMeters = $lastMeters - $firstMeters;
        $distanceKm     = $distanceMeters / 1000.0;

        $this->log('GPS_SUCCESS', sprintf(
            "vehicleId=%s start=%s end=%s distance=%.1f km (%.0f m)",
            $vehicleId, $start, $end, $distanceKm, $distanceMeters
        ));

        return round($distanceKm, 1);
    }

    // --------------------------------------------------------
    // getOdometerReading()
    //
    // Returns the latest odometer reading (in km) for a vehicle.
    // Used by the daily GPS sync cron to store absolute readings
    // in mileage_logs.odometer_reading.
    //
    // @param  string $vehicleId  Samsara vehicle ID
    // @return int|null           odometer in km (rounded), or null on failure
    // --------------------------------------------------------
    public function getOdometerReading(string $vehicleId): ?int
    {
        if ($this->apiKey === '' || $this->orgId === '') {
            return null;
        }

        if ($vehicleId === '') {
            return null;
        }

        // Samsara vehicle stats (current): returns latest obdOdometerMeters
        $url = self::API_BASE . '/fleet/vehicles/' . urlencode($vehicleId)
             . '/stats?types=obdOdometerMeters';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'X-Org-Id: '            . $this->orgId,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '' || $code !== 200) {
            $this->log('GPS_ODOMETER_ERROR', "vehicleId=$vehicleId HTTP=$code error=$err");
            return null;
        }

        $data = json_decode((string) $raw, true);

        // Samsara current stats response: data.obdOdometerMeters.value
        $meters = $data['data']['obdOdometerMeters']['value'] ?? null;

        if ($meters === null) {
            $this->log('GPS_ODOMETER_MISSING', "vehicleId=$vehicleId — obdOdometerMeters not in response");
            return null;
        }

        // Convert meters → km, round to nearest km
        return (int) round((float) $meters / 1000.0);
    }

    // --------------------------------------------------------
    // log() — append a timestamped line to logs/gps.log
    // Never throws — logging failure is swallowed silently.
    // --------------------------------------------------------
    private function log(string $level, string $message): void
    {
        try {
            $logPath = $this->projectRoot . '/' . self::LOG_FILE;
            $line    = sprintf(
                "[%s] [%s] %s\n",
                date('Y-m-d H:i:s'),
                $level,
                $message
            );
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging failure must never crash the application
        }
    }
}
