<?php declare(strict_types=1);

/**
 * api/v1/accounting/periods/reopen.php
 *
 * Step a period back one state, with a reason — SOP known issue I6 ("there
 * is no way to reopen a closed period"):
 *
 *   action = 'reopen' : closed → open   (post a late correction, then close again)
 *   action = 'unlock' : locked → closed (a month locked by hand, by mistake)
 *
 * A year that has an active year-end close cannot be stepped back here: its
 * months were locked by the close, and the closing entry depends on them.
 * Reverse the year-end first (Accounting → Year-End), which already returns
 * those months to 'closed'.
 *
 * Super Admin only (same gate as lock.php). The reason is required and is
 * written to the audit log and appended to acc_periods.notes, so a reopened
 * month is never silent.
 *
 * @method  POST
 * @body    JSON: id (required), action ('reopen'|'unlock', default 'reopen'),
 *                reason (required, 5–500 chars)
 * @auth    Session required; require_role('super_admin')
 * @returns 200 updated period | 409 INVALID_TRANSITION / YEAR_CLOSED | 404 | 422
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_role('super_admin');

$body   = json_body();
$id     = clean_int($body['id'] ?? null);
$action = (string) ($body['action'] ?? 'reopen');
$reason = trim((string) ($body['reason'] ?? ''));

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
if (!in_array($action, ['reopen', 'unlock'], true)) {
    json_error('VALIDATION_ERROR', "action must be 'reopen' or 'unlock'.", 422);
}
if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
    json_error('VALIDATION_ERROR', 'A reason (5–500 characters) is required.', 422);
}

// WHY: which status each action starts from and moves to.
$from = $action === 'reopen' ? 'closed' : 'locked';
$to   = $action === 'reopen' ? 'open' : 'closed';

$result = null;

db_transaction(function () use ($id, $action, $reason, $from, $to, &$result) {
    // WHY: FOR UPDATE so a concurrent close/lock cannot interleave.
    $period = db_row("SELECT * FROM acc_periods WHERE id = ? FOR UPDATE", [$id]);
    if (!$period) {
        json_error('NOT_FOUND', 'Accounting period not found.', 404);
    }

    if ($period['status'] !== $from) {
        json_error(
            'INVALID_TRANSITION',
            "Cannot {$action} period {$period['name']}: it is '{$period['status']}', not '{$from}'.",
            409
        );
    }

    $closure = db_row(
        "SELECT id FROM acc_year_end_closures WHERE fiscal_year = ? AND status = 'closed' LIMIT 1",
        [(int) $period['year']]
    );
    if ($closure) {
        json_error(
            'YEAR_CLOSED',
            "Fiscal year {$period['year']} has been closed. Reverse the year-end close first (Accounting → Year-End); that returns its months to 'closed'.",
            409
        );
    }

    $userId = current_user_id();
    $user   = current_user();
    $stamp  = ff_today();

    $update = ['status' => $to];
    if ($action === 'reopen') {
        $update['closed_by'] = null;
        $update['closed_at'] = null;
    } else {
        $update['locked_by'] = null;
        $update['locked_at'] = null;
    }
    // WHY: keep the history on the period itself as well as in the audit log.
    $update['notes'] = trim(((string) ($period['notes'] ?? '')) . "\n{$stamp} "
        . ($action === 'reopen' ? 'Reopened' : 'Unlocked') . ' by ' . ($user['name'] ?? 'Super Admin') . ": {$reason}");

    db_update('acc_periods', $update, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $user['name'] ?? 'System',
        'action'       => 'status_change',
        'module'       => 'accounting',
        'entity_type'  => 'period',
        'entity_id'    => $id,
        'entity_label' => $period['name'],
        'notes'        => "Period {$period['name']} " . ($action === 'reopen' ? 'reopened' : 'unlocked') . ": {$reason}",
        'old_values'   => json_encode(['status' => $from]),
        'new_values'   => json_encode(['status' => $to]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = db_row("SELECT * FROM acc_periods WHERE id = ?", [$id]);
});

json_success($result);
