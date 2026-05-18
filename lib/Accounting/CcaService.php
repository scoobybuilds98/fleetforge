<?php
declare(strict_types=1);

/**
 * lib/Accounting/CcaService.php
 *
 * Capital Cost Allowance (T2 Schedule 8) continuity engine per
 * ACCOUNTING_SPEC §23.3 + §23.4. Computes per-fiscal-year per-class UCC
 * roll-forward (opening → additions → dispositions → AIIP → half-year →
 * CCA → recapture → terminal loss → closing) and persists to
 * acc_cca_continuity.
 *
 * AIIP rules (S-ACCT-CCA-2, spec §23.4) applied per-asset by
 * computeAiipForClass() keyed off COALESCE(available_for_use_date,
 * acquisition_date):
 *   - Before 2018-11-20:     no AIIP, standard half-year
 *   - 2018-11-20..2023-12-31: full AIIP (1.5× multiplier), half-year suspended
 *   - 2024-01-01..2027-12-31: phase-out (no multiplier, half-year suspended)
 *   - 2028-01-01..:          no AIIP, standard half-year
 *   - Proposed 2024 FES reinstatement (when setting enabled):
 *       2025-01-01..2029-12-31 → full AIIP reinstated
 *       2030-01-01..2033-12-31 → phase-out treatment (simplified)
 *       2034-01-01..           → expired, standard half-year
 *
 * Required by: api/v1/accounting/cca/{compute,export,show,lock,aiip-detail,adjust}.php
 * Depends on: acc_cca_classes (seeded), acc_cca_continuity (per year),
 *             acc_fixed_assets.cca_class_id (set by operator),
 *             acc_asset_disposals.proceeds (per CRA on-disk column name),
 *             accounting.aiip_proposed_reinstatement_enabled (setting).
 *
 * Pre-flight column-name catches (K-22, locked in REFERENCE.md §11 Traps 16-19):
 *   - acc_fixed_assets.acquisition_cost  (not original_cost)
 *   - acc_fixed_assets.asset_class       (not asset_category)
 *   - acc_asset_disposals.proceeds       (matches spec §23.3)
 *   - acc_fixed_assets has NO deleted_at column (hard-delete only)
 *
 * Spec refs: FLEETFORGE_ACCOUNTING_SPEC.md §23.3 (Schedule 8), §23.4 (AIIP)
 * Sessions:  S-ACCT-CCA-1 (engine + Schedule 8 + admin), S-ACCT-CCA-2 (AIIP)
 */

namespace FleetForge\Accounting;

class CcaService
{
    /**
     * Entry types of acc_journal_entries — not relevant here. CCA computes
     * directly off acc_fixed_assets + acc_asset_disposals (the JE side
     * is what S030 depreciation already books).
     */

    /**
     * Compute T2 Schedule 8 continuity for $fiscalYear. Idempotent on
     * re-run when no rows for the year are locked: passing
     * $recompute=true deletes existing rows and recomputes; $recompute=false
     * returns the existing rows.
     *
     * Wrapped in db_transaction() with advisory lock ff_cca_{year} to
     * serialise concurrent compute attempts (parallel cron + manual UI).
     *
     * @param int $fiscalYear
     * @param int $userId
     * @param bool $recompute  default false — when true, deletes existing
     *                          year rows (if not locked) and recomputes.
     * @return array            { fiscal_year, rows: [continuity...], computed }
     * @throws \RuntimeException when any year row is locked, or on
     *         step-11 logic-error sanity violation (terminal loss + CCA
     *         claimed both > 0 for same class).
     */
    public static function compute(int $fiscalYear, int $userId, bool $recompute = false): array
    {
        $lockKey = "ff_cca_{$fiscalYear}";
        $got = (int) (\db_row("SELECT GET_LOCK(?, 10) AS got", [$lockKey])['got'] ?? 0);
        if ($got !== 1) {
            throw new \RuntimeException("Another CCA compute is in progress for FY{$fiscalYear}. Try again shortly.");
        }

        try {
            return \db_transaction(function () use ($fiscalYear, $userId, $recompute) {
                // ── Year-lock guard ─────────────────────────────────────────
                $existing = \db_select(
                    "SELECT id, is_locked FROM acc_cca_continuity WHERE fiscal_year = ?",
                    [$fiscalYear]
                );
                $hasLocked = false;
                foreach ($existing as $r) {
                    if ((int) $r['is_locked'] === 1) { $hasLocked = true; break; }
                }
                if ($hasLocked) {
                    throw new \RuntimeException("CCA for FY{$fiscalYear} is locked. Unlock first.");
                }
                if ($existing && !$recompute) {
                    return [
                        'fiscal_year' => $fiscalYear,
                        'rows'        => self::getSchedule($fiscalYear)['rows'],
                        'computed'    => false,
                    ];
                }
                if ($existing && $recompute) {
                    \db_execute("DELETE FROM acc_cca_continuity WHERE fiscal_year = ?", [$fiscalYear]);
                }

                // ── Iterate every active class with at least one asset, OR
                //   a prior-year closing UCC, OR a current-year disposal.
                //   This catches three real cases: (1) new additions this
                //   year, (2) carry-forward UCC from prior years, (3) only-
                //   dispositions years (which can trigger recapture / TL).
                $classes = \db_select(
                    "SELECT * FROM acc_cca_classes WHERE is_active = 1 ORDER BY class_number"
                );

                $rowsOut = [];
                foreach ($classes as $cls) {
                    $row = self::computeClass($cls, $fiscalYear, $userId);
                    if ($row === null) continue;  // Skip classes with zero activity + zero opening.

                    // STOP CONDITION (spec §23.3): CCA + terminal loss
                    // cannot both be > 0 for the same class.
                    if (bccomp($row['cca_claimed'], '0', 2) > 0
                        && bccomp($row['terminal_loss'], '0', 2) > 0) {
                        throw new \RuntimeException(
                            "CCA computation error — class {$cls['class_number']} "
                            . "has both cca_claimed and terminal_loss > 0. Logic error."
                        );
                    }

                    \db_insert('acc_cca_continuity', $row);
                    $rowsOut[] = $row;
                }

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'cca_continuity',
                    'entity_id'   => $fiscalYear,
                    'notes'       => "CCA Schedule 8 computed for FY{$fiscalYear}: " . count($rowsOut) . " class rows.",
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'fiscal_year' => $fiscalYear,
                    'rows'        => $rowsOut,
                    'computed'    => true,
                ];
            });
        } finally {
            \db_row("SELECT RELEASE_LOCK(?) AS released", [$lockKey]);
        }
    }

    /**
     * Compute one class's continuity row for $fiscalYear. Returns null when
     * the class has zero opening UCC AND zero additions AND zero dispositions
     * (no reason to emit a row).
     *
     * @return array|null  Row ready for db_insert('acc_cca_continuity', ...)
     */
    private static function computeClass(array $cls, int $fiscalYear, int $userId): ?array
    {
        $classId   = (int) $cls['id'];
        $rate      = (string) $cls['rate'];
        $halfYear  = (int) $cls['half_year_rule'] === 1;

        // Step 1 — Opening UCC from prior year's closing
        $prior = \db_row(
            "SELECT closing_ucc FROM acc_cca_continuity
              WHERE fiscal_year = ? AND cca_class_id = ?",
            [$fiscalYear - 1, $classId]
        );
        $openingUcc = $prior ? (string) $prior['closing_ucc'] : '0.00';

        // Step 2 — Cost of additions
        // Use available_for_use_date if populated; fall back to acquisition_date
        // per the STOP CONDITION (warning logged via notes column on the row).
        $additionsRow = \db_row(
            "SELECT COALESCE(SUM(acquisition_cost), 0) AS total,
                    SUM(CASE WHEN available_for_use_date IS NULL THEN 1 ELSE 0 END) AS fallback_count
               FROM acc_fixed_assets
              WHERE cca_class_id = ?
                AND YEAR(COALESCE(available_for_use_date, acquisition_date)) = ?",
            [$classId, $fiscalYear]
        );
        $additions = (string) ($additionsRow['total'] ?? '0.00');
        $fallbackCount = (int) ($additionsRow['fallback_count'] ?? 0);

        // Step 3 — Adjustments / transfers — CCA-2 scope.
        $adjustments = '0.00';

        // Step 4 — Proceeds of disposition (capped at acquisition_cost per CRA)
        // Note: acc_asset_disposals.proceeds is the on-disk column name
        // (matches spec §23.3 — no K-22 catch needed here).
        $disposalRows = \db_select(
            "SELECT d.proceeds, fa.acquisition_cost
               FROM acc_asset_disposals d
               JOIN acc_fixed_assets fa ON fa.id = d.asset_id
              WHERE fa.cca_class_id = ?
                AND YEAR(d.disposal_date) = ?",
            [$classId, $fiscalYear]
        );
        $proceeds = '0.00';
        foreach ($disposalRows as $d) {
            $capped = bccomp($d['proceeds'], $d['acquisition_cost'], 2) > 0
                ? (string) $d['acquisition_cost']
                : (string) $d['proceeds'];
            $proceeds = bcadd($proceeds, $capped, 2);
        }

        // Early-out: if everything is zero, skip emitting a row.
        if (bccomp($openingUcc, '0', 2) === 0
            && bccomp($additions, '0', 2) === 0
            && bccomp($proceeds,  '0', 2) === 0) {
            return null;
        }

        // Step 5 — UCC after additions/dispositions (signed; <0 → recapture territory)
        $uccAfter = bcadd(
            bcadd($openingUcc, $additions, 2),
            $adjustments,
            2
        );
        $uccAfter = bcsub($uccAfter, $proceeds, 2);

        // Step 6 + 7 — AIIP adjustment + half-year (S-ACCT-CCA-2)
        // computeAiipForClass returns both the aggregate AIIP adjustment and
        // the half-year adjustment because the half-year rule is suspended
        // when ANY asset in the class triggers AIIP suspension (spec §23.4).
        $aiip = self::computeAiipForClass($classId, $fiscalYear, $additions, $proceeds, $halfYear);
        $aiipAdjustment      = $aiip['aiip_adjustment'];
        $halfYearAdjustment  = $aiip['half_year_adjustment'];
        $aiipFallbackCount   = $aiip['fallback_count'];

        // Step 8 — Base amount for CCA
        $base = bcadd($uccAfter, $aiipAdjustment, 2);
        $base = bcsub($base, $halfYearAdjustment, 2);
        if (bccomp($base, '0', 2) < 0) {
            $base = '0.00';
        }

        // Step 9 — CCA claimed = base × rate (truncate to 2dp — CRA permits)
        $cca = bcmul($base, $rate, 2);

        // Step 10 — Recapture (only when ucc_after < 0)
        $recapture = '0.00';
        if (bccomp($uccAfter, '0', 2) < 0) {
            $recapture = ltrim((string) $uccAfter, '-');
            $cca = '0.00';  // No CCA when in recapture territory.
        }

        // Step 11 — Terminal loss (only when ucc_after > 0 AND class is empty at year-end)
        // Class empty = no acc_fixed_assets rows with cca_class_id = N and
        // status != 'disposed'. (Hard-delete only — no deleted_at filter.)
        $terminalLoss = '0.00';
        if ((int) $cls['terminal_loss_applies'] === 1 && bccomp($uccAfter, '0', 2) > 0) {
            $remaining = (int) \db_count(
                "SELECT COUNT(*) FROM acc_fixed_assets
                  WHERE cca_class_id = ? AND status <> 'disposed'",
                [$classId]
            );
            if ($remaining === 0) {
                $terminalLoss = $uccAfter;
                $cca = '0.00';  // Terminal loss replaces CCA.
            }
        }

        // Step 12 — Closing UCC
        // closing = ucc_after - cca - recapture (when recapture, ucc_after is
        // already negative and absorbed; result ≈ 0). When terminal loss
        // applies, the entire residual leaves UCC → closing = 0.
        if (bccomp($terminalLoss, '0', 2) > 0) {
            $closingUcc = '0.00';
        } elseif (bccomp($recapture, '0', 2) > 0) {
            $closingUcc = '0.00';
        } else {
            $closingUcc = bcsub($uccAfter, $cca, 2);
        }

        $notes = null;
        if ($fallbackCount > 0 || $aiipFallbackCount > 0) {
            $notes = "{$fallbackCount} addition(s) and {$aiipFallbackCount} AIIP determination(s) used acquisition_date fallback (available_for_use_date NULL).";
        }

        return [
            'fiscal_year'                      => $fiscalYear,
            'cca_class_id'                     => $classId,
            'opening_ucc'                      => $openingUcc,
            'cost_of_additions'                => $additions,
            'adjustments_transfers'            => $adjustments,
            'proceeds_of_disposition'          => $proceeds,
            'ucc_after_additions_dispositions' => $uccAfter,
            'aiip_adjustment'                  => $aiipAdjustment,
            'base_amount_for_cca'              => $base,
            'half_year_adjustment'             => $halfYearAdjustment,
            'cca_claimed'                      => $cca,
            'recapture'                        => $recapture,
            'terminal_loss'                    => $terminalLoss,
            'closing_ucc'                      => $closingUcc,
            'is_locked'                        => 0,
            'computed_at'                      => date('Y-m-d H:i:s'),
            'computed_by'                      => $userId,
        ];
    }

    /**
     * Lock all continuity rows for a fiscal year (sign-off step).
     * Subsequent compute calls throw until unlock() is called.
     */
    public static function lock(int $fiscalYear, int $userId): void
    {
        \db_transaction(function () use ($fiscalYear, $userId) {
            $n = \db_execute(
                "UPDATE acc_cca_continuity SET is_locked = 1 WHERE fiscal_year = ?",
                [$fiscalYear]
            );
            if ($n === 0) {
                throw new \RuntimeException("No CCA continuity rows found for FY{$fiscalYear}.");
            }
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'cca_continuity',
                'entity_id'   => $fiscalYear,
                'notes'       => "CCA FY{$fiscalYear} locked ({$n} class rows).",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        });
    }

    /**
     * Unlock — super_admin only (the caller enforces; this method only
     * performs the write + audit).
     */
    public static function unlock(int $fiscalYear, int $userId): void
    {
        \db_transaction(function () use ($fiscalYear, $userId) {
            $n = \db_execute(
                "UPDATE acc_cca_continuity SET is_locked = 0 WHERE fiscal_year = ?",
                [$fiscalYear]
            );
            if ($n === 0) {
                throw new \RuntimeException("No CCA continuity rows found for FY{$fiscalYear}.");
            }
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'cca_continuity',
                'entity_id'   => $fiscalYear,
                'notes'       => "CCA FY{$fiscalYear} UNLOCKED ({$n} class rows) by super_admin user #{$userId}.",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        });
    }

    /**
     * Fetch a fiscal year's continuity rows joined to class metadata,
     * plus a per-class asset list (additions + carry-overs + dispositions
     * relevant to this year).
     *
     * @return array { fiscal_year, rows: [...], assets_by_class: [classId => [...]] }
     */
    public static function getSchedule(int $fiscalYear): array
    {
        $rows = \db_select(
            "SELECT cont.*,
                    cls.class_number, cls.description AS class_description,
                    cls.rate AS class_rate, cls.method AS class_method,
                    cls.half_year_rule AS class_half_year_rule,
                    cls.aiip_eligible AS class_aiip_eligible,
                    cls.one_asset_per_class AS class_one_asset_per_class,
                    u.name AS computed_by_name
               FROM acc_cca_continuity cont
               JOIN acc_cca_classes cls ON cls.id = cont.cca_class_id
          LEFT JOIN users u ON u.id = cont.computed_by
              WHERE cont.fiscal_year = ?
              ORDER BY CAST(cls.class_number AS DECIMAL(6,2)) ASC",
            [$fiscalYear]
        );

        $assetsByClass = [];
        if ($rows) {
            $classIds = array_unique(array_map(fn($r) => (int) $r['cca_class_id'], $rows));
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $assets = \db_select(
                "SELECT id, asset_number, name, cca_class_id, acquisition_cost,
                        acquisition_date, available_for_use_date, status,
                        is_aiip_eligible
                   FROM acc_fixed_assets
                  WHERE cca_class_id IN ({$ph})
                  ORDER BY cca_class_id, acquisition_date",
                $classIds
            );
            $disposals = \db_select(
                "SELECT d.id AS disposal_id, d.asset_id, d.disposal_date, d.proceeds,
                        d.disposal_type, fa.cca_class_id, fa.asset_number, fa.name,
                        fa.acquisition_cost
                   FROM acc_asset_disposals d
                   JOIN acc_fixed_assets fa ON fa.id = d.asset_id
                  WHERE fa.cca_class_id IN ({$ph})
                    AND YEAR(d.disposal_date) = ?",
                [...$classIds, $fiscalYear]
            );
            foreach ($assets as $a) {
                $cid = (int) $a['cca_class_id'];
                if (!isset($assetsByClass[$cid])) {
                    $assetsByClass[$cid] = ['assets' => [], 'disposals' => []];
                }
                $assetsByClass[$cid]['assets'][] = $a;
            }
            foreach ($disposals as $d) {
                $cid = (int) $d['cca_class_id'];
                if (!isset($assetsByClass[$cid])) {
                    $assetsByClass[$cid] = ['assets' => [], 'disposals' => []];
                }
                $assetsByClass[$cid]['disposals'][] = $d;
            }
        }

        return [
            'fiscal_year'     => $fiscalYear,
            'rows'            => $rows,
            'assets_by_class' => $assetsByClass,
        ];
    }

    /**
     * Class 16 GVWR validator — surfaces a warning when GVWR > 11,788 kg
     * (≈ 25,990 lbs) but the proposed cca_class_id is neither Class 16
     * nor Class 55 (ZEV equivalent). Returns null when no warning needed.
     *
     * GVWR field on disk is equipment_units.weight_capacity_lbs (lbs, not
     * kg) per pre-flight catch. Threshold conversion: 11,788 kg × 2.2046 ≈
     * 25,990 lbs (rounded down to 25,990).
     *
     * @param int|null $ccaClassId  The chosen class FK
     * @param int|null $gvwrKg      Optional explicit GVWR in kg
     * @param int|null $gvwrLbs     Optional explicit GVWR in lbs (mainland
     *                              equipment_units uses lbs natively)
     * @return string|null  Warning message, or null if no warning
     */
    public static function classifyGvwrWarning(?int $ccaClassId, ?int $gvwrKg = null, ?int $gvwrLbs = null): ?string
    {
        $exceeds = false;
        if ($gvwrKg !== null && $gvwrKg > 11788) {
            $exceeds = true;
        }
        if ($gvwrLbs !== null && $gvwrLbs > 25990) {
            $exceeds = true;
        }
        if (!$exceeds) return null;

        // Look up the chosen class number to decide if it's already 16 or 55.
        if ($ccaClassId !== null) {
            $row = \db_row(
                "SELECT class_number FROM acc_cca_classes WHERE id = ?",
                [$ccaClassId]
            );
            $chosen = $row['class_number'] ?? '';
            if ($chosen === '16' || $chosen === '55') return null;
        }

        return 'GVWR > 11,788 kg (≈ 25,990 lbs) typically qualifies as Class 16 '
             . '(40% declining balance) or Class 55 for ZEV equivalents. '
             . 'Consider updating the CCA class.';
    }

    /**
     * Apply the AIIP rules table (spec §23.4) to one asset based on its
     * available-for-use date and the proposed-reinstatement setting.
     *
     * Returns ['treatment', 'multiplier', 'half_year_suspended', 'reason']:
     *   treatment = short label for UI display
     *   multiplier = bcmath string applied to acquisition_cost
     *                (0.00 = no AIIP contribution to base)
     *   half_year_suspended = true → class-level half-year skipped
     *   reason = human-readable rule citation
     *
     * Date thresholds use string comparison on Y-m-d formatted dates.
     */
    public static function classifyAiipTreatment(string $availableDate, bool $reinstatementEnabled): array
    {
        // Before AIIP existed.
        if ($availableDate < '2018-11-20') {
            return [
                'treatment' => 'no_aiip',
                'multiplier' => '0',
                'half_year_suspended' => false,
                'reason' => 'Before 2018-11-20: standard half-year, no AIIP.',
            ];
        }

        // Full AIIP era.
        if ($availableDate >= '2018-11-20' && $availableDate <= '2023-12-31') {
            return [
                'treatment' => 'aiip_full',
                'multiplier' => '1.5',
                'half_year_suspended' => true,
                'reason' => '2018-11-20 to 2023-12-31: full AIIP (1.5× multiplier), half-year suspended (3× first-year CCA).',
            ];
        }

        // Phase-out era (2024-2027). Proposed reinstatement (if enabled)
        // overrides this for 2025+ acquisitions.
        if ($availableDate >= '2024-01-01' && $availableDate <= '2027-12-31') {
            if ($reinstatementEnabled && $availableDate >= '2025-01-01') {
                return [
                    'treatment' => 'aiip_full_reinstated',
                    'multiplier' => '1.5',
                    'half_year_suspended' => true,
                    'reason' => 'Proposed 2024 FES reinstatement (2025-2029): full AIIP restored.',
                ];
            }
            return [
                'treatment' => 'aiip_phaseout',
                'multiplier' => '0',
                'half_year_suspended' => true,
                'reason' => '2024-2027 phase-out: half-year suspended, no multiplier (2× first-year CCA).',
            ];
        }

        // 2028+ era (proposed reinstatement may extend AIIP to 2029).
        if ($availableDate >= '2028-01-01' && $availableDate <= '2029-12-31') {
            if ($reinstatementEnabled) {
                return [
                    'treatment' => 'aiip_full_reinstated',
                    'multiplier' => '1.5',
                    'half_year_suspended' => true,
                    'reason' => 'Proposed 2024 FES reinstatement (2025-2029): full AIIP restored.',
                ];
            }
            return [
                'treatment' => 'no_aiip',
                'multiplier' => '0',
                'half_year_suspended' => false,
                'reason' => '2028+: no AIIP, standard half-year (current law).',
            ];
        }

        // 2030-2033 proposed reinstatement phase-down (simplified — CCA-2
        // scope decision). 2030-2033 reinstatement phase-down: simplified to
        // phase-out treatment. Update when CRA publishes step-down schedule.
        if ($reinstatementEnabled && $availableDate >= '2030-01-01' && $availableDate <= '2033-12-31') {
            return [
                'treatment' => 'aiip_reinstatement_phaseout',
                'multiplier' => '0',
                'half_year_suspended' => true,
                'reason' => 'Proposed 2024 FES reinstatement 2030-2033 phase-down: simplified to phase-out (no multiplier, half-year suspended). Update when CRA publishes step-down schedule.',
            ];
        }

        // 2034+ or any date with reinstatement disabled past 2028.
        return [
            'treatment' => 'no_aiip',
            'multiplier' => '0',
            'half_year_suspended' => false,
            'reason' => '2034+ or post-AIIP era: standard half-year, no AIIP.',
        ];
    }

    /**
     * Compute AIIP adjustment + half-year adjustment for a class in a
     * fiscal year. Applies the spec §23.4 rules table per-asset, then
     * aggregates: aiip_adjustment = Σ (multiplier × acquisition_cost) over
     * AIIP-eligible assets; half-year = 0.5 × (additions − proceeds) unless
     * ANY asset in the class triggers half-year suspension (in which case 0).
     *
     * The is_aiip_eligible flag on the asset overrides the date-based rules:
     * an asset with is_aiip_eligible=0 always reverts to standard half-year
     * (no multiplier contribution, no class-level half-year suspension by
     * THAT asset — other assets can still trigger it).
     *
     * Internal helper to compute(). Public for the aiip-detail.php endpoint
     * to surface the per-asset breakdown.
     *
     * Returns array of:
     *   aiip_adjustment      — bcmath string, sum of per-asset contributions
     *   half_year_adjustment — bcmath string, 0 when any asset suspends it
     *   half_year_suspended  — bool, true when any asset suspends half-year
     *   reinstatement_active — bool, whether the setting was on at compute time
     *   fallback_count       — int, assets that used acquisition_date fallback
     *   per_asset_breakdown  — array of {asset_id, asset_number, name,
     *     acquisition_cost, effective_date, is_aiip_eligible,
     *     treatment, multiplier, half_year_suspended, aiip_contribution, reason}
     *
     * @param int $classId
     * @param int $fiscalYear
     * @param string $netAdditions  Pre-computed additions − proceeds (bcmath)
     *                              — provided by caller for half-year math.
     *                              Actually receives $additions and $proceeds
     *                              separately for transparency.
     * @param string $additions
     * @param string $proceeds
     * @param bool $classHalfYearRule  Whether the CLASS supports half-year
     *                                  (Class 12 has half_year_rule=0).
     */
    public static function computeAiipForClass(
        int $classId,
        int $fiscalYear,
        string $additions,
        string $proceeds,
        bool $classHalfYearRule
    ): array {
        $reinstatementEnabled = ((string) \settings_get('accounting.aiip_proposed_reinstatement_enabled', '0')) === '1';

        // All assets in this class with an effective date in the fiscal year.
        // Note: AIIP-ineligible assets are still pulled — they contribute 0
        // to aiip_adjustment but do not flip class-level half-year suspension.
        // K-22 trap: no deleted_at column on acc_fixed_assets (hard-delete only).
        $assets = \db_select(
            "SELECT id, asset_number, name, acquisition_cost,
                    available_for_use_date, acquisition_date, is_aiip_eligible
               FROM acc_fixed_assets
              WHERE cca_class_id = ?
                AND YEAR(COALESCE(available_for_use_date, acquisition_date)) = ?",
            [$classId, $fiscalYear]
        );

        $totalAiipAdjustment = '0.00';
        $classHalfYearSuspended = false;
        $fallbackCount = 0;
        $breakdown = [];

        foreach ($assets as $a) {
            $cost = (string) $a['acquisition_cost'];
            $availDate = $a['available_for_use_date'] ?: $a['acquisition_date'];
            if (empty($a['available_for_use_date'])) {
                $fallbackCount++;
            }
            $isEligible = (int) $a['is_aiip_eligible'] === 1;

            // Ineligible assets: no AIIP contribution, no half-year suspension.
            if (!$isEligible) {
                $breakdown[] = [
                    'asset_id'             => (int) $a['id'],
                    'asset_number'         => $a['asset_number'],
                    'name'                 => $a['name'],
                    'acquisition_cost'     => $cost,
                    'effective_date'       => $availDate,
                    'effective_date_source' => empty($a['available_for_use_date']) ? 'acquisition_fallback' : 'available_for_use',
                    'is_aiip_eligible'     => 0,
                    'treatment'            => 'ineligible',
                    'multiplier'           => '0',
                    'half_year_suspended'  => false,
                    'aiip_contribution'    => '0.00',
                    'reason'               => 'Asset flagged is_aiip_eligible=0 — standard half-year regardless of date.',
                ];
                continue;
            }

            $rule = self::classifyAiipTreatment($availDate, $reinstatementEnabled);
            $contribution = bcmul($cost, $rule['multiplier'], 2);
            $totalAiipAdjustment = bcadd($totalAiipAdjustment, $contribution, 2);
            if ($rule['half_year_suspended']) {
                $classHalfYearSuspended = true;
            }

            $breakdown[] = [
                'asset_id'             => (int) $a['id'],
                'asset_number'         => $a['asset_number'],
                'name'                 => $a['name'],
                'acquisition_cost'     => $cost,
                'effective_date'       => $availDate,
                'effective_date_source' => empty($a['available_for_use_date']) ? 'acquisition_fallback' : 'available_for_use',
                'is_aiip_eligible'     => 1,
                'treatment'            => $rule['treatment'],
                'multiplier'           => $rule['multiplier'],
                'half_year_suspended'  => $rule['half_year_suspended'],
                'aiip_contribution'    => $contribution,
                'reason'               => $rule['reason'],
            ];
        }

        // Class-level half-year: only applied when the CLASS supports it AND
        // no asset in the class has triggered suspension via AIIP.
        $halfYearAdjustment = '0.00';
        if ($classHalfYearRule && !$classHalfYearSuspended) {
            $net = bcsub($additions, $proceeds, 2);
            if (bccomp($net, '0', 2) > 0) {
                $halfYearAdjustment = bcmul($net, '0.5', 2);
            }
        }

        return [
            'aiip_adjustment'      => $totalAiipAdjustment,
            'half_year_adjustment' => $halfYearAdjustment,
            'half_year_suspended'  => $classHalfYearSuspended,
            'reinstatement_active' => $reinstatementEnabled,
            'fallback_count'       => $fallbackCount,
            'per_asset_breakdown'  => $breakdown,
        ];
    }
}
