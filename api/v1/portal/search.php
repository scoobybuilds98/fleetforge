<?php
declare(strict_types=1);

/**
 * api/v1/portal/search.php
 *
 * Customer portal — the ⌘K search palette (S-PORTAL-REDESIGN). One box
 * finds invoices (by number), leases (contract # or unit #), units on the
 * account (unit #, VIN, plate) and service requests (subject), up to five of
 * each, already shaped for the palette (group/title/sub/right/url/icon).
 *
 * Trap 8: every query is scoped to portal_customer_id(); units are reached
 * only through the customer's own leases. LIKE wildcards in the term are
 * escaped (pt_like).
 *
 * @method  GET
 * @query   q (2–60 chars)
 * @auth    portal session (401 JSON when it has ended)
 * @returns { results: [{group,title,sub,right,url,icon_svg}] }
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 3) . '/app/portal/includes/ui.php';

require_method('GET');
require_portal_auth_api();

$q = trim((string) clean_string($_GET['q'] ?? '', 60));
if (mb_strlen($q) < 2) {
    json_success(['results' => []]);
}

$cid  = portal_customer_id();
$like = pt_like($q);
$out  = [];

// Invoices
foreach (db_select(
    pt_invoice_select_sql() . " WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i') . "
       AND (i.invoice_number LIKE ? OR l.contract_number LIKE ?)
     ORDER BY i.invoice_date DESC LIMIT 5",
    [$cid, $like, $like]
) as $r) {
    $j = pt_invoice_json($r);
    $out[] = [
        'group'    => 'Invoices',
        'title'    => 'Invoice ' . $j['number'],
        'sub'      => $j['status_label'] . ' · issued ' . format_date($j['invoice_date']) . ($j['lease'] !== '' ? ' · lease ' . $j['lease'] : ''),
        'right'    => $j['payable'] ? $j['balance_fmt'] . ' due' : $j['total_fmt'],
        'url'      => pt_url('invoices/view?id=' . $j['id']),
        'icon_svg' => pt_icon('document-text'),
    ];
}

// Leases
foreach (db_select(
    "SELECT l.id, l.contract_number, l.status, l.start_date, eu.unit_number, et.name AS type_name
       FROM leases l
       JOIN equipment_units eu ON eu.id = l.equipment_unit_id
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
      WHERE l.customer_id = ? AND l.deleted_at IS NULL
        AND (l.contract_number LIKE ? OR eu.unit_number LIKE ?)
      ORDER BY (l.status = 'active') DESC, l.start_date DESC LIMIT 5",
    [$cid, $like, $like]
) as $r) {
    $out[] = [
        'group'    => 'Leases',
        'title'    => 'Lease ' . $r['contract_number'],
        'sub'      => 'Unit ' . $r['unit_number'] . ($r['type_name'] ? ' · ' . $r['type_name'] : '') . ' · since ' . format_date($r['start_date']),
        'right'    => ucfirst((string) $r['status']),
        'url'      => pt_url('leases/view?id=' . $r['id']),
        'icon_svg' => pt_icon('clipboard-document-list'),
    ];
}

// Units currently on rent with this customer
foreach (db_select(
    "SELECT eu.id, eu.unit_number, eu.license_plate, et.name AS type_name, l.id AS lease_id
       FROM equipment_units eu
       JOIN leases l ON l.equipment_unit_id = eu.id AND l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
      WHERE eu.deleted_at IS NULL
        AND (eu.unit_number LIKE ? OR eu.vin LIKE ? OR eu.license_plate LIKE ?)
      ORDER BY eu.unit_number LIMIT 5",
    [$cid, $like, $like, $like]
) as $r) {
    $out[] = [
        'group'    => 'Equipment on rent',
        'title'    => 'Unit ' . $r['unit_number'],
        'sub'      => trim(($r['type_name'] ?: 'Equipment') . ($r['license_plate'] ? ' · plate ' . $r['license_plate'] : '')),
        'right'    => '',
        'url'      => pt_url('leases/view?id=' . $r['lease_id']),
        'icon_svg' => pt_icon('truck'),
    ];
}

// Requests
$types = pt_request_types();
foreach (db_select(
    "SELECT id, request_type, subject, status, created_at
       FROM portal_service_requests
      WHERE customer_id = ? AND subject LIKE ?
      ORDER BY created_at DESC LIMIT 5",
    [$cid, $like]
) as $r) {
    $out[] = [
        'group'    => 'Requests',
        'title'    => (string) $r['subject'],
        'sub'      => ($types[$r['request_type']][0] ?? 'Request') . ' · ' . format_datetime($r['created_at'], 'M j, Y'),
        'right'    => ucfirst(str_replace('_', ' ', (string) $r['status'])),
        'url'      => pt_url('requests/view?id=' . $r['id']),
        'icon_svg' => pt_icon('wrench-screwdriver'),
    ];
}

json_success(['results' => $out]);
