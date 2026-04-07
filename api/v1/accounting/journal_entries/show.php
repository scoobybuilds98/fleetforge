<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/show.php
 *
 * Return a single journal entry with all lines and account info.
 * Uses JournalEntryService::getWithLines() which joins account code/name
 * onto each line row.
 *
 * @method  GET
 * @query   id (required — journal entry ID)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 entry + lines | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php, JournalEntryService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$id = require_id($_GET['id'] ?? null);

$entry = JournalEntryService::getWithLines($id);

if (!$entry) {
    json_error('NOT_FOUND', 'Journal entry not found.', 404);
}

json_success($entry);
