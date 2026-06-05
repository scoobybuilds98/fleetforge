<?php
declare(strict_types=1);

/**
 * FleetForge — Credit Application Invite Preview API
 *
 * @file        api/v1/credit_applications/preview.php
 * @description Compiles the credit_application_invite email template for the
 *              given customer and returns the rendered subject + body_html so
 *              the admin can preview what will be sent before clicking Send.
 *
 *              The CTA href in the preview points to ?admin_preview=1 on the
 *              credit application form — clicking it lets the operator inspect
 *              the form layout without a real token being generated (D-CCA-1 /
 *              Trap 7: raw token never pre-created for preview; emailed once only
 *              when the operator clicks Send).
 *
 * @method      GET
 * @query       customer_id (required int)
 * @auth        Session required; require_permission('customers','view')
 * @returns     200 { subject, body_html, to_email }
 *              404 if customer not found
 *
 * @depends     api/bootstrap.php, lib/Email/EmailService.php
 * @session     S-CCA-1, S-CCA-BTN-SETTINGS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Email\EmailService;

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId || $customerId <= 0) {
    json_error('INVALID_ID', 'A valid customer_id is required.', 400);
}

$customer = db_row(
    "SELECT id, company_name, contact_name, email, billing_email
       FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

$toEmail = clean_string($customer['email'] ?: ($customer['billing_email'] ?? '')) ?: '(no email on file)';
$toName  = (string) ($customer['contact_name'] ?: $customer['company_name']);

// Compile the template.  The CTA href uses the admin-preview URL so operators
// can click "Start Your Credit Application" in the preview and immediately see
// the form layout (auth-gated via ?admin_preview=1 in credit-application.php).
// No real token is generated here — Trap 7 / D-CCA-1.
$adminPreviewUrl = base_url('credit-application') . '?admin_preview=1';

$compiled = EmailService::compileTemplate(
    db_row("SELECT id FROM email_templates WHERE slug = 'credit_application_invite' AND is_active = 1 AND deleted_at IS NULL", [])['id'] ?? 0,
    [
        'customer_name'             => $toName,
        'credit_application_url'    => $adminPreviewUrl,
        'minimum_requirements_html' => (string) settings_get('credit_application.minimum_requirements_html', ''),
    ]
);

json_success([
    'subject'   => $compiled['subject'],
    'body_html' => EmailService::renderEmailHtml($compiled['body_html']),
    'to_email'  => $toEmail,
]);
