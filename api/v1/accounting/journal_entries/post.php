<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/post.php
 *
 * Post a draft journal entry — transitions status from 'draft' to 'posted'.
 * Validates that the entry is balanced and its period is open.
 * Delegates all logic to JournalEntryService::post().
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
    $entry = JournalEntryService::post($id, current_user_id());
} catch (\RuntimeException $e) {
    // WHY: Service throws for not-found, already-posted, period-closed, and unbalanced
    $message = $e->getMessage();

    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }

    // Route period-closed to entry_date, balance to lines, status conflict to id
    $slot = 'id';
    if (stripos($message, 'period') !== false)                        $slot = 'entry_date';
    elseif (stripos($message, 'unbalanced') !== false)                $slot = 'lines';
    elseif (stripos($message, 'already') !== false)                   $slot = 'id';
    json_validation_error([$slot => $message], $message);
}

json_success($entry);
