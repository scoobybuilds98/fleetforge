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
 *   2. Customer-email gate (I22) — CustomerReminders::mayEmailCustomer('dunning',
 *      …, requireTypeEnabled: false). A manual operator action skips the
 *      "Dunning letters" type toggle but still honours the Customer Emails
 *      master switch, the global do-not-email list and a bounced address.
 *   3. Call DunningLetterGenerator::generate() — ALWAYS, so a blocked email
 *      still yields a PDF the operator can post (recorded as sent_method
 *      'mail' so the letter log never claims an email that didn't go).
 *   4. Email via CustomerReminders::deliver() (notification_log row, reply-to,
 *      BCC) — was a bare Mailer::send() whose failure was never reported.
 *   5. JSON response incl. email_sent + email_skipped_reason / email_error.
 *
 * @method  POST
 * @body    customer_id (required), letter_type (required), sent_method?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, letter_type, pdf_filename, total_overdue, invoice_count,
 *                sent_method, email_requested, email_sent, email_to,
 *                email_skipped_reason, email_error }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Dunning letters)
 * Session: S031 (original), S-CRON-3 (refactor to shared generator),
 *          I22 (customer-email gating)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Notifications\CustomerReminders;

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

// I22: decide BEFORE generating whether the email may go, so the letter row
// records what really happens. Pure read — nothing is written if it blocks.
$emailRequested = in_array($sentMethod, ['email', 'both'], true);
$gate           = null;
$recordMethod   = $sentMethod;
if ($emailRequested) {
    $gate = CustomerReminders::mayEmailCustomer('dunning', (int) $customerId, false);
    if (!$gate['ok']) {
        // Letter still generated for posting; never claim it was emailed.
        $recordMethod = 'mail';
    }
}

// Generate via shared library — pdf, storage upload, acc_dunning_letters row,
// and audit_log entry all happen inside generate().
try {
    $result = \FleetForge\Accounting\DunningLetterGenerator::generate(
        customerId:  $customerId,
        letterType:  $letterType,
        sentMethod:  $recordMethod,
        createdBy:   current_user_id(),
        sentToEmail: ($gate !== null && $gate['ok']) ? $gate['to'] : null,
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

// Email send — through deliver() so it is logged in notification_log (visible
// in Settings → Customer Emails → delivery log) with reply-to/BCC applied. In
// dev without SES creds Mailer writes logs/mail.log; production sends via SES.
$emailSent    = false;
$emailError   = null;
$skipReason   = null;
if ($emailRequested) {
    if (!$gate['ok']) {
        $skipReason = $gate['reason'];
    } else {
        $delivered = CustomerReminders::deliver([
            'reminder_key' => 'dunning',
            'customer_id'  => (int) $customerId,
            'dedup_type'   => CustomerReminders::config('dunning')['dedup_type'],
            'entity_type'  => 'customer',
            'entity_id'    => (int) $customerId,
            'channels'     => ['email'],   // dunning is email-only (fixed schedule)
            'to_email'     => (string) $gate['to'],
            'to_name'      => (string) $gate['to_name'],
            'subject'      => $result['subject'],
            'body_html'    => $result['html_body'],
            'raw_html'     => true,         // the letter has its own letterhead
            'log_summary'  => "Dunning letter {$result['letter_type']} (#{$result['id']}) — "
                            . "{$result['total_overdue']} overdue across {$result['invoice_count']} invoice(s)",
        ]);
        $emailSent = $delivered['email'] === true;
        if (!$emailSent) {
            // Honest failure: the letter exists (PDF + log row) but the mail
            // did not go — the operator must know to resend or post it.
            $emailError = 'The email could not be sent (see Settings → Customer Emails delivery log).';
        }
    }
}

json_success([
    'id'                   => $result['id'],
    'letter_type'          => $result['letter_type'],
    'pdf_filename'         => $result['pdf_filename'],
    'total_overdue'        => $result['total_overdue'],
    'invoice_count'        => $result['invoice_count'],
    'sent_method'          => $recordMethod,
    'email_requested'      => $emailRequested,
    'email_sent'           => $emailSent,
    'email_to'             => $emailSent ? $gate['to'] : null,
    'email_skipped_reason' => $skipReason,
    'email_error'          => $emailError,
], 201);
