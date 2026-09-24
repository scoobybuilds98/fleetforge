<?php
declare(strict_types=1);

/**
 * api/v1/billing/exchange_rates.php
 *
 * S-BILLING-MODULE — the USD→CAD exchange rate that US-dollar invoices
 * freeze (InvoiceGenerator takes the LATEST exchange_rates row, whatever its
 * date). Until now nothing in the app could write that table — rates only
 * arrived via seed scripts — so a stale rate could not be corrected from the
 * UI. Billing → Settings shows the recent history and adds a day's rate.
 *
 * One row per day (uq_rate_date): entering a rate for a day that already
 * has one replaces it. Payments (FX gain/loss) and banking read the same
 * table, so this is also where their rate comes from.
 *
 * @method  GET | POST
 * @body    POST { rate_date: 'YYYY-MM-DD', rate: '1.365000' }
 * @auth    GET invoices:view; POST settings_general:edit
 * @returns 200 { rates: [{rate_date, rate, source, created_at}] }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET', 'POST');
require_auth_api();
require_permission('invoices', 'view');

$list = static fn(): array => db_select(
    "SELECT rate_date, rate, source, created_at FROM exchange_rates
      WHERE from_currency = 'USD' AND to_currency = 'CAD'
      ORDER BY rate_date DESC, id DESC LIMIT 12",
    []
);

if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
    json_success(['rates' => $list(), 'markup_pct' => (string) settings_get('currency.usd_cad_markup_pct', '0')]);
}

if (!can('settings_general', 'edit')) {
    json_error('FORBIDDEN', 'Entering exchange rates needs the Settings (General) edit permission.', 403);
}

$body = json_body();
$date = clean_date($body['rate_date'] ?? null);
$rate = clean_positive_decimal($body['rate'] ?? null);
$fields = [];
if (!$date) $fields['rate_date'] = 'Enter the date the rate is for.';
elseif ($date > ff_today()) $fields['rate_date'] = 'A rate cannot be entered for a future day.';
if ($rate === null) {
    $fields['rate'] = 'Enter the rate as a number, e.g. 1.3650.';
} elseif (bccomp($rate, '0.5', 6) < 0 || bccomp($rate, '3', 6) > 0) {
    // DECIMAL(10,6); a USD→CAD rate outside 0.5–3 is a typo (e.g. CAD→USD entered).
    $fields['rate'] = 'That does not look like a USD→CAD rate (expected between 0.5 and 3).';
}
if ($fields) json_validation_error($fields);

$rate = bcadd($rate, '0', 6);
$old = db_row("SELECT rate FROM exchange_rates WHERE from_currency = 'USD' AND to_currency = 'CAD' AND rate_date = ?", [$date]);
db_execute(
    "INSERT INTO exchange_rates (from_currency, to_currency, rate, rate_date, source)
     VALUES ('USD', 'CAD', ?, ?, 'manual')
     ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = 'manual'",
    [$rate, $date]
);
db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => $old ? 'update' : 'create',
    'module'       => 'billing',
    'entity_type'  => 'exchange_rate',
    'entity_id'    => null,
    'entity_label' => "USD→CAD {$date}",
    'old_values'   => $old ? json_encode(['rate' => $old['rate']]) : null,
    'new_values'   => json_encode(['rate' => $rate]),
    'notes'        => "USD→CAD rate for {$date} set to {$rate}" . ($old ? " (was {$old['rate']})" : '') . '.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['rates' => $list()]);
