<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/preview.php
 *
 * GET ?template_id=N&customer_id=Y&entity_type=invoice&entity_id=Z
 *
 * Returns the COMPILED template (subject + body with variables
 * substituted) using REAL data from the customer/entity if
 * provided. Lets the compose modal show a realistic preview
 * before the user clicks Send.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

use FleetForge\Email\EmailService;

$templateId = (int)($_GET['template_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$entityType = clean_string($_GET['entity_type'] ?? null, 50);
$entityId   = (int)($_GET['entity_id'] ?? 0);

if ($templateId <= 0) {
    json_error('VALIDATION_ERROR', 'template_id is required.', 422);
}

// Build the variable map from real data:
//   1. Company-wide vars (always)
//   2. Customer vars
//   3. Entity vars (invoice/lease/payment/equipment_unit)
$vars = EmailService::companyVariables();
if ($customerId > 0) {
    $vars = array_merge($vars, EmailService::resolveCustomerVariables($customerId));
}
if ($entityType && $entityId > 0) {
    $vars = array_merge($vars, EmailService::resolveEntityVariables($entityType, $entityId));
}

$compiled = EmailService::compileTemplate($templateId, $vars);

if ($compiled['subject'] === '' && $compiled['body_html'] === '') {
    json_error('NOT_FOUND', 'Email template not found.', 404);
}

json_success($compiled);
