<?php
declare(strict_types=1);

/**
 * api/v1/accounting/categorization-rules/index.php
 *
 * List auto-categorization rules ordered by priority.
 * Returns rule details with GL account info and vendor name if linked.
 *
 * @method  GET
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { rules[], global_enabled }
 *
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

use FleetForge\Accounting\AccountingService;

$rules = db_select(
    "SELECT r.*, a.code AS account_code, a.name AS account_name,
            v.name AS vendor_name
     FROM acc_categorization_rules r
     JOIN acc_accounts a ON a.id = r.account_id
     LEFT JOIN vendors v ON v.id = r.vendor_id AND v.deleted_at IS NULL
     ORDER BY r.priority ASC, r.name ASC",
    []
);

$globalEnabled = AccountingService::setting('accounting.auto_categorization_enabled', true);

json_success([
    'rules'          => $rules,
    'global_enabled' => (bool) $globalEnabled,
]);
