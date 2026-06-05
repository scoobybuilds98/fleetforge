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
 *              The public URL in the preview is a placeholder string — the real
 *              tokenized link is generated at send time (token never pre-created
 *              just for preview, per D-CCA-1 and Trap 7).
 *
 * @method      GET
 * @query       customer_id (required int)
 * @auth        Session required; require_permission('customers','view')
 * @returns     200 { subject, body_html, to_email }
 *              404 if customer not found
 *
 * @depends     api/bootstrap.php, lib/Email/EmailService.php
 * @session     S-CCA-1
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

// Compile the template with a placeholder URL — no token is generated for preview.
$compiled = EmailService::compileTemplate(
    db_row("SELECT id FROM email_templates WHERE slug = 'credit_application_invite' AND is_active = 1 AND deleted_at IS NULL", [])['id'] ?? 0,
    [
        'customer_name'            => $toName,
        'credit_application_url'   => '[Credit Application Link]',
        'minimum_requirements_html' => (string) settings_get('credit_application.minimum_requirements_html', ''),
    ]
);

// FIXPACK-EMAIL-SCROLL-BTN: neutralise all href attributes before returning.
// The credit_application_url placeholder compiles to "[Credit Application Link]"
// in both <a href> and visible text; square brackets fail resolve_route()'s
// /^[a-zA-Z0-9_\-.]+$/ guard → ff_not_found() → 404 on click.  Any other href
// in the template is equally unsafe in a preview context (no real token exists).
// Replacing with javascript:void(0) prevents navigation for all anchors.
// Belt-and-suspenders: the preview-body div also carries @click.capture/prevent.
$bodyHtml = EmailService::renderEmailHtml($compiled['body_html']);
$bodyHtml = (string) preg_replace('/ href="[^"]*"/i', ' href="javascript:void(0)"', $bodyHtml);

json_success([
    'subject'   => $compiled['subject'],
    'body_html' => $bodyHtml,
    'to_email'  => $toEmail,
]);
