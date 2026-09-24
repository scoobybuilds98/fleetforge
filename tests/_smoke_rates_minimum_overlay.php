<?php
declare(strict_types=1);

/**
 * tests/_smoke_rates_minimum_overlay.php
 *
 * S-RATES-MINIMUM-OVERLAY — a rate-card line that sets ONLY a minimum (every
 * price blank) overlays its minimum_days onto the next tier's prices instead
 * of winning the price outright. Schema-real: every case runs the real
 * RateResolver / RateInsights / lookup_rates.php against real rate_cards,
 * rate_card_items and equipment_templates rows.
 *
 * WHY: general card #27 "Chassis Minimum Days" (main price list, one
 * whole-category chassis line, minimum_days=3, no prices) used to win for
 * every chassis type, so customers without a chassis card of their own got a
 * blank lease pre-fill / Price check / standard price (KNOWN ISSUE #116, F96).
 *
 *   M1  a general minimum-only line over the type's defaults → template
 *       prices + its minimum, label names the card
 *   M2  … over a lower-ranked priced general card → that card's prices +
 *       the overlaid minimum
 *   M3  a priced line ranked ABOVE a minimum-only line → unchanged: its own
 *       prices, its own (blank) minimum; lower lines never contribute
 *   M4  a customer minimum-only line (5) over a general priced line (min 2)
 *       → general prices, the customer's 5-day minimum (higher rank wins)
 *   M5  minimum 0 counts as set (explicit "no minimum")
 *   M6  no priced line + no type defaults → none, minimum kept
 *   M7  a line with no prices AND no minimum is skipped entirely
 *   M8  helpers: hasPrices (GPS-only is a price, mileage 0 is not),
 *       priceWinnerIndex, minimumIndex
 *   M9  explain(): price card vs minimum card, used_for / priceless, reasons,
 *       tier states; quote() bills the overlaid minimum on the type's prices
 *   M10 RateInsights: customerPrices names the PRICED card; leasesOnCard
 *       never assigns a lease to a minimum-only card; lineIssues accepts a
 *       minimum-only line and flags an empty one
 *   M11 api/v1/leases/lookup_rates.php (real endpoint, child process)
 *       returns the overlaid price with the exact response keys
 *
 * M1–M10 run inside ONE transaction that is rolled back. M11 needs its
 * fixtures committed (the endpoint runs in a child process with its own
 * connection); it tags them and deletes them in a finally.
 *
 * Synthetic category slugs keep every fixture out of reach of real cards
 * (the resolver matches template.category ↔ rate_card_items.equipment_type).
 *
 * Run:  php tests/_smoke_rates_minimum_overlay.php
 * Exit: 0 all pass, 1 on any failure.
 *
 * @session S-RATES-MINIMUM-OVERLAY
 */

// ── CHILD MODE: run lookup_rates.php as a super admin ────────────────────
if (isset($argv[1], $argv[2]) && ctype_digit($argv[1]) && ctype_digit($argv[2])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET = ['customer_id' => (int) $argv[1], 'equipment_template_id' => (int) $argv[2]];

    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id' => 1, 'name' => 'Smoke Admin', 'email' => 'smoke@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();

    require FF_ROOT . '/api/v1/leases/lookup_rates.php';
    exit(0); // unreached — the endpoint exits via json_response()
}

// ── PARENT MODE ─────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\RateCards\RateInsights;
use FleetForge\RateCards\RateResolver;

echo "FleetForge — minimum-only rate-card lines overlay (S-RATES-MINIMUM-OVERLAY)\n";
echo str_repeat('=', 70), "\n";

$passes   = 0;
$failures = [];
$check = static function (bool $ok, string $m) use (&$passes, &$failures): void {
    if ($ok) { $passes++; echo "  PASS  {$m}\n"; }
    else     { $failures[] = $m; echo "  FAIL  {$m}\n"; }
};
$section = static fn (string $t) => print("\n── {$t}\n");

$today = ff_today();
$tag   = bin2hex(random_bytes(3));
$KEYS  = ['source', 'source_label', 'daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'mileage_unit',
          'hourly_rate', 'currency', 'gps_price', 'rate_card_id', 'minimum_days'];

/** A template in its own synthetic category. */
$mkTemplate = static function (string $cat, string $name, array $defaults = []) use ($tag): int {
    return db_insert('equipment_templates', $defaults + ['name' => $name, 'slug' => $cat . '-' . $tag, 'category' => $cat,
        'default_mileage_rate' => '0.0000']);
};
/** A card with one line; $line holds the line's price / minimum columns. */
$mkCard = static function (string $name, string $cat, array $line, array $card = []) use ($tag): int {
    $id = db_insert('rate_cards', $card + ['name' => $name . ' ' . $tag, 'effective_from' => '2020-01-01', 'is_default' => 0]);
    db_insert('rate_card_items', ['rate_card_id' => $id, 'equipment_type' => $cat] + $line);
    return $id;
};
$chassisDefaults = ['default_daily_rate' => '50.00', 'default_weekly_rate' => '300.00', 'default_monthly_rate' => '650.00',
                    'default_mileage_rate' => '0.0900', 'default_mileage_unit' => 'miles'];

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $cust = db_insert('customers', ['company_name' => 'ZZ MinOverlay ' . $tag]);

    // ── M1 ───────────────────────────────────────────────────────────
    $section('M1 minimum-only general line over the equipment type defaults (the card #27 shape)');
    $c1 = 'zz_mo1_' . $tag;
    $t1 = $mkTemplate($c1, "ZZ 40' Tridem", $chassisDefaults);
    $minCard1 = $mkCard('ZZ Chassis Minimum Days', $c1, ['minimum_days' => 3], ['is_default' => 1]);
    $tpl1 = RateResolver::template($t1);
    $r1   = RateResolver::resolve(null, $tpl1, $today);
    $check(array_keys($r1) === $KEYS, 'response keeps the exact lookup_rates keys, in order');
    $check($r1['source'] === 'template' && $r1['daily_rate'] === '50.00' && $r1['weekly_rate'] === '300.00' && $r1['monthly_rate'] === '650.00',
        "prices come from the type's defaults (\$50 / \$300 / \$650), not the blank line");
    $check($r1['mileage_rate'] === '0.0900' && $r1['mileage_unit'] === 'miles', "distance price + unit come with the type's prices (not the line's km)");
    $check((int) $r1['minimum_days'] === 3 && $r1['rate_card_id'] === null, 'minimum overlaid from the line (3); rate_card_id stays the price source (none)');
    $check($r1['source_label'] === "ZZ 40' Tridem rate · template default · 3-day minimum from card \"ZZ Chassis Minimum Days {$tag}\"",
        'label says where the minimum came from');
    $r1c = RateResolver::resolve($cust, $tpl1, $today);
    $check($r1c['source'] === 'template' && $r1c['daily_rate'] === '50.00' && (int) $r1c['minimum_days'] === 3,
        'a customer without a card of their own gets the same (standard) price');
    $check(RateResolver::standard($tpl1, $today) === $r1, 'standard() = the overlaid price');

    // ── M2 ───────────────────────────────────────────────────────────
    $section('M2 minimum-only line over a lower-ranked priced general card');
    $c2 = 'zz_mo2_' . $tag;
    $t2 = $mkTemplate($c2, 'ZZ Two', $chassisDefaults);
    $minCard2 = $mkCard('ZZ Min2', $c2, ['minimum_days' => 4], ['is_default' => 1]);
    $priced2  = $mkCard('ZZ Priced2', $c2, ['daily_rate' => '61.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
                                             'mileage_rate' => '0.0500', 'mileage_unit' => 'km', 'minimum_days' => 2]);
    $r2 = RateResolver::resolve(null, RateResolver::template($t2), $today);
    $check($r2['source'] === 'rate_card' && $r2['rate_card_id'] === $priced2 && $r2['daily_rate'] === '61.00' && $r2['mileage_unit'] === 'km',
        'prices (and unit) come from the priced card, which also becomes rate_card_id');
    $check((int) $r2['minimum_days'] === 4, "the higher-ranked minimum-only line's 4 days beats the priced line's own 2");
    $check(str_ends_with($r2['source_label'], "card \"ZZ Priced2 {$tag}\" · 4-day minimum from card \"ZZ Min2 {$tag}\""), 'label names both cards');

    // ── M3 ───────────────────────────────────────────────────────────
    $section('M3 a priced line ranked above a minimum-only line is unchanged');
    $c3 = 'zz_mo3_' . $tag;
    $t3 = $mkTemplate($c3, 'ZZ Three', $chassisDefaults);
    $mkCard('ZZ Min3', $c3, ['minimum_days' => 3], ['is_default' => 1]);
    $cust3 = $mkCard('ZZ Cust3', $c3, ['daily_rate' => '40.00', 'weekly_rate' => '240.00', 'monthly_rate' => '520.00'], ['customer_id' => $cust]);
    $r3 = RateResolver::resolve($cust, RateResolver::template($t3), $today);
    $check($r3['source'] === 'customer' && $r3['rate_card_id'] === $cust3 && $r3['daily_rate'] === '40.00', "the customer's priced card wins");
    $check($r3['minimum_days'] === null, "its blank minimum stays blank (the lower minimum-only line does not reach up — the setting applies)");
    $check(!str_contains($r3['source_label'], 'minimum'), 'no minimum note in the label');

    // ── M4 ───────────────────────────────────────────────────────────
    $section("M4 a customer's minimum-only line over a general priced line");
    $c4 = 'zz_mo4_' . $tag;
    $t4 = $mkTemplate($c4, 'ZZ Four', $chassisDefaults);
    $gen4  = $mkCard('ZZ Gen4', $c4, ['daily_rate' => '55.00', 'weekly_rate' => '320.00', 'monthly_rate' => '680.00', 'minimum_days' => 2], ['is_default' => 1]);
    $cmin4 = $mkCard('ZZ CustMin4', $c4, ['minimum_days' => 5], ['customer_id' => $cust]);
    $r4 = RateResolver::resolve($cust, RateResolver::template($t4), $today);
    $check($r4['source'] === 'rate_card' && $r4['rate_card_id'] === $gen4 && $r4['daily_rate'] === '55.00', 'prices from the general card');
    $check((int) $r4['minimum_days'] === 5 && str_contains($r4['source_label'], "5-day minimum from custom card \"ZZ CustMin4 {$tag}\""),
        "the customer's 5-day minimum wins over the general line's 2 (label says custom card)");
    $r4s = RateResolver::resolve(null, RateResolver::template($t4), $today);
    $check((int) $r4s['minimum_days'] === 2 && $r4s['rate_card_id'] === $gen4, "the standard price keeps the general line's own 2-day minimum");

    // ── M5 ───────────────────────────────────────────────────────────
    $section('M5 minimum 0 counts as set');
    $c5 = 'zz_mo5_' . $tag;
    $t5 = $mkTemplate($c5, 'ZZ Five', $chassisDefaults);
    $mkCard('ZZ Zero5', $c5, ['minimum_days' => 0], ['is_default' => 1]);
    $mkCard('ZZ Priced5', $c5, ['daily_rate' => '10.00', 'weekly_rate' => '60.00', 'monthly_rate' => '200.00', 'minimum_days' => 4]);
    $r5 = RateResolver::resolve(null, RateResolver::template($t5), $today);
    $check($r5['minimum_days'] !== null && (int) $r5['minimum_days'] === 0 && $r5['daily_rate'] === '10.00',
        'an explicit 0 (no minimum) on the higher line beats the priced line\'s 4');

    // ── M6 ───────────────────────────────────────────────────────────
    $section('M6 no priced line and no type defaults');
    $c6 = 'zz_mo6_' . $tag;
    $t6 = $mkTemplate($c6, 'ZZ Six');
    $mkCard('ZZ Min6', $c6, ['minimum_days' => 3]);
    $r6 = RateResolver::resolve(null, RateResolver::template($t6), $today);
    $check(array_keys($r6) === $KEYS && $r6['source'] === 'none' && $r6['daily_rate'] === null && $r6['rate_card_id'] === null,
        'source none, no prices');
    $check((int) $r6['minimum_days'] === 3 && str_starts_with($r6['source_label'], 'No rates configured for ZZ Six · 3-day minimum'),
        'the minimum is still carried (and named)');

    // ── M7 ───────────────────────────────────────────────────────────
    $section('M7 a line with no prices and no minimum is skipped');
    $c7 = 'zz_mo7_' . $tag;
    $t7 = $mkTemplate($c7, 'ZZ Seven', $chassisDefaults);
    $empty7  = $mkCard('ZZ Empty7', $c7, [], ['is_default' => 1]);
    $priced7 = $mkCard('ZZ Priced7', $c7, ['daily_rate' => '20.00', 'weekly_rate' => '120.00', 'monthly_rate' => '400.00', 'minimum_days' => 6]);
    $r7 = RateResolver::resolve(null, RateResolver::template($t7), $today);
    $check($r7['rate_card_id'] === $priced7 && (int) $r7['minimum_days'] === 6 && !str_contains($r7['source_label'], 'minimum'),
        "the priced line wins with its own 6-day minimum; the empty line contributes nothing");

    // ── M8 ───────────────────────────────────────────────────────────
    $section('M8 helpers');
    $check(RateResolver::hasPrices(['gps_price' => '1.00']), 'GPS-only is a price');
    $check(RateResolver::hasPrices(['hourly_rate' => '12.5000']), 'hourly-only is a price');
    $check(!RateResolver::hasPrices(['mileage_rate' => '0.0000', 'daily_rate' => null, 'minimum_days' => 3]), 'mileage 0 + a minimum is not a price');
    $check(!RateResolver::hasPrices(['daily_rate' => '0.00', 'weekly_rate' => '', 'monthly_rate' => null]), '$0 / blank prices are not prices');
    $cands2 = RateResolver::candidates(null, RateResolver::template($t2), $today);
    $check(RateResolver::priceWinnerIndex($cands2) === 1 && RateResolver::minimumIndex($cands2) === 0
        && (int) RateResolver::priceWinner($cands2)['rate_card_id'] === $priced2, 'M2 candidates: price from #2, minimum from #1');
    $check(RateResolver::priceWinnerIndex([]) === null && RateResolver::minimumIndex([]) === null && RateResolver::priceWinner([]) === null,
        'empty candidate list → nulls');

    // ── M9 ───────────────────────────────────────────────────────────
    $section('M9 explain() + quote()');
    $ex1 = RateResolver::explain(null, $tpl1, $today);
    $check($ex1['price']['source'] === 'template' && $ex1['price']['card_name'] === null
        && $ex1['price']['minimum_card_id'] === $minCard1 && $ex1['price']['minimum_card_name'] === "ZZ Chassis Minimum Days {$tag}",
        'price source = type defaults; minimum_card_id/name = the minimum-only card');
    $check(count($ex1['candidates']) === 1 && $ex1['candidates'][0]['priceless'] === true && $ex1['candidates'][0]['used_for'] === ['minimum']
        && str_contains($ex1['candidates'][0]['why'], "3-day minimum is used; the prices come from the equipment type's defaults"),
        'the minimum-only line is listed as used for the minimum only, with the reason');
    $check($ex1['tiers'][1]['state'] === 'used' && str_contains($ex1['tiers'][1]['detail'], 'Sets only a minimum (3 days)')
        && $ex1['tiers'][2]['state'] === 'used', 'tiers: general used for the minimum, the type defaults used for the price');

    $ex2 = RateResolver::explain(null, RateResolver::template($t2), $today);
    $check($ex2['price']['card_name'] === "ZZ Priced2 {$tag}" && $ex2['price']['minimum_card_id'] === $minCard2
        && $ex2['candidates'][0]['used_for'] === ['minimum'] && $ex2['candidates'][1]['used_for'] === ['price']
        && str_contains($ex2['candidates'][1]['why'], 'The minimum comes from the line above'),
        'M2: winner = the priced card, the line above supplies the minimum');
    $ex3 = RateResolver::explain($cust, RateResolver::template($t3), $today);
    $check($ex3['price']['minimum_card_id'] === null && $ex3['candidates'][0]['used_for'] === ['price']
        && $ex3['candidates'][1]['used_for'] === [] && $ex3['candidates'][1]['priceless'] === true,
        'M3: no minimum card; the lower minimum-only line is used for nothing');
    $ex7 = RateResolver::explain(null, RateResolver::template($t7), $today);
    $check($ex7['candidates'][0]['why'] === 'Sets no prices and no minimum — skipped.' && $ex7['candidates'][1]['used_for'] === ['price', 'minimum'],
        'M7: the empty line is skipped, the priced line is used for both');

    // quote(): the overlaid minimum binds on the type's prices where the category enforces minimums.
    db_insert('equipment_categories', ['slug' => $c1, 'label' => 'ZZ MO Cat ' . $tag, 'enforce_minimum_billing_days' => 1]);
    $q = RateResolver::quote($ex1['price'], $tpl1, '2026-10-01', '2026-10-01', []);
    $check($q['minimum_days'] === 3 && $q['minimum_from'] === 'line' && $q['lines'][0]['amount'] === '150.00',
        "a 1-day rental bills the overlaid 3-day minimum × the type's \$50 = \$150.00");

    // ── M10 ──────────────────────────────────────────────────────────
    $section('M10 RateInsights');
    RateInsights::reset();
    $mine = RateInsights::customerPrices($cust, $today, false);
    $row1 = array_values(array_filter($mine['prices'], fn ($p) => $p['template_id'] === $t1))[0] ?? null;
    $row2 = array_values(array_filter($mine['prices'], fn ($p) => $p['template_id'] === $t2))[0] ?? null;
    $check($row1 && $row1['card_name'] === null && $row1['line_scope'] === null && $row1['price']['daily_rate'] === '50.00',
        'customerPrices: no card name when the type defaults set the price');
    $check($row2 && $row2['card_name'] === "ZZ Priced2 {$tag}" && $row2['line_scope'] === 'category', 'customerPrices: names the PRICED card');

    $unit = db_insert('equipment_units', ['template_id' => $t2, 'unit_number' => 'ZZMO' . $tag, 'ownership_type' => 'owned']);
    $lease = db_insert('leases', ['contract_number' => 'ZZMO-' . $tag, 'customer_id' => $cust, 'equipment_unit_id' => $unit,
        'start_date' => '2026-01-01', 'status' => 'active', 'daily_rate' => '61.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        'mileage_unit' => 'km', 'mileage_rate' => '0.0500']);
    RateInsights::reset();
    $check(RateInsights::leasesOnCard($minCard2, null, $today)['total'] === 0, 'leasesOnCard: a minimum-only card claims no lease');
    $lo = RateInsights::leasesOnCard($priced2, null, $today);
    $check($lo['total'] === 1 && $lo['leases'][0]['id'] === $lease && $lo['differ'] === 0, 'the lease is on the priced card, with no price differences');

    $check(RateInsights::lineIssues(['minimum_days' => 3]) === [], 'lineIssues: a minimum-only line is fine');
    $check(RateInsights::lineIssues(['minimum_days' => null]) === ['No prices and no minimum — this line does nothing.'], 'lineIssues: an empty line is flagged');
    $zero = RateInsights::lineIssues(['daily_rate' => '0.00', 'monthly_rate' => '0.00', 'gps_price' => '0.00', 'minimum_days' => 0]);
    $check(count($zero) === 1 && str_contains($zero[0], 'only switches the short-lease minimum off'),
        'lineIssues: an all-$0 line with minimum 0 (prod card #64 shape) is flagged for a second look');
    $check(RateInsights::lineIssues(['daily_rate' => '10.00', 'minimum_days' => null]) !== [], 'lineIssues: the D132 incomplete-trio rule still applies');
} finally {
    $pdo->rollBack();
    RateInsights::reset();
}

// ── M11 ──────────────────────────────────────────────────────────────
// Committed fixtures (the endpoint runs in its own process / connection).
$section('M11 api/v1/leases/lookup_rates.php (real endpoint)');
$cE = 'zz_mo_ep_' . $tag;
$ids = ['tpl' => 0, 'card' => 0, 'cust' => 0];
try {
    $ids['tpl']  = $mkTemplate($cE, 'ZZ EP Chassis', $chassisDefaults);
    $ids['card'] = db_insert('rate_cards', ['name' => 'ZZ EP Min ' . $tag, 'effective_from' => '2020-01-01', 'is_default' => 1]);
    db_insert('rate_card_items', ['rate_card_id' => $ids['card'], 'equipment_type' => $cE, 'minimum_days' => 3]);
    $ids['cust'] = db_insert('customers', ['company_name' => 'ZZ MinOverlay EP ' . $tag]);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $ids['cust'] . ' ' . $ids['tpl'] . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $j   = json_decode(substr($out, (int) strpos($out, '{')), true);
    $d   = $j['data'] ?? null;
    $check(is_array($d) && ($j['success'] ?? false) === true, 'the endpoint answers' . (is_array($d) ? '' : ' — got: ' . substr($out, 0, 200)));
    $check(is_array($d) && array_keys($d) === $KEYS, 'exact response keys, in order');
    $check(is_array($d) && $d['source'] === 'template' && $d['daily_rate'] === '50.00' && $d['monthly_rate'] === '650.00'
        && (int) $d['minimum_days'] === 3 && $d['rate_card_id'] === null,
        "a new lease pre-fills the type's \$50 / \$300 / \$650 with the card's 3-day minimum (was blank)");
    $check(is_array($d) && $d === RateResolver::resolve($ids['cust'], RateResolver::template($ids['tpl']), date('Y-m-d')),
        'the endpoint returns exactly what RateResolver::resolve() returns');
} finally {
    if ($ids['card']) { db_execute('DELETE FROM rate_card_items WHERE rate_card_id = ?', [$ids['card']]); db_execute('DELETE FROM rate_cards WHERE id = ?', [$ids['card']]); }
    if ($ids['tpl'])  { db_execute('DELETE FROM equipment_templates WHERE id = ?', [$ids['tpl']]); }
    if ($ids['cust']) { db_execute('DELETE FROM customers WHERE id = ?', [$ids['cust']]); }
}
$check((int) db_row('SELECT COUNT(*) n FROM rate_cards WHERE name LIKE ?', ['ZZ EP Min ' . $tag])['n'] === 0, 'endpoint fixtures cleaned up');

echo "\n" . ($failures ? 'FAILED' : 'ALL PASS') . " — {$passes} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "  ✗ {$f}\n"; }
exit($failures ? 1 : 0);
