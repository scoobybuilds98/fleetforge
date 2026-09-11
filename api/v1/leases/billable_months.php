<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Billable Months API
 *
 * @file        api/v1/leases/billable_months.php
 * @description R2 §3.6 in-order month picker data source. Returns the lease's
 *              billable calendar-month segments (from lease.start_date through
 *              the known extent E = actual_return ?? end_date ?? today), each
 *              tagged with its generation status:
 *                - 'billed'   → a NON-VOID invoice covers the segment (carries
 *                               invoice_number + invoice_id)
 *                - 'void'     → only a VOID invoice covers it (re-billable, and
 *                               it ARMS next_due_index — see S-PICKER-OPEN-LEASE)
 *                - 'unbilled' → no invoice covers it yet
 *              The segments MIRROR generateForLease's spanning decision so they
 *              match exactly what generation would write: the picker fans into
 *              per-calendar-month segments (HolisticLeaseEngine::segmentsFor)
 *              ONLY when the engine classifies the full-extent lease as
 *              'monthly_multi_month'; every non-spanning case — sub-month
 *              daily/weekly cross-month, single-calendar-month monthly, and the
 *              ≤1-month straddle flat cap (basis 'monthly_short_flat',
 *              S-MONTHLY-SHORT-FLAT) — is presented as ONE 'single_period'
 *              segment [start, extent], exactly the one flat invoice generation
 *              writes. (Before S-MONTHLY-SHORT-FLAT-PICKER this always called
 *              segmentsFor(), so a ≤30-day straddle showed two months while
 *              generation wrote one flat invoice — a contradictory split.)
 *              `next_due_index` is the FIRST unbilled-or-void segment (in-order
 *              gate 4.5 — only that month may be generated next); `fully_billed`
 *              is true when every segment is billed.
 *
 *              S-PICKER-OPEN-LEASE (2026-09-12) — TWO fixes, both in here:
 *                (1) The mirror above applies ONLY to a DEFINITIVE extent
 *                    (actual_return_date / end_date). When the extent is merely
 *                    TODAY the lease is still running, the whole-lease caps
 *                    ('monthly_single_month' / 'monthly_short_flat') are not yet
 *                    knowable claims about its final total, and applying them
 *                    collapsed the lease into ONE growing segment — which the
 *                    first invoice then tagged 'billed' by any-overlap, setting
 *                    fully_billed and DISABLING Generate. An indefinite extent
 *                    therefore always fans out per calendar month. The new
 *                    `extent_definitive` payload field reports which case it is.
 *                (2) The 'void' branch now arms next_due_index, so voiding an
 *                    invoice actually re-opens its segment for regeneration.
 *              Neither touches the engine: the caps still set every AMOUNT via
 *              createFromLease's running reconciliation, and still set the
 *              segment COUNT once the lease has a real end.
 *
 * @method      GET
 * @query       id | lease_id (required)
 * @auth        Session required; require_permission('invoices','create')
 * @returns     200 { lease_id, contract_number, engine_version, extent,
 *                    extent_definitive, fully_billed, next_due_index,
 *                    months: [...] } | 404
 *
 * @depends     api/bootstrap.php, lib/Billing/HolisticLeaseEngine.php
 * @spec        FleetForge_Holistic_Billing_Engine_Spec_Revision2 §3.6, §9
 * @session     S-BILLING-INVOICE-DISPLAY-PICKER, S-PICKER-OPEN-LEASE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Billing\HolisticLeaseEngine;

/**
 * Compute the R2 §3.6 billable-months payload for a lease. Pure read; returns
 * null when the lease doesn't exist. Exposed as a function (with the
 * FF_BILLABLE_MONTHS_INCLUDE guard below) so the smoke can exercise the real
 * computation against the real schema without an HTTP session.
 *
 * @return array|null { lease_id, contract_number, engine_version, start_date,
 *                      extent, fully_billed, next_due_index, months[] }
 */
function ff_billable_months(int $leaseId): ?array
{
    $lease = db_row(
        "SELECT id, contract_number, start_date, end_date, actual_return_date,
                status, engine_version, billing_cycle,
                daily_rate, weekly_rate, monthly_rate
           FROM leases
          WHERE id = ? AND deleted_at IS NULL",
        [$leaseId]
    );
    if (!$lease) {
        return null;
    }

    $start = (string) $lease['start_date'];

    // Known extent E (R2 §6): actual return (closed) · end_date (expected) ·
    // else today. The picker never lists months past E (no open future).
    //
    // S-PICKER-OPEN-LEASE: $extentDefinitive records WHICH rung of that ladder we
    // landed on. The first two rungs are a real, knowable END of the lease; the
    // third ("today") is a moving horizon that advances every single day. The
    // whole-lease rate caps below are claims about the lease's FINAL total, so
    // they may only be applied to a definitive extent.
    $extent           = date('Y-m-d');
    $extentDefinitive = false;
    if (!empty($lease['actual_return_date'])) {
        $extent           = (string) $lease['actual_return_date'];
        $extentDefinitive = true;
    } elseif (!empty($lease['end_date'])) {
        $extent           = (string) $lease['end_date'];
        $extentDefinitive = true;
    }

    // Defensive: a lease whose start is past the extent yields no months.
    $months       = [];
    $nextDueIndex = null;

    if ($start !== '' && $extent >= $start) {
        // Mirror generateForLease's spanning decision (R2 §3.6 contract above):
        // present the SAME segments generation would write. Generation fans into
        // per-calendar-month segments ONLY for a holistic lease the engine
        // classifies as 'monthly_multi_month' (monthly tier spanning >1 calendar
        // month). EVERY other case writes ONE invoice for [start, extent] and so
        // must show ONE 'single_period' segment: non-holistic (legacy/mileage —
        // the holistic rate ladder doesn't even apply, and the picker UI hides
        // for it), sub-month daily/weekly cross-month, single-calendar-month
        // monthly, and the ≤1-month straddle flat cap ('monthly_short_flat').
        //
        // S-PICKER-OPEN-LEASE: that mirror only holds for a DEFINITIVE extent.
        // 'monthly_single_month' and 'monthly_short_flat' are WHOLE-LEASE caps —
        // "this lease, in total, bills one flat month". On an open-ended lease the
        // extent is TODAY, so asking the cap how many billing periods exist
        // collapses a still-running lease into one un-splittable mega-segment that
        // grows a day longer every day: a lease started Aug 26, viewed Sep 11,
        // rendered as a single row "August 2026 · Aug 26 → Sep 11 · 17 days". The
        // first invoice then tagged that whole row billed (any-overlap, below),
        // fully_billed went true and create.php disabled Generate — the operator
        // could not bill September at all until the lease passed its monthiversary.
        // So: walk calendar months whenever the extent is not definitive. This does
        // NOT weaken S-MONTHLY-SHORT-FLAT — the cap still sets the AMOUNT at
        // generation time via createFromLease's running reconciliation (each
        // invoice bills cumulative_correct − already_billed, so the lease total is
        // invariant under any in-order segmentation), and it still decides the
        // segment COUNT the moment the lease acquires a real end (close, or
        // end_date set). generateForLease carries the matching guard.
        $engine   = new \FleetForge\Billing\HolisticLeaseEngine();
        $spanning = false;
        if (($lease['engine_version'] ?? '') === 'holistic') {
            if (!$extentDefinitive) {
                $spanning = true;
            } else {
                $cls = $engine->cumulativeCorrect(
                    $start, $extent, $extent,
                    (string) $lease['daily_rate'],
                    (string) $lease['weekly_rate'],
                    (string) $lease['monthly_rate']
                );
                $spanning = ($cls['basis'] === 'monthly_multi_month');
            }
        }

        if ($spanning) {
            $segments = $engine->segmentsFor($start, $extent);
            // A span that lies inside ONE calendar month is still exactly the old
            // single-row case. Restoring 'single_period' here keeps the payload
            // byte-identical for every lease that has not yet crossed a month
            // boundary, so the change above is provably a no-op for them.
            if (count($segments) === 1) {
                $segments[0]['billing_type'] = 'single_period';
                $segments[0]['complete']     = false;
            }
        } else {
            // ONE invoice for the whole [start, extent] span — billing_type
            // 'single_period', exactly generateForLease's non-spanning path.
            // 'complete' is informational only; a cross-month/partial span is
            // never a clean full calendar month, so false.
            $segments = [[
                'period_start' => $start,
                'period_end'   => $extent,
                'billing_type' => 'single_period',
                'complete'     => false,
            ]];
        }

        // All non-deleted invoices, NON-VOID first so a live invoice always
        // wins over a void one for the same segment.
        $invoices = db_select(
            "SELECT id, invoice_number, status, billing_period_start, billing_period_end
               FROM invoices
              WHERE lease_id = ?
                AND deleted_at IS NULL
                AND billing_period_start IS NOT NULL
                AND billing_period_end   IS NOT NULL
              ORDER BY (status = 'void') ASC, billing_period_start ASC, id ASC",
            [$leaseId]
        );

        foreach ($segments as $idx => $seg) {
            $segStart = $seg['period_start'];
            $segEnd   = $seg['period_end'];

            $billed = null;
            $voided = null;
            foreach ($invoices as $inv) {
                // Closed-interval overlap (matches findOverlappingInvoice).
                if ($inv['billing_period_start'] <= $segEnd && $inv['billing_period_end'] >= $segStart) {
                    if ($inv['status'] === 'void') {
                        $voided ??= $inv;
                    } else {
                        $billed = $inv;
                        break;
                    }
                }
            }

            if ($billed !== null) {
                $status        = 'billed';
                $invoiceNumber = $billed['invoice_number'];
                $invoiceId     = (int) $billed['id'];
            } elseif ($voided !== null) {
                // S-PICKER-OPEN-LEASE: a segment whose only invoice is VOID is
                // RE-BILLABLE — findOverlappingInvoice() filters `status <> 'void'`
                // (InvoiceGenerator.php:3206), so generation over it is allowed.
                // This branch used to fall through WITHOUT arming $nextDueIndex,
                // so fully_billed stayed true and create.php kept Generate
                // disabled: the void-then-regenerate recovery the page advertises
                // in its own warning text was a dead end, stranding leases whose
                // only invoice had been voided. Arm it, in order, like 'unbilled'.
                $status        = 'void';
                $invoiceNumber = $voided['invoice_number'];
                $invoiceId     = (int) $voided['id'];
                if ($nextDueIndex === null) {
                    $nextDueIndex = $idx;
                }
            } else {
                $status        = 'unbilled';
                $invoiceNumber = null;
                $invoiceId     = null;
                if ($nextDueIndex === null) {
                    $nextDueIndex = $idx;
                }
            }

            $months[] = [
                'index'          => $idx,
                'label'          => date('F Y', strtotime($segStart)),
                'period_start'   => $segStart,
                'period_end'     => $segEnd,
                'days'           => \FleetForge\Billing\HolisticLeaseEngine::inclusiveDays($segStart, $segEnd),
                'billing_type'   => $seg['billing_type'],
                'complete'       => (bool) $seg['complete'],
                'is_final'       => ($idx === count($segments) - 1)
                                    && !empty($lease['actual_return_date'] ?: $lease['end_date']),
                'status'         => $status,
                'invoice_number' => $invoiceNumber,
                'invoice_id'     => $invoiceId,
            ];
        }
    }

    return [
        'lease_id'        => (int) $lease['id'],
        'contract_number' => $lease['contract_number'],
        'engine_version'  => $lease['engine_version'],
        'start_date'      => $start,
        'extent'          => $extent,
        // S-PICKER-OPEN-LEASE: false when $extent is merely "today" (lease still
        // running). create.php uses it to refuse to disable Generate on a lease
        // that has no known end — a picker bug must never hard-lock billing.
        'extent_definitive' => $extentDefinitive,
        'fully_billed'    => ($months !== [] && $nextDueIndex === null),
        'next_due_index'  => $nextDueIndex,
        'months'          => $months,
    ];
}

// Script wrapper — skipped when required for testing (FF_BILLABLE_MONTHS_INCLUDE).
if (!defined('FF_BILLABLE_MONTHS_INCLUDE')) {
    require_method('GET');
    require_auth_api();
    require_permission('invoices', 'create');

    $id = clean_int($_GET['id'] ?? $_GET['lease_id'] ?? null);
    if (!$id) {
        json_error('MISSING_REQUIRED', 'id is required.', 422);
    }

    $payload = ff_billable_months($id);
    if ($payload === null) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    json_success($payload);
}
