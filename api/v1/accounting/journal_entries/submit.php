<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/submit.php
 *
 * Submit an AJE draft for review (S-ACCT-AJE / spec §23.1).
 * Transitions entry_status 'draft' → 'submitted' and stamps the
 * submitter. Only valid for AJE types (adjusting, reclassifying,
 * prior_period). Delegates all logic to JournalEntryService::submit().
 *
 * @method  POST
 * @body    JSON: id (required — journal entry ID)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 updated entry | 422 validation error | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php, JournalEntryService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body   = json_body();
$fields = [];
$id     = clean_int($body['id'] ?? null);

if (!$id) {
    $fields['id'] = 'Journal entry ID is required.';
}
if ($fields) {
    json_validation_error($fields);
}

try {
    $entry = JournalEntryService::submit($id, current_user_id());
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }
    json_validation_error(['id' => $message], $message);
}

json_success($entry);
