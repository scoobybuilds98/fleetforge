<?php
declare(strict_types=1);

/**
 * api/v1/samsara/vehicles.php
 *
 * GET → List every TRACKABLE in the customer's Samsara org —
 * vehicles AND trailers — annotated with which FleetForge unit
 * (if any) each is already linked to. Drives the unmapped-state
 * dropdown on the equipment unit show page's "Samsara Mapping"
 * tab so a single picker can map to either entity type.
 *
 * The endpoint URL is still /samsara/vehicles for backwards
 * compatibility with the SAMSARA-1 front-end JS — the entries
 * just now carry an `entity_type` field of 'vehicle' or 'trailer'.
 *
 * Shape returned:
 *   {
 *     vehicles: [
 *       {
 *         id:            "281474986...",
 *         name:          "Trailer 36V203",
 *         entity_type:   "vehicle" | "trailer",  // SAMSARA-2
 *         vin:           "1XP...",
 *         serial_number: "G...",
 *         gateway_id:    "G...",
 *         make, model, year, license_plate, tags: [...],
 *         is_linked:          bool,
 *         linked_unit_id:     int|null,
 *         linked_unit_number: string|null
 *       }, ...
 *     ],
 *     total:        int,
 *     linked_count: int,
 *     by_type: { vehicle: int, trailer: int }   // SAMSARA-2
 *   }
 *
 * The frontend uses `is_linked` to gray-out already-linked entries
 * in the dropdown so an entity can never be mapped to two units.
 *
 * Permission: equipment:edit (same level as the link action).
 *
 * @depends api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session SAMSARA-1, SAMSARA-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'edit');

use FleetForge\GPS\SamsaraClient;

// ── Pull the combined Samsara catalog (vehicles + trailers) ──
// SAMSARA-2: getAllTrackables() merges /fleet/vehicles and
// /fleet/trailers so a single picker can map to either type.
// Cursor-paginated under the hood; may take a few seconds on
// large fleets. No caching here — the mapping UI is low-volume
// and users expect fresh data on page open.
$client    = new SamsaraClient();
$trackables = $client->getAllTrackables();

// ── Load every FleetForge unit that is already linked ────────
// Used to annotate the list; IS NOT NULL catches both strings
// and empty-string edge cases (we always clear to NULL on unlink).
$linked = db_select(
    "SELECT id, unit_number, samsara_vehicle_id
       FROM equipment_units
      WHERE samsara_vehicle_id IS NOT NULL
        AND samsara_vehicle_id <> ''
        AND deleted_at IS NULL"
);

// Build a samsaraId → {unit_id, unit_number} lookup so the
// annotation loop below is O(n) instead of O(n²).
$byVehicleId = [];
foreach ($linked as $row) {
    $byVehicleId[(string) $row['samsara_vehicle_id']] = [
        'id'          => (int) $row['id'],
        'unit_number' => (string) $row['unit_number'],
    ];
}

// ── Annotate each trackable with its link status ─────────────
$annotated = [];
$byType    = ['vehicle' => 0, 'trailer' => 0];
foreach ($trackables as $t) {
    $link = $byVehicleId[$t['id']] ?? null;
    $annotated[] = $t + [
        'is_linked'          => $link !== null,
        'linked_unit_id'     => $link['id']          ?? null,
        'linked_unit_number' => $link['unit_number'] ?? null,
    ];
    $type = (string) ($t['entity_type'] ?? 'vehicle');
    if (isset($byType[$type])) {
        $byType[$type]++;
    }
}

// Sort by entity type first (vehicles before trailers) then
// alphabetically by name. Two-level sort keeps the dropdown
// easy to scan when the same fleet has hundreds of trailers.
usort($annotated, static function (array $a, array $b): int {
    $ta = (string) ($a['entity_type'] ?? 'vehicle');
    $tb = (string) ($b['entity_type'] ?? 'vehicle');
    if ($ta !== $tb) {
        return strcmp($ta, $tb);
    }
    return strcasecmp((string) $a['name'], (string) $b['name']);
});

json_success([
    'vehicles'     => $annotated,
    'total'        => count($annotated),
    'linked_count' => count($byVehicleId),
    'by_type'      => $byType,
]);
