<?php
declare(strict_types=1);

/**
 * api/v1/billing/trends.php
 *
 * S-BILLING-MODULE-2 — how the monthly billing has been RUNNING, month by
 * month (not a revenue report — that is Reports → Financial): invoices
 * billed, drafts still unsent, how quickly the month went out (days from
 * the month's end to the last invoice sent), share emailed, exceptions
 * raised, voids, and whether the cycle was closed. Billed CAD is included
 * for scale when the viewer can see amounts.
 *
 * @method  GET
 * @query   months (3-36, default 12)
 * @auth    Session required; invoices:view
 * @returns 200 { months: [{month, label, invoices, drafts, sent, emailed_pct, days_to_send,
 *                          exceptions, voids, billed_cad, cycle: {id,status}|null}] }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;

$n = max(3, min(36, clean_int($_GET['months'] ?? null) ?: 12));
$showMoney = can_view_financials();
$last = substr(ff_today(), 0, 7);
$first = BillingCycles::shiftMonth($last, -($n - 1));
$types = "'" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "'";

$agg = [];
foreach (db_select(
    "SELECT DATE_FORMAT(i.billing_period_start, '%Y-%m') AS m,
            SUM(i.status <> 'void') AS live, SUM(i.status = 'draft') AS drafts,
            SUM(i.status NOT IN ('void','draft')) AS sent, SUM(i.status = 'void') AS voids,
            SUM(CASE WHEN i.status <> 'void' THEN " . BillingCycles::cadSql('total_amount') . " ELSE 0 END) AS cad,
            MAX(CASE WHEN i.status NOT IN ('void','draft') THEN i.sent_at END) AS last_sent,
            SUM(i.status NOT IN ('void','draft') AND EXISTS (
                SELECT 1 FROM email_logs el WHERE el.entity_type = 'invoice' AND el.entity_id = i.id AND el.status = 'sent')) AS emailed
       FROM invoices i
      WHERE i.deleted_at IS NULL AND i.lease_id IS NOT NULL AND i.invoice_type IN ({$types})
        AND i.billing_period_start BETWEEN ? AND ?
      GROUP BY m",
    [$first . '-01', date('Y-m-t', strtotime($last . '-01'))]
) as $r) {
    $agg[$r['m']] = $r;
}
$exc = [];
foreach (db_select(
    "SELECT DATE_FORMAT(period_start, '%Y-%m') AS m, COUNT(*) AS n FROM invoice_billing_exceptions
      WHERE deleted_at IS NULL AND period_start BETWEEN ? AND ? GROUP BY m",
    [$first . '-01', date('Y-m-t', strtotime($last . '-01'))]
) as $r) {
    $exc[$r['m']] = (int) $r['n'];
}
$cyc = [];
foreach (db_select("SELECT id, status, DATE_FORMAT(period_start, '%Y-%m') AS m FROM billing_cycles WHERE period_start BETWEEN ? AND ?",
    [$first . '-01', $last . '-01']) as $r) {
    $cyc[$r['m']] = ['id' => (int) $r['id'], 'status' => $r['status']];
}

$out = [];
for ($m = $first; $m <= $last; $m = BillingCycles::shiftMonth($m, 1)) {
    $a = $agg[$m] ?? null;
    $monthEnd = date('Y-m-t', strtotime($m . '-01'));
    $days = null;
    if ($a && $a['last_sent'] && (int) $a['drafts'] === 0) {
        $days = (int) floor((strtotime(substr((string) $a['last_sent'], 0, 10)) - strtotime($monthEnd)) / 86400);
    }
    $sent = (int) ($a['sent'] ?? 0);
    $out[] = [
        'month'        => $m,
        'label'        => date('M Y', strtotime($m . '-01')),
        'invoices'     => (int) ($a['live'] ?? 0),
        'drafts'       => (int) ($a['drafts'] ?? 0),
        'sent'         => $sent,
        'emailed_pct'  => $sent > 0 ? (int) round((int) $a['emailed'] / $sent * 100) : null,
        'days_to_send' => $days,
        'exceptions'   => $exc[$m] ?? 0,
        'voids'        => (int) ($a['voids'] ?? 0),
        'billed_cad'   => $showMoney ? bcadd((string) ($a['cad'] ?? '0'), '0', 2) : null,
        'cycle'        => $cyc[$m] ?? null,
    ];
}
json_success(['months' => $out]);
