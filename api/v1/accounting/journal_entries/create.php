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
$body   = json_body();
$fields = [];

$entryDate = clean_date($body['entry_date'] ?? null);
$desc      = clean_string($body['description'] ?? null, 1000);

if (!$entryDate) {
    $fields['entry_date'] = 'Entry date is required.';
}
if (!$desc) {
    $fields['description'] = 'Description is required.';
}
if (empty($body['lines']) || !is_array($body['lines'])) {
    $fields['lines'] = 'At least 2 journal lines are required.';
} elseif (count($body['lines']) < 2) {
    $fields['lines'] = 'Journal entry must have at least 2 lines.';
} elseif (count($body['lines']) > 50) {
    $fields['lines'] = 'Journal entry cannot exceed 50 lines.';
}

if ($fields) {
    json_validation_error($fields);
}

// --- Build header and lines for the service ---
$entryType        = clean_string($body['entry_type'] ?? 'manual', 50);
$postImmediately  = (bool) ($body['post_immediately'] ?? false);

// AJE types must enter the draft → review workflow (S-ACCT-AJE / spec §23.1).
// Force post_immediately=false so the service stamps entry_status='draft'
// and the entry begins the submit/approve chain.
if (JournalEntryService::isAjeType($entryType)) {
    $postImmediately = false;
}

$header = [
    'entry_date'       => $entryDate,
    'description'      => $desc,
    'reference'        => clean_string($body['reference'] ?? null, 255),
    'entry_type'       => $entryType,
    'post_immediately' => $postImmediately,
];

// ── Per-line validation — accumulate line-level problems
$lineErrors = [];
$lines      = [];
$totalDebit  = '0.00';
$totalCredit = '0.00';
foreach ($body['lines'] as $i => $raw) {
    $lineNum    = $i + 1;
    $accountId  = clean_int($raw['account_id'] ?? null) ?? 0;
    $debit      = clean_decimal($raw['debit']  ?? null) ?? '0.00';
    $credit     = clean_decimal($raw['credit'] ?? null) ?? '0.00';

    if (!$accountId) {
        $lineErrors[] = "Line {$lineNum}: please select an account.";
    }
    // Negative amounts — guard before summing
    if (bccomp($debit, '0', 2) < 0) {
        $lineErrors[] = "Line {$lineNum}: debit cannot be negative.";
    }
    if (bccomp($credit, '0', 2) < 0) {
        $lineErrors[] = "Line {$lineNum}: credit cannot be negative.";
    }
    if (bccomp($debit, '0.00', 2) > 0 && bccomp($credit, '0.00', 2) > 0) {
        $lineErrors[] = "Line {$lineNum}: cannot have both a debit and a credit on the same line.";
    }
    if (bccomp($debit, '0.00', 2) === 0 && bccomp($credit, '0.00', 2) === 0) {
        $lineErrors[] = "Line {$lineNum}: must have either a debit or a credit.";
    }

    $totalDebit  = bcadd($totalDebit,  $debit,  2);
    $totalCredit = bcadd($totalCredit, $credit, 2);

    $lines[] = [
        'account_id'  => $accountId,
        'debit'       => $debit,
        'credit'      => $credit,
        'description' => clean_string($raw['description'] ?? null, 500),
    ];
}

// Balance check — debits must equal credits
if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
    $lineErrors[] = "Entry is unbalanced: debits ({$totalDebit}) must equal credits ({$totalCredit}).";
}
if (bccomp($totalDebit, '0.00', 2) === 0) {
    $lineErrors[] = "Entry total cannot be zero.";
}

if ($lineErrors) {
    json_validation_error(
        ['lines' => implode(' ', $lineErrors)],
        'Please fix the line errors and try again.'
    );
}

// --- Create via service (throws RuntimeException on validation failure) ---
try {
    $entry = JournalEntryService::create($header, $lines, current_user_id());
} catch (\RuntimeException $e) {
    // Map service errors to field slots when possible
    $msg    = $e->getMessage();
    $slot   = 'lines';
    if (stripos($msg, 'period') !== false) {
        $slot = 'entry_date';
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($entry, 201);
