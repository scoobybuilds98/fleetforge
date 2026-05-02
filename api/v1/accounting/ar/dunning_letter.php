<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/dunning_letter.php
 *
 * Generate and record a dunning letter for a customer.
 * Delegates PDF + storage + acc_dunning_letters write to
 * \FleetForge\Accounting\DunningLetterGenerator (S-CRON-3 refactor).
 *
 * Endpoint responsibility:
 *   1. Auth + permission + request validation
 *   2. Call DunningLetterGenerator::generate()
 *   3. Email send (dev: logs/mail.log, prod: SES)
 *   4. JSON response
 *
 * @method  POST
 * @body    customer_id (required), letter_type (required), sent_method?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, letter_type, pdf_filename }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Dunning letters)
 * Session: S031 (original), S-CRON-3 (refactor to shared generator)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$customerId = clean_int($input['customer_id'] ?? null);
$letterType = clean_string($input['letter_type'] ?? null);
$sentMethod = clean_string($input['sent_method'] ?? null) ?? 'email';

if (!$customerId) $fields['customer_id'] = 'Please select a customer.';

if (!$letterType) {
    $fields['letter_type'] = 'Please select a letter type.';
} elseif (!in_array($letterType, \FleetForge\Accounting\DunningLetterGenerator::LETTER_TYPES, true)) {
    $fields['letter_type'] = 'Letter type must be one of: '
        . implode(', ', \FleetForge\Accounting\DunningLetterGenerator::LETTER_TYPES) . '.';
}

$validMethods = ['email', 'mail', 'both'];
if (!in_array($sentMethod, $validMethods, true)) {
    $fields['sent_method'] = 'Delivery method must be email, mail, or both.';
}

if ($fields) {
    json_validation_error($fields);
}

// Generate via shared library — pdf, storage upload, acc_dunning_letters row,
// and audit_log entry all happen inside generate().
try {
    $result = \FleetForge\Accounting\DunningLetterGenerator::generate(
        customerId: $customerId,
        letterType: $letterType,
        sentMethod: $sentMethod,
        createdBy:  current_user_id(),
    );
} catch (\InvalidArgumentException $e) {
    // Customer not found → 404
    json_error('NOT_FOUND', $e->getMessage(), 404, [
        'fields' => ['customer_id' => 'Customer not found.'],
    ]);
} catch (\DomainException $e) {
    // No overdue invoices → validation error
    json_validation_error(
        ['customer_id' => 'No overdue invoices found for this customer.'],
        'No overdue invoices found for this customer.'
    );
} catch (\Throwable $e) {
    // PDF/storage failure
    error_log('[dunning_letter endpoint] ' . $e->getMessage());
    json_error('GENERATION_FAILED', 'Could not generate dunning letter.', 500);
}

// Email send — endpoint-side concern. In dev (no SES creds) Mailer falls
// back to logs/mail.log automatically; production sends via SES.
if (in_array($sentMethod, ['email', 'both'], true) && !empty($result['customer']['email'])) {
    \FleetForge\Notifications\Mailer::send(
        toEmail:  $result['customer']['email'],
        toName:   (string) ($result['customer']['contact_name'] ?: $result['customer']['company_name']),
        subject:  $result['subject'],
        htmlBody: $result['html_body'],
    );
}

json_success([
    'id'            => $result['id'],
    'letter_type'   => $result['letter_type'],
    'pdf_filename'  => $result['pdf_filename'],
    'total_overdue' => $result['total_overdue'],
    'invoice_count' => $result['invoice_count'],
], 201);
