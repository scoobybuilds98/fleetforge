<?php
declare(strict_types=1);

/**
 * api/v1/accounting/saved-reports/index.php
 *
 * List saved report configurations for the current user. Optional filter
 * by report_type. Schema-on-disk: `saved_reports` (NOT acc_saved_reports
 * — table prefix differs from the rest of the accounting schema; the
 * spec naming `acc_saved_reports` is aspirational. K-22: trust the
 * actual schema.)
 *
 * @method  GET
 * @query   report_type? (filter)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$userId     = current_user_id();
$reportType = clean_string($_GET['report_type'] ?? null);

$where  = ['user_id = ?'];
$params = [$userId];
if ($reportType) {
    $where[] = 'report_type = ?';
    $params[] = $reportType;
}
$whereSql = implode(' AND ', $where);

$rows = db_select(
    "SELECT id, name, report_type, parameters, is_pinned, created_at
       FROM saved_reports
      WHERE {$whereSql}
      ORDER BY is_pinned DESC, created_at DESC",
    $params
);

foreach ($rows as &$r) {
    // Decode JSON parameters for client convenience
    if (is_string($r['parameters'])) {
        $r['parameters'] = json_decode($r['parameters'], true) ?? [];
    }
}
unset($r);

json_success($rows);
