<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/index.php
 *
 * List recurring JE templates with line counts. No pagination — templates
 * are unlikely to exceed a handful.
 *
 * @method  GET
 * @query   is_active? (1|0|all), frequency?
 * @auth    require_permission('journal_entries','view')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$isActive  = clean_string($_GET['is_active'] ?? null);
$frequency = clean_string($_GET['frequency'] ?? null);

$where = ['1=1'];
$params = [];
if ($isActive !== null && $isActive !== '' && $isActive !== 'all') {
    $where[] = 't.is_active = ?';
    $params[] = ((int) $isActive) === 1 ? 1 : 0;
}
if ($frequency && in_array($frequency, ['monthly','quarterly','annually'], true)) {
    $where[] = 't.frequency = ?';
    $params[] = $frequency;
}
$whereSql = implode(' AND ', $where);

$rows = db_select(
    "SELECT t.id, t.name, t.description, t.frequency, t.day_of_month,
            t.start_date, t.end_date, t.next_post_date, t.last_posted_date,
            t.is_active, t.auto_post,
            t.created_at, t.updated_at,
            u.name AS created_by_name,
            (SELECT COUNT(*) FROM acc_recurring_entry_lines WHERE recurring_entry_id = t.id) AS line_count,
            (SELECT COUNT(*) FROM acc_journal_entries
              WHERE source_type='recurring' AND source_id = t.id) AS post_count
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE {$whereSql}
      ORDER BY t.is_active DESC, t.name ASC",
    $params
);

json_success($rows);
