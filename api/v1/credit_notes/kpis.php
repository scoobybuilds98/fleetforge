<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/kpis.php
 *
 * Credit note summary KPI tile counts for the credit notes index page.
 * Called by Alpine.js on init so tiles always reflect current data,
 * even when the user navigates back (cached page).
 *
 * @method  GET
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { active_balance, active_cnt, issued_total, issued_cnt,
 *                fully_used_cnt, expired_cnt }
 *
 * Decisions: D5 (soft delete)
 * Session: S021 (tile auto-refresh)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$activeBalance = db_row(
    "SELECT COALESCE(SUM(amount_remaining), 0) AS total, COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status IN ('active', 'partially_used')",
    []
);

$issuedThisMonth = db_row(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL
       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

$fullyUsedThisMonth = db_row(
    "SELECT COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status = 'fully_used'
       AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

$expiredThisMonth = db_row(
    "SELECT COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status IN ('expired', 'void')
       AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

// I03: dollar totals hidden from roles without payments:view; counts stay.
$canSeeMoney = can_view_financials();

json_success([
    'active_balance' => $canSeeMoney ? ($activeBalance['total'] ?? '0.00') : '0.00',
    'active_cnt'     => (int)($activeBalance['cnt'] ?? 0),
    'issued_total'   => $canSeeMoney ? ($issuedThisMonth['total'] ?? '0.00') : '0.00',
    'issued_cnt'     => (int)($issuedThisMonth['cnt'] ?? 0),
    'fully_used_cnt' => (int)($fullyUsedThisMonth['cnt'] ?? 0),
    'expired_cnt'    => (int)($expiredThisMonth['cnt'] ?? 0),
]);
