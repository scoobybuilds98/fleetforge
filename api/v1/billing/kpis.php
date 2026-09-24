<?php
declare(strict_types=1);

/**
 * api/v1/billing/kpis.php
 *
 * S-BILLING-MODULE — the Billing home tiles: the cycle to work on now, and
 * the queues that need someone (open exceptions, runs awaiting a decision,
 * active holds, months with unsent drafts). Counts only — amounts are
 * returned only for can_view_financials() and only for the draft backlog.
 *
 * @method  GET
 * @auth    Session required; invoices:view
 * @returns 200 { target_month, target_cycle, open_exceptions, pending_runs,
 *                approved_runs, active_holds, backlog_months, backlog_drafts, backlog_total_cad }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;

$target = BillingCycles::targetMonth();
$cycle  = BillingCycles::findByMonth($target);
$types  = "'" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "'";

$backlog = db_row(
    "SELECT COUNT(DISTINCT DATE_FORMAT(i.billing_period_start, '%Y-%m')) AS months, COUNT(*) AS drafts,
            COALESCE(SUM(" . BillingCycles::cadSql('total_amount') . "), 0) AS cad
       FROM invoices i
      WHERE i.deleted_at IS NULL AND i.status = 'draft' AND i.lease_id IS NOT NULL
        AND i.invoice_type IN ({$types}) AND i.billing_period_start < ?",
    [$target . '-01']
);

$stage = null;
if ($cycle) {
    $ov = BillingCycles::overview($cycle, false);
    $stage = ['current' => $ov['stage']['current'], 'percent' => $ov['stage']['percent'], 'steps' => $ov['stage']['steps']];
}

json_success([
    'target_month'      => $target,
    'target_label'      => BillingCycles::label($target . '-01'),
    'target_cycle'      => $cycle ? [
        'id' => $cycle['id'], 'reference' => $cycle['reference'], 'status' => $cycle['status'],
        'send_by_date' => $cycle['send_by_date'], 'owner_name' => $cycle['owner_name'], 'stage' => $stage,
    ] : null,
    'open_exceptions'   => (int) db_count("SELECT COUNT(*) FROM invoice_billing_exceptions WHERE deleted_at IS NULL AND status = 'open'", []),
    'pending_runs'      => (int) db_count("SELECT COUNT(*) FROM invoice_batch_runs WHERE deleted_at IS NULL AND status = 'pending'", []),
    'approved_runs'     => (int) db_count("SELECT COUNT(*) FROM invoice_batch_runs WHERE deleted_at IS NULL AND status = 'approved'", []),
    'active_holds'      => (int) db_count("SELECT COUNT(*) FROM billing_holds WHERE released_at IS NULL AND (ends_on IS NULL OR ends_on >= ?)", [ff_today()]),
    'backlog_months'    => (int) $backlog['months'],
    'backlog_drafts'    => (int) $backlog['drafts'],
    'backlog_total_cad' => can_view_financials() ? bcadd((string) $backlog['cad'], '0', 2) : null,
]);
