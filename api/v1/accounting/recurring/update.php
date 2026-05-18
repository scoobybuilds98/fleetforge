<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/update.php
 *
 * Update header fields and/or replace the line set. D19 optimistic-lock
 * via updated_at compare.
 *
 * @method  POST
 * @body    { id, updated_at, ...optional fields..., lines? }
 * @auth    require_permission('journal_entries','edit')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$row = db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$id]);
if (!$row) {
    json_error('NOT_FOUND', 'Template not found.', 404);
}

$providedUpdatedAt = (string) ($input['updated_at'] ?? '');
if ($providedUpdatedAt && $providedUpdatedAt !== (string) $row['updated_at']) {
    json_error('STALE_DATA', 'Template was modified by someone else. Reload and try again.', 409);
}

$header = [];
if (isset($input['name'])) {
    $name = trim((string) $input['name']);
    if ($name === '') json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
    $header['name'] = $name;
}
if (array_key_exists('description', $input)) {
    $header['description'] = $input['description'] !== null ? (string) $input['description'] : null;
}
if (isset($input['frequency'])) {
    if (!in_array($input['frequency'], ['monthly','quarterly','annually'], true)) {
        json_error('VALIDATION_ERROR', 'frequency invalid.', 422);
    }
    $header['frequency'] = (string) $input['frequency'];
}
if (isset($input['day_of_month'])) {
    $d = (int) $input['day_of_month'];
    if ($d < 1 || $d > 31) json_error('VALIDATION_ERROR', 'day_of_month must be 1-31.', 422);
    $header['day_of_month'] = $d;
}
if (isset($input['start_date'])) {
    $sd = clean_date($input['start_date']);
    if (!$sd) json_error('VALIDATION_ERROR', 'start_date invalid.', 422);
    $header['start_date'] = $sd;
}
if (array_key_exists('end_date', $input)) {
    $ed = $input['end_date'] !== null && $input['end_date'] !== '' ? clean_date($input['end_date']) : null;
    $header['end_date'] = $ed;
}
if (array_key_exists('auto_post', $input)) {
    $header['auto_post'] = !empty($input['auto_post']) ? 1 : 0;
}

$lines = $input['lines'] ?? null;
$cleanLines = null;
if (is_array($lines)) {
    if (count($lines) < 2) {
        json_error('VALIDATION_ERROR', 'At least 2 lines are required.', 422);
    }
    $sumDr = '0.00'; $sumCr = '0.00';
    $cleanLines = [];
    foreach ($lines as $i => $l) {
        $accountId = (int) ($l['account_id'] ?? 0);
        $debit  = number_format((float) ($l['debit']  ?? 0), 2, '.', '');
        $credit = number_format((float) ($l['credit'] ?? 0), 2, '.', '');
        if ($accountId <= 0) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": account_id required.", 422);
        if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
            json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": cannot have both debit and credit.", 422);
        }
        if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
            json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": debit or credit must be > 0.", 422);
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
}

db_transaction(function () use ($id, $header, $cleanLines, $row) {
    if (!empty($header)) {
        db_update('acc_recurring_entries', $header, 'id = ?', [$id]);
    }
    if ($cleanLines !== null) {
        db_execute("DELETE FROM acc_recurring_entry_lines WHERE recurring_entry_id = ?", [$id]);
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
    }
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'user_name'   => current_user()['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'recurring_entry',
        'entity_id'   => $id,
        'entity_label' => (string) $row['name'],
        'notes'       => sprintf(
            "Recurring template #%d updated (header keys: %d, lines replaced: %s).",
            $id,
            count($header),
            $cleanLines === null ? 'no' : 'yes (' . count($cleanLines) . ')'
        ),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$fresh = db_row(
    "SELECT t.*, u.name AS created_by_name
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE t.id = ?",
    [$id]
);
json_success($fresh);
