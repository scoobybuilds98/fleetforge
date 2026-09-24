<?php
declare(strict_types=1);

/**
 * lib/RateCards/RateCardItems.php
 *
 * Shared rules for the price lines on a rate card (S-RATES-MODULE).
 *
 * WHY: create.php and update.php each carried an identical 90-line copy of
 * the line validation; the new "change prices" path (RateCardRevision) needs
 * it a third time. One copy now, with the same messages the endpoints always
 * returned.
 *
 *   normalize()   validate + clean a raw items[] array → [items, errors]
 *   snapshot()    compact, comparable form of a card's lines (for the audit
 *                 log, so price history can show what changed per line)
 *   diff()        per-line, per-field changes between two snapshots
 *   adjust()      raise / lower prices by a percentage with rounding
 *   status()      active | ending | upcoming | expired for a card's window
 *   validDate()   a Y-m-d inside a sane range (clean_date accepts year 0001)
 *
 * A line is keyed "t:{template_id}" (one equipment type) or "c:{slug}" (a
 * whole category) — S-RATE-CARD-TEMPLATE-ITEM; no key may repeat on a card.
 *
 * @depends  includes/functions.php (clean_*, bcround), includes/db.php
 * @session  S-RATES-MODULE (extracted from api/v1/rate_cards/{create,update}.php)
 */

namespace FleetForge\RateCards;

final class RateCardItems
{
    /** Friendly names for the price fields (error messages + history). */
    public const RATE_LABELS = [
        'daily_rate'   => 'Daily rate',
        'weekly_rate'  => 'Weekly rate',
        'monthly_rate' => 'Monthly rate',
        'mileage_rate' => 'Mileage rate',
        'hourly_rate'  => 'Hourly rate',
        'gps_price'    => 'GPS rate',
    ];

    /** Decimal places each field is stored with (DB column scale). */
    public const SCALE = [
        'daily_rate'   => 2,
        'weekly_rate'  => 2,
        'monthly_rate' => 2,
        'mileage_rate' => 4,
        'hourly_rate'  => 4,
        'gps_price'    => 2,
    ];

    /** Largest value each column can hold (DECIMAL precision). */
    public const MAX = [
        'daily_rate'   => '99999999.99',
        'weekly_rate'  => '99999999.99',
        'monthly_rate' => '99999999.99',
        'mileage_rate' => '9999.9999',
        'hourly_rate'  => '999999.9999',
        'gps_price'    => '99999999.99',
    ];

    /** A card whose end date is this close counts as "ending soon". */
    public const ENDING_SOON_DAYS = 30;

    /**
     * Validate + clean a raw items[] payload.
     *
     * @param  mixed $raw
     * @return array{0: list<array<string,mixed>>, 1: list<string>} [items ready for db_insert, error sentences]
     */
    public static function normalize(mixed $raw): array
    {
        $items  = [];
        $errors = [];
        if (!is_array($raw)) {
            return [$items, $errors];
        }

        $seenKeys = [];
        foreach (array_values($raw) as $idx => $item) {
            $lineNum = $idx + 1;
            if (!is_array($item)) {
                $errors[] = "Item {$lineNum}: equipment type is required.";
                continue;
            }
            $equipType = \clean_string($item['equipment_type'] ?? null, 255);
            if (!$equipType) {
                $errors[] = "Item {$lineNum}: equipment type is required.";
                continue;
            }

            // S-RATE-CARD-TEMPLATE-ITEM: optional equipment_template_id. The
            // category is derived from the template, so a mismatched slug is
            // corrected silently.
            $templateId = null;
            if (!empty($item['equipment_template_id'])) {
                $templateId = \clean_int($item['equipment_template_id']);
                if (!$templateId || !\db_exists('equipment_templates', 'id = ? AND deleted_at IS NULL', [$templateId])) {
                    $errors[] = "Item {$lineNum}: equipment template not found.";
                    continue;
                }
                $tmpl = \db_row("SELECT category FROM equipment_templates WHERE id = ?", [$templateId]);
                if ($tmpl && $tmpl['category'] !== $equipType) {
                    $equipType = $tmpl['category'];
                }
            }

            $key = $templateId !== null ? "t:{$templateId}" : "c:{$equipType}";
            if (in_array($key, $seenKeys, true)) {
                $errors[] = "Item {$lineNum}: this equipment type / template combination is listed more than once.";
                continue;
            }
            $seenKeys[] = $key;

            // Rates — D16 bcmath strings; each must parse and be ≥ 0 when given.
            $rates   = array_fill_keys(array_keys(self::RATE_LABELS), null);
            $hadError = false;
            foreach (self::RATE_LABELS as $field => $label) {
                if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) {
                    continue;
                }
                $val = \clean_decimal(is_scalar($item[$field]) ? (string) $item[$field] : '');
                if ($val === null) {
                    $errors[] = "Item {$lineNum}: {$label} must be a valid number.";
                    $hadError = true;
                    continue;
                }
                if (bccomp($val, '0', 6) < 0) {
                    $errors[] = "Item {$lineNum}: {$label} cannot be negative.";
                    $hadError = true;
                    continue;
                }
                // The columns are DECIMAL(10,2)/(8,4)/(10,4): refuse what they
                // cannot hold instead of letting STRICT mode abort the insert.
                $max = self::MAX[$field];
                if (bccomp($val, $max, 6) > 0) {
                    $errors[] = "Item {$lineNum}: {$label} is too large.";
                    $hadError = true;
                    continue;
                }
                $rates[$field] = $val;
            }

            $currency    = \clean_string($item['currency'] ?? null, 10) ?? 'CAD';
            $mileageUnit = \clean_string($item['mileage_unit'] ?? null, 10) ?? 'km';
            if (!in_array($currency, ['CAD', 'USD'], true)) {
                $errors[] = "Item {$lineNum}: currency must be CAD or USD.";
                $hadError = true;
            }
            if (!in_array($mileageUnit, ['km', 'miles'], true)) {
                $errors[] = "Item {$lineNum}: mileage unit must be km or miles.";
                $hadError = true;
            }

            // S-LEASE-MIN-DAYS: nullable unsigned 0..90; empty/absent → NULL (no
            // floor). 0/1 persist but are a no-op in the billing engines.
            $minimumDays = null;
            if (isset($item['minimum_days']) && $item['minimum_days'] !== '' && $item['minimum_days'] !== null) {
                $minimumDays = \clean_int($item['minimum_days']);
                if ($minimumDays === null || $minimumDays < 0 || $minimumDays > 90) {
                    $errors[] = "Item {$lineNum}: minimum days must be a whole number between 0 and 90.";
                    $hadError = true;
                }
            }

            // D132 rate-tier completeness (S-RATES-MODULE): lease create, the
            // equipment-type defaults and the billing engine all require the
            // rent trio to be complete — any of daily / weekly / monthly above
            // $0 means all three are. A card line with a hole would pre-fill a
            // lease the lease form then refuses (or, before the guard, billed
            // $0 rent past 7 days). All three blank stays allowed: hourly-only
            // and distance-only lines (S-HOURLY-ONLY).
            if (!$hadError) {
                $trio = [$rates['daily_rate'], $rates['weekly_rate'], $rates['monthly_rate']];
                $pos  = array_filter($trio, static fn ($v) => $v !== null && bccomp($v, '0', 4) > 0);
                if ($pos !== [] && count($pos) < 3) {
                    $errors[] = "Item {$lineNum}: daily, weekly and monthly prices go together — set all three, or leave all three blank.";
                    $hadError = true;
                }
            }

            if ($hadError) {
                continue;
            }

            $items[] = [
                'equipment_type'        => $equipType,
                'equipment_template_id' => $templateId,
                'daily_rate'            => $rates['daily_rate'],
                'weekly_rate'           => $rates['weekly_rate'],
                'monthly_rate'          => $rates['monthly_rate'],
                'mileage_rate'          => $rates['mileage_rate'],
                'mileage_unit'          => $mileageUnit,
                'hourly_rate'           => $rates['hourly_rate'],
                'gps_price'             => $rates['gps_price'],
                'minimum_days'          => $minimumDays,
                'currency'              => $currency,
                'notes'                 => \clean_string($item['notes'] ?? null, 1000),
            ];
        }

        return [$items, $errors];
    }

    /** "t:{template_id}" or "c:{category slug}" — a line's identity on a card. */
    public static function lineKey(array $item): string
    {
        return !empty($item['equipment_template_id'])
            ? 't:' . (int) $item['equipment_template_id']
            : 'c:' . (string) $item['equipment_type'];
    }

    /**
     * The lines of a card in a compact, comparable form for audit_log
     * old_values / new_values. Decimals are normalised to their column scale
     * so "50" and "50.00" never show as a change.
     *
     * @param  list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function snapshot(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $row = [
                'key'          => self::lineKey($it),
                'type'         => (string) $it['equipment_type'],
                'template_id'  => !empty($it['equipment_template_id']) ? (int) $it['equipment_template_id'] : null,
            ];
            foreach (self::SCALE as $f => $scale) {
                $v = $it[$f] ?? null;
                $row[$f] = ($v === null || $v === '') ? null : bcadd((string) $v, '0', $scale);
            }
            $row['mileage_unit'] = $it['mileage_unit'] ?? 'km';
            $row['minimum_days'] = ($it['minimum_days'] ?? null) === null || $it['minimum_days'] === '' ? null : (int) $it['minimum_days'];
            $row['currency']     = $it['currency'] ?? 'CAD';
            $out[] = $row;
        }
        usort($out, static fn ($a, $b) => strcmp($a['key'], $b['key']));
        return $out;
    }

    /**
     * Line-level changes between two snapshots.
     *
     * @param  list<array<string,mixed>> $before
     * @param  list<array<string,mixed>> $after
     * @return list<array{key:string, change:string, fields:list<array{field:string, from:mixed, to:mixed}>}>
     *         change = added | removed | changed
     */
    public static function diff(array $before, array $after): array
    {
        $b = [];
        foreach ($before as $r) { $b[$r['key']] = $r; }
        $a = [];
        foreach ($after as $r) { $a[$r['key']] = $r; }

        $fields = array_merge(array_keys(self::SCALE), ['mileage_unit', 'minimum_days', 'currency']);
        $out = [];
        foreach ($a as $key => $row) {
            if (!isset($b[$key])) {
                $out[] = ['key' => $key, 'change' => 'added', 'fields' => []];
                continue;
            }
            $changed = [];
            foreach ($fields as $f) {
                $from = $b[$key][$f] ?? null;
                $to   = $row[$f] ?? null;
                if ((string) $from !== (string) $to) {
                    $changed[] = ['field' => $f, 'from' => $from, 'to' => $to];
                }
            }
            if ($changed) {
                $out[] = ['key' => $key, 'change' => 'changed', 'fields' => $changed];
            }
        }
        foreach ($b as $key => $row) {
            if (!isset($a[$key])) {
                $out[] = ['key' => $key, 'change' => 'removed', 'fields' => []];
            }
        }
        return $out;
    }

    /**
     * Raise / lower the given price fields by $percent, rounding each result.
     *
     * $round applies to the whole-currency fields (daily / weekly / monthly):
     * '0.01' = to the cent, '1' = whole dollars, '5' = nearest $5. Mileage and
     * hourly always keep 4 decimals, GPS 2 — a $0.06/km rate rounded to the
     * dollar would become $0.
     *
     * @param  list<array<string,mixed>> $items
     * @param  list<string>              $fields subset of RATE_LABELS keys
     * @return list<array<string,mixed>>
     */
    public static function adjust(array $items, string $percent, string $round, array $fields): array
    {
        $factor = bcadd('1', bcdiv($percent, '100', 8), 8);
        foreach ($items as &$it) {
            foreach ($fields as $f) {
                $v = $it[$f] ?? null;
                if ($v === null || $v === '' || !isset(self::SCALE[$f])) {
                    continue;
                }
                $raw = bcmul((string) $v, $factor, 8);
                if (bccomp($raw, '0', 8) < 0) {
                    $raw = '0';
                }
                $it[$f] = in_array($f, ['daily_rate', 'weekly_rate', 'monthly_rate'], true)
                    ? self::roundTo($raw, $round)
                    : bcround($raw, self::SCALE[$f]);
            }
        }
        unset($it);
        return $items;
    }

    /** Round a non-negative decimal to the nearest $step ('0.01', '1', '5'). */
    public static function roundTo(string $value, string $step): string
    {
        if (!in_array($step, ['0.01', '1', '5'], true)) {
            $step = '0.01';
        }
        if ($step === '0.01') {
            return bcround($value, 2);
        }
        $units = bcdiv($value, $step, 8);
        $whole = bcadd($units, '0.5', 0); // half-up for a non-negative value
        return bcmul($whole, $step, 2);
    }

    /**
     * Where a card's window stands on $today.
     *
     * @param array{effective_from:string, effective_to:?string} $card
     * @return string active | ending | upcoming | expired
     */
    public static function status(array $card, string $today): string
    {
        if ($card['effective_from'] > $today) {
            return 'upcoming';
        }
        $to = $card['effective_to'] ?? null;
        if ($to !== null && $to < $today) {
            return 'expired';
        }
        if ($to !== null) {
            $soon = (new \DateTimeImmutable($today))->modify('+' . self::ENDING_SOON_DAYS . ' days')->format('Y-m-d');
            if ($to <= $soon) {
                return 'ending';
            }
        }
        return 'active';
    }

    /** A real Y-m-d between 2000-01-01 and 2100-12-31, else null. */
    public static function validDate(mixed $val): ?string
    {
        $d = \clean_date($val);
        if ($d === null || $d < '2000-01-01' || $d > '2100-12-31') {
            return null;
        }
        return $d;
    }

    /**
     * slug → label for every equipment-type slug a line can carry (the
     * operator-managed taxonomy; category labels win over a same-slug
     * sub-category — S-EQTAX). Unknown slugs are humanised by label().
     *
     * @return array<string,string>
     */
    public static function categoryLabels(): array
    {
        static $labels = null;
        if ($labels !== null) {
            return $labels;
        }
        $labels = [];
        foreach (\db_select("SELECT slug, label FROM equipment_subcategories WHERE deleted_at IS NULL") as $r) {
            $labels[$r['slug']] = $r['label'];
        }
        foreach (\db_select("SELECT slug, label FROM equipment_categories WHERE deleted_at IS NULL") as $r) {
            $labels[$r['slug']] = $r['label'];
        }
        return $labels;
    }

    /** Display label for a category slug. */
    public static function label(string $slug): string
    {
        $labels = self::categoryLabels();
        return $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug));
    }
}
