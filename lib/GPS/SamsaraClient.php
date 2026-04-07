<?php
declare(strict_types=1);

namespace FleetForge\GPS;

// ============================================================
// FleetForge — SamsaraClient
//
// Samsara API wrapper. Two generations of methods live here:
//
// (1) S026 legacy methods for mileage + basic location:
//     getMileageForLease, getOdometerReading, getVehicleLocation,
//     getVehicleLocations, testConnection.
//
// (2) SAMSARA-1 methods for full mapping + 5-min live sync:
//     getVehicles, getVehicleStats, getVehicleOdometer,
//     pingVehicle. These power the manual mapping UI, the
//     /tracking Fleet Tracking module, and the 5-min sync cron.
//
// All public methods return null / an empty array on failure —
// GPS data is NEVER blocking for the rest of the app. Every
// failure is logged to logs/gps.log and swallowed.
//
// Credentials lookup order [INT-1]:
//   1. settings table FIRST  — settings_get('gps.samsara_api_key')
//   2. .env file  SECOND     — env('GPS_SAMSARA_API_KEY')
// This lets the Settings → Integrations UI override .env
// without a redeploy. Empty settings value → fall through.
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
        // INT-1: settings table FIRST, then fall back to .env.
        // settings_get() returns null when the row exists with an empty
        // value OR when the row is missing — both cases must fall back,
        // so use the ?: operator (truthy check) rather than ??.
        $this->apiKey = (string) (
            settings_get('gps.samsara_api_key')
            ?: env('GPS_SAMSARA_API_KEY', '')
        );
        $this->orgId = (string) (
            settings_get('gps.samsara_org_id')
            ?: env('GPS_SAMSARA_ORG_ID', '')
        );
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
        // Dev mode: blank API key → return null without HTTP call.
        // Org ID is optional (single-org tokens omit it).
        if ($this->apiKey === '') {
            $this->log('GPS_SKIP', "API key not configured — returning null (dev mode). vehicleId=$vehicleId");
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
        if ($this->apiKey === '') {
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
        if ($this->apiKey === '') {
            $this->log('GPS_SKIP', 'API key not configured — returning null (dev mode)');
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
        if ($this->apiKey === '' || $vehicleId === '') {
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
        // INT-1: distinguish "missing key" from "wrong key" so the
        // Settings UI can give a useful next step. Org ID is optional
        // for some Samsara accounts (single-org tokens) — only the
        // API key is hard-required.
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'message' => 'API key not configured. Please add your Samsara API key in Settings → Integrations.',
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
                'message' => 'API key is invalid. Please check your Samsara API key in Settings → Integrations.',
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

    // ============================================================
    // SAMSARA-1 METHODS
    //
    // The methods below power the manual mapping UI, the Fleet
    // Tracking module, and the 5-minute sync cron. They return
    // structured arrays (never throw). On failure they return
    // an empty array / null and log the error.
    // ============================================================

    // --------------------------------------------------------
    // getVehicles()
    //
    // Returns ALL vehicles in the Samsara account with their
    // static identifiers. Used to populate the manual-mapping
    // dropdown on the equipment unit Samsara tab and the list
    // view on the Fleet Tracking module.
    //
    // Handles cursor-based pagination — Samsara caps page size
    // at 50 and expects &after={endCursor} for subsequent pages.
    //
    // @return array  List of vehicles, each:
    //     {
    //         id:             string,       // Samsara vehicle ID
    //         name:           string,       // Display name
    //         vin:            string|null,
    //         serial_number:  string|null,  // Asset serial
    //         gateway_id:     string|null,  // Gateway device ID
    //         tags:           array,        // Tag strings
    //     }
    // --------------------------------------------------------
    public function getVehicles(): array
    {
        if ($this->apiKey === '') {
            $this->log('GPS_SKIP', 'API key not configured — getVehicles returning []');
            return [];
        }

        $allVehicles = [];
        $cursor = null;
        $maxPages = 20; // 20 * 50 = 1000 vehicles cap — safety guard

        for ($i = 0; $i < $maxPages; $i++) {
            $url = self::API_BASE . '/fleet/vehicles?limit=50';
            if ($cursor) {
                $url .= '&after=' . urlencode($cursor);
            }

            $response = $this->apiRequest($url);
            if ($response === null) {
                // Hard failure — return what we have so UI still works
                $this->log('GPS_VEHICLES_ERROR', 'apiRequest returned null on page ' . ($i + 1));
                return $allVehicles;
            }

            foreach ($response['data'] ?? [] as $v) {
                // Normalize to our schema so callers do not touch raw Samsara JSON
                $tags = [];
                foreach (($v['tags'] ?? []) as $t) {
                    if (isset($t['name'])) $tags[] = (string) $t['name'];
                }
                $allVehicles[] = [
                    'id'            => (string) ($v['id']           ?? ''),
                    'name'          => (string) ($v['name']         ?? ''),
                    'vin'           => isset($v['vin']) && $v['vin'] !== '' ? (string) $v['vin'] : null,
                    'serial_number' => isset($v['serial'])           ? (string) $v['serial']           : null,
                    'gateway_id'    => isset($v['gateway']['serial']) ? (string) $v['gateway']['serial'] : null,
                    'make'          => isset($v['make'])             ? (string) $v['make']             : null,
                    'model'         => isset($v['model'])            ? (string) $v['model']            : null,
                    'year'          => isset($v['year'])             ? (string) $v['year']             : null,
                    'license_plate' => isset($v['licensePlate'])     ? (string) $v['licensePlate']     : null,
                    'tags'          => $tags,
                ];
            }

            $hasMore = $response['pagination']['hasNextPage'] ?? false;
            if (!$hasMore) break;
            $cursor = $response['pagination']['endCursor'] ?? null;
            if (!$cursor) break;
        }

        $this->log('GPS_VEHICLES_SUCCESS', sprintf('Fetched %d vehicles', count($allVehicles)));
        return $allVehicles;
    }

    // --------------------------------------------------------
    // getVehicleStats()
    //
    // Returns current stats for ONE vehicle: GPS + odometer +
    // battery + fuel. Used by the sync cron and by the "Sync
    // Now" button on the equipment Samsara tab.
    //
    // @param  string $vehicleId  Samsara vehicle ID
    // @return array  Normalized stats. Empty array on failure.
    //     {
    //         gps: {lat, lng, speed_kph, heading, address, time},
    //         odometer_km: float,
    //         battery_pct: int|null,
    //         battery_charging: bool|null,
    //         fuel_pct: int|null,
    //         power_source: string|null,
    //         check_in_mode: string|null,
    //         last_connected_at: string|null,
    //     }
    // --------------------------------------------------------
    public function getVehicleStats(string $vehicleId): array
    {
        if ($this->apiKey === '' || $vehicleId === '') {
            return [];
        }

        // Samsara fleet-wide stats endpoint filtered to one vehicle.
        // We request every telemetry type we care about in a single
        // call to minimise HTTP round-trips for the 5-min sync cron.
        $types = 'gps,obdOdometerMeters,fuelPercents';
        $url   = self::API_BASE . '/fleet/vehicles/stats'
               . '?types=' . urlencode($types)
               . '&vehicleIds=' . urlencode($vehicleId);

        $response = $this->apiRequest($url);
        if ($response === null) {
            return [];
        }

        // stats endpoint returns data as an array; find our vehicle
        $vehicle = null;
        foreach ($response['data'] ?? [] as $v) {
            if ((string)($v['id'] ?? '') === $vehicleId) {
                $vehicle = $v;
                break;
            }
        }
        if ($vehicle === null) {
            $this->log('GPS_STATS_NO_MATCH', "vehicleId=$vehicleId — not in stats response");
            return [];
        }

        return $this->normalizeVehicleStats($vehicle);
    }

    // --------------------------------------------------------
    // getVehicleOdometer()
    //
    // Returns current odometer reading in km. Thin wrapper
    // around getVehicleStats for lease-close auto-populate
    // and similar spot-lookups where only odometer matters.
    //
    // @param  string $vehicleId  Samsara vehicle ID
    // @return float              odometer in km, 0.0 on failure
    // --------------------------------------------------------
    public function getVehicleOdometer(string $vehicleId): float
    {
        $stats = $this->getVehicleStats($vehicleId);
        return (float) ($stats['odometer_km'] ?? 0.0);
    }

    // --------------------------------------------------------
    // pingVehicle()
    //
    // Returns all available data for ONE vehicle — static
    // identifiers + live stats — in a single call. Used by
    // api/v1/samsara/link.php right after a user links a unit
    // so the full profile populates without a second round-trip.
    //
    // Note: we call getVehicles() then filter, because Samsara
    // does not expose a single "get vehicle by id" endpoint that
    // returns make/model/year/serial in one shot on older plans.
    //
    // @param  string $vehicleId  Samsara vehicle ID
    // @return array  Combined vehicle + stats, or [] on failure.
    // --------------------------------------------------------
    public function pingVehicle(string $vehicleId): array
    {
        if ($this->apiKey === '' || $vehicleId === '') {
            return [];
        }

        // Step 1: static identifiers (VIN, serial, gateway, name)
        $staticData = null;
        foreach ($this->getVehicles() as $v) {
            if ($v['id'] === $vehicleId) {
                $staticData = $v;
                break;
            }
        }

        if ($staticData === null) {
            $this->log('GPS_PING_NOT_FOUND', "vehicleId=$vehicleId not in getVehicles()");
            return [];
        }

        // Step 2: live stats (GPS + battery + odometer)
        $stats = $this->getVehicleStats($vehicleId);

        return array_merge($staticData, ['stats' => $stats]);
    }

    // --------------------------------------------------------
    // normalizeVehicleStats()
    //
    // Convert a raw Samsara stats object into FleetForge's
    // normalized shape. Extracted for reuse from getVehicleStats,
    // getVehicleLocations, and any future batch endpoints.
    //
    // WHY: Samsara's response has deeply-nested sub-objects
    // ({obdOdometerMeters: {value, time}}) and uses miles for
    // speed. Doing the conversion once means every caller gets
    // km and a flat shape.
    // --------------------------------------------------------
    public function normalizeVehicleStats(array $vehicle): array
    {
        $out = [
            'id'                => (string) ($vehicle['id']   ?? ''),
            'name'              => (string) ($vehicle['name'] ?? ''),
            'gps'               => null,
            'odometer_km'       => null,
            'battery_pct'       => null,
            'battery_charging'  => null,
            'fuel_pct'          => null,
            'power_source'      => null,
            'check_in_mode'     => null,
            'last_connected_at' => null,
        ];

        // GPS sub-object — Samsara returns speed in mph; convert to kph.
        // WHY 1.60934: exact conversion factor for miles→km, matches
        // Samsara's internal rounding better than a rounded 1.61.
        if (isset($vehicle['gps']) && is_array($vehicle['gps'])) {
            $gps = $vehicle['gps'];
            $speedMph = isset($gps['speedMilesPerHour']) ? (float) $gps['speedMilesPerHour'] : null;
            $out['gps'] = [
                'lat'       => isset($gps['latitude'])       ? (float) $gps['latitude']       : null,
                'lng'       => isset($gps['longitude'])      ? (float) $gps['longitude']      : null,
                'speed_kph' => $speedMph !== null ? round($speedMph * 1.60934, 2) : null,
                'heading'   => isset($gps['headingDegrees']) ? (int) round((float)$gps['headingDegrees']) : null,
                'address'   => $gps['reverseGeo']['formattedLocation'] ?? null,
                'time'      => $gps['time'] ?? null,
            ];
            if (isset($gps['time'])) {
                $out['last_connected_at'] = $gps['time'];
            }
        }

        // Odometer — Samsara returns meters, we store km with 2dp
        if (isset($vehicle['obdOdometerMeters']['value'])) {
            $meters = (float) $vehicle['obdOdometerMeters']['value'];
            $out['odometer_km'] = round($meters / 1000.0, 2);
        }

        // Fuel percent — legacy key we expose for diesel/gas units
        if (isset($vehicle['fuelPercents']['value'])) {
            $out['fuel_pct'] = (int) $vehicle['fuelPercents']['value'];
        }

        // Battery percent — newer unpowered/trailer gateway feature.
        // Samsara exposes this under different top-level keys depending
        // on the plan; probe the common names and fall through.
        foreach (['batteryLevelPercent', 'batteryPercents', 'trailerBatteryPercent'] as $key) {
            if (isset($vehicle[$key]['value'])) {
                $out['battery_pct'] = (int) $vehicle[$key]['value'];
                break;
            }
        }

        // Battery milli-volts (fallback → rough percent if nothing else)
        if ($out['battery_pct'] === null && isset($vehicle['batteryMilliVolts']['value'])) {
            $mv = (float) $vehicle['batteryMilliVolts']['value'];
            // 12V lead-acid: 11.5V empty → 12.7V full (approx linear)
            if ($mv > 0) {
                $pct = ($mv - 11500) / (12700 - 11500) * 100;
                $out['battery_pct'] = max(0, min(100, (int) round($pct)));
            }
        }

        // Power source (Battery / External) — from charging state field
        if (isset($vehicle['chargingStatus']['value'])) {
            $out['battery_charging'] = $vehicle['chargingStatus']['value'] === 'Charging' ? 1 : 0;
            $out['power_source']     = $vehicle['chargingStatus']['value'];
        }
        if (isset($vehicle['powerSource']['value'])) {
            $out['power_source'] = (string) $vehicle['powerSource']['value'];
        }

        // Check-in mode (Unpowered mode, etc.)
        if (isset($vehicle['checkInMode']['value'])) {
            $out['check_in_mode'] = (string) $vehicle['checkInMode']['value'];
        }

        return $out;
    }

    // ============================================================
    // SAMSARA-2 — TRAILER METHODS
    //
    // The original SAMSARA-1 methods only hit /fleet/vehicles, which
    // works for trucks/cars but returns nothing for accounts that
    // only own asset gateways on trailers (most refrigerated /
    // flatbed leasing fleets). Samsara exposes those as TRAILERS
    // under a parallel API tree:
    //
    //   GET /fleet/trailers                — list (paginated)
    //   GET /fleet/trailers/stats          — bulk telemetry
    //   GET /fleet/trailers/stats?...&trailerIds=ID  — single
    //
    // Telemetry types supported by trailers (per Samsara docs as
    // of 2026-04 — call with an unsupported type to see the list):
    //
    //   gps                — lat/lng/speed/heading/reverseGeo/time
    //   gpsOdometerMeters  — distance accumulated by the gateway
    //
    // Trailers do NOT have OBD odometer, fuel, engine hours, or
    // battery telemetry exposed via /fleet/trailers/stats — those
    // would require the (separate) gateway-info endpoints. The
    // SAMSARA-2 cron treats battery_pct/charging/etc. as null
    // for trailers and the UI degrades gracefully.
    // ============================================================

    // --------------------------------------------------------
    // getTrailers()
    //
    // Returns ALL trailers in the Samsara account, normalized to
    // the same shape getVehicles() returns so callers can treat
    // them uniformly. Each row carries entity_type='trailer'.
    //
    // Cursor-paginated; Samsara caps page size at 100 for the
    // /fleet/trailers endpoint.
    //
    // @return array  List of trailers, each:
    //     {
    //         id:             string,
    //         name:           string,
    //         vin:            string|null,
    //         serial_number:  string|null,
    //         gateway_id:     string|null,
    //         license_plate:  string|null,
    //         tags:           array,
    //         entity_type:    'trailer',
    //     }
    // --------------------------------------------------------
    public function getTrailers(): array
    {
        if ($this->apiKey === '') {
            $this->log('GPS_SKIP', 'API key not configured — getTrailers returning []');
            return [];
        }

        $allTrailers = [];
        $cursor      = null;
        $maxPages    = 20; // 20 * 100 = 2000 trailers cap — safety guard

        for ($i = 0; $i < $maxPages; $i++) {
            $url = self::API_BASE . '/fleet/trailers?limit=100';
            if ($cursor) {
                $url .= '&after=' . urlencode($cursor);
            }

            $response = $this->apiRequest($url);
            if ($response === null) {
                $this->log('GPS_TRAILERS_ERROR', 'apiRequest returned null on page ' . ($i + 1));
                return $allTrailers;
            }

            foreach ($response['data'] ?? [] as $t) {
                // Normalize tags from the {id, name} object form into
                // a flat string array so the UI can show them as chips
                // without touching raw Samsara JSON shapes.
                $tags = [];
                foreach (($t['tags'] ?? []) as $tag) {
                    if (isset($tag['name'])) {
                        $tags[] = (string) $tag['name'];
                    }
                }

                // externalIds.{samsara.vin, samsara.serial} is the
                // canonical place trailers carry their VIN + gateway
                // serial — fall back to top-level fields if absent.
                $vin    = $t['externalIds']['samsara.vin']    ?? null;
                $serial = $t['externalIds']['samsara.serial'] ?? null;
                $gw     = $t['installedGateway']['serial']    ?? null;

                $allTrailers[] = [
                    'id'            => (string) ($t['id'] ?? ''),
                    'name'          => (string) ($t['name'] ?? ''),
                    'vin'           => $vin    !== null && $vin    !== '' ? (string) $vin    : null,
                    'serial_number' => $serial !== null && $serial !== '' ? (string) $serial : null,
                    'gateway_id'    => $gw     !== null && $gw     !== '' ? (string) $gw     : null,
                    // Trailers do not expose make/model/year via this
                    // endpoint, but the dropdown handles missing values
                    // gracefully so we leave them null on purpose.
                    'make'          => null,
                    'model'         => isset($t['installedGateway']['model']) ? (string) $t['installedGateway']['model'] : null,
                    'year'          => null,
                    'license_plate' => isset($t['licensePlate']) && $t['licensePlate'] !== ''
                        ? (string) $t['licensePlate']
                        : null,
                    'tags'          => $tags,
                    'entity_type'   => 'trailer',
                ];
            }

            $hasMore = $response['pagination']['hasNextPage'] ?? false;
            if (!$hasMore) {
                break;
            }
            $cursor = $response['pagination']['endCursor'] ?? null;
            if (!$cursor) {
                break;
            }
        }

        $this->log('GPS_TRAILERS_SUCCESS', sprintf('Fetched %d trailers', count($allTrailers)));
        return $allTrailers;
    }

    // --------------------------------------------------------
    // getTrailerStats()
    //
    // Returns current stats for ONE trailer in the same flat shape
    // getVehicleStats() uses, so callers can persist either entity
    // type via the same column set.
    //
    // We pass &trailerIds={id} to scope the bulk endpoint to just
    // the trailer we care about — Samsara still returns an array,
    // we filter for our id below.
    //
    // @param  string $trailerId
    // @return array  Normalized stats. Empty array on failure.
    // --------------------------------------------------------
    public function getTrailerStats(string $trailerId): array
    {
        if ($this->apiKey === '' || $trailerId === '') {
            return [];
        }

        $url = self::API_BASE . '/fleet/trailers/stats'
             . '?types=' . urlencode('gps,gpsOdometerMeters')
             . '&trailerIds=' . urlencode($trailerId);

        $response = $this->apiRequest($url);
        if ($response === null) {
            return [];
        }

        $trailer = null;
        foreach ($response['data'] ?? [] as $t) {
            if ((string)($t['id'] ?? '') === $trailerId) {
                $trailer = $t;
                break;
            }
        }

        if ($trailer === null) {
            $this->log('GPS_TRAILER_STATS_NO_MATCH', "trailerId=$trailerId not in stats response");
            return [];
        }

        return $this->normalizeTrailerStats($trailer);
    }

    // --------------------------------------------------------
    // getAllTrailerStats()
    //
    // Returns current stats for EVERY trailer in the org in a
    // single call. Used by the cron sync to avoid N+1 round-trips
    // when there are 100+ linked trailers — one call returns all
    // 162 in our test account in well under a second.
    //
    // @return array<string, array>  Map of trailerId → normalized stats.
    //                               Empty map on failure or empty fleet.
    // --------------------------------------------------------
    public function getAllTrailerStats(): array
    {
        if ($this->apiKey === '') {
            return [];
        }

        $out  = [];
        $cursor = null;
        $maxPages = 20; // safety guard — 20 * 100 = 2000 trailers

        for ($i = 0; $i < $maxPages; $i++) {
            $url = self::API_BASE . '/fleet/trailers/stats'
                 . '?types=' . urlencode('gps,gpsOdometerMeters');
            if ($cursor) {
                $url .= '&after=' . urlencode($cursor);
            }

            $response = $this->apiRequest($url);
            if ($response === null) {
                $this->log('GPS_ALL_TRAILER_STATS_ERROR', 'apiRequest returned null on page ' . ($i + 1));
                return $out;
            }

            foreach ($response['data'] ?? [] as $t) {
                $id = (string) ($t['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[$id] = $this->normalizeTrailerStats($t);
            }

            $hasMore = $response['pagination']['hasNextPage'] ?? false;
            if (!$hasMore) {
                break;
            }
            $cursor = $response['pagination']['endCursor'] ?? null;
            if (!$cursor) {
                break;
            }
        }

        return $out;
    }

    // --------------------------------------------------------
    // pingTrailer()
    //
    // Like pingVehicle() — combines static identifiers (from
    // getTrailers()) with current stats so the link API endpoint
    // can persist a fully-populated row in one call.
    //
    // @param  string $trailerId
    // @return array  Combined trailer + stats, or [] on failure.
    // --------------------------------------------------------
    public function pingTrailer(string $trailerId): array
    {
        if ($this->apiKey === '' || $trailerId === '') {
            return [];
        }

        $staticData = null;
        foreach ($this->getTrailers() as $t) {
            if ($t['id'] === $trailerId) {
                $staticData = $t;
                break;
            }
        }

        if ($staticData === null) {
            $this->log('GPS_TRAILER_PING_NOT_FOUND', "trailerId=$trailerId not in getTrailers()");
            return [];
        }

        $stats = $this->getTrailerStats($trailerId);

        return array_merge($staticData, ['stats' => $stats]);
    }

    // --------------------------------------------------------
    // normalizeTrailerStats()
    //
    // Converts a Samsara trailer stats object into FleetForge's
    // shared shape — same keys getVehicleStats() returns so the
    // sync code can write either entity type via the same column
    // set without branching.
    //
    // Differences from vehicle stats:
    //   • Odometer comes from gpsOdometerMeters (gateway-derived)
    //     rather than obdOdometerMeters (engine ECU)
    //   • battery_pct, fuel_pct, power_source, check_in_mode and
    //     battery_charging are always null — trailers don't expose
    //     them on /fleet/trailers/stats
    // --------------------------------------------------------
    public function normalizeTrailerStats(array $trailer): array
    {
        $out = [
            'id'                => (string) ($trailer['id']   ?? ''),
            'name'              => (string) ($trailer['name'] ?? ''),
            'gps'               => null,
            'odometer_km'       => null,
            'battery_pct'       => null,
            'battery_charging'  => null,
            'fuel_pct'          => null,
            'power_source'      => null,
            'check_in_mode'     => null,
            'last_connected_at' => null,
        ];

        // Same conversion factor as normalizeVehicleStats(). Mirrored
        // here so a future API change in one path doesn't accidentally
        // break the other.
        if (isset($trailer['gps']) && is_array($trailer['gps'])) {
            $gps      = $trailer['gps'];
            $speedMph = isset($gps['speedMilesPerHour']) ? (float) $gps['speedMilesPerHour'] : null;
            $out['gps'] = [
                'lat'       => isset($gps['latitude'])       ? (float) $gps['latitude']       : null,
                'lng'       => isset($gps['longitude'])      ? (float) $gps['longitude']      : null,
                'speed_kph' => $speedMph !== null ? round($speedMph * 1.60934, 2) : null,
                'heading'   => isset($gps['headingDegrees']) ? (int) round((float)$gps['headingDegrees']) : null,
                'address'   => $gps['reverseGeo']['formattedLocation'] ?? null,
                'time'      => $gps['time'] ?? null,
            ];
            if (isset($gps['time'])) {
                $out['last_connected_at'] = $gps['time'];
            }
        }

        // Trailer odometer is GPS-derived (the gateway integrates
        // distance from its own GPS readings). Stored in km with 2dp
        // for column-shape compatibility with vehicle odometer.
        if (isset($trailer['gpsOdometerMeters']['value'])) {
            $meters = (float) $trailer['gpsOdometerMeters']['value'];
            $out['odometer_km'] = round($meters / 1000.0, 2);
        }

        return $out;
    }

    // ============================================================
    // SAMSARA-2 — ENTITY DISPATCHER METHODS
    //
    // Thin facade over the per-type methods. The link / sync /
    // cron code reads samsara_entity_type from the DB and calls
    // these helpers, which dispatch to the right per-type method
    // so callers never repeat the if/else chain.
    // ============================================================

    // --------------------------------------------------------
    // getAllTrackables()
    //
    // Returns vehicles AND trailers in a single combined list,
    // each entry tagged with `entity_type` so the UI can show
    // mixed dropdowns. Used by the manual mapping picker so
    // users can link a unit to either kind of Samsara entity
    // without choosing the type first.
    //
    // @return array  combined list, sorted by name (case-insensitive)
    // --------------------------------------------------------
    public function getAllTrackables(): array
    {
        $vehicles = $this->getVehicles();
        // Tag every vehicle with its entity type so the front-end can
        // distinguish them in the dropdown without sniffing the shape.
        foreach ($vehicles as &$v) {
            $v['entity_type'] = 'vehicle';
        }
        unset($v);

        $trailers = $this->getTrailers();
        // getTrailers() already sets entity_type='trailer' on each row,
        // so we can merge directly without re-tagging.

        return array_merge($vehicles, $trailers);
    }

    // --------------------------------------------------------
    // getEntityStats()
    //
    // Dispatch helper. Reads the entity_type and calls the right
    // per-type stats method. Used by sync.php and cron/samsara_sync.php
    // so neither has to know which API path is involved.
    //
    // @param  string $type  'vehicle' or 'trailer'
    // @param  string $id    Samsara id
    // @return array         Normalized stats. Empty array on failure.
    // --------------------------------------------------------
    public function getEntityStats(string $type, string $id): array
    {
        if ($type === 'trailer') {
            return $this->getTrailerStats($id);
        }
        // Default to vehicle for backwards compat with rows that
        // pre-date the SAMSARA-2 entity_type column.
        return $this->getVehicleStats($id);
    }

    // --------------------------------------------------------
    // pingEntity()
    //
    // Dispatch helper for the link flow.
    //
    // @param  string $type  'vehicle' or 'trailer'
    // @param  string $id    Samsara id
    // @return array         Combined static + stats. [] on failure.
    // --------------------------------------------------------
    public function pingEntity(string $type, string $id): array
    {
        if ($type === 'trailer') {
            return $this->pingTrailer($id);
        }
        return $this->pingVehicle($id);
    }

    // ============================================================
    // SAMSARA-3: Bidirectional write methods
    //
    // createTrailer() — POST /fleet/trailers
    // updateTrailer() — PATCH /fleet/trailers/{id}
    // deleteTrailer() — DELETE /fleet/trailers/{id}
    //
    // These are called from the equipment unit create / update /
    // delete API endpoints so every FleetForge unit is mirrored
    // in Samsara automatically.
    //
    // Failure model: consistent with existing read methods —
    // GPS / Samsara failures are NEVER blocking. Every failure is
    // logged to logs/gps.log and null / false is returned.  The
    // caller decides how to surface the warning.
    // ============================================================

    /**
     * createTrailer() — create a new trailer asset in Samsara.
     *
     * Maps FleetForge equipment unit fields to Samsara trailer fields:
     *   name              ← unit_number (required by Samsara)
     *   vin               ← vin
     *   year              ← year
     *   make              ← template brand
     *   model             ← template model
     *   licensePlateNumber← license_plate
     *   notes             ← "Created in FleetForge — unit #{id}"
     *
     * @param  string $name   Unit number (becomes the Samsara asset name)
     * @param  array  $fields Optional additional fields (vin, year, make, model, etc.)
     * @return array|null     ['id' => ..., 'name' => ...] on success, null on failure
     */
    public function createTrailer(string $name, array $fields = []): ?array
    {
        $payload = array_filter([
            'name'               => $name,
            'vin'                => $fields['vin']           ?? null,
            'year'               => isset($fields['year']) ? (int) $fields['year'] : null,
            'make'               => $fields['make']          ?? null,
            'model'              => $fields['model']         ?? null,
            'licensePlateNumber' => $fields['license_plate'] ?? null,
            'notes'              => $fields['notes']         ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $data = $this->apiWrite('POST', self::API_BASE . '/fleet/trailers', $payload);
        if (!$data || empty($data['data']['id'])) {
            $this->log('SAMSARA_CREATE_TRAILER_FAILED',
                "name={$name} — no id in response: " . json_encode($data));
            return null;
        }
        return $data['data'];
    }

    /**
     * updateTrailer() — PATCH an existing Samsara trailer with changed fields.
     *
     * Only sends fields that are present in $fields (sparse update).
     * Silently succeeds (returns true) when $fields is empty.
     *
     * @param  string $trailerId  Samsara trailer ID (equipment_units.samsara_vehicle_id)
     * @param  array  $fields     Map of Samsara field names → new values
     * @return bool               true on success or nothing-to-do, false on API error
     */
    public function updateTrailer(string $trailerId, array $fields): bool
    {
        // WHY: filter nulls so we do a true sparse PATCH — Samsara treats
        // an explicit null as "clear this field" which is not always what
        // we want when a field is simply not being changed.
        $payload = array_filter($fields, fn($v) => $v !== null && $v !== '');
        if (empty($payload)) {
            return true; // nothing relevant changed
        }

        $data = $this->apiWrite(
            'PATCH',
            self::API_BASE . '/fleet/trailers/' . urlencode($trailerId),
            $payload
        );
        if ($data === null) {
            $this->log('SAMSARA_UPDATE_TRAILER_FAILED',
                "id={$trailerId} fields=" . json_encode($fields));
            return false;
        }
        return true;
    }

    /**
     * deleteTrailer() — remove a trailer asset from Samsara.
     *
     * Called when a FleetForge unit is soft-deleted so the Samsara
     * fleet list stays in sync. Returns true on 204 No Content.
     *
     * @param  string $trailerId  Samsara trailer ID
     * @return bool               true on success, false on failure
     */
    public function deleteTrailer(string $trailerId): bool
    {
        $url = self::API_BASE . '/fleet/trailers/' . urlencode($trailerId);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            $this->log('SAMSARA_DELETE_TRAILER_CURL_ERROR', "id={$trailerId} error={$err}");
            return false;
        }
        // 204 = deleted, 404 = already gone — both are acceptable outcomes
        if ($code !== 204 && $code !== 404) {
            $this->log('SAMSARA_DELETE_TRAILER_HTTP_ERROR',
                "id={$trailerId} HTTP={$code}");
            return false;
        }
        return true;
    }

    // --------------------------------------------------------
    // apiWrite() — shared helper for POST and PATCH calls.
    // Returns parsed JSON array on success, null on any failure.
    // WHY separate from apiRequest(): write calls need
    // Content-Type: application/json + a request body, and
    // non-200 success codes (Samsara POST returns 200, PATCH 200).
    // --------------------------------------------------------
    private function apiWrite(string $method, string $url, array $payload): ?array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => array_merge(
                $this->buildHeaders(),
                ['Content-Type: application/json']
            ),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $this->log('GPS_API_WRITE_CURL_ERROR',
                "method={$method} url={$url} error={$err}");
            return null;
        }

        // Samsara write endpoints return 200 for both POST and PATCH
        if ($code < 200 || $code >= 300) {
            $this->log('GPS_API_WRITE_HTTP_ERROR',
                "method={$method} url={$url} HTTP={$code} body=" . substr((string) $raw, 0, 500));
            return null;
        }

        // Empty body (e.g. some 204s) is not an error for write operations
        if ($raw === '') {
            return [];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $this->log('GPS_API_WRITE_PARSE_ERROR', "url={$url} — invalid JSON");
            return null;
        }
        return $data;
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
    //
    // X-Org-Id is only sent when configured. Single-org Samsara
    // tokens are scoped to their org and reject this header,
    // so omitting it lets one-org accounts work without setup.
    // --------------------------------------------------------
    private function buildHeaders(): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        if ($this->orgId !== '') {
            $headers[] = 'X-Org-Id: ' . $this->orgId;
        }
        return $headers;
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
