<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/reverse.php
 *
 * Reverse a posted journal entry — creates a new entry with debits and credits
 * swapped, marks the original as 'reversed', and links the two via
 * reversal_of_id / reversed_by_id.
 *
 * Delegates all logic to JournalEntryService::reverse().
 *
 * @method  POST
 * @body    JSON: id (required), reversal_date (optional — defaults to today)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 new reversal entry | 422 validation error | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php, JournalEntryService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body         = json_body();
$fields       = [];
$id           = clean_int($body['id'] ?? null);
$reversalDate = clean_date($body['reversal_date'] ?? null);

if (!$id) {
    $fields['id'] = 'Journal entry ID is required.';
}
if ($fields) {
    json_validation_error($fields);
}

try {
    $reversalEntry = JournalEntryService::reverse($id, $reversalDate, current_user_id());
} catch (\RuntimeException $e) {
    // WHY: Service throws for not-found, not-posted, already-reversed, and period issues
    $message = $e->getMessage();

    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }

    $slot = 'id';
    if (stripos($message, 'period') !== false) $slot = 'reversal_date';
    json_validation_error([$slot => $message], $message);
}

json_success($reversalEntry);
