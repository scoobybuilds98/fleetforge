<?php
declare(strict_types=1);

/**
 * api/v1/customers/kpis.php
 *
 * FleetForge — Customers module KPI endpoint.
 *
 * Returns the 4 summary counters that drive the .stat-card tiles on the
 * /customers admin index page:
 *
 *   - total            → active list count (non-deleted)
 *   - active           → customers with status = 'active'
 *   - credit_hold      → customers with status = 'credit_hold'
 *   - overdue_balance  → SUM(invoices.balance_due) where invoices.status = 'overdue'
 *                        scoped to non-deleted customers, non-deleted invoices
 *
 * The page previously stubbed `overdue_balance: 0` in JS (with a TODO comment
 * noting "aggregate not available without dedicated endpoint"), which caused
 * the tile to render as "—" forever regardless of real overdue AR. This
 * endpoint plugs that hole so the tile shows the actual total.
 *
 * @method   GET
 * @params   (none)
 * @auth     Session required; customers:view permission required
 * @returns  {
 *              success: true,
 *              data: {
 *                  total:            int,
 *                  active:           int,
 *                  credit_hold:      int,
 *                  overdue_balance:  string  // bcmath-safe decimal
 *              }
 *          }
 *
 * @session  TILES-1 (2026-04-08)
 * @depends  api/bootstrap.php
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

// Total + per-status counts — three lightweight COUNT queries.
// A single grouped query would be marginally faster but harder to read;
// these three run in <5ms combined on the seeded dev dataset.
$total = db_count(
    "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL"
);

$active = db_count(
    "SELECT COUNT(*) FROM customers
      WHERE deleted_at IS NULL AND status = 'active'"
);

$creditHold = db_count(
    "SELECT COUNT(*) FROM customers
      WHERE deleted_at IS NULL AND status = 'credit_hold'"
);

// Overdue AR across the whole customer base.
// WHY join customers: ensures we don't double-count balances belonging to
// soft-deleted customers (which can still have overdue invoice rows).
// WHY bcround: monetary totals must not go through float math per D16.
// WHY CASE: invoices may be billed in USD; CAD-canonical reporting policy
// requires converting USD balances via exchange_rate_to_cad before summing,
// matching dashboard/kpis.php and reports/revenue.php. Summing raw balance_due
// would add USD 1:1 to CAD and understate overdue AR for USD-billed customers.
$overdueRow = db_row(
    "SELECT COALESCE(SUM(
                CASE WHEN i.currency = 'USD'
                     THEN i.balance_due * COALESCE(i.exchange_rate_to_cad, 1)
                     ELSE i.balance_due
                END
            ), 0) AS total
       FROM invoices i
       JOIN customers c ON c.id = i.customer_id
      WHERE i.deleted_at IS NULL
        AND c.deleted_at IS NULL
        AND i.status      = 'overdue'"
);
$overdueBalance = bcround((string) ($overdueRow['total'] ?? '0'), 2);

$kpis = [
    'total'           => $total,
    'active'          => $active,
    'credit_hold'     => $creditHold,
    'overdue_balance' => $overdueBalance,
];

// Serve-time financial redaction — overdue_balance is a dollar figure; counts
// stay visible. Roles without payments:view get the counts, not the balance.
if (!can_view_financials()) {
    unset($kpis['overdue_balance']);
}

json_success($kpis);
