<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/create.php
 *
 * Create a new journal entry (draft or posted immediately).
 * Accepts a JSON body with header fields and an array of lines.
 * Delegates all validation and persistence to JournalEntryService::create().
 *
 * Business rules enforced by the service:
 *   - At least 2 lines, max 50
 *   - Debits must equal credits (balanced entry)
 *   - Each line posts to a valid, active, non-header account
 *   - Period must exist for the entry_date and be open (if posting immediately)
 *
 * @method  POST
 * @body    JSON: { entry_date, description, reference?, entry_type?,
 *                  lines: [{ account_id, debit, credit, description? }],
 *                  post_immediately? }
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 created entry | 422 validation error
 *
 * @depends api/bootstrap.php, JournalEntryService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

// --- Read and validate JSON body ---
$body = json_body();

if (empty($body['entry_date'])) {
    json_error('VALIDATION_ERROR', 'entry_date is required.', 422);
}

if (empty($body['description'])) {
    json_error('VALIDATION_ERROR', 'description is required.', 422);
}

if (empty($body['lines']) || !is_array($body['lines'])) {
    json_error('VALIDATION_ERROR', 'lines array is required and must contain at least 2 entries.', 422);
}

// --- Build header and lines for the service ---
$header = [
    'entry_date'       => clean_date($body['entry_date']),
    'description'      => clean_string($body['description'] ?? '', 1000),
    'reference'        => clean_string($body['reference'] ?? null, 255),
    'entry_type'       => clean_string($body['entry_type'] ?? 'manual', 50),
    'post_immediately' => (bool) ($body['post_immediately'] ?? false),
];

$lines = [];
foreach ($body['lines'] as $i => $raw) {
    $lines[] = [
        'account_id'  => clean_int($raw['account_id'] ?? null) ?? 0,
        'debit'       => clean_decimal($raw['debit'] ?? null) ?? '0.00',
        'credit'      => clean_decimal($raw['credit'] ?? null) ?? '0.00',
        'description' => clean_string($raw['description'] ?? null, 500),
    ];
}

// --- Create via service (throws RuntimeException on validation failure) ---
try {
    $entry = JournalEntryService::create($header, $lines, current_user_id());
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($entry, 201);
