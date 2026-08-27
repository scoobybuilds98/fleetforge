<?php
declare(strict_types=1);

/**
 * lib/Billing/BillingRateGuidance.php
 *
 * Turns a BillingRateException into an OPERATOR-ACTIONABLE error payload:
 * what happened, why, what to do about it, and the links that do it.
 *
 * WHY (S-BILLING-GUIDANCE, from FLEETFORGE-1E): a rate hole is not a server
 * fault — it is a lease the operator can fix in about thirty seconds, once
 * they know which lease, which field, and where the field lives. Lease #528
 * spent an afternoon throwing "AN UNEXPECTED ERROR OCCURRED" at every attempt
 * to invoice OR close it, and nothing on screen said "this lease has an
 * estimated mileage with no rate to price it". The engine knew exactly that —
 * it is in the exception message — but the message went to Sentry, not to the
 * person who could act on it.
 *
 * The `guidance` key this builds is a GENERAL contract, not a billing one:
 * any endpoint may attach it to a json_error(), and public/assets/js/app.js
 * pops the explain-and-fix modal for it automatically (FF_Api → FF_Guidance).
 * Shape:
 *
 *   guidance: {
 *     title:   string,           // modal heading
 *     summary: string,           // one plain-English sentence: what happened
 *     cause:   string|null,      // the specific numbers behind it
 *     steps:   string[],         // what to do, in order
 *     actions: [{ label, url, primary? }],   // links that do it (same-origin)
 *     detail:  string|null       // raw engine diagnostic, collapsed by default
 *   }
 *
 * Defines: FleetForge\Billing\BillingRateGuidance
 * Used by: api/v1/invoices/create.php, api/v1/invoices/regenerate.php,
 *          api/v1/leases/close.php
 * @session S-MILEAGE-EST-RATE-HOLE / S-BILLING-GUIDANCE
 */

namespace FleetForge\Billing;

final class BillingRateGuidance
{
    /**
     * Build the full json_error() $extra array for a BillingRateException.
     *
     * @param BillingRateException $e              The engine's refusal.
     * @param int                  $leaseId        Lease the action targeted.
     * @param string               $blockedAction  What the operator was doing,
     *                                             as a sentence fragment:
     *                                             'create this invoice',
     *                                             'close this lease'.
     * @param string|null          $contractNumber For the modal heading.
     * @return array<string,mixed>
     */
    public static function payload(
        BillingRateException $e,
        int $leaseId,
        string $blockedAction,
        ?string $contractNumber = null
    ): array {
        $ctx = $e->context;

        $leaseLabel = $contractNumber !== null && $contractNumber !== ''
            ? "Lease {$contractNumber}"
            : "Lease #{$leaseId}";

        $editUrl  = self::url('leases/edit?id=' . $leaseId);
        $amendUrl = self::url('leases/show?id=' . $leaseId . '#amendments');

        // Which hole is it? Keyed on the context each guard supplies, so a new
        // guard that forgets to add one still gets the generic branch rather
        // than a wrong explanation.
        if (isset($ctx['estimated_mileage_per_day_km'])) {
            $g = [
                'summary' => 'This lease bills an estimated mileage every period, but it has no '
                           . 'mileage rate to price that estimate with — so the invoice cannot be '
                           . 'calculated and nothing was billed.',
                'cause'   => sprintf(
                    'Estimated mileage per day is %s km, and the mileage rate is $%s/km.',
                    self::trimNum((string) $ctx['estimated_mileage_per_day_km']),
                    self::trimNum((string) ($ctx['mileage_rate_km'] ?? '0'))
                ),
                'steps'   => [
                    'If this lease SHOULD bill mileage: set a mileage rate through the Rate '
                    . 'Amendment workflow (rates are audit-trailed, so they cannot be edited '
                    . 'directly on the lease).',
                    'If it should NOT: open Edit Lease and set "Estimated mileage per day" back to 0.',
                    'Then ' . $blockedAction . ' again.',
                ],
                'actions' => [
                    ['label' => 'Set a mileage rate', 'url' => $amendUrl, 'primary' => true],
                    ['label' => 'Edit lease — clear the estimate', 'url' => $editUrl],
                ],
            ];
        } elseif (isset($ctx['estimated_mileage_km'])) {
            $g = [
                'summary' => 'This lease has a mileage allowance configured but no mileage rate to '
                           . 'price the distance driven — so the invoice cannot be calculated and '
                           . 'nothing was billed.',
                'cause'   => sprintf(
                    'Mileage allowance is %s km (distance this period: %s km), and the mileage rate is $%s/km.',
                    self::trimNum((string) $ctx['estimated_mileage_km']),
                    self::trimNum((string) ($ctx['period_distance_km'] ?? '0')),
                    self::trimNum((string) ($ctx['mileage_rate_km'] ?? '0'))
                ),
                'steps'   => [
                    'If this lease SHOULD bill mileage: set a mileage rate through the Rate '
                    . 'Amendment workflow.',
                    'If it should NOT: open Edit Lease and clear the mileage allowance.',
                    'Then ' . $blockedAction . ' again.',
                ],
                'actions' => [
                    ['label' => 'Set a mileage rate', 'url' => $amendUrl, 'primary' => true],
                    ['label' => 'Edit lease — clear the allowance', 'url' => $editUrl],
                ],
            ];
        } elseif (isset($ctx['estimated_engine_hours_per_day'])) {
            $g = [
                'summary' => 'This lease bills estimated engine hours every period, but it has no '
                           . 'hourly rate to price them with — so the invoice cannot be calculated '
                           . 'and nothing was billed.',
                'cause'   => sprintf(
                    'Estimated engine hours per day is %s, and the hourly rate is $%s.',
                    self::trimNum((string) $ctx['estimated_engine_hours_per_day']),
                    self::trimNum((string) ($ctx['hourly_rate'] ?? '0'))
                ),
                'steps'   => [
                    'If this lease SHOULD bill engine hours: set an hourly rate through the Rate '
                    . 'Amendment workflow.',
                    'If it should NOT: open Edit Lease and set "Estimated engine hours per day" to 0.',
                    'Then ' . $blockedAction . ' again.',
                ],
                'actions' => [
                    ['label' => 'Set an hourly rate', 'url' => $amendUrl, 'primary' => true],
                    ['label' => 'Edit lease — clear the estimate', 'url' => $editUrl],
                ],
            ];
        } elseif ($e->method === 'none') {
            $g = [
                'summary' => 'This lease has NO billing basis at all — every rental tier, the '
                           . 'mileage rate and the hourly rate are zero — so billing it would '
                           . 'produce an empty $0 invoice. Nothing was billed.',
                'cause'   => sprintf(
                    'Daily $%s / weekly $%s / monthly $%s, over %d billable day(s).',
                    self::trimNum($e->daily), self::trimNum($e->weekly),
                    self::trimNum($e->monthly), $e->days
                ),
                'steps'   => [
                    'Set the lease\'s rates through the Rate Amendment workflow — a lease must '
                    . 'carry at least one rate greater than zero.',
                    'All three rental tiers (daily, weekly, monthly) must be set together.',
                    'Then ' . $blockedAction . ' again.',
                ],
                'actions' => [
                    ['label' => 'Set the lease rates', 'url' => $amendUrl, 'primary' => true],
                ],
            ];
        } else {
            $g = [
                'summary' => 'The billing engine refused to bill this lease because its rates are '
                           . 'incomplete — the period it was asked to bill priced out at $0. '
                           . 'Nothing was billed.',
                'cause'   => sprintf(
                    'Tier used: %s. Daily $%s / weekly $%s / monthly $%s, over %d day(s).',
                    $e->method, self::trimNum($e->daily), self::trimNum($e->weekly),
                    self::trimNum($e->monthly), $e->days
                ),
                'steps'   => [
                    'Open the Rate Amendment workflow and fill in every rate tier — when any of '
                    . 'daily / weekly / monthly is set, all three must be greater than zero.',
                    'Then ' . $blockedAction . ' again.',
                ],
                'actions' => [
                    ['label' => 'Review the lease rates', 'url' => $amendUrl, 'primary' => true],
                ],
            ];
        }

        $period = isset($ctx['period_start'], $ctx['period_end'])
            ? " (period {$ctx['period_start']} → {$ctx['period_end']})"
            : '';

        return [
            'lease_id'        => $leaseId,
            'detail'          => $e->getMessage(),
            'billing_context' => $ctx,
            'guidance'        => [
                'title'   => $leaseLabel . ' needs a rate before it can be billed',
                'summary' => $g['summary'] . $period,
                'cause'   => $g['cause'],
                'steps'   => $g['steps'],
                'actions' => $g['actions'],
                // The engine's own words — shown behind a "technical detail"
                // toggle so support can read it without facing the operator
                // with a file path.
                'detail'  => $e->getMessage(),
            ],
        ];
    }

    /** base_url() when the app helpers are loaded; a root-relative path otherwise. */
    private static function url(string $path): string
    {
        return function_exists('base_url') ? base_url($path) : '/' . ltrim($path, '/');
    }

    /** 160.9344 → "160.9344"; 0.0000 → "0"; 100.00 → "100". */
    private static function trimNum(string $n): string
    {
        if (!str_contains($n, '.')) {
            return $n;
        }
        $t = rtrim(rtrim($n, '0'), '.');
        return $t === '' || $t === '-' ? '0' : $t;
    }
}
