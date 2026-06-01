<?php
require_once __DIR__ . '/../api/bootstrap.php';

echo "=== LIVE DB VALUES ===\n";
$rev = db_row("SELECT COALESCE(SUM(monthly_rate),0) AS t FROM leases WHERE status='active' AND deleted_at IS NULL");
echo "Active Revenue: \$" . number_format((float)$rev['t'], 2) . "\n";

$fleet = db_row("SELECT COUNT(*) total, SUM(CASE WHEN status='on_lease' THEN 1 ELSE 0 END) ol FROM equipment_units WHERE status!='decommissioned' AND deleted_at IS NULL");
$util = (int)$fleet['total'] > 0 ? round(((int)$fleet['ol']/(int)$fleet['total'])*100, 1) : 0;
echo "Fleet: {$fleet['ol']} of {$fleet['total']} = {$util}%\n";

$od = db_row("SELECT COUNT(*) cnt, COALESCE(SUM(balance_due),0) tot FROM invoices WHERE status='overdue' AND deleted_at IS NULL");
echo "Overdue: {$od['cnt']} / \$" . number_format((float)$od['tot'], 2) . "\n";

$t30 = date('Y-m-d', strtotime('+30 days'));
$ca = db_row("SELECT COUNT(DISTINCT id) cnt FROM equipment_units WHERE deleted_at IS NULL AND status!='decommissioned' AND ((cvi_expiry IS NOT NULL AND cvi_expiry<=?) OR (registration_expiry IS NOT NULL AND registration_expiry<=?) OR (mvi_expiry IS NOT NULL AND mvi_expiry<=?) OR (insurance_expiry IS NOT NULL AND insurance_expiry<=?))", [$t30,$t30,$t30,$t30]);
echo "Compliance alerts: {$ca['cnt']}\n";

$ol = db_row("SELECT COUNT(*) cnt FROM leases WHERE status IN ('active','pending') AND deleted_at IS NULL");
echo "Open leases: {$ol['cnt']}\n";

$today = date('Y-m-d');
$tp = db_row("SELECT COUNT(*) cnt FROM reservations WHERE pickup_date=? AND status IN ('pending','confirmed') AND deleted_at IS NULL", [$today]);
echo "Today's pickups: {$tp['cnt']}\n";

echo "\n=== CACHE STATE ===\n";
$rows = db_select("SELECT report_type, parameters_hash, generated_at, expires_at, expires_at > NOW() AS valid FROM report_cache WHERE report_type='dashboard_kpis'");
if (!$rows) {
    echo "No cache row found.\n";
} else {
    foreach ($rows as $r) {
        echo "Generated: {$r['generated_at']}, Expires: {$r['expires_at']}, Still valid: " . ((int)$r['valid'] ? 'YES' : 'NO') . "\n";
    }
}
$cur = db_row("SELECT result_data, expires_at FROM report_cache WHERE report_type='dashboard_kpis' ORDER BY generated_at DESC LIMIT 1");
if ($cur) {
    echo "\n=== CURRENT CACHE PAYLOAD ===\n";
    $d = json_decode($cur['result_data'], true);
    echo "Active Revenue: \$" . number_format((float)($d['active_revenue']??0), 2) . "\n";
    echo "Fleet: {$d['on_lease_count']} of {$d['total_active_units']} = {$d['fleet_utilization']}%\n";
    echo "Overdue: {$d['overdue_invoices']['count']} / \$" . number_format((float)($d['overdue_invoices']['total']??0), 2) . "\n";
    echo "Compliance: {$d['compliance_alerts']}\n";
    echo "Open Leases: {$d['open_leases']}\n";
    echo "Today's Pickups: {$d['todays_pickups']}\n";
    echo "Expires: {$cur['expires_at']}\n";
}
