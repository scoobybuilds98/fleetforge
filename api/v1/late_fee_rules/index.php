<?php
declare(strict_types=1);

/**
 * api/v1/late_fee_rules/index.php
 *
 * I19 — list every late-fee rule for the Settings → Late Fees screen: the
 * global rule(s) (customer_id NULL) and the per-customer overrides, each with
 * the customer's company name and a UI-ready `fee_value_input` (percent for
 * percentage rules, dollars for flat — see _helpers.php::lfr_shape()).
 *
 * Also reports whether the "Apply late fees" cron is switched on, because a
 * rule does nothing unless cron/late_fee_apply.php actually runs.
 *
 * @method  GET
 * @auth    Session required; require_permission('settings','view')
 * @returns 200 { global: rule|null, global_count: int, overrides: rule[],
 *                cron_enabled: bool }
 *
 * @depends api/bootstrap.php, api/v1/late_fee_rules/_helpers.php
 * @session I19-LATE-FEE-RULES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

// One small table — no pagination needed (one row per customer at most).
$rows = db_select(
    "SELECT r.*, c.company_name AS customer_name, c.deleted_at AS customer_deleted_at,
            u.name AS created_by_name
       FROM late_fee_rules r
       LEFT JOIN customers c ON c.id = r.customer_id
       LEFT JOIN users u     ON u.id = r.created_by
      ORDER BY (r.customer_id IS NULL) DESC, c.company_name ASC, r.id DESC",
    []
);

$globals   = [];
$overrides = [];
foreach ($rows as $row) {
    $shaped = lfr_shape($row);
    if ($shaped['customer_id'] === null) {
        $globals[] = $shaped;
    } else {
        $overrides[] = $shaped;
    }
}

// WHY pick the global the same way the billing engine does (active first,
// then newest id): if legacy data ever holds more than one global row, the
// card must show the rule that will actually be charged, not an arbitrary one.
usort($globals, static function (array $a, array $b): int {
    return [$b['is_active'], $b['id']] <=> [$a['is_active'], $a['id']];
});

json_success([
    'global'       => $globals[0] ?? null,
    'global_count' => count($globals),
    'overrides'    => $overrides,
    'cron_enabled' => cron_enabled('late_fee_apply'),
]);
