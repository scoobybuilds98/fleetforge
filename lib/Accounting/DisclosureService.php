<?php
declare(strict_types=1);

/**
 * lib/Accounting/DisclosureService.php
 *
 * ASPE compilation note pack generator per FLEETFORGE_ACCOUNTING_SPEC.md
 * §23.9 (Disclosure Note Builder). Generates 9 notes auto-driven by live
 * data + engagement settings; persists to acc_disclosure_notes with
 * is_auto_generated guard so manually-edited notes survive re-runs.
 *
 * Note inventory (ASPE Part II + spec §23.9):
 *   1 — Basis of Accounting
 *   2 — Significant Accounting Policies (revenue / PP&E / leases / FX / tax)
 *   3 — PP&E (cost, accum dep, NBV by asset_class)
 *   4 — Lease Commitments (5-year minimum payment waterfall)
 *   5 — Long-Term Debt
 *   6 — Related-Party Transactions
 *   7 — Commitments and Contingencies
 *   8 — Subsequent Events
 *   9 — Net Investment in Lease (stub until first capital lease originates)
 *
 * Each generateNoteN_*() produces a UTF-8 plain-text string. The string is
 * stored verbatim in acc_disclosure_notes.note_content and rendered into PDF
 * by ReportPdfRenderer::disclosureNotePack(). Plain-text format (not HTML)
 * keeps editing trivial and prevents PDF-render surprises.
 *
 * Re-run semantics (idempotent):
 *   - generateAll() iterates notes 1–9.
 *   - Each note is UPSERT'd into acc_disclosure_notes.
 *   - When the existing row has is_auto_generated=0 (manually edited),
 *     generation is SKIPPED. Operator override wins over auto-regen.
 *   - When a single-note generator throws, the placeholder
 *     "[Note N — Auto-generation failed: ...]" is written instead and the
 *     loop continues. STOP CONDITION compliant.
 *
 * Pre-flight column-name catches (K-22, locked in REFERENCE.md §11):
 *   - acc_fixed_assets.depreciation_method (not amortization_method)
 *   - acc_fixed_assets.asset_class (not asset_category)
 *   - acc_fixed_assets.useful_life_years (not _months)
 *   - acc_depreciation_run_lines.depreciation (the period dep amount)
 *   - acc_periods.year (not fiscal_year)
 *   - damage_claims.status ENUM: 'resolved'/'written_off' present,
 *     'settled' ABSENT. Note 7 filters NOT IN ('resolved','written_off').
 *   - leases NO classification column on disk → Note 9 emits stub.
 *
 * @session S-ACCT-DISC
 */

namespace FleetForge\Accounting;

class DisclosureService
{
    /** Note titles — kept in one place so generateAll() and the admin
     *  UI agree on labels. */
    public const NOTE_TITLES = [
        1 => 'Basis of Accounting',
        2 => 'Significant Accounting Policies',
        3 => 'Property, Plant and Equipment',
        4 => 'Lease Commitments',
        5 => 'Long-Term Debt',
        6 => 'Related-Party Transactions',
        7 => 'Commitments and Contingencies',
        8 => 'Subsequent Events',
        9 => 'Net Investment in Lease',
    ];

    /**
     * Auto-generate all 9 notes for $fiscalYear. Iterates each note, catches
     * per-note exceptions so a single failure cannot abort the run, and
     * skips notes already manually edited (is_auto_generated=0).
     *
     * @param int $fiscalYear
     * @param int $userId
     * @return array<int,array<string,mixed>>  the 9 acc_disclosure_notes rows
     */
    public static function generateAll(int $fiscalYear, int $userId): array
    {
        $existing = self::loadByYear($fiscalYear);
        $existingByNumber = [];
        foreach ($existing as $row) {
            $existingByNumber[(int) $row['note_number']] = $row;
        }

        foreach (range(1, 9) as $noteNumber) {
            $title = self::NOTE_TITLES[$noteNumber];

            // Guard: don't clobber manually-edited notes.
            if (
                isset($existingByNumber[$noteNumber])
                && (int) $existingByNumber[$noteNumber]['is_auto_generated'] === 0
            ) {
                continue;
            }

            try {
                $content = self::generateNote($noteNumber, $fiscalYear);
            } catch (\Throwable $e) {
                $content = "[Note {$noteNumber} — Auto-generation failed: "
                    . $e->getMessage() . ". Please edit manually.]";
                error_log(
                    "[DisclosureService] Note {$noteNumber} FY{$fiscalYear} failed: "
                    . $e->getMessage()
                );
            }

            self::upsert($fiscalYear, $noteNumber, $title, $content);
        }

        return self::loadByYear($fiscalYear);
    }

    /**
     * Dispatch a single note generator by number. Pure routing — the heavy
     * lifting is in generateNoteN_*() methods below.
     */
    public static function generateNote(int $noteNumber, int $fiscalYear): string
    {
        return match ($noteNumber) {
            1 => self::generateNote1_BasisOfAccounting($fiscalYear),
            2 => self::generateNote2_SignificantAccountingPolicies($fiscalYear),
            3 => self::generateNote3_PPE($fiscalYear),
            4 => self::generateNote4_LeaseCommitments($fiscalYear),
            5 => self::generateNote5_LongTermDebt($fiscalYear),
            6 => self::generateNote6_RelatedParty($fiscalYear),
            7 => self::generateNote7_CommitmentsContingencies($fiscalYear),
            8 => self::generateNote8_SubsequentEvents($fiscalYear),
            9 => self::generateNote9_NetInvestmentInLease($fiscalYear),
            default => throw new \InvalidArgumentException("Invalid note number: {$noteNumber}"),
        };
    }

    /**
     * Get a single note row. Returns null when missing.
     */
    public static function getNote(int $fiscalYear, int $noteNumber): ?array
    {
        $row = \db_row(
            "SELECT * FROM acc_disclosure_notes
              WHERE fiscal_year = ? AND note_number = ?",
            [$fiscalYear, $noteNumber]
        );
        return $row ?: null;
    }

    /**
     * Get all notes for a fiscal year, sorted by note_number ASC.
     */
    public static function loadByYear(int $fiscalYear): array
    {
        return \db_select(
            "SELECT * FROM acc_disclosure_notes
              WHERE fiscal_year = ?
              ORDER BY note_number ASC",
            [$fiscalYear]
        );
    }

    /**
     * Update a single note's content. Sets is_auto_generated=0 so future
     * generateAll() runs preserve this edit.
     */
    public static function updateNote(
        int $fiscalYear,
        int $noteNumber,
        string $content,
        int $userId
    ): array {
        $existing = self::getNote($fiscalYear, $noteNumber);
        if (!$existing) {
            throw new \RuntimeException("Note {$noteNumber} for FY{$fiscalYear} does not exist. Run generate first.");
        }

        \db_update(
            'acc_disclosure_notes',
            [
                'note_content'      => $content,
                'is_auto_generated' => 0,
                'edited_by'         => $userId,
                'edited_at'         => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [(int) $existing['id']]
        );

        return self::getNote($fiscalYear, $noteNumber) ?? [];
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 1 — Basis of Accounting
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote1_BasisOfAccounting(int $fiscalYear): string
    {
        $entity         = (string) \settings_get('accounting.entity_legal_name', 'The Company');
        $engagementType = (string) \settings_get('accounting.engagement_type', 'compilation');
        $fyEnd          = self::fiscalYearEndLabel($fiscalYear);

        $out  = "Note 1 — Basis of Accounting\n\n";
        $out .= "{$entity} prepares its financial statements in accordance with Accounting "
              . "Standards for Private Enterprises (ASPE) as set out in Part II of the CPA "
              . "Canada Handbook. This basis of accounting is a comprehensive basis of "
              . "accounting other than generally accepted accounting principles applicable "
              . "to publicly accountable enterprises.\n\n";

        if ($engagementType === 'compilation') {
            $out .= "These financial statements have not been audited or reviewed and "
                  . "readers are cautioned that these statements may not be appropriate "
                  . "for their purposes.\n\n";
        } elseif ($engagementType === 'review') {
            $out .= "A review engagement was performed on these financial statements. "
                  . "The accompanying review engagement report describes the practitioner's "
                  . "conclusions.\n\n";
        }

        $out .= "Going Concern: The financial statements have been prepared on a going "
              . "concern basis, which assumes that {$entity} will continue in operation for "
              . "the foreseeable future and will be able to realize its assets and discharge "
              . "its liabilities in the normal course of business through {$fyEnd}.\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 2 — Significant Accounting Policies
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote2_SignificantAccountingPolicies(int $fiscalYear): string
    {
        [$fyStart, $fyEnd] = self::fiscalYearWindow($fiscalYear);

        $out  = "Note 2 — Significant Accounting Policies\n\n";

        // 2a. Revenue Recognition by stream
        $out .= "(a) Revenue Recognition\n\n";
        $itemTypes = \db_select(
            "SELECT DISTINCT li.item_type
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status NOT IN ('void','draft')",
            [$fyStart, $fyEnd]
        );
        $types = array_map(static fn($r) => (string) $r['item_type'], $itemTypes);

        $policyLines = [];
        if (in_array('base_rental', $types, true)) {
            $policyLines[] = "Operating lease rentals are recognized on a straight-line basis "
                . "over the lease term, in accordance with ASPE 3065.";
        }
        if (array_intersect(['mileage_usage', 'mileage_overage', 'mileage_adjustment'], $types)) {
            $policyLines[] = "Mileage overage revenue is recognized as a contingent rental "
                . "in the period the amount becomes determinable.";
        }
        if (in_array('mileage_precharge', $types, true)) {
            $policyLines[] = "Mileage precharges collected at lease inception are recognized "
                . "as deferred revenue and drawn down against actual usage over the lease term.";
        }
        if (in_array('gps', $types, true)) {
            $gpsDefault = (string) \settings_get('accounting.gps_net_revenue_presentation_default', 'net');
            if ($gpsDefault === 'net') {
                $policyLines[] = "GPS recharge revenue is presented on a net basis (margin only) "
                    . "as the Company acts as agent in arranging GPS services with the third-party "
                    . "provider (ASPE 3400 agent presentation).";
            } else {
                $policyLines[] = "GPS recharge revenue is presented on a gross basis with the "
                    . "corresponding third-party cost included in operating expenses (ASPE 3400 "
                    . "principal presentation).";
            }
        }
        if (in_array('insurance', $types, true)) {
            $policyLines[] = "Insurance recharge revenue is recognized rateably over the lease "
                . "period to which the coverage relates.";
        }
        if (in_array('warranty', $types, true)) {
            $policyLines[] = "Warranty recharge revenue is recognized rateably over the warranty "
                . "period.";
        }
        if (in_array('late_fee', $types, true)) {
            $policyLines[] = "Late fees are recognized as revenue when assessed and collection is "
                . "reasonably assured.";
        }
        if (in_array('damage', $types, true)) {
            $policyLines[] = "Damage recovery revenue is recognized when the customer accepts "
                . "liability and collection is reasonably assured.";
        }
        if (!$policyLines) {
            $policyLines[] = "Revenue is recognized when earned, when the amount is determinable, "
                . "and when collection is reasonably assured.";
        }
        $out .= '    ' . implode("\n    ", $policyLines) . "\n\n";

        // 2b. PP&E and Depreciation
        $out .= "(b) Property, Plant and Equipment\n\n";
        $methods = \db_select(
            "SELECT DISTINCT depreciation_method
               FROM acc_fixed_assets
              WHERE status <> 'disposed' AND depreciation_method <> 'none'"
        );
        $methodCodes = array_map(static fn($r) => (string) $r['depreciation_method'], $methods);

        $ppeLines = [
            "Property, plant and equipment is recorded at cost less accumulated depreciation "
            . "and any accumulated impairment losses, in accordance with ASPE 3061."
        ];
        if (in_array('straight_line', $methodCodes, true)) {
            $ppeLines[] = "Depreciation of straight-line-method assets is recognized on a "
                . "straight-line basis over the estimated useful life of the asset.";
        }
        if (in_array('declining_balance', $methodCodes, true)) {
            $ppeLines[] = "Depreciation of declining-balance-method assets is calculated using "
                . "the declining balance method at rates that approximate the estimated useful "
                . "life of the asset.";
        }
        if (in_array('units_of_production', $methodCodes, true)) {
            $ppeLines[] = "Depreciation of units-of-production assets is recognized based on "
                . "actual usage relative to total expected lifetime units.";
        }
        $components = (int) (\db_row(
            "SELECT COUNT(*) AS n FROM acc_fixed_assets WHERE is_component = 1"
        )['n'] ?? 0);
        if ($components > 0) {
            $ppeLines[] = "Components with significantly different useful lives are amortized "
                . "separately, in accordance with ASPE 3061.18.";
        }
        $ppeLines[] = "Betterments that extend the useful life or increase the service potential "
            . "of an asset are capitalized. Repairs and maintenance that maintain the asset in "
            . "ordinary working condition are expensed as incurred (ASPE 3061.14).";
        $out .= '    ' . implode("\n    ", $ppeLines) . "\n\n";

        // 2c. Lease Classification (always include)
        $out .= "(c) Lease Classification\n\n";
        $out .= "    Leases are classified as operating, sales-type, or direct financing leases "
              . "in accordance with ASPE 3065. As at " . self::fiscalYearEndLabel($fiscalYear)
              . ", all leases originated by the Company are classified as operating leases. "
              . "Under operating leases, the underlying asset remains on the Company's balance "
              . "sheet and rental income is recognized on a straight-line basis over the lease "
              . "term.\n\n";

        // 2d. Foreign Currency — only when USD transactions present
        $hasUsd = self::hasUsdTransactions($fyStart, $fyEnd);
        if ($hasUsd) {
            $out .= "(d) Foreign Currency Translation\n\n";
            $out .= "    Foreign currency transactions are translated using the temporal method "
                  . "in accordance with ASPE 1651. Monetary assets and liabilities denominated "
                  . "in foreign currencies are translated at the exchange rate in effect at the "
                  . "balance sheet date. Non-monetary items are translated at historical rates. "
                  . "Exchange gains and losses arising on translation and settlement are included "
                  . "in income.\n\n";
        }

        // 2e. Income Taxes — always include
        $taxLabel = $hasUsd ? '(e)' : '(d)';
        $out .= "{$taxLabel} Income Taxes\n\n";
        $out .= "    The Company follows the taxes payable method of accounting for income taxes "
              . "as permitted under ASPE 3465. Under this method, only current income taxes are "
              . "recognized; future (deferred) income tax assets and liabilities are not recorded.\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 3 — Property, Plant and Equipment
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote3_PPE(int $fiscalYear): string
    {
        [$fyStart, $fyEnd] = self::fiscalYearWindow($fiscalYear);
        $priorYearEnd      = ($fiscalYear - 1) . '-12-31';

        $out  = "Note 3 — Property, Plant and Equipment\n\n";

        // Pull all assets relevant to the fiscal year: anything acquired on or
        // before $fyEnd that wasn't disposed before $fyStart. Then bucket by
        // asset_class. K-22: column name is asset_class (not asset_category).
        $assets = \db_select(
            "SELECT id, asset_class, acquisition_date, acquisition_cost,
                    accumulated_depreciation, net_book_value, status,
                    depreciation_method, useful_life_years, is_component
               FROM acc_fixed_assets
              WHERE acquisition_date <= ?",
            [$fyEnd]
        );

        $disposalsThisYear = \db_select(
            "SELECT d.asset_id, d.disposal_date, d.proceeds,
                    d.net_book_value_at_disposal, a.asset_class, a.acquisition_cost
               FROM acc_asset_disposals d
               JOIN acc_fixed_assets a ON a.id = d.asset_id
              WHERE d.disposal_date BETWEEN ? AND ?",
            [$fyStart, $fyEnd]
        );

        // Current year depreciation, summed per asset, from posted run lines.
        // K-22: column is `depreciation` (not amount). join through period and run.
        $depByAsset = [];
        $depRows = \db_select(
            "SELECT rl.asset_id, COALESCE(SUM(rl.depreciation), 0) AS total
               FROM acc_depreciation_run_lines rl
               JOIN acc_depreciation_runs r ON r.id = rl.run_id
               JOIN acc_periods p ON p.id = rl.period_id
              WHERE p.year = ?
                AND r.status = 'posted'
              GROUP BY rl.asset_id"
        , [$fiscalYear]);
        foreach ($depRows as $r) {
            $depByAsset[(int) $r['asset_id']] = (string) $r['total'];
        }

        $disposalsByAsset = [];
        foreach ($disposalsThisYear as $d) {
            $disposalsByAsset[(int) $d['asset_id']] = $d;
        }

        // Bucket by class — sum opening/additions/disposals/dep
        $buckets = [];
        foreach ($assets as $a) {
            $class = (string) $a['asset_class'];
            $id    = (int) $a['id'];
            $cost  = (string) $a['acquisition_cost'];
            $accum = (string) $a['accumulated_depreciation'];

            $buckets[$class] ??= [
                'class'           => $class,
                'opening_cost'    => '0.00',
                'additions'       => '0.00',
                'disposals_cost'  => '0.00',
                'closing_cost'    => '0.00',
                'opening_accum'   => '0.00',
                'current_dep'     => '0.00',
                'disposals_accum' => '0.00',
                'closing_accum'   => '0.00',
                'closing_nbv'     => '0.00',
                'asset_count'     => 0,
            ];
            $b = &$buckets[$class];

            $acqDate = (string) $a['acquisition_date'];
            $isAddition = ($acqDate >= $fyStart);

            if ($isAddition) {
                $b['additions']    = bcadd($b['additions'], $cost, 2);
            } else {
                $b['opening_cost'] = bcadd($b['opening_cost'], $cost, 2);
                $b['opening_accum'] = bcadd(
                    $b['opening_accum'],
                    self::priorYearAccumulated($id, $accum, $depByAsset[$id] ?? '0.00'),
                    2
                );
            }

            $b['current_dep'] = bcadd($b['current_dep'], $depByAsset[$id] ?? '0.00', 2);

            if (isset($disposalsByAsset[$id])) {
                $b['disposals_cost']  = bcadd($b['disposals_cost'], $cost, 2);
                $b['disposals_accum'] = bcadd(
                    $b['disposals_accum'],
                    (string) ($disposalsByAsset[$id]['net_book_value_at_disposal'] ?? '0.00') !== ''
                        ? bcsub($cost, (string) ($disposalsByAsset[$id]['net_book_value_at_disposal'] ?? '0.00'), 2)
                        : '0.00',
                    2
                );
            }
            $b['asset_count']++;
            unset($b);
        }

        if (!$buckets) {
            $out .= "The Company has no property, plant and equipment as at "
                  . self::fiscalYearEndLabel($fiscalYear) . ".\n";
            return $out;
        }

        // Compute closing values
        $grandClosingCost  = '0.00';
        $grandClosingAccum = '0.00';
        $grandClosingNbv   = '0.00';
        foreach ($buckets as &$b) {
            $b['closing_cost']  = bcsub(bcadd($b['opening_cost'], $b['additions'], 2), $b['disposals_cost'], 2);
            $b['closing_accum'] = bcsub(bcadd($b['opening_accum'], $b['current_dep'], 2), $b['disposals_accum'], 2);
            $b['closing_nbv']   = bcsub($b['closing_cost'], $b['closing_accum'], 2);
            $grandClosingCost   = bcadd($grandClosingCost, $b['closing_cost'], 2);
            $grandClosingAccum  = bcadd($grandClosingAccum, $b['closing_accum'], 2);
            $grandClosingNbv    = bcadd($grandClosingNbv, $b['closing_nbv'], 2);
        }
        unset($b);

        // Render
        $out .= "Property, plant and equipment as at " . self::fiscalYearEndLabel($fiscalYear)
              . " (in CAD):\n\n";

        foreach ($buckets as $b) {
            $label = self::classLabel($b['class']);
            $out .= "  {$label} ({$b['asset_count']} asset" . ($b['asset_count'] === 1 ? '' : 's') . "):\n";
            $out .= "    Opening cost:                  " . self::money($b['opening_cost']) . "\n";
            $out .= "    Additions:                     " . self::money($b['additions']) . "\n";
            $out .= "    Disposals (at cost):           " . self::money($b['disposals_cost']) . "\n";
            $out .= "    Closing cost:                  " . self::money($b['closing_cost']) . "\n";
            $out .= "    Opening accumulated dep:       " . self::money($b['opening_accum']) . "\n";
            $out .= "    Current year depreciation:     " . self::money($b['current_dep']) . "\n";
            $out .= "    Disposals (accum dep):         " . self::money($b['disposals_accum']) . "\n";
            $out .= "    Closing accumulated dep:       " . self::money($b['closing_accum']) . "\n";
            $out .= "    Net book value:                " . self::money($b['closing_nbv']) . "\n\n";
        }

        $out .= "  Total Property, Plant and Equipment:\n";
        $out .= "    Closing cost:                  " . self::money($grandClosingCost) . "\n";
        $out .= "    Closing accumulated dep:       " . self::money($grandClosingAccum) . "\n";
        $out .= "    Net book value:                " . self::money($grandClosingNbv) . "\n";

        return $out;
    }

    /**
     * Approximate prior-year accumulated depreciation: closing accum minus
     * current-year dep. Naïve but defensible — corrections come from manual
     * editing. Returns string clamped at 0.00.
     */
    private static function priorYearAccumulated(int $assetId, string $closingAccum, string $currentYearDep): string
    {
        $prior = bcsub($closingAccum, $currentYearDep, 2);
        return bccomp($prior, '0', 2) < 0 ? '0.00' : $prior;
    }

    private static function classLabel(string $assetClass): string
    {
        return match ($assetClass) {
            'fleet_equipment'         => 'Fleet equipment',
            'vehicles'                => 'Vehicles',
            'office_equipment'        => 'Office equipment',
            'leasehold_improvements'  => 'Leasehold improvements',
            'land'                    => 'Land',
            'building'                => 'Buildings',
            'other'                   => 'Other',
            default                   => ucfirst(str_replace('_', ' ', $assetClass)),
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 4 — Lease Commitments
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote4_LeaseCommitments(int $fiscalYear): string
    {
        [$fyStart, $fyEnd] = self::fiscalYearWindow($fiscalYear);

        $out  = "Note 4 — Lease Commitments\n\n";

        $activeLeases = \db_select(
            "SELECT id, contract_number, monthly_rate, start_date, end_date, status
               FROM leases
              WHERE status = 'active'
                AND (end_date IS NULL OR end_date > ?)
                AND deleted_at IS NULL",
            [$fyEnd]
        );

        if (!$activeLeases) {
            $out .= "The Company has no operating lease commitments as at "
                  . self::fiscalYearEndLabel($fiscalYear) . ".\n";
        } else {
            // 5-year waterfall + thereafter — operator (lessor) view: future
            // MINIMUM rent receivable on operating leases. Stops at end_date.
            $waterfall = [
                'y1' => '0.00', 'y2' => '0.00', 'y3' => '0.00',
                'y4' => '0.00', 'y5' => '0.00', 'thereafter' => '0.00',
            ];
            for ($lease = 0, $leaseCount = count($activeLeases); $lease < $leaseCount; $lease++) {
                $l = $activeLeases[$lease];
                $monthlyRate = (string) $l['monthly_rate'];
                if (bccomp($monthlyRate, '0', 2) === 0) {
                    continue;
                }
                $endDate = $l['end_date'] ?? null;
                for ($yr = 1; $yr <= 6; $yr++) {
                    $windowStart = ($fiscalYear + $yr - 1) . '-12-31';
                    $windowEnd   = ($fiscalYear + $yr) . '-12-31';
                    $months = self::monthsInWindow(
                        max($fyEnd, $windowStart),
                        $endDate ? min($endDate, $windowEnd) : $windowEnd
                    );
                    if ($months <= 0) continue;
                    $annual = bcmul($monthlyRate, (string) $months, 2);
                    if ($yr <= 5) {
                        $key = 'y' . $yr;
                        $waterfall[$key] = bcadd($waterfall[$key], $annual, 2);
                    } else {
                        // thereafter — collapse anything from year 6+ if
                        // a lease still has time left. Conservative: only
                        // include if end_date > fy+5 end.
                        if ($endDate && $endDate > (($fiscalYear + 5) . '-12-31')) {
                            $waterfall['thereafter'] = bcadd($waterfall['thereafter'], $annual, 2);
                        }
                    }
                }
            }

            $total = '0.00';
            foreach ($waterfall as $v) $total = bcadd($total, $v, 2);

            $out .= "Future minimum lease payments receivable under operating leases (in CAD):\n\n";
            $out .= "  Year ending " . self::fiscalYearEndLabel($fiscalYear + 1) . ":           "
                  . self::money($waterfall['y1']) . "\n";
            $out .= "  Year ending " . self::fiscalYearEndLabel($fiscalYear + 2) . ":           "
                  . self::money($waterfall['y2']) . "\n";
            $out .= "  Year ending " . self::fiscalYearEndLabel($fiscalYear + 3) . ":           "
                  . self::money($waterfall['y3']) . "\n";
            $out .= "  Year ending " . self::fiscalYearEndLabel($fiscalYear + 4) . ":           "
                  . self::money($waterfall['y4']) . "\n";
            $out .= "  Year ending " . self::fiscalYearEndLabel($fiscalYear + 5) . ":           "
                  . self::money($waterfall['y5']) . "\n";
            $out .= "  Thereafter:                                "
                  . self::money($waterfall['thereafter']) . "\n";
            $out .= "  Total:                                     " . self::money($total) . "\n\n";
            $out .= "Based on " . count($activeLeases) . " active operating lease"
                  . (count($activeLeases) === 1 ? '' : 's')
                  . " as at " . self::fiscalYearEndLabel($fiscalYear) . ".\n\n";
        }

        // Contingent rentals (mileage overage) recognized in the period
        $contingent = \db_row(
            "SELECT COALESCE(SUM(li.amount), 0) AS total
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.invoice_date BETWEEN ? AND ?
                AND i.status NOT IN ('void','draft')
                AND li.item_type IN ('mileage_usage','mileage_adjustment')",
            [$fyStart, $fyEnd]
        );
        $contingentAmt = (string) ($contingent['total'] ?? '0.00');

        $out .= "Contingent rentals (mileage overage) included in rental income for the "
              . "year ended " . self::fiscalYearEndLabel($fiscalYear) . ": "
              . self::money($contingentAmt) . ".\n";

        return $out;
    }

    /**
     * Whole-month count between two dates (inclusive, calendar months).
     * Returns 0 when from > to.
     */
    private static function monthsInWindow(string $from, string $to): int
    {
        if ($from > $to) return 0;
        $fromDt = new \DateTime($from);
        $toDt   = new \DateTime($to);
        $iv     = $fromDt->diff($toDt);
        $months = ($iv->y * 12) + $iv->m;
        if ($iv->d > 0) $months++;
        return $months;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 5 — Long-Term Debt
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote5_LongTermDebt(int $fiscalYear): string
    {
        $fyEnd = ($fiscalYear) . '-12-31';

        $out  = "Note 5 — Long-Term Debt\n\n";

        // Long-term debt: account_type=liability, code prefix 25xx (per CRA
        // convention used in our COA). Sum balance as of FY end.
        $accounts = \db_select(
            "SELECT id, code, name
               FROM acc_accounts
              WHERE account_type = 'liability'
                AND code LIKE '25%'
                AND is_active = 1
              ORDER BY code ASC"
        );

        if (!$accounts) {
            $out .= "The Company has no long-term debt as at "
                  . self::fiscalYearEndLabel($fiscalYear) . ".\n";
            return $out;
        }

        $grandTotal = '0.00';
        $detailRows = [];
        foreach ($accounts as $a) {
            $balance = \FleetForge\Accounting\AccountingService::accountBalance(
                (int) $a['id'], $fyEnd
            );
            if (bccomp($balance, '0', 2) === 0) continue;
            $grandTotal = bcadd($grandTotal, $balance, 2);
            $detailRows[] = [
                'code'    => $a['code'],
                'name'    => $a['name'],
                'balance' => $balance,
            ];
        }

        if (bccomp($grandTotal, '0', 2) === 0) {
            $out .= "The Company has no long-term debt as at "
                  . self::fiscalYearEndLabel($fiscalYear) . ".\n";
            return $out;
        }

        $out .= "Long-term debt as at " . self::fiscalYearEndLabel($fiscalYear) . ":\n\n";
        foreach ($detailRows as $r) {
            $out .= "  {$r['code']} — {$r['name']}: " . self::money($r['balance']) . "\n";
            $out .= "    Maturity date: [Add maturity date]\n";
            $out .= "    Interest rate: [Add interest rate]\n";
            $out .= "    Security:      [Add security / collateral]\n";
            $out .= "    Current portion (next 12 months): [Add]\n\n";
        }
        $out .= "  Total long-term debt: " . self::money($grandTotal) . "\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 6 — Related-Party Transactions
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote6_RelatedParty(int $fiscalYear): string
    {
        [$fyStart, $fyEnd] = self::fiscalYearWindow($fiscalYear);

        $out  = "Note 6 — Related-Party Transactions\n\n";

        // Defensive: confirm the columns exist before querying (post-migration
        // they should — but per STOP CONDITION, fall back to a non-error stub).
        if (!self::columnExists('customers', 'is_related_party')
            || !self::columnExists('vendors', 'is_related_party')) {
            $out .= "No related-party transactions identified for the year ended "
                  . self::fiscalYearEndLabel($fiscalYear) . ". Review and edit "
                  . "manually before issuing financial statements.\n";
            return $out;
        }

        $relatedCustomers = \db_select(
            "SELECT id, company_name FROM customers
              WHERE is_related_party = 1 AND deleted_at IS NULL
              ORDER BY company_name"
        );
        $relatedVendors = \db_select(
            "SELECT id, name FROM vendors
              WHERE is_related_party = 1 AND deleted_at IS NULL
              ORDER BY name"
        );

        if (!$relatedCustomers && !$relatedVendors) {
            $out .= "During the year ended " . self::fiscalYearEndLabel($fiscalYear)
                  . ", the Company had no related-party transactions.\n";
            return $out;
        }

        $out .= "During the year ended " . self::fiscalYearEndLabel($fiscalYear)
              . ", the Company entered into the following transactions with related parties. "
              . "Transactions were in the normal course of operations and recorded at the "
              . "exchange amounts agreed to by the parties (ASPE 3840).\n\n";

        if ($relatedCustomers) {
            $out .= "Sales to related parties:\n\n";
            foreach ($relatedCustomers as $c) {
                $rev = \db_row(
                    "SELECT COALESCE(SUM(total_amount), 0) AS rev
                       FROM invoices
                      WHERE customer_id = ?
                        AND invoice_date BETWEEN ? AND ?
                        AND status NOT IN ('void','draft')",
                    [(int) $c['id'], $fyStart, $fyEnd]
                );
                $ar = \db_row(
                    "SELECT COALESCE(SUM(total_amount - amount_paid), 0) AS bal
                       FROM invoices
                      WHERE customer_id = ?
                        AND status IN ('sent','partially_paid','overdue')
                        AND invoice_date <= ?",
                    [(int) $c['id'], $fyEnd]
                );
                $out .= "  {$c['company_name']}\n";
                $out .= "    Nature of relationship: [Describe relationship]\n";
                $out .= "    Revenue during period:  " . self::money((string) $rev['rev']) . "\n";
                $out .= "    Outstanding AR at year-end: " . self::money((string) $ar['bal']) . "\n\n";
            }
        }

        if ($relatedVendors) {
            $out .= "Purchases from related parties:\n\n";
            foreach ($relatedVendors as $v) {
                $pur = \db_row(
                    "SELECT COALESCE(SUM(total_amount), 0) AS pur
                       FROM acc_bills
                      WHERE vendor_id = ?
                        AND bill_date BETWEEN ? AND ?
                        AND status NOT IN ('void','draft')",
                    [(int) $v['id'], $fyStart, $fyEnd]
                );
                $out .= "  {$v['name']}\n";
                $out .= "    Nature of relationship: [Describe relationship]\n";
                $out .= "    Purchases during period: " . self::money((string) ($pur['pur'] ?? '0.00')) . "\n\n";
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 7 — Commitments and Contingencies
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote7_CommitmentsContingencies(int $fiscalYear): string
    {
        $fyEnd = ($fiscalYear) . '-12-31';

        $out  = "Note 7 — Commitments and Contingencies\n\n";

        // Open damage claims. K-22: damage_claims.status has no 'settled'
        // value — open = status NOT IN ('resolved','written_off').
        $openClaims = \db_select(
            "SELECT claim_number, status, estimated_repair_cost, actual_repair_cost
               FROM damage_claims
              WHERE status NOT IN ('resolved','written_off')
                AND created_at <= ?
                AND deleted_at IS NULL
              ORDER BY created_at DESC",
            [$fyEnd . ' 23:59:59']
        );

        if (!$openClaims) {
            $out .= "Commitments: The Company has no material operating or capital "
                  . "commitments other than those disclosed in Note 4 (Lease Commitments) "
                  . "as at " . self::fiscalYearEndLabel($fiscalYear) . ".\n\n";
        } else {
            $out .= "Commitments — open damage claims at " . self::fiscalYearEndLabel($fiscalYear) . ":\n\n";
            $totalEstimated = '0.00';
            foreach ($openClaims as $c) {
                $est = (string) ($c['actual_repair_cost'] ?? $c['estimated_repair_cost'] ?? '0.00');
                if ($est === '') $est = '0.00';
                $totalEstimated = bcadd($totalEstimated, $est, 2);
                $out .= "  {$c['claim_number']} ({$c['status']}): " . self::money($est) . "\n";
            }
            $out .= "  Total exposure: " . self::money($totalEstimated) . "\n\n";
        }

        $out .= "Contingencies: The Company is not aware of any claims or legal actions "
              . "against it as at " . self::fiscalYearEndLabel($fiscalYear) . " that have not "
              . "been disclosed elsewhere in these financial statements.\n\n";
        $out .= "[Edit before finalizing: add any known legal matters, regulatory inquiries, "
              . "or contingent liabilities here.]\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 8 — Subsequent Events
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote8_SubsequentEvents(int $fiscalYear): string
    {
        $fyEnd = self::fiscalYearEndLabel($fiscalYear);

        $out  = "Note 8 — Subsequent Events\n\n";
        $out .= "Management has evaluated subsequent events from {$fyEnd} through "
              . "[date these financial statements are issued], being the date these "
              . "financial statements were available to be issued.\n\n";
        $out .= "[Describe any material subsequent events here, or retain the following "
              . "statement if there are none:]\n\n";
        $out .= "Management is not aware of any subsequent events that would require "
              . "recognition or disclosure in these financial statements.\n\n";
        $out .= "[Edit before finalizing.]\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // NOTE 9 — Net Investment in Lease
    // ──────────────────────────────────────────────────────────────────────

    private static function generateNote9_NetInvestmentInLease(int $fiscalYear): string
    {
        $fyEnd = self::fiscalYearEndLabel($fiscalYear);

        $out  = "Note 9 — Net Investment in Lease\n\n";

        // K-22: leases table has NO classification column on disk (capital
        // leases are Phase D / S-LESSOR-3065 scope). Always stub for now.
        $hasClassificationColumn = self::columnExists('leases', 'classification');

        if (!$hasClassificationColumn) {
            $out .= "The Company has no sales-type or direct financing leases as at "
                  . "{$fyEnd}. This note will become applicable upon origination of "
                  . "the first capital lease under the Company's lease-to-own program "
                  . "(ASPE 3065). The lessor capital-lease module is staged but inactive.\n";
            return $out;
        }

        $count = (int) (\db_row(
            "SELECT COUNT(*) AS n FROM leases
              WHERE classification IN ('sales_type','direct_financing')
                AND status = 'active'
                AND deleted_at IS NULL"
        )['n'] ?? 0);

        if ($count === 0) {
            $out .= "The Company has no sales-type or direct financing leases as at "
                  . "{$fyEnd}.\n";
            return $out;
        }

        $out .= "The Company has {$count} capital lease"
              . ($count === 1 ? '' : 's')
              . " as at {$fyEnd}. Per ASPE 3065.54, net investment in lease is presented "
              . "segregated between current and long-term portions on the balance sheet.\n\n";
        $out .= "[Detailed schedule pending — capital-lease module integration in progress.]\n";

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /** Insert or update a single note row. */
    private static function upsert(int $fiscalYear, int $noteNumber, string $title, string $content): void
    {
        \db_execute(
            "INSERT INTO acc_disclosure_notes
                (fiscal_year, note_number, note_title, note_content, is_auto_generated)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                note_title   = VALUES(note_title),
                note_content = VALUES(note_content),
                is_auto_generated = 1,
                edited_by   = NULL,
                edited_at   = NULL",
            [$fiscalYear, $noteNumber, $title, $content]
        );
    }

    /** [fy_start, fy_end] from the entity_fiscal_year_end setting. */
    private static function fiscalYearWindow(int $fiscalYear): array
    {
        $fyEndMmDd = (string) \settings_get('accounting.entity_fiscal_year_end', '12-31');
        if (!preg_match('/^\d{2}-\d{2}$/', $fyEndMmDd)) $fyEndMmDd = '12-31';
        // For "12-31", FY 2025 = 2025-01-01 to 2025-12-31. For e.g. "06-30",
        // FY 2025 ends 2025-06-30 and starts 2024-07-01. We compute generically.
        $fyEnd = $fiscalYear . '-' . $fyEndMmDd;
        $fyEndDt = new \DateTime($fyEnd);
        $fyStartDt = (clone $fyEndDt)->modify('-1 year')->modify('+1 day');
        return [$fyStartDt->format('Y-m-d'), $fyEndDt->format('Y-m-d')];
    }

    private static function fiscalYearEndLabel(int $fiscalYear): string
    {
        [$_, $end] = self::fiscalYearWindow($fiscalYear);
        return date('F j, Y', strtotime($end));
    }

    private static function hasUsdTransactions(string $fyStart, string $fyEnd): bool
    {
        $usdInvoices = (int) (\db_row(
            "SELECT COUNT(*) AS n FROM invoices
              WHERE currency = 'USD'
                AND invoice_date BETWEEN ? AND ?
                AND status NOT IN ('void','draft')",
            [$fyStart, $fyEnd]
        )['n'] ?? 0);
        if ($usdInvoices > 0) return true;

        $usdLeases = (int) (\db_row(
            "SELECT COUNT(*) AS n FROM leases
              WHERE currency = 'USD'
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)
                AND deleted_at IS NULL",
            [$fyEnd, $fyStart]
        )['n'] ?? 0);
        return $usdLeases > 0;
    }

    /** Best-effort column existence check via INFORMATION_SCHEMA. */
    private static function columnExists(string $table, string $column): bool
    {
        $row = \db_row(
            "SELECT COUNT(*) AS n FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (int) ($row['n'] ?? 0) > 0;
    }

    /** Format a bcmath-string as $1,234.56 with negative sign in front. */
    private static function money(string $val): string
    {
        if ($val === '' || $val === null) $val = '0.00';
        $sign = bccomp($val, '0', 2) < 0 ? '-' : '';
        $abs  = ltrim($val, '-');
        return $sign . '$' . number_format((float) $abs, 2, '.', ',');
    }
}
