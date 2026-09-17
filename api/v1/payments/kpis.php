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
       AND payment_date >= DATE_FORMAT(?, '%Y-%m-01')",
    // Business DATE vs company-local today: SQL CURDATE() is the UTC day, so
    // after 5pm Pacific (4pm in winter) on the last of a month it would already be next month (ff_today).
    [ff_today()]
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
       AND due_date < ?",
    // Business DATE vs company-local today: SQL CURDATE() is the UTC day, which
    // flips invoices due today to overdue every evening after 5pm Pacific (4pm in winter) (ff_today).
    [ff_today()]
);

$today = db_row(
    "SELECT COUNT(*) AS cnt FROM payments
     WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?",
    // created_at is UTC (PDO session +00:00): CURDATE() rolled "today" over at
    // 5pm Pacific (4pm in winter). Bound to the company-local day in UTC, sargable.
    [ff_local_day_start_utc(), ff_local_day_start_utc(ff_local_date_add(ff_today(), 1))]
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
