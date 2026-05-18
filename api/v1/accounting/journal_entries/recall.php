<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/recall.php
 *
 * Recall a submitted AJE back to draft (S-ACCT-AJE).
 * Allowed only to the original submitter or a super_admin. Resets
 * entry_status to 'draft' and clears submitted_by_id + submitted_at.
 *
 * @method  POST
 * @body    JSON: id (required — journal entry ID)
 * @auth    Session required; require_permission('journal_entries','edit');
 *          caller must be original submitter OR super_admin
 * @returns 200 updated entry | 403 FORBIDDEN | 404 NOT_FOUND | 422 validation error
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

// Caller gate (C2): only the original submitter or a super_admin may recall.
// We read the JE here (no FOR UPDATE — the service re-reads inside the txn)
// to enforce the gate before delegating.
$entry = db_row(
    "SELECT id, entry_number, submitted_by_id FROM acc_journal_entries WHERE id = ?",
    [$id]
);
if (!$entry) {
    json_error('NOT_FOUND', 'Journal entry not found.', 404);
}

$userId = current_user_id();
$isSubmitter = $entry['submitted_by_id'] !== null && (int) $entry['submitted_by_id'] === (int) $userId;
if (!$isSubmitter && !is_super_admin()) {
    json_error(
        'FORBIDDEN',
        'Only the original submitter or a super-admin can recall this entry.',
        403
    );
}

try {
    $updated = JournalEntryService::recall($id, $userId);
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }
    json_validation_error(['id' => $message], $message);
}

json_success($updated);
