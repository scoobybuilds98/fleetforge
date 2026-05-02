<?php
/**
 * api/v1/chat/search/records.php
 *
 * GET ?type=invoice&q=ABC&limit=10
 * Searches FleetForge records to attach to messages.
 * Returns standardised preview data for each result.
 *
 * Supported types: invoice, lease, customer, payment, work_order,
 *                  damage_claim, reservation, equipment
 *
 * WHY: Single search endpoint per type keeps each query optimised.
 *      LIKE used for broad compatibility (no FULLTEXT on all tables).
 *      Always filters deleted_at IS NULL.
 *      Target: < 200ms.
 *
 * Dependencies: api/bootstrap.php, invoices, leases, customers, payments,
 *               maintenance_work_orders, damage_claims, reservations,
 *               equipment_units
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$type  = clean_string($_GET['type'] ?? null, 50);
$q     = clean_string($_GET['q'] ?? null, 255);
$limit = min(10, max(1, clean_int($_GET['limit'] ?? 10) ?? 10));

if (!$type) json_error('MISSING_REQUIRED', 'type is required.', 422);

$validTypes = ['invoice','lease','customer','payment','work_order',
               'damage_claim','reservation','equipment'];
if (!in_array($type, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid type.', 422);
}

$results = [];
$sq      = '%' . ($q ?? '') . '%';

switch ($type) {

    case 'invoice':
        $rows = db_select(
            "SELECT i.id, i.invoice_number, i.status, i.total_amount,
                    c.company_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
             WHERE i.deleted_at IS NULL
               AND (i.invoice_number LIKE ? OR c.company_name LIKE ?)
             ORDER BY i.id DESC LIMIT {$limit}",
            [$sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'       => (int)$r['id'],
                'type'     => 'invoice',
                'title'    => $r['invoice_number'],
                'subtitle' => ($r['company_name'] ?? '') . ' · $' . number_format((float)($r['total_amount'] ?? 0), 2),
                'badge'    => $r['status'],
                'badge_class' => match($r['status']) {
                    'paid'          => 'badge-success',
                    'sent','overdue'=> 'badge-warning',
                    'void'          => 'badge-danger',
                    'draft'         => 'badge-neutral',
                    default         => 'badge-info',
                },
                'url' => '/fleetforge/invoices/show?id=' . $r['id'],
            ];
        }
        break;

    case 'lease':
        $rows = db_select(
            "SELECT l.id, l.contract_number, l.status, c.company_name
             FROM leases l
             JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
             WHERE l.deleted_at IS NULL
               AND (l.contract_number LIKE ? OR c.company_name LIKE ?)
             ORDER BY l.id DESC LIMIT {$limit}",
            [$sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'lease',
                'title'       => $r['contract_number'],
                'subtitle'    => ($r['company_name'] ?? '') . ' · ' . ucfirst($r['status'] ?? ''),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'active'    => 'badge-success',
                    'pending'   => 'badge-warning',
                    'completed' => 'badge-neutral',
                    'cancelled' => 'badge-danger',
                    default     => 'badge-info',
                },
                'url' => '/fleetforge/leases/show?id=' . $r['id'],
            ];
        }
        break;

    case 'customer':
        $rows = db_select(
            "SELECT id, company_name, contact_name, email, status
             FROM customers
             WHERE deleted_at IS NULL
               AND (company_name LIKE ? OR contact_name LIKE ? OR email LIKE ?)
             ORDER BY company_name ASC LIMIT {$limit}",
            [$sq, $sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'customer',
                'title'       => $r['company_name'],
                'subtitle'    => ($r['contact_name'] ?? '') . ($r['email'] ? ' · ' . $r['email'] : ''),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'active'    => 'badge-success',
                    'suspended' => 'badge-danger',
                    default     => 'badge-neutral',
                },
                'url' => '/fleetforge/customers/show?id=' . $r['id'],
            ];
        }
        break;

    case 'payment':
        $rows = db_select(
            "SELECT p.id, p.payment_number, p.amount, p.status, p.currency,
                    c.company_name
             FROM payments p
             JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
             WHERE p.deleted_at IS NULL
               AND (p.payment_number LIKE ? OR c.company_name LIKE ?)
             ORDER BY p.id DESC LIMIT {$limit}",
            [$sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'payment',
                'title'       => $r['payment_number'],
                'subtitle'    => ($r['company_name'] ?? '') . ' · ' . ($r['currency'] ?? 'CAD') . ' $' . number_format((float)($r['amount'] ?? 0), 2),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'applied'  => 'badge-success',
                    'pending'  => 'badge-warning',
                    'refunded' => 'badge-danger',
                    default    => 'badge-neutral',
                },
                'url' => '/fleetforge/payments/show?id=' . $r['id'],
            ];
        }
        break;

    case 'work_order':
        $rows = db_select(
            "SELECT mwo.id, mwo.work_order_number, mwo.title, mwo.status,
                    eu.unit_number
             FROM maintenance_work_orders mwo
             LEFT JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
             WHERE mwo.deleted_at IS NULL
               AND (mwo.work_order_number LIKE ? OR eu.unit_number LIKE ? OR mwo.title LIKE ?)
             ORDER BY mwo.id DESC LIMIT {$limit}",
            [$sq, $sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'work_order',
                'title'       => $r['work_order_number'],
                'subtitle'    => ($r['unit_number'] ? 'Unit ' . $r['unit_number'] . ' · ' : '') . ($r['title'] ?? ''),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'completed'    => 'badge-success',
                    'in_progress'  => 'badge-info',
                    'waiting_parts'=> 'badge-warning',
                    'open'         => 'badge-neutral',
                    'cancelled'    => 'badge-danger',
                    default        => 'badge-neutral',
                },
                'url' => '/fleetforge/maintenance/show?id=' . $r['id'],
            ];
        }
        break;

    case 'damage_claim':
        $rows = db_select(
            "SELECT dc.id, dc.claim_number, dc.status, eu.unit_number, c.company_name
             FROM damage_claims dc
             LEFT JOIN equipment_units eu ON eu.id = dc.equipment_unit_id
             LEFT JOIN customers c ON c.id = dc.customer_id AND c.deleted_at IS NULL
             WHERE dc.deleted_at IS NULL
               AND (dc.claim_number LIKE ? OR eu.unit_number LIKE ?)
             ORDER BY dc.id DESC LIMIT {$limit}",
            [$sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'damage_claim',
                'title'       => $r['claim_number'],
                'subtitle'    => ($r['unit_number'] ? 'Unit ' . $r['unit_number'] : '') . ($r['company_name'] ? ' · ' . $r['company_name'] : ''),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'resolved'     => 'badge-success',
                    'assessed'     => 'badge-info',
                    'reported'     => 'badge-warning',
                    'written_off'  => 'badge-danger',
                    default        => 'badge-neutral',
                },
                'url' => '/fleetforge/damage-claims/show?id=' . $r['id'],
            ];
        }
        break;

    case 'reservation':
        $rows = db_select(
            // WHY: reservations has no number column — match by id cast + name, per api/v1/search.php
            "SELECT r.id, r.contact_name, r.pickup_date, r.status, c.company_name
             FROM reservations r
             JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
             WHERE r.deleted_at IS NULL
               AND (CAST(r.id AS CHAR) LIKE ? OR c.company_name LIKE ? OR r.contact_name LIKE ?)
             ORDER BY r.id DESC LIMIT {$limit}",
            [$sq, $sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'reservation',
                'title'       => '#' . (int)$r['id'] . ($r['contact_name'] ? ' — ' . $r['contact_name'] : ''),
                'subtitle'    => $r['company_name'] ?? '',
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'confirmed' => 'badge-success',
                    'pending'   => 'badge-warning',
                    'cancelled' => 'badge-danger',
                    'completed' => 'badge-neutral',
                    default     => 'badge-info',
                },
                'url' => '/fleetforge/reservations/show?id=' . $r['id'],
            ];
        }
        break;

    case 'equipment':
        $rows = db_select(
            "SELECT eu.id, eu.unit_number, eu.vin, eu.status,
                    et.name AS template_name
             FROM equipment_units eu
             LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
             WHERE eu.deleted_at IS NULL
               AND (eu.unit_number LIKE ? OR eu.vin LIKE ? OR eu.serial_number LIKE ?)
             ORDER BY eu.unit_number ASC LIMIT {$limit}",
            [$sq, $sq, $sq]
        );
        foreach ($rows as $r) {
            $results[] = [
                'id'          => (int)$r['id'],
                'type'        => 'equipment',
                'title'       => 'Unit ' . $r['unit_number'],
                'subtitle'    => ($r['template_name'] ?? '') . ($r['vin'] ? ' · ' . $r['vin'] : ''),
                'badge'       => $r['status'],
                'badge_class' => match($r['status']) {
                    'available'   => 'badge-success',
                    'on_lease'    => 'badge-info',
                    'maintenance' => 'badge-warning',
                    'reserved'    => 'badge-purple',
                    'inactive'    => 'badge-neutral',
                    default       => 'badge-neutral',
                },
                'url' => '/fleetforge/equipment/show?id=' . $r['id'],
            ];
        }
        break;
}

json_success(['results' => $results, 'type' => $type, 'query' => $q]);
