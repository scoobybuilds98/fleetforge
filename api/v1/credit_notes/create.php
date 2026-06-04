<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/create.php
 *
 * Create a new credit note for a customer. Credit notes are issued manually
 * (goodwill, damage resolution, invoice adjustment, etc.) or auto-created
 * by InvoiceGenerator for mileage overpayment or payment_returned scenarios.
 *
 * Business rules:
 *   - Credit note number: CN-CR-YYYY-NNNNN, gap-free via FOR UPDATE on settings row
 *     (same atomic counter pattern as PAY-YYYY-NNNNN — D15)
 *   - D16: Amount via bcmath — positive, 2 decimal places stored
 *   - Customer must exist and not be soft-deleted
 *   - lease_id (optional): must belong to the specified customer
 *   - source_invoice_id (optional): must belong to the specified customer
 *   - source_payment_id (optional): must belong to the specified customer
 *   - Initial status always 'active'; amount_remaining = amount
 *
 * @method  POST
 * @body    JSON: customer_id (required), source (required), amount (required),
 *               currency (required), reason (required),
 *               lease_id?, source_invoice_id?, source_payment_id?,
 *               expires_at?, internal_notes?
 * @auth    Session required; require_permission('invoices','create')
 * @returns 201 { id, credit_note_number, amount, currency, status }
 *
 * Decisions: D15/D16, §9 Atomic counter pattern, §7 Audit log
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

// -----------------------------------------------------------------------
// 1. Input validation
// -----------------------------------------------------------------------
$body = json_body();

$customerId = clean_int($body['customer_id'] ?? null);
if (!$customerId) {
    json_error('MISSING_REQUIRED', 'customer_id is required.', 422);
}

$source = clean_string($body['source'] ?? null);
$validSources = ['mileage_overpayment', 'invoice_adjustment', 'damage_resolution', 'goodwill', 'payment_returned', 'other'];
if (!$source || !in_array($source, $validSources, true)) {
    json_error('VALIDATION_ERROR', 'source is required and must be one of: ' . implode(', ', $validSources) . '.', 422);
}

$amountRaw = clean_decimal($body['amount'] ?? null);
if ($amountRaw === null || bccomp($amountRaw, '0', 6) <= 0) {
    json_error('VALIDATION_ERROR', 'amount must be a positive number.', 422);
}
$amount = bcround($amountRaw, 2);

$currency = strtoupper(clean_string($body['currency'] ?? 'CAD') ?? 'CAD');
if (!in_array($currency, ['CAD', 'USD'], true)) {
    json_error('VALIDATION_ERROR', 'currency must be CAD or USD.', 422);
}

$reason = clean_string($body['reason'] ?? null, 2000);
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required.', 422);
}

// Optional fields
$leaseId         = clean_int($body['lease_id'] ?? null);
$sourceInvoiceId = clean_int($body['source_invoice_id'] ?? null);
$sourcePaymentId = clean_int($body['source_payment_id'] ?? null);
$expiresAt       = clean_date($body['expires_at'] ?? null);
$internalNotes   = clean_string($body['internal_notes'] ?? null, 2000);

// -----------------------------------------------------------------------
// 2. Pre-flight: verify customer, lease, invoice, payment belong together
// -----------------------------------------------------------------------
$customer = db_row(
    "SELECT id, company_name, contact_name, billing_address, province, email
     FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

if ($leaseId) {
    $leaseCheck = db_row(
        "SELECT id FROM leases WHERE id = ? AND customer_id = ? AND deleted_at IS NULL",
        [$leaseId, $customerId]
    );
    if (!$leaseCheck) {
        json_error('NOT_FOUND', 'Lease not found or does not belong to this customer.', 404);
    }
}

if ($sourceInvoiceId) {
    $invCheck = db_row(
        "SELECT id FROM invoices WHERE id = ? AND customer_id = ? AND deleted_at IS NULL",
        [$sourceInvoiceId, $customerId]
    );
    if (!$invCheck) {
        json_error('NOT_FOUND', 'Source invoice not found or does not belong to this customer.', 404);
    }
}

if ($sourcePaymentId) {
    $payCheck = db_row(
        "SELECT id FROM payments WHERE id = ? AND customer_id = ? AND deleted_at IS NULL",
        [$sourcePaymentId, $customerId]
    );
    if (!$payCheck) {
        json_error('NOT_FOUND', 'Source payment not found or does not belong to this customer.', 404);
    }
}

// -----------------------------------------------------------------------
// 3. Transaction: generate gap-free number, insert row, audit
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $customerId, $source, $amount, $currency, $reason,
    $leaseId, $sourceInvoiceId, $sourcePaymentId,
    $expiresAt, $internalNotes, $customer, &$result
) {
    // ------------------------------------------------------------------
    // 3a. Gap-free credit note number via FOR UPDATE on settings row
    //     Pattern: CN-CR-YYYY-NNNNN
    // ------------------------------------------------------------------
    $year = date('Y');
    $key  = "credit_note.next_number.{$year}";

    $settingsRow = db_row(
        "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
        [$key]
    );
    $next             = $settingsRow ? (int) $settingsRow['value'] : 1;
    // WHY: prefix from settings so admin can rebrand without code change
    $prefix           = settings_get('credit_note.prefix', 'CN-CR');
    $creditNoteNumber = sprintf('%s-%s-%05d', $prefix, $year, $next);

    if ($settingsRow) {
        db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$next + 1, $key]
        );
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'credit_notes')",
            [$key, $next + 1]
        );
    }

    // ------------------------------------------------------------------
    // 3b. Insert credit note row
    //     amount_remaining = amount (full balance available at creation)
    //     status = 'active'
    // ------------------------------------------------------------------
    $cnId = db_insert('credit_notes', [
        'credit_note_number'      => $creditNoteNumber,
        'company_name_snapshot'   => $customer['company_name']   ?? null,
        'customer_name_snapshot'  => $customer['contact_name']   ?? null,
        'billing_address_snapshot' => $customer['billing_address'] ?? null,
        'province_snapshot'        => $customer['province']        ?? null,
        'customer_email_snapshot'  => $customer['email']           ?? null,
        'customer_id'             => $customerId,
        'lease_id'                => $leaseId,
        'source'                  => $source,
        'source_invoice_id'       => $sourceInvoiceId,
        'source_payment_id'       => $sourcePaymentId,
        'amount'                  => $amount,
        'currency'                => $currency,
        'amount_remaining'        => $amount,
        'status'                  => 'active',
        'expires_at'              => $expiresAt,
        'reason'                  => $reason,
        'internal_notes'          => $internalNotes,
        'created_by'              => current_user_id(),
    ]);

    // ------------------------------------------------------------------
    // 3c. Audit log — inside transaction (FIX #19 pattern)
    // ------------------------------------------------------------------
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note',
        'entity_id'    => $cnId,
        'entity_label' => $creditNoteNumber,
        'notes'        => "Credit note {$creditNoteNumber} ({$currency} {$amount}) created for {$customer['company_name']}. Source: {$source}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE: DR 4xxx Revenue / CR 2060 Customer Credits Liability
    // WHY: PASS-6:G2 — credit note creation posts to liability, NOT to AR directly.
    // AR is only reduced when the credit is APPLIED to an invoice.
    // Inside same transaction — JE failure rolls back the credit note creation (A8, §16).
    \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued($cnId, current_user_id());

    $result = [
        'id'                 => $cnId,
        'credit_note_number' => $creditNoteNumber,
        'amount'             => $amount,
        'amount_remaining'   => $amount,
        'currency'           => $currency,
        'status'             => 'active',
    ];
});

// S-QBO-16: enqueue QBO CreditMemo push after the credit-note insert commits.
// Best-effort per D-ENQUEUER-CONTRACT — never throws; gate-0/1/2/3 inside the
// enqueuer reject when ineligible (sync_enabled='0' default per D-CPA-5 means
// this is a silent no-op until S-QBO-30 cutover). Mirrors send.php (S-QBO-11).
if (is_array($result) && !empty($result['id'])) {
    try {
        \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $result['id'], 'create');
    } catch (\Throwable $e) {
        error_log("credit_notes/create.php: CreditMemoEnqueuer threw despite best-effort discipline: " . $e->getMessage());
    }
}

json_success($result, 201);
