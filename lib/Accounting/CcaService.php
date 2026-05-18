<?php
declare(strict_types=1);

/**
 * lib/Accounting/CcaService.php
 *
 * Capital Cost Allowance (T2 Schedule 8) continuity engine per
 * ACCOUNTING_SPEC §23.3. Computes per-fiscal-year per-class UCC roll-forward
 * (opening → additions → dispositions → AIIP → half-year → CCA → recapture →
 * terminal loss → closing) and persists to acc_cca_continuity.
 *
 * AIIP scope: §23.4 phase-out rules are implemented in S-ACCT-CCA-2.
 * CCA-1 leaves aiip_adjustment = '0.00' and flags it in the row's notes
 * field. The half-year-rule short-circuit when AIIP is non-zero is wired
 * but currently unreachable (since aiip is always 0 here).
 *
 * Required by: api/v1/accounting/cca/{compute,export,show,lock}.php
 * Depends on: acc_cca_classes (seeded), acc_cca_continuity (per year),
 *             acc_fixed_assets.cca_class_id (set by operator),
 *             acc_asset_disposals.proceeds (per CRA on-disk column name).
 *
 * Pre-flight column-name catches (K-22, S-ACCT-CCA-1):
 *   - acc_fixed_assets.acquisition_cost  (not original_cost)
 *   - acc_fixed_assets.asset_class       (not asset_category)
 *   - acc_asset_disposals.proceeds       (matches spec §23.3)
 *   - acc_fixed_assets has NO deleted_at column (hard-delete only)
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
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

        // Step 6 — AIIP adjustment (CCA-2 scope)
        $aiipAdjustment = '0.00';

        // Step 7 — Half-year adjustment
        // 0.5 × (additions − proceeds), floored at 0 (never increases base).
        // Skipped when AIIP > 0 (CCA-2 will short-circuit). Skipped when
        // class.half_year_rule = 0 (e.g. Class 12 100% write-off).
        $halfYearAdjustment = '0.00';
        if ($halfYear && bccomp($aiipAdjustment, '0', 2) === 0) {
            $netAdditions = bcsub($additions, $proceeds, 2);
            if (bccomp($netAdditions, '0', 2) > 0) {
                $halfYearAdjustment = bcmul($netAdditions, '0.5', 2);
            }
        }

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
        if ($fallbackCount > 0) {
            $notes = "AIIP pending CCA-2. {$fallbackCount} addition(s) used acquisition_date fallback (available_for_use_date NULL).";
        } else {
            $notes = "AIIP pending CCA-2.";
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
}
