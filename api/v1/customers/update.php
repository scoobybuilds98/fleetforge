<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Update API
 *
 * @file        api/v1/customers/update.php
 * @description Updates an existing customer. Implements D19 optimistic locking:
 *              the caller must submit the customer's current updated_at timestamp;
 *              if it differs from the DB record, the update is rejected with 409.
 *              Tags are replaced wholesale: pass the complete desired tags array.
 *
 * @method      POST
 * @body        JSON
 * @required    id (int), updated_at (datetime string — optimistic lock per D19)
 * @optional    All editable customer fields (same as create.php);
 *              tags (array) — replaces all existing tags if present
 * @auth        Session required; require_permission('customers','edit')
 * @returns     200 { id, company_name, updated_at }
 *              409 STALE_DATA if updated_at mismatch
 *              404 if customer not found
 *              422 on validation errors
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @design      D19 optimistic locking on all update endpoints
 * @session     S005
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

$body = json_body();

// ── Required: id + updated_at ──────────────────────────────────
$id = clean_int($body['id'] ?? null);
if (!$id || $id <= 0) {
    json_error('INVALID_ID', 'A valid customer ID is required.', 400);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null, 30);
if (!$submittedUpdatedAt) {
    json_error('MISSING_LOCK', 'updated_at is required for optimistic locking (D19).', 422);
}

// ── Load existing record ────────────────────────────────────────
$existing = db_row(
    "SELECT id, company_name, email, status, updated_at FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$existing) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── D19 Optimistic lock check ──────────────────────────────────
// Compare submitted updated_at against DB — reject if stale.
// WHY: prevents overwriting changes made by another user since the form was loaded.
if ($submittedUpdatedAt !== $existing['updated_at']) {
    json_error('STALE_DATA', 'This record was modified by another user. Please reload and try again.', 409);
}

// ── Optional scalar fields ─────────────────────────────────────
$companyName = clean_string($body['company_name'] ?? $existing['company_name'], 255) ?? $existing['company_name'];
$contactName  = array_key_exists('contact_name', $body)  ? clean_string($body['contact_name'], 255) : null;
$email        = array_key_exists('email', $body)         ? clean_email($body['email']) : null;
$phone        = array_key_exists('phone', $body)         ? clean_string($body['phone'], 50) : null;
$altPhone     = array_key_exists('alt_phone', $body)     ? clean_string($body['alt_phone'], 50) : null;
$website      = array_key_exists('website', $body)       ? clean_string($body['website'], 500) : null;
$address      = array_key_exists('address', $body)       ? clean_string($body['address'], 500) : null;
$city         = array_key_exists('city', $body)          ? clean_string($body['city'], 100) : null;
$state        = array_key_exists('state', $body)         ? clean_string($body['state'], 100) : null;
$province     = array_key_exists('province', $body)      ? clean_string($body['province'], 100) : null;
$postalCode   = array_key_exists('postal_code', $body)   ? clean_string($body['postal_code'], 20) : null;
$country      = array_key_exists('country', $body)       ? clean_string($body['country'], 100) : null;
$taxId        = array_key_exists('tax_id', $body)        ? clean_string($body['tax_id'], 100) : null;
$dotNumber    = array_key_exists('dot_number', $body)    ? clean_string($body['dot_number'], 100) : null;
$mcNumber     = array_key_exists('mc_number', $body)     ? clean_string($body['mc_number'], 100) : null;
$gstNumber    = array_key_exists('gst_number', $body)    ? clean_string($body['gst_number'], 50) : null;
$pstNumber    = array_key_exists('pst_number', $body)    ? clean_string($body['pst_number'], 50) : null;

$billingContactName = array_key_exists('billing_contact_name', $body) ? clean_string($body['billing_contact_name'], 255) : null;
$billingEmail       = array_key_exists('billing_email', $body)        ? clean_email($body['billing_email']) : null;
$billingPhone       = array_key_exists('billing_phone', $body)        ? clean_string($body['billing_phone'], 50) : null;
$billingAddress     = array_key_exists('billing_address', $body)      ? clean_string($body['billing_address'], 5000) : null;

// Tax exemption fields (D22)
$gstExempt       = array_key_exists('gst_exempt', $body)       ? (bool) $body['gst_exempt'] : null;
$pstExempt       = array_key_exists('pst_exempt', $body)       ? (bool) $body['pst_exempt'] : null;
$gstExemptNumber = array_key_exists('gst_exempt_number', $body)? clean_string($body['gst_exempt_number'], 100) : null;
$pstExemptNumber = array_key_exists('pst_exempt_number', $body)? clean_string($body['pst_exempt_number'], 100) : null;
$gstExemptExpiry = array_key_exists('gst_exempt_expiry', $body)? clean_date($body['gst_exempt_expiry']) : null;
$pstExemptExpiry = array_key_exists('pst_exempt_expiry', $body)? clean_date($body['pst_exempt_expiry']) : null;
$taxRateId       = array_key_exists('tax_rate_id', $body)      ? clean_int($body['tax_rate_id']) : null;

// Enum fields
$currency = array_key_exists('currency', $body)
    ? (in_array($body['currency'], ['CAD', 'USD'], true) ? $body['currency'] : null)
    : null;
$billingCycle = array_key_exists('billing_cycle', $body)
    ? (in_array($body['billing_cycle'], ['monthly', 'on_close_only'], true) ? $body['billing_cycle'] : null)
    : null;
$invoiceDelivery = array_key_exists('invoice_delivery', $body)
    ? (in_array($body['invoice_delivery'], ['email', 'mail', 'portal', 'none'], true) ? $body['invoice_delivery'] : null)
    : null;
$invoiceEmail    = array_key_exists('invoice_email', $body)    ? clean_email($body['invoice_email']) : null;
$poRequired      = array_key_exists('po_required', $body)      ? (bool) $body['po_required'] : null;
$defaultPoNumber = array_key_exists('default_po_number', $body)? clean_string($body['default_po_number'], 100) : null;
$paymentTerms    = array_key_exists('payment_terms', $body)    ? clean_string($body['payment_terms'], 100) : null;
$creditLimit     = array_key_exists('credit_limit', $body)     ? clean_decimal($body['credit_limit']) : null;
$discountType    = array_key_exists('discount_type', $body)
    ? (in_array($body['discount_type'], ['none', 'percentage', 'flat'], true) ? $body['discount_type'] : null)
    : null;
$discountValue   = array_key_exists('discount_value', $body)   ? clean_decimal($body['discount_value']) : null;

$validStatuses = ['active', 'inactive', 'pending', 'suspended', 'credit_hold'];
$status = array_key_exists('status', $body)
    ? (in_array($body['status'], $validStatuses, true) ? $body['status'] : null)
    : null;
$validRisk = ['low', 'medium', 'high'];
$riskScore = array_key_exists('risk_score', $body)
    ? (in_array($body['risk_score'], $validRisk, true) ? $body['risk_score'] : null)
    : null;
$riskNotes = array_key_exists('risk_notes', $body) ? clean_string($body['risk_notes'], 5000) : null;

// FIX #30: Validate status transitions
// Allowed transitions per spec — prevents invalid status jumps (e.g. suspended → pending)
if ($status !== null && $status !== $existing['status']) {
    $allowedTransitions = [
        'active'      => ['inactive', 'suspended', 'credit_hold'],
        'inactive'    => ['active'],
        'pending'     => ['active', 'inactive'],
        'suspended'   => ['active', 'inactive'],
        'credit_hold' => ['active', 'suspended'],
    ];
    $currentStatus = $existing['status'];
    $allowed = $allowedTransitions[$currentStatus] ?? [];
    if (!in_array($status, $allowed, true)) {
        json_error('INVALID_TRANSITION',
            "Cannot change customer status from '{$currentStatus}' to '{$status}'.", 409);
    }
}

// Tags
$replaceTags = array_key_exists('tags', $body) && is_array($body['tags']);
$validTags   = ['vip','preferred','owner-operator','fleet','net-30','net-45','net-60',
                'cod','tax-exempt','high-risk','watchlist','credit-hold','delinquent',
                'new','seasonal','government','broker'];
$newTags     = $replaceTags
    ? array_values(array_filter($body['tags'], fn($t) => in_array($t, $validTags, true)))
    : [];

// ── Build update data ───────────────────────────────────────────
// WHY: only include fields that were actually sent — partial updates
$data = ['updated_by' => current_user_id()];

$optionals = [
    'company_name'        => $companyName,
    'contact_name'        => $contactName,
    'email'               => $email,
    'phone'               => $phone,
    'alt_phone'           => $altPhone,
    'website'             => $website,
    'address'             => $address,
    'city'                => $city,
    'state'               => $state,
    'province'            => $province,
    'postal_code'         => $postalCode,
    'country'             => $country,
    'tax_id'              => $taxId,
    'dot_number'          => $dotNumber,
    'mc_number'           => $mcNumber,
    'gst_number'          => $gstNumber,
    'pst_number'          => $pstNumber,
    'billing_contact_name'=> $billingContactName,
    'billing_email'       => $billingEmail,
    'billing_phone'       => $billingPhone,
    'billing_address'     => $billingAddress,
    'gst_exempt_number'   => $gstExemptNumber,
    'pst_exempt_number'   => $pstExemptNumber,
    'gst_exempt_expiry'   => $gstExemptExpiry,
    'pst_exempt_expiry'   => $pstExemptExpiry,
    'tax_rate_id'         => $taxRateId,
    'currency'            => $currency,
    'billing_cycle'       => $billingCycle,
    'invoice_delivery'    => $invoiceDelivery,
    'invoice_email'       => $invoiceEmail,
    'default_po_number'   => $defaultPoNumber,
    'payment_terms'       => $paymentTerms,
    'credit_limit'        => $creditLimit,
    'discount_type'       => $discountType,
    'discount_value'      => $discountValue,
    'status'              => $status,
    'risk_score'          => $riskScore,
    'risk_notes'          => $riskNotes,
];

foreach ($optionals as $col => $val) {
    if ($val !== null) {
        $data[$col] = $val;
    }
}

// Bool fields need explicit handling (false is valid)
if ($gstExempt !== null)  $data['gst_exempt']  = $gstExempt  ? 1 : 0;
if ($pstExempt !== null)  $data['pst_exempt']  = $pstExempt  ? 1 : 0;
if ($poRequired !== null) $data['po_required'] = $poRequired ? 1 : 0;

$userId = current_user_id();

db_transaction(function () use (&$data, $id, $replaceTags, $newTags, $userId, $existing, $companyName): void {
    db_update('customers', $data, 'id = ?', [$id]);

    // Replace tags wholesale if the 'tags' key was present in request
    if ($replaceTags) {
        db_execute("DELETE FROM customer_tags WHERE customer_id = ?", [$id]);
        foreach ($newTags as $tag) {
            db_insert('customer_tags', ['customer_id' => $id, 'tag' => $tag]);
        }
    }

    // Audit log
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'update',
        'module'       => 'customers',
        'entity_type'  => 'customer',
        'entity_id'    => $id,
        'entity_label' => $data['company_name'] ?? $existing['company_name'],
        'old_values'   => json_encode(['company_name' => $existing['company_name'], 'updated_at' => $existing['updated_at']]),
        'new_values'   => json_encode(array_intersect_key($data, array_flip(['company_name', 'status', 'risk_score']))),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

// Re-fetch updated_at after the update
$updated = db_row("SELECT id, company_name, updated_at FROM customers WHERE id = ?", [$id]);

json_success([
    'id'           => $id,
    'company_name' => $updated['company_name'] ?? $existing['company_name'],
    'updated_at'   => $updated['updated_at'] ?? null,
]);
