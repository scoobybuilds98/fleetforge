<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/flags.php
 *
 * List all completed work orders that have crossed the configured
 * accounting.capex_threshold_cad and are NOT yet linked to an
 * acc_capex_requests row (i.e. waiting for accountant review).
 *
 * Used by the CapEx admin page's "Flagged Work Orders" panel so the
 * accounting team can decide between capitalize / expense for each one.
 *
 * @method  GET
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 { rows, threshold }
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;
use FleetForge\Accounting\AccountingService;

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

$rows      = FixedAssetService::listFlaggedWorkOrders();
$threshold = (string) AccountingService::setting('accounting.capex_threshold_cad', '2500.00');

json_success([
    'rows'      => $rows,
    'threshold' => $threshold,
    'count'     => count($rows),
]);
