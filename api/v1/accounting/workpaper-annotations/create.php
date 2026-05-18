<?php
declare(strict_types=1);

/**
 * api/v1/accounting/workpaper-annotations/create.php
 *
 * Create a workpaper annotation (tickmark + note) attached to a trial
 * balance, lead schedule, or report workpaper per spec §23.2.
 *
 * Annotations are IMMUTABLE — there is no update or delete endpoint
 * for CRA defensibility (STOP CONDITION). If a tickmark needs to be
 * corrected, the auditor adds a new annotation noting the correction.
 *
 * @method  POST
 * @body    JSON: workpaper_type ∈ {trial_balance,lead_schedule,report},
 *                workpaper_ref (≤50 chars), period_id (FK),
 *                account_id (optional FK), tickmark (optional — must
 *                match a key in accounting.tickmark_legend),
 *                note (optional)
 *                — at least one of tickmark/note required.
 * @auth    Session required; require_permission('journal_entries','create')
 *          (workpaper-annotations does not have its own permission module;
 *          journal_entries.create is the closest analog because annotations
 *          are accounting-record creations.)
 * @returns 201 created annotation | 422 validation error
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.2
 * Session: S-ACCT-WTB
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$body          = json_body();
$wpType        = clean_string($body['workpaper_type'] ?? null, 30);
$wpRef         = clean_string($body['workpaper_ref']  ?? null, 50);
$periodId      = clean_int($body['period_id']         ?? null);
$accountId     = clean_int($body['account_id']        ?? null);
$tickmark      = clean_string($body['tickmark']       ?? null, 8);
$note          = $body['note'] ?? null;
if (is_string($note)) $note = trim($note);
if ($note === '') $note = null;

$errors = [];

$validTypes = ['trial_balance', 'lead_schedule', 'report'];
if (!$wpType || !in_array($wpType, $validTypes, true)) {
    $errors['workpaper_type'] = 'workpaper_type must be one of: ' . implode(', ', $validTypes) . '.';
}
if (!$wpRef) {
    $errors['workpaper_ref'] = 'workpaper_ref is required.';
}
if (!$periodId) {
    $errors['period_id'] = 'period_id is required.';
}
if ($tickmark === null && $note === null) {
    $errors['_general'] = 'At least one of tickmark or note must be provided.';
}

if ($errors) {
    json_validation_error($errors);
}

// Period FK guard.
$period = db_row("SELECT id FROM acc_periods WHERE id = ?", [$periodId]);
if (!$period) {
    json_validation_error(['period_id' => 'Period not found.']);
}

// Optional account FK guard.
if ($accountId !== null) {
    $acct = db_row("SELECT id FROM acc_accounts WHERE id = ?", [$accountId]);
    if (!$acct) {
        json_validation_error(['account_id' => 'Account not found.']);
    }
}

// Tickmark must match a key in the canonical legend setting (if provided).
if ($tickmark !== null) {
    $legendRaw = (string) settings_get('accounting.tickmark_legend', '{}');
    $legend    = json_decode($legendRaw, true) ?: [];
    if (!array_key_exists($tickmark, $legend)) {
        $validKeys = implode(', ', array_keys($legend));
        json_validation_error([
            'tickmark' => "Unknown tickmark '{$tickmark}'. Valid keys: {$validKeys}.",
        ]);
    }
}

$userId = current_user_id();

$id = db_insert('acc_workpaper_annotations', [
    'workpaper_type' => $wpType,
    'workpaper_ref'  => $wpRef,
    'period_id'      => $periodId,
    'account_id'     => $accountId,
    'tickmark'       => $tickmark,
    'note'           => $note,
    'created_by'     => $userId,
]);

db_insert('audit_log', [
    'user_id'     => $userId,
    'action'      => 'create',
    'module'      => 'accounting',
    'entity_type' => 'workpaper_annotation',
    'entity_id'   => $id,
    'notes'       => "Workpaper annotation #{$id} created on {$wpType}/{$wpRef}",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

$row = db_row(
    "SELECT wpa.*, u.name AS created_by_name
       FROM acc_workpaper_annotations wpa
       LEFT JOIN users u ON u.id = wpa.created_by
      WHERE wpa.id = ?",
    [$id]
);

json_success($row, 201);
