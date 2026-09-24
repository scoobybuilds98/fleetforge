<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/export.php
 *
 * S-BILLING-MODULE — the cycle's billing register as CSV: every invoice in
 * the month (void included, for the audit trail) with its money, CAD value,
 * send and email facts and review mark. Free-text cells are neutralised
 * against spreadsheet formula injection (CycleClose::registerRows).
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:export + can_view_financials()
 * @returns 200 text/csv attachment
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'export');
if (!can_view_financials()) {
    json_error('FORBIDDEN', 'The billing register contains amounts, which your role cannot see.', 403);
}

use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\Cycle\CycleClose;

$cycle = billing_cycle_from_request();
$rows  = CycleClose::registerRows($cycle);

BillingCycles::audit($cycle, 'export', 'Exported the billing register for ' . $cycle['reference'] . ' (' . (count($rows) - 1) . ' invoices).');

header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="billing-register-' . $cycle['month'] . '.csv"');
header('Cache-Control: no-store');
$fh = fopen('php://output', 'w');
fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
foreach ($rows as $row) {
    fputcsv($fh, $row);
}
fclose($fh);
exit;
