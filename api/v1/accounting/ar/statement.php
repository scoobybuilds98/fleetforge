<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/statement.php
 *
 * Customer statement PDF — generates a statement showing opening balance,
 * all invoices, payments, credit notes, and write-offs for a date range,
 * with a closing balance and aged summary.
 *
 * @method  GET
 * @query   customer_id (required), date_from?, date_to?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns PDF binary stream (Content-Type: application/pdf)
 *
 * Decisions: A9 (AR subledger is FleetForge billing)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Customer statements)
 * Session: S031, S-PDF-LETTERHEAD (letterhead + layout via FleetForge\Pdf\PdfKit),
 *          S-PORTAL-REDESIGN (build/render moved to FleetForge\Billing\CustomerStatement)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Billing\CustomerStatement;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$dateTo   = clean_date($_GET['date_to'] ?? null) ?? date('Y-m-d');
$dateFrom = clean_date($_GET['date_from'] ?? null) ?? date('Y-m-01', strtotime('-3 months'));

// S-PORTAL-REDESIGN: the statement is built and rendered by
// FleetForge\Billing\CustomerStatement so the customer portal's self-serve
// statement and this staff copy can never disagree about a balance.
$statement = CustomerStatement::build($customerId, $dateFrom, $dateTo);
if ($statement === null) json_error('NOT_FOUND', 'Customer not found.', 404);

try {
    $bytes = CustomerStatement::renderPdf($statement);
} catch (\Throwable $e) {
    error_log('[ar/statement] customer ' . $customerId . ': ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not produce the statement PDF. Please try again.', 500);
}

PdfKit::stream($bytes, CustomerStatement::filename($statement));
