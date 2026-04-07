<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Create API
 *
 * @file        api/v1/customers/create.php
 * @description Creates a new customer record. Inserts tags and an initial note
 *              (if provided) in the same transaction. Logs to audit_log.
 *              Returns 422 on duplicate company_name + email combination.
 *
 * @method      POST
 * @body        JSON — see required/optional fields below
 * @required    company_name
 * @optional    contact_name, email, phone, alt_phone, website,
 *              address, city, state, province, postal_code, country,
 *              tax_id, dot_number, mc_number, gst_number, pst_number,
 *              billing_contact_name, billing_email, billing_phone, billing_address,
 *              currency (CAD|USD), mileage_unit (miles|km),
 *              gst_exempt (bool), pst_exempt (bool),
 *              gst_exempt_number, pst_exempt_number,
 *              gst_exempt_expiry (Y-m-d), pst_exempt_expiry (Y-m-d),
 *              tax_rate_id (int),
 *              billing_cycle (monthly|on_close_only), invoice_delivery,
 *              invoice_email, po_required (bool), default_po_number,
 *              discount_type, discount_value, payment_terms,
 *              status (active|inactive|pending|suspended|credit_hold),
 *              risk_score (low|medium|high), credit_limit,
 *              tags (array of tag strings),
 *              notes (string — initial customer note)
 * @auth        Session required; require_permission('customers','create')
 * @returns     201 { id, company_name } on success
 *              422 on validation errors or duplicate
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'create');

$body   = json_body();
$fields = [];

// ── Required fields ── VALID-2: accumulate every error ─────────
$companyName = clean_string($body['company_name'] ?? null, 255);
if (!$companyName) {
    $fields['company_name'] = 'Company name is required.';
}

// ── Optional scalar fields ─────────────────────────────────────
$contactName = clean_string($body['contact_name'] ?? null, 255);

// VALID-2: reject invalid email format with a clear message
$rawEmail = $body['email'] ?? null;
$email    = null;
if ($rawEmail !== null && $rawEmail !== '') {
    $email = clean_email($rawEmail);
    if ($email === null) {
        $fields['email'] = 'Please enter a valid email address.';
    }
}

$phone        = clean_string($body['phone'] ?? null, 50);
$altPhone     = clean_string($body['alt_phone'] ?? null, 50);
$website      = clean_string($body['website'] ?? null, 500);
$address      = clean_string($body['address'] ?? null, 500);
$city         = clean_string($body['city'] ?? null, 100);
$state        = clean_string($body['state'] ?? null, 100);
$province     = clean_string($body['province'] ?? null, 100);
$postalCode   = clean_string($body['postal_code'] ?? null, 20);
$country      = clean_string($body['country'] ?? null, 100) ?? 'Canada';
$taxId        = clean_string($body['tax_id'] ?? null, 100);
$dotNumber    = clean_string($body['dot_number'] ?? null, 100);
$mcNumber     = clean_string($body['mc_number'] ?? null, 100);
$gstNumber    = clean_string($body['gst_number'] ?? null, 50);
$pstNumber    = clean_string($body['pst_number'] ?? null, 50);

// Billing
$billingContactName = clean_string($body['billing_contact_name'] ?? null, 255);

// VALID-2: validate billing_email format
$rawBillingEmail = $body['billing_email'] ?? null;
$billingEmail    = null;
if ($rawBillingEmail !== null && $rawBillingEmail !== '') {
    $billingEmail = clean_email($rawBillingEmail);
    if ($billingEmail === null) {
        $fields['billing_email'] = 'Please enter a valid billing email address.';
    }
}

$billingPhone       = clean_string($body['billing_phone'] ?? null, 50);
$billingAddress     = clean_string($body['billing_address'] ?? null, 5000);

// Tax exemption (D22 — gst and pst are independent booleans)
$gstExempt       = isset($body['gst_exempt']) ? (bool) $body['gst_exempt'] : false;
$pstExempt       = isset($body['pst_exempt']) ? (bool) $body['pst_exempt'] : false;
$gstExemptNumber = clean_string($body['gst_exempt_number'] ?? null, 100);
$pstExemptNumber = clean_string($body['pst_exempt_number'] ?? null, 100);
$gstExemptExpiry = clean_date($body['gst_exempt_expiry'] ?? null);
$pstExemptExpiry = clean_date($body['pst_exempt_expiry'] ?? null);
$taxRateId       = clean_int($body['tax_rate_id'] ?? null);

// Commercial fields
$rawCurrency     = $body['currency']         ?? 'CAD';
$rawMileage      = $body['mileage_unit']    ?? 'km';
$rawCycle        = $body['billing_cycle']   ?? 'monthly';
$rawDelivery     = $body['invoice_delivery'] ?? 'email';
$currency        = in_array($rawCurrency, ['CAD', 'USD'], true)                              ? $rawCurrency : 'CAD';
$mileageUnit     = in_array($rawMileage, ['km', 'miles'], true)                              ? $rawMileage  : 'km';
$billingCycle    = in_array($rawCycle, ['monthly', 'on_close_only'], true)                   ? $rawCycle    : 'monthly';
$invoiceDelivery = in_array($rawDelivery, ['email', 'mail', 'portal', 'none'], true)         ? $rawDelivery : 'email';
// VALID-2: validate invoice_email format
$rawInvoiceEmail = $body['invoice_email'] ?? null;
$invoiceEmail    = null;
if ($rawInvoiceEmail !== null && $rawInvoiceEmail !== '') {
    $invoiceEmail = clean_email($rawInvoiceEmail);
    if ($invoiceEmail === null) {
        $fields['invoice_email'] = 'Please enter a valid invoice email address.';
    }
}

$poRequired      = isset($body['po_required']) ? (bool) $body['po_required'] : false;
$defaultPoNumber = clean_string($body['default_po_number'] ?? null, 100);
$paymentTerms    = clean_string($body['payment_terms'] ?? null, 100);

// VALID-2: credit_limit must be >= 0 with a clear message
$creditLimit = null;
$rawCredit   = $body['credit_limit'] ?? null;
if ($rawCredit !== null && $rawCredit !== '') {
    $d = clean_decimal($rawCredit);
    if ($d === null || bccomp($d, '0', 4) < 0) {
        $fields['credit_limit'] = 'Credit limit cannot be negative.';
    } else {
        $creditLimit = $d;
    }
}

// Discount
$rawDiscountType = $body['discount_type'] ?? 'none';
$discountType    = in_array($rawDiscountType, ['none', 'percentage', 'flat'], true) ? $rawDiscountType : 'none';

// VALID-2: discount must be >= 0; percentage type capped at 100
$discountValue = '0.0000';
$rawDiscount   = $body['discount_value'] ?? null;
if ($rawDiscount !== null && $rawDiscount !== '') {
    $d = clean_decimal($rawDiscount);
    if ($d === null || bccomp($d, '0', 4) < 0) {
        $fields['discount_value'] = 'Discount cannot be negative.';
    } elseif ($discountType === 'percentage' && bccomp($d, '100', 4) > 0) {
        $fields['discount_value'] = 'Percentage discount cannot exceed 100%.';
    } else {
        $discountValue = $d;
    }
}

// Status / risk
$validStatuses = ['active', 'inactive', 'pending', 'suspended', 'credit_hold'];
$rawStatus     = $body['status'] ?? 'active';
$status        = in_array($rawStatus, $validStatuses, true) ? $rawStatus : 'active';
$validRisk     = ['low', 'medium', 'high'];
$rawRisk       = $body['risk_score'] ?? 'low';
$riskScore     = in_array($rawRisk, $validRisk, true) ? $rawRisk : 'low';
$riskNotes     = clean_string($body['risk_notes'] ?? null, 5000);

// Tags
$validTags = ['vip','preferred','owner-operator','fleet','net-30','net-45','net-60',
              'cod','tax-exempt','high-risk','watchlist','credit-hold','delinquent',
              'new','seasonal','government','broker'];
$tagsInput = is_array($body['tags'] ?? null) ? $body['tags'] : [];
$tags      = array_values(array_filter($tagsInput, fn($t) => in_array($t, $validTags, true)));

// Initial note
$noteText  = clean_string($body['notes'] ?? null, 10000);

// Short-circuit before DB duplicate lookup if we already have errors
if ($fields) {
    json_validation_error($fields);
}

// ── Duplicate check ────────────────────────────────────────────
// UNIQUE KEY uq_company_email (company_name, email)
// WHY manual check: returns a clean 422 with field-level error instead of DB exception
$dupExists = db_exists(
    'customers',
    'company_name = ? AND email = ?',
    [$companyName, $email]
);
if ($dupExists) {
    json_validation_error(
        ['company_name' => 'A customer with this company name and email already exists.'],
        'A customer with this company name and email already exists.'
    );
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

// ── Insert (transaction: customer + tags + optional note + audit_log) ──
$newId = null;

db_transaction(function () use (
    &$newId, $userId, $now,
    $companyName, $contactName, $email, $phone, $altPhone, $website,
    $address, $city, $state, $province, $postalCode, $country,
    $taxId, $dotNumber, $mcNumber, $gstNumber, $pstNumber,
    $billingContactName, $billingEmail, $billingPhone, $billingAddress,
    $currency, $mileageUnit, $billingCycle, $invoiceDelivery,
    $invoiceEmail, $poRequired, $defaultPoNumber, $paymentTerms, $creditLimit,
    $gstExempt, $pstExempt, $gstExemptNumber, $pstExemptNumber,
    $gstExemptExpiry, $pstExemptExpiry, $taxRateId,
    $discountType, $discountValue, $status, $riskScore, $riskNotes,
    $tags, $noteText
): void {
    $newId = db_insert('customers', [
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
        'currency'            => $currency,
        'mileage_unit'        => $mileageUnit,
        'gst_exempt'          => $gstExempt ? 1 : 0,
        'pst_exempt'          => $pstExempt ? 1 : 0,
        'gst_exempt_number'   => $gstExemptNumber,
        'pst_exempt_number'   => $pstExemptNumber,
        'gst_exempt_expiry'   => $gstExemptExpiry,
        'pst_exempt_expiry'   => $pstExemptExpiry,
        'tax_rate_id'         => $taxRateId,
        'billing_cycle'       => $billingCycle,
        'invoice_delivery'    => $invoiceDelivery,
        'invoice_email'       => $invoiceEmail,
        'po_required'         => $poRequired ? 1 : 0,
        'default_po_number'   => $defaultPoNumber,
        'discount_type'       => $discountType,
        'discount_value'      => $discountValue,
        'payment_terms'       => $paymentTerms,
        'credit_limit'        => $creditLimit,
        'status'              => $status,
        'risk_score'          => $riskScore,
        'risk_notes'          => $riskNotes,
        'created_by'          => $userId,
        'updated_by'          => $userId,
    ]);

    // Insert tags
    foreach ($tags as $tag) {
        db_insert('customer_tags', [
            'customer_id' => $newId,
            'tag'         => $tag,
        ]);
    }

    // Insert initial note if provided
    if ($noteText !== null) {
        db_insert('customer_notes', [
            'customer_id' => $newId,
            'note'        => $noteText,
            'is_pinned'   => 0,
            'created_by'  => $userId,
        ]);
    }

    // Audit log
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'create',
        'module'       => 'customers',
        'entity_type'  => 'customer',
        'entity_id'    => $newId,
        'entity_label' => $companyName,
        'new_values'   => json_encode(['company_name' => $companyName, 'status' => $status]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $newId, 'company_name' => $companyName], 201);
