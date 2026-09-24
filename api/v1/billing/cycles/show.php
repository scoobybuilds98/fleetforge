<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/show.php
 *
 * S-BILLING-MODULE — one cycle with everything its header and Overview tab
 * need: the record, headline stats, per-lease coverage counts, readings
 * progress, the seven-step stage, and the overdue-target flags.
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { cycle, stats, coverage, readings, stage, overdue, previous_id, next_id, approval_required }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;

$cycle = billing_cycle_from_request();
$showMoney = can_view_financials();
$ov = BillingCycles::overview($cycle, $showMoney);

if (!$showMoney) {
    $cycle['close_snapshot'] = null;
}

$prev = db_row("SELECT id FROM billing_cycles WHERE period_start < ? ORDER BY period_start DESC LIMIT 1", [$cycle['period_start']]);
$next = db_row("SELECT id FROM billing_cycles WHERE period_start > ? ORDER BY period_start ASC LIMIT 1", [$cycle['period_start']]);

json_success([
    'cycle'             => $cycle,
    'stats'             => $ov['stats'],
    'coverage'          => $ov['coverage'],
    'readings'          => $ov['readings'],
    'stage'             => $ov['stage'],
    'overdue'           => $ov['overdue'],
    'previous_id'       => $prev ? (int) $prev['id'] : null,
    'next_id'           => $next ? (int) $next['id'] : null,
    'approval_required' => (string) settings_get('invoices.approval_required', '0') === '1',
]);
