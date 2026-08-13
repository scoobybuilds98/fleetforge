<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * lib/Billing/BatchPreviewService.php
 *
 * S-BATCH-APPROVAL — the single implementation of the batch DRY RUN.
 *
 * Computes exactly what each lease WOULD be billed for a period without
 * persisting anything, by running the REAL generator inside a transaction
 * and forcing a rollback. Reimplementing the math from
 * HolisticLeaseEngine (which is pure and write-free) would only yield the
 * base-rental line — no tax, discount, mileage estimate/true-up, GPS,
 * hourly, insurance/warranty, or credit-overflow cap — so a preview built
 * that way would quietly disagree with the invoice actually generated.
 *
 * Rollback is guaranteed by throwing: db_transaction() rolls back on any
 * Throwable and rethrows, so DryRunComplete (a sentinel, never an error)
 * both aborts the write and carries the captured snapshot out. The
 * invoice-number counter is a `settings` row bumped INSIDE the same
 * transaction, so it rewinds too — a preview never burns invoice numbers.
 *
 * Each lease gets its OWN transaction so one bad lease cannot poison the
 * others, matching batch_generate.php's per-lease isolation.
 *
 * WHY a service and not endpoint-local code: two callers need byte-identical
 * numbers — api/v1/invoices/batch_preview.php (the live preview) and
 * api/v1/invoices/batch_runs/create.php (which FREEZES this output as the
 * approval snapshot a manager signs off). If those ever drifted, a manager
 * would approve figures the generator would not reproduce.
 *
 * @session S-BATCH-APPROVAL
 */
final class BatchPreviewService
{
    private function __construct() {}

    /**
     * Run the dry run over a set of leases.
     *
     * @param int[]  $leaseIds
     * @param string $periodStart Y-m-d
     * @param string $periodEnd   Y-m-d
     * @param ?int   $userId      stamped as created_by on the rolled-back invoice
     * @return array{
     *   period: array{start:string,end:string,is_full_calendar_month:bool},
     *   previews: array<int,array<string,mixed>>,
     *   totals: array{count:int,ok_count:int,error_count:int,by_currency:array<string,string>}
     * }
     */
    public static function run(array $leaseIds, string $periodStart, string $periodEnd, ?int $userId): array
    {
        $isFullCalendarMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                            && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));
        $billingType = $isFullCalendarMonth ? 'full_month' : 'single_period';

        $generator  = new InvoiceGenerator();
        $previews   = [];
        $byCurrency = [];
        $okCount    = 0;
        $errCount   = 0;

        foreach ($leaseIds as $leaseId) {
            $leaseId = (int) $leaseId;

            // Full commercial terms too — the review surface shows the RATES
            // and usage inputs the engine billed FROM, not just the result.
            $lease = \db_row(
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
                $previews[] = self::errRow($leaseId, $lease, "Lease is '{$lease['status']}', not active.");
                $errCount++;
                continue;
            }

            $existing = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
            if ($existing) {
                $previews[] = self::errRow($leaseId, $lease,
                    "Already covered by invoice {$existing['invoice_number']} ({$existing['status']}).");
                $errCount++;
                continue;
            }

            try {
                \db_transaction(static function () use (
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

                    $inv = \db_row(
                        "SELECT subtotal, discount_amount, subtotal_after_discount,
                                tax_gst_rate, tax_gst_amount, tax_pst_rate, tax_pst_amount,
                                tax_hst_rate, tax_hst_amount, tax_total,
                                total_amount, currency, exchange_rate_to_cad,
                                rate_method_used, billing_period_start, billing_period_end,
                                billing_period_days, billing_type, invoice_date, due_date,
                                odometer_at_period_start_km, odometer_at_period_end_km,
                                engine_hours_at_period_start, engine_hours_at_period_end,
                                total_days_at_period_end
                           FROM invoices WHERE id = ?",
                        [(int) $res['invoice_id']]
                    );
                    $lines = \db_select(
                        "SELECT item_type, description, quantity, unit, unit_price,
                                amount, is_credit, taxable, billing_days, rate_method,
                                period_start, period_end,
                                mileage_distance, mileage_unit, mileage_rate,
                                mileage_estimated, mileage_actual
                           FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
                        [(int) $res['invoice_id']]
                    );

                    // Throwing is what rolls the whole thing back.
                    throw new DryRunComplete(['invoice' => $inv, 'lines' => $lines]);
                });

                $previews[] = self::errRow($leaseId, $lease, 'Preview did not roll back as expected.');
                $errCount++;

            } catch (DryRunComplete $done) {
                $inv = $done->snapshot['invoice'];
                $cur = (string) ($inv['currency'] ?? 'CAD');
                $byCurrency[$cur] = bcadd($byCurrency[$cur] ?? '0', (string) $inv['total_amount'], 2);
                $okCount++;
                $previews[] = self::okRow($leaseId, $lease, $inv, $done->snapshot['lines']);

            } catch (BillingRateException $e) {
                $previews[] = self::errRow($leaseId, $lease, 'No billable rate configured: ' . $e->getMessage());
                $errCount++;
            } catch (\Throwable $e) {
                $previews[] = self::errRow($leaseId, $lease, $e->getMessage());
                $errCount++;
                \error_log("[BatchPreviewService] Lease #{$leaseId}: " . $e->getMessage());
            }
        }

        return [
            'period'   => ['start' => $periodStart, 'end' => $periodEnd, 'is_full_calendar_month' => $isFullCalendarMonth],
            'previews' => $previews,
            'totals'   => [
                'count'       => count($leaseIds),
                'ok_count'    => $okCount,
                'error_count' => $errCount,
                'by_currency' => $byCurrency,
            ],
        ];
    }

    /** @param array<string,mixed> $lease */
    private static function errRow(int $leaseId, array $lease, string $error): array
    {
        return [
            'lease_id'        => $leaseId,
            'ok'              => false,
            'contract_number' => $lease['contract_number'] ?? null,
            'company_name'    => $lease['company_name'] ?? null,
            'error'           => $error,
        ];
    }

    /**
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $inv
     * @param array<int,array<string,mixed>> $lines
     */
    private static function okRow(int $leaseId, array $lease, array $inv, array $lines): array
    {
        return [
            'lease_id'        => $leaseId,
            'ok'              => true,
            'contract_number' => $lease['contract_number'],
            'company_name'    => $lease['company_name'],
            'customer_id'     => (int) $lease['customer_id'],
            'unit_number'     => $lease['unit_number'],
            'po_number'       => $lease['po_number'],

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
            'currency'                 => (string) $inv['currency'],
            'exchange_rate_to_cad'     => $inv['exchange_rate_to_cad'],
            'rate_method'              => (string) ($inv['rate_method_used'] ?? ''),
            'billing_type'             => (string) $inv['billing_type'],
            'billing_days'             => (int) $inv['billing_period_days'],
            'period_start'             => (string) $inv['billing_period_start'],
            'period_end'               => (string) $inv['billing_period_end'],
            'invoice_date'             => (string) $inv['invoice_date'],
            'due_date'                 => (string) $inv['due_date'],
            'total_days_at_period_end' => $inv['total_days_at_period_end'],

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
            ], $lines),
        ];
    }
}

/**
 * Sentinel used ONLY to force a rollback out of db_transaction(). Never an
 * error — carries the captured snapshot out of the doomed transaction.
 */
final class DryRunComplete extends \RuntimeException
{
    public array $snapshot;
    public function __construct(array $snapshot)
    {
        parent::__construct('dry-run complete');
        $this->snapshot = $snapshot;
    }
}
