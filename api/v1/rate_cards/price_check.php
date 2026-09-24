<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/price_check.php
 *
 * S-RATES-MODULE — "What would this customer pay?" The price a new lease for
 * (customer, equipment type) would pre-fill on a date, why (every candidate
 * line and the rule that decided it), the standard price for comparison, and
 * — when a rental period is given — an estimate from the real billing law
 * (HolisticLeaseEngine::cumulativeCorrect).
 *
 * Runs the same resolver as the lease form's lookup (lookup_rates.php), so the
 * answer here is the answer there.
 *
 * @method  GET
 * @query   template_id (required), customer_id? (none = standard price),
 *          date? (Y-m-d, default today), start? + end? (Y-m-d, rental to quote),
 *          gps? (1 = include GPS), distance_per_day?, hours_per_day?
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 { customer, template, date, price, standard, candidates, tiers, quote|null }
 *          404 NOT_FOUND (customer / equipment type) · 422 VALIDATION_ERROR
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateResolver;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$templateId = clean_int($_GET['template_id'] ?? null);
if (!$templateId) {
    json_validation_error(['template_id' => 'Choose an equipment type.']);
}
$template = RateResolver::template($templateId);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment type not found.', 404);
}

$customer   = null;
$customerId = clean_int($_GET['customer_id'] ?? null);
if ($customerId) {
    $customer = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
    if (!$customer) {
        json_error('NOT_FOUND', 'Customer not found.', 404);
    }
}

$date = ff_today();
if (!empty($_GET['date'])) {
    $date = RateCardItems::validDate($_GET['date']);
    if ($date === null) {
        json_validation_error(['date' => 'Enter a valid date.']);
    }
}

$out = RateResolver::explain($customer ? (int) $customer['id'] : null, $template, $date);

$quote = null;
if (!empty($_GET['start']) || !empty($_GET['end'])) {
    $start = RateCardItems::validDate($_GET['start'] ?? null);
    $end   = RateCardItems::validDate($_GET['end'] ?? null);
    $errs  = [];
    if ($start === null) {
        $errs['start'] = 'Enter the day the rental starts.';
    }
    if ($end === null) {
        $errs['end'] = 'Enter the day it comes back.';
    } elseif ($start !== null && $end < $start) {
        $errs['end'] = 'The return must be on or after the start.';
    } elseif ($start !== null && (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 1100) {
        $errs['end'] = 'Quote at most three years at a time.';
    }
    if ($errs) {
        json_validation_error($errs);
    }
    $quote = RateResolver::quote($out['price'], $template, $start, $end, [
        'gps'              => !empty($_GET['gps']),
        'distance_per_day' => $_GET['distance_per_day'] ?? null,
        'hours_per_day'    => $_GET['hours_per_day'] ?? null,
    ]);
}

json_success($out + [
    'customer' => $customer ? ['id' => (int) $customer['id'], 'name' => $customer['company_name']] : null,
    'template' => [
        'id'             => (int) $template['id'],
        'name'           => $template['name'],
        'category'       => $template['category'],
        'category_label' => RateCardItems::label((string) $template['category']),
    ],
    'quote'    => $quote,
]);
