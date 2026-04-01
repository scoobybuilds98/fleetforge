<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Detail API
 *
 * @file        api/v1/customers/show.php
 * @description Returns a single customer record by ID, including their tags array
 *              and contacts array. Soft-deleted customers return 404.
 *
 * @method      GET
 * @params      id (required, int) — customer ID
 * @auth        Session required; require_permission('customers','view')
 * @returns     { customer: { ...all fields, tags: [...], contacts: [...] } }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

// ── Input ──────────────────────────────────────────────────────
$id = clean_int($_GET['id'] ?? null);
if (!$id || $id <= 0) {
    json_error('INVALID_ID', 'A valid customer ID is required.', 400);
}

// ── Fetch customer — FIX #31: explicit column list (no SELECT *) ──
// Explicit columns prevent leaking internal fields if schema changes.
$customer = db_row(
    "SELECT
        id, company_name, contact_name, email, phone, alt_phone, website,
        address, city, state, province, postal_code, country,
        status, risk_score, risk_notes,
        tax_id, dot_number, mc_number, gst_number, pst_number,
        gst_exempt, pst_exempt, tax_exempt,
        gst_exempt_number, pst_exempt_number, gst_exempt_expiry, pst_exempt_expiry,
        tax_rate_id,
        billing_contact_name, billing_email, billing_phone, billing_address,
        invoice_delivery, invoice_email, invoice_cc_emails,
        currency, billing_cycle, mileage_unit,
        payment_terms, po_required, default_po_number, credit_limit,
        discount_type, discount_value,
        outstanding_balance, total_invoiced, total_paid,
        active_lease_count, lease_count,
        late_fee_enabled, late_fee_rate, late_fee_grace_days,
        notes, internal_notes,
        created_at, updated_at, created_by, updated_by
     FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Fetch tags ─────────────────────────────────────────────────
$tagRows = db_select(
    "SELECT tag FROM customer_tags WHERE customer_id = ? ORDER BY tag",
    [$id]
);
$tags = array_column($tagRows, 'tag');

// ── Fetch contacts ─────────────────────────────────────────────
// customer_contacts has no deleted_at — CASCADE delete on customer
$contacts = db_select(
    "SELECT id, name, title, email, phone, is_primary, notes, created_at
       FROM customer_contacts
      WHERE customer_id = ?
      ORDER BY is_primary DESC, name ASC",
    [$id]
);

// Cast types for contacts
foreach ($contacts as &$contact) {
    $contact['id']         = (int) $contact['id'];
    $contact['is_primary'] = (bool) $contact['is_primary'];
}
unset($contact);

// ── Build response ─────────────────────────────────────────────
$customer['id']                 = (int) $customer['id'];
$customer['tax_exempt']         = (bool) $customer['tax_exempt'];
$customer['gst_exempt']         = (bool) $customer['gst_exempt'];
$customer['pst_exempt']         = (bool) $customer['pst_exempt'];
$customer['po_required']        = (bool) $customer['po_required'];
$customer['late_fee_enabled']   = (bool) $customer['late_fee_enabled'];
$customer['active_lease_count'] = (int) $customer['active_lease_count'];
$customer['lease_count']        = (int) $customer['lease_count'];
$customer['tags']               = $tags;
$customer['contacts']           = $contacts;

// Decode JSON fields
if (isset($customer['invoice_cc_emails']) && $customer['invoice_cc_emails'] !== null) {
    $customer['invoice_cc_emails'] = json_decode($customer['invoice_cc_emails'], true) ?? [];
}

json_success(['customer' => $customer]);
