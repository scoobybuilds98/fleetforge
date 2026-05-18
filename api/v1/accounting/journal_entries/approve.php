<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/approve.php
 *
 * Approve a submitted AJE and post it (S-ACCT-AJE / spec §23.1).
 * Transitions entry_status 'submitted' → 'approved' → 'posted' and
 * (via JournalEntryService::post()) flips status='posted'. When
 * accounting.aje_review_required='1', the approver must be a different
 * user from the submitter (D-AJE-4).
 *
 * Approval is a higher-privilege action than submitting: restricted to
 * manager, accountant, or super_admin roles.
 *
 * @method  POST
 * @body    JSON: id (required — journal entry ID)
 * @auth    Session required; require_permission('journal_entries','edit');
 *          role in {manager, accountant, super_admin}
 * @returns 200 updated entry | 422 validation error | 403 FORBIDDEN | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php, JournalEntryService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

// Role gate (B2): approving requires manager/accountant/super_admin.
$role = current_user()['role_slug'] ?? '';
if (!in_array($role, ['super_admin', 'manager', 'accountant'], true)) {
    json_error(
        'FORBIDDEN',
        'Approving adjusting entries is restricted to managers, accountants, and super-admins.',
        403
    );
}

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
    $entry = JournalEntryService::approveAndPost($id, current_user_id());
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'not found')) {
        json_error('NOT_FOUND', $message, 404);
    }
    // Route period-closed to entry_date, balance to lines, everything else to id.
    $slot = 'id';
    if (stripos($message, 'period') !== false)     $slot = 'entry_date';
    elseif (stripos($message, 'unbalanced') !== false) $slot = 'lines';
    json_validation_error([$slot => $message], $message);
}

json_success($entry);
