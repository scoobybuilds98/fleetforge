<?php
declare(strict_types=1);

/**
 * api/v1/accounting/disclosure/note.php
 *
 * GET — fetch a single disclosure note row.
 * POST — update note_content (sets is_auto_generated=0; subsequent
 *        generateAll() runs will skip this note).
 *
 * @method  GET | POST
 * @query   (GET) fiscal_year, note_number
 * @body    (POST) JSON: fiscal_year, note_number, note_content
 * @auth    Session required; require_permission('journal_entries', view|edit)
 * @returns 200 { note: {...} } | 404 not found | 422 validation
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.9
 * Session: S-ACCT-DISC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\DisclosureService;

require_auth_api();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_permission('journal_entries', 'view');

    $fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
    $noteNumber = clean_int($_GET['note_number'] ?? null);

    if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
        json_error('VALIDATION_ERROR', 'fiscal_year is required and must be a 4-digit year.', 422);
    }
    if (!$noteNumber || $noteNumber < 1 || $noteNumber > 9) {
        json_error('VALIDATION_ERROR', 'note_number must be 1-9.', 422);
    }

    $note = DisclosureService::getNote($fiscalYear, $noteNumber);
    if (!$note) {
        json_error('NOT_FOUND', "Note {$noteNumber} for FY{$fiscalYear} not found. Run generate first.", 404);
    }

    json_success(['note' => $note]);
}

if ($method === 'POST') {
    require_permission('journal_entries', 'edit');

    $body       = json_body();
    $fiscalYear = clean_int($body['fiscal_year'] ?? null);
    $noteNumber = clean_int($body['note_number'] ?? null);
    $content    = isset($body['note_content']) ? (string) $body['note_content'] : '';

    if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
        json_error('VALIDATION_ERROR', 'fiscal_year is required and must be a 4-digit year.', 422);
    }
    if (!$noteNumber || $noteNumber < 1 || $noteNumber > 9) {
        json_error('VALIDATION_ERROR', 'note_number must be 1-9.', 422);
    }
    if (trim($content) === '') {
        json_error('VALIDATION_ERROR', 'note_content cannot be empty.', 422);
    }

    try {
        $note = DisclosureService::updateNote($fiscalYear, $noteNumber, $content, current_user_id());
    } catch (\RuntimeException $e) {
        json_error('NOT_FOUND', $e->getMessage(), 404);
    }

    json_success(['note' => $note]);
}

json_error('METHOD_NOT_ALLOWED', 'GET or POST required.', 405);
