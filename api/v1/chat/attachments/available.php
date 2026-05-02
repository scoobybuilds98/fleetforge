<?php
/**
 * api/v1/chat/attachments/available.php
 *
 * GET ?type=invoice&query=X
 * Returns searchable list of FleetForge records to attach to messages.
 * Types: invoice, lease, customer, payment, work_order,
 *        damage_claim, reservation, equipment
 *
 * Dependencies: api/bootstrap.php, invoices, leases, customers, payments,
 *               maintenance_work_orders, damage_claims, reservations,
 *               equipment_units, equipment_templates
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$type  = clean_string($_GET['type'] ?? null, 50);
$query = clean_string($_GET['query'] ?? null, 200);
$limit = 20;

if (!$type) json_error('MISSING_REQUIRED', 'type is required.', 422);

// Safe LIKE search
$like = $query ? '%' . str_replace(['%','_','\\'],['\\%','\\_','\\\\'], $query) . '%' : '%';

$results = match($type) {
    'invoice' => db_select(
        "SELECT id, invoice_number AS title, status AS badge,
                CONCAT(status, ' · ', COALESCE(total_amount, '0')) AS subtitle
         FROM invoices
         WHERE deleted_at IS NULL AND (invoice_number LIKE ? OR company_name_snapshot LIKE ?)
         ORDER BY created_at DESC LIMIT ?",
        [$like, $like, $limit]
    ),
    'lease' => db_select(
        "SELECT l.id, l.contract_number AS title, l.status AS badge,
                CONCAT(c.company_name, ' · ', l.status) AS subtitle
         FROM leases l JOIN customers c ON c.id = l.customer_id
         WHERE l.deleted_at IS NULL AND c.deleted_at IS NULL
           AND (l.contract_number LIKE ? OR c.company_name LIKE ?)
         ORDER BY l.created_at DESC LIMIT ?",
        [$like, $like, $limit]
    ),
    'customer' => db_select(
        "SELECT id, company_name AS title, status AS badge,
                CONCAT(contact_name, ' · ', COALESCE(email,'')) AS subtitle
         FROM customers
         WHERE deleted_at IS NULL AND (company_name LIKE ? OR contact_name LIKE ? OR email LIKE ?)
         ORDER BY company_name ASC LIMIT ?",
        [$like, $like, $like, $limit]
    ),
    'payment' => db_select(
        "SELECT p.id, p.reference_number AS title, p.status AS badge,
                CONCAT(c.company_name, ' · \$', p.amount) AS subtitle
         FROM payments p JOIN customers c ON c.id = p.customer_id
         WHERE p.deleted_at IS NULL AND c.deleted_at IS NULL
           AND (p.reference_number LIKE ? OR c.company_name LIKE ?)
         ORDER BY p.created_at DESC LIMIT ?",
        [$like, $like, $limit]
    ),
    'work_order' => db_select(
        "SELECT wo.id, wo.work_order_number AS title, wo.status AS badge,
                CONCAT(et.category, ' · ', eu.unit_number) AS subtitle
         FROM maintenance_work_orders wo
         JOIN equipment_units eu ON eu.id = wo.equipment_unit_id
         JOIN equipment_templates et ON et.id = eu.template_id
         WHERE wo.deleted_at IS NULL AND eu.deleted_at IS NULL
           AND (wo.work_order_number LIKE ? OR eu.unit_number LIKE ?)
         ORDER BY wo.created_at DESC LIMIT ?",
        [$like, $like, $limit]
    ),
    'damage_claim' => db_select(
        "SELECT dc.id, dc.claim_number AS title, dc.status AS badge,
                CONCAT(c.company_name, ' · \$', COALESCE(dc.total_amount,'0')) AS subtitle
         FROM damage_claims dc JOIN customers c ON c.id = dc.customer_id
         WHERE dc.deleted_at IS NULL AND c.deleted_at IS NULL
           AND (dc.claim_number LIKE ? OR c.company_name LIKE ?)
         ORDER BY dc.created_at DESC LIMIT ?",
        [$like, $like, $limit]
    ),
    // WHY: reservations has no number column or start_date — match by id/name, pickup_date for subtitle
    'reservation' => db_select(
        "SELECT r.id,
                CONCAT('#', r.id, IF(COALESCE(r.contact_name,'') != '', CONCAT(' — ', r.contact_name), '')) AS title,
                r.status AS badge,
                CONCAT(c.company_name, ' · ', r.pickup_date) AS subtitle
         FROM reservations r JOIN customers c ON c.id = r.customer_id
         WHERE r.deleted_at IS NULL AND c.deleted_at IS NULL
           AND (CAST(r.id AS CHAR) LIKE ? OR c.company_name LIKE ? OR r.contact_name LIKE ?)
         ORDER BY r.created_at DESC LIMIT ?",
        [$like, $like, $like, $limit]
    ),
    'equipment' => db_select(
        "SELECT eu.id, eu.unit_number AS title, eu.status AS badge,
                CONCAT(eu.year, ' ', et.brand, ' ', et.model) AS subtitle
         FROM equipment_units eu JOIN equipment_templates et ON et.id = eu.template_id
         WHERE eu.deleted_at IS NULL AND et.deleted_at IS NULL
           AND (eu.unit_number LIKE ? OR et.brand LIKE ? OR et.model LIKE ?)
         ORDER BY eu.unit_number ASC LIMIT ?",
        [$like, $like, $like, $limit]
    ),
    default => [],
};

json_success(['type' => $type, 'results' => $results]);
