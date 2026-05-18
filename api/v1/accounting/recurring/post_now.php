<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/post_now.php
 *
 * Manual trigger — runs RecurringEntryService::postTemplate() without
 * the isDueToday() day check. Idempotency still enforced via the
 * reference key, so re-running on the same template + same year-month
 * returns the existing JE row unchanged.
 *
 * Permission: super_admin or manager only (stricter than base
 * accounting permission — manual posting bypasses the schedule and
 * should be an audited admin action).
 *
 * @method  POST
 * @body    { id, date? (Y-m-d, defaults to today) }
 * @auth    role: super_admin or manager
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

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
$date = clean_date($input['date'] ?? null) ?: date('Y-m-d');
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$template = db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$id]);
if (!$template) {
    json_error('NOT_FOUND', 'Template not found.', 404);
}

try {
    $result = RecurringEntryService::postTemplate($template, $date, current_user_id());
    json_success($result, $result['created'] ? 201 : 200);
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
