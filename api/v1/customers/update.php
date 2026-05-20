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

$body   = json_body();
$fields = [];

// ── Required: id + updated_at ──── VALID-2: accumulate ─────────
$id = clean_int($body['id'] ?? null);
if (!$id || $id <= 0) {
    $fields['id'] = 'A valid customer ID is required.';
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null, 30);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}

if ($fields) {
    json_validation_error($fields);
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
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This customer was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This customer was modified by another user. Refresh and try again.']]);
}

// ── Optional scalar fields ─────────────────────────────────────
// Track whether company_name was explicitly sent so we know whether to enforce non-empty
if (array_key_exists('company_name', $body)) {
    $companyName = clean_string($body['company_name'], 255);
    if (!$companyName) {
        $fields['company_name'] = 'Company name is required.';
        $companyName = $existing['company_name'];
    }
} else {
    $companyName = $existing['company_name'];
}
$contactName  = array_key_exists('contact_name', $body)  ? clean_string($body['contact_name'], 255) : null;

// VALID-2: validate email format if provided
$email = null;
if (array_key_exists('email', $body)) {
    $rawEmail = $body['email'];
    if ($rawEmail === null || $rawEmail === '') {
        $email = null;
    } else {
        $email = clean_email($rawEmail);
        if ($email === null) {
            $fields['email'] = 'Please enter a valid email address.';
        }
    }
}
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

// VALID-2: validate billing_email format if provided
$billingEmail = null;
if (array_key_exists('billing_email', $body)) {
    $rawBillingEmail = $body['billing_email'];
    if ($rawBillingEmail !== null && $rawBillingEmail !== '') {
        $billingEmail = clean_email($rawBillingEmail);
        if ($billingEmail === null) {
            $fields['billing_email'] = 'Please enter a valid billing email address.';
        }
    }
}

$billingPhone       = array_key_exists('billing_phone', $body)        ? clean_string($body['billing_phone'], 50) : null;
$billingAddress     = array_key_exists('billing_address', $body)      ? clean_string($body['billing_address'], 5000) : null;

// Tax exemption fields (D22)
$gstExempt       = array_key_exists('gst_exempt', $body)       ? (bool) $body['gst_exempt'] : null;
$pstExempt       = array_key_exists('pst_exempt', $body)       ? (bool) $body['pst_exempt'] : null;

// S-ACCT-DISC: related-party flag for ASPE 3840 Note 6 disclosure.
$isRelatedParty  = array_key_exists('is_related_party', $body) ? (bool) $body['is_related_party'] : null;
$gstExemptNumber = array_key_exists('gst_exempt_number', $body)? clean_string($body['gst_exempt_number'], 100) : null;
$pstExemptNumber = array_key_exists('pst_exempt_number', $body)? clean_string($body['pst_exempt_number'], 100) : null;
$gstExemptExpiry = array_key_exists('gst_exempt_expiry', $body)? clean_date($body['gst_exempt_expiry']) : null;
$pstExemptExpiry = array_key_exists('pst_exempt_expiry', $body)? clean_date($body['pst_exempt_expiry']) : null;
$taxRateId       = array_key_exists('tax_rate_id', $body)      ? clean_int($body['tax_rate_id']) : null;

// Enum fields
$currency = array_key_exists('currency', $body)
    ? (in_array($body['currency'], ['CAD', 'USD'], true) ? $body['currency'] : null)
    : null;
// FIX #11: mileage_unit was missing from update — add it
$mileageUnit = array_key_exists('mileage_unit', $body)
    ? (in_array($body['mileage_unit'], ['km', 'miles'], true) ? $body['mileage_unit'] : null)
    : null;
// S-ACCT-GPS: gps_revenue_presentation toggle (ASPE 3400 net/gross).
// Per-customer policy (D-GPS-1). Validates against the ENUM — invalid
// values silently become NULL so the field is skipped in the update.
$gpsRevenuePresentation = array_key_exists('gps_revenue_presentation', $body)
    ? (in_array($body['gps_revenue_presentation'], ['net', 'gross'], true) ? $body['gps_revenue_presentation'] : null)
    : null;

$billingCycle = array_key_exists('billing_cycle', $body)
    ? (in_array($body['billing_cycle'], ['monthly', 'on_close_only'], true) ? $body['billing_cycle'] : null)
    : null;
$invoiceDelivery = array_key_exists('invoice_delivery', $body)
    ? (in_array($body['invoice_delivery'], ['email', 'mail', 'portal', 'none'], true) ? $body['invoice_delivery'] : null)
    : null;
// VALID-2: validate invoice_email format if provided
$invoiceEmail = null;
if (array_key_exists('invoice_email', $body)) {
    $rawInvoiceEmail = $body['invoice_email'];
    if ($rawInvoiceEmail !== null && $rawInvoiceEmail !== '') {
        $invoiceEmail = clean_email($rawInvoiceEmail);
        if ($invoiceEmail === null) {
            $fields['invoice_email'] = 'Please enter a valid invoice email address.';
        }
    }
}

$poRequired      = array_key_exists('po_required', $body)      ? (bool) $body['po_required'] : null;
$defaultPoNumber = array_key_exists('default_po_number', $body)? clean_string($body['default_po_number'], 100) : null;
$paymentTerms    = array_key_exists('payment_terms', $body)    ? clean_string($body['payment_terms'], 100) : null;

// VALID-2: credit_limit must be >= 0 with clear message
$creditLimit = null;
if (array_key_exists('credit_limit', $body)) {
    $raw = $body['credit_limit'];
    if ($raw !== null && $raw !== '') {
        $d = clean_decimal($raw);
        if ($d === null || bccomp($d, '0', 4) < 0) {
            $fields['credit_limit'] = 'Credit limit cannot be negative.';
        } else {
            $creditLimit = $d;
        }
    }
}

$discountType = null;
if (array_key_exists('discount_type', $body)) {
    if (in_array($body['discount_type'], ['none', 'percentage', 'flat'], true)) {
        $discountType = $body['discount_type'];
    } else {
        $fields['discount_type'] = 'Please select a valid discount type.';
    }
}

// VALID-2: discount must be >= 0; percentage type capped at 100
$discountValue = null;
if (array_key_exists('discount_value', $body)) {
    $raw = $body['discount_value'];
    if ($raw !== null && $raw !== '') {
        $d = clean_decimal($raw);
        if ($d === null || bccomp($d, '0', 4) < 0) {
            $fields['discount_value'] = 'Discount cannot be negative.';
        } elseif ($discountType === 'percentage' && bccomp($d, '100', 4) > 0) {
            $fields['discount_value'] = 'Percentage discount cannot exceed 100%.';
        } else {
            $discountValue = $d;
        }
    }
}

$validStatuses = ['active', 'inactive', 'pending', 'suspended', 'credit_hold'];
$status = array_key_exists('status', $body)
    ? (in_array($body['status'], $validStatuses, true) ? $body['status'] : null)
    : null;
$validRisk = ['low', 'medium', 'high'];
$riskScore = array_key_exists('risk_score', $body)
    ? (in_array($body['risk_score'], $validRisk, true) ? $body['risk_score'] : null)
    : null;
$riskNotes = array_key_exists('risk_notes', $body) ? clean_string($body['risk_notes'], 5000) : null;

// Short-circuit if any single-field errors accumulated
if ($fields) {
    json_validation_error($fields);
}

// FIX #10: check for duplicate company_name when it's being changed
if (array_key_exists('company_name', $body) && $companyName !== $existing['company_name']) {
    // Use the email being set (or the existing email from DB) for the dupe check
    $checkEmail = $email ?? $existing['email'];
    if (db_exists('customers', 'company_name = ? AND email = ? AND id != ?', [$companyName, $checkEmail, $id])) {
        json_validation_error(
            ['company_name' => 'Another customer with this company name and email already exists.'],
            'Another customer with this company name and email already exists.'
        );
    }
}

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
            "Cannot change customer status from '{$currentStatus}' to '{$status}'.", 409,
            ['fields' => ['status' => "Cannot change status from '{$currentStatus}' to '{$status}'."]]);
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
    'mileage_unit'        => $mileageUnit,  // FIX #11
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
if ($gpsRevenuePresentation !== null) $data['gps_revenue_presentation'] = $gpsRevenuePresentation;
if ($pstExempt !== null)  $data['pst_exempt']  = $pstExempt  ? 1 : 0;
if ($poRequired !== null) $data['po_required'] = $poRequired ? 1 : 0;
if ($isRelatedParty !== null) $data['is_related_party'] = $isRelatedParty ? 1 : 0;

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

    // ── In-app status-change notifications (NOTIF-1) ───────────
    // Only fire when the status actually changed and is one we care about.
    try {
        $newStatus = $data['status'] ?? null;
        $oldStatus = $existing['status'] ?? null;
        $name      = $data['company_name'] ?? $existing['company_name'];
        if ($newStatus && $newStatus !== $oldStatus) {
            if ($newStatus === 'credit_hold') {
                \FleetForge\Notifications\NotificationService::notify(
                    type:       'customer.credit_hold',
                    title:      "{$name} placed on credit hold",
                    message:    "{$name} placed on credit hold",
                    entityType: 'customer',
                    entityId:   $id,
                    url:        '/fleetforge/customers/show?id=' . $id,
                    severity:   'warning'
                );
            } elseif ($newStatus === 'suspended') {
                \FleetForge\Notifications\NotificationService::notify(
                    type:       'customer.suspended',
                    title:      "{$name} suspended",
                    message:    "{$name} account suspended",
                    entityType: 'customer',
                    entityId:   $id,
                    url:        '/fleetforge/customers/show?id=' . $id,
                    severity:   'critical'
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF customer.status_change] ' . $e->getMessage());
    }
});

// Re-fetch updated_at after the update
$updated = db_row("SELECT id, company_name, updated_at FROM customers WHERE id = ?", [$id]);

// QBO sync enqueue (S-QBO-6). No-op when sync_enabled='0' (D-CPA-5
// default) or mode rejects pushes. Never throws — sync is best-effort
// and must not break customer-update flows.
\FleetForge\QboPushers\CustomerEnqueuer::enqueue($id, 'update');

json_success([
    'id'           => $id,
    'company_name' => $updated['company_name'] ?? $existing['company_name'],
    'updated_at'   => $updated['updated_at'] ?? null,
]);
