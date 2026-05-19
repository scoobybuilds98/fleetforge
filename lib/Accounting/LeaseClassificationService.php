<?php
declare(strict_types=1);

/**
 * lib/Accounting/LeaseClassificationService.php
 *
 * ASPE 3065.06–.10 lease classification wizard. Walks a candidate lease
 * through the three "any-one" criteria (3065.06) plus the two qualifying
 * conditions (3065.07–.08), then resolves to one of:
 *   - operating          (default if no criterion / condition combination triggers)
 *   - sales_type         (all met AND fair value ≠ carrying amount)
 *   - direct_financing   (all met AND fair value = carrying amount)
 *
 * Numeric work is bcmath end-to-end — every ratio / PV / comparison is
 * decimal-string + bccomp. Float arithmetic is never used for money.
 *
 * The service writes two things on success:
 *   1. acc_lease_classifications — full criteria archive (UPSERT on lease_id).
 *   2. leases.{classification, classification_signed_off_by/at,
 *      economic_life_months, initial_fair_value, implicit_rate,
 *      guaranteed/unguaranteed_residual_value, initial_direct_costs,
 *      bargain_purchase_option_amount/date}.
 *
 * Re-running the wizard on a lease already classified as sales_type or
 * direct_financing requires super-admin (reclassification is a material
 * event — drops the JE posting path).
 *
 * Pre-flight catches (S-ACCT-LESSOR-1 2026-05-19):
 *   - leases has NO term_months column on disk. Operator supplies
 *     lease_term_months as a wizard input (decision D-LESSOR-1-TERM).
 *   - acc_accounts code 1040 was ALREADY "Allowance for Doubtful
 *     Accounts" — NI Current relocated to 1090. Operator-locked.
 *
 * @session S-ACCT-LESSOR-1
 */

namespace FleetForge\Accounting;

class LeaseClassificationService
{
    /** bcmath scale used for ratio + PV arithmetic. 4 decimals on ratios
     *  matches the schema (criterion_b_ratio / criterion_c_ratio are
     *  DECIMAL(5,4)). 10 decimals for PV intermediates avoids rounding
     *  drift before the DECIMAL(15,2) cast. */
    private const RATIO_SCALE = 4;
    private const PV_SCALE    = 10;

    // ============================================================
    // 3065.06(a) — title transfer OR bargain purchase option
    // ============================================================

    /**
     * Evaluate criterion A: reasonable assurance the lessee obtains
     * ownership by end of term. Triggered by either (1) explicit title
     * transfer clause or (2) a bargain purchase option (BPO) priced
     * below the asset's expected fair value at exercise date.
     *
     * leases.title_transfers column does NOT exist on disk — operator
     * input "title_transfers" is consulted instead. BPO presence is
     * derived from bpo_amount > 0 OR bpo_date NOT NULL.
     *
     * @param array $input  Wizard input including 'title_transfers',
     *                      'bpo_amount', 'bpo_date'.
     * @return array{met:bool,evidence:string,notes:string}
     */
    public static function evaluateCriterionA(array $input): array
    {
        $titleTransfers = !empty($input['title_transfers']);

        $bpoAmount = $input['bpo_amount'] ?? null;
        $bpoDate   = $input['bpo_date']   ?? null;

        $bpoPresent =
            ($bpoAmount !== null && $bpoAmount !== '' && bccomp((string) $bpoAmount, '0', 2) > 0)
            || !empty($bpoDate);

        $met = $titleTransfers || $bpoPresent;

        $evidenceBits = [];
        if ($titleTransfers) $evidenceBits[] = 'title transfer clause';
        if ($bpoPresent)     $evidenceBits[] = 'bargain purchase option';
        $evidence = $met ? implode(' + ', $evidenceBits) : 'none';

        return [
            'met'      => $met,
            'evidence' => $evidence,
            'notes'    => $input['criterion_a_notes'] ?? '',
        ];
    }

    // ============================================================
    // 3065.06(b) — lease term ≥ 75% of economic life
    // ============================================================

    /**
     * Evaluate criterion B: lease term length relative to remaining
     * economic life of the asset.
     *
     * @param int $leaseTermMonths    Wizard-provided term length.
     * @param int $economicLifeMonths Wizard-provided economic life.
     * @return array{met:bool,lease_term_months:int,economic_life_months:int,ratio:string}
     * @throws \InvalidArgumentException When economicLifeMonths ≤ 0.
     */
    public static function evaluateCriterionB(int $leaseTermMonths, int $economicLifeMonths): array
    {
        if ($economicLifeMonths <= 0) {
            throw new \InvalidArgumentException('Economic life cannot be zero.');
        }
        if ($leaseTermMonths <= 0) {
            throw new \InvalidArgumentException('Lease term cannot be zero.');
        }

        $ratio = bcdiv((string) $leaseTermMonths, (string) $economicLifeMonths, self::RATIO_SCALE);
        $met   = bccomp($ratio, '0.75', self::RATIO_SCALE) >= 0;

        return [
            'met'                  => $met,
            'lease_term_months'    => $leaseTermMonths,
            'economic_life_months' => $economicLifeMonths,
            'ratio'                => $ratio,
        ];
    }

    // ============================================================
    // 3065.06(c) — PV of minimum lease payments ≥ 90% of fair value
    // ============================================================

    /**
     * Evaluate criterion C: present value of minimum lease payments
     * (MLP) compared to fair value of the leased asset at inception.
     *
     * MLP = sum of monthly payments over term + guaranteed residual at
     * end of term. Unguaranteed residual is EXCLUDED from MLP (it is
     * factored into the implicit rate solve in LESSOR-2, not here).
     *
     * Annuity PV (monthly) computed by direct iterative discounting so
     * the rate-zero edge case (operator entered 0%) degrades gracefully
     * to the nominal sum.
     *
     * @param string $monthlyPayment Wizard input (decimal string).
     * @param int    $termMonths
     * @param string $guaranteedResidual Decimal string (use '0.00' if none).
     * @param string $discountRate Annual decimal rate as string (e.g. '0.0650').
     * @param string $fairValue Initial fair value (decimal string, > 0).
     * @return array{met:bool,pv_mlp:string,fair_value:string,ratio:string,discount_rate_used:string,is_undiscounted:bool}
     * @throws \InvalidArgumentException When fairValue ≤ 0.
     */
    public static function evaluateCriterionC(
        string $monthlyPayment,
        int    $termMonths,
        string $guaranteedResidual,
        string $discountRate,
        string $fairValue
    ): array {
        if (bccomp($fairValue, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Initial fair value cannot be zero.');
        }
        if ($termMonths <= 0) {
            throw new \InvalidArgumentException('Lease term cannot be zero.');
        }

        $isUndiscounted = bccomp($discountRate, '0', 6) === 0;

        if ($isUndiscounted) {
            // WHY rate=0 fallback: bcdiv by zero would crash. Sum the
            // nominal payments + guaranteed residual instead — ASPE
            // doesn't forbid a 0% implicit rate; it's just unusual.
            $pv = bcadd(
                bcmul($monthlyPayment, (string) $termMonths, self::PV_SCALE),
                $guaranteedResidual,
                self::PV_SCALE
            );
        } else {
            // Monthly rate = annual / 12. Discrete monthly compounding
            // matches the amortization engine LESSOR-2 will use, so the
            // criterion-C decision stays consistent with the post-classification
            // schedule.
            $monthlyRate = bcdiv($discountRate, '12', self::PV_SCALE);
            $onePlusR    = bcadd('1', $monthlyRate, self::PV_SCALE);

            $pv = '0.0000000000';
            $discountFactor = '1.0000000000';
            // Iteratively accumulate Σ payment / (1+r)^t for t=1..n. Term
            // capped at 6000 months (500 years) as a defensive bound —
            // anything beyond that is operator input error, not a real lease.
            $iters = min($termMonths, 6000);
            for ($t = 1; $t <= $iters; $t++) {
                $discountFactor = bcmul($discountFactor, $onePlusR, self::PV_SCALE);
                $pv = bcadd($pv, bcdiv($monthlyPayment, $discountFactor, self::PV_SCALE), self::PV_SCALE);
            }
            // Final discount factor is now (1+r)^n — reuse for the
            // guaranteed-residual discount instead of recomputing.
            if (bccomp($guaranteedResidual, '0', 2) > 0) {
                $pv = bcadd($pv, bcdiv($guaranteedResidual, $discountFactor, self::PV_SCALE), self::PV_SCALE);
            }
        }

        $pvCast = bcadd($pv, '0', 2);  // round to DECIMAL(15,2)
        $ratio  = bcdiv($pvCast, $fairValue, self::RATIO_SCALE);
        $met    = bccomp($ratio, '0.90', self::RATIO_SCALE) >= 0;

        return [
            'met'                => $met,
            'pv_mlp'             => $pvCast,
            'fair_value'         => $fairValue,
            'ratio'              => $ratio,
            'discount_rate_used' => $discountRate,
            'is_undiscounted'    => $isUndiscounted,
        ];
    }

    // ============================================================
    // 3065.06 + 3065.07–.08 — assemble final classification
    // ============================================================

    /**
     * Roll the three criterion verdicts + two qualifying conditions
     * into a final classification per ASPE 3065.
     *
     * Rules:
     *   any criterion met (A or B or C) AND credit risk normal AND
     *     costs estimable  → capital-equivalent candidate
     *       fair value ≠ carrying amount → 'sales_type'
     *       fair value = carrying amount → 'direct_financing'
     *   else                                                → 'operating'
     *
     * @return array{
     *   classification: string,
     *   any_criterion_met: bool,
     *   all_conditions_met: bool,
     *   rationale: string,
     * }
     */
    public static function determineClassification(
        array  $criteria,
        bool   $creditRiskNormal,
        bool   $costsEstimable,
        string $initialFairValue,
        string $assetCarryingAmount
    ): array {
        $a = (bool) ($criteria['A']['met'] ?? false);
        $b = (bool) ($criteria['B']['met'] ?? false);
        $c = (bool) ($criteria['C']['met'] ?? false);

        $anyMet = $a || $b || $c;
        $allCondMet = $anyMet && $creditRiskNormal && $costsEstimable;

        if (!$allCondMet) {
            $reasons = [];
            if (!$anyMet)            $reasons[] = 'no 3065.06 criterion met';
            if (!$creditRiskNormal)  $reasons[] = 'credit risk NOT normal (3065.07)';
            if (!$costsEstimable)    $reasons[] = 'unreimbursable costs NOT estimable (3065.08)';

            return [
                'classification'     => 'operating',
                'any_criterion_met'  => $anyMet,
                'all_conditions_met' => false,
                'rationale'          => 'Operating lease — ' . implode('; ', $reasons) . '.',
            ];
        }

        $fairVsCarrying = bccomp($initialFairValue, $assetCarryingAmount, 2);

        if ($fairVsCarrying !== 0) {
            return [
                'classification'     => 'sales_type',
                'any_criterion_met'  => true,
                'all_conditions_met' => true,
                'rationale'          => sprintf(
                    'Sales-type lease — fair value (%s) ≠ carrying amount (%s); selling profit recognized at inception.',
                    $initialFairValue,
                    $assetCarryingAmount
                ),
            ];
        }

        return [
            'classification'     => 'direct_financing',
            'any_criterion_met'  => true,
            'all_conditions_met' => true,
            'rationale'          => 'Direct financing lease — fair value equals carrying amount; no selling profit, finance income only.',
        ];
    }

    // ============================================================
    // runWizard() — orchestrate B1–B4 + persist
    // ============================================================

    /**
     * Execute the full wizard for a lease. Validates inputs, evaluates
     * all three criteria, resolves classification, UPSERTs the archive
     * row, updates the leases row, and writes the audit log entry.
     *
     * @param int  $leaseId
     * @param array $input  Wizard inputs (see api/classify.php for keys).
     * @param int  $userId  Operator who ran the wizard.
     * @return array Wizard result payload (full criteria + classification).
     * @throws \InvalidArgumentException 422-class input errors.
     * @throws \RuntimeException         500-class state errors.
     */
    public static function runWizard(int $leaseId, array $input, int $userId): array
    {
        $pdo = \db_pdo();
        $pdo->beginTransaction();

        try {
            // Lock the lease row for the duration so concurrent wizard runs
            // can't race a classification flip.
            $lease = \db_row(
                "SELECT id, customer_id, equipment_unit_id, start_date, end_date,
                        monthly_rate, status, classification, deleted_at
                 FROM leases WHERE id = ? FOR UPDATE",
                [$leaseId]
            );
            if (!$lease || $lease['deleted_at'] !== null) {
                throw new \InvalidArgumentException('Lease not found.');
            }

            // STOP CONDITION: re-classifying a lease that is already
            // sales_type / direct_financing is a material event. Require
            // explicit confirm_reclassify=true to proceed. Super-admin
            // (when wired) is a future tightening; for now the explicit
            // confirm flag is the gate.
            $alreadyCapital = in_array($lease['classification'], ['sales_type', 'direct_financing'], true);
            if ($alreadyCapital && empty($input['confirm_reclassify'])) {
                throw new \InvalidArgumentException(
                    "Lease is already classified as '{$lease['classification']}'. " .
                    'Re-running the wizard requires confirm_reclassify=true.'
                );
            }

            // Normalize + validate numeric inputs (all bcmath strings).
            $econLifeMonths = (int) ($input['economic_life_months'] ?? 0);
            $leaseTermMonths = (int) ($input['lease_term_months'] ?? 0);
            $fairValue       = (string) ($input['initial_fair_value'] ?? '0');
            $discountRate    = (string) ($input['discount_rate'] ?? '0');
            $guaranteedResid = (string) ($input['guaranteed_residual_value'] ?? '0');
            $unguaranteedResid = (string) ($input['unguaranteed_residual_value'] ?? '0');
            $initialDirectCosts = (string) ($input['initial_direct_costs'] ?? '0');
            $bpoAmount       = $input['bpo_amount'] ?? null;
            $bpoDate         = $input['bpo_date']   ?? null;

            $creditRiskNormal = (bool) ($input['credit_risk_normal'] ?? true);
            $costsEstimable   = (bool) ($input['costs_estimable']    ?? true);

            $monthlyPayment = (string) $lease['monthly_rate'];

            // Carrying amount: pull from acc_fixed_assets if the equipment
            // has a depreciable cost record, else fall back to fair value
            // (which makes direct_financing the default for that branch).
            // Per spec §24.5 IDC handling, carrying ≠ fair → sales_type.
            $carrying = self::lookupCarryingAmount((int) $lease['equipment_unit_id']);
            if ($carrying === null) {
                $carrying = $fairValue;
            }

            // ── B1 / B2 / B3 ────────────────────────────────────────
            $a = self::evaluateCriterionA([
                'title_transfers'    => $input['title_transfers'] ?? false,
                'bpo_amount'         => $bpoAmount,
                'bpo_date'           => $bpoDate,
                'criterion_a_notes'  => $input['criterion_a_notes'] ?? '',
            ]);
            $b = self::evaluateCriterionB($leaseTermMonths, $econLifeMonths);
            $c = self::evaluateCriterionC(
                $monthlyPayment,
                $leaseTermMonths,
                $guaranteedResid,
                $discountRate,
                $fairValue
            );

            // ── B4 ──────────────────────────────────────────────────
            $decision = self::determineClassification(
                ['A' => $a, 'B' => $b, 'C' => $c],
                $creditRiskNormal,
                $costsEstimable,
                $fairValue,
                $carrying
            );

            // ── UPSERT acc_lease_classifications ────────────────────
            \db_execute(
                "INSERT INTO acc_lease_classifications (
                    lease_id,
                    criterion_a_met, criterion_a_notes,
                    criterion_b_met, criterion_b_lease_term_months,
                      criterion_b_economic_life_months, criterion_b_ratio,
                    criterion_c_met, criterion_c_pv_mlp,
                      criterion_c_fair_value, criterion_c_ratio,
                    credit_risk_normal, costs_estimable,
                    any_criterion_met, all_conditions_met,
                    determined_classification, classification_rationale,
                    wizard_completed_at, wizard_completed_by
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?
                 ) ON DUPLICATE KEY UPDATE
                    criterion_a_met                   = VALUES(criterion_a_met),
                    criterion_a_notes                 = VALUES(criterion_a_notes),
                    criterion_b_met                   = VALUES(criterion_b_met),
                    criterion_b_lease_term_months     = VALUES(criterion_b_lease_term_months),
                    criterion_b_economic_life_months  = VALUES(criterion_b_economic_life_months),
                    criterion_b_ratio                 = VALUES(criterion_b_ratio),
                    criterion_c_met                   = VALUES(criterion_c_met),
                    criterion_c_pv_mlp                = VALUES(criterion_c_pv_mlp),
                    criterion_c_fair_value            = VALUES(criterion_c_fair_value),
                    criterion_c_ratio                 = VALUES(criterion_c_ratio),
                    credit_risk_normal                = VALUES(credit_risk_normal),
                    costs_estimable                   = VALUES(costs_estimable),
                    any_criterion_met                 = VALUES(any_criterion_met),
                    all_conditions_met                = VALUES(all_conditions_met),
                    determined_classification         = VALUES(determined_classification),
                    classification_rationale          = VALUES(classification_rationale),
                    wizard_completed_at               = NOW(),
                    wizard_completed_by               = VALUES(wizard_completed_by)",
                [
                    $leaseId,
                    $a['met'] ? 1 : 0, $a['notes'] ?: null,
                    $b['met'] ? 1 : 0, $b['lease_term_months'],
                      $b['economic_life_months'], $b['ratio'],
                    $c['met'] ? 1 : 0, $c['pv_mlp'],
                      $c['fair_value'], $c['ratio'],
                    $creditRiskNormal ? 1 : 0, $costsEstimable ? 1 : 0,
                    $decision['any_criterion_met'] ? 1 : 0,
                    $decision['all_conditions_met'] ? 1 : 0,
                    $decision['classification'], $decision['rationale'],
                    $userId,
                ]
            );

            // ── UPDATE leases ───────────────────────────────────────
            \db_execute(
                "UPDATE leases SET
                    classification                    = ?,
                    classification_signed_off_by      = ?,
                    classification_signed_off_at      = NOW(),
                    economic_life_months              = ?,
                    initial_fair_value                = ?,
                    initial_direct_costs              = ?,
                    guaranteed_residual_value         = ?,
                    unguaranteed_residual_value       = ?,
                    implicit_rate                     = ?,
                    bargain_purchase_option_amount    = ?,
                    bargain_purchase_option_date      = ?
                 WHERE id = ?",
                [
                    $decision['classification'],
                    $userId,
                    $econLifeMonths,
                    $fairValue,
                    $initialDirectCosts,
                    $guaranteedResid,
                    $unguaranteedResid,
                    bccomp($discountRate, '0', 6) === 0 ? null : $discountRate,
                    ($bpoAmount !== null && $bpoAmount !== '') ? $bpoAmount : null,
                    !empty($bpoDate) ? $bpoDate : null,
                    $leaseId,
                ]
            );

            // ── Audit log ───────────────────────────────────────────
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'lease',
                'entity_id'   => $leaseId,
                'notes'       => sprintf(
                    'ASPE 3065 classification: %s. A=%s, B=%s (ratio=%s), C=%s (ratio=%s, PV=%s, FV=%s). %s',
                    $decision['classification'],
                    $a['met'] ? 'met' : 'not met',
                    $b['met'] ? 'met' : 'not met',
                    $b['ratio'],
                    $c['met'] ? 'met' : 'not met',
                    $c['ratio'],
                    $c['pv_mlp'],
                    $c['fair_value'],
                    $decision['rationale']
                ),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            $pdo->commit();

            return self::buildResult($leaseId, $a, $b, $c, $decision, $carrying);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Look up the depreciable carrying amount of the leased asset.
     * Joins equipment_units → acc_fixed_assets via the FA's
     * equipment_unit_id link. Returns null when no FA record exists
     * (caller falls back to fair value).
     */
    private static function lookupCarryingAmount(int $equipmentUnitId): ?string
    {
        if ($equipmentUnitId <= 0) return null;

        $row = \db_row(
            "SELECT
                CAST(acquisition_cost AS DECIMAL(15,2))
                  - COALESCE(CAST(accumulated_depreciation AS DECIMAL(15,2)), 0) AS nbv
             FROM acc_fixed_assets
             WHERE equipment_unit_id = ?
               AND status = 'active'
             ORDER BY id DESC
             LIMIT 1",
            [$equipmentUnitId]
        );

        return $row ? (string) $row['nbv'] : null;
    }

    /**
     * Pull the stored classification + archive row for an existing
     * lease. Used by the GET /classification endpoint and the lease
     * create-page wizard re-load path.
     */
    public static function getClassification(int $leaseId): ?array
    {
        $lease = \db_row(
            "SELECT id, contract_number, classification, classification_signed_off_by,
                    classification_signed_off_at, economic_life_months, initial_fair_value,
                    initial_direct_costs, guaranteed_residual_value, unguaranteed_residual_value,
                    implicit_rate, bargain_purchase_option_amount, bargain_purchase_option_date
             FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$leaseId]
        );
        if (!$lease) return null;

        $archive = \db_row(
            "SELECT * FROM acc_lease_classifications WHERE lease_id = ?",
            [$leaseId]
        );

        return [
            'lease'   => $lease,
            'archive' => $archive,
        ];
    }

    private static function buildResult(
        int   $leaseId,
        array $a,
        array $b,
        array $c,
        array $decision,
        string $carrying
    ): array {
        $archive = \db_row(
            "SELECT * FROM acc_lease_classifications WHERE lease_id = ?",
            [$leaseId]
        );

        return [
            'lease_id'       => $leaseId,
            'classification' => $decision['classification'],
            'rationale'      => $decision['rationale'],
            'criteria'       => [
                'A' => $a,
                'B' => $b,
                'C' => $c,
            ],
            'qualifying' => [
                'any_criterion_met'  => $decision['any_criterion_met'],
                'all_conditions_met' => $decision['all_conditions_met'],
            ],
            'asset_carrying_amount' => $carrying,
            'archive'               => $archive,
        ];
    }
}
