<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/create.php
 *
 * @method  POST
 * @body    { name, description?, frequency, day_of_month, start_date,
 *            end_date?, auto_post?, lines[]: { account_id, debit, credit,
 *            description? } }
 * @auth    require_permission('journal_entries','create')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\RecurringEntryService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$name        = trim((string) ($input['name'] ?? ''));
$description = isset($input['description']) ? (string) $input['description'] : null;
$frequency   = (string) ($input['frequency'] ?? '');
$dayOfMonth  = (int) ($input['day_of_month'] ?? 0);
$startDate   = clean_date($input['start_date'] ?? null);
$endDate     = clean_date($input['end_date'] ?? null);
$autoPost    = !empty($input['auto_post']) ? 1 : 0;
$lines       = $input['lines'] ?? [];

if ($name === '') {
    json_error('MISSING_REQUIRED', 'name is required.', 422);
}
if (!in_array($frequency, ['monthly', 'quarterly', 'annually'], true)) {
    json_error('VALIDATION_ERROR', 'frequency must be monthly, quarterly, or annually.', 422);
}
if ($dayOfMonth < 1 || $dayOfMonth > 31) {
    json_error('VALIDATION_ERROR', 'day_of_month must be 1-31.', 422);
}
if (!$startDate) {
    json_error('MISSING_REQUIRED', 'start_date is required.', 422);
}
if ($endDate && strtotime($endDate) < strtotime($startDate)) {
    json_error('VALIDATION_ERROR', 'end_date must be on or after start_date.', 422);
}
if (!is_array($lines) || count($lines) < 2) {
    json_error('VALIDATION_ERROR', 'At least 2 lines are required.', 422);
}

// Validate lines + balance
$sumDr = '0.00';
$sumCr = '0.00';
$cleanLines = [];
foreach ($lines as $i => $l) {
    $accountId = (int) ($l['account_id'] ?? 0);
    $debit  = (string) ($l['debit']  ?? '0.00');
    $credit = (string) ($l['credit'] ?? '0.00');
    if (!is_numeric($debit))  $debit  = '0.00';
    if (!is_numeric($credit)) $credit = '0.00';
    $debit  = number_format((float) $debit, 2, '.', '');
    $credit = number_format((float) $credit, 2, '.', '');

    if ($accountId <= 0) {
        json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": account_id is required.", 422);
    }
    if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
        json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": cannot have both debit and credit.", 422);
    }
    if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
        json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": debit or credit must be > 0.", 422);
    }
    // Verify account exists + active + not header
    $a = db_row("SELECT id, is_active, is_header FROM acc_accounts WHERE id = ?", [$accountId]);
    if (!$a || (int) $a['is_active'] !== 1 || (int) $a['is_header'] === 1) {
        json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": account is invalid (not found, inactive, or header).", 422);
    }

    $sumDr = bcadd($sumDr, $debit, 2);
    $sumCr = bcadd($sumCr, $credit, 2);
    $cleanLines[] = [
        'account_id'  => $accountId,
        'line_number' => $i + 1,
        'description' => isset($l['description']) ? (string) $l['description'] : null,
        'debit'       => $debit,
        'credit'      => $credit,
    ];
}
if (bccomp($sumDr, $sumCr, 2) !== 0) {
    json_error('VALIDATION_ERROR', "Template lines unbalanced: debits ({$sumDr}) ≠ credits ({$sumCr}).", 422);
}

// Compute next_post_date based on start_date + day_of_month
$startTs = strtotime($startDate);
$startDay = (int) date('j', $startTs);
$startMonthDays = (int) date('t', $startTs);
$targetThisMonth = min($dayOfMonth, $startMonthDays);
if ($startDay <= $targetThisMonth) {
    // This month, on day_of_month
    $nextPost = sprintf('%s-%02d', date('Y-m', $startTs), $targetThisMonth);
} else {
    // Next applicable cycle from start_date — use service helper for symmetry
    $tplStub = [
        'frequency'    => $frequency,
        'day_of_month' => $dayOfMonth,
        'start_date'   => $startDate,
    ];
    $nextPost = RecurringEntryService::computeNextPostDate($tplStub, $startDate);
}

$templateId = db_transaction(function () use ($name, $description, $frequency, $dayOfMonth, $startDate, $endDate, $autoPost, $nextPost, $cleanLines) {
    $id = db_insert('acc_recurring_entries', [
        'name'             => $name,
        'description'      => $description,
        'frequency'        => $frequency,
        'day_of_month'     => $dayOfMonth,
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'next_post_date'   => $nextPost,
        'last_posted_date' => null,
        'is_active'        => 1,
        'auto_post'        => $autoPost,
        'created_by'       => current_user_id(),
    ]);

    foreach ($cleanLines as $l) {
        db_insert('acc_recurring_entry_lines', [
            'recurring_entry_id' => $id,
            'account_id'         => $l['account_id'],
            'line_number'        => $l['line_number'],
            'description'        => $l['description'],
            'debit'              => $l['debit'],
            'credit'             => $l['credit'],
        ]);
    }

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'user_name'   => current_user()['name'] ?? 'system',
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'recurring_entry',
        'entity_id'   => $id,
        'entity_label'=> $name,
        'notes'       => "Recurring template created: {$name} ({$frequency}, day {$dayOfMonth}, " . count($cleanLines) . " lines, auto_post=" . ($autoPost ? 'YES' : 'NO') . ").",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

$row = db_row(
    "SELECT t.*, u.name AS created_by_name
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE t.id = ?",
    [$templateId]
);

json_success($row, 201);
