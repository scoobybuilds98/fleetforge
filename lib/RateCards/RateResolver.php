<?php
declare(strict_types=1);

/**
 * lib/RateCards/RateResolver.php
 *
 * The ONE place that decides which price a customer pays for an equipment
 * type on a given day (S-RATES-MODULE).
 *
 * WHY a class: the lease form's lookup (api/v1/leases/lookup_rates.php) and
 * the Rates module's Price check / "what they pay" views must never disagree.
 * Before S-RATES-MODULE the lookup SQL lived inline in lookup_rates.php, so a
 * second screen that wanted to explain a price would have had to copy it — and
 * copies drift. Both now call resolve(); explain() adds the reasoning the
 * Price check shows (every candidate line, why the winner won, and what the
 * customer would pay without a card of their own).
 *
 * Resolution order (unchanged from lookup_rates.php, S-RATES-CONSOLIDATE):
 *   1. an in-force rate-card line for the equipment type — this customer's own
 *      cards before general cards; a line for the exact equipment type before a
 *      whole-category line; then the main price list (is_default); then the
 *      latest start date. S-RATES-MODULE adds rc.id DESC, rci.id DESC as final
 *      tie-breakers so an exact tie is deterministic (newest card wins) — the
 *      old LIMIT 1 returned whichever row MySQL happened to read first.
 *   2. the equipment type's own default prices (equipment_templates.default_*)
 *   3. nothing.
 *
 * Minimum-only lines (S-RATES-MINIMUM-OVERLAY): a line that carries no price
 * at all (every PRICE_FIELDS column blank or $0) — e.g. general card #27
 * "Chassis Minimum Days", a whole-category chassis line with only
 * minimum_days=3 — never wins on PRICE. Before this it outranked the equipment
 * type's defaults and pre-filled every chassis lease for a customer without a
 * chassis card of their own with blank prices. Now prices come from the first
 * line that HAS a price (else tier 2 / 3), and minimum_days comes from the
 * first line, ranked at or above that priced line, that sets one — so a
 * minimum-only line OVERLAYS its minimum onto the next tier's prices. A line
 * with any price still wins outright and prices are never mixed field by
 * field (operator decision 2026-09-24). Lines ranked below the priced line
 * never contribute a minimum (unchanged: a priced winner with a blank
 * minimum still falls back to the 'lease.minimum_billing_days' setting).
 *
 * "In force" = effective_from <= date AND (effective_to IS NULL OR
 * effective_to >= date), cards not soft-deleted (D5).
 *
 * quote() runs the REAL billing law (HolisticLeaseEngine::cumulativeCorrect)
 * over a hypothetical rental so the Price check's estimate is the number the
 * engine would bill, not a re-implementation of it.
 *
 * Money stays in bcmath strings throughout (D16).
 *
 * @depends  includes/db.php, includes/functions.php (bcround, settings_get),
 *           lib/Billing/HolisticLeaseEngine.php
 * @session  S-RATES-MODULE
 */

namespace FleetForge\RateCards;

use FleetForge\Billing\HolisticLeaseEngine;

final class RateResolver
{
    /** Every price column a rate-card line carries, in display order. */
    public const PRICE_FIELDS = ['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'hourly_rate', 'gps_price'];

    /**
     * The equipment-type row the resolver needs (soft-deleted → null).
     *
     * @return array<string,mixed>|null
     */
    public static function template(int $templateId): ?array
    {
        return \db_row(
            "SELECT id, name, category, category_id, is_active,
                    default_daily_rate, default_weekly_rate, default_monthly_rate,
                    default_mileage_rate, default_mileage_unit, default_currency,
                    default_hourly_rate
               FROM equipment_templates
              WHERE id = ? AND deleted_at IS NULL",
            [$templateId]
        ) ?: null;
    }

    /**
     * Every in-force rate-card line that could price $template for
     * $customerId on $date, best first. Row 0 is what a new lease gets.
     *
     * $customerId NULL = a customer with no card of their own (general cards
     * only) — the "standard price".
     *
     * @param  array<string,mixed> $template row from template()
     * @return list<array<string,mixed>>
     */
    public static function candidates(?int $customerId, array $template, string $date): array
    {
        $params = [(int) $template['id'], (string) $template['category'], (string) $template['category'], $date, $date];
        $scope  = 'rc.customer_id IS NULL';
        if ($customerId !== null) {
            $scope    = '(rc.customer_id = ? OR rc.customer_id IS NULL)';
            $params[] = $customerId;
        }

        return \db_select(
            "SELECT rci.id AS item_id, rci.equipment_type, rci.equipment_template_id,
                    rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
                    rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price,
                    rci.currency, rci.minimum_days,
                    rc.id AS rate_card_id, rc.name AS card_name, rc.customer_id,
                    rc.is_default, rc.effective_from, rc.effective_to
               FROM rate_card_items rci
               JOIN rate_cards rc ON rc.id = rci.rate_card_id
              WHERE (
                      (rci.equipment_template_id = ? AND rci.equipment_type = ?)
                      OR
                      (rci.equipment_template_id IS NULL AND rci.equipment_type = ?)
                    )
                AND rc.deleted_at IS NULL
                AND rc.effective_from <= ?
                AND (rc.effective_to IS NULL OR rc.effective_to >= ?)
                AND {$scope}
              ORDER BY
                    (rc.customer_id IS NOT NULL) DESC,
                    (rci.equipment_template_id IS NOT NULL) DESC,
                    rc.is_default DESC,
                    rc.effective_from DESC,
                    rc.id DESC,
                    rci.id DESC",
            $params
        );
    }

    /**
     * Does a rate-card line (or candidates() row) carry any price at all?
     *
     * A price = any PRICE_FIELDS column above $0. A line with none of them is
     * a minimum-only line (or an empty one) — it can set minimum_days but
     * never the price (S-RATES-MINIMUM-OVERLAY). mileage_rate 0 already means
     * "no distance charge" (D135), so > 0 is the test for every field.
     *
     * @param array<string,mixed> $row
     */
    public static function hasPrices(array $row): bool
    {
        foreach (self::PRICE_FIELDS as $f) {
            $v = $row[$f] ?? null;
            if ($v !== null && $v !== '' && bccomp((string) $v, '0', 4) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Index of the candidate that sets the PRICE — the first line with any
     * price — or null when no candidate carries one (tier 2 / 3 prices it).
     *
     * @param list<array<string,mixed>> $candidates from candidates()
     */
    public static function priceWinnerIndex(array $candidates): ?int
    {
        foreach ($candidates as $i => $c) {
            if (self::hasPrices($c)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The candidate that sets the price (see priceWinnerIndex), or null.
     *
     * @param  list<array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    public static function priceWinner(array $candidates): ?array
    {
        $i = self::priceWinnerIndex($candidates);
        return $i === null ? null : $candidates[$i];
    }

    /**
     * Index of the candidate whose minimum_days applies: the first line ranked
     * at or above the price-setting line (every line when none has a price)
     * whose minimum_days is set. 0 counts as set (an explicit "no minimum").
     * Null = no line sets one → the 'lease.minimum_billing_days' setting.
     *
     * @param list<array<string,mixed>> $candidates
     */
    public static function minimumIndex(array $candidates): ?int
    {
        $upTo = self::priceWinnerIndex($candidates) ?? (count($candidates) - 1);
        for ($i = 0; $i <= $upTo; $i++) {
            $m = $candidates[$i]['minimum_days'] ?? null;
            if ($m !== null && $m !== '') {
                return $i;
            }
        }
        return null;
    }

    /**
     * The price a new lease gets — the exact lookup_rates.php response shape
     * (same keys, same order, same label wording).
     *
     * S-RATES-MINIMUM-OVERLAY: prices come from the first candidate WITH a
     * price (else the equipment type's defaults, else none); minimum_days from
     * minimumIndex(). When the minimum comes from a different card than the
     * prices, source_label says so ("· 3-day minimum from card "X"").
     *
     * @param  array<string,mixed>            $template   row from template()
     * @param  list<array<string,mixed>>|null $candidates pass when already loaded
     * @return array<string,mixed>
     */
    public static function resolve(?int $customerId, array $template, string $date, ?array $candidates = null): array
    {
        $candidates ??= self::candidates($customerId, $template, $date);

        // S-RATE-CARD-LABEL-EQTYPE: the equipment type's name leads the label so
        // it is clear WHICH line of a card was used.
        $typeLabel = (string) $template['name'];

        $r       = self::priceWinner($candidates);
        $minIdx  = self::minimumIndex($candidates);
        $minLine = $minIdx === null ? null : $candidates[$minIdx];
        $minimum = $minLine['minimum_days'] ?? null;

        // "· 3-day minimum from card "X"" — only when a minimum-only line on
        // ANOTHER card (or over the type defaults) supplied it.
        $minNote = '';
        if ($minLine !== null && ($r === null || (int) $minLine['rate_card_id'] !== (int) $r['rate_card_id'])) {
            $minNote = ' · ' . (int) $minimum . '-day minimum from '
                . ($minLine['customer_id'] !== null ? 'custom card' : 'card') . ' "' . $minLine['card_name'] . '"';
        }

        if ($r !== null) {
            $isCustomerCard = $r['customer_id'] !== null;
            return [
                'source'       => $isCustomerCard ? 'customer' : 'rate_card',
                'source_label' => ($isCustomerCard
                    ? $typeLabel . ' rate · custom card "' . $r['card_name'] . '"'
                    : $typeLabel . ' rate · card "' . $r['card_name'] . '"') . $minNote,
                'daily_rate'   => $r['daily_rate'],
                'weekly_rate'  => $r['weekly_rate'],
                'monthly_rate' => $r['monthly_rate'],
                'mileage_rate' => $r['mileage_rate'],
                'mileage_unit' => $r['mileage_unit'],
                'hourly_rate'  => $r['hourly_rate'],
                'currency'     => $r['currency'],
                'gps_price'    => $r['gps_price'],
                'rate_card_id' => (int) $r['rate_card_id'],
                'minimum_days' => $minimum,
            ];
        }

        // Tier 2 — the equipment type's defaults.
        // NB: default_mileage_rate is NOT NULL DEFAULT 0.0000 (0 = mileage
        // disabled, D135), so it counts only when > 0; hourly stays nullable
        // (S-AUDIT-BILLING-ENGINE-1 #24).
        if (self::templateHasPrices($template)) {
            return [
                'source'       => 'template',
                'source_label' => $typeLabel . ' rate · template default' . $minNote,
                'daily_rate'   => $template['default_daily_rate'],
                'weekly_rate'  => $template['default_weekly_rate'],
                'monthly_rate' => $template['default_monthly_rate'],
                'mileage_rate' => $template['default_mileage_rate'],
                'mileage_unit' => $template['default_mileage_unit'] ?? 'km',
                'hourly_rate'  => $template['default_hourly_rate'],
                'currency'     => $template['default_currency'] ?? 'CAD',
                'gps_price'    => null,
                'rate_card_id' => null,
                'minimum_days' => $minimum,
            ];
        }

        return [
            'source'       => 'none',
            'source_label' => 'No rates configured for ' . $typeLabel . $minNote,
            'daily_rate'   => null,
            'weekly_rate'  => null,
            'monthly_rate' => null,
            'mileage_rate' => null,
            'mileage_unit' => 'km',
            'hourly_rate'  => null,
            'currency'     => 'CAD',
            'gps_price'    => null,
            'rate_card_id' => null,
            'minimum_days' => $minimum,
        ];
    }

    /**
     * The standard price — what a customer WITHOUT a card of their own pays
     * (general cards, then the equipment type's defaults).
     *
     * @param  array<string,mixed> $template
     * @return array<string,mixed> resolve() shape
     */
    public static function standard(array $template, string $date): array
    {
        return self::resolve(null, $template, $date);
    }

    /**
     * Everything the Price check shows: the winning price, every candidate
     * line with why it lost, the three tiers, and the standard price.
     *
     * @param  array<string,mixed> $template
     * @return array<string,mixed>
     */
    public static function explain(?int $customerId, array $template, string $date): array
    {
        $candidates = self::candidates($customerId, $template, $date);
        $price      = self::resolve($customerId, $template, $date, $candidates);
        $standard   = self::standard($template, $date);
        // S-RATES-MINIMUM-OVERLAY: the winner is the line that sets the PRICE;
        // a minimum-only line ranked above it only supplies minimum_days.
        $winIdx     = self::priceWinnerIndex($candidates);
        $winner     = $winIdx === null ? null : $candidates[$winIdx];
        $minIdx     = self::minimumIndex($candidates);
        $minLine    = $minIdx === null ? null : $candidates[$minIdx];

        $lines = [];
        foreach ($candidates as $i => $c) {
            $priced = self::hasPrices($c);
            if ($i === $winIdx) {
                $why = 'Used — the best match.' . ($minIdx !== null && $minIdx !== $i ? ' The minimum comes from the line above.' : '');
            } elseif (!$priced && $i === $minIdx) {
                $why = 'Sets only a minimum — its ' . (int) $c['minimum_days'] . '-day minimum is used; the prices come from '
                    . ($winner !== null ? 'the next line with prices.' : ($price['source'] === 'template' ? "the equipment type's defaults." : 'nowhere — no price is set.'));
            } elseif (!$priced && ($winIdx === null || $i < $winIdx)) {
                $why = $c['minimum_days'] !== null && $c['minimum_days'] !== ''
                    ? 'Sets only a minimum — a higher line already set the minimum.'
                    : 'Sets no prices and no minimum — skipped.';
            } else {
                $why = self::whyLost($c, $winner);
            }
            $lines[] = [
                'rank'           => $i + 1,
                'item_id'        => (int) $c['item_id'],
                'rate_card_id'   => (int) $c['rate_card_id'],
                'card_name'      => (string) $c['card_name'],
                'is_customer'    => $c['customer_id'] !== null,
                'is_default'     => (bool) $c['is_default'],
                'line_scope'     => $c['equipment_template_id'] !== null ? 'type' : 'category',
                'effective_from' => $c['effective_from'],
                'effective_to'   => $c['effective_to'],
                'prices'         => self::pickPrices($c),
                'priceless'      => !$priced,
                'used_for'       => array_values(array_filter([$i === $winIdx ? 'price' : null, $i === $minIdx ? 'minimum' : null])),
                'why'            => $why,
            ];
        }

        $hasCustomer = $customerId !== null && array_filter($candidates, static fn ($c) => $c['customer_id'] !== null) !== [];
        $hasGeneral  = array_filter($candidates, static fn ($c) => $c['customer_id'] === null) !== [];
        $source      = (string) $price['source'];
        // Which tier supplied only the minimum (never the price).
        $minTier = null;
        if ($minLine !== null && ($winner === null || $minIdx !== $winIdx)) {
            $minTier = $minLine['customer_id'] !== null ? 'customer' : 'general';
        }
        $minDetail = $minLine !== null
            ? ' Sets only a minimum (' . (int) $minLine['minimum_days'] . ' days) — used for the minimum; the price comes from below.'
            : '';

        $tiers = [
            [
                'key'    => 'customer',
                'label'  => "This customer's own cards",
                'state'  => $customerId === null ? 'skipped' : ($source === 'customer' || $minTier === 'customer' ? 'used' : 'none'),
                'detail' => $customerId === null ? 'No customer chosen.'
                    : ($minTier === 'customer' ? ltrim($minDetail)
                        : ($hasCustomer ? 'Has a line for this equipment.' : 'No in-force line for this equipment.')),
            ],
            [
                'key'    => 'general',
                'label'  => 'General cards (everyone)',
                'state'  => $source === 'rate_card' || $minTier === 'general' ? 'used' : ($hasGeneral ? 'available' : 'none'),
                'detail' => $minTier === 'general' ? ltrim($minDetail)
                    : ($hasGeneral ? 'Has a line for this equipment.' : 'No in-force line for this equipment.'),
            ],
            [
                'key'    => 'type_default',
                'label'  => "The equipment type's default prices",
                'state'  => $source === 'template' ? 'used' : (self::templateHasPrices($template) ? 'available' : 'none'),
                'detail' => self::templateHasPrices($template) ? 'Set on the equipment type.' : 'Not set.',
            ],
        ];

        return [
            'date'       => $date,
            'price'      => $price + [
                'card_name'         => $winner['card_name'] ?? null,
                'line_scope'        => $winner ? ($winner['equipment_template_id'] !== null ? 'type' : 'category') : null,
                'item_id'           => $winner ? (int) $winner['item_id'] : null,
                // S-RATES-MINIMUM-OVERLAY: set only when a minimum-only line on
                // another card supplied minimum_days.
                'minimum_card_id'   => $minTier !== null ? (int) $minLine['rate_card_id'] : null,
                'minimum_card_name' => $minTier !== null ? (string) $minLine['card_name'] : null,
            ],
            'standard'   => $standard,
            'candidates' => $lines,
            'tiers'      => $tiers,
        ];
    }

    /**
     * Estimate a rental with the real billing law.
     *
     * Rent = HolisticLeaseEngine::cumulativeCorrect over [start, end] (start =
     * through = extent, i.e. a rental billed once at its known end), with the
     * short-lease minimum resolved the way lease creation + billing do: the
     * line's minimum_days, else the 'lease.minimum_billing_days' setting —
     * and only when the equipment's category enforces minimums
     * (equipment_categories.enforce_minimum_billing_days, S-EQTAX).
     *
     * Extras (each optional): GPS per day, estimated distance per day ×
     * mileage rate, estimated engine hours per day × hourly rate. All before
     * tax.
     *
     * @param array<string,mixed> $price    resolve() shape
     * @param array<string,mixed> $template template() row
     * @param array{gps?:bool, distance_per_day?:?string, hours_per_day?:?string} $opts
     * @return array<string,mixed>
     */
    public static function quote(array $price, array $template, string $start, string $end, array $opts = []): array
    {
        $days = HolisticLeaseEngine::inclusiveDays($start, $end);

        // Short-lease minimum: line → setting; gated by the category flag.
        $minSource = null;
        $minDays   = 0;
        $lineMin   = $price['minimum_days'] ?? null;
        $enforce   = (int) (\db_row(
            "SELECT ec.enforce_minimum_billing_days AS enforce
               FROM equipment_templates et
               LEFT JOIN equipment_categories ec
                      ON ec.deleted_at IS NULL
                     AND (ec.id = et.category_id OR (et.category_id IS NULL AND ec.slug = et.category))
              WHERE et.id = ?",
            [(int) $template['id']]
        )['enforce'] ?? 0);
        if ($lineMin !== null && $lineMin !== '') {
            $minDays   = (int) $lineMin;
            $minSource = 'line';
        } else {
            $minDays   = (int) \settings_get('lease.minimum_billing_days', '3');
            $minSource = 'setting';
        }
        if ($enforce !== 1) {
            $minDays   = 0;
            $minSource = 'not_enforced';
        }

        $d = (string) ($price['daily_rate'] ?? '0') ?: '0';
        $w = (string) ($price['weekly_rate'] ?? '0') ?: '0';
        $m = (string) ($price['monthly_rate'] ?? '0') ?: '0';

        $engine = new HolisticLeaseEngine();
        $rent   = $days > 0
            ? $engine->cumulativeCorrect($start, $end, $end, $d, $w, $m, $minDays)
            : ['amount' => '0.00', 'tier' => 'none', 'basis' => 'none', 'explanation' => [], 'segments' => []];

        $lines = [[
            'key'     => 'rent',
            'label'   => 'Rent',
            'amount'  => $rent['amount'],
            'detail'  => $rent['explanation'],
            'basis'   => $rent['basis'],
        ]];
        $total = $rent['amount'];

        if (!empty($opts['gps']) && bccomp((string) ($price['gps_price'] ?? '0'), '0', 2) > 0) {
            $amt = bcround(bcmul((string) $price['gps_price'], (string) $days, 6), 2);
            $lines[] = ['key' => 'gps', 'label' => 'GPS tracking', 'amount' => $amt,
                        'detail' => ["{$days} day(s) × \${$price['gps_price']}/day"], 'basis' => 'gps'];
            $total = bcadd($total, $amt, 2);
        }
        $dist = \clean_decimal($opts['distance_per_day'] ?? null);
        if ($dist !== null && bccomp($dist, '0', 4) > 0 && bccomp((string) ($price['mileage_rate'] ?? '0'), '0', 4) > 0) {
            $unit  = ($price['mileage_unit'] ?? 'km') === 'miles' ? 'mi' : 'km';
            $total_dist = bcmul($dist, (string) $days, 4);
            $amt   = bcround(bcmul($total_dist, (string) $price['mileage_rate'], 6), 2);
            $lines[] = ['key' => 'mileage', 'label' => 'Estimated distance', 'amount' => $amt,
                        'detail' => [rtrim(rtrim($total_dist, '0'), '.') . " {$unit} ({$days} × {$dist}) × \$" . $price['mileage_rate'] . "/{$unit}"], 'basis' => 'mileage'];
            $total = bcadd($total, $amt, 2);
        }
        $hrs = \clean_decimal($opts['hours_per_day'] ?? null);
        if ($hrs !== null && bccomp($hrs, '0', 4) > 0 && bccomp((string) ($price['hourly_rate'] ?? '0'), '0', 4) > 0) {
            $total_hrs = bcmul($hrs, (string) $days, 4);
            $amt   = bcround(bcmul($total_hrs, (string) $price['hourly_rate'], 6), 2);
            $lines[] = ['key' => 'hours', 'label' => 'Estimated engine hours', 'amount' => $amt,
                        'detail' => [rtrim(rtrim($total_hrs, '0'), '.') . " h × \$" . $price['hourly_rate'] . '/h'], 'basis' => 'hours'];
            $total = bcadd($total, $amt, 2);
        }

        $warnings = [];
        $hasRentPrice = bccomp($d, '0', 2) > 0 || bccomp($w, '0', 2) > 0 || bccomp($m, '0', 2) > 0;
        if ($days > 0 && $hasRentPrice && bccomp($rent['amount'], '0', 2) === 0) {
            $warnings[] = 'These prices bill $0 rent for a rental this long — the weekly or monthly price is missing.';
        }

        return [
            'start'        => $start,
            'end'          => $end,
            'days'         => $days,
            'currency'     => $price['currency'] ?? 'CAD',
            'minimum_days' => $minDays,
            'minimum_from' => $minSource,
            'lines'        => $lines,
            'total'        => $total,
            'per_day'      => $days > 0 ? bcround(bcdiv($total, (string) $days, 6), 2) : '0.00',
            'warnings'     => $warnings,
        ];
    }

    /**
     * Does the equipment type carry any default price of its own?
     *
     * @param array<string,mixed> $template
     */
    public static function templateHasPrices(array $template): bool
    {
        return $template['default_daily_rate'] !== null
            || $template['default_weekly_rate'] !== null
            || $template['default_monthly_rate'] !== null
            || $template['default_hourly_rate'] !== null
            || bccomp((string) ($template['default_mileage_rate'] ?? '0'), '0', 4) > 0;
    }

    /**
     * The price columns of a row, keyed by field (plus unit / currency / min days).
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function pickPrices(array $row): array
    {
        $out = [];
        foreach (self::PRICE_FIELDS as $f) {
            $out[$f] = $row[$f] ?? null;
        }
        $out['mileage_unit'] = $row['mileage_unit'] ?? 'km';
        $out['currency']     = $row['currency'] ?? 'CAD';
        $out['minimum_days'] = $row['minimum_days'] ?? null;
        return $out;
    }

    /**
     * Percent difference of $value against $base (e.g. -12.5 = 12.5 % below).
     * Null when either side is missing or the base is zero.
     */
    public static function pctDiff(mixed $value, mixed $base): ?string
    {
        if ($value === null || $value === '' || $base === null || $base === '') {
            return null;
        }
        $v = (string) $value;
        $b = (string) $base;
        if (bccomp($b, '0', 6) <= 0) {
            return null;
        }
        return bcround(bcmul(bcdiv(bcsub($v, $b, 6), $b, 8), '100', 6), 1);
    }

    /**
     * Plain-English reason a candidate line lost to the winner — the first
     * ORDER BY key where they differ.
     *
     * @param array<string,mixed>      $c
     * @param array<string,mixed>|null $winner
     */
    private static function whyLost(array $c, ?array $winner): string
    {
        if ($winner === null) {
            return '';
        }
        if (($winner['customer_id'] !== null) !== ($c['customer_id'] !== null)) {
            return "The customer's own card comes before general cards.";
        }
        if (($winner['equipment_template_id'] !== null) !== ($c['equipment_template_id'] !== null)) {
            return 'A line for this exact equipment type comes before a whole-category line.';
        }
        if ((int) $winner['is_default'] !== (int) $c['is_default']) {
            return 'The main price list comes before other general cards.';
        }
        if ($winner['effective_from'] !== $c['effective_from']) {
            return 'A card that started later (' . \format_date($winner['effective_from']) . ') comes first.';
        }
        return 'Same priority — the newest card comes first.';
    }
}
