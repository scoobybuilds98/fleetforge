<?php
declare(strict_types=1);

/**
 * api/v1/accounting/leases/classify.php
 *
 * Run the ASPE 3065 lease classification wizard. Accepts wizard inputs,
 * evaluates 3065.06 criteria (A/B/C), checks 3065.07–.08 qualifying
 * conditions, persists the result, and returns the full breakdown.
 *
 * @method  POST
 * @body    JSON:
 *   - lease_id (required, int > 0)
 *   - lease_term_months (required, int > 0) — operator-provided per
 *     decision D-LESSOR-1-TERM: leases has no term_months column on disk,
 *     so the wizard takes term as input.
 *   - economic_life_months (required, int > 0)
 *   - initial_fair_value (required, positive decimal string)
 *   - discount_rate (required, decimal string ≥ 0, e.g. '0.0650' = 6.5%)
 *   - guaranteed_residual_value (optional, default '0.00')
 *   - unguaranteed_residual_value (optional, default '0.00')
 *   - initial_direct_costs (optional, default '0.00')
 *   - title_transfers (optional bool, default false)
 *   - bpo_amount (optional decimal string)
 *   - bpo_date (optional Y-m-d string)
 *   - criterion_a_notes (optional string)
 *   - credit_risk_normal (optional bool, default true)
 *   - costs_estimable (optional bool, default true)
 *   - confirm_reclassify (bool — REQUIRED if lease is already non-operating)
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { lease_id, classification, rationale, criteria{A,B,C},
 *                qualifying{any_criterion_met,all_conditions_met},
 *                asset_carrying_amount, archive }
 *          422 invalid input
 *          409 confirm_reclassify required on already-classified lease
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.2
 * Session: S-ACCT-LESSOR-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\LeaseClassificationService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body    = json_body();
$leaseId = clean_positive_int($body['lease_id'] ?? null);
if ($leaseId === null) {
    json_error('VALIDATION_ERROR', 'lease_id is required and must be a positive integer.', 422);
}

$leaseTermMonths     = clean_positive_int($body['lease_term_months'] ?? null);
$economicLifeMonths  = clean_positive_int($body['economic_life_months'] ?? null);
$fairValue           = clean_positive_decimal($body['initial_fair_value'] ?? null);
$discountRate        = clean_decimal($body['discount_rate'] ?? null);
$guaranteedResid     = clean_decimal($body['guaranteed_residual_value'] ?? '0') ?? '0';
$unguaranteedResid   = clean_decimal($body['unguaranteed_residual_value'] ?? '0') ?? '0';
$initialDirectCosts  = clean_decimal($body['initial_direct_costs'] ?? '0') ?? '0';

if ($leaseTermMonths === null) {
    json_error('VALIDATION_ERROR', 'lease_term_months is required and must be a positive integer.', 422);
}
if ($economicLifeMonths === null) {
    json_error('VALIDATION_ERROR', 'economic_life_months is required and must be a positive integer.', 422);
}
if ($fairValue === null) {
    json_error('VALIDATION_ERROR', 'initial_fair_value is required and must be > 0.', 422);
}
if ($discountRate === null || bccomp($discountRate, '0', 6) < 0) {
    json_error('VALIDATION_ERROR', 'discount_rate is required and must be ≥ 0 (decimal, e.g. 0.0650 for 6.5%).', 422);
}
foreach (['guaranteed_residual_value' => $guaranteedResid,
          'unguaranteed_residual_value' => $unguaranteedResid,
          'initial_direct_costs' => $initialDirectCosts] as $name => $val) {
    if (bccomp($val, '0', 2) < 0) {
        json_error('VALIDATION_ERROR', "{$name} cannot be negative.", 422);
    }
}

// Optional BPO fields — only validated if present.
$bpoAmount = isset($body['bpo_amount']) && $body['bpo_amount'] !== ''
    ? clean_decimal($body['bpo_amount'])
    : null;
if (isset($body['bpo_amount']) && $body['bpo_amount'] !== '' && $bpoAmount === null) {
    json_error('VALIDATION_ERROR', 'bpo_amount must be a decimal value.', 422);
}
$bpoDate = $body['bpo_date'] ?? null;
if ($bpoDate !== null && $bpoDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $bpoDate)) {
    json_error('VALIDATION_ERROR', 'bpo_date must be YYYY-MM-DD.', 422);
}

$wizardInput = [
    'lease_term_months'           => $leaseTermMonths,
    'economic_life_months'        => $economicLifeMonths,
    'initial_fair_value'          => $fairValue,
    'discount_rate'               => $discountRate,
    'guaranteed_residual_value'   => $guaranteedResid,
    'unguaranteed_residual_value' => $unguaranteedResid,
    'initial_direct_costs'        => $initialDirectCosts,
    'title_transfers'             => (bool) ($body['title_transfers']     ?? false),
    'bpo_amount'                  => $bpoAmount,
    'bpo_date'                    => ($bpoDate ?: null),
    'criterion_a_notes'           => (string) ($body['criterion_a_notes'] ?? ''),
    'credit_risk_normal'          => (bool) ($body['credit_risk_normal']  ?? true),
    'costs_estimable'             => (bool) ($body['costs_estimable']     ?? true),
    'confirm_reclassify'          => (bool) ($body['confirm_reclassify']  ?? false),
];

try {
    $result = LeaseClassificationService::runWizard($leaseId, $wizardInput, current_user_id());
} catch (\InvalidArgumentException $e) {
    // STOP CONDITION: surface the reclassify-without-confirm case as 409
    // so the UI can distinguish "needs confirmation" from generic validation.
    $code = str_contains($e->getMessage(), 'confirm_reclassify') ? 409 : 422;
    json_error('VALIDATION_ERROR', $e->getMessage(), $code);
}

json_success($result);
