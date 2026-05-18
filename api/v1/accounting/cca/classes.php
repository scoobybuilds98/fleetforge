<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/classes.php
 *
 * Lists CCA classes (seeded via 015_acc_cca_classes.sql per spec §23.3).
 * Used by the asset create/edit dropdown and the CCA admin page filter.
 *
 * @method  GET
 * @query   active? (default 1 — include inactive when 0)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 [{id, class_number, description, rate, method, half_year_rule,
 *                aiip_eligible, one_asset_per_class, is_active}]
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$activeOnly = ($_GET['active'] ?? '1') !== '0';

$where = $activeOnly ? 'WHERE is_active = 1' : '';
$rows = db_select(
    "SELECT id, class_number, description, rate, method,
            half_year_rule, aiip_eligible, recapture_applies, terminal_loss_applies,
            one_asset_per_class, is_active
       FROM acc_cca_classes
       {$where}
      ORDER BY CAST(class_number AS DECIMAL(6,2)) ASC"
);

json_success($rows);
