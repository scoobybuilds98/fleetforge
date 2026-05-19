<?php
declare(strict_types=1);

/**
 * lib/Accounting/ImpairmentTestService.php
 *
 * ASPE 3063 Fleet Impairment Two-Step Test workflow service. Each fleet
 * unit is its own CGU per ASPE 3063.12 (D-LESSOR-6-CGU-UNIT-LEVEL).
 * Tests run per-asset at year-end or on event triggers (idle, damage,
 * market decline). Once impairment is posted, ASPE 3063 prohibits
 * reversal (D-LESSOR-6-NO-REVERSAL) — this service has no un-impair
 * method.
 *
 * Two-step structure (ASPE 3063.20–.23):
 *   Step 1 (recoverability): compare carrying amount to undiscounted
 *     future cash flows from use + eventual disposal. Passes if
 *     carrying ≤ undiscounted CF — no impairment.
 *   Step 2 (measurement): when step 1 fails, operator provides the
 *     asset's fair value. Impairment loss = carrying − fair value.
 *     JE posts via FixedAssetService::impair() (D-LESSOR-6-JE-VIA-
 *     FIXED-ASSET-SERVICE — single source of impairment-JE truth at
 *     account #78 / code 7020).
 *
 * Default CF estimator (D-LESSOR-6-CF-ESTIMATOR):
 *   undiscounted_cf = avg_monthly_revenue × remaining_useful_months
 *                       + estimated_disposal_value
 * Operator can override per-test via $cfOverride parameter.
 *
 * @session S-ACCT-LESSOR-6
 */

namespace FleetForge\Accounting;

class ImpairmentTestService
{
    // ============================================================
    // B1. estimateUndiscountedCf — default CF computation
    // ============================================================

    /**
     * Compute the default undiscounted future cash flow for an asset.
     * Used as step 1 input when no operator override is supplied.
     *
     * Returns the full breakdown so it can be persisted into
     * `acc_impairment_tests.step_1_cf_breakdown_json` for audit trail.
     */
    public static function estimateUndiscountedCf(int $assetId): array
    {
        $asset = \db_row(
            "SELECT id, equipment_unit_id, depreciation_start_date, useful_life_years,
                    salvage_value, net_book_value, acquisition_cost, accumulated_depreciation
               FROM acc_fixed_assets WHERE id = ?",
            [$assetId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException("Asset #{$assetId} not found.");
        }

        $today = date('Y-m-d');

        // Months since depreciation_start_date — bounded at 0 (asset not
        // yet in service) and at useful life (don't extrapolate negative
        // remaining months).
        $startDate = $asset['depreciation_start_date'];
        $monthsSinceStart = 0;
        if ($startDate) {
            $startTs = strtotime((string) $startDate);
            $nowTs   = strtotime($today);
            if ($startTs !== false && $startTs <= $nowTs) {
                // Diff in months (calendar): year-diff*12 + month-diff.
                $diff = (int) date('Y', $nowTs) * 12 + (int) date('n', $nowTs)
                      - ((int) date('Y', $startTs) * 12 + (int) date('n', $startTs));
                $monthsSinceStart = max(0, $diff);
            }
        }

        $usefulYears   = (string) ($asset['useful_life_years'] ?? '0');
        $usefulMonths  = (int) bcmul($usefulYears, '12', 0);
        $remainingUsefulMonths = max(0, $usefulMonths - $monthsSinceStart);

        // Revenue lookback. UnitProfitabilityService::getUnitRevenue
        // computes the per-unit revenue rollup across all lease+invoice
        // line items in the window (S-ACCT-UNIT). When the asset has no
        // equipment_unit_id link (rare — e.g. office equipment classed
        // as fleet by mistake) we fall back to zero revenue.
        $lookbackMonths = (int) (AccountingService::setting(
            'accounting.impairment_cf_lookback_months', 12
        ) ?? 12);
        $lookbackStart = date('Y-m-d', strtotime("-{$lookbackMonths} months"));

        $totalRevenue          = '0.00';
        $actualMonthsInWindow  = 0;
        $hasRevenueHistory     = false;

        if (!empty($asset['equipment_unit_id'])) {
            $unitRev = UnitProfitabilityService::getUnitRevenue(
                (int) $asset['equipment_unit_id'],
                $lookbackStart,
                $today
            );
            $totalRevenue = (string) ($unitRev['revenue']['total'] ?? '0.00');
            // Effective months = min(lookback, monthsSinceStart) — a
            // brand-new asset with 3 months on the books shouldn't
            // average revenue over 12 months.
            $actualMonthsInWindow = min($lookbackMonths, $monthsSinceStart);
            $hasRevenueHistory = bccomp($totalRevenue, '0', 2) > 0;
        }

        $avgMonthlyRevenue = $actualMonthsInWindow > 0
            ? bcdiv($totalRevenue, (string) $actualMonthsInWindow, 2)
            : '0.00';

        $futureRevenue = bcmul(
            $avgMonthlyRevenue,
            (string) $remainingUsefulMonths,
            2
        );

        $estimatedDisposal = (string) ($asset['salvage_value'] ?? '0.00');
        $undiscountedCf    = bcadd($futureRevenue, $estimatedDisposal, 2);

        $breakdown = [
            'lookback_months'         => $lookbackMonths,
            'lookback_start'          => $lookbackStart,
            'lookback_end'            => $today,
            'months_since_start'      => $monthsSinceStart,
            'useful_months'           => $usefulMonths,
            'remaining_useful_months' => $remainingUsefulMonths,
            'total_revenue_window'    => $totalRevenue,
            'actual_months_in_window' => $actualMonthsInWindow,
            'avg_monthly_revenue'     => $avgMonthlyRevenue,
            'future_revenue'          => $futureRevenue,
            'estimated_disposal'      => $estimatedDisposal,
            'undiscounted_cf'         => $undiscountedCf,
            'has_revenue_history'     => $hasRevenueHistory,
        ];

        return [
            'asset_id'                => $assetId,
            'avg_monthly_revenue'     => $avgMonthlyRevenue,
            'remaining_useful_months' => $remainingUsefulMonths,
            'future_revenue'          => $futureRevenue,
            'estimated_disposal'      => $estimatedDisposal,
            'undiscounted_cf'         => $undiscountedCf,
            'breakdown_json'          => $breakdown,
            'has_revenue_history'     => $hasRevenueHistory,
        ];
    }

    // ============================================================
    // B2. runStep1 — recoverability test
    // ============================================================

    /**
     * Step 1 of the ASPE 3063 test. Returns the comparison + verdict
     * without writing anything.
     */
    public static function runStep1(int $assetId, ?string $cfOverride = null): array
    {
        $asset = \db_row(
            "SELECT id, net_book_value FROM acc_fixed_assets WHERE id = ?",
            [$assetId]
        );
        if (!$asset) {
            throw new \InvalidArgumentException("Asset #{$assetId} not found.");
        }

        $carryingAmount = (string) $asset['net_book_value'];

        if ($cfOverride !== null && $cfOverride !== '') {
            $undiscountedCf = (string) $cfOverride;
            $source = 'operator_override';
            $breakdown = ['override_amount' => $cfOverride];
        } else {
            $estimate = self::estimateUndiscountedCf($assetId);
            $undiscountedCf = $estimate['undiscounted_cf'];
            $source = 'estimator';
            $breakdown = $estimate['breakdown_json'];
        }

        $passed = bccomp($carryingAmount, $undiscountedCf, 2) <= 0;
        $deficit = $passed ? null : bcsub($carryingAmount, $undiscountedCf, 2);

        return [
            'asset_id'        => $assetId,
            'carrying_amount' => $carryingAmount,
            'undiscounted_cf' => $undiscountedCf,
            'source'          => $source,
            'breakdown_json'  => $breakdown,
            'passed'          => $passed,
            'deficit'         => $deficit,
        ];
    }

    // ============================================================
    // B3. runTest — full two-step test + JE post
    // ============================================================

    /**
     * Record a complete ASPE 3063 test. UPSERTs on the unique
     * (asset_id, fiscal_year, triggering_event) key so re-running the
     * same triggering_event on the same asset in the same fiscal year
     * updates the existing row rather than violating the constraint.
     *
     * @param int    $userId
     * @param string $userRoleSlug  Required for FixedAssetService::impair()
     *                              RBAC guard (manager/accountant/super_admin).
     * @return array {
     *   test_id, passed, deficit (when failed), impairment_loss (when posted),
     *   je_id (when posted), status: 'passed'|'step_1_failed_pending_fv'|
     *                                'impairment_posted'|'fv_recovery'
     * }
     * @throws \InvalidArgumentException  asset not found / disposed
     * @throws \RuntimeException          JE post failure / RBAC denial
     */
    public static function runTest(
        int $assetId,
        int $fiscalYear,
        string $triggeringEvent,
        int $userId,
        string $userRoleSlug,
        ?string $cfOverride = null,
        ?string $fairValue = null,
        ?string $fairValueBasis = null,
        ?string $notes = null,
        ?string $triggeringEventNotes = null
    ): array {
        $allowedEvents = ['annual','idle','damage','market_decline','adverse_legal','other'];
        if (!in_array($triggeringEvent, $allowedEvents, true)) {
            throw new \InvalidArgumentException(
                "Invalid triggering_event '{$triggeringEvent}'. Allowed: " . implode(', ', $allowedEvents)
            );
        }
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            throw new \InvalidArgumentException("Invalid fiscal_year {$fiscalYear}.");
        }

        return \db_transaction(function () use (
            $assetId, $fiscalYear, $triggeringEvent, $userId, $userRoleSlug,
            $cfOverride, $fairValue, $fairValueBasis, $notes, $triggeringEventNotes
        ): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got",
                ["ff_impair_test_{$assetId}_{$fiscalYear}"]);

            try {
                $asset = \db_row(
                    "SELECT id, asset_number, name, status, asset_class
                       FROM acc_fixed_assets WHERE id = ? FOR UPDATE",
                    [$assetId]
                );
                if (!$asset) {
                    throw new \InvalidArgumentException("Asset #{$assetId} not found.");
                }
                if ($asset['status'] === 'disposed') {
                    throw new \InvalidArgumentException(
                        "Asset #{$assetId} is disposed — cannot run impairment test."
                    );
                }

                // Step 1.
                $step1 = self::runStep1($assetId, $cfOverride);

                // Step 1 passed → no impairment, just record the test.
                if ($step1['passed']) {
                    $testId = self::upsertTestRow([
                        'asset_id'                 => $assetId,
                        'fiscal_year'              => $fiscalYear,
                        'triggering_event'         => $triggeringEvent,
                        'triggering_event_notes'   => $triggeringEventNotes,
                        'step_1_carrying_amount'   => $step1['carrying_amount'],
                        'step_1_undiscounted_cf'   => $step1['undiscounted_cf'],
                        'step_1_cf_source'         => $step1['source'],
                        'step_1_cf_breakdown_json' => json_encode($step1['breakdown_json']),
                        'step_1_passed'            => 1,
                        'step_2_fair_value'        => null,
                        'step_2_impairment_loss'   => null,
                        'step_2_fair_value_basis'  => null,
                        'impairment_je_id'         => null,
                        'tested_by'                => $userId,
                        'notes'                    => $notes,
                    ]);

                    \db_insert('audit_log', [
                        'user_id'     => $userId,
                        'action'      => 'create',
                        'module'      => 'accounting',
                        'entity_type' => 'fixed_asset',
                        'entity_id'   => $assetId,
                        'entity_label' => $asset['asset_number'],
                        'notes'       => sprintf(
                            'ASPE 3063 test FY%d (%s): step 1 PASSED — carrying %s ≤ undiscounted CF %s. Source: %s.',
                            $fiscalYear, $triggeringEvent,
                            $step1['carrying_amount'], $step1['undiscounted_cf'],
                            $step1['source']
                        ),
                        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ]);

                    return [
                        'test_id'         => $testId,
                        'passed'          => true,
                        'status'          => 'passed',
                        'carrying_amount' => $step1['carrying_amount'],
                        'undiscounted_cf' => $step1['undiscounted_cf'],
                    ];
                }

                // Step 1 failed.
                if ($fairValue === null || $fairValue === '') {
                    // Pending FV — record the failed step 1, wait for operator.
                    $testId = self::upsertTestRow([
                        'asset_id'                 => $assetId,
                        'fiscal_year'              => $fiscalYear,
                        'triggering_event'         => $triggeringEvent,
                        'triggering_event_notes'   => $triggeringEventNotes,
                        'step_1_carrying_amount'   => $step1['carrying_amount'],
                        'step_1_undiscounted_cf'   => $step1['undiscounted_cf'],
                        'step_1_cf_source'         => $step1['source'],
                        'step_1_cf_breakdown_json' => json_encode($step1['breakdown_json']),
                        'step_1_passed'            => 0,
                        'step_2_fair_value'        => null,
                        'step_2_impairment_loss'   => null,
                        'step_2_fair_value_basis'  => null,
                        'impairment_je_id'         => null,
                        'tested_by'                => $userId,
                        'notes'                    => $notes,
                    ]);

                    \db_insert('audit_log', [
                        'user_id'     => $userId,
                        'action'      => 'create',
                        'module'      => 'accounting',
                        'entity_type' => 'fixed_asset',
                        'entity_id'   => $assetId,
                        'entity_label' => $asset['asset_number'],
                        'notes'       => sprintf(
                            'ASPE 3063 test FY%d (%s): step 1 FAILED — carrying %s > undiscounted CF %s (deficit %s). Step 2 pending operator fair_value.',
                            $fiscalYear, $triggeringEvent,
                            $step1['carrying_amount'], $step1['undiscounted_cf'], $step1['deficit']
                        ),
                        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ]);

                    return [
                        'test_id'         => $testId,
                        'passed'          => false,
                        'status'          => 'step_1_failed_pending_fv',
                        'carrying_amount' => $step1['carrying_amount'],
                        'undiscounted_cf' => $step1['undiscounted_cf'],
                        'deficit'         => $step1['deficit'],
                        'message'         => 'Step 1 failed. Provide fair_value to complete step 2 and post impairment JE.',
                    ];
                }

                // Step 2 with FV — compute impairment + delegate JE post.
                $carrying = $step1['carrying_amount'];
                $impairmentLoss = bcsub($carrying, (string) $fairValue, 2);

                // Edge case: FV ≥ carrying. Step 1 must have used a low
                // CF estimate, but operator's FV says the asset is fine.
                // Record as recovery (no impairment), log warning.
                if (bccomp($impairmentLoss, '0', 2) <= 0) {
                    $testId = self::upsertTestRow([
                        'asset_id'                 => $assetId,
                        'fiscal_year'              => $fiscalYear,
                        'triggering_event'         => $triggeringEvent,
                        'triggering_event_notes'   => $triggeringEventNotes,
                        'step_1_carrying_amount'   => $carrying,
                        'step_1_undiscounted_cf'   => $step1['undiscounted_cf'],
                        'step_1_cf_source'         => $step1['source'],
                        'step_1_cf_breakdown_json' => json_encode($step1['breakdown_json']),
                        'step_1_passed'            => 1,  // FV recovery overrides
                        'step_2_fair_value'        => $fairValue,
                        'step_2_impairment_loss'   => '0.00',
                        'step_2_fair_value_basis'  => $fairValueBasis,
                        'impairment_je_id'         => null,
                        'tested_by'                => $userId,
                        'notes'                    => $notes,
                    ]);
                    error_log(sprintf(
                        '[ImpairmentTestService::runTest] asset=%d FY=%d: operator FV (%s) >= carrying (%s). Step 1 estimator showed deficit (%s) but operator FV indicates no impairment — recording as passed, no JE.',
                        $assetId, $fiscalYear, $fairValue, $carrying, $step1['deficit']
                    ));
                    return [
                        'test_id'        => $testId,
                        'passed'         => true,
                        'status'         => 'fv_recovery',
                        'message'        => 'Operator fair_value ≥ carrying amount — no impairment. Step 1 estimator low-balled; FV recovers.',
                    ];
                }

                // Real impairment — delegate JE post to FixedAssetService.
                // impairment_date: end of fiscal year for annual, today for events.
                $impairmentDate = $triggeringEvent === 'annual'
                    ? "{$fiscalYear}-12-31"
                    : date('Y-m-d');

                $reason = sprintf(
                    'ASPE 3063 impairment — %s. Carrying=%s, FV=%s, Loss=%s. Test FY%d.',
                    $triggeringEvent, $carrying, $fairValue, $impairmentLoss, $fiscalYear
                );

                $impairmentRecord = FixedAssetService::impair([
                    'asset_id'        => $assetId,
                    'impairment_date' => $impairmentDate,
                    'impairment_loss' => $impairmentLoss,
                    'reason'          => $reason,
                ], $userId, $userRoleSlug);

                $jeId = (int) ($impairmentRecord['journal_entry_id'] ?? 0);

                $testId = self::upsertTestRow([
                    'asset_id'                 => $assetId,
                    'fiscal_year'              => $fiscalYear,
                    'triggering_event'         => $triggeringEvent,
                    'triggering_event_notes'   => $triggeringEventNotes,
                    'step_1_carrying_amount'   => $carrying,
                    'step_1_undiscounted_cf'   => $step1['undiscounted_cf'],
                    'step_1_cf_source'         => $step1['source'],
                    'step_1_cf_breakdown_json' => json_encode($step1['breakdown_json']),
                    'step_1_passed'            => 0,
                    'step_2_fair_value'        => $fairValue,
                    'step_2_impairment_loss'   => $impairmentLoss,
                    'step_2_fair_value_basis'  => $fairValueBasis,
                    'impairment_je_id'         => $jeId ?: null,
                    'tested_by'                => $userId,
                    'notes'                    => $notes,
                ]);

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => 'create',
                    'module'      => 'accounting',
                    'entity_type' => 'fixed_asset',
                    'entity_id'   => $assetId,
                    'entity_label' => $asset['asset_number'],
                    'notes'       => sprintf(
                        'ASPE 3063 test FY%d (%s): IMPAIRED — carrying=%s, FV=%s, loss=%s. JE #%d.',
                        $fiscalYear, $triggeringEvent, $carrying, $fairValue, $impairmentLoss, $jeId
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'test_id'         => $testId,
                    'passed'          => false,
                    'status'          => 'impairment_posted',
                    'carrying_amount' => $carrying,
                    'fair_value'      => $fairValue,
                    'impairment_loss' => $impairmentLoss,
                    'je_id'           => $jeId,
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_impair_test_{$assetId}_{$fiscalYear}"]);
            }
        });
    }

    // ============================================================
    // B4. runAnnual — batch runner for year-end close
    // ============================================================

    /**
     * Run the annual ASPE 3063 test on every active fleet asset. Two
     * typical operator flows:
     *
     *   1. Preview (no $fairValueInputs): runs step 1 for every asset.
     *      Returns the verdict per asset with pending_fair_value count.
     *      Operator reviews UI, provides FV for the failing assets.
     *   2. Complete: re-run with $fairValueInputs keyed by asset_id —
     *      service UPSERTs each test row and posts impairment JEs for
     *      the failing assets.
     */
    public static function runAnnual(
        int $fiscalYear,
        int $userId,
        string $userRoleSlug,
        array $cfOverrides = [],
        array $fairValueInputs = []
    ): array {
        $assets = \db_select(
            "SELECT id, asset_number, name FROM acc_fixed_assets
              WHERE status = 'active'
                AND asset_class = 'fleet_equipment'
              ORDER BY id ASC"
        );

        $results = [
            'fiscal_year'         => $fiscalYear,
            'total'               => count($assets),
            'step_1_passed'       => 0,
            'pending_fair_value'  => 0,
            'impairment_posted'   => 0,
            'fv_recovery'         => 0,
            'errors'              => 0,
            'tests'               => [],
        ];

        foreach ($assets as $asset) {
            $assetId   = (int) $asset['id'];
            $cfOverride = $cfOverrides[$assetId] ?? null;
            $fvSpec    = $fairValueInputs[$assetId] ?? null;
            $fairValue = is_array($fvSpec) ? ($fvSpec['fair_value'] ?? null) : $fvSpec;
            $fvBasis   = is_array($fvSpec) ? ($fvSpec['basis'] ?? null)      : null;

            try {
                $r = self::runTest(
                    $assetId, $fiscalYear, 'annual',
                    $userId, $userRoleSlug,
                    $cfOverride,
                    $fairValue,
                    $fvBasis
                );
                $results['tests'][] = $r;
                switch ($r['status']) {
                    case 'passed':                    $results['step_1_passed']++;      break;
                    case 'step_1_failed_pending_fv':  $results['pending_fair_value']++; break;
                    case 'impairment_posted':         $results['impairment_posted']++;  break;
                    case 'fv_recovery':               $results['fv_recovery']++;        break;
                }
            } catch (\Throwable $e) {
                $results['errors']++;
                $results['tests'][] = [
                    'asset_id' => $assetId,
                    'asset_number' => $asset['asset_number'],
                    'error'    => $e->getMessage(),
                ];
                error_log(sprintf(
                    'ImpairmentTestService::runAnnual: asset #%d %s — %s',
                    $assetId, $asset['asset_number'], $e->getMessage()
                ));
                if (class_exists('\FleetForge\Observability\Sentry')) {
                    \FleetForge\Observability\Sentry::captureException($e);
                }
            }
        }

        \db_insert('audit_log', [
            'user_id'     => $userId,
            'action'      => 'cron',
            'module'      => 'accounting',
            'entity_type' => 'impairment_batch',
            'notes'       => sprintf(
                'ASPE 3063 annual batch FY%d: total=%d, passed=%d, pending_fv=%d, impaired=%d, fv_recovery=%d, errors=%d.',
                $fiscalYear, $results['total'], $results['step_1_passed'],
                $results['pending_fair_value'], $results['impairment_posted'],
                $results['fv_recovery'], $results['errors']
            ),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return $results;
    }

    // ============================================================
    // listForYear — read-only history for admin UI
    // ============================================================

    public static function listForYear(int $fiscalYear): array
    {
        return \db_select(
            "SELECT t.*, a.asset_number, a.name AS asset_name,
                    a.net_book_value AS current_nbv,
                    je.entry_number AS je_entry_number,
                    u.full_name AS tester_name
               FROM acc_impairment_tests t
               LEFT JOIN acc_fixed_assets a ON a.id = t.asset_id
               LEFT JOIN acc_journal_entries je ON je.id = t.impairment_je_id
               LEFT JOIN users u ON u.id = t.tested_by AND u.deleted_at IS NULL
              WHERE t.fiscal_year = ?
              ORDER BY t.tested_at DESC",
            [$fiscalYear]
        );
    }

    public static function getTest(int $testId): ?array
    {
        return \db_row(
            "SELECT t.*, a.asset_number, a.name AS asset_name,
                    a.acquisition_cost, a.accumulated_depreciation, a.net_book_value AS current_nbv,
                    a.asset_class, a.useful_life_years, a.salvage_value,
                    a.depreciation_start_date,
                    je.entry_number AS je_entry_number,
                    u.full_name AS tester_name
               FROM acc_impairment_tests t
               LEFT JOIN acc_fixed_assets a ON a.id = t.asset_id
               LEFT JOIN acc_journal_entries je ON je.id = t.impairment_je_id
               LEFT JOIN users u ON u.id = t.tested_by AND u.deleted_at IS NULL
              WHERE t.id = ?",
            [$testId]
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * UPSERT on UNIQUE(asset_id, fiscal_year, triggering_event).
     * Returns the row id.
     */
    private static function upsertTestRow(array $row): int
    {
        $existing = \db_row(
            "SELECT id FROM acc_impairment_tests
              WHERE asset_id = ? AND fiscal_year = ? AND triggering_event = ?",
            [$row['asset_id'], $row['fiscal_year'], $row['triggering_event']]
        );

        if ($existing) {
            \db_execute(
                "UPDATE acc_impairment_tests
                    SET triggering_event_notes   = ?,
                        step_1_carrying_amount   = ?,
                        step_1_undiscounted_cf   = ?,
                        step_1_cf_source         = ?,
                        step_1_cf_breakdown_json = ?,
                        step_1_passed            = ?,
                        step_2_fair_value        = ?,
                        step_2_impairment_loss   = ?,
                        step_2_fair_value_basis  = ?,
                        impairment_je_id         = ?,
                        tested_by                = ?,
                        tested_at                = NOW(),
                        notes                    = ?
                  WHERE id = ?",
                [
                    $row['triggering_event_notes'],
                    $row['step_1_carrying_amount'],
                    $row['step_1_undiscounted_cf'],
                    $row['step_1_cf_source'],
                    $row['step_1_cf_breakdown_json'],
                    $row['step_1_passed'],
                    $row['step_2_fair_value'],
                    $row['step_2_impairment_loss'],
                    $row['step_2_fair_value_basis'],
                    $row['impairment_je_id'],
                    $row['tested_by'],
                    $row['notes'],
                    (int) $existing['id'],
                ]
            );
            return (int) $existing['id'];
        }

        return \db_insert('acc_impairment_tests', $row);
    }
}
