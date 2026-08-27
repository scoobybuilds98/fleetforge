<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_guidance_payload.php
 *
 * S-BILLING-GUIDANCE — PROVE the explain-and-fix payload is correct for every
 * shape of BillingRateException the engine can throw.
 *
 * This is the operator-facing half of the FLEETFORGE-1E fix: the endpoints
 * answer a rate hole with 422 + `error.guidance`, and app.js pops a modal
 * showing it (FF_Api._guide → FF_Guidance → FF_GuidanceModal). If the branch
 * selection here is wrong, the operator is told to fix the wrong field —
 * worse than the old generic error, so it is worth pinning.
 *
 * Deliberately DEPENDENCY-FREE: no config/app.php, no database, no composer
 * autoload — it requires the two lib files directly and stubs base_url().
 * That means it runs anywhere, including a checkout with no .env, and it is
 * the one test in this fix that needs no fixtures.
 *
 * Cases:
 *   G1  per-day mileage estimate + no rate (the real lease #528 exception) →
 *       mileage branch, both fix links, numbers rendered from the context.
 *   G2  mileage ALLOWANCE + no rate → allowance branch (not the per-day one).
 *   G3  per-day engine hours + no hourly rate → hours branch, hourly links.
 *   G4  no billing basis at all (method='none') → rates branch, amendment only.
 *   G5  zero base_rental from a tier hole → generic tier branch.
 *   G6  every action URL is same-origin and carries the right lease id.
 *   G7  the engine diagnostic is preserved verbatim in `detail`.
 *
 * Run: php tests/_smoke_billing_guidance_payload.php
 *
 * @session S-MILEAGE-EST-RATE-HOLE / S-BILLING-GUIDANCE
 */

if (!function_exists('base_url')) {
    function base_url(string $p = ''): string { return '/fleetforge/' . ltrim($p, '/'); }
}

require_once __DIR__ . '/../lib/Billing/BillingRateException.php';
require_once __DIR__ . '/../lib/Billing/BillingRateGuidance.php';

use FleetForge\Billing\BillingRateException;
use FleetForge\Billing\BillingRateGuidance;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-BILLING-GUIDANCE — explain-and-fix payload per exception shape\n" . str_repeat('=', 72) . "\n";

/** @return array<string,mixed> the guidance block */
function guidance(string $method, array $ctx, string $action = 'create this invoice',
                  ?string $contract = null, int $leaseId = 528, string $detail = 'engine detail'): array {
    $e = new BillingRateException($detail, $method, 25, '80.00', '480.00', '1800.00', $ctx);
    return BillingRateGuidance::payload($e, $leaseId, $action, $contract)['guidance'];
}

/** Every label in the actions list, joined — for substring assertions. */
function labels(array $g): string {
    return implode(' | ', array_column($g['actions'], 'label'));
}

// ── G1: the real lease #528 exception ───────────────────────────────────────
$realDetail = 'InvoiceGenerator refused to bill estimated mileage: lease_id=528, '
    . 'period=2026-08-01..2026-08-25 (25 days), estimated_mileage_per_day_km=160.9344 '
    . 'configured but mileage_rate_km=0.0000. Set a mileage rate at lease create '
    . '(rate-tier completeness — see api/v1/leases/create.php).';
$g1 = guidance('mileage_excess', [
    'lease_id' => 528, 'period_start' => '2026-08-01', 'period_end' => '2026-08-25',
    'estimated_mileage_per_day_km' => '160.9344', 'mileage_rate_km' => '0.0000',
    'billing_type' => 'partial_start',
], 'close this lease', 'MTTS473', 528, $realDetail);

ck('G1a', str_contains($g1['title'], 'MTTS473'),
    "title names the lease by contract number: \"{$g1['title']}\"");
ck('G1b', str_contains($g1['summary'], 'estimated mileage')
        && str_contains($g1['summary'], 'no mileage rate')
        && str_contains($g1['summary'], '2026-08-01 → 2026-08-25'),
    'summary states the problem + the period');
ck('G1c', str_contains($g1['cause'], '160.9344 km') && str_contains($g1['cause'], '$0/km'),
    "cause carries both numbers: \"{$g1['cause']}\"");
ck('G1d', count($g1['steps']) === 3 && str_contains($g1['steps'][2], 'close this lease'),
    'steps end with the action the operator was blocked on');
ck('G1e', str_contains(labels($g1), 'Set a mileage rate')
        && str_contains(labels($g1), 'clear the estimate')
        && $g1['actions'][0]['primary'] === true,
    'both exits offered, rate amendment primary: ' . labels($g1));

// ── G2: allowance, not per-day — must NOT take the per-day branch ───────────
$g2 = guidance('mileage_excess', [
    'estimated_mileage_km' => '5000.000', 'mileage_rate_km' => '0.0000',
    'period_distance_km' => '812.4',
]);
ck('G2', str_contains($g2['summary'], 'mileage allowance')
        && str_contains($g2['cause'], '5000 km')
        && str_contains($g2['cause'], '812.4 km')
        && str_contains(labels($g2), 'clear the allowance'),
    "allowance branch: \"{$g2['cause']}\"");

// ── G3: engine hours ────────────────────────────────────────────────────────
$g3 = guidance('mileage_excess', [
    'estimated_engine_hours_per_day' => '8.00', 'hourly_rate' => '0.0000',
]);
ck('G3', str_contains($g3['summary'], 'engine hours')
        && str_contains($g3['cause'], '8')
        && str_contains(labels($g3), 'Set an hourly rate'),
    "hours branch points at the hourly rate: {$g3['cause']}");

// ── G4: no billing basis at all ─────────────────────────────────────────────
$g4 = guidance('none', ['lease_id' => 99]);
ck('G4', str_contains($g4['summary'], 'NO billing basis')
        && count($g4['actions']) === 1
        && str_contains(labels($g4), 'Set the lease rates'),
    'no-basis branch offers the amendment only (nothing to "clear")');

// ── G5: tier hole (HolisticLeaseEngine zero base_rental) ────────────────────
$g5 = guidance('monthly', [
    'lease_id' => 99, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
    'total_days_so_far' => 31, 'cumulative_correct' => '0.00', 'already_billed' => '0.00',
]);
ck('G5', str_contains($g5['summary'], 'rates are incomplete')
        && str_contains($g5['cause'], 'Tier used: monthly')
        && !str_contains($g5['summary'], 'mileage'),
    "unknown-context shape falls to the tier branch, not a wrong one: \"{$g5['cause']}\"");

// ── G6: every URL is same-origin and carries the right lease ────────────────
$allUrls = [];
foreach ([$g1, $g2, $g3, $g4, $g5] as $g) {
    foreach ($g['actions'] as $a) { $allUrls[] = $a['url']; }
}
$offSite  = array_filter($allUrls, static fn(string $u): bool => (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $u));
$leaseIds = array_filter($g1['actions'], static fn(array $a): bool => str_contains($a['url'], 'id=528'));
ck('G6', $offSite === [] && count($leaseIds) === count($g1['actions']) && $allUrls !== [],
    count($allUrls) . ' action URLs, all relative + lease-scoped (e.g. ' . $g1['actions'][0]['url'] . ')');

// ── G7: the engine's own words survive for support ──────────────────────────
ck('G7', $g1['detail'] === $realDetail,
    'engine diagnostic preserved verbatim in detail (' . strlen($g1['detail']) . ' chars)');

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — every exception shape yields correct, actionable guidance\033[0m\n";
exit(0);
