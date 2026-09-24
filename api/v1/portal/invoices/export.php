<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/export.php
 *
 * Customer portal — the Invoices list as a CSV for the customer's own AP
 * system (S-PORTAL-REDESIGN). Same tabs/filters as api/v1/portal/invoices/list
 * (tab, q, from, to), no paging (max 5,000 rows).
 *
 * Money columns are the stored decimals (bcmath strings), not formatted, so
 * a spreadsheet sums them exactly. A leading =,+,-,@ in any text cell is
 * neutralised (CSV formula injection).
 *
 * @method  GET
 * @auth    portal session
 * @returns text/csv attachment
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

require_method('GET');
require_portal_auth_api();

$cid   = portal_customer_id();
$today = ff_today();
$tab   = (string) ($_GET['tab'] ?? 'all');
$q     = trim((string) clean_string($_GET['q'] ?? '', 100));
$from  = clean_date($_GET['from'] ?? null);
$to    = clean_date($_GET['to'] ?? null);

$where  = "i.customer_id = ? AND " . pt_invoice_visible_sql('i');
$params = [$cid];
switch ($tab) {
    case 'open':     $where .= " AND i.status IN (" . pt_open_statuses_sql() . ")"; break;
    case 'past_due': $where .= " AND i.status IN (" . pt_open_statuses_sql() . ") AND i.balance_due > 0 AND (i.status = 'overdue' OR i.due_date < ?)"; $params[] = $today; break;
    case 'paid':     $where .= " AND i.status = 'paid'"; break;
    default:         break;
}
if ($q !== '')  { $where .= " AND (i.invoice_number LIKE ? OR l.contract_number LIKE ?)"; $params[] = pt_like($q); $params[] = pt_like($q); }
if ($from)      { $where .= " AND i.invoice_date >= ?"; $params[] = $from; }
if ($to)        { $where .= " AND i.invoice_date <= ?"; $params[] = $to; }

$rows = db_select(pt_invoice_select_sql() . " WHERE {$where} ORDER BY i.invoice_date DESC, i.id DESC LIMIT 5000", $params);

$cell = static function (mixed $v): string {
    $s = (string) ($v ?? '');
    return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
};

if (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="invoices_' . $today . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
fputcsv($out, ['Invoice', 'Invoice date', 'Due date', 'Period start', 'Period end', 'Lease', 'Status', 'Currency', 'Total', 'Paid', 'Balance due'], ',', '"', '');
foreach ($rows as $r) {
    $j = pt_invoice_json($r);
    fputcsv($out, [
        $cell($j['number']),
        $j['invoice_date'],
        $j['due_date'],
        (string) ($r['billing_period_start'] ?? ''),
        (string) (ff_invoice_display_period_end($r) ?? ''),
        $cell($j['lease']),
        $j['status_label'],
        $j['currency'],
        $j['total'],
        $j['paid'],
        $j['balance'],
    ], ',', '"', ''); // explicit escape — PHP 8.4 deprecates the implicit "\\" default
}
fclose($out);
exit;
