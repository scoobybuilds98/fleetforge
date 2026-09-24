<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/signoff.php
 *
 * S-BILLING-MODULE-2 — sign off (or withdraw the sign-off of) one of the
 * cycle's steps: who finished it, when, with an optional note. Stored in
 * billing_cycles.step_signoffs and shown on the stepper — the cycle's own
 * record of who did what, next to the derived "done" state. Logic:
 * BillingCycles::signoff().
 *
 * @method  POST
 * @body    { id, step: prepare|readings|generate|review|approve|send, signed: bool, note? }
 *          (no 'close' — closing the cycle is that step's sign-off)
 * @auth    Session required; invoices:edit
 * @returns 200 { step_signoffs }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingCycles;

$cycle = billing_cycle_from_request();
billing_cycle_require_open($cycle);
$body = json_body();
$step = (string) ($body['step'] ?? '');
if (!in_array($step, BillingCycles::SIGNOFF_STEPS, true)) {
    json_validation_error(['step' => 'Unknown step.']);
}
$map = BillingCycles::signoff($cycle, $step, !empty($body['signed']), clean_string($body['note'] ?? null, 500));
json_success(['step_signoffs' => (object) $map]);
