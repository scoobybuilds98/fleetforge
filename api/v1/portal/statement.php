<?php
declare(strict_types=1);

/**
 * api/v1/portal/statement.php
 *
 * Customer portal — self-serve statement of account PDF (S-PORTAL-REDESIGN).
 * Built by FleetForge\Billing\CustomerStatement — the SAME builder staff use
 * (api/v1/accounting/ar/statement.php), so the customer's copy and ours can
 * never disagree about a balance.
 *
 * Range: from/to are company-local business dates. Defaults to the first of
 * the month three months back → today. `to` is clamped to today; the range
 * may not exceed 3 years (keeps the PDF sane).
 *
 * Trap 8: the customer id is portal_customer_id() — never a parameter.
 *
 * @method  GET
 * @query   from?, to?, download (0|1)
 * @auth    portal session
 * @returns application/pdf
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/portal/includes/auth.php';

use FleetForge\Billing\CustomerStatement;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_portal_auth_api();

$today = ff_today();
$to    = clean_date($_GET['to'] ?? null) ?? $today;
if ($to > $today) {
    $to = $today;
}
$from = clean_date($_GET['from'] ?? null)
    ?? (new DateTimeImmutable($today))->modify('first day of -3 months')->format('Y-m-d');
if ($from > $to) {
    json_error('VALIDATION_ERROR', 'The start date must be on or before the end date.', 422);
}
if ($from < ff_local_date_add($to, -1096)) {
    json_error('VALIDATION_ERROR', 'Statements can cover up to three years at a time.', 422);
}

$statement = CustomerStatement::build(portal_customer_id(), $from, $to);
if ($statement === null) {
    json_error('NOT_FOUND', 'Account not found.', 404);
}

try {
    $bytes = CustomerStatement::renderPdf($statement);
} catch (\Throwable $e) {
    error_log('[portal/statement] customer ' . portal_customer_id() . ': ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Your statement could not be produced right now. Please try again shortly.', 500);
}

try {
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'portal:' . portal_user_id(),
        'action'       => 'export',
        'module'       => 'portal',
        'entity_type'  => 'customer_statement',
        'entity_id'    => portal_customer_id(),
        'entity_label' => 'Statement ' . $from . ' → ' . $to,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (\Throwable $e) {
    error_log('[portal/statement audit] ' . $e->getMessage());
}

PdfKit::stream($bytes, CustomerStatement::filename($statement), ($_GET['download'] ?? '') === '1');
