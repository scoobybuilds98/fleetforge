<?php
declare(strict_types=1);

/**
 * api/v1/payments/kpis.php
 *
 * Payment summary KPI tile counts for the payments index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('payments','view')
 * @returns 200 { collected_total, collected_cnt, ar_outstanding_total,
 *                ar_outstanding_cnt, ar_overdue_total, ar_overdue_cnt,
 *                recorded_today_cnt }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('payments', 'view');

$collectedThisMonth = db_row(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM payments
     WHERE deleted_at IS NULL AND status = 'cleared'
       AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

$arOutstanding = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE deleted_at IS NULL AND status IN ('sent', 'partially_paid', 'overdue')",
    []
);

$arOverdue = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE deleted_at IS NULL AND status IN ('sent', 'partially_paid', 'overdue')
       AND due_date < CURDATE()",
    []
);

$today = db_row(
    "SELECT COUNT(*) AS cnt FROM payments
     WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()",
    []
);

json_success([
    'collected_total'      => $collectedThisMonth['total'] ?? '0.00',
    'collected_cnt'        => (int)($collectedThisMonth['cnt'] ?? 0),
    'ar_outstanding_total' => $arOutstanding['total'] ?? '0.00',
    'ar_outstanding_cnt'   => (int)($arOutstanding['cnt'] ?? 0),
    'ar_overdue_total'     => $arOverdue['total'] ?? '0.00',
    'ar_overdue_cnt'       => (int)($arOverdue['cnt'] ?? 0),
    'recorded_today_cnt'   => (int)($today['cnt'] ?? 0),
]);
