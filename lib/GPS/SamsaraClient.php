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
    // getVehicleLocations()
    //
    // Returns GPS locations for ALL vehicles in the Samsara org.
    // Uses the fleet-wide stats endpoint with types=gps,obdOdometerMeters.
    // Handles cursor-based pagination to fetch all vehicles.
    //
    // Each element in the returned array has:
    //   id, name, gps { time, latitude, longitude, headingDegrees,
    //                    speedMilesPerHour, reverseGeo { formattedLocation } },
    //   obdOdometerMeters { time, value }
    //
    // @return array|null  Array of vehicle data, or null on any failure
    // --------------------------------------------------------
    public function getVehicleLocations(): ?array
    {
        if ($this->apiKey === '' || $this->orgId === '') {
            $this->log('GPS_SKIP', 'API keys not configured — returning null (dev mode)');
            return null;
        }

        $allVehicles = [];
        $cursor = null;
        $maxPages = 10; // Safety limit: 10 pages × 50/page = 500 vehicles max

        for ($i = 0; $i < $maxPages; $i++) {
            $url = self::API_BASE . '/fleet/vehicles/stats?types=gps,obdOdometerMeters';
            if ($cursor) {
                $url .= '&after=' . urlencode($cursor);
            }

            $response = $this->apiRequest($url);
            if ($response === null) {
                $this->log('GPS_LOCATIONS_ERROR', 'API request failed on page ' . ($i + 1));
                return null;
            }

            foreach ($response['data'] ?? [] as $vehicle) {
                $allVehicles[] = $vehicle;
            }

            // Cursor-based pagination (Samsara standard)
            $hasMore = $response['pagination']['hasNextPage'] ?? false;
            if (!$hasMore) break;
            $cursor = $response['pagination']['endCursor'] ?? null;
            if (!$cursor) break;
        }

        $this->log('GPS_LOCATIONS_SUCCESS', sprintf('Fetched %d vehicle locations', count($allVehicles)));
        return $allVehicles;
    }

    // --------------------------------------------------------
    // getVehicleLocation()
    //
    // Returns GPS location + stats for a SINGLE Samsara vehicle.
    // Uses the per-vehicle stats endpoint.
    //
    // @param  string $vehicleId  Samsara vehicle ID (equipment_units.gps_device_id)
    // @return array|null  Vehicle stat data, or null on failure
    // --------------------------------------------------------
    public function getVehicleLocation(string $vehicleId): ?array
    {
        if ($this->apiKey === '' || $this->orgId === '' || $vehicleId === '') {
            return null;
        }

        $url = self::API_BASE . '/fleet/vehicles/' . urlencode($vehicleId)
             . '/stats?types=gps,obdOdometerMeters,fuelPercents';

        $response = $this->apiRequest($url);
        return $response['data'] ?? null;
    }

    // --------------------------------------------------------
    // testConnection()
    //
    // Tests the Samsara API connection by listing one vehicle.
    // Returns a structured result for the Settings → Integrations
    // connection test UI.
    //
    // @return array{success: bool, message: string, details: array}
    // --------------------------------------------------------
    public function testConnection(): array
    {
        if ($this->apiKey === '' || $this->orgId === '') {
            return [
                'success' => false,
                'message' => 'API key or Organization ID not configured.',
                'details' => [],
            ];
        }

        $url = self::API_BASE . '/fleet/vehicles?limit=1';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return [
                'success' => false,
                'message' => "Connection failed: {$err}",
                'details' => ['http_code' => $code],
            ];
        }

        if ($code === 401 || $code === 403) {
            return [
                'success' => false,
                'message' => 'Authentication failed. Check your API key and Organization ID.',
                'details' => ['http_code' => $code],
            ];
        }

        if ($code !== 200) {
            return [
                'success' => false,
                'message' => "Unexpected response: HTTP {$code}",
                'details' => ['http_code' => $code, 'body' => substr((string)$raw, 0, 200)],
            ];
        }

        $data = json_decode((string) $raw, true);
        $vehicleCount = count($data['data'] ?? []);
        $hasMore = $data['pagination']['hasNextPage'] ?? false;

        return [
            'success' => true,
            'message' => 'Connected to Samsara successfully.',
            'details' => [
                'http_code'      => 200,
                'vehicles_found' => $hasMore ? '1+' : (string)$vehicleCount,
                'org_id'         => $this->orgId,
            ],
        ];
    }

    // --------------------------------------------------------
    // apiRequest() — shared HTTP helper for all Samsara calls.
    // Returns parsed JSON array on success, null on any failure.
    // Centralizes curl setup, error handling, and logging.
    // --------------------------------------------------------
    private function apiRequest(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $this->log('GPS_API_CURL_ERROR', "url={$url} error={$err}");
            return null;
        }

        if ($code !== 200) {
            $this->log('GPS_API_HTTP_ERROR', "url={$url} HTTP={$code} body=" . substr((string)$raw, 0, 500));
            return null;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->log('GPS_API_PARSE_ERROR', "url={$url} — invalid JSON");
            return null;
        }

        return $data;
    }

    // --------------------------------------------------------
    // buildHeaders() — standard Samsara API request headers
    // --------------------------------------------------------
    private function buildHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'X-Org-Id: '            . $this->orgId,
            'Accept: application/json',
        ];
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
