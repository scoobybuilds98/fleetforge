<?php
declare(strict_types=1);

/**
 * FleetForge — Global Search API
 *
 * @file        api/v1/search.php
 * @description Grouped global search across customers, equipment units,
 *              leases, invoices, and reservations. Drives both the inline
 *              topbar search widget (FF_SearchWidget in app.js) and the
 *              full-screen ⌘K modal (FF_Search.query in app.js).
 *
 *              Response shape is grouped by type so the Alpine widget can
 *              render per-module sections without re-grouping client-side.
 *
 * @method      GET
 * @params      q     (required, string, min 2 chars) — search term
 *              limit (optional, int 1..10, default 3) — items per group
 * @auth        Session required (require_auth_api)
 * @permissions Per-module: only groups the user can view are queried and
 *              returned. A dispatcher with no invoices access will never
 *              see invoices in the response.
 *
 * @returns {
 *   success: true,
 *   data: {
 *     query: "abc",
 *     total: 12,
 *     results: {
 *       customers:    [{ id, type, title, subtitle, url, badge, badge_class }],
 *       equipment:    [...],
 *       leases:       [...],
 *       invoices:     [...],
 *       reservations: [...]
 *     }
 *   }
 * }
 *
 * @error   QUERY_TOO_SHORT 422  — q is empty, missing, or < 2 chars
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7 — cross-module search
 * @session SEARCH-1 (2026-04-08)
 */

// dirname(__DIR__, 2): api/v1/ → api/ → project root
require_once dirname(__DIR__, 2) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Input validation ──────────────────────────────────────────────────────────
// WHY min 2: single-character searches return too many results to be useful and
// overload the LIKE scans on every table.
$q = clean_string($_GET['q'] ?? null, 255);

if ($q === null || strlen($q) < 2) {
    json_error('QUERY_TOO_SHORT', 'Search query must be at least 2 characters.', 422);
}

// Per-group cap: client can request 1..10, defaults to 3 for the dropdown.
$limit = (int) ($_GET['limit'] ?? 3);
if ($limit < 1) $limit = 3;
if ($limit > 10) $limit = 10;

// FIX #37: escape LIKE metacharacters so a search for "50%" doesn't match
// everything, and "unit_1" doesn't wildcard-match "unit01".
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
$like    = '%' . $escaped . '%';

// ── Badge class helper ────────────────────────────────────────────────────────
// WHY keep this here rather than importing: the badge-class mapping is a
// presentation concern that only the search endpoint cares about, and the
// UI strings are tightly coupled to the enum values below.
$badge_class = static function (string $status): string {
    return match ($status) {
        'active', 'available', 'paid', 'confirmed', 'completed'
            => 'badge-success',
        'pending', 'reserved', 'draft', 'assessed', 'repair_ordered'
            => 'badge-warning',
        'overdue', 'cancelled', 'decommissioned', 'voided', 'written_off'
            => 'badge-danger',
        'inactive', 'on_lease', 'maintenance'
            => 'badge-info',
        default => 'badge-neutral',
    };
};

$humanize = static function (?string $s): string {
    return $s === null || $s === ''
        ? ''
        : ucfirst(str_replace('_', ' ', $s));
};

// ── Response scaffold ─────────────────────────────────────────────────────────
// Keys are always present even when empty so the Alpine widget can iterate
// without null checks.
$results = [
    'customers'    => [],
    'equipment'    => [],
    'leases'       => [],
    'invoices'     => [],
    'reservations' => [],
];
$total = 0;

// ── Customers ─────────────────────────────────────────────────────────────────
if (can('customers', 'view')) {
    $rows = db_select(
        "SELECT id, company_name, contact_name, email, phone, status
           FROM customers
          WHERE deleted_at IS NULL
            AND (
                company_name LIKE ?
                OR contact_name LIKE ?
                OR email        LIKE ?
                OR phone        LIKE ?
            )
          ORDER BY company_name ASC
          LIMIT {$limit}",
        [$like, $like, $like, $like]
    );
    foreach ($rows as $row) {
        $sub = $row['email'] ?: ($row['phone'] ?: ($row['contact_name'] ?? ''));
        $results['customers'][] = [
            'id'          => (int) $row['id'],
            'type'        => 'customer',
            'title'       => $row['company_name'],
            'subtitle'    => (string) $sub,
            'url'         => base_url('customers/show') . '?id=' . (int) $row['id'],
            'badge'       => $humanize($row['status'] ?? ''),
            'badge_class' => $badge_class($row['status'] ?? ''),
        ];
        $total++;
    }
}

// ── Equipment Units ───────────────────────────────────────────────────────────
// WHY join equipment_templates: make/model live on the template, not the unit.
// The existing api/v1/search.php queried eu.make/eu.model which don't exist —
// that's the root cause of every search throwing a 500 today.
if (can('equipment', 'view')) {
    $rows = db_select(
        "SELECT eu.id,
                eu.unit_number,
                eu.vin,
                eu.license_plate,
                eu.yard_location,
                eu.status,
                et.name  AS template_name,
                et.brand AS brand,
                et.model AS model
           FROM equipment_units eu
           LEFT JOIN equipment_templates et ON et.id = eu.template_id
          WHERE eu.deleted_at IS NULL
            AND (
                eu.unit_number   LIKE ?
                OR eu.vin         LIKE ?
                OR eu.license_plate LIKE ?
                OR et.name        LIKE ?
                OR et.brand       LIKE ?
                OR et.model       LIKE ?
            )
          ORDER BY eu.unit_number ASC
          LIMIT {$limit}",
        [$like, $like, $like, $like, $like, $like]
    );
    foreach ($rows as $row) {
        $subParts = array_filter([
            $row['template_name'] ?? '',
            $row['yard_location'] ?? '',
            $row['vin'] ?? '',
        ]);
        $results['equipment'][] = [
            'id'          => (int) $row['id'],
            'type'        => 'equipment',
            'title'       => $row['unit_number'],
            'subtitle'    => implode(' · ', $subParts),
            'url'         => base_url('equipment/show') . '?id=' . (int) $row['id'],
            'badge'       => $humanize($row['status'] ?? ''),
            // S-UNIT-STATUS-COLOR 2026-05-14: route equipment_units status
            // through the canonical helper at includes/functions.php so the
            // search result badge matches the fleet list + unit profile +
            // lease show + maintenance show + reservation show. The local
            // $badge_class closure above is a multi-entity overload that
            // had drifted color mappings for equipment statuses (reserved →
            // warning instead of purple; maintenance → info instead of
            // warning; inactive → info instead of neutral). Other entity
            // types (leases, invoices, payments, customers) keep using
            // $badge_class below.
            'badge_class' => unit_status_badge_class($row['status'] ?? ''),
        ];
        $total++;
    }
}

// ── Leases ────────────────────────────────────────────────────────────────────
// Snapshot columns on leases (company_name_snapshot, unit_number_snapshot) make
// this search resilient to customer or unit renames after the lease is signed.
if (can('leases', 'view')) {
    $rows = db_select(
        "SELECT id,
                contract_number,
                company_name_snapshot,
                unit_number_snapshot,
                status
           FROM leases
          WHERE deleted_at IS NULL
            AND (
                contract_number       LIKE ?
                OR company_name_snapshot LIKE ?
                OR unit_number_snapshot  LIKE ?
            )
          ORDER BY created_at DESC
          LIMIT {$limit}",
        [$like, $like, $like]
    );
    foreach ($rows as $row) {
        $subParts = array_filter([
            $row['company_name_snapshot'] ?? '',
            $row['unit_number_snapshot']
                ? 'Unit ' . $row['unit_number_snapshot']
                : '',
        ]);
        $results['leases'][] = [
            'id'          => (int) $row['id'],
            'type'        => 'lease',
            'title'       => $row['contract_number'],
            'subtitle'    => implode(' · ', $subParts),
            'url'         => base_url('leases/show') . '?id=' . (int) $row['id'],
            'badge'       => $humanize($row['status'] ?? ''),
            'badge_class' => $badge_class($row['status'] ?? ''),
        ];
        $total++;
    }
}

// ── Invoices ──────────────────────────────────────────────────────────────────
// COALESCE live customer name over the snapshot so a rename on the customer
// surfaces in search results without a historical rewrite of every invoice.
if (can('invoices', 'view')) {
    $rows = db_select(
        "SELECT i.id,
                i.invoice_number,
                i.status,
                i.total_amount,
                COALESCE(c.company_name, i.company_name_snapshot) AS customer_name
           FROM invoices i
           LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
          WHERE i.deleted_at IS NULL
            AND (
                i.invoice_number LIKE ?
                OR COALESCE(c.company_name, i.company_name_snapshot) LIKE ?
            )
          ORDER BY i.created_at DESC
          LIMIT {$limit}",
        [$like, $like]
    );
    foreach ($rows as $row) {
        $subParts = array_filter([
            $row['customer_name'] ?? '',
            $row['total_amount'] !== null
                ? '$' . number_format((float) $row['total_amount'], 2)
                : '',
        ]);
        $results['invoices'][] = [
            'id'          => (int) $row['id'],
            'type'        => 'invoice',
            'title'       => $row['invoice_number'],
            'subtitle'    => implode(' · ', $subParts),
            'url'         => base_url('invoices/show') . '?id=' . (int) $row['id'],
            'badge'       => $humanize($row['status'] ?? ''),
            'badge_class' => $badge_class($row['status'] ?? ''),
        ];
        $total++;
    }
}

// ── Reservations ──────────────────────────────────────────────────────────────
// NOTE: reservations table has no reservation_number — lookups are by numeric
// id (supports "R123" or "123") plus company/contact name. Casting id to CHAR
// lets us LIKE-match the digits inside the query string even if the user
// typed "R" prefixes or partial numbers.
if (can('reservations', 'view')) {
    $rows = db_select(
        "SELECT id,
                contact_name,
                company_name,
                pickup_date,
                status
           FROM reservations
          WHERE deleted_at IS NULL
            AND (
                CAST(id AS CHAR)   LIKE ?
                OR company_name    LIKE ?
                OR contact_name    LIKE ?
            )
          ORDER BY pickup_date DESC, id DESC
          LIMIT {$limit}",
        [$like, $like, $like]
    );
    foreach ($rows as $row) {
        $subParts = array_filter([
            $row['company_name'] ?? '',
            $row['pickup_date']
                ? 'Pickup ' . $row['pickup_date']
                : '',
        ]);
        $results['reservations'][] = [
            'id'          => (int) $row['id'],
            'type'        => 'reservation',
            'title'       => '#' . (int) $row['id']
                . ($row['contact_name'] ? ' — ' . $row['contact_name'] : ''),
            'subtitle'    => implode(' · ', $subParts),
            'url'         => base_url('reservations/show') . '?id=' . (int) $row['id'],
            'badge'       => $humanize($row['status'] ?? ''),
            'badge_class' => $badge_class($row['status'] ?? ''),
        ];
        $total++;
    }
}

// ── Respond ───────────────────────────────────────────────────────────────────
json_success([
    'query'   => $q,
    'total'   => $total,
    'results' => $results,
]);
