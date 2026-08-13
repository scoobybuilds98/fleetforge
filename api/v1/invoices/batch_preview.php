<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_preview.php
 *
 * S-BATCH-INVOICING-2 — DRY RUN. Computes exactly what each selected lease
 * WOULD be billed for the period, without persisting anything.
 *
 * ── Why it really generates, then rolls back ─────────────────────────────
 * The obvious cheap alternative — reimplementing the math from
 * HolisticLeaseEngine::calculateForInvoice() (which is pure, no writes) —
 * only yields the BASE RENTAL line. It knows nothing about tax, discounts,
 * mileage estimate/true-up, GPS, hourly, insurance/warranty, or the
 * credit-overflow cap. A preview that quietly disagrees with the invoice
 * the operator then generates is worse than no preview at all in a billing
 * tool, so this runs the REAL generator inside a transaction and forces a
 * rollback, giving numbers that are exact by construction.
 *
 * Rollback is guaranteed by throwing: db_transaction() rolls back on any
 * Throwable and rethrows, so DryRunComplete (a private sentinel, never an
 * error) both aborts the write and carries the captured totals out. The
 * invoice-number counter lives in a `settings` row bumped INSIDE the same
 * transaction, so it rewinds too — a preview never burns invoice numbers
 * or leaves a gap.
 *
 * Each lease gets its OWN transaction so one bad lease can't poison the
 * others' previews, matching batch_generate.php's per-lease isolation.
 *
 * @method  POST
 * @body    { period_start, period_end, lease_ids: [int,...] }  (max 200)
 * @auth    Session required; require_permission('invoices','create')
 *          — 'create' not 'view': this exercises the real generator, so it
 *          should be gated like generation even though nothing persists.
 * @returns 200 { period, previews: [{lease_id, ok, total_amount, subtotal,
 *                tax_total, currency, rate_method, billing_days, error?}],
 *                totals: {count, ok_count, error_count, by_currency: {CAD: "…"}} }
 *
 * @depends lib/Billing/InvoiceGenerator.php
 * @session S-BATCH-INVOICING-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;

/** Sentinel used only to force a rollback out of db_transaction(). Not an error. */
final class FF_DryRunComplete extends \RuntimeException
{
    public array $snapshot;
    public function __construct(array $snapshot)
    {
        parent::__construct('dry-run complete');
        $this->snapshot = $snapshot;
    }
}

$body = json_body();

$periodStart = clean_date($body['period_start'] ?? null);
$periodEnd   = clean_date($body['period_end'] ?? null);

$fields = [];
if (!$periodStart) $fields['period_start'] = 'A valid period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'A valid period end date is required.';
if ($periodStart && $periodEnd && $periodEnd < $periodStart) {
    $fields['period_end'] = 'Period end cannot be before period start.';
}
if ($periodStart && $periodEnd && !isset($fields['period_end'])) {
    if ($periodErr = ff_billing_period_error($periodStart, $periodEnd)) {
        $fields['period_start'] = $periodErr;
    }
}

$rawIds = $body['lease_ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    $fields['lease_ids'] = 'Select at least one lease to preview.';
} elseif (count($rawIds) > 200) {
    $fields['lease_ids'] = 'A maximum of 200 leases can be previewed at once.';
}
if ($fields) {
    json_validation_error($fields);
}

$leaseIds = [];
foreach ($rawIds as $raw) {
    $id = clean_int($raw);
    if ($id && $id > 0) $leaseIds[] = $id;
}
$leaseIds = array_values(array_unique($leaseIds));
if (!$leaseIds) {
    json_validation_error(['lease_ids' => 'No valid lease IDs were submitted.']);
}

$isFullCalendarMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                    && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));
$billingType = $isFullCalendarMonth ? 'full_month' : 'single_period';

$generator = new InvoiceGenerator();
$userId    = current_user_id();

$previews   = [];
$byCurrency = [];
$okCount    = 0;
$errCount   = 0;

foreach ($leaseIds as $leaseId) {
    // Pull the full commercial terms too — the preview is a manager's
    // check-everything surface, so it shows the RATES and usage inputs the
    // engine billed from, not just the resulting number.
    $lease = db_row(
        "SELECT l.id, l.contract_number, l.customer_id, l.status, l.billing_cycle,
                l.start_date, l.end_date, l.actual_return_date,
                l.daily_rate, l.weekly_rate, l.monthly_rate, l.hourly_rate,
                l.currency, l.mileage_unit, l.mileage_rate, l.mileage_rate_km, l.mileage_rate_miles,
                l.estimated_mileage_per_day, l.estimated_engine_hours_per_day,
                l.mileage_tracking_mode, l.odometer_start_km,
                l.minimum_billing_days, l.billing_days_removed,
                l.insurance_opt_in, l.insurance_cost,
                l.warranty_opt_in, l.warranty_cost,
                l.gps_opt_in, l.gps_cost,
                l.discount_type, l.discount_value, l.po_number,
                c.company_name,
                eu.unit_number, eu.samsara_odometer_km
           FROM leases l
           JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
           LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
          WHERE l.id = ? AND l.deleted_at IS NULL",
        [$leaseId]
    );
    if (!$lease) {
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'error' => 'Lease not found or deleted.'];
        $errCount++;
        continue;
    }
    if ($lease['status'] !== 'active') {
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'contract_number' => $lease['contract_number'],
                       'company_name' => $lease['company_name'], 'error' => "Lease is '{$lease['status']}', not active."];
        $errCount++;
        continue;
    }

    $existing = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
    if ($existing) {
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'contract_number' => $lease['contract_number'],
                       'company_name' => $lease['company_name'],
                       'error' => "Already covered by invoice {$existing['invoice_number']} ({$existing['status']})."];
        $errCount++;
        continue;
    }

    try {
        db_transaction(function () use (
            $generator, $leaseId, $periodStart, $periodEnd, $billingType, $userId
        ): void {
            $res = $generator->createFromLease([
                'lease_id'             => $leaseId,
                'period_start'         => $periodStart,
                'period_end'           => $periodEnd,
                'billing_type'         => $billingType,
                'invoice_type'         => 'regular',
                'generation_source'    => 'manual',
                'auto_generated'       => 0,
                'created_by'           => $userId,
                'require_lease_status' => 'active',
            ]);

            $inv = db_row(
                "SELECT subtotal, discount_amount, subtotal_after_discount,
                        tax_gst_rate, tax_gst_amount, tax_pst_rate, tax_pst_amount,
                        tax_hst_rate, tax_hst_amount, tax_total,
                        total_amount, currency, exchange_rate_to_cad,
                        rate_method_used, rate_method_explanation,
                        billing_period_start, billing_period_end, billing_period_days,
                        billing_type, invoice_date, due_date,
                        odometer_at_period_start_km, odometer_at_period_end_km,
                        engine_hours_at_period_start, engine_hours_at_period_end,
                        total_days_at_period_end
                   FROM invoices WHERE id = ?",
                [(int) $res['invoice_id']]
            );
            $lines = db_select(
                "SELECT item_type, description, detail_lines, quantity, unit, unit_price,
                        amount, is_credit, taxable, billing_days, rate_method,
                        period_start, period_end,
                        mileage_distance, mileage_unit, mileage_rate,
                        mileage_estimated, mileage_actual
                   FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
                [(int) $res['invoice_id']]
            );

            // Throwing is what rolls the whole thing back — see docblock.
            throw new FF_DryRunComplete(['invoice' => $inv, 'lines' => $lines]);
        });

        // Unreachable: the closure always throws.
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'error' => 'Preview did not roll back as expected.'];
        $errCount++;

    } catch (FF_DryRunComplete $done) {
        $inv = $done->snapshot['invoice'];
        $cur = (string) ($inv['currency'] ?? 'CAD');
        $byCurrency[$cur] = bcadd($byCurrency[$cur] ?? '0', (string) $inv['total_amount'], 2);
        $okCount++;

        $previews[] = [
            'lease_id'        => $leaseId,
            'ok'              => true,
            'contract_number' => $lease['contract_number'],
            'company_name'    => $lease['company_name'],
            'customer_id'     => (int) $lease['customer_id'],
            'unit_number'     => $lease['unit_number'],
            'po_number'       => $lease['po_number'],

            // ── What the engine produced ────────────────────────────
            'subtotal'                 => (string) $inv['subtotal'],
            'discount_amount'          => (string) $inv['discount_amount'],
            'subtotal_after_discount'  => (string) $inv['subtotal_after_discount'],
            'tax_gst_rate'             => (string) $inv['tax_gst_rate'],
            'tax_gst_amount'           => (string) $inv['tax_gst_amount'],
            'tax_pst_rate'             => (string) $inv['tax_pst_rate'],
            'tax_pst_amount'           => (string) $inv['tax_pst_amount'],
            'tax_hst_rate'             => (string) $inv['tax_hst_rate'],
            'tax_hst_amount'           => (string) $inv['tax_hst_amount'],
            'tax_total'                => (string) $inv['tax_total'],
            'total_amount'             => (string) $inv['total_amount'],
            'currency'                 => $cur,
            'exchange_rate_to_cad'     => $inv['exchange_rate_to_cad'],
            'rate_method'              => (string) ($inv['rate_method_used'] ?? ''),
            'rate_method_explanation'  => $inv['rate_method_explanation'],
            'billing_type'             => (string) $inv['billing_type'],
            'billing_days'             => (int) $inv['billing_period_days'],
            'period_start'             => (string) $inv['billing_period_start'],
            'period_end'               => (string) $inv['billing_period_end'],
            'invoice_date'             => (string) $inv['invoice_date'],
            'due_date'                 => (string) $inv['due_date'],
            'total_days_at_period_end' => $inv['total_days_at_period_end'],

            // ── The inputs it billed FROM (what a manager checks) ───
            'terms' => [
                'lease_start'        => $lease['start_date'],
                'lease_end'          => $lease['end_date'],
                'actual_return_date' => $lease['actual_return_date'],
                'daily_rate'         => (string) $lease['daily_rate'],
                'weekly_rate'        => (string) $lease['weekly_rate'],
                'monthly_rate'       => (string) $lease['monthly_rate'],
                'hourly_rate'        => $lease['hourly_rate'],
                'mileage_unit'       => $lease['mileage_unit'],
                'mileage_rate'       => (string) $lease['mileage_rate'],
                'mileage_rate_km'    => $lease['mileage_rate_km'],
                'mileage_rate_miles' => $lease['mileage_rate_miles'],
                'estimated_mileage_per_day'      => (string) $lease['estimated_mileage_per_day'],
                'estimated_engine_hours_per_day' => (string) $lease['estimated_engine_hours_per_day'],
                'mileage_tracking_mode'          => $lease['mileage_tracking_mode'],
                'odometer_start_km'              => $lease['odometer_start_km'],
                'unit_odometer_now_km'           => $lease['samsara_odometer_km'],
                'minimum_billing_days'           => $lease['minimum_billing_days'],
                'billing_days_removed'           => (int) $lease['billing_days_removed'],
                'insurance'  => ((int) $lease['insurance_opt_in'] === 1) ? (string) $lease['insurance_cost'] : null,
                'warranty'   => ((int) $lease['warranty_opt_in'] === 1) ? (string) $lease['warranty_cost'] : null,
                'gps'        => ((int) $lease['gps_opt_in'] === 1) ? (string) $lease['gps_cost'] : null,
                'discount_type'  => $lease['discount_type'],
                'discount_value' => (string) $lease['discount_value'],
            ],

            // ── Usage snapshot the invoice captured ────────────────
            'usage' => [
                'odometer_start' => $inv['odometer_at_period_start_km'],
                'odometer_end'   => $inv['odometer_at_period_end_km'],
                'hours_start'    => $inv['engine_hours_at_period_start'],
                'hours_end'      => $inv['engine_hours_at_period_end'],
            ],

            'lines' => array_map(static fn ($l) => [
                'item_type'    => $l['item_type'],
                'description'  => $l['description'],
                'quantity'     => (string) $l['quantity'],
                'unit'         => $l['unit'],
                'unit_price'   => (string) $l['unit_price'],
                'amount'       => (string) $l['amount'],
                'is_credit'    => (int) $l['is_credit'],
                'taxable'      => (int) $l['taxable'],
                'billing_days' => $l['billing_days'],
                'rate_method'  => $l['rate_method'],
                'period_start' => $l['period_start'],
                'period_end'   => $l['period_end'],
                'mileage_distance'  => $l['mileage_distance'],
                'mileage_unit'      => $l['mileage_unit'],
                'mileage_rate'      => $l['mileage_rate'],
                'mileage_estimated' => $l['mileage_estimated'],
                'mileage_actual'    => $l['mileage_actual'],
            ], $done->snapshot['lines']),
        ];

    } catch (BillingRateException $e) {
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'contract_number' => $lease['contract_number'],
                       'company_name' => $lease['company_name'],
                       'error' => 'No billable rate configured: ' . $e->getMessage()];
        $errCount++;
    } catch (\Throwable $e) {
        $previews[] = ['lease_id' => $leaseId, 'ok' => false, 'contract_number' => $lease['contract_number'],
                       'company_name' => $lease['company_name'], 'error' => $e->getMessage()];
        $errCount++;
        error_log("[batch_preview] Lease #{$leaseId}: " . $e->getMessage());
    }
}

json_success([
    'period'   => ['start' => $periodStart, 'end' => $periodEnd, 'is_full_calendar_month' => $isFullCalendarMonth],
    'previews' => $previews,
    'totals'   => [
        'count'       => count($leaseIds),
        'ok_count'    => $okCount,
        'error_count' => $errCount,
        'by_currency' => $byCurrency,
    ],
]);
