<?php
declare(strict_types=1);

/**
 * api/v1/accounting/disclosure/note-pack.php
 *
 * Returns the full 9-note disclosure pack for a fiscal year. Auto-generates
 * any missing notes (idempotent re-run safe). Three response formats:
 *   - json (default) — returns the 9 rows as JSON
 *   - pdf            — emits a multi-page mPDF document via
 *                      ReportPdfRenderer::disclosureNotePack()
 *   - docx           — 501 stub (Phase E / Accountants Portal scope)
 *
 * @method  GET
 * @query   fiscal_year (required), engagement_type (compilation|review,
 *          optional — defaults to settings.accounting.engagement_type),
 *          format (json|pdf|docx, default json)
 * @auth    Session required; require_permission('journal_entries', 'view')
 * @returns 200 JSON | application/pdf | 501 DOCX | 422
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.9
 * Session: S-ACCT-DISC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\DisclosureService;
use FleetForge\Accounting\ReportPdfRenderer;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear     = clean_int($_GET['fiscal_year'] ?? null);
$engagementType = isset($_GET['engagement_type'])
    ? (string) $_GET['engagement_type']
    : (string) settings_get('accounting.engagement_type', 'compilation');
$format         = strtolower((string) ($_GET['format'] ?? 'json'));

if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required and must be a 4-digit year.', 422);
}
if (!in_array($engagementType, ['compilation', 'review'], true)) {
    json_error('VALIDATION_ERROR', "engagement_type must be 'compilation' or 'review'.", 422);
}
if (!in_array($format, ['json', 'pdf', 'docx'], true)) {
    json_error('VALIDATION_ERROR', "format must be one of: json, pdf, docx.", 422);
}

if ($format === 'docx') {
    json_error('NOT_IMPLEMENTED',
        'DOCX format is reserved for the Phase E Accountants Portal. Use format=pdf or format=json.',
        501);
}

$notes = DisclosureService::loadByYear($fiscalYear);
if (count($notes) < 9) {
    $notes = DisclosureService::generateAll($fiscalYear, current_user_id());
}

if ($format === 'pdf') {
    ReportPdfRenderer::disclosureNotePack([
        'fiscal_year'     => $fiscalYear,
        'engagement_type' => $engagementType,
        'notes'           => $notes,
        'entity_name'     => (string) settings_get('accounting.entity_legal_name', 'The Company'),
        'cpa_firm'        => (string) settings_get('accounting.cpa_firm_name', ''),
        'cpa_designation' => (string) settings_get('accounting.cpa_designation', ''),
        'cpa_city'        => (string) settings_get('accounting.cpa_city', ''),
    ]);
    return;
}

json_success([
    'fiscal_year'     => $fiscalYear,
    'engagement_type' => $engagementType,
    'notes'           => $notes,
]);
