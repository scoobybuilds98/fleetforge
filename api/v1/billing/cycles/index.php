<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/index.php
 *
 * S-BILLING-MODULE — every billing cycle, newest first, with its headline
 * numbers, plus the months that have unsent drafts but no cycle yet (the
 * draft backlog, so an old month can be opened and worked like any other).
 *
 * Per-cycle numbers come from ONE grouped query over the invoices (month ×
 * status), not a query per cycle. Amounts are CAD-canonical and redacted
 * for roles without can_view_financials().
 *
 * @method  GET
 * @query   limit (1-120, default 36)
 * @auth    Session required; invoices:view
 * @returns 200 { cycles: [...], backlog: [{month, label, drafts, draft_total_cad}],
 *                current_month, target_month }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;

$showMoney = can_view_financials();
$limit = max(1, min(120, clean_int($_GET['limit'] ?? null) ?: 36));

$cycles = db_select(
    "SELECT bc.id, bc.reference, bc.period_start, bc.period_end, bc.status, bc.bill_by_date, bc.send_by_date,
            bc.readiness_checked_at, bc.readiness_summary, bc.closed_at, bc.created_at,
            ou.name AS owner_name, cu.name AS closed_by_name
       FROM billing_cycles bc
       LEFT JOIN users ou ON ou.id = bc.owner_user_id
       LEFT JOIN users cu ON cu.id = bc.closed_by
      ORDER BY bc.period_start DESC
      LIMIT {$limit}",
    []
);

// Month × status roll-up of every lease invoice in cycle scope.
$types = "'" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "'";
$agg = [];
foreach (db_select(
    "SELECT DATE_FORMAT(i.billing_period_start, '%Y-%m') AS month, i.status, COUNT(*) AS n,
            SUM(" . BillingCycles::cadSql('total_amount') . ") AS total_cad
       FROM invoices i
      WHERE i.deleted_at IS NULL AND i.lease_id IS NOT NULL AND i.invoice_type IN ({$types})
      GROUP BY month, i.status",
    []
) as $r) {
    $agg[$r['month']][$r['status']] = ['n' => (int) $r['n'], 'cad' => (string) $r['total_cad']];
}

$rollup = static function (string $month) use ($agg, $showMoney): array {
    $s = $agg[$month] ?? [];
    $live = 0; $drafts = 0; $total = '0.00'; $draftTotal = '0.00';
    foreach ($s as $status => $v) {
        if ($status === 'void') continue;
        $live += $v['n'];
        $total = bcadd($total, $v['cad'], 2);
        if ($status === 'draft') { $drafts += $v['n']; $draftTotal = bcadd($draftTotal, $v['cad'], 2); }
    }
    return [
        'live'            => $live,
        'drafts'          => $drafts,
        'issued'          => $live - $drafts,
        'paid'            => $s['paid']['n'] ?? 0,
        'void'            => $s['void']['n'] ?? 0,
        'total_cad'       => $showMoney ? $total : null,
        'draft_total_cad' => $showMoney ? $draftTotal : null,
    ];
};

$out = [];
$haveMonth = [];
foreach ($cycles as $c) {
    $month = substr((string) $c['period_start'], 0, 7);
    $haveMonth[$month] = true;
    $out[] = [
        'id'                   => (int) $c['id'],
        'reference'            => $c['reference'],
        'month'                => $month,
        'label'                => BillingCycles::label((string) $c['period_start']),
        'period_start'         => $c['period_start'],
        'period_end'           => $c['period_end'],
        'status'               => $c['status'],
        'owner_name'           => $c['owner_name'],
        'bill_by_date'         => $c['bill_by_date'],
        'send_by_date'         => $c['send_by_date'],
        'readiness_checked_at' => $c['readiness_checked_at'],
        'readiness_summary'    => $c['readiness_summary'] ? json_decode((string) $c['readiness_summary'], true) : null,
        'closed_at'            => $c['closed_at'],
        'closed_by_name'       => $c['closed_by_name'],
        'created_at'           => $c['created_at'],
        'stats'                => $rollup($month),
    ];
}

// Months with unsent drafts and no cycle record (the backlog).
$backlog = [];
foreach ($agg as $month => $s) {
    if (isset($haveMonth[$month]) || empty($s['draft'])) continue;
    $backlog[] = [
        'month'           => $month,
        'label'           => BillingCycles::label($month . '-01'),
        'drafts'          => $s['draft']['n'],
        'draft_total_cad' => $showMoney ? bcadd($s['draft']['cad'], '0', 2) : null,
    ];
}
usort($backlog, static fn($a, $b) => strcmp($a['month'], $b['month']));

json_success([
    'cycles'        => $out,
    'backlog'       => $backlog,
    'current_month' => substr(ff_today(), 0, 7),
    'target_month'  => BillingCycles::targetMonth(),
]);
