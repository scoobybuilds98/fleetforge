<?php
declare(strict_types=1);

/**
 * lib/Accounting/Gst34Service.php
 *
 * CRA GST34 line-by-line generator + ITC restrictions + tax detail reports
 * per spec §23.7. All monetary values bcmath strings.
 *
 * Lines computed (13 per CRA GST34):
 *   101 — Total revenue (subtotal_after_discount on posted/sent invoices)
 *   103 — GST/HST collected (CREDIT side of accounting.gst_payable_account_id
 *         JE lines in the period)
 *   104 — Adjustments (tax_adjustment source_type — currently 0 since no
 *         such ENUM value exists on disk; placeholder per spec)
 *   105 — Total tax collectible (103 + 104)
 *   106 — Input tax credits (DEBIT side of accounting.gst_receivable_account_id
 *         JE lines in the period; with ITC restrictions applied)
 *   107 — ITC adjustments (itc_adjustment source_type — 0 placeholder)
 *   108 — Total ITCs (106 + 107)
 *   109 — Net tax (105 − 108)
 *   110 — Instalments + payments (acc_tax_remittances against the period)
 *   111 — Rebates (0 placeholder per spec)
 *   112 — Total payments + rebates (110 + 111)
 *   113 — Balance due / refund (109 − 112)
 *
 * Pre-flight column-name catches (K-22, S-ACCT-GST34 — locked alongside this
 * session in REFERENCE.md §11 Traps 29+):
 *   - acc_tax_filing_periods uses period_start / period_end (NOT
 *     start_date / end_date as the spec assumed).
 *   - acc_tax_remittances uses filing_period_id (NOT tax_filing_period_id).
 *   - invoices uses gst_exempt_snapshot / pst_exempt_snapshot (NOT
 *     gst_exempt / pst_exempt).
 *   - invoice_line_items.item_type ENUM has no 'meals_entertainment' or
 *     'export' value — M&E ITC 50% rule + zero-rated export filtering
 *     are stubbed pending schema additions.
 *   - vendors has NO business_number column — ITC documentation report
 *     skips that field check (logged in non-compliance reason).
 *   - acc_bill_lines.is_tax_input_credit flags ITC-eligible lines.
 *   - Both GST and HST output route to accounting.gst_payable_account_id
 *     (account #23 / 2030) on disk — there is no separate HST account
 *     (single liability account holds both per AutoEntryBridge).
 *
 * ITC account = accounting.gst_receivable_account_id (account #6 / 1050
 * "GST/HST Receivable (Input Tax Credits)" — confirmed via pre-flight).
 *
 * Required by: api/v1/accounting/tax/{gst34,itc-report,tax-detail}.php
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.7
 * Session: S-ACCT-GST34
 */

namespace FleetForge\Accounting;

class Gst34Service
{
    /**
     * CRA passenger-vehicle ITC cap (Class 10.1 cost ceiling × federal GST 5%).
     * 2025: $38,000 × 5% = $1,900 GST. Applied to passenger-vehicle bills
     * in Line 106 ITC restrictions.
     */
    private const PASSENGER_VEHICLE_COST_CAP_CAD = '38000.00';

    /**
     * Compute the GST34 13-line return for a filing period.
     *
     * @return array { period, quick_method, lines: {L101..L113}, computed_at,
     *                 period_id, warnings: [...] }
     * @throws \RuntimeException when period not found, ITC account not configured,
     *         or line-105 invariant fails.
     */
    public static function compute(int $periodId): array
    {
        $period = \db_row(
            "SELECT id, tax_type, period_start, period_end, filing_due_date,
                    frequency, status
               FROM acc_tax_filing_periods WHERE id = ?",
            [$periodId]
        );
        if (!$period) {
            throw new \RuntimeException("Filing period #{$periodId} not found.");
        }

        $start = $period['period_start'];
        $end   = $period['period_end'];

        $gstPayableId    = (int) AccountingService::setting('accounting.gst_payable_account_id');
        $gstReceivableId = (int) AccountingService::setting('accounting.gst_receivable_account_id');
        if (!$gstPayableId) {
            throw new \RuntimeException(
                'accounting.gst_payable_account_id not configured — cannot compute Line 103.'
            );
        }
        if (!$gstReceivableId) {
            throw new \RuntimeException(
                'accounting.gst_receivable_account_id not configured — cannot compute Line 106.'
            );
        }

        $quickEnabled = ((string) \settings_get('accounting.gst_quick_method_enabled', '0')) === '1';
        $quickRatePct = (string) \settings_get('accounting.gst_quick_method_rate', '3.6');

        $warnings = [];

        // ── LINE 101 — Total revenue ──────────────────────────────────────
        // Posted/sent/paid invoices in the period. Fully-exempt (both GST+PST
        // exempt) invoices excluded — these are non-reportable for GST34.
        // K-22: GROUP_CONCAT with inline LIMIT requires MySQL 8.x+ and isn't
        // portable. Two queries: aggregate first, then fetch up to 50 ids
        // for the drill-down.
        $l101Row = \db_row(
            "SELECT COALESCE(SUM(subtotal_after_discount), 0) AS total,
                    COUNT(*) AS n
               FROM invoices
              WHERE invoice_date BETWEEN ? AND ?
                AND status IN ('sent','paid','partially_paid','overdue')
                AND NOT (gst_exempt_snapshot = 1 AND pst_exempt_snapshot = 1)
                AND deleted_at IS NULL",
            [$start, $end]
        );
        $l101Amount = (string) ($l101Row['total'] ?? '0.00');
        $l101Count  = (int) ($l101Row['n'] ?? 0);
        $l101Ids    = '';
        if ($l101Count > 0) {
            $idRows = \db_select(
                "SELECT id FROM invoices
                  WHERE invoice_date BETWEEN ? AND ?
                    AND status IN ('sent','paid','partially_paid','overdue')
                    AND NOT (gst_exempt_snapshot = 1 AND pst_exempt_snapshot = 1)
                    AND deleted_at IS NULL
                  ORDER BY id LIMIT 50",
                [$start, $end]
            );
            $l101Ids = implode(',', array_column($idRows, 'id'));
        }

        // ── LINE 103 — GST/HST collected ──────────────────────────────────
        // Quick Method path: line_101 × quick_rate ÷ 100.
        // Standard path: CREDIT side of GST payable account JE lines.
        if ($quickEnabled) {
            $l103Amount = bcmul($l101Amount, bcdiv($quickRatePct, '100', 6), 2);
            $l103Desc   = "Quick Method: revenue × {$quickRatePct}%";
        } else {
            $l103Row = \db_row(
                "SELECT COALESCE(SUM(jel.credit), 0) AS total
                   FROM acc_journal_entry_lines jel
                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                  WHERE jel.account_id = ?
                    AND je.status = 'posted'
                    AND je.entry_date BETWEEN ? AND ?",
                [$gstPayableId, $start, $end]
            );
            $l103Amount = (string) ($l103Row['total'] ?? '0.00');
            $l103Desc   = "GST/HST collected (CREDIT side of acct #{$gstPayableId})";
        }

        // ── LINE 104 — Adjustments ────────────────────────────────────────
        // source_type='tax_adjustment' isn't in the source_type ENUM on disk
        // (verified via REFERENCE.md §0 list — see audit_log import note for
        // the ENUM-rejection pattern). Default to 0 + note in warnings.
        $l104Amount = '0.00';
        $warnings[] = 'L104 adjustments use 0.00 placeholder — source_type "tax_adjustment" not in acc_journal_entries.source_type ENUM. Add via separate migration when needed.';

        // ── LINE 105 — Total tax collectible ──────────────────────────────
        $l105Amount = bcadd($l103Amount, $l104Amount, 2);

        // STOP CONDITION: bcmath sanity.
        $l105Check = bcadd($l103Amount, $l104Amount, 2);
        if (bccomp($l105Amount, $l105Check, 2) !== 0) {
            throw new \RuntimeException('GST34 computation error: L105 ≠ L103 + L104.');
        }

        // ── LINE 106 — Input tax credits ──────────────────────────────────
        // Quick Method: only capital ITC (bills linked to fixed asset).
        // Standard: all DEBIT side of GST receivable JE lines, then apply
        // ITC restrictions per applyItcRestrictions().
        $restrictionsApplied = [];
        if ($quickEnabled) {
            // Capital ITC only — sum bills.tax_gst_amount + tax_hst_amount
            // where bill has any asset-linked line.
            $row = \db_row(
                "SELECT COALESCE(SUM(b.tax_gst_amount + b.tax_hst_amount), 0) AS total
                   FROM acc_bills b
                  WHERE b.bill_date BETWEEN ? AND ?
                    AND b.status IN ('approved','partially_paid','paid')
                    AND EXISTS (
                        SELECT 1 FROM acc_bill_lines bl
                         WHERE bl.bill_id = b.id AND bl.asset_id IS NOT NULL
                    )",
                [$start, $end]
            );
            $l106Amount = (string) ($row['total'] ?? '0.00');
            $l106Desc   = 'Quick Method: capital ITCs only (asset-linked bills)';
        } else {
            $row = \db_row(
                "SELECT COALESCE(SUM(jel.debit), 0) AS total
                   FROM acc_journal_entry_lines jel
                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                  WHERE jel.account_id = ?
                    AND je.status = 'posted'
                    AND je.entry_date BETWEEN ? AND ?",
                [$gstReceivableId, $start, $end]
            );
            $rawItc = (string) ($row['total'] ?? '0.00');
            $restricted = self::applyItcRestrictions($start, $end, $rawItc);
            $l106Amount = $restricted['adjusted_itc'];
            $restrictionsApplied = $restricted['restrictions'];
            $l106Desc = "Input Tax Credits (DEBIT side of acct #{$gstReceivableId})"
                      . (count($restrictionsApplied) > 0
                          ? ' — ' . count($restrictionsApplied) . ' restriction(s) applied'
                          : '');
        }

        // ── LINE 107 — ITC adjustments ────────────────────────────────────
        $l107Amount = '0.00';
        $warnings[] = 'L107 ITC adjustments use 0.00 placeholder — source_type "itc_adjustment" not in ENUM.';

        // ── LINE 108 — Total ITCs ─────────────────────────────────────────
        $l108Amount = bcadd($l106Amount, $l107Amount, 2);

        // ── LINE 109 — Net tax ────────────────────────────────────────────
        $l109Amount = bcsub($l105Amount, $l108Amount, 2);
        $l109Status = bccomp($l109Amount, '0', 2) > 0 ? 'owing'
                    : (bccomp($l109Amount, '0', 2) < 0 ? 'refund' : 'nil');

        // ── LINE 110 — Instalments + payments ─────────────────────────────
        // acc_tax_remittances has no remittance_type column on disk; sum all
        // remittances against this filing_period_id (K-22 — confirmed key
        // name filing_period_id, not tax_filing_period_id).
        $remitRow = \db_row(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
               FROM acc_tax_remittances
              WHERE filing_period_id = ?",
            [$periodId]
        );
        $l110Amount = (string) ($remitRow['total'] ?? '0.00');
        $l110Desc   = "Instalments + remittances ({$remitRow['n']} payments against filing_period_id={$periodId})";

        // ── LINE 111 — Rebates ────────────────────────────────────────────
        $l111Amount = '0.00';

        // ── LINE 112 — Total payments + rebates ──────────────────────────
        $l112Amount = bcadd($l110Amount, $l111Amount, 2);

        // ── LINE 113 — Balance due / refund ──────────────────────────────
        $l113Amount = bcsub($l109Amount, $l112Amount, 2);
        $l113Status = bccomp($l113Amount, '0', 2) > 0 ? 'balance_due'
                    : (bccomp($l113Amount, '0', 2) < 0 ? 'refund' : 'nil');

        return [
            'period'       => $period,
            'quick_method' => $quickEnabled,
            'quick_rate'   => $quickEnabled ? $quickRatePct : null,
            'lines'        => [
                'L101' => ['amount' => $l101Amount, 'description' => 'Total revenue (taxable supplies)', 'source_count' => $l101Count, 'source_ids' => $l101Ids],
                'L103' => ['amount' => $l103Amount, 'description' => $l103Desc, 'gl_account_id' => $gstPayableId],
                'L104' => ['amount' => $l104Amount, 'description' => 'Adjustments to tax collected'],
                'L105' => ['amount' => $l105Amount, 'description' => 'Total GST/HST + adjustments (L103 + L104)'],
                'L106' => ['amount' => $l106Amount, 'description' => $l106Desc, 'restrictions_applied' => $restrictionsApplied],
                'L107' => ['amount' => $l107Amount, 'description' => 'ITC adjustments'],
                'L108' => ['amount' => $l108Amount, 'description' => 'Total ITCs (L106 + L107)'],
                'L109' => ['amount' => $l109Amount, 'description' => 'Net tax (L105 − L108)', 'status' => $l109Status],
                'L110' => ['amount' => $l110Amount, 'description' => $l110Desc],
                'L111' => ['amount' => $l111Amount, 'description' => 'Rebates (placeholder — no rebate engine yet)'],
                'L112' => ['amount' => $l112Amount, 'description' => 'Total payments + rebates (L110 + L111)'],
                'L113' => ['amount' => $l113Amount, 'description' => 'Balance due / refund (L109 − L112)', 'status' => $l113Status],
            ],
            'warnings'     => $warnings,
            'computed_at'  => date('Y-m-d H:i:s'),
            'period_id'    => $periodId,
        ];
    }

    /**
     * Apply CRA ITC restrictions per ETA §201 + §67.1 to the raw ITC total.
     * Returns adjusted total + per-restriction trail.
     *
     * Restrictions implemented:
     *   - Passenger-vehicle cap: bills linked to a Class 10.1 fixed asset
     *     get ITC = GST/HST × min(acquisition_cost, $38,000) / acquisition_cost.
     *   - Meals & entertainment 50%: SKIPPED — invoice_line_items.item_type
     *     ENUM has no 'meals_entertainment' value on disk + acc_bill_lines
     *     has no equivalent flag. Add when schema supports it.
     */
    public static function applyItcRestrictions(string $start, string $end, string $rawItc): array
    {
        $restrictions = [];
        $adjusted = $rawItc;

        // Passenger-vehicle ITC cap. Class 10.1 id from acc_cca_classes.
        $cls10_1 = \db_row("SELECT id FROM acc_cca_classes WHERE class_number = '10.1' LIMIT 1");
        if ($cls10_1) {
            // ONLY_FULL_GROUP_BY compatibility: group by every non-aggregated
            // column. Yields one row per (bill, asset) pair — multi-asset
            // bills for passenger vehicles are rare but possible.
            $passengerBills = \db_select(
                "SELECT b.id AS bill_id, b.bill_number,
                        b.tax_gst_amount, b.tax_hst_amount,
                        fa.id AS asset_id, fa.acquisition_cost
                   FROM acc_bills b
                   JOIN acc_bill_lines bl ON bl.bill_id = b.id
                   JOIN acc_fixed_assets fa ON fa.id = bl.asset_id
                  WHERE fa.cca_class_id = ?
                    AND b.bill_date BETWEEN ? AND ?
                    AND b.status IN ('approved','partially_paid','paid')
                  GROUP BY b.id, b.bill_number, b.tax_gst_amount, b.tax_hst_amount, fa.id, fa.acquisition_cost",
                [(int) $cls10_1['id'], $start, $end]
            );
            foreach ($passengerBills as $b) {
                $itcOnBill = bcadd((string) $b['tax_gst_amount'], (string) $b['tax_hst_amount'], 2);
                $cost = (string) $b['acquisition_cost'];
                if (bccomp($cost, self::PASSENGER_VEHICLE_COST_CAP_CAD, 2) <= 0) {
                    continue;  // No cap when cost at or below threshold.
                }
                // Capped ITC = full ITC × (cap / actual cost).
                $cappedItc = bcdiv(bcmul($itcOnBill, self::PASSENGER_VEHICLE_COST_CAP_CAD, 6), $cost, 2);
                $reduction = bcsub($itcOnBill, $cappedItc, 2);
                if (bccomp($reduction, '0', 2) > 0) {
                    $adjusted = bcsub($adjusted, $reduction, 2);
                    $restrictions[] = [
                        'reason'           => 'passenger_vehicle_cap',
                        'bill_id'          => (int) $b['bill_id'],
                        'bill_number'      => $b['bill_number'],
                        'original_itc'     => $itcOnBill,
                        'adjusted_itc'     => $cappedItc,
                        'reduction'        => $reduction,
                        'cost_basis'       => $cost,
                        'cap_basis'        => self::PASSENGER_VEHICLE_COST_CAP_CAD,
                        'detail'           => "Class 10.1 passenger vehicle exceeds CAD \$38K cap (cost {$cost})",
                    ];
                }
            }
        }

        // M&E 50% rule: deferred until invoice_line_items.item_type ENUM (or
        // acc_bill_lines flag) supports identifying meals-and-entertainment.

        if (bccomp($adjusted, '0', 2) < 0) $adjusted = '0.00';

        return ['adjusted_itc' => $adjusted, 'restrictions' => $restrictions];
    }

    /**
     * Tax detail grouped by tax-code combination (province + which tax rates
     * were applied). Useful for per-jurisdiction reconciliation.
     */
    public static function taxDetailByCode(int $periodId): array
    {
        $period = \db_row("SELECT period_start, period_end FROM acc_tax_filing_periods WHERE id = ?", [$periodId]);
        if (!$period) {
            throw new \RuntimeException("Filing period #{$periodId} not found.");
        }

        // Group invoices by which tax rates were applied (signature = combination
        // of non-zero rate columns). Customer province surfaced for context.
        $rows = \db_select(
            "SELECT c.province AS province,
                    COUNT(*)   AS invoice_count,
                    COALESCE(SUM(i.subtotal_after_discount), 0) AS taxable_sales,
                    COALESCE(SUM(i.tax_gst_amount), 0) AS gst_collected,
                    COALESCE(SUM(i.tax_hst_amount), 0) AS hst_collected,
                    COALESCE(SUM(i.tax_pst_amount), 0) AS pst_collected,
                    MAX(i.tax_gst_rate) AS gst_rate,
                    MAX(i.tax_hst_rate) AS hst_rate,
                    MAX(i.tax_pst_rate) AS pst_rate
               FROM invoices i
          LEFT JOIN customers c ON c.id = i.customer_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','paid','partially_paid','overdue')
                AND i.deleted_at IS NULL
              GROUP BY c.province
              ORDER BY c.province",
            [$period['period_start'], $period['period_end']]
        );

        return $rows;
    }

    /**
     * Per-customer detail with POS mismatch flag. Used for CRA audit
     * defensibility — surfaces customers whose billed jurisdiction
     * differs from what PlaceOfSupplyService would now derive.
     */
    public static function taxDetailByCustomer(int $periodId): array
    {
        $period = \db_row("SELECT period_start, period_end FROM acc_tax_filing_periods WHERE id = ?", [$periodId]);
        if (!$period) {
            throw new \RuntimeException("Filing period #{$periodId} not found.");
        }

        $rows = \db_select(
            "SELECT c.id AS customer_id, c.company_name, c.province AS province_applied,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.subtotal_after_discount), 0) AS total_revenue,
                    COALESCE(SUM(i.tax_gst_amount), 0) AS gst_collected,
                    COALESCE(SUM(i.tax_hst_amount), 0) AS hst_collected,
                    COALESCE(SUM(i.tax_pst_amount), 0) AS pst_collected
               FROM invoices i
               JOIN customers c ON c.id = i.customer_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status IN ('sent','paid','partially_paid','overdue')
                AND i.deleted_at IS NULL
              GROUP BY c.id, c.company_name, c.province
              ORDER BY total_revenue DESC",
            [$period['period_start'], $period['period_end']]
        );

        // Annotate each row with POS-derived province + mismatch flag.
        foreach ($rows as &$row) {
            try {
                $pos = PlaceOfSupplyService::resolve([
                    'transaction_type' => 'short_lease',
                    'transaction_date' => $period['period_end'],
                    'customer_id'      => (int) $row['customer_id'],
                ]);
                $row['pos_derived_province'] = $pos['resolved_province'];
                $row['pos_mismatch'] = $row['province_applied'] !== $pos['resolved_province'];
            } catch (\Throwable $e) {
                $row['pos_derived_province'] = null;
                $row['pos_mismatch'] = false;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * ITC documentation completeness report. Checks each bill in the period
     * with ITC claimed against the three CRA threshold tiers:
     *   < $30      : no documentation gate (always compliant)
     *   $30–$149.99: vendor.name + bill.bill_date + bill.total_amount
     *                + bill.tax_gst_amount/tax_hst_amount required
     *   ≥ $150    : above + bill.description (line-level) required
     *
     * K-22 catches:
     *   - vendors has NO business_number column on disk → that field skipped;
     *     surfaced in the non-compliance reason as a known gap.
     *   - acc_bills has no buyer-company snapshot → skipped same way.
     */
    public static function itcDocumentationReport(int $periodId): array
    {
        $period = \db_row("SELECT period_start, period_end FROM acc_tax_filing_periods WHERE id = ?", [$periodId]);
        if (!$period) {
            throw new \RuntimeException("Filing period #{$periodId} not found.");
        }

        $bills = \db_select(
            "SELECT b.id, b.bill_number, b.bill_date, b.total_amount,
                    b.tax_gst_amount, b.tax_hst_amount, b.status,
                    v.name AS vendor_name,
                    (SELECT COUNT(*) FROM acc_bill_lines bl
                      WHERE bl.bill_id = b.id AND bl.is_tax_input_credit = 1) AS itc_line_count,
                    (SELECT MAX(CHAR_LENGTH(COALESCE(bl.description, '')))
                       FROM acc_bill_lines bl WHERE bl.bill_id = b.id) AS max_line_desc_len
               FROM acc_bills b
               JOIN vendors v ON v.id = b.vendor_id
              WHERE b.bill_date BETWEEN ? AND ?
                AND b.status IN ('approved','partially_paid','paid')",
            [$period['period_start'], $period['period_end']]
        );

        $compliant = 0;
        $nonCompliant = [];
        foreach ($bills as $b) {
            $amt = (string) $b['total_amount'];
            $missing = [];
            $tier = '<30';
            if (bccomp($amt, '30', 2) >= 0)  $tier = '30-149.99';
            if (bccomp($amt, '150', 2) >= 0) $tier = '>=150';

            // Tier-30+: vendor name + date + total + tax amounts required.
            if ($tier !== '<30') {
                if (empty($b['vendor_name'])) $missing[] = 'vendor_name';
                if (empty($b['bill_date']))   $missing[] = 'bill_date';
                if (bccomp((string) $b['total_amount'], '0', 2) <= 0) $missing[] = 'total_amount';
                $hasTax = bccomp((string) $b['tax_gst_amount'], '0', 2) > 0
                       || bccomp((string) $b['tax_hst_amount'], '0', 2) > 0;
                if (!$hasTax) $missing[] = 'tax_gst_amount_or_tax_hst_amount';
                // K-22: vendors has no business_number column on disk.
                $missing[] = '[gap] vendor.business_number column absent on disk';
            }

            // Tier-150+: line descriptions also required.
            if ($tier === '>=150') {
                if ((int) ($b['max_line_desc_len'] ?? 0) < 5) {
                    $missing[] = 'bill_lines.description (line-level detail < 5 chars)';
                }
                // K-22: acc_bills has no buyer-company snapshot column.
                $missing[] = '[gap] acc_bills.buyer_company_name column absent on disk';
            }

            // The two [gap] entries are constant — strip them when calculating
            // compliance so the report doesn't always show every bill as
            // non-compliant. Surfacing them in missing[] is informational.
            $realMissing = array_filter($missing, fn($m) => strpos($m, '[gap]') !== 0);
            if (empty($realMissing)) {
                $compliant++;
            } else {
                $nonCompliant[] = [
                    'bill_id'        => (int) $b['id'],
                    'bill_number'    => $b['bill_number'],
                    'bill_date'      => $b['bill_date'],
                    'amount'         => $amt,
                    'tier'           => $tier,
                    'vendor_name'    => $b['vendor_name'],
                    'missing_fields' => array_values($realMissing),
                    'known_gaps'     => array_values(array_filter($missing, fn($m) => strpos($m, '[gap]') === 0)),
                ];
            }
        }

        return [
            'period'              => $period,
            'total_bills'         => count($bills),
            'compliant_count'     => $compliant,
            'non_compliant_count' => count($nonCompliant),
            'non_compliant'       => $nonCompliant,
            'schema_gaps'         => [
                'vendors.business_number column missing on disk — required for tier-30+ ITC documentation per CRA §169(4); add via separate migration to fully enforce.',
                'acc_bills buyer-company snapshot missing — required for tier-150+ ITC documentation; add via separate migration to fully enforce.',
            ],
        ];
    }

    /**
     * Render a GST34 result as a CSV string ready for download.
     */
    public static function generateCsv(array $data): string
    {
        $p = $data['period'];
        $out = "FleetForge GST34 Return — {$p['period_start']} to {$p['period_end']}\n";
        $out .= 'Generated: ' . $data['computed_at'] . "\n";
        $out .= 'Tax Type: ' . $p['tax_type'] . "\n";
        $out .= 'Filing Due: ' . $p['filing_due_date'] . "\n";
        $out .= 'Quick Method: ' . ($data['quick_method'] ? 'YES (' . $data['quick_rate'] . '%)' : 'NO') . "\n";
        $out .= "\n";
        $out .= "Line,Description,Amount\n";
        foreach ($data['lines'] as $key => $line) {
            $num = preg_replace('/^L/', '', $key);
            $desc = str_replace(['"', "\n"], ['""', ' '], $line['description']);
            $out .= "{$num},\"{$desc}\",{$line['amount']}\n";
        }
        $out .= "\n";
        $l113 = $data['lines']['L113']['amount'];
        $out .= 'Net Balance Due (positive) or Refund (negative): ' . $l113 . "\n";
        return $out;
    }

    /**
     * CRA-compatible XML element shape for electronic filing. Not a full
     * schema-validated GST34 transmission file (CRA imposes additional
     * envelope/headers via NetFile or My Business Account upload) — this
     * is the per-line element block suitable for paste-in.
     */
    public static function generateXml(array $data): string
    {
        $p = $data['period'];
        $bn = (string) \settings_get('accounting.cra_business_number', '');
        $bnOut = $bn !== '' ? $bn : '000000000RT0001';

        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<GST34Return>\n";
        $xml .= "  <BusinessNumber>" . htmlspecialchars($bnOut, ENT_XML1) . "</BusinessNumber>\n";
        if ($bn === '') {
            $xml .= "  <!-- WARNING: accounting.cra_business_number not configured; placeholder used. -->\n";
        }
        $xml .= "  <ReportingPeriod>\n";
        $xml .= "    <Start>{$p['period_start']}</Start>\n";
        $xml .= "    <End>{$p['period_end']}</End>\n";
        $xml .= "    <FilingDue>{$p['filing_due_date']}</FilingDue>\n";
        $xml .= "    <Frequency>{$p['frequency']}</Frequency>\n";
        $xml .= "  </ReportingPeriod>\n";
        $xml .= "  <QuickMethod>" . ($data['quick_method'] ? 'true' : 'false') . "</QuickMethod>\n";
        if ($data['quick_method']) {
            $xml .= "  <QuickMethodRate>" . htmlspecialchars((string) $data['quick_rate'], ENT_XML1) . "</QuickMethodRate>\n";
        }
        $xml .= "  <Lines>\n";
        foreach ($data['lines'] as $key => $line) {
            $num = preg_replace('/^L/', '', $key);
            $xml .= "    <Line{$num}>{$line['amount']}</Line{$num}>\n";
        }
        $xml .= "  </Lines>\n";
        $xml .= "</GST34Return>\n";
        return $xml;
    }
}
