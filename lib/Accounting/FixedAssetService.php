<?php
declare(strict_types=1);

/**
 * lib/Accounting/FixedAssetService.php
 *
 * Fixed-asset lifecycle: register, depreciate, dispose, impair, capex.
 * All money math uses bcmath; every state-changing call runs in a
 * db_transaction so the GL stays consistent.
 *
 * Required by: api/v1/accounting/fixed-assets/* , api/v1/accounting/depreciation/*
 *              api/v1/accounting/capex/*
 * Depends on: AccountingService (period lookup, JE numbering, settings)
 *             JournalEntryService (post the GL side of every event)
 *
 * Decisions: A4 (calendar fiscal), A7 (DECIMAL 15,2), A8 (JE backbone)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.8 (schema), §8 (workflow), §16 (auto-JEs)
 *
 * Key invariants:
 *   - Sum of acc_fixed_assets.acquisition_cost  ≡ GL balance of asset cost accounts
 *   - Sum of acc_fixed_assets.accumulated_depreciation
 *                                              ≡ GL balance of accum depr accounts
 *   - Posted depreciation runs are unique per (period, asset) — no double-post
 *   - Disposal sets status='disposed' and removes the asset's contribution to GL
 *     by crediting cost and debiting accumulated depreciation
 */

namespace FleetForge\Accounting;

class FixedAssetService
{
    // ============================================================
    // ASSET-NUMBER GENERATION — atomic, year-prefixed
    // Pattern: FA-YYYY-NNNNN
    // ============================================================

    /**
     * Generate the next asset number atomically.
     * Must be called inside a db_transaction().
     *
     * @param string $year 4-digit year
     * @return string e.g. "FA-2026-00001"
     */
    public static function nextAssetNumber(string $year): string
    {
        $key = "accounting.asset_next_number.{$year}";

        // Lock the settings row so two parallel inserts can't collide
        $row = \db_row("SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
        $next = $row ? (int) $row['value'] : 1;
        $number = sprintf("FA-%s-%05d", $year, $next);

        if ($row) {
            \db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string)($next + 1), $key]);
        } else {
            \db_execute(
                "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
                 VALUES (?, ?, 'integer', 'accounting', 'FA Counter', 'Auto-increment counter', 0)",
                [$key, (string)($next + 1)]
            );
        }

        return $number;
    }

    /**
     * Generate the next CapEx request number atomically.
     */
    public static function nextCapexNumber(string $year): string
    {
        $key = "accounting.capex_next_number.{$year}";

        $row  = \db_row("SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
        $next = $row ? (int) $row['value'] : 1;
        $number = sprintf("CAPEX-%s-%05d", $year, $next);

        if ($row) {
            \db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string)($next + 1), $key]);
        } else {
            \db_execute(
                "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
                 VALUES (?, ?, 'integer', 'accounting', 'CAPEX Counter', 'Auto-increment counter', 0)",
                [$key, (string)($next + 1)]
            );
        }

        return $number;
    }

    // ============================================================
    // CREATE — register a new fixed asset
    // ============================================================

    /**
     * Create a new fixed asset and return the inserted row.
     *
     * @param array    $data    Required: name, asset_class, acquisition_date,
     *                          acquisition_cost, asset_account_id,
     *                          accum_depr_account_id, depr_expense_account_id.
     *                          Optional: description, equipment_unit_id,
     *                          depreciation_method, useful_life_years,
     *                          salvage_value, cra_class, cra_cca_rate,
     *                          total_expected_units, vendor_id, location,
     *                          serial_number, notes, depreciation_start_date.
     * @param int|null $userId
     */
    public static function create(array $data, ?int $userId = null): array
    {
        // ── Required fields ─────────────────────────────────────
        $required = [
            'name', 'asset_class', 'acquisition_date', 'acquisition_cost',
            'asset_account_id', 'accum_depr_account_id', 'depr_expense_account_id',
        ];
        foreach ($required as $k) {
            if (!isset($data[$k]) || $data[$k] === '' || $data[$k] === null) {
                throw new \RuntimeException("$k is required.");
            }
        }

        $cost    = self::money($data['acquisition_cost']);
        $salvage = self::money($data['salvage_value'] ?? '0.00');

        if (bccomp($cost, '0.00', 2) <= 0) {
            throw new \RuntimeException('acquisition_cost must be greater than zero.');
        }
        if (bccomp($salvage, '0.00', 2) < 0) {
            throw new \RuntimeException('salvage_value cannot be negative.');
        }
        if (bccomp($salvage, $cost, 2) > 0) {
            throw new \RuntimeException('salvage_value cannot exceed acquisition_cost.');
        }

        $depreciableCost = bcsub($cost, $salvage, 2);

        $method = $data['depreciation_method'] ?? 'straight_line';
        $valid  = ['straight_line', 'declining_balance', 'units_of_production', 'none'];
        if (!in_array($method, $valid, true)) {
            throw new \RuntimeException("depreciation_method must be one of: " . implode(', ', $valid));
        }

        // Method-specific guards
        if ($method === 'straight_line' && empty($data['useful_life_years'])) {
            throw new \RuntimeException('useful_life_years is required for straight_line.');
        }
        if ($method === 'declining_balance' && empty($data['cra_cca_rate'])) {
            throw new \RuntimeException('cra_cca_rate is required for declining_balance.');
        }
        if ($method === 'units_of_production' && empty($data['total_expected_units'])) {
            throw new \RuntimeException('total_expected_units is required for units_of_production.');
        }

        return \db_transaction(function () use ($data, $cost, $salvage, $depreciableCost, $method, $userId) {
            $year   = substr((string) $data['acquisition_date'], 0, 4);
            $number = self::nextAssetNumber($year);

            $insertId = \db_insert('acc_fixed_assets', [
                'asset_number'             => $number,
                'name'                     => $data['name'],
                'description'              => $data['description'] ?? null,
                'asset_class'              => $data['asset_class'],
                // S-ACCT-CCA-1: cca_class_id is the FK-driven CCA mapping.
                // Legacy cra_class (varchar) + cra_cca_rate kept for backward
                // compatibility with depreciation engine — CCA engine uses the FK.
                'cca_class_id'             => $data['cca_class_id'] ?? null,
                'cra_class'                => $data['cra_class'] ?? null,
                'cra_cca_rate'             => $data['cra_cca_rate'] ?? null,
                'equipment_unit_id'        => $data['equipment_unit_id'] ?? null,
                'acquisition_date'         => $data['acquisition_date'],
                'available_for_use_date'   => $data['available_for_use_date'] ?? null,
                'is_aiip_eligible'         => array_key_exists('is_aiip_eligible', $data)
                                                ? (!empty($data['is_aiip_eligible']) ? 1 : 0)
                                                : 1,
                'acquisition_cost'         => $cost,
                // ── PAYOFF-1: acquisition cost breakdown ────────────
                // WHY: Total acquisition cost for payoff includes more
                // than the sticker price (taxes, delivery, setup). Stored
                // as nullable so legacy assets aren't forced to backfill.
                'purchase_tax_gst'          => $data['purchase_tax_gst']  ?? null,
                'purchase_tax_pst'          => $data['purchase_tax_pst']  ?? null,
                'delivery_cost'             => $data['delivery_cost']     ?? '0.00',
                'setup_cost'                => $data['setup_cost']        ?? '0.00',
                // ── PAYOFF-1: financing (optional) ──────────────────
                'is_financed'               => !empty($data['is_financed']) ? 1 : 0,
                'financing_monthly_payment' => $data['financing_monthly_payment'] ?? null,
                'financing_interest_rate'   => $data['financing_interest_rate']   ?? null,
                'financing_remaining_months'=> $data['financing_remaining_months']?? null,
                // ── PAYOFF-1: monthly fixed operating costs ─────────
                'monthly_insurance_cost'    => $data['monthly_insurance_cost']    ?? '0.00',
                'monthly_licensing_cost'    => $data['monthly_licensing_cost']    ?? '0.00',
                'monthly_registration_cost' => $data['monthly_registration_cost'] ?? '0.00',
                'acquisition_bill_id'      => $data['acquisition_bill_id'] ?? null,
                // S-FA-IMPORT: 1 = the asset predates the system, so its
                // acquisition_date is a book-entry date rather than a cash
                // event. Cash flow excludes these; every other report treats
                // them as ordinary assets. Defaults 0 for the normal flow.
                'is_opening_balance'       => !empty($data['is_opening_balance']) ? 1 : 0,
                'vendor_id'                => $data['vendor_id'] ?? null,
                'depreciation_method'      => $method,
                'useful_life_years'        => $data['useful_life_years'] ?? null,
                'salvage_value'            => $salvage,
                'depreciable_cost'         => $depreciableCost,
                'accumulated_depreciation' => '0.00',
                'net_book_value'           => $cost,
                'depreciation_start_date'  => $data['depreciation_start_date'] ?? $data['acquisition_date'],
                'asset_account_id'         => (int) $data['asset_account_id'],
                'accum_depr_account_id'    => (int) $data['accum_depr_account_id'],
                'depr_expense_account_id'  => (int) $data['depr_expense_account_id'],
                'status'                   => 'active',
                'total_expected_units'     => $data['total_expected_units'] ?? null,
                'units_used_to_date'       => 0,
                'location'                 => $data['location'] ?? null,
                'serial_number'            => $data['serial_number'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $userId,
            ]);

            self::audit(
                $userId,
                'create',
                'fixed_asset',
                $insertId,
                "{$number} {$data['name']}",
                "Created fixed asset: cost \${$cost}, method {$method}, " .
                "salvage \${$salvage}, acquired {$data['acquisition_date']}"
            );

            return \db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$insertId]);
        });
    }

    /**
     * Update an existing asset with optimistic locking on updated_at (D19).
     *
     * @param int      $id
     * @param array    $data       fields to change + must contain updated_at
     * @param int|null $userId
     * @return array               fresh row including new updated_at
     * @throws \RuntimeException on stale data, not found, or invalid value
     */
    public static function update(int $id, array $data, ?int $userId = null): array
    {
        if (empty($data['updated_at'])) {
            throw new \RuntimeException('updated_at is required for the optimistic lock.');
        }

        return \db_transaction(function () use ($id, $data, $userId) {
            $row = \db_row("SELECT * FROM acc_fixed_assets WHERE id = ? FOR UPDATE", [$id]);
            if (!$row) {
                throw new \RuntimeException('Asset not found.');
            }
            if (!optimistic_lock_matches($data['updated_at'], $row['updated_at'])) {
                throw new \RuntimeException('STALE_DATA: this asset was modified by another user.');
            }
            if ($row['status'] === 'disposed') {
                throw new \RuntimeException('Cannot edit a disposed asset.');
            }

            $editable = [
                'name', 'description', 'asset_class', 'cra_class', 'cra_cca_rate',
                // S-ACCT-CCA-1: new CCA fields editable on the asset form.
                'cca_class_id', 'available_for_use_date', 'is_aiip_eligible',
                'equipment_unit_id', 'depreciation_method', 'useful_life_years',
                'salvage_value', 'total_expected_units', 'vendor_id',
                'asset_account_id', 'accum_depr_account_id', 'depr_expense_account_id',
                'location', 'serial_number', 'notes',
                // ── PAYOFF-1: acquisition detail + financing + fixed costs
                // WHY: Payoff calculator inputs are editable on the asset
                // edit form. All monetary values stay as strings for bcmath.
                'purchase_tax_gst', 'purchase_tax_pst',
                'delivery_cost', 'setup_cost',
                'is_financed', 'financing_monthly_payment',
                'financing_interest_rate', 'financing_remaining_months',
                'monthly_insurance_cost', 'monthly_licensing_cost',
                'monthly_registration_cost',
            ];

            $updates = [];
            foreach ($editable as $f) {
                if (array_key_exists($f, $data)) {
                    $updates[$f] = $data[$f];
                }
            }
            if (empty($updates)) {
                throw new \RuntimeException('No editable fields supplied.');
            }

            // ── PAYOFF-1: normalise is_financed flag ────────────────
            // WHY: The endpoint forwards a raw value (string/bool/null).
            // Coerce to a strict 0/1 so MySQL stores a clean bit value.
            if (array_key_exists('is_financed', $updates)) {
                $updates['is_financed'] = !empty($updates['is_financed']) ? 1 : 0;
            }

            // ── PAYOFF-1: validate non-negative money for new fields
            // WHY: Database allows null, but user-entered values must
            // never be negative (would produce nonsense payoff math).
            $nonNegMoneyFields = [
                'purchase_tax_gst', 'purchase_tax_pst',
                'delivery_cost', 'setup_cost',
                'financing_monthly_payment',
                'monthly_insurance_cost', 'monthly_licensing_cost',
                'monthly_registration_cost',
            ];
            foreach ($nonNegMoneyFields as $f) {
                if (array_key_exists($f, $updates) && $updates[$f] !== null && $updates[$f] !== '') {
                    $v = self::money($updates[$f]);
                    if (bccomp($v, '0.00', 2) < 0) {
                        throw new \RuntimeException("$f cannot be negative.");
                    }
                    $updates[$f] = $v;
                }
            }

            // Recompute depreciable_cost / NBV if salvage changed
            if (array_key_exists('salvage_value', $updates)) {
                $newSalvage = self::money($updates['salvage_value']);
                if (bccomp($newSalvage, '0.00', 2) < 0) {
                    throw new \RuntimeException('salvage_value cannot be negative.');
                }
                if (bccomp($newSalvage, $row['acquisition_cost'], 2) > 0) {
                    throw new \RuntimeException('salvage_value cannot exceed acquisition_cost.');
                }
                $updates['salvage_value']    = $newSalvage;
                $updates['depreciable_cost'] = bcsub($row['acquisition_cost'], $newSalvage, 2);
            }

            $updates['updated_at'] = date('Y-m-d H:i:s');
            \db_update('acc_fixed_assets', $updates, 'id = ?', [$id]);

            self::audit(
                $userId,
                'update',
                'fixed_asset',
                $id,
                "{$row['asset_number']} {$row['name']}",
                "Updated fields: " . implode(', ', array_keys($updates))
            );

            return \db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$id]);
        });
    }

    // ============================================================
    // DEPRECIATION — calculation engines
    // Each engine returns the monthly depreciation amount as a
    // bcmath-safe string. Period-level math is done in centsby
    // multiplying by the number of months in the run period
    // (typically 1 for monthly closes).
    // ============================================================

    /**
     * Compute the depreciation for one period, returning a structured
     * result with the dollar amount, opening NBV, closing NBV, and
     * a calculation_detail JSON breakdown for audit.
     *
     * @param array  $asset       full row from acc_fixed_assets
     * @param array  $period      acc_periods row for the period being run
     * @param int|null $unitsUsed Optional override for UOP method
     *
     * @return array {amount, opening_nbv, closing_nbv, method, calculation_detail}
     */
    public static function calculateForPeriod(array $asset, array $period, ?int $unitsUsed = null): array
    {
        $openingNbv = (string) $asset['net_book_value'];
        $method     = $asset['depreciation_method'];
        $detail     = ['method' => $method, 'period' => $period['name']];
        $amount     = '0.00';

        // Skip fully depreciated, disposed, impaired
        if ($asset['status'] !== 'active' || $method === 'none') {
            return [
                'amount'             => '0.00',
                'opening_nbv'        => $openingNbv,
                'closing_nbv'        => $openingNbv,
                'method'             => $method,
                'calculation_detail' => $detail + ['skipped' => true, 'reason' => "status={$asset['status']}"],
            ];
        }

        $cost            = (string) $asset['acquisition_cost'];
        $salvage         = (string) $asset['salvage_value'];
        $depreciable     = (string) $asset['depreciable_cost'];
        $accumulated     = (string) $asset['accumulated_depreciation'];

        // -- STRAIGHT LINE -----------------------------------------------------
        // Straight-line over the REMAINING life: monthly = (NBV − salvage) /
        // remaining_months. For a never-impaired asset this is identical to the
        // classic (cost − salvage)/(life·12) every month (proven algebraically),
        // but it self-corrects after an impairment — impair() resets NBV /
        // depreciable_cost to the lower carrying value WITHOUT shortening
        // useful_life_years, so the old `depreciable_cost / (life·12)` spread the
        // reduced base over the FULL original life and under-depreciated
        // ($60/mo vs the correct $100/mo). The final month trues up to salvage.
        if ($method === 'straight_line') {
            $life        = (string) $asset['useful_life_years'];
            $totalMonths = (int) bcmul($life, '12', 0);
            $startY = (int) substr((string) $asset['depreciation_start_date'], 0, 4);
            $startM = (int) substr((string) $asset['depreciation_start_date'], 5, 2);
            $py     = (int) ($period['year']  ?? $startY);
            $pm     = (int) ($period['month'] ?? $startM);
            $elapsedMonths   = ($py - $startY) * 12 + ($pm - $startM);
            $remainingMonths = $totalMonths - $elapsedMonths;
            // Amount still to depreciate from the opening (pre-this-period) NBV.
            $deprRemaining = bcsub($openingNbv, $salvage, 2);

            if (bccomp($deprRemaining, '0', 2) <= 0) {
                $amount = '0.00';
            } elseif ($remainingMonths <= 1) {
                // Final scheduled month (or past end-of-life): book the remainder
                // so the asset lands exactly on salvage.
                $amount = self::roundMoney($deprRemaining);
            } else {
                $amount = self::roundMoney(bcdiv($deprRemaining, (string) $remainingMonths, 6));
            }
            $detail += [
                'opening_nbv'        => $openingNbv,
                'salvage'            => $salvage,
                'useful_life_years'  => $life,
                'elapsed_months'     => $elapsedMonths,
                'remaining_months'   => max(0, $remainingMonths),
                'monthly_amount'     => $amount,
                'formula'            => '(opening_nbv - salvage) / remaining_months (re-spreads after impairment)',
            ];
        }

        // -- DECLINING BALANCE (CRA half-year rule) ----------------------------
        elseif ($method === 'declining_balance') {
            $rate = (string) $asset['cra_cca_rate'];

            // First year: half-year rule. Year is calendar year of depreciation_start_date.
            $startYear  = (int) substr((string) $asset['depreciation_start_date'], 0, 4);
            $periodYear = (int) $period['year'];
            $isFirstYear = ($periodYear === $startYear);

            if ($isFirstYear) {
                // First year FULL annual = cost * rate * 0.5  (half-year)
                $annual  = bcmul($cost, $rate, 6);
                $annual  = bcmul($annual, '0.5', 6);
                $monthly = self::roundMoney(bcdiv($annual, '12', 6));
                $detail += [
                    'rate'         => $rate,
                    'opening_ucc'  => $cost,
                    'half_year'    => true,
                    'annual_amount'=> self::roundMoney($annual),
                    'monthly'      => $monthly,
                    'formula'      => 'cost * rate * 0.5 / 12',
                ];
            } else {
                // CRA declining balance: the rate applies to the UCC at the
                // START of the fiscal year (= calendar year here), and the
                // resulting annual CCA is spread evenly across the 12 monthly
                // runs. The previous `cost - accumulated` used the LIVE
                // accumulated, which grows with each monthly run, so the rate
                // hit a shrinking mid-year UCC and compounded monthly →
                // ~12.7% under-depreciation in year 2 (H3).
                //
                // calculateForPeriod is stateless and is driven by two callers
                // that both carry `accumulated` forward but hold no DB run
                // history mid-projection (the depreciationSchedule simulation),
                // so we cannot query prior-year postings. Instead recover the
                // constant monthly M directly: with monthsElapsed = (month - 1)
                // equal charges of M already in `accumulated` this year,
                //   UCC_opening = (cost - accumulated) + monthsElapsed * M
                //   M           = UCC_opening * rate / 12
                // Solving eliminates the unknown opening UCC:
                //   M = (cost - accumulated) * (rate/12) / (1 - monthsElapsed * rate/12)
                // For month 1 this collapses to (cost - accumulated) * rate / 12,
                // and it yields the same constant M for every month of the year
                // regardless of rounding drift in `accumulated`.
                $monthsElapsed = max(0, (int) ($period['month'] ?? 1) - 1);
                $uccNow  = bcsub($cost, $accumulated, 6);            // cost − live accumulated
                $rate12  = bcdiv($rate, '12', 12);
                $denom   = bcsub('1', bcmul((string) $monthsElapsed, $rate12, 12), 12);

                if (bccomp($denom, '0', 12) <= 0) {
                    // Degenerate (absurd rate × month) — fall back to the live UCC.
                    $monthly    = self::roundMoney(bcmul($uccNow, $rate12, 12));
                    $openingUcc = $uccNow;
                } else {
                    $monthly    = self::roundMoney(bcdiv(bcmul($uccNow, $rate12, 12), $denom, 12));
                    $openingUcc = bcadd($uccNow, bcmul((string) $monthsElapsed, $monthly, 6), 2);
                }
                $detail += [
                    'rate'         => $rate,
                    'opening_ucc'  => self::money($openingUcc),
                    'half_year'    => false,
                    'annual_amount'=> self::roundMoney(bcmul($openingUcc, $rate, 6)),
                    'monthly'      => $monthly,
                    'formula'      => 'year_opening_ucc * rate / 12 (held constant across the fiscal year)',
                ];
            }
            $amount = $monthly;
        }

        // -- UNITS OF PRODUCTION ----------------------------------------------
        elseif ($method === 'units_of_production') {
            $totalUnits = (int) $asset['total_expected_units'];
            if ($totalUnits <= 0) {
                throw new \RuntimeException('total_expected_units must be > 0 for units_of_production.');
            }

            // If caller didn't supply units, try to auto-pull from mileage_logs.
            $units = $unitsUsed;
            if ($units === null) {
                $units = self::autoUnitsFromMileage(
                    (int) $asset['equipment_unit_id'],
                    $period['start_date'],
                    $period['end_date']
                );
            }
            $units = max(0, (int) $units);

            // monthly = (cost - salvage) * (units / total)
            if ($units > 0) {
                $ratio  = bcdiv((string) $units, (string) $totalUnits, 10);
                $amount = self::roundMoney(bcmul($depreciable, $ratio, 6));
            }

            $detail += [
                'depreciable_cost'    => $depreciable,
                'total_expected_units'=> $totalUnits,
                'units_used'          => $units,
                'auto_pulled'         => $unitsUsed === null,
                'formula'             => '(cost - salvage) * (units / total)',
            ];
        } else {
            throw new \RuntimeException("Unknown depreciation method: {$method}");
        }

        // Cap depreciation so NBV never falls below salvage value.
        // Closing NBV = opening - amount, but must be >= salvage_value.
        $closing = bcsub($openingNbv, $amount, 2);
        if (bccomp($closing, $salvage, 2) < 0) {
            // Adjust amount down to land exactly on salvage
            $amount  = bcsub($openingNbv, $salvage, 2);
            $closing = $salvage;
            $detail['capped_to_salvage'] = true;
        }

        return [
            'amount'             => $amount,
            'opening_nbv'        => $openingNbv,
            'closing_nbv'        => $closing,
            'method'             => $method,
            'calculation_detail' => $detail,
        ];
    }

    /**
     * Pull miles driven for a unit between two dates by diffing the
     * earliest/latest odometer readings in mileage_logs.
     */
    public static function autoUnitsFromMileage(int $unitId, string $start, string $end): int
    {
        if (!$unitId) return 0;
        $row = \db_row(
            "SELECT MIN(odometer_reading) AS first_odo,
                    MAX(odometer_reading) AS last_odo
             FROM mileage_logs
             WHERE equipment_unit_id = ? AND log_date BETWEEN ? AND ?",
            [$unitId, $start, $end]
        );
        if (!$row || $row['first_odo'] === null) return 0;
        return max(0, (int) $row['last_odo'] - (int) $row['first_odo']);
    }

    // ============================================================
    // DEPRECIATION RUN — preview / post / list
    // ============================================================

    /**
     * Preview a depreciation run for the given period.
     * Creates a draft `acc_depreciation_runs` row with status='preview',
     * one line per active asset, but does NOT create a journal entry.
     * Caller must call `postRun()` to commit.
     *
     * Re-running preview for the same period replaces the previous preview.
     *
     * @param int      $periodId
     * @param int|null $userId
     * @param array    $unitOverrides  asset_id => units (UOP overrides)
     * @return array {run, lines, totals_by_account}
     */
    public static function previewRun(int $periodId, ?int $userId = null, array $unitOverrides = []): array
    {
        return \db_transaction(function () use ($periodId, $userId, $unitOverrides) {
            $period = \db_row("SELECT * FROM acc_periods WHERE id = ?", [$periodId]);
            if (!$period) throw new \RuntimeException('Period not found.');

            // Block if a posted run already exists for this period
            $posted = \db_row(
                "SELECT id FROM acc_depreciation_runs WHERE period_id = ? AND status = 'posted'",
                [$periodId]
            );
            if ($posted) {
                throw new \RuntimeException("A depreciation run is already posted for {$period['name']}. Reverse it first.");
            }

            // Wipe any earlier preview for the same period (allowed)
            $oldPreview = \db_row(
                "SELECT id FROM acc_depreciation_runs WHERE period_id = ? AND status = 'preview'",
                [$periodId]
            );
            if ($oldPreview) {
                \db_execute("DELETE FROM acc_depreciation_run_lines WHERE run_id = ?", [$oldPreview['id']]);
                \db_execute("DELETE FROM acc_depreciation_runs       WHERE id = ?",     [$oldPreview['id']]);
            }

            // Create run header (preview)
            $runId = \db_insert('acc_depreciation_runs', [
                'period_id'         => $periodId,
                'run_date'          => date('Y-m-d H:i:s'),
                'status'            => 'preview',
                'total_depreciation'=> '0.00',
                'asset_count'       => 0,
                'run_by'            => $userId,
                'notes'             => "Preview generated " . date('c'),
            ]);

            // For each active asset whose start_date <= period.end_date and not skipped
            $assets = \db_select(
                "SELECT * FROM acc_fixed_assets
                 WHERE status = 'active'
                   AND depreciation_start_date <= ?
                   AND depreciation_method <> 'none'
                 ORDER BY id",
                [$period['end_date']]
            );

            $total      = '0.00';
            $count      = 0;
            $byAccount  = [];   // [debit_account_id => amount, credit_account_id => amount]
            $lines      = [];

            foreach ($assets as $a) {
                $override = $unitOverrides[$a['id']] ?? null;
                $calc     = self::calculateForPeriod($a, $period, $override);

                if (bccomp($calc['amount'], '0.00', 2) === 0) continue; // skip 0

                \db_insert('acc_depreciation_run_lines', [
                    'run_id'             => $runId,
                    'asset_id'           => (int) $a['id'],
                    'period_id'          => $periodId,
                    'opening_nbv'        => $calc['opening_nbv'],
                    'depreciation'       => $calc['amount'],
                    'closing_nbv'        => $calc['closing_nbv'],
                    'method_used'        => $calc['method'],
                    'calculation_detail' => json_encode($calc['calculation_detail']),
                ]);

                $total = bcadd($total, $calc['amount'], 2);
                $count++;

                $dr = (int) $a['depr_expense_account_id'];
                $cr = (int) $a['accum_depr_account_id'];
                $byAccount[$dr]['debit']  = bcadd($byAccount[$dr]['debit']  ?? '0.00', $calc['amount'], 2);
                $byAccount[$cr]['credit'] = bcadd($byAccount[$cr]['credit'] ?? '0.00', $calc['amount'], 2);

                $lines[] = [
                    'asset_id'     => (int) $a['id'],
                    'asset_number' => $a['asset_number'],
                    'asset_name'   => $a['name'],
                    'opening_nbv'  => $calc['opening_nbv'],
                    'amount'       => $calc['amount'],
                    'closing_nbv'  => $calc['closing_nbv'],
                    'method'       => $calc['method'],
                ];
            }

            \db_update(
                'acc_depreciation_runs',
                ['total_depreciation' => $total, 'asset_count' => $count],
                'id = ?',
                [$runId]
            );

            return [
                'run'               => \db_row("SELECT * FROM acc_depreciation_runs WHERE id = ?", [$runId]),
                'lines'             => $lines,
                'totals_by_account' => $byAccount,
            ];
        });
    }

    /**
     * Post a previously-previewed run.  Creates the consolidated GL entry,
     * updates each asset's accumulated_depreciation / NBV / status,
     * and marks the run as posted.
     *
     * §16 closed-period rule: If the run's period is closed/locked at posting
     * time, we DO NOT silently redirect (depreciation is per-period and the
     * accountant must consciously choose another period). Instead we throw —
     * the UI should inform the user and offer to re-preview against an open
     * period. Compare to billing auto-JEs (AutoEntryBridge) which DO redirect.
     */
    public static function postRun(int $runId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($runId, $userId) {
            $run = \db_row("SELECT * FROM acc_depreciation_runs WHERE id = ? FOR UPDATE", [$runId]);
            if (!$run) throw new \RuntimeException('Depreciation run not found.');
            if ($run['status'] !== 'preview') {
                throw new \RuntimeException("Cannot post — run is already {$run['status']}.");
            }

            $period = \db_row("SELECT * FROM acc_periods WHERE id = ?", [$run['period_id']]);
            $err    = AccountingService::validatePeriodForPosting((int) $period['id']);
            if ($err) {
                // §16: Depreciation runs are tied to a specific period. Surface
                // the error so the UI can prompt the user to re-preview against
                // an open period rather than silently moving the entry.
                throw new \RuntimeException("PERIOD_CLOSED: {$err}");
            }

            // Build the consolidated JE — one line per (account, side)
            $rawLines = \db_select(
                "SELECT l.*, a.depr_expense_account_id, a.accum_depr_account_id, a.asset_class, a.name AS asset_name
                 FROM acc_depreciation_run_lines l
                 JOIN acc_fixed_assets a ON a.id = l.asset_id
                 WHERE l.run_id = ?",
                [$runId]
            );
            if (!$rawLines) throw new \RuntimeException('Run has no lines.');

            $byAcct = []; // acct_id => ['debit'=>x, 'credit'=>y]
            foreach ($rawLines as $l) {
                $dr = (int) $l['depr_expense_account_id'];
                $cr = (int) $l['accum_depr_account_id'];
                $byAcct[$dr]['debit']  = bcadd($byAcct[$dr]['debit']  ?? '0.00', $l['depreciation'], 2);
                $byAcct[$cr]['credit'] = bcadd($byAcct[$cr]['credit'] ?? '0.00', $l['depreciation'], 2);
            }

            $jeLines = [];
            foreach ($byAcct as $acctId => $sides) {
                if (!empty($sides['debit'])) {
                    $jeLines[] = [
                        'account_id' => $acctId,
                        'debit'      => $sides['debit'],
                        'credit'     => '0.00',
                        'description'=> "Depreciation — {$period['name']}",
                    ];
                }
                if (!empty($sides['credit'])) {
                    $jeLines[] = [
                        'account_id' => $acctId,
                        'debit'      => '0.00',
                        'credit'     => $sides['credit'],
                        'description'=> "Accumulated depreciation — {$period['name']}",
                    ];
                }
            }

            $entry = JournalEntryService::create([
                'entry_date'      => $period['end_date'],
                'description'     => "Depreciation run — {$period['name']}",
                'entry_type'      => 'system',
                'reference'       => "DEP-RUN-{$runId}",
                'source_type'     => 'depreciation',
                'source_id'       => $runId,
                'post_immediately'=> true,
            ], $jeLines, $userId);

            // Update each asset
            foreach ($rawLines as $l) {
                $newAccum = bcadd((string) \db_row(
                    "SELECT accumulated_depreciation FROM acc_fixed_assets WHERE id = ? FOR UPDATE",
                    [$l['asset_id']]
                )['accumulated_depreciation'], $l['depreciation'], 2);
                $newNbv   = bcsub(
                    (string) \db_row("SELECT acquisition_cost FROM acc_fixed_assets WHERE id = ?", [$l['asset_id']])['acquisition_cost'],
                    $newAccum,
                    2
                );

                $update = [
                    'accumulated_depreciation' => $newAccum,
                    'net_book_value'           => $newNbv,
                    'last_depreciation_date'   => $period['end_date'],
                ];

                // Mark fully depreciated when NBV touches salvage
                $salvage = (string) \db_row("SELECT salvage_value FROM acc_fixed_assets WHERE id = ?", [$l['asset_id']])['salvage_value'];
                if (bccomp($newNbv, $salvage, 2) <= 0) {
                    $update['status']                = 'fully_depreciated';
                    $update['fully_depreciated_date']= $period['end_date'];
                }

                // Bump units_used_to_date for UOP
                if ($l['method_used'] === 'units_of_production') {
                    $detail = json_decode($l['calculation_detail'] ?? '{}', true) ?? [];
                    $units  = (int) ($detail['units_used'] ?? 0);
                    if ($units > 0) {
                        \db_execute(
                            "UPDATE acc_fixed_assets SET units_used_to_date = units_used_to_date + ? WHERE id = ?",
                            [$units, $l['asset_id']]
                        );
                    }
                }

                \db_update('acc_fixed_assets', $update, 'id = ?', [$l['asset_id']]);
            }

            // Mark run posted + link JE
            \db_update('acc_depreciation_runs', [
                'status'           => 'posted',
                'journal_entry_id' => $entry['id'],
            ], 'id = ?', [$runId]);

            // Audit
            self::audit(
                $userId,
                'status_change',
                'depreciation_run',
                $runId,
                "DEP-RUN-{$runId} ({$period['name']})",
                "Posted depreciation run for {$period['name']}: " .
                count($rawLines) . " assets, total \${$run['total_depreciation']}, JE #{$entry['id']}"
            );

            return \db_row("SELECT * FROM acc_depreciation_runs WHERE id = ?", [$runId]);
        });
    }

    // ============================================================
    // REVERSE A POSTED DEPRECIATION RUN
    // Spec: §8 — accountants must be able to undo a posted run if a
    // mistake is found during close, *before* the period is locked.
    // The reversal swaps DR/CR via JournalEntryService::reverse() and
    // restores each asset's accumulated_depreciation / NBV.
    // ============================================================

    /**
     * Reverse a previously-posted depreciation run.
     *
     * Steps (all inside one transaction):
     *   1. Lock the run row, validate status='posted'.
     *   2. Validate that the period is not locked (cannot reverse a locked period).
     *   3. Reverse the linked journal entry (swaps DR/CR, marks original as 'reversed').
     *   4. For each line: subtract the depreciation amount from the asset's
     *      accumulated_depreciation and add it back to net_book_value.
     *   5. Restore status to 'active' if the asset was marked 'fully_depreciated'
     *      by this very run (closing NBV had previously hit salvage).
     *   6. Mark the run row status='reversed'.
     *   7. Audit log.
     *
     * @throws \RuntimeException on bad state, locked period, or missing JE
     */
    public static function reverseRun(int $runId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($runId, $userId) {
            $run = \db_row("SELECT * FROM acc_depreciation_runs WHERE id = ? FOR UPDATE", [$runId]);
            if (!$run) throw new \RuntimeException('Depreciation run not found.');
            if ($run['status'] !== 'posted') {
                throw new \RuntimeException("Cannot reverse — run is currently {$run['status']}.");
            }
            if (!$run['journal_entry_id']) {
                throw new \RuntimeException('Posted run has no linked journal entry.');
            }

            // Block reversal if the period is locked (locked = month-end closed).
            // Closed periods can still be reversed by an accountant; locked
            // periods cannot — re-open the period first.
            $period = \db_row("SELECT * FROM acc_periods WHERE id = ?", [$run['period_id']]);
            if ($period && $period['status'] === 'locked') {
                throw new \RuntimeException(
                    "Cannot reverse — period {$period['name']} is locked. " .
                    "Re-open the period in Accounting → Periods first."
                );
            }

            // 1. Reverse the JE
            $reversal = JournalEntryService::reverse(
                (int) $run['journal_entry_id'],
                date('Y-m-d'),
                $userId
            );

            // 2. Restore each asset's NBV
            $lines = \db_select(
                "SELECT * FROM acc_depreciation_run_lines WHERE run_id = ?",
                [$runId]
            );
            foreach ($lines as $l) {
                $asset = \db_row(
                    "SELECT * FROM acc_fixed_assets WHERE id = ? FOR UPDATE",
                    [$l['asset_id']]
                );
                if (!$asset) continue;

                $newAccum = bcsub((string) $asset['accumulated_depreciation'], (string) $l['depreciation'], 2);
                if (bccomp($newAccum, '0.00', 2) < 0) $newAccum = '0.00';
                $newNbv = bcsub((string) $asset['acquisition_cost'], $newAccum, 2);

                $update = [
                    'accumulated_depreciation' => $newAccum,
                    'net_book_value'           => $newNbv,
                ];

                // If this run had pushed the asset to 'fully_depreciated' on its
                // very last cycle, restore it to 'active'. We detect this by:
                // the closing_nbv on the line equalled salvage_value, and the
                // asset is currently fully_depreciated.
                if (
                    $asset['status'] === 'fully_depreciated' &&
                    bccomp((string) $l['closing_nbv'], (string) $asset['salvage_value'], 2) === 0
                ) {
                    $update['status']                = 'active';
                    $update['fully_depreciated_date']= null;
                }

                // Roll back units_used_to_date for UOP
                if ($l['method_used'] === 'units_of_production') {
                    $detail = json_decode($l['calculation_detail'] ?? '{}', true) ?? [];
                    $units  = (int) ($detail['units_used'] ?? 0);
                    if ($units > 0) {
                        \db_execute(
                            "UPDATE acc_fixed_assets
                             SET units_used_to_date = GREATEST(0, units_used_to_date - ?)
                             WHERE id = ?",
                            [$units, $l['asset_id']]
                        );
                    }
                }

                \db_update('acc_fixed_assets', $update, 'id = ?', [$l['asset_id']]);
            }

            // 3. Mark run reversed
            \db_update('acc_depreciation_runs', [
                'status' => 'reversed',
                'notes'  => trim(($run['notes'] ?? '') . "\nReversed " . date('c') . " by user #{$userId}, reversal JE #{$reversal['id']}"),
            ], 'id = ?', [$runId]);

            // 4. Audit
            self::audit(
                $userId,
                'status_change',
                'depreciation_run',
                $runId,
                "DEP-RUN-{$runId} ({$period['name']})",
                "Reversed depreciation run for {$period['name']}: " .
                count($lines) . " assets, total \${$run['total_depreciation']}, reversal JE #{$reversal['id']}"
            );

            return [
                'run'         => \db_row("SELECT * FROM acc_depreciation_runs WHERE id = ?", [$runId]),
                'reversal_je' => $reversal,
            ];
        });
    }

    // ============================================================
    // DISPOSAL — sale / scrap / write-off / trade-in
    // ============================================================

    /**
     * Dispose of an asset.
     *
     * Posts:
     *   DR  Cash                          [proceeds]
     *   DR  Accumulated depreciation       [accumulated_depreciation]
     *   DR  Loss on Disposal               [if NBV > proceeds]
     *     CR  Asset Cost                   [acquisition_cost]
     *     CR  Gain on Disposal             [if proceeds > NBV]
     *
     * @param array    $data {asset_id, disposal_date, disposal_type, proceeds,
     *                        proceeds_account_id?, gain_loss_account_id?,
     *                        buyer_name?, notes?, requires_manager?}
     * @param int|null $userId
     * @param string   $userRoleSlug   slug of the calling user's role
     */
    public static function dispose(array $data, ?int $userId = null, string $userRoleSlug = ''): array
    {
        $required = ['asset_id', 'disposal_date', 'disposal_type', 'proceeds'];
        foreach ($required as $k) {
            if (!isset($data[$k])) throw new \RuntimeException("$k is required.");
        }

        // Accountant+ guard (accountant, manager, super_admin allowed)
        // WHY: §8 — disposal is part of the close cycle owned by the
        // accounting team. Managers approve CapEx, but accountants book
        // the disposal.
        $allowedRoles = ['super_admin', 'manager', 'accountant'];
        if (!in_array($userRoleSlug, $allowedRoles, true)) {
            throw new \RuntimeException('Only accountants and managers can record an asset disposal.');
        }

        return \db_transaction(function () use ($data, $userId) {
            $asset = \db_row(
                "SELECT * FROM acc_fixed_assets WHERE id = ? FOR UPDATE",
                [(int) $data['asset_id']]
            );
            if (!$asset) throw new \RuntimeException('Asset not found.');
            if ($asset['status'] === 'disposed') throw new \RuntimeException('Asset is already disposed.');

            $proceeds = self::money($data['proceeds']);
            $cost     = (string) $asset['acquisition_cost'];
            $accum    = (string) $asset['accumulated_depreciation'];
            $nbv      = bcsub($cost, $accum, 2);
            // gain = proceeds - NBV ; positive => gain ; negative => loss
            $gainLoss = bcsub($proceeds, $nbv, 2);

            // Resolve accounts
            $cashAcctId = (int) ($data['proceeds_account_id']
                ?? AccountingService::setting('accounting.default_cash_account_id'));
            if (!$cashAcctId) throw new \RuntimeException('No cash account configured for proceeds.');

            // Default gain → 7010, loss → 7020
            $gainLossAcctId = (int) ($data['gain_loss_account_id']
                ?? (bccomp($gainLoss, '0.00', 2) >= 0
                    ? self::accountIdByCode('7010')   // Gain
                    : self::accountIdByCode('7020'))); // Loss
            if (!$gainLossAcctId) throw new \RuntimeException('No gain/loss account configured.');

            // Build JE lines
            $lines = [];
            // Cash DR (only if proceeds > 0)
            if (bccomp($proceeds, '0.00', 2) > 0) {
                $lines[] = ['account_id' => $cashAcctId, 'debit' => $proceeds, 'credit' => '0.00',
                            'description' => "Proceeds — {$asset['name']}"];
            }
            // Accumulated depreciation DR
            if (bccomp($accum, '0.00', 2) > 0) {
                $lines[] = ['account_id' => (int) $asset['accum_depr_account_id'], 'debit' => $accum, 'credit' => '0.00',
                            'description' => "Reverse accumulated depr — {$asset['name']}"];
            }
            // Asset cost CR
            $lines[] = ['account_id' => (int) $asset['asset_account_id'], 'debit' => '0.00', 'credit' => $cost,
                        'description' => "Remove asset cost — {$asset['name']}"];

            // Gain or Loss
            if (bccomp($gainLoss, '0.00', 2) > 0) {
                // gain = CR
                $lines[] = ['account_id' => $gainLossAcctId, 'debit' => '0.00', 'credit' => $gainLoss,
                            'description' => "Gain on disposal — {$asset['name']}"];
            } elseif (bccomp($gainLoss, '0.00', 2) < 0) {
                // loss = DR (positive number)
                $loss = bcmul($gainLoss, '-1', 2);
                $lines[] = ['account_id' => $gainLossAcctId, 'debit' => $loss, 'credit' => '0.00',
                            'description' => "Loss on disposal — {$asset['name']}"];
            }

            $entry = JournalEntryService::create([
                'entry_date'      => $data['disposal_date'],
                'description'     => "Disposal of {$asset['asset_number']} — {$asset['name']}",
                'entry_type'      => 'system',
                'source_type'     => 'asset_disposal',
                'reference'       => "DISP-{$asset['asset_number']}",
                'post_immediately'=> true,
            ], $lines, $userId);

            $disposalId = \db_insert('acc_asset_disposals', [
                'asset_id'                  => (int) $asset['id'],
                'disposal_date'             => $data['disposal_date'],
                'disposal_type'             => $data['disposal_type'],
                'proceeds'                  => $proceeds,
                'net_book_value_at_disposal'=> $nbv,
                'gain_loss'                 => $gainLoss,
                'proceeds_account_id'       => $cashAcctId,
                'gain_loss_account_id'      => $gainLossAcctId,
                'journal_entry_id'          => (int) $entry['id'],
                'buyer_name'                => $data['buyer_name'] ?? null,
                'buyer_reference'           => $data['buyer_reference'] ?? null,
                'notes'                     => $data['notes'] ?? null,
                'created_by'                => $userId,
            ]);

            // Link the JE back so the depreciation run row also matches
            \db_update('acc_journal_entries',
                ['source_id' => $disposalId],
                'id = ?', [$entry['id']]
            );

            \db_update('acc_fixed_assets', [
                'status'         => 'disposed',
                'net_book_value' => '0.00',
            ], 'id = ?', [$asset['id']]);

            self::audit(
                $userId,
                'status_change',
                'fixed_asset',
                (int) $asset['id'],
                "{$asset['asset_number']} {$asset['name']}",
                "Disposed ({$data['disposal_type']}) on {$data['disposal_date']}: " .
                "proceeds \${$proceeds}, NBV \${$nbv}, gain/loss \${$gainLoss}, JE #{$entry['id']}"
            );

            return \db_row("SELECT * FROM acc_asset_disposals WHERE id = ?", [$disposalId]);
        });
    }

    // ============================================================
    // IMPAIRMENT
    // Posts: DR Impairment Loss / CR Accumulated Depreciation
    // Spec ref §16: 7020 used for impairment loss
    // ============================================================

    public static function impair(array $data, ?int $userId = null, string $userRoleSlug = ''): array
    {
        $required = ['asset_id', 'impairment_date', 'impairment_loss', 'reason'];
        foreach ($required as $k) {
            if (!isset($data[$k]) || $data[$k] === '' || $data[$k] === null) {
                throw new \RuntimeException("$k is required.");
            }
        }
        // Accountant+ guard. Same reasoning as dispose() — impairment is an
        // accounting-team action even when triggered by a business event.
        $allowedRoles = ['super_admin', 'manager', 'accountant'];
        if (!in_array($userRoleSlug, $allowedRoles, true)) {
            throw new \RuntimeException('Only accountants and managers can record an impairment.');
        }

        return \db_transaction(function () use ($data, $userId) {
            $asset = \db_row("SELECT * FROM acc_fixed_assets WHERE id = ? FOR UPDATE", [(int) $data['asset_id']]);
            if (!$asset) throw new \RuntimeException('Asset not found.');
            if ($asset['status'] === 'disposed') throw new \RuntimeException('Asset is disposed.');

            $loss = self::money($data['impairment_loss']);
            if (bccomp($loss, '0.00', 2) <= 0) {
                throw new \RuntimeException('impairment_loss must be greater than zero.');
            }

            $preNbv = bcsub((string) $asset['acquisition_cost'], (string) $asset['accumulated_depreciation'], 2);
            if (bccomp($loss, $preNbv, 2) > 0) {
                throw new \RuntimeException('impairment_loss cannot exceed pre-impairment NBV.');
            }
            $recoverable = bcsub($preNbv, $loss, 2);

            $impLossAcctId = self::accountIdByCode('7020');
            if (!$impLossAcctId) throw new \RuntimeException('Impairment loss account (7020) not found.');

            $lines = [
                ['account_id' => $impLossAcctId,                       'debit' => $loss, 'credit' => '0.00',
                 'description' => "Impairment loss — {$asset['name']}"],
                ['account_id' => (int) $asset['accum_depr_account_id'],'debit' => '0.00','credit' => $loss,
                 'description' => "Accumulated impairment — {$asset['name']}"],
            ];

            $entry = JournalEntryService::create([
                'entry_date'      => $data['impairment_date'],
                'description'     => "Impairment of {$asset['asset_number']} — {$asset['name']}",
                'entry_type'      => 'system',
                // S-QBO-22 / D-QBO-22-2: dedicated 'impairment' ENUM value
                //   added to acc_journal_entries.source_type via
                //   202605302200_S-QBO-22.sql. Replaces prior "closest enum
                //   match" workaround of asset_disposal. Existing pre-S-QBO-22
                //   impairment JEs keep source_type='asset_disposal' for
                //   audit-trail preservation (NOT backfilled). NEW
                //   impairments use the clean value. Flows through
                //   JournalEntryPusher as a non-bridge-derived push.
                'source_type'     => 'impairment',
                'reference'       => "IMP-{$asset['asset_number']}",
                'post_immediately'=> true,
            ], $lines, $userId);

            $impId = \db_insert('acc_asset_impairments', [
                'asset_id'           => (int) $asset['id'],
                'impairment_date'    => $data['impairment_date'],
                'pre_impairment_nbv' => $preNbv,
                'recoverable_amount' => $recoverable,
                'impairment_loss'    => $loss,
                'reason'             => $data['reason'],
                'journal_entry_id'   => (int) $entry['id'],
                'created_by'         => $userId,
            ]);

            // NBV down by loss; accumulated depr up by loss; mark impaired
            $newAccum = bcadd((string) $asset['accumulated_depreciation'], $loss, 2);
            $newNbv   = bcsub((string) $asset['acquisition_cost'], $newAccum, 2);

            // WHY 'impaired' instead of 'active': the asset stays on the books
            // but at a lower carrying value. Subsequent depreciation runs SHOULD
            // continue from the new NBV — calculateForPeriod() skips when status
            // != 'active' (line 283), so we need to reset to 'active' for SL/UoP
            // assets so they keep depreciating. For declining_balance the NEW
            // UCC (cost - accum) is automatically used in year-2+ formula, so
            // the math works correctly with 'active' status. We use 'impaired'
            // only as a marker for the period it was impaired in — actually we
            // restore to 'active' so depreciation continues. The acc_asset_
            // impairments row remains the historical record.
            \db_update('acc_fixed_assets', [
                'accumulated_depreciation' => $newAccum,
                'net_book_value'           => $newNbv,
                'depreciable_cost'         => bcsub($newNbv, (string) $asset['salvage_value'], 2),
                'status'                   => 'active',
            ], 'id = ?', [$asset['id']]);

            self::audit(
                $userId,
                'status_change',
                'fixed_asset',
                (int) $asset['id'],
                "{$asset['asset_number']} {$asset['name']}",
                "Impaired on {$data['impairment_date']}: pre-NBV \${$preNbv}, " .
                "loss \${$loss}, recoverable \${$recoverable}, JE #{$entry['id']}. Reason: {$data['reason']}"
            );

            return \db_row("SELECT * FROM acc_asset_impairments WHERE id = ?", [$impId]);
        });
    }

    // ============================================================
    // CAPEX REQUESTS
    // ============================================================

    public static function createCapex(array $data, ?int $userId = null): array
    {
        foreach (['title', 'asset_class', 'budget_amount'] as $k) {
            if (empty($data[$k])) throw new \RuntimeException("$k is required.");
        }
        if (bccomp(self::money($data['budget_amount']), '0.00', 2) <= 0) {
            throw new \RuntimeException('budget_amount must be greater than zero.');
        }

        return \db_transaction(function () use ($data, $userId) {
            $year   = date('Y');
            $number = self::nextCapexNumber($year);

            $id = \db_insert('acc_capex_requests', [
                'request_number' => $number,
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'asset_class'    => $data['asset_class'],
                'budget_amount'  => self::money($data['budget_amount']),
                'status'         => 'pending_approval',
                'work_order_id'  => $data['work_order_id'] ?? null,
                'vendor_id'      => $data['vendor_id'] ?? null,
                'requested_by'   => $userId,
                'justification'  => $data['justification'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);

            self::audit(
                $userId,
                'create',
                'capex_request',
                $id,
                "{$number} {$data['title']}",
                "Created CapEx request: budget \${$data['budget_amount']}, class {$data['asset_class']}"
            );

            return \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$id]);
        });
    }

    public static function approveCapex(int $id, ?int $userId = null, string $userRoleSlug = ''): array
    {
        if (!in_array($userRoleSlug, ['super_admin', 'manager'], true)) {
            throw new \RuntimeException('Only managers can approve a CapEx request.');
        }
        return \db_transaction(function () use ($id, $userId) {
            $row = \db_row("SELECT * FROM acc_capex_requests WHERE id = ? FOR UPDATE", [$id]);
            if (!$row) throw new \RuntimeException('CapEx request not found.');
            if ($row['status'] !== 'pending_approval') {
                throw new \RuntimeException("Cannot approve — current status is {$row['status']}.");
            }
            \db_update('acc_capex_requests', [
                'status'       => 'approved',
                'approved_by'  => $userId,
                'approved_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            self::audit(
                $userId,
                'status_change',
                'capex_request',
                $id,
                "{$row['request_number']} {$row['title']}",
                "Approved CapEx request (was pending_approval)"
            );

            return \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$id]);
        });
    }

    public static function startCapex(int $id, ?int $userId = null): array
    {
        return \db_transaction(function () use ($id, $userId) {
            $row = \db_row("SELECT * FROM acc_capex_requests WHERE id = ? FOR UPDATE", [$id]);
            if (!$row) throw new \RuntimeException('CapEx request not found.');
            if ($row['status'] !== 'approved') {
                throw new \RuntimeException("Cannot start — must be approved first (current: {$row['status']}).");
            }
            \db_update('acc_capex_requests', ['status' => 'in_progress'], 'id = ?', [$id]);

            self::audit(
                $userId,
                'status_change',
                'capex_request',
                $id,
                "{$row['request_number']} {$row['title']}",
                "Started CapEx request (was approved)"
            );

            return \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$id]);
        });
    }

    /**
     * Mark CapEx complete and (optionally) link to a newly-created asset.
     * If asset_data is provided, creates the asset and links it.
     */
    public static function completeCapex(int $id, array $payload, ?int $userId = null): array
    {
        return \db_transaction(function () use ($id, $payload, $userId) {
            $row = \db_row("SELECT * FROM acc_capex_requests WHERE id = ? FOR UPDATE", [$id]);
            if (!$row) throw new \RuntimeException('CapEx request not found.');
            if (!in_array($row['status'], ['approved', 'in_progress'], true)) {
                throw new \RuntimeException("Cannot complete — current status is {$row['status']}.");
            }

            $assetId = $payload['asset_id'] ?? null;
            if (!$assetId && !empty($payload['asset_data'])) {
                $asset = self::create($payload['asset_data'], $userId);
                $assetId = $asset['id'];
            }

            \db_update('acc_capex_requests', [
                'status'        => 'completed',
                'completed_by'  => $userId,
                'completed_at'  => date('Y-m-d H:i:s'),
                'actual_amount' => isset($payload['actual_amount']) ? self::money($payload['actual_amount']) : $row['budget_amount'],
                'asset_id'      => $assetId,
            ], 'id = ?', [$id]);

            self::audit(
                $userId,
                'status_change',
                'capex_request',
                $id,
                "{$row['request_number']} {$row['title']}",
                "Completed CapEx request" . ($assetId ? " — linked to asset #{$assetId}" : "")
            );

            return \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$id]);
        });
    }

    // ============================================================
    // REPORTS / QUERIES
    // ============================================================

    public static function listAssets(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['asset_class'])) {
            $where[] = 'asset_class = ?';
            $params[] = $filters['asset_class'];
        }
        if (!empty($filters['equipment_unit_id'])) {
            $where[] = 'equipment_unit_id = ?';
            $params[] = (int) $filters['equipment_unit_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE ? OR asset_number LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        return \db_select(
            "SELECT * FROM acc_fixed_assets WHERE " . implode(' AND ', $where) . " ORDER BY asset_number",
            $params
        );
    }

    /**
     * Return the depreciation schedule for an asset over its full life
     * (every period from depreciation_start_date until fully depreciated
     * or up to a max-rows safety cap).
     */
    public static function depreciationSchedule(int $assetId, int $maxRows = 600): array
    {
        $asset = \db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$assetId]);
        if (!$asset) return [];

        // Use a synthetic period array for forecasting (no DB writes)
        $start = new \DateTimeImmutable($asset['depreciation_start_date']);
        $sim   = $asset; // mutable copy
        $rows  = [];
        for ($i = 0; $i < $maxRows; $i++) {
            $month = $start->modify("+{$i} months");
            $endOfMonth = $month->modify('last day of this month');
            $period = [
                'name'       => $month->format('F Y'),
                'year'       => (int) $month->format('Y'),
                'month'      => (int) $month->format('n'),
                'start_date' => $month->format('Y-m-01'),
                'end_date'   => $endOfMonth->format('Y-m-d'),
            ];
            $calc = self::calculateForPeriod($sim, $period);
            if (bccomp($calc['amount'], '0.00', 2) === 0) break;

            $rows[] = [
                'period_name' => $period['name'],
                'opening_nbv' => $calc['opening_nbv'],
                'amount'      => $calc['amount'],
                'closing_nbv' => $calc['closing_nbv'],
            ];
            // Mutate sim for the next pass
            $sim['accumulated_depreciation'] = bcadd($sim['accumulated_depreciation'], $calc['amount'], 2);
            $sim['net_book_value']           = $calc['closing_nbv'];
            if (bccomp($sim['net_book_value'], $sim['salvage_value'], 2) <= 0) break;
        }
        return $rows;
    }

    // ============================================================
    // INTERNAL HELPERS
    // ============================================================

    /** Normalize an incoming string/number to a 2-decimal bcmath string. */
    private static function money(mixed $v): string
    {
        if ($v === null || $v === '') return '0.00';
        return bcadd((string) $v, '0.00', 2);
    }

    /**
     * Half-up round a bcmath string to 2 decimals.
     * WHY: bcdiv truncates instead of rounding. Without this, 14500/60
     * becomes 241.66 instead of the accountant-expected 241.67.
     */
    private static function roundMoney(string $v): string
    {
        $neg = bccomp($v, '0', 4) < 0;
        $abs = $neg ? bcmul($v, '-1', 8) : $v;
        $rounded = bcadd($abs, '0.005', 2); // adding then truncating == half-up
        return $neg ? bcmul($rounded, '-1', 2) : $rounded;
    }

    /** Resolve an account code to its id. */
    private static function accountIdByCode(string $code): ?int
    {
        $row = \db_row("SELECT id FROM acc_accounts WHERE code = ? AND is_active = 1", [$code]);
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Insert an audit_log row for a fixed-asset event.
     *
     * Centralized to keep the call sites readable. The audit_log.action is
     * an ENUM (create/update/delete/restore/login/logout/export/status_change/
     * view/bulk_action/payment_recorded/invoice_sent/invoice_voided/
     * lease_closed/cron) so callers MUST pass one of those values.
     *
     * @param int|null $userId
     * @param string $action  must be a valid audit_log.action ENUM value
     * @param string $entityType  e.g. 'fixed_asset', 'depreciation_run', 'capex_request'
     * @param int $entityId
     * @param string $entityLabel  human-friendly label (e.g. asset number + name)
     * @param string $notes  free-text describing the event
     */
    private static function audit(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        string $entityLabel,
        string $notes
    ): void {
        // audit_log.user_name is NOT NULL (DEFAULT 'system'), and db_insert sends
        // the column explicitly — so a null here overrides the default and throws
        // 1048. Web callers always pass current_user_id(), which is why this only
        // ever bit CLI/system callers that legitimately have no user.
        $userName = 'system';
        if ($userId) {
            $u = \db_row("SELECT name FROM users WHERE id = ?", [$userId]);
            if ($u) $userName = $u['name'];
        }
        \db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => $action,
            'module'       => 'accounting',
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_label' => mb_substr($entityLabel, 0, 255),
            'notes'        => $notes,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 500),
        ]);
    }

    // ============================================================
    // CAPEX — WORK ORDER FLAGGING
    //
    // §8 / §16: Work orders whose total_cost exceeds the configured
    // accounting.capex_threshold_cad must be reviewed by an accountant
    // who decides whether to capitalize the spend (creates a fixed
    // asset) or expense it as repairs (no asset, the WO bill stays in
    // 6190 Repairs & Maintenance).
    //
    // We don't add a new "flagged_work_orders" table — instead we use
    // a SQL projection joining maintenance_work_orders with the existing
    // acc_capex_requests table. A WO is "flagged" if it's completed,
    // total_cost > threshold, and there is NO existing capex_requests row
    // pointing back to it.
    // ============================================================

    /**
     * Return work orders that have crossed the capex threshold but are not
     * yet linked to an acc_capex_requests row (i.e. not yet reviewed).
     *
     * @return array each row contains the WO data plus computed fields
     */
    public static function listFlaggedWorkOrders(): array
    {
        $threshold = (string) (AccountingService::setting('accounting.capex_threshold_cad', '2500.00'));

        return \db_select(
            "SELECT wo.id,
                    wo.work_order_number,
                    wo.title,
                    wo.work_type,
                    wo.equipment_unit_id,
                    wo.vendor_id,
                    wo.completed_date,
                    wo.total_cost,
                    eu.unit_number,
                    eb.label AS brand,
                    et.model,
                    v.name AS vendor_name
             FROM maintenance_work_orders wo
             LEFT JOIN equipment_units eu     ON eu.id = wo.equipment_unit_id
             LEFT JOIN equipment_templates et ON et.id = eu.template_id
             LEFT JOIN equipment_brands eb    ON eb.id = eu.brand_id
             LEFT JOIN vendors v              ON v.id  = wo.vendor_id
             LEFT JOIN acc_capex_requests cr  ON cr.work_order_id = wo.id
             WHERE wo.deleted_at IS NULL
               AND wo.status = 'completed'
               AND wo.total_cost > ?
               AND cr.id IS NULL
             ORDER BY wo.completed_date DESC, wo.id DESC",
            [$threshold]
        );
    }

    /**
     * Capitalize a flagged work order: create an acc_capex_requests row
     * (status='completed', already approved+complete since the spend
     * happened), and create the linked acc_fixed_assets row.
     *
     * @param int   $woId     the maintenance_work_orders.id
     * @param array $payload  asset_data (name, asset_class, depreciation_method,
     *                        useful_life_years OR cra_cca_rate, salvage_value,
     *                        asset_account_id, accum_depr_account_id,
     *                        depr_expense_account_id, depreciation_start_date)
     */
    public static function capitalizeFlaggedWorkOrder(int $woId, array $payload, ?int $userId = null, string $userRoleSlug = ''): array
    {
        $allowed = ['super_admin', 'manager', 'accountant'];
        if (!in_array($userRoleSlug, $allowed, true)) {
            throw new \RuntimeException('Only accountants and managers can capitalize a work order.');
        }

        return \db_transaction(function () use ($woId, $payload, $userId) {
            $wo = \db_row("SELECT * FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL", [$woId]);
            if (!$wo) throw new \RuntimeException('Work order not found.');
            if ($wo['status'] !== 'completed') {
                throw new \RuntimeException('Only completed work orders can be capitalized.');
            }

            // Refuse if there's already a capex_requests row for this WO
            $existing = \db_row(
                "SELECT id FROM acc_capex_requests WHERE work_order_id = ?",
                [$woId]
            );
            if ($existing) throw new \RuntimeException("Work order already has CapEx review #{$existing['id']}.");

            // Build the asset row
            $assetData = array_merge([
                'name'                  => $wo['title'] ?: ('WO ' . $wo['work_order_number']),
                'description'           => "Capitalized from work order {$wo['work_order_number']}",
                'asset_class'           => 'fleet_equipment',
                'equipment_unit_id'     => $wo['equipment_unit_id'],
                'acquisition_date'      => $wo['completed_date'] ?: date('Y-m-d'),
                'acquisition_cost'      => $wo['total_cost'],
                'vendor_id'             => $wo['vendor_id'],
                'depreciation_method'   => 'straight_line',
                'useful_life_years'     => 5,
                'salvage_value'         => '0.00',
            ], $payload);
            $asset = self::create($assetData, $userId);

            // Create the capex row in completed state
            $crId = \db_insert('acc_capex_requests', [
                'request_number' => self::nextCapexNumber(date('Y')),
                'title'          => $assetData['name'],
                'description'    => "Auto-capitalized from WO {$wo['work_order_number']}",
                'asset_class'    => $assetData['asset_class'],
                'budget_amount'  => $wo['total_cost'],
                'actual_amount'  => $wo['total_cost'],
                'status'         => 'completed',
                'work_order_id'  => $woId,
                'asset_id'       => $asset['id'],
                'vendor_id'      => $wo['vendor_id'],
                'requested_by'   => $userId,
                'approved_by'    => $userId,
                'approved_at'    => date('Y-m-d H:i:s'),
                'completed_by'   => $userId,
                'completed_at'   => date('Y-m-d H:i:s'),
                'justification'  => "Capitalized — total_cost \${$wo['total_cost']} > capex threshold",
            ]);

            self::audit(
                $userId,
                'create',
                'capex_request',
                $crId,
                "WO {$wo['work_order_number']} → {$asset['asset_number']}",
                "Capitalized work order {$wo['work_order_number']} (\${$wo['total_cost']}) " .
                "into asset {$asset['asset_number']}"
            );

            return [
                'capex_request' => \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$crId]),
                'asset'         => $asset,
            ];
        });
    }

    /**
     * Mark a flagged WO as expense (no asset, no JE). We still write a
     * capex_requests row in 'rejected' state so the WO disappears from
     * the flagged list and we have an audit trail of the decision.
     */
    public static function expenseFlaggedWorkOrder(int $woId, string $reason, ?int $userId = null, string $userRoleSlug = ''): array
    {
        $allowed = ['super_admin', 'manager', 'accountant'];
        if (!in_array($userRoleSlug, $allowed, true)) {
            throw new \RuntimeException('Only accountants and managers can review CapEx flags.');
        }
        if (trim($reason) === '') {
            throw new \RuntimeException('Reason is required when expensing a flagged work order.');
        }

        return \db_transaction(function () use ($woId, $reason, $userId) {
            $wo = \db_row("SELECT * FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL", [$woId]);
            if (!$wo) throw new \RuntimeException('Work order not found.');

            $existing = \db_row(
                "SELECT id FROM acc_capex_requests WHERE work_order_id = ?",
                [$woId]
            );
            if ($existing) throw new \RuntimeException("Work order already has CapEx review #{$existing['id']}.");

            $crId = \db_insert('acc_capex_requests', [
                'request_number'   => self::nextCapexNumber(date('Y')),
                'title'            => $wo['title'] ?: ('WO ' . $wo['work_order_number']),
                'description'      => "Reviewed and expensed from WO {$wo['work_order_number']}",
                'asset_class'      => 'fleet_equipment',
                'budget_amount'    => $wo['total_cost'],
                'actual_amount'    => $wo['total_cost'],
                'status'           => 'rejected',
                'work_order_id'    => $woId,
                'vendor_id'        => $wo['vendor_id'],
                'requested_by'     => $userId,
                'approved_by'      => $userId,
                'approved_at'      => date('Y-m-d H:i:s'),
                'rejected_reason'  => $reason,
                'justification'    => "Expensed — does not meet capitalization criteria",
            ]);

            self::audit(
                $userId,
                'status_change',
                'capex_request',
                $crId,
                "WO {$wo['work_order_number']} expensed",
                "Flagged work order {$wo['work_order_number']} (\${$wo['total_cost']}) " .
                "expensed (not capitalized). Reason: {$reason}"
            );

            return \db_row("SELECT * FROM acc_capex_requests WHERE id = ?", [$crId]);
        });
    }

    // ── S-ACCT-COMP — PP&E Componentization (ASPE 3061.18) ─────────────────

    /**
     * Fetch a parent asset row with its component children + a roll-up
     * total_nbv (parent NBV + sum of all component NBVs). Used by the asset
     * show endpoint and the asset schedule report.
     *
     * @param int $assetId  Parent asset id.
     * @return array { ...parent_row, components: [...], component_count: int,
     *                 total_nbv: bcmath string }
     */
    public static function getWithComponents(int $assetId): array
    {
        $parent = \db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$assetId]);
        if (!$parent) {
            throw new \RuntimeException('Asset not found.');
        }

        $components = \db_select(
            "SELECT id, asset_number, name, asset_class, acquisition_date,
                    available_for_use_date, acquisition_cost, depreciable_cost,
                    accumulated_depreciation, net_book_value, salvage_value,
                    useful_life_years, depreciation_method, status,
                    cca_class_id, is_aiip_eligible
               FROM acc_fixed_assets
              WHERE parent_asset_id = ?
              ORDER BY id ASC",
            [$assetId]
        );

        $totalNbv = (string) ($parent['net_book_value'] ?? '0.00');
        foreach ($components as $c) {
            $totalNbv = bcadd($totalNbv, (string) ($c['net_book_value'] ?? '0.00'), 2);
        }

        $parent['components']      = $components;
        $parent['component_count'] = count($components);
        $parent['total_nbv']       = $totalNbv;
        return $parent;
    }

    /**
     * Sum NBV for parent + all component children. Returns '0.00' when the
     * asset has no children (caller can fall back to parent.net_book_value).
     */
    public static function getParentNbv(int $parentAssetId): string
    {
        $row = \db_row(
            "SELECT COALESCE(SUM(net_book_value), 0) AS total
               FROM acc_fixed_assets
              WHERE id = ? OR parent_asset_id = ?",
            [$parentAssetId, $parentAssetId]
        );
        return (string) ($row['total'] ?? '0.00');
    }

    /**
     * Capitalize a betterment to a fixed asset (ASPE 3061.14). Adds $amount
     * to acquisition_cost and recomputes depreciable_cost (= acquisition_cost
     * − salvage_value). Does NOT directly touch net_book_value or
     * accumulated_depreciation — depreciation runs handle those.
     *
     * Remaining-life calculation note: spec referenced useful_life_months
     * but the on-disk column is `useful_life_years` (K-22 trap). For
     * straight-line assets the existing previewRun()/calculateForPeriod()
     * path will pick up the new depreciable_cost on the next run and amortize
     * over remaining life automatically — no per-asset manual recompute
     * needed here.
     *
     * Optional bill_line_id parameter: when provided, the caller already
     * marked acc_bill_lines.capitalize=1 + betterment_note=$note. We just
     * cite it in the audit-log note.
     *
     * @throws \RuntimeException when amount ≤ 0, asset disposed, or the
     *         new depreciable_cost would go negative (salvage > new cost).
     */
    public static function capitalize(
        int $assetId,
        string $amount,
        ?int $userId = null,
        string $note = '',
        ?int $billLineId = null
    ): array {
        $amount = self::money($amount);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new \RuntimeException('Betterment amount must be greater than zero.');
        }
        if (trim($note) === '') {
            throw new \RuntimeException('Betterment note is required (ASPE 3061.14 justification).');
        }

        return \db_transaction(function () use ($assetId, $amount, $userId, $note, $billLineId) {
            $asset = \db_row("SELECT * FROM acc_fixed_assets WHERE id = ? FOR UPDATE", [$assetId]);
            if (!$asset) {
                throw new \RuntimeException('Asset not found.');
            }
            if ($asset['status'] === 'disposed') {
                throw new \RuntimeException('Cannot capitalize betterment on a disposed asset.');
            }

            $oldCost     = (string) $asset['acquisition_cost'];
            $newCost     = bcadd($oldCost, $amount, 2);
            $salvage     = (string) $asset['salvage_value'];
            $newDeprBase = bcsub($newCost, $salvage, 2);

            // STOP CONDITION: never let the new depreciable_cost go negative.
            if (bccomp($newDeprBase, '0.00', 2) < 0) {
                throw new \RuntimeException(
                    'Betterment amount would produce negative depreciable cost. '
                    . 'Check salvage value configuration for this asset.'
                );
            }

            \db_update('acc_fixed_assets', [
                'acquisition_cost' => $newCost,
                'depreciable_cost' => $newDeprBase,
                'updated_at'       => date('Y-m-d H:i:s'),
            ], 'id = ?', [$assetId]);

            $billRef = $billLineId ? " Bill line #{$billLineId}." : '';
            self::audit(
                $userId,
                'update',
                'fixed_asset',
                $assetId,
                "{$asset['asset_number']} {$asset['name']}",
                "Betterment capitalized: +\${$amount} (cost {$oldCost} → {$newCost})." . $billRef . " Note: {$note}"
            );

            return \db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$assetId]);
        });
    }
}
