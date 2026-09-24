<?php
declare(strict_types=1);

/**
 * api/v1/billing/_cycle.php
 *
 * S-BILLING-MODULE — shared loader for the api/v1/billing/cycles/* and
 * readings/* endpoints: resolve the cycle a request is about, from
 * `id` / `cycle_id` (query or JSON body) or `month` (YYYY-MM), or 404.
 * Keeps the lookup + not-found contract in one place so the endpoints
 * cannot drift. Not an endpoint itself: it defines functions only.
 *
 * @depends api/bootstrap.php, lib/Billing/Cycle/BillingCycles.php
 * @session S-BILLING-MODULE
 */

use FleetForge\Billing\Cycle\BillingCycles;

/**
 * The cycle named by the request, or a JSON 404.
 * `month` resolves an EXISTING cycle only — opening one is cycles/open.
 */
function billing_cycle_from_request(): array
{
    $body  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? [] : json_body();
    $id    = clean_int($_GET['id'] ?? $_GET['cycle_id'] ?? $body['id'] ?? $body['cycle_id'] ?? null);
    $month = (string) ($_GET['month'] ?? $body['month'] ?? '');

    $cycle = null;
    if ($id && $id > 0) {
        $cycle = BillingCycles::find($id);
    } elseif ($month !== '') {
        if (!BillingCycles::isValidMonth($month)) {
            json_validation_error(['month' => 'Month must be YYYY-MM.']);
        }
        $cycle = BillingCycles::findByMonth($month);
    } else {
        json_error('MISSING_REQUIRED', 'A cycle id or month is required.', 422);
    }
    if (!$cycle) {
        json_error('NOT_FOUND', 'Billing cycle not found.', 404);
    }
    return $cycle;
}

/** 409 when a write targets a closed cycle (reopen first). */
function billing_cycle_require_open(array $cycle): void
{
    if ($cycle['status'] !== 'open') {
        json_error('CYCLE_CLOSED', "Billing cycle {$cycle['reference']} is closed. Reopen it to make changes.", 409);
    }
}
