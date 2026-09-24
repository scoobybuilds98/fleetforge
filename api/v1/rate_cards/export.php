<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/export.php
 *
 * S-RATES-MODULE — every rate-card line as a CSV (one row per line): card,
 * who it is for, window + status, equipment, every price, and the standard
 * price it compares against. For spreadsheets, the accountant, or a price
 * review.
 *
 * Text cells that start with = + - @ (or a tab / carriage return) are
 * prefixed with an apostrophe so a spreadsheet never runs them as a formula
 * (CSV injection); numbers are written as plain decimals.
 *
 * @method  GET
 * @query   status? (in_force default | all), scope? (customer|general)
 * @auth    Session required; require_permission('rates','export')
 * @returns 200 text/csv attachment
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'export');

$today  = ff_today();
$where  = ['rc.deleted_at IS NULL'];
$params = [];
if (($_GET['status'] ?? 'in_force') !== 'all') {
    $where[]  = 'rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)';
    array_push($params, $today, $today);
}
if (($_GET['scope'] ?? '') === 'customer') {
    $where[] = 'rc.customer_id IS NOT NULL';
} elseif (($_GET['scope'] ?? '') === 'general') {
    $where[] = 'rc.customer_id IS NULL';
}

$cards = db_select(
    "SELECT rc.id, rc.name, rc.customer_id, c.company_name AS customer_name,
            rc.effective_from, rc.effective_to, rc.is_default
       FROM rate_cards rc
       LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
      WHERE " . implode(' AND ', $where) . "
      ORDER BY rc.customer_id IS NULL, c.company_name, rc.effective_from DESC, rc.id",
    $params
);
$items = RateInsights::itemsByCard(array_map('intval', array_column($cards, 'id')));

$safe = static function (?string $v): string {
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
};

audit_log_export_rates(count($cards));

header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rate-cards-' . $today . '.csv"');
header('Cache-Control: no-store');
$fh = fopen('php://output', 'w');
fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
fputcsv_rates($fh, ['Card', 'Card ID', 'For', 'Customer', 'Starts', 'Ends', 'Status', 'Equipment', 'Applies to',
              'Currency', 'Daily', 'Weekly', 'Monthly', 'Mileage rate', 'Mileage unit', 'Hourly', 'GPS per day',
              'Minimum days', 'Standard daily', 'Standard monthly', 'Daily vs standard %', 'Notes']);
foreach ($cards as $c) {
    foreach (RateInsights::decorateLines($items[(int) $c['id']] ?? [], $today) as $l) {
        fputcsv_rates($fh, [
            $safe($c['name']),
            (int) $c['id'],
            $c['customer_id'] !== null ? 'Customer' : 'Everyone',
            $safe($c['customer_id'] !== null ? ($c['customer_name'] ?? 'Archived customer') : ''),
            $c['effective_from'],
            $c['effective_to'] ?? '',
            RateCardItems::status($c, $today),
            $safe($l['label']),
            $safe($l['scope']),
            $l['currency'],
            $l['daily_rate'] ?? '',
            $l['weekly_rate'] ?? '',
            $l['monthly_rate'] ?? '',
            $l['mileage_rate'] ?? '',
            $l['mileage_unit'],
            $l['hourly_rate'] ?? '',
            $l['gps_price'] ?? '',
            $l['minimum_days'] ?? '',
            $l['standard']['daily'] ?? '',
            $l['standard']['monthly'] ?? '',
            $l['vs']['daily'] ?? '',
            $safe($l['notes'] ?? ''),
        ]);
    }
}
fclose($fh);
exit;

/** fputcsv with the escape character set explicitly (PHP 8.4 deprecates the default). */
function fputcsv_rates($fh, array $row): void
{
    fputcsv($fh, $row, ',', '"', '');
}

/** One audit row per export (who pulled the price list, and how much of it). */
function audit_log_export_rates(int $cards): void
{
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'export',
        'module'       => 'rates',
        'entity_type'  => 'rate_card',
        'entity_id'    => 0,
        'entity_label' => 'Rate cards CSV',
        'notes'        => 'Exported ' . $cards . ' rate card' . ($cards === 1 ? '' : 's') . ' to CSV.',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
}
