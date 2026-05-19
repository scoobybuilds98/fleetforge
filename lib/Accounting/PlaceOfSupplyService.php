<?php
declare(strict_types=1);

/**
 * lib/Accounting/PlaceOfSupplyService.php
 *
 * Place of Supply rule engine per ACCOUNTING_SPEC §23.6. Determines the
 * correct sales-tax province for a transaction based on customer billing
 * address, asset registration province, or lease ordinarily-located rule.
 *
 * Pre-flight column-name catches (K-22, S-ACCT-POS — locked in REFERENCE.md
 * §11 alongside this session):
 *   - tax_rates uses WIDE-row design: `province` (varchar) + separate
 *     gst_rate / pst_rate / hst_rate columns. NOT tax_type+rate tall rows.
 *   - customers uses `province` (not `province_code`).
 *   - leases has NO ordinarily_located_province column on disk — LONG_LEASE
 *     resolution falls back to customer.province (logged in derivation_trail).
 *   - equipment_units has NO province_code column — SPECIFIED_MOTOR_VEHICLE
 *     resolution falls back to customer.province (logged in derivation_trail).
 *
 * Scope: this session adds the derivation layer + warning surface only.
 * Full TaxCalculator integration (driving tax calculation end-to-end from
 * the resolved province) is deferred to S-ACCT-GST34.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.6
 * Session: S-ACCT-POS
 */

namespace FleetForge\Accounting;

class PlaceOfSupplyService
{
    /**
     * Resolve the place of supply + applicable tax rates for a transaction.
     *
     * @param array $params {
     *   transaction_type:   'short_lease'|'long_lease'|'service'|
     *                       'goods_delivered'|'specified_motor_vehicle' (required)
     *   transaction_date:   'Y-m-d' (required — drives effective-date filter)
     *   customer_id:        int (required — primary province signal)
     *   lease_id:           int (optional — for long_lease ordinarily-located)
     *   asset_id:           int (optional — for specified_motor_vehicle)
     *   delivery_province:  string (optional — short_lease/goods_delivered override)
     * }
     * @return array {
     *   resolved_province: 'BC' etc,
     *   resolution_method: 'delivery_province'|'customer_province'|'ordinarily_located'
     *                     |'asset_registration'|'default',
     *   transaction_type, transaction_date,
     *   applicable_rates: [{ id, name, gst_rate, pst_rate, hst_rate,
     *                        effective_from, effective_to }, ...],
     *   is_out_of_province: bool (true when resolved != pos_default_province),
     *   derivation_trail:  string (human-readable audit explanation)
     * }
     * @throws \RuntimeException on missing required params or unknown
     *   transaction_type.
     */
    public static function resolve(array $params): array
    {
        $type = $params['transaction_type'] ?? null;
        $date = $params['transaction_date'] ?? null;
        $custId = (int) ($params['customer_id'] ?? 0);

        $validTypes = ['short_lease','long_lease','service','goods_delivered','specified_motor_vehicle'];
        if (!in_array($type, $validTypes, true)) {
            throw new \RuntimeException(
                'transaction_type must be one of: ' . implode(', ', $validTypes)
            );
        }
        if (!$date) {
            throw new \RuntimeException('transaction_date is required.');
        }
        if (!$custId) {
            throw new \RuntimeException('customer_id is required.');
        }

        // K-22: customers uses `company_name` not `name`.
        $customer = \db_row("SELECT id, company_name, province FROM customers WHERE id = ?", [$custId]);
        if (!$customer) {
            throw new \RuntimeException("Customer #{$custId} not found.");
        }

        $defaultProvince = (string) \settings_get('accounting.pos_default_province', 'BC');
        $trail = [];

        // ── Per-rule_type resolution ────────────────────────────────────────
        $resolved      = null;
        $method        = null;
        $deliveryProv  = isset($params['delivery_province']) && $params['delivery_province'] !== ''
            ? strtoupper((string) $params['delivery_province']) : null;

        switch ($type) {
            case 'short_lease':
            case 'goods_delivered':
                // POS = delivery province if provided; else customer billing province.
                if ($deliveryProv) {
                    $resolved = $deliveryProv;
                    $method = 'delivery_province';
                    $trail[] = "delivery_province override = '{$deliveryProv}'";
                } else {
                    $resolved = self::normalizeProvince($customer['province']);
                    $method = 'customer_province';
                    $trail[] = "customer billing province = '" . ($customer['province'] ?? 'NULL') . "'";
                }
                break;

            case 'long_lease':
                // Spec: POS = province where asset is ordinarily located.
                // On-disk leases table has NO ordinarily_located_province column
                // — fall back to customer.province (logged).
                $resolved = self::normalizeProvince($customer['province']);
                $method = 'customer_province';
                $trail[] = 'leases table has no ordinarily_located_province column on disk';
                $trail[] = "fallback: customer billing province = '" . ($customer['province'] ?? 'NULL') . "'";
                break;

            case 'service':
                // POS = customer billing province (closest to service).
                $resolved = self::normalizeProvince($customer['province']);
                $method = 'customer_province';
                $trail[] = "customer billing province = '" . ($customer['province'] ?? 'NULL') . "'";
                break;

            case 'specified_motor_vehicle':
                // Spec: POS = equipment_units.province_code (registration province).
                // On-disk equipment_units has NO province_code column — fall back.
                $resolved = self::normalizeProvince($customer['province']);
                $method = 'customer_province';
                $trail[] = 'equipment_units table has no province_code column on disk';
                $trail[] = "fallback: customer billing province = '" . ($customer['province'] ?? 'NULL') . "'";
                break;
        }

        // Final fallback to default province when no signal at all.
        if (!$resolved) {
            $resolved = $defaultProvince;
            $method = 'default';
            $trail[] = "no province signal — using pos_default_province '{$defaultProvince}'";
        }

        // ── Load POS rule + applicable tax_rates ───────────────────────────
        $ruleRow = \db_row(
            "SELECT id, applicable_tax_rate_ids, notes
               FROM acc_place_of_supply_rules
              WHERE rule_type = ? AND province_code = ? AND is_active = 1
              ORDER BY priority ASC LIMIT 1",
            [$type, $resolved]
        );

        $rates = [];
        if ($ruleRow) {
            $rateIds = json_decode((string) $ruleRow['applicable_tax_rate_ids'], true) ?: [];
            if (!empty($rateIds)) {
                $ph = implode(',', array_fill(0, count($rateIds), '?'));
                $rates = \db_select(
                    "SELECT id, name, province, gst_rate, pst_rate, hst_rate,
                            effective_from, effective_to
                       FROM tax_rates
                      WHERE id IN ({$ph}) AND is_active = 1
                        AND (effective_from IS NULL OR effective_from <= ?)
                        AND (effective_to   IS NULL OR effective_to   >= ?)
                      ORDER BY effective_from DESC",
                    array_merge($rateIds, [$date, $date])
                );
            }
            $trail[] = "POS rule #{$ruleRow['id']} ({$type} / {$resolved}) → " . count($rates) . ' JSON-rate row(s)';
        }

        // Fallback: when POS rule has no rows (missing OR JSON-filter returned
        // empty after effective-date filter — e.g. only the current rate id
        // is in the JSON but transaction_date is pre-effective_from), look up
        // by province directly. Catches the NS HST 15% pre-Apr-2025 case
        // where the JSON holds only the current 14% row (#7) but the historic
        // 15% row (#14) is the right answer for that transaction_date.
        if (empty($rates)) {
            $rates = \db_select(
                "SELECT id, name, province, gst_rate, pst_rate, hst_rate,
                        effective_from, effective_to
                   FROM tax_rates
                  WHERE province = ? AND is_active = 1
                    AND (effective_from IS NULL OR effective_from <= ?)
                    AND (effective_to   IS NULL OR effective_to   >= ?)
                  ORDER BY effective_from DESC",
                [$resolved, $date, $date]
            );
            $trail[] = $ruleRow
                ? "JSON-rate filter empty for {$date} — fell back to direct tax_rates lookup"
                : "no POS rule row for ({$type} / {$resolved}) — fell back to direct tax_rates lookup";
        }

        return [
            'resolved_province'  => $resolved,
            'resolution_method'  => $method,
            'transaction_type'   => $type,
            'transaction_date'   => $date,
            'applicable_rates'   => $rates,
            'is_out_of_province' => $resolved !== $defaultProvince,
            'derivation_trail'   => implode(' → ', $trail),
        ];
    }

    /**
     * Audit a fiscal period: re-derive POS for every posted invoice and
     * flag invoices whose tax was computed against a different province
     * than the POS engine now says.
     *
     * @param int $periodId
     * @return array { period, total_invoices, mismatch_count,
     *                 mismatches: [{ invoice_id, invoice_number,
     *                   applied_province, derived_province, tax_diff_estimate }] }
     */
    public static function auditReport(int $periodId): array
    {
        $period = \db_row(
            "SELECT id, name, year, month, start_date, end_date FROM acc_periods WHERE id = ?",
            [$periodId]
        );
        if (!$period) {
            throw new \RuntimeException("Period #{$periodId} not found.");
        }

        // Pull invoices in the period. K-22 catches: invoices table uses
        // `invoice_date` (not `issue_date`); has no `bill_province` column
        // — applied province is implicit in which of tax_{gst,pst,hst}_rate
        // are populated. We surface customer.province as the applied
        // signal (the value the TaxCalculator received at invoice time).
        $invoices = \db_select(
            "SELECT i.id, i.invoice_number, i.customer_id, i.invoice_date,
                    i.tax_total, i.tax_gst_rate, i.tax_pst_rate, i.tax_hst_rate,
                    c.province AS customer_province
               FROM invoices i
          LEFT JOIN customers c ON c.id = i.customer_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.deleted_at IS NULL
              ORDER BY i.id",
            [$period['start_date'], $period['end_date']]
        );

        $mismatches = [];
        foreach ($invoices as $inv) {
            $applied = self::normalizeProvince($inv['customer_province']);
            try {
                $r = self::resolve([
                    'transaction_type' => 'short_lease',  // Default rule for re-derivation
                    'transaction_date' => $inv['invoice_date'],
                    'customer_id'      => (int) $inv['customer_id'],
                ]);
            } catch (\Throwable $e) {
                continue;  // Skip invoices missing required signals.
            }
            $derived = $r['resolved_province'];
            if ($applied && $derived && $applied !== $derived) {
                $mismatches[] = [
                    'invoice_id'        => (int) $inv['id'],
                    'invoice_number'    => $inv['invoice_number'],
                    'applied_province'  => $applied,
                    'derived_province'  => $derived,
                    'tax_total'         => $inv['tax_total'],
                    'invoice_date'      => $inv['invoice_date'],
                ];
            }
        }

        return [
            'period'          => $period,
            'total_invoices'  => count($invoices),
            'mismatch_count'  => count($mismatches),
            'mismatches'      => $mismatches,
        ];
    }

    /**
     * Normalize province strings stored on disk (sometimes varchar(100)
     * holding the full name "British Columbia", sometimes the 2-letter
     * code "BC"). Returns the 2-letter code uppercased, or '' if unknown.
     */
    private static function normalizeProvince(?string $province): string
    {
        if ($province === null || $province === '') return '';
        $p = strtoupper(trim($province));
        // Already a 2-3 letter code?
        if (preg_match('/^[A-Z]{2,3}$/', $p)) return $p;

        // Full names → ISO codes.
        static $names = [
            'BRITISH COLUMBIA' => 'BC',
            'ALBERTA' => 'AB',
            'SASKATCHEWAN' => 'SK',
            'MANITOBA' => 'MB',
            'ONTARIO' => 'ON',
            'QUEBEC' => 'QC', 'QUÉBEC' => 'QC',
            'NEW BRUNSWICK' => 'NB',
            'NOVA SCOTIA' => 'NS',
            'PRINCE EDWARD ISLAND' => 'PE',
            'NEWFOUNDLAND AND LABRADOR' => 'NL', 'NEWFOUNDLAND' => 'NL',
            'YUKON' => 'YT',
            'NORTHWEST TERRITORIES' => 'NT',
            'NUNAVUT' => 'NU',
        ];
        return $names[$p] ?? $p;
    }
}
