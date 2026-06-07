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
 *                - 'void'     → only a VOID invoice covers it (re-billable)
 *                - 'unbilled' → no invoice covers it yet
 *              The segment dates come straight from HolisticLeaseEngine::
 *              segmentsFor() so they match exactly what generation would write.
 *              `next_due_index` is the FIRST unbilled segment (in-order gate
 *              4.5 — only that month may be generated next); `fully_billed`
 *              is true when every segment is billed.
 *
 * @method      GET
 * @query       id | lease_id (required)
 * @auth        Session required; require_permission('invoices','create')
 * @returns     200 { lease_id, contract_number, engine_version, extent,
 *                    fully_billed, next_due_index, months: [...] } | 404
 *
 * @depends     api/bootstrap.php, lib/Billing/HolisticLeaseEngine.php
 * @spec        FleetForge_Holistic_Billing_Engine_Spec_Revision2 §3.6, §9
 * @session     S-BILLING-INVOICE-DISPLAY-PICKER
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
                status, engine_version, billing_cycle
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
    $extent = date('Y-m-d');
    if (!empty($lease['actual_return_date'])) {
        $extent = (string) $lease['actual_return_date'];
    } elseif (!empty($lease['end_date'])) {
        $extent = (string) $lease['end_date'];
    }

    // Defensive: a lease whose start is past the extent yields no months.
    $months       = [];
    $nextDueIndex = null;

    if ($start !== '' && $extent >= $start) {
        $segments = (new \FleetForge\Billing\HolisticLeaseEngine())->segmentsFor($start, $extent);

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
                $status        = 'void';
                $invoiceNumber = $voided['invoice_number'];
                $invoiceId     = (int) $voided['id'];
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
