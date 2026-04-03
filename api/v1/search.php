<?php
declare(strict_types=1);

/**
 * FleetForge — Global Search API
 *
 * @file        api/v1/search.php
 * @description Global search endpoint for the topbar FF_Search component.
 *              Accepts ?q= (min 2 chars) and searches across customers,
 *              equipment units, leases, invoices, and vendors using LIKE
 *              substring matching. Returns up to 5 results per entity type.
 *
 * @method      GET
 * @params      q   (required, string, min 2 chars) — search term
 * @auth        Session required (require_auth_api)
 * @returns     { success: true, data: { results: [{ type, title, meta, url }] } }
 *              - type:  customer | equipment | lease | invoice | vendor
 *              - title: primary identifier (company name, unit number, etc.)
 *              - meta:  secondary info string (contact, status, amount, etc.)
 *              - url:   absolute URL to the detail show page
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7 (cross-module)
 * @session     S017
 */

// dirname(__DIR__, 2): api/v1/ → api/ → project root
require_once dirname(__DIR__, 2) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Input validation ──────────────────────────────────────────────────────────
// WHY min 2: single-character searches return too many results to be useful and
// are expensive without a FULLTEXT index.
$q = clean_string($_GET['q'] ?? '', 255);

if (strlen(trim($q)) < 2) {
    json_error('QUERY_TOO_SHORT', 'Search query must be at least 2 characters.', 400);
}

$like    = '%' . $q . '%';
$results = [];

// ── Customers ─────────────────────────────────────────────────────────────────
// Searches company_name, contact_name, email — the fields most commonly used
// when dispatchers look up a customer account.
$customers = db_select(
    "SELECT id, company_name, contact_name, email, status
       FROM customers
      WHERE deleted_at IS NULL
        AND (company_name LIKE ? OR contact_name LIKE ? OR email LIKE ?)
      ORDER BY company_name ASC
      LIMIT 5",
    [$like, $like, $like]
);
foreach ($customers as $row) {
    $metaParts = array_filter([
        $row['contact_name'] ?? '',
        $row['email']        ?? '',
    ]);
    $results[] = [
        'type'  => 'customer',
        'title' => $row['company_name'],
        'meta'  => implode(' · ', $metaParts),
        'url'   => base_url('customers/show') . '?id=' . (int) $row['id'],
    ];
}

// ── Equipment Units ───────────────────────────────────────────────────────────
// Searches unit_number (primary), VIN, make, and model so technicians can
// find a unit by any identifier they have on hand.
$units = db_select(
    "SELECT id, unit_number, vin, make, model, status
       FROM equipment_units
      WHERE deleted_at IS NULL
        AND (unit_number LIKE ? OR vin LIKE ? OR make LIKE ? OR model LIKE ?)
      ORDER BY unit_number ASC
      LIMIT 5",
    [$like, $like, $like, $like]
);
foreach ($units as $row) {
    $metaParts = array_filter([
        $row['make']  ?? '',
        $row['model'] ?? '',
        $row['vin']   ?? '',
    ]);
    $results[] = [
        'type'  => 'equipment',
        'title' => $row['unit_number'],
        'meta'  => implode(' · ', $metaParts),
        'url'   => base_url('equipment/show') . '?id=' . (int) $row['id'],
    ];
}

// ── Leases ────────────────────────────────────────────────────────────────────
// Searches contract_number and company_name_snapshot so users can find leases
// by contract reference or by customer name (snapshot survives customer edits).
$leases = db_select(
    "SELECT id, contract_number, status, company_name_snapshot, unit_number_snapshot
       FROM leases
      WHERE deleted_at IS NULL
        AND (contract_number LIKE ? OR company_name_snapshot LIKE ?)
      ORDER BY created_at DESC
      LIMIT 5",
    [$like, $like]
);
foreach ($leases as $row) {
    $metaParts = array_filter([
        $row['company_name_snapshot'] ?? '',
        $row['unit_number_snapshot']  ? 'Unit ' . $row['unit_number_snapshot'] : '',
        ucfirst($row['status']        ?? ''),
    ]);
    $results[] = [
        'type'  => 'lease',
        'title' => $row['contract_number'],
        'meta'  => implode(' · ', $metaParts),
        'url'   => base_url('leases/show') . '?id=' . (int) $row['id'],
    ];
}

// ── Invoices ──────────────────────────────────────────────────────────────────
// Searches invoice_number and customer name (live join + snapshot fallback).
$invoices = db_select(
    "SELECT i.id,
            i.invoice_number,
            i.status,
            i.total_amount,
            COALESCE(c.company_name, i.customer_name_snapshot) AS customer_name
       FROM invoices i
       LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
      WHERE i.deleted_at IS NULL
        AND (
            i.invoice_number LIKE ?
            OR COALESCE(c.company_name, i.customer_name_snapshot) LIKE ?
        )
      ORDER BY i.created_at DESC
      LIMIT 5",
    [$like, $like]
);
foreach ($invoices as $row) {
    $metaParts = array_filter([
        $row['customer_name'] ?? '',
        $row['total_amount'] !== null
            ? '$' . number_format((float) $row['total_amount'], 2)
            : '',
        ucfirst(str_replace('_', ' ', $row['status'] ?? '')),
    ]);
    $results[] = [
        'type'  => 'invoice',
        'title' => $row['invoice_number'],
        'meta'  => implode(' · ', $metaParts),
        'url'   => base_url('invoices/show') . '?id=' . (int) $row['id'],
    ];
}

// ── Vendors ───────────────────────────────────────────────────────────────────
// WHY include vendors: dispatchers frequently search for vendors by name to
// look up contact details or check open work orders.
$vendors = db_select(
    "SELECT id, name, contact_name, city
       FROM vendors
      WHERE deleted_at IS NULL
        AND (name LIKE ? OR contact_name LIKE ?)
      ORDER BY name ASC
      LIMIT 5",
    [$like, $like]
);
foreach ($vendors as $row) {
    $metaParts = array_filter([
        $row['contact_name'] ?? '',
        $row['city']         ?? '',
    ]);
    $results[] = [
        'type'  => 'vendor',
        'title' => $row['name'],
        'meta'  => implode(' · ', $metaParts),
        'url'   => base_url('vendors/show') . '?id=' . (int) $row['id'],
    ];
}

// ── Respond ───────────────────────────────────────────────────────────────────
json_success(['results' => $results]);
