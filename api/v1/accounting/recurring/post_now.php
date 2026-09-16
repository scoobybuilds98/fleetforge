<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/post_now.php
 *
 * Manual trigger. Three modes:
 *
 *   1. Template is OVERDUE (next_post_date <= today) and no `date` given →
 *      CATCH UP: RecurringEntryService::catchUp() posts every missed
 *      occurrence oldest-first, each dated on its own scheduled date, and
 *      advances next_post_date. Stops at the first failure (e.g. a closed
 *      period) and reports it; months already posted stay posted.
 *   2. Nothing overdue and no `date` → legacy "post now": one entry dated
 *      today (keyed on today's month).
 *   3. Explicit `date` → one entry on that date (operator's choice).
 *
 * Idempotency is enforced via the per-month reference key in every mode, so
 * re-running never double-posts; the schedule only ever moves forward.
 *
 * Permission: super_admin or manager only (stricter than base
 * accounting permission — manual posting bypasses the schedule and
 * should be an audited admin action).
 *
 * @method  POST
 * @body    { id, date? (Y-m-d; omit to catch up / post today) }
 * @returns 201|200 { created, je, posted[], skipped[], remaining, next_post_date, mode }
 *          422 with the posted-so-far list when a catch-up stops on a failure
 * @auth    role: super_admin or manager
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\RecurringEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$roleSlug = current_user()['role_slug'] ?? '';
if (!in_array($roleSlug, ['super_admin', 'manager'], true)) {
    json_error('FORBIDDEN', 'Post-Now requires super_admin or manager role.', 403);
}

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id   = clean_int($input['id'] ?? null);
$explicitDate = clean_date($input['date'] ?? null);
$today = AccountingService::businessToday();
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$template = db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$id]);
if (!$template) {
    json_error('NOT_FOUND', 'Template not found.', 404);
}

// Mode 1 — catch up every overdue occurrence.
if (!$explicitDate && RecurringEntryService::dueOccurrences($template, $today, 1)) {
    $result = RecurringEntryService::catchUp($template, $today, (int) current_user_id());
    $last   = $result['posted'] ? end($result['posted']) : ($result['skipped'] ? end($result['skipped']) : null);
    $payload = $result + [
        'mode'    => 'catch_up',
        'created' => count($result['posted']) > 0,
        // `je` kept for older callers: the most recent entry touched.
        'je'      => $last ? ['id' => $last['je_id'], 'entry_number' => $last['entry_number']] : null,
    ];
    if ($result['error'] !== null) {
        $posted = count($result['posted']);
        json_error(
            'CATCH_UP_STOPPED',
            "Posted {$posted} missed occurrence(s), then stopped at {$result['failed_date']}: {$result['error']}",
            422,
            ['result' => $payload]
        );
    }
    json_success($payload, $payload['created'] ? 201 : 200);
}

// Modes 2 + 3 — a single entry on the explicit date, or today.
$date = $explicitDate ?: $today;
try {
    $result = RecurringEntryService::postTemplate($template, $date, current_user_id());
    $fresh  = db_row("SELECT next_post_date FROM acc_recurring_entries WHERE id = ?", [$id]);
    json_success($result + [
        'mode'           => $explicitDate ? 'explicit_date' : 'post_today',
        'next_post_date' => $fresh['next_post_date'] ?? null,
    ], $result['created'] ? 201 : 200);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'no lines')) {
        json_error('NO_LINES', $msg, 422);
    }
    if (str_contains($msg, 'No accounting period')) {
        json_error('NO_PERIOD', $msg, 422);
    }
    if (str_contains($msg, 'cannot post recurring JE')) {
        json_error('PERIOD_CLOSED', $msg, 422);
    }
    json_error('POST_FAILED', $msg, 422);
}
