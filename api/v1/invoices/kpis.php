<?php
declare(strict_types=1);

/**
 * api/v1/invoices/kpis.php
 *
 * AR aging KPI tile counts for the invoices index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { current_total, current_cnt, ar30_total, ar30_cnt,
 *                ar60_total, ar60_cnt, ar90_total, ar90_cnt }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$arCurrent = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status = 'sent' AND due_date >= CURDATE() AND deleted_at IS NULL",
    []
);

$ar30 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE()
        AND due_date >= CURDATE() - INTERVAL 30 DAY
        AND deleted_at IS NULL",
    []
);

$ar60 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE() - INTERVAL 30 DAY
        AND due_date >= CURDATE() - INTERVAL 60 DAY
        AND deleted_at IS NULL",
    []
);

$ar90 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE() - INTERVAL 60 DAY
        AND deleted_at IS NULL",
    []
);

json_success([
    'current_total' => $arCurrent['total'] ?? '0.00',
    'current_cnt'   => (int)($arCurrent['cnt'] ?? 0),
    'ar30_total'    => $ar30['total'] ?? '0.00',
    'ar30_cnt'      => (int)($ar30['cnt'] ?? 0),
    'ar60_total'    => $ar60['total'] ?? '0.00',
    'ar60_cnt'      => (int)($ar60['cnt'] ?? 0),
    'ar90_total'    => $ar90['total'] ?? '0.00',
    'ar90_cnt'      => (int)($ar90['cnt'] ?? 0),
]);
