<?php
declare(strict_types=1);

/**
 * api/v1/accounting/workpaper-annotations/index.php
 *
 * List workpaper annotations for a given period, optionally filtered by
 * workpaper_type, workpaper_ref, and account_id. Joined to users.name
 * for the created_by display.
 *
 * @method  GET
 * @query   period_id (required), workpaper_type? (filter), workpaper_ref? (filter),
 *          account_id? (filter)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 [annotations...] in chronological order (created_at ASC)
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.2
 * Session: S-ACCT-WTB
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$periodId  = clean_int($_GET['period_id'] ?? null);
$wpType    = clean_string($_GET['workpaper_type'] ?? null, 30);
$wpRef     = clean_string($_GET['workpaper_ref']  ?? null, 50);
$accountId = clean_int($_GET['account_id'] ?? null);

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}

$where  = ['wpa.period_id = ?'];
$params = [$periodId];

if ($wpType) {
    if (!in_array($wpType, ['trial_balance', 'lead_schedule', 'report'], true)) {
        json_error('VALIDATION_ERROR', 'workpaper_type invalid.', 422);
    }
    $where[]  = 'wpa.workpaper_type = ?';
    $params[] = $wpType;
}
if ($wpRef) {
    $where[]  = 'wpa.workpaper_ref = ?';
    $params[] = $wpRef;
}
if ($accountId !== null) {
    $where[]  = 'wpa.account_id = ?';
    $params[] = $accountId;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = db_select(
    "SELECT wpa.id, wpa.workpaper_type, wpa.workpaper_ref, wpa.period_id, wpa.account_id,
            wpa.tickmark, wpa.note, wpa.created_by, wpa.created_at,
            u.name AS created_by_name,
            a.code AS account_code, a.name AS account_name
       FROM acc_workpaper_annotations wpa
       LEFT JOIN users u        ON u.id = wpa.created_by
       LEFT JOIN acc_accounts a ON a.id = wpa.account_id
       {$whereSql}
      ORDER BY wpa.created_at ASC, wpa.id ASC",
    $params
);

json_success($rows);
