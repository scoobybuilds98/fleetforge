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

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $entry = JournalEntryService::post($id, current_user_id());
} catch (\RuntimeException $e) {
    // WHY: Service throws for not-found, already-posted, period-closed, and unbalanced
    $message = $e->getMessage();

    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }

    json_error('VALIDATION_ERROR', $message, 422);
}

json_success($entry);
