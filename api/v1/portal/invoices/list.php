<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/list.php
 *
 * Customer portal — the Invoices page's data (S-PORTAL-REDESIGN). Replaces
 * the page's old ?ajax=1 branch, which answered an expired session with the
 * login page's HTML (the table then silently kept stale rows) and capped the
 * list at 100 with no paging.
 *
 * Tabs:  open (sent/partly paid/overdue) · past_due (open and past its due
 *        date) · paid · all (everything the customer may see — incl. advance
 *        bills for future periods)
 * Filters: q (invoice # or lease contract #), from/to (invoice date)
 * Paging:  page (1-based), per_page (10–100, default 25)
 *
 * Trap 8: customer-scoped; visibility = pt_invoice_visible_sql().
 *
 * @method  GET
 * @auth    portal session (401 JSON when it has ended)
 * @returns { invoices:[...], total:int, counts:{open,past_due,paid,all} }
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

require_method('GET');
require_portal_auth_api();

$cid     = portal_customer_id();
$today   = ff_today();
$tab     = (string) ($_GET['tab'] ?? 'open');
$q       = trim((string) clean_string($_GET['q'] ?? '', 100));
$from    = clean_date($_GET['from'] ?? null);
$to      = clean_date($_GET['to'] ?? null);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

$tabs = [
    'open'     => "i.status IN (" . pt_open_statuses_sql() . ")",
    'past_due' => "i.status IN (" . pt_open_statuses_sql() . ") AND i.balance_due > 0 AND (i.status = 'overdue' OR i.due_date < ?)",
    'paid'     => "i.status = 'paid'",
    'all'      => '1=1',
];
if (!isset($tabs[$tab])) {
    $tab = 'open';
}

$base       = "i.customer_id = ? AND " . pt_invoice_visible_sql('i');
$baseParams = [$cid];

// Search + date filters apply to the rows AND to every tab count, so the
// numbers on the tabs always describe what clicking them will show.
$filter = '';
$filterParams = [];
if ($q !== '') {
    $filter .= " AND (i.invoice_number LIKE ? OR l.contract_number LIKE ?)";
    $filterParams[] = pt_like($q);
    $filterParams[] = pt_like($q);
}
if ($from) { $filter .= " AND i.invoice_date >= ?"; $filterParams[] = $from; }
if ($to)   { $filter .= " AND i.invoice_date <= ?"; $filterParams[] = $to; }

$tabParams = static fn (string $t): array => $t === 'past_due' ? [$today] : [];

$counts = [];
foreach ($tabs as $t => $cond) {
    $counts[$t] = db_count(
        "SELECT COUNT(*) FROM invoices i
           LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
          WHERE {$base} AND {$cond}{$filter}",
        array_merge($baseParams, $tabParams($t), $filterParams)
    );
}

$order = match ($tab) {
    'open', 'past_due' => 'i.due_date ASC, i.id ASC',
    default            => 'i.invoice_date DESC, i.id DESC',
};

$offset = ($page - 1) * $perPage;
$rows = db_select(
    pt_invoice_select_sql() . " WHERE {$base} AND {$tabs[$tab]}{$filter} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
    array_merge($baseParams, $tabParams($tab), $filterParams)
);

json_success([
    'invoices' => array_map('pt_invoice_json', $rows),
    'total'    => $counts[$tab],
    'counts'   => $counts,
    'page'     => $page,
    'per_page' => $perPage,
]);
