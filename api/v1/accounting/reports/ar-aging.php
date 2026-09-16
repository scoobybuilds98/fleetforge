<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/ar-aging.php
 *
 * AR aging report — groups open invoices by customer and aging bucket.
 * Buckets: Current (not yet due), 1-30, 31-60, 61-90, 90+ days past due.
 *
 * All amounts are CAD (canonical): each invoice balance is converted with its
 * own frozen exchange_rate_to_cad. The as-of date restricts the report to
 * invoices issued on/before it AND rolls each balance back to that date
 * (payments/credits applied later do not reduce it). The maths lives in
 * lib/Reports/ArAging.php, shared with Reports → AR Aging and the Dashboard.
 *
 * @method  GET
 * @query   as_of_date? (Y-m-d, defaults to today — the business date)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { as_of_date, currency:'CAD', customers[], totals{},
 *                native_totals{CAD,USD}, invoice_count, fx_rate_missing_count }
 *          customers[].invoices[] carry balance_due (CAD) plus currency,
 *          balance_due_native and exchange_rate_to_cad.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (AR Accounting Layer)
 * Session: S030
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Reports\ArAging;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$asOfDate = clean_date($_GET['as_of_date'] ?? null) ?? date('Y-m-d');

$aging = ArAging::asOf($asOfDate);

// Keep the historical payload shape (customers + totals) — the page reads it —
// and expose the reconciling extras alongside.
json_success([
    'as_of_date'            => $aging['as_of_date'],
    'currency'              => $aging['currency'],
    'customers'             => $aging['customers'],
    'totals'                => $aging['totals'],
    'native_totals'         => $aging['native_totals'],
    'invoice_count'         => $aging['invoice_count'],
    'fx_rate_missing_count' => $aging['fx_rate_missing_count'],
]);
