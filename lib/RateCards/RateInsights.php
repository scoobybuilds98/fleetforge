<?php
declare(strict_types=1);

/**
 * lib/RateCards/RateInsights.php
 *
 * The read side of the Rates module (S-RATES-MODULE): everything the Rates
 * home, the rate-card page and the customer's Rates tab show that is more
 * than one table lookup.
 *
 *   customerPricing()  one row per customer with their own prices — every
 *                      line with its card, status and "vs standard"
 *   priceBook()        the standard price of every equipment type (what a
 *                      customer without their own card pays) + how many
 *                      customer deals cover it and how many units are out
 *   customerPrices()   what ONE customer pays for every equipment type, where
 *                      each price comes from, and the prices on their leases
 *   leasesOnCard()     active leases a card prices today, and which of them
 *                      carry different prices than the card
 *   attention()        the "needs a look" list for the Rates home
 *   history()          a card's change log with per-line price diffs, and the
 *                      price timeline of its lines across earlier cards
 *
 * All pricing decisions go through RateResolver, so these views always agree
 * with what the lease form pre-fills. Money stays in bcmath strings (D16).
 * Leases copy their prices at creation and keep no rate_card_id, so "leases
 * on a card" is RESOLVED: the lease's customer + equipment type are run
 * through the resolver today, and the lease counts when the card wins.
 *
 * @depends  lib/RateCards/{RateResolver,RateCardItems}.php, includes/db.php
 * @session  S-RATES-MODULE
 */

namespace FleetForge\RateCards;

final class RateInsights
{
    /** @var array<int,array<string,mixed>> template id → template row */
    private static array $templates = [];

    /** @var array<int,array<string,mixed>> template id → standard price (per date key) */
    private static array $standards = [];

    // ─────────────────────────────────────────────────────────────────
    // Equipment types
    // ─────────────────────────────────────────────────────────────────

    /**
     * Every live equipment type, keyed by id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function templates(): array
    {
        if (self::$templates === []) {
            foreach (\db_select(
                "SELECT id, name, category, category_id, is_active,
                        default_daily_rate, default_weekly_rate, default_monthly_rate,
                        default_mileage_rate, default_mileage_unit, default_currency,
                        default_hourly_rate, updated_at
                   FROM equipment_templates
                  WHERE deleted_at IS NULL
                  ORDER BY name ASC"
            ) as $t) {
                self::$templates[(int) $t['id']] = $t;
            }
        }
        return self::$templates;
    }

    /** Forget cached lookups (tests change data between calls). */
    public static function reset(): void
    {
        self::$templates = [];
        self::$standards = [];
    }

    /**
     * Standard price of an equipment type on $date (cached per request).
     *
     * @return array<string,mixed>
     */
    public static function standardFor(int $templateId, string $date): array
    {
        $key = $templateId . '@' . $date;
        if (!isset(self::$standards[$key])) {
            $t = self::templates()[$templateId] ?? null;
            self::$standards[$key] = $t ? RateResolver::standard($t, $date) : [];
        }
        return self::$standards[$key];
    }

    /**
     * The standard a LINE compares against. A line for one equipment type
     * compares to that type's standard. A whole-category line compares to the
     * standard every type in the category shares — or, when the types differ,
     * carries the range instead of a single figure.
     *
     * @param  array<string,mixed> $line needs equipment_type, equipment_template_id
     * @return array{daily:?string, weekly:?string, monthly:?string, range:?array, source:?string}
     */
    public static function lineStandard(array $line, string $date): array
    {
        if (!empty($line['equipment_template_id'])) {
            $s = self::standardFor((int) $line['equipment_template_id'], $date);
            return ['daily' => $s['daily_rate'] ?? null, 'weekly' => $s['weekly_rate'] ?? null,
                    'monthly' => $s['monthly_rate'] ?? null, 'range' => null, 'source' => $s['source'] ?? null];
        }
        $ids = array_keys(array_filter(self::templates(), static fn ($t) => $t['category'] === $line['equipment_type']));
        if ($ids === []) {
            return ['daily' => null, 'weekly' => null, 'monthly' => null, 'range' => null, 'source' => null];
        }
        $vals = ['daily' => [], 'weekly' => [], 'monthly' => []];
        $sources = [];
        foreach ($ids as $id) {
            $s = self::standardFor($id, $date);
            $sources[] = $s['source'] ?? 'none';
            foreach (['daily', 'weekly', 'monthly'] as $k) {
                $v = $s[$k . '_rate'] ?? null;
                if ($v !== null && $v !== '') {
                    $vals[$k][] = (string) $v;
                }
            }
        }
        $single = true;
        $range  = [];
        foreach ($vals as $k => $list) {
            if ($list === []) {
                continue;
            }
            usort($list, static fn ($a, $b) => bccomp($a, $b, 4));
            $range[$k] = [$list[0], $list[count($list) - 1]];
            if (bccomp($list[0], $list[count($list) - 1], 4) !== 0 || count($list) !== count($ids)) {
                $single = false;
            }
        }
        if ($single) {
            return ['daily' => $range['daily'][0] ?? null, 'weekly' => $range['weekly'][0] ?? null,
                    'monthly' => $range['monthly'][0] ?? null, 'range' => null, 'source' => $sources[0] ?? null];
        }
        return ['daily' => null, 'weekly' => null, 'monthly' => null, 'range' => $range, 'source' => 'mixed'];
    }

    /**
     * Display labels for a line: [label, scope] — "Dry Van" + "every Dry Van
     * type", or "53' T/A Heater" + "Dry Van · this type only".
     *
     * @param  array<string,mixed> $line
     * @return array{0:string,1:string}
     */
    public static function lineLabels(array $line): array
    {
        $cat = RateCardItems::label((string) $line['equipment_type']);
        if (!empty($line['equipment_template_id'])) {
            $t = self::templates()[(int) $line['equipment_template_id']] ?? null;
            return [(string) ($t['name'] ?? $line['equipment_template_name'] ?? 'Equipment type #' . $line['equipment_template_id']), $cat . ' · this type only'];
        }
        return [$cat, 'every ' . $cat . ' type'];
    }

    /**
     * A card's lines, decorated for display (labels, standard, vs %).
     *
     * @param  list<array<string,mixed>> $items rate_card_items rows
     * @return list<array<string,mixed>>
     */
    public static function decorateLines(array $items, string $date): array
    {
        $out = [];
        foreach ($items as $it) {
            [$label, $scope] = self::lineLabels($it);
            $std = self::lineStandard($it, $date);
            $row = [
                'id'                    => isset($it['id']) ? (int) $it['id'] : null,
                'key'                   => RateCardItems::lineKey($it),
                'label'                 => $label,
                'scope'                 => $scope,
                'line_scope'            => !empty($it['equipment_template_id']) ? 'type' : 'category',
                'equipment_type'        => (string) $it['equipment_type'],
                'equipment_template_id' => !empty($it['equipment_template_id']) ? (int) $it['equipment_template_id'] : null,
                'mileage_unit'          => $it['mileage_unit'] ?? 'km',
                'currency'              => $it['currency'] ?? 'CAD',
                'minimum_days'          => $it['minimum_days'] ?? null,
                'notes'                 => $it['notes'] ?? null,
                'standard'              => $std,
                'vs'                    => [
                    'daily'   => RateResolver::pctDiff($it['daily_rate'] ?? null, $std['daily']),
                    'weekly'  => RateResolver::pctDiff($it['weekly_rate'] ?? null, $std['weekly']),
                    'monthly' => RateResolver::pctDiff($it['monthly_rate'] ?? null, $std['monthly']),
                ],
                'issues'                => self::lineIssues($it),
            ];
            foreach (RateResolver::PRICE_FIELDS as $f) {
                $row[$f] = $it[$f] ?? null;
            }
            $out[] = $row;
        }
        usort($out, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Problems with a single line that make it bill wrong or not at all.
     *
     * @param  array<string,mixed> $it
     * @return list<string>
     */
    public static function lineIssues(array $it): array
    {
        $pos = static fn ($v): bool => $v !== null && $v !== '' && bccomp((string) $v, '0', 4) > 0;
        $issues = [];
        $trio = array_filter(['daily_rate', 'weekly_rate', 'monthly_rate'], static fn ($f) => $pos($it[$f] ?? null));
        if (!RateResolver::hasPrices($it)) {
            // S-RATES-MINIMUM-OVERLAY: a line with only a minimum is a valid
            // "minimum-only" line — the resolver takes its minimum and prices
            // from the next line / the equipment type. Only a line that sets
            // NOTHING is a problem (it is skipped entirely). A 0 / 1-day
            // "minimum" with no prices only switches the minimum OFF for that
            // equipment — legitimate, but on prod it came from a line typed
            // as all-$0 (card #64), so surface it for a second look.
            $min = $it['minimum_days'] ?? null;
            if ($min === null || $min === '') {
                $issues[] = 'No prices and no minimum — this line does nothing.';
            } elseif ((int) $min < 2) {
                $issues[] = 'No prices — this line only switches the short-lease minimum off; prices come from the next line or the equipment type.';
            }
        } elseif ($trio !== [] && count($trio) < 3) {
            // D132: the lease form refuses an incomplete rent trio, and the
            // engine bills $0 rent in whichever tier is missing (past 7 days
            // with no weekly price, for instance).
            $issues[] = 'Daily, weekly and monthly must all be set — a lease from this line cannot be saved as-is.';
        }
        return $issues;
    }

    // ─────────────────────────────────────────────────────────────────
    // Customer pricing (Rates home → Customer prices)
    // ─────────────────────────────────────────────────────────────────

    /**
     * One row per customer that has their own rate card(s).
     *
     * @param array{q?:?string, status?:?string, equipment?:?string, sort?:?string} $f
     *        status: in_force | ending | upcoming | expired | all (default in_force)
     *        equipment: "c:{slug}" or "t:{template id}"
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public static function customerPricing(array $f, int $page, int $perPage, string $today): array
    {
        $params = [];
        $where  = ['rc.deleted_at IS NULL', 'rc.customer_id IS NOT NULL', 'c.deleted_at IS NULL'];
        if (!empty($f['q'])) {
            $where[]  = '(c.company_name LIKE ? OR rc.name LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        $cards = \db_select(
            "SELECT rc.id, rc.name, rc.customer_id, c.company_name AS customer_name,
                    rc.effective_from, rc.effective_to, rc.updated_at
               FROM rate_cards rc
               JOIN customers c ON c.id = rc.customer_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY c.company_name ASC, rc.effective_from DESC",
            $params
        );
        $itemsByCard = self::itemsByCard(array_map('intval', array_column($cards, 'id')));
        $leases = self::activeLeaseCounts();

        $byCustomer = [];
        foreach ($cards as $card) {
            $cid    = (int) $card['customer_id'];
            $status = RateCardItems::status($card, $today);
            $byCustomer[$cid] ??= [
                'customer_id'   => $cid,
                'customer_name' => $card['customer_name'],
                'active_leases' => $leases[$cid] ?? 0,
                'cards'         => [],
                'lines'         => [],
            ];
            $byCustomer[$cid]['cards'][] = [
                'id'             => (int) $card['id'],
                'name'           => $card['name'],
                'status'         => $status,
                'effective_from' => $card['effective_from'],
                'effective_to'   => $card['effective_to'],
                'line_count'     => count($itemsByCard[(int) $card['id']] ?? []),
                'updated_at'     => $card['updated_at'],
            ];
            foreach (self::decorateLines($itemsByCard[(int) $card['id']] ?? [], $today) as $line) {
                $byCustomer[$cid]['lines'][] = $line + [
                    'card_id'     => (int) $card['id'],
                    'card_name'   => $card['name'],
                    'card_status' => $status,
                    'card_from'   => $card['effective_from'],
                    'card_to'     => $card['effective_to'],
                ];
            }
        }

        // Summaries + filters.
        $status = in_array($f['status'] ?? '', ['in_force', 'ending', 'upcoming', 'expired', 'all'], true) ? $f['status'] : 'in_force';
        $equip  = (string) ($f['equipment'] ?? '');
        $rows   = [];
        foreach ($byCustomer as $row) {
            $statuses = array_column($row['cards'], 'status');
            $inForce  = array_values(array_filter($row['cards'], static fn ($c) => in_array($c['status'], ['active', 'ending'], true)));
            $ends     = array_filter(array_column($inForce, 'effective_to'));
            sort($ends);
            $row['summary'] = [
                'status'         => in_array('active', $statuses, true) ? 'active'
                                  : (in_array('ending', $statuses, true) ? 'ending'
                                  : (in_array('upcoming', $statuses, true) ? 'upcoming' : 'expired')),
                'in_force_cards' => count($inForce),
                'ending_cards'   => count(array_filter($statuses, static fn ($s) => $s === 'ending')),
                'upcoming_cards' => count(array_filter($statuses, static fn ($s) => $s === 'upcoming')),
                'next_end'       => $ends[0] ?? null,
                'issues'         => array_sum(array_map(static fn ($l) => in_array($l['card_status'], ['active', 'ending', 'upcoming'], true) ? count($l['issues']) : 0, $row['lines'])),
            ];

            if ($status === 'in_force' && $row['summary']['in_force_cards'] === 0) {
                continue;
            }
            if (in_array($status, ['ending', 'upcoming', 'expired'], true) && !in_array($status, $statuses, true)) {
                continue;
            }
            if ($equip !== '' && !self::linesCover($row['lines'], $equip)) {
                continue;
            }
            // Lines in force first, then upcoming, then ended.
            $rank = ['active' => 0, 'ending' => 0, 'upcoming' => 1, 'expired' => 2];
            usort($row['lines'], static fn ($a, $b) => [$rank[$a['card_status']], $a['label']] <=> [$rank[$b['card_status']], $b['label']]);
            $rows[] = $row;
        }

        $sort = (string) ($f['sort'] ?? 'name');
        if ($sort === 'ending') {
            usort($rows, static fn ($a, $b) => [$a['summary']['next_end'] === null, $a['summary']['next_end'], $a['customer_name']]
                                             <=> [$b['summary']['next_end'] === null, $b['summary']['next_end'], $b['customer_name']]);
        } elseif ($sort === 'leases') {
            usort($rows, static fn ($a, $b) => [$b['active_leases'], $a['customer_name']] <=> [$a['active_leases'], $b['customer_name']]);
        }

        $total      = count($rows);
        $perPage    = max(5, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(1, min($page, $totalPages));

        return [
            'rows'        => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Does any line cover the equipment filter? "c:slug" matches a category
     * line of that slug or a type line of a type in it; "t:id" matches that
     * type's line or its category's line.
     *
     * @param list<array<string,mixed>> $lines
     */
    private static function linesCover(array $lines, string $equip): bool
    {
        if (str_starts_with($equip, 't:')) {
            $tid = (int) substr($equip, 2);
            $cat = self::templates()[$tid]['category'] ?? null;
            foreach ($lines as $l) {
                if ($l['equipment_template_id'] === $tid || ($l['equipment_template_id'] === null && $l['equipment_type'] === $cat)) {
                    return true;
                }
            }
            return false;
        }
        $slug = str_starts_with($equip, 'c:') ? substr($equip, 2) : $equip;
        foreach ($lines as $l) {
            if ($l['equipment_type'] === $slug) {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────
    // Price book (Rates home → Standard prices)
    // ─────────────────────────────────────────────────────────────────

    /**
     * The standard price of every equipment type, grouped by category, plus
     * the general cards that set them.
     *
     * @return array{groups:list<array<string,mixed>>, general_cards:list<array<string,mixed>>}
     */
    public static function priceBook(string $today): array
    {
        $templates = self::templates();

        // Customer lines in force today, for "N customer deals".
        $custLines = \db_select(
            "SELECT rci.equipment_type, rci.equipment_template_id, rci.daily_rate, rci.monthly_rate, rc.customer_id
               FROM rate_card_items rci
               JOIN rate_cards rc ON rc.id = rci.rate_card_id
               JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
              WHERE rc.deleted_at IS NULL AND rc.customer_id IS NOT NULL
                AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)",
            [$today, $today]
        );
        $units = [];
        foreach (\db_select(
            "SELECT u.template_id,
                    COUNT(*) AS units,
                    SUM(CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END) AS on_rent
               FROM equipment_units u
               LEFT JOIN leases l ON l.equipment_unit_id = u.id AND l.status = 'active' AND l.deleted_at IS NULL
              WHERE u.deleted_at IS NULL AND u.status <> 'decommissioned'
              GROUP BY u.template_id"
        ) as $u) {
            $units[(int) $u['template_id']] = ['units' => (int) $u['units'], 'on_rent' => (int) $u['on_rent']];
        }

        $groups = [];
        foreach ($templates as $id => $t) {
            $std = self::standardFor($id, $today);
            $deals = array_values(array_filter($custLines, static fn ($l) =>
                ($l['equipment_template_id'] !== null && (int) $l['equipment_template_id'] === $id)
                || ($l['equipment_template_id'] === null && $l['equipment_type'] === $t['category'])));
            $customers = count(array_unique(array_column($deals, 'customer_id')));
            $range = static function (array $rows, string $f): ?array {
                $v = array_values(array_filter(array_column($rows, $f), static fn ($x) => $x !== null && $x !== ''));
                if ($v === []) {
                    return null;
                }
                usort($v, static fn ($a, $b) => bccomp((string) $a, (string) $b, 4));
                return [$v[0], $v[count($v) - 1]];
            };

            $slug = (string) $t['category'];
            $groups[$slug] ??= ['slug' => $slug, 'label' => RateCardItems::label($slug), 'types' => []];
            $groups[$slug]['types'][] = [
                'id'          => $id,
                'name'        => $t['name'],
                'is_active'   => (int) $t['is_active'] === 1,
                'updated_at'  => $t['updated_at'],
                'defaults'    => [
                    'daily_rate'   => $t['default_daily_rate'],
                    'weekly_rate'  => $t['default_weekly_rate'],
                    'monthly_rate' => $t['default_monthly_rate'],
                    'mileage_rate' => $t['default_mileage_rate'],
                    'mileage_unit' => $t['default_mileage_unit'],
                    'hourly_rate'  => $t['default_hourly_rate'],
                    'currency'     => $t['default_currency'],
                ],
                'standard'    => $std,
                'deals'       => [
                    'customers' => $customers,
                    'daily'     => $range($deals, 'daily_rate'),
                    'monthly'   => $range($deals, 'monthly_rate'),
                ],
                'units'       => $units[$id]['units'] ?? 0,
                'on_rent'     => $units[$id]['on_rent'] ?? 0,
            ];
        }
        ksort($groups);

        $general = [];
        $gCards = \db_select(
            "SELECT id, name, is_default, effective_from, effective_to, description, updated_at,
                    (SELECT COUNT(*) FROM rate_card_items WHERE rate_card_id = rate_cards.id) AS line_count
               FROM rate_cards
              WHERE deleted_at IS NULL AND customer_id IS NULL
              ORDER BY is_default DESC, effective_from DESC"
        );
        foreach ($gCards as $g) {
            $general[] = [
                'id'             => (int) $g['id'],
                'name'           => $g['name'],
                'is_default'     => (int) $g['is_default'] === 1,
                'effective_from' => $g['effective_from'],
                'effective_to'   => $g['effective_to'],
                'status'         => RateCardItems::status($g, $today),
                'line_count'     => (int) $g['line_count'],
                'description'    => $g['description'],
            ];
        }

        return ['groups' => array_values($groups), 'general_cards' => $general];
    }

    // ─────────────────────────────────────────────────────────────────
    // One customer (customer profile → Rates, create page, price sheet)
    // ─────────────────────────────────────────────────────────────────

    /**
     * What one customer pays for every equipment type today.
     *
     * @return array<string,mixed>
     */
    public static function customerPrices(int $customerId, string $today, bool $activeTypesOnly = true): array
    {
        $templates = self::templates();

        // Prices on the customer's active leases, per equipment type — the
        // most common set (a customer usually has one price per type).
        $leaseRows = \db_select(
            "SELECT u.template_id, l.daily_rate, l.weekly_rate, l.monthly_rate,
                    l.mileage_rate, l.mileage_unit, l.hourly_rate, l.gps_cost, l.currency,
                    l.minimum_billing_days
               FROM leases l
               JOIN equipment_units u ON u.id = l.equipment_unit_id
              WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL",
            [$customerId]
        );
        $leaseSets = [];
        foreach ($leaseRows as $r) {
            $tid = (int) $r['template_id'];
            $sig = implode('|', [$r['daily_rate'], $r['weekly_rate'], $r['monthly_rate'], $r['mileage_rate'], $r['mileage_unit'], $r['hourly_rate'], $r['gps_cost'], $r['currency']]);
            $leaseSets[$tid][$sig] ??= ['count' => 0, 'row' => $r];
            $leaseSets[$tid][$sig]['count']++;
        }

        $prices = [];
        foreach ($templates as $id => $t) {
            $onRent = array_sum(array_column($leaseSets[$id] ?? [], 'count'));
            if ($activeTypesOnly && (int) $t['is_active'] !== 1 && $onRent === 0) {
                continue;
            }
            $candidates = RateResolver::candidates($customerId, $t, $today);
            $price      = RateResolver::resolve($customerId, $t, $today, $candidates);
            $priced     = RateResolver::priceWinner($candidates);
            $std        = self::standardFor($id, $today);

            $lease = null;
            if (!empty($leaseSets[$id])) {
                $sets = $leaseSets[$id];
                uasort($sets, static fn ($a, $b) => $b['count'] <=> $a['count']);
                $top = reset($sets);
                $r   = $top['row'];
                $lease = [
                    'count'        => $onRent,
                    'variants'     => count($sets),
                    'daily_rate'   => $r['daily_rate'],
                    'weekly_rate'  => $r['weekly_rate'],
                    'monthly_rate' => $r['monthly_rate'],
                    'mileage_rate' => $r['mileage_rate'],
                    'mileage_unit' => $r['mileage_unit'],
                    'hourly_rate'  => $r['hourly_rate'],
                    'gps_price'    => $r['gps_cost'],
                    'currency'     => $r['currency'],
                    'minimum_days' => $r['minimum_billing_days'],
                ];
            }

            $prices[] = [
                'template_id'    => $id,
                'name'           => $t['name'],
                'category'       => $t['category'],
                'category_label' => RateCardItems::label((string) $t['category']),
                'is_active'      => (int) $t['is_active'] === 1,
                'price'          => $price,
                // S-RATES-MINIMUM-OVERLAY: the card that sets the PRICE, not a
                // minimum-only line ranked above it.
                'card_name'      => $priced['card_name'] ?? null,
                'line_scope'     => $priced !== null ? ($priced['equipment_template_id'] !== null ? 'type' : 'category') : null,
                'standard'       => $std,
                'vs'             => [
                    'daily'   => $price['source'] === 'customer' ? RateResolver::pctDiff($price['daily_rate'], $std['daily_rate'] ?? null) : null,
                    'monthly' => $price['source'] === 'customer' ? RateResolver::pctDiff($price['monthly_rate'], $std['monthly_rate'] ?? null) : null,
                ],
                'lease'          => $lease,
            ];
        }
        // Their own prices first, then what they rent, then the rest by name.
        usort($prices, static fn ($a, $b) =>
            [$a['price']['source'] !== 'customer', $a['lease'] === null, $a['name']]
            <=> [$b['price']['source'] !== 'customer', $b['lease'] === null, $b['name']]);

        $cards = [];
        foreach (\db_select(
            "SELECT rc.id, rc.name, rc.effective_from, rc.effective_to, rc.updated_at,
                    (SELECT COUNT(*) FROM rate_card_items WHERE rate_card_id = rc.id) AS line_count
               FROM rate_cards rc
              WHERE rc.customer_id = ? AND rc.deleted_at IS NULL
              ORDER BY rc.effective_from DESC",
            [$customerId]
        ) as $c) {
            $cards[] = [
                'id'             => (int) $c['id'],
                'name'           => $c['name'],
                'effective_from' => $c['effective_from'],
                'effective_to'   => $c['effective_to'],
                'status'         => RateCardItems::status($c, $today),
                'line_count'     => (int) $c['line_count'],
            ];
        }

        return [
            'customer_id' => $customerId,
            'date'        => $today,
            'prices'      => $prices,
            'cards'       => $cards,
            'own_prices'  => count(array_filter($prices, static fn ($p) => $p['price']['source'] === 'customer')),
            'on_rent'     => count($leaseRows),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Leases a card prices
    // ─────────────────────────────────────────────────────────────────

    /**
     * Active leases whose customer + equipment type resolve to $cardId today,
     * with the fields where the lease's prices differ from the card's.
     *
     * @return array{leases:list<array<string,mixed>>, total:int, differ:int}
     */
    public static function leasesOnCard(int $cardId, ?int $customerId, string $today): array
    {
        $params = [];
        $scope  = '';
        if ($customerId !== null) {
            $scope    = ' AND l.customer_id = ?';
            $params[] = $customerId;
        }
        $rows = \db_select(
            "SELECT l.id, l.contract_number, l.customer_id, l.start_date, l.end_date,
                    l.daily_rate, l.weekly_rate, l.monthly_rate, l.mileage_rate, l.mileage_unit,
                    l.mileage_rate_km, l.mileage_rate_miles, l.hourly_rate, l.gps_cost, l.currency,
                    COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
                    u.unit_number, u.template_id
               FROM leases l
               JOIN equipment_units u ON u.id = l.equipment_unit_id
               LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
              WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.customer_id IS NOT NULL{$scope}
              ORDER BY l.start_date DESC, l.id DESC",
            $params
        );

        $templates = self::templates();
        $resolved  = [];
        $out       = [];
        $differ    = 0;
        foreach ($rows as $r) {
            $tid = (int) $r['template_id'];
            $t   = $templates[$tid] ?? null;
            if ($t === null) {
                continue;
            }
            $k = (int) $r['customer_id'] . ':' . $tid;
            if (!isset($resolved[$k])) {
                // S-RATES-MINIMUM-OVERLAY: a lease is "on" the card that sets
                // its price — a minimum-only line prices nothing, so comparing
                // leases to its blank prices would flag every one as different.
                $resolved[$k] = RateResolver::priceWinner(RateResolver::candidates((int) $r['customer_id'], $t, $today));
            }
            $win = $resolved[$k];
            if ($win === null || (int) $win['rate_card_id'] !== $cardId) {
                continue;
            }
            $diffs = self::leaseDiffs($r, $win);
            if ($diffs) {
                $differ++;
            }
            $out[] = [
                'id'              => (int) $r['id'],
                'contract_number' => $r['contract_number'],
                'customer_id'     => (int) $r['customer_id'],
                'customer_name'   => $r['customer_name'],
                'unit_number'     => $r['unit_number'],
                'equipment'       => $t['name'],
                'start_date'      => $r['start_date'],
                'end_date'        => $r['end_date'],
                'currency'        => $r['currency'],
                'prices'          => [
                    'daily_rate'   => $r['daily_rate'],
                    'weekly_rate'  => $r['weekly_rate'],
                    'monthly_rate' => $r['monthly_rate'],
                ],
                'diffs'           => $diffs,
            ];
        }
        // Different prices first — they are the ones to look at.
        usort($out, static fn ($a, $b) => [$a['diffs'] === [], $b['start_date']] <=> [$b['diffs'] === [], $a['start_date']]);

        return ['leases' => $out, 'total' => count($out), 'differ' => $differ];
    }

    /**
     * Fields where a lease's frozen prices differ from a card line.
     *
     * @param  array<string,mixed> $lease
     * @param  array<string,mixed> $line  candidates() row
     * @return list<array{field:string, lease:?string, card:?string}>
     */
    private static function leaseDiffs(array $lease, array $line): array
    {
        $z   = static fn ($v): string => ($v === null || $v === '') ? '0' : (string) $v;
        $out = [];
        foreach (['daily_rate' => 2, 'weekly_rate' => 2, 'monthly_rate' => 2] as $f => $s) {
            if (bccomp($z($lease[$f]), $z($line[$f]), $s) !== 0) {
                $out[] = ['field' => $f, 'lease' => $lease[$f], 'card' => $line[$f]];
            }
        }
        // Mileage — compare in the card's unit when the lease carries it.
        $cardUnit = $line['mileage_unit'] ?? 'km';
        $leaseMil = null;
        if (($lease['mileage_unit'] ?? 'km') === $cardUnit) {
            $leaseMil = $lease['mileage_rate'];
        } elseif ($cardUnit === 'km' && $lease['mileage_rate_km'] !== null) {
            $leaseMil = $lease['mileage_rate_km'];
        } elseif ($cardUnit === 'miles' && $lease['mileage_rate_miles'] !== null) {
            $leaseMil = $lease['mileage_rate_miles'];
        }
        if ($leaseMil !== null && bccomp($z($leaseMil), $z($line['mileage_rate']), 4) !== 0) {
            $out[] = ['field' => 'mileage_rate', 'lease' => $leaseMil, 'card' => $line['mileage_rate']];
        }
        if (bccomp($z($lease['gps_cost']), $z($line['gps_price']), 2) !== 0) {
            $out[] = ['field' => 'gps_price', 'lease' => $lease['gps_cost'], 'card' => $line['gps_price']];
        }
        if (bccomp($z($lease['hourly_rate']), $z($line['hourly_rate']), 4) !== 0) {
            $out[] = ['field' => 'hourly_rate', 'lease' => $lease['hourly_rate'], 'card' => $line['hourly_rate']];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Needs attention (Rates home)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Everything on the rate cards that needs a person, most urgent first.
     *
     * @return array{items:list<array<string,mixed>>, counts:array<string,int>}
     */
    public static function attention(string $today): array
    {
        $items = [];
        $cards = \db_select(
            "SELECT rc.id, rc.name, rc.customer_id, rc.effective_from, rc.effective_to,
                    c.company_name AS customer_name, c.deleted_at AS customer_deleted
               FROM rate_cards rc
               LEFT JOIN customers c ON c.id = rc.customer_id
              WHERE rc.deleted_at IS NULL"
        );
        $itemsByCard = self::itemsByCard(array_map('intval', array_column($cards, 'id')));
        $leases      = self::activeLeaseCounts();
        $soon14      = (new \DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
        $ago90       = (new \DateTimeImmutable($today))->modify('-90 days')->format('Y-m-d');

        // In-force customer line keys, per customer — to tell whether an
        // ended card was replaced.
        $liveKeys = [];
        foreach ($cards as $c) {
            if ($c['customer_id'] === null || $c['effective_from'] > $today || ($c['effective_to'] !== null && $c['effective_to'] < $today)) {
                continue;
            }
            foreach ($itemsByCard[(int) $c['id']] ?? [] as $it) {
                $liveKeys[(int) $c['customer_id']][RateCardItems::lineKey($it)] = true;
            }
        }

        $whoFor = static fn (array $c): string => $c['customer_id'] !== null ? (string) ($c['customer_name'] ?? 'Archived customer') : 'everyone';
        foreach ($cards as $c) {
            $id     = (int) $c['id'];
            $status = RateCardItems::status($c, $today);
            $lines  = $itemsByCard[$id] ?? [];
            $url    = 'rates/show?id=' . $id;

            if ($c['customer_id'] !== null && $c['customer_deleted'] !== null && in_array($status, ['active', 'ending', 'upcoming'], true)) {
                $items[] = ['tone' => 'info', 'kind' => 'archived_customer', 'card_id' => $id, 'url' => $url,
                    'title' => $c['name'], 'text' => 'The customer on this card is archived — it prices nothing.', 'sort' => 5];
                continue;
            }
            if ($status === 'ending') {
                $days = (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable((string) $c['effective_to']))->days;
                $items[] = ['tone' => $days <= 7 ? 'danger' : 'warning', 'kind' => 'ending', 'card_id' => $id, 'url' => $url, 'action' => 'revise',
                    'title' => $c['name'], 'text' => 'Prices for ' . $whoFor($c) . ' end ' . ($days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's')) . ' (' . \format_date($c['effective_to']) . ').',
                    'sort' => $days <= 7 ? 1 : 2];
            }
            if ($status === 'upcoming' && $c['effective_from'] <= $soon14) {
                $items[] = ['tone' => 'info', 'kind' => 'upcoming', 'card_id' => $id, 'url' => $url,
                    'title' => $c['name'], 'text' => 'New prices for ' . $whoFor($c) . ' start ' . \format_date($c['effective_from']) . '.', 'sort' => 6];
            }
            if (in_array($status, ['active', 'ending', 'upcoming'], true)) {
                if ($lines === []) {
                    $items[] = ['tone' => 'warning', 'kind' => 'empty', 'card_id' => $id, 'url' => $url,
                        'title' => $c['name'], 'text' => 'This card has no prices — it prices nothing.', 'sort' => 3];
                }
                foreach ($lines as $it) {
                    foreach (self::lineIssues($it) as $issue) {
                        [$label] = self::lineLabels($it);
                        $items[] = ['tone' => 'danger', 'kind' => 'line_issue', 'card_id' => $id, 'url' => $url,
                            'title' => $c['name'] . ' · ' . $label, 'text' => $issue, 'sort' => 0];
                    }
                }
            }
            // A customer card that ended recently, was not replaced, and the
            // customer still rents from you: new leases now get standard prices.
            if ($status === 'expired' && $c['customer_id'] !== null && $c['customer_deleted'] === null
                && $c['effective_to'] >= $ago90 && ($leases[(int) $c['customer_id']] ?? 0) > 0) {
                $gone = array_filter($lines, static fn ($it) => !isset($liveKeys[(int) $c['customer_id']][RateCardItems::lineKey($it)]));
                if ($gone) {
                    $items[] = ['tone' => 'warning', 'kind' => 'expired_uncovered', 'card_id' => $id, 'url' => $url, 'action' => 'revise',
                        'title' => $c['name'], 'text' => (string) $c['customer_name'] . "'s prices ended " . \format_date($c['effective_to']) . ' and were not renewed — new leases get standard prices.',
                        'sort' => 2];
                }
            }
        }

        // Customers renting without any card of their own.
        $noCard = \db_select(
            "SELECT c.id, c.company_name, COUNT(l.id) AS leases
               FROM customers c
               JOIN leases l ON l.customer_id = c.id AND l.status = 'active' AND l.deleted_at IS NULL
              WHERE c.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM rate_cards rc
                     WHERE rc.customer_id = c.id AND rc.deleted_at IS NULL
                       AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)
                )
              GROUP BY c.id, c.company_name
              ORDER BY leases DESC, c.company_name ASC",
            [$today, $today]
        );
        foreach ($noCard as $n) {
            $items[] = ['tone' => 'info', 'kind' => 'no_card', 'customer_id' => (int) $n['id'],
                'url' => 'rates/create?customer_id=' . (int) $n['id'],
                'title' => $n['company_name'],
                'text' => (int) $n['leases'] . ' lease' . ((int) $n['leases'] === 1 ? '' : 's') . ' on rent with no rate card — their prices live only on each lease.',
                'sort' => 7];
        }

        usort($items, static fn ($a, $b) => [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']]);
        $counts = [];
        foreach ($items as $i => $it) {
            $counts[$it['kind']] = ($counts[$it['kind']] ?? 0) + 1;
            unset($items[$i]['sort']);
        }

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * Headline numbers for the Rates home.
     *
     * @return array<string,int>
     */
    public static function kpis(string $today): array
    {
        $soon = (new \DateTimeImmutable($today))->modify('+' . RateCardItems::ENDING_SOON_DAYS . ' days')->format('Y-m-d');
        $row  = \db_row(
            "SELECT
                SUM(rc.customer_id IS NOT NULL AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)) AS customer_in_force,
                SUM(rc.customer_id IS NULL AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)) AS general_in_force,
                SUM(rc.effective_to IS NOT NULL AND rc.effective_to >= ? AND rc.effective_to <= ? AND rc.effective_from <= ?) AS ending,
                SUM(rc.effective_from > ?) AS upcoming,
                COUNT(*) AS cards
               FROM rate_cards rc
               LEFT JOIN customers c ON c.id = rc.customer_id
              WHERE rc.deleted_at IS NULL AND (rc.customer_id IS NULL OR c.deleted_at IS NULL)",
            [$today, $today, $today, $today, $today, $soon, $today, $today]
        ) ?: [];
        $customers = (int) (\db_row(
            "SELECT COUNT(DISTINCT rc.customer_id) AS n
               FROM rate_cards rc JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
              WHERE rc.deleted_at IS NULL AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)",
            [$today, $today]
        )['n'] ?? 0);

        $types  = self::templates();
        $priced = 0;
        $active = 0;
        foreach ($types as $id => $t) {
            if ((int) $t['is_active'] !== 1) {
                continue;
            }
            $active++;
            if ((self::standardFor($id, $today)['source'] ?? 'none') !== 'none') {
                $priced++;
            }
        }

        return [
            'customers_with_prices' => $customers,
            'customer_cards'        => (int) ($row['customer_in_force'] ?? 0),
            'general_cards'         => (int) ($row['general_in_force'] ?? 0),
            'ending_soon'           => (int) ($row['ending'] ?? 0),
            'upcoming'              => (int) ($row['upcoming'] ?? 0),
            'cards'                 => (int) ($row['cards'] ?? 0),
            'types_priced'          => $priced,
            'types_active'          => $active,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // History
    // ─────────────────────────────────────────────────────────────────

    /**
     * A card's change log (newest first, with per-line price diffs where the
     * audit row carries line snapshots) and the price timeline of its lines
     * across every card of the same customer (or every general card).
     *
     * @return array{events:list<array<string,mixed>>, timeline:list<array<string,mixed>>}
     */
    public static function history(int $cardId, string $today): array
    {
        $card = \db_row("SELECT id, customer_id FROM rate_cards WHERE id = ?", [$cardId]);
        if (!$card) {
            return ['events' => [], 'timeline' => []];
        }

        $labels = [];
        $labelFor = static function (string $key) use (&$labels): string {
            if (!isset($labels[$key])) {
                $labels[$key] = str_starts_with($key, 't:')
                    ? (string) (self::templates()[(int) substr($key, 2)]['name'] ?? 'Equipment type #' . substr($key, 2))
                    : RateCardItems::label(substr($key, 2));
            }
            return $labels[$key];
        };

        $events = [];
        foreach (\db_select(
            "SELECT id, action, user_name, old_values, new_values, notes, created_at
               FROM audit_log
              WHERE entity_type = 'rate_card' AND entity_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 100",
            [$cardId]
        ) as $a) {
            $old = is_string($a['old_values']) ? (json_decode($a['old_values'], true) ?: []) : [];
            $new = is_string($a['new_values']) ? (json_decode($a['new_values'], true) ?: []) : [];

            $fields = [];
            foreach (['name' => 'Name', 'effective_from' => 'Starts', 'effective_to' => 'Ends', 'customer_id' => 'Customer', 'is_default' => 'Main price list', 'description' => 'Description'] as $k => $lbl) {
                if (array_key_exists($k, $new) && array_key_exists($k, $old) && (string) $old[$k] !== (string) $new[$k]) {
                    $fields[] = ['label' => $lbl, 'from' => $old[$k], 'to' => $new[$k], 'field' => $k];
                }
            }
            $lines = [];
            if (isset($new['items']) && is_array($new['items'])) {
                $diff = RateCardItems::diff(is_array($old['items'] ?? null) ? $old['items'] : [], $new['items']);
                foreach ($diff as $d) {
                    $snap = null;
                    foreach ($new['items'] as $row) {
                        if (($row['key'] ?? '') === $d['key']) {
                            $snap = $row;
                        }
                    }
                    $lines[] = ['label' => $labelFor($d['key']), 'change' => $d['change'], 'fields' => $d['fields'], 'prices' => $snap];
                }
            }
            $events[] = [
                'id'         => (int) $a['id'],
                'action'     => $a['action'],
                'user_name'  => $a['user_name'],
                'at'         => $a['created_at'],
                'notes'      => $a['notes'],
                'fields'     => $fields,
                'lines'      => $lines,
                'replaced_by'=> isset($new['replaced_by']) ? (int) $new['replaced_by'] : null,
                'replaces'   => isset($new['replaces_card_id']) ? (int) $new['replaces_card_id'] : null,
                'item_count' => $new['item_count'] ?? null,
            ];
        }

        // Timeline: every live card of the same customer (or every general
        // card) that has a line with one of this card's keys.
        $keys = array_map([RateCardItems::class, 'lineKey'], \db_select(
            "SELECT equipment_type, equipment_template_id FROM rate_card_items WHERE rate_card_id = ?",
            [$cardId]
        ));
        $timeline = [];
        if ($keys !== []) {
            $scope  = $card['customer_id'] !== null ? 'rc.customer_id = ?' : 'rc.customer_id IS NULL';
            $params = $card['customer_id'] !== null ? [(int) $card['customer_id']] : [];
            $rows = \db_select(
                "SELECT rci.equipment_type, rci.equipment_template_id, rci.daily_rate, rci.weekly_rate,
                        rci.monthly_rate, rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price,
                        rci.minimum_days, rci.currency,
                        rc.id AS card_id, rc.name AS card_name, rc.effective_from, rc.effective_to
                   FROM rate_card_items rci
                   JOIN rate_cards rc ON rc.id = rci.rate_card_id
                  WHERE rc.deleted_at IS NULL AND {$scope}
                  ORDER BY rc.effective_from ASC, rc.id ASC",
                $params
            );
            $byKey = [];
            foreach ($rows as $r) {
                $k = RateCardItems::lineKey($r);
                if (!in_array($k, $keys, true)) {
                    continue;
                }
                $byKey[$k][] = [
                    'card_id'        => (int) $r['card_id'],
                    'card_name'      => $r['card_name'],
                    'effective_from' => $r['effective_from'],
                    'effective_to'   => $r['effective_to'],
                    'status'         => RateCardItems::status($r, $today),
                    'is_this'        => (int) $r['card_id'] === $cardId,
                    'prices'         => RateResolver::pickPrices($r),
                ];
            }
            foreach ($byKey as $k => $periods) {
                $timeline[] = ['key' => $k, 'label' => $labelFor($k), 'periods' => $periods];
            }
            usort($timeline, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return ['events' => $events, 'timeline' => $timeline];
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * rate_card_items grouped by card id.
     *
     * @param  list<int> $cardIds
     * @return array<int,list<array<string,mixed>>>
     */
    public static function itemsByCard(array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($cardIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (\db_select(
                "SELECT rci.id, rci.rate_card_id, rci.equipment_type, rci.equipment_template_id,
                        et.name AS equipment_template_name,
                        rci.daily_rate, rci.weekly_rate, rci.monthly_rate, rci.mileage_rate, rci.mileage_unit,
                        rci.hourly_rate, rci.gps_price, rci.minimum_days, rci.currency, rci.notes
                   FROM rate_card_items rci
                   LEFT JOIN equipment_templates et ON et.id = rci.equipment_template_id
                  WHERE rci.rate_card_id IN ($in)
                  ORDER BY rci.rate_card_id, rci.equipment_type, rci.id",
                $chunk
            ) as $it) {
                $out[(int) $it['rate_card_id']][] = $it;
            }
        }
        return $out;
    }

    /**
     * Active leases per customer.
     *
     * @return array<int,int>
     */
    public static function activeLeaseCounts(): array
    {
        $out = [];
        foreach (\db_select(
            "SELECT customer_id, COUNT(*) AS n FROM leases
              WHERE status = 'active' AND deleted_at IS NULL AND customer_id IS NOT NULL
              GROUP BY customer_id"
        ) as $r) {
            $out[(int) $r['customer_id']] = (int) $r['n'];
        }
        return $out;
    }
}
