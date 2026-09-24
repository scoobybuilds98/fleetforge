<?php
declare(strict_types=1);

/**
 * tests/_smoke_rates_module.php
 *
 * S-RATES-MODULE — the rebuilt Rates module, schema-real.
 *
 *   R1  RateResolver parity: for EVERY live customer × equipment type, the
 *       resolver picks the same card line (or an exact tie on every ORDER BY
 *       key) and the same prices as the pre-S-RATES-MODULE inline lookup
 *       SQL in api/v1/leases/lookup_rates.php; standard() = customer-less
 *   R2  resolve() keeps lookup_rates.php's response shape (keys, order, the
 *       template / none tiers)
 *   R3  RateCardItems: every validation message (incl. the D132 rent-trio
 *       rule and column limits), snapshot normalisation, diff, adjust +
 *       rounding, status windows, validDate range
 *   R4  explain() tiers + candidates + reasons; quote() equals
 *       HolisticLeaseEngine::cumulativeCorrect, and the short-lease minimum
 *       binds only where the equipment's category enforces it
 *   R5  RateCardRevision: dry run leaves the DB untouched; apply ends the old
 *       card the day before, creates the new one with adjusted prices, moves
 *       the main-list flag, writes both audit rows; a refused card makes the
 *       whole apply save nothing; explicit lines; $5 rounding that would
 *       zero a price is refused (D132); names stay unique
 *   R6  RateInsights: customer pricing (+ filters), price book, one customer's
 *       prices (+ lease prices), leases on a card (+ differing fields),
 *       needs-attention kinds, history timeline + change log with line diffs
 *   R7  static wiring: every new endpoint authenticates and gates on the
 *       right permission; create/update/revise share RateCardItems; the
 *       lookup uses the resolver; the pages load rates.css / rates.js; the
 *       list sort no longer interpolates a column name
 *   R8  HTTP (dev site fleetforge.test, READ-ONLY + dry runs): every GET
 *       endpoint answers for a super admin; create dry_run and revise
 *       dry_run validate without saving; a dispatcher (rates: none) is
 *       refused; the CSV export is formula-safe
 *
 * R1–R6 run inside ONE transaction that is rolled back. R8 creates nothing
 * (dry runs roll back server-side) and removes the session files it minted.
 *
 * Run:  php tests/_smoke_rates_module.php   (R8 needs the Herd dev site)
 * Exit: 0 all pass, 1 on any failure.
 *
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateCardRevision;
use FleetForge\RateCards\RateInsights;
use FleetForge\RateCards\RateResolver;

$passes   = 0;
$failures = [];
$check = static function (bool $ok, string $m) use (&$passes, &$failures): void {
    if ($ok) { $passes++; echo "  PASS  {$m}\n"; }
    else     { $failures[] = $m; echo "  FAIL  {$m}\n"; }
};
$section = static fn (string $t) => print("\n── {$t}\n");

/** The lookup SQL exactly as lookup_rates.php ran it before S-RATES-MODULE. */
function rt_legacy_lookup(int $customerId, array $t, string $today): ?array
{
    return db_row(
        "SELECT rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
                rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price, rci.currency,
                rci.minimum_days, rci.equipment_template_id,
                rc.id AS rate_card_id, rc.name AS card_name, rc.customer_id, rc.is_default, rc.effective_from
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
           AND (rc.customer_id = ? OR rc.customer_id IS NULL)
         ORDER BY
             (rc.customer_id IS NOT NULL) DESC,
             (rci.equipment_template_id IS NOT NULL) DESC,
             rc.is_default DESC,
             rc.effective_from DESC
         LIMIT 1",
        [$t['id'], $t['category'], $t['category'], $today, $today, $customerId]
    ) ?: null;
}

$today = date('Y-m-d');
$pdo   = db_pdo();
$pdo->beginTransaction();

try {
    // ── R1 ───────────────────────────────────────────────────────────
    $section('R1 resolver parity with the legacy lookup (every customer × equipment type)');
    $customers = array_map('intval', array_column(db_select("SELECT id FROM customers WHERE deleted_at IS NULL"), 'id'));
    $templates = RateInsights::templates();
    $combos = 0; $mismatch = []; $priceMismatch = 0; $overlay = 0;
    foreach ($customers as $cid) {
        foreach ($templates as $t) {
            $combos++;
            $old = rt_legacy_lookup($cid, $t, $today);
            $new = RateResolver::resolve($cid, $t, $today);
            if ($old === null) {
                if ($new['rate_card_id'] !== null) { $mismatch[] = "c{$cid}/t{$t['id']}: legacy none, resolver card {$new['rate_card_id']}"; }
                continue;
            }
            // S-RATES-MINIMUM-OVERLAY deliberately departs from the legacy SQL
            // when its winner is a minimum-only line (e.g. general card #27
            // "Chassis Minimum Days"): prices now come from the next priced
            // line / the type's defaults, and the minimum is overlaid. Those
            // combos are covered by tests/_smoke_rates_minimum_overlay.php;
            // here we only require the legacy winner's minimum to carry over.
            if (!RateResolver::hasPrices($old)) {
                $overlay++;
                if ($old['minimum_days'] !== null && (string) $old['minimum_days'] !== (string) $new['minimum_days']) {
                    $mismatch[] = "c{$cid}/t{$t['id']}: minimum-only line's {$old['minimum_days']}-day minimum not overlaid";
                }
                continue;
            }
            if ((int) $old['rate_card_id'] !== $new['rate_card_id']) {
                // Allowed only on an exact tie of every legacy ORDER BY key.
                $w = RateResolver::candidates($cid, $t, $today)[0] ?? null;
                $tie = $w && (($w['customer_id'] !== null) === ($old['customer_id'] !== null))
                    && (($w['equipment_template_id'] !== null) === ($old['equipment_template_id'] !== null))
                    && (int) $w['is_default'] === (int) $old['is_default'] && $w['effective_from'] === $old['effective_from'];
                if (!$tie) { $mismatch[] = "c{$cid}/t{$t['id']}: legacy {$old['rate_card_id']} vs resolver {$new['rate_card_id']}"; }
                continue;
            }
            foreach (['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'mileage_unit', 'hourly_rate', 'gps_price', 'currency', 'minimum_days'] as $f) {
                if ((string) $old[$f] !== (string) $new[$f]) { $priceMismatch++; }
            }
        }
    }
    $check($mismatch === [], "{$combos} customer × equipment combos pick the same card as the legacy SQL ({$overlay} minimum-only overlays)" . ($mismatch ? ' — ' . implode('; ', array_slice($mismatch, 0, 3)) : ''));
    $check($priceMismatch === 0, 'and carry identical prices, units, currency and minimum days');
    $anyT = reset($templates);
    $std  = RateResolver::standard($anyT, $today);
    $cands = RateResolver::candidates(null, $anyT, $today);
    $check(array_filter($cands, fn ($c) => $c['customer_id'] !== null) === [], 'standard() considers general cards only');
    $check($std['source'] !== 'customer', 'standard() never reports a customer source');

    // ── R2 ───────────────────────────────────────────────────────────
    $section('R2 lookup_rates response shape');
    $keys = ['source', 'source_label', 'daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'mileage_unit',
             'hourly_rate', 'currency', 'gps_price', 'rate_card_id', 'minimum_days'];
    $cat = 'zz_rt_' . bin2hex(random_bytes(2));
    $tNone = db_insert('equipment_templates', ['name' => 'ZZ RT None', 'slug' => $cat . '-none', 'category' => $cat . 'n',
                                               'default_mileage_rate' => '0.0000']);
    $tDef  = db_insert('equipment_templates', ['name' => 'ZZ RT Default', 'slug' => $cat . '-def', 'category' => $cat,
                                               'default_daily_rate' => '70.00', 'default_weekly_rate' => '400.00', 'default_monthly_rate' => '900.00',
                                               'default_mileage_rate' => '0.0500', 'default_mileage_unit' => 'miles']);
    $tTwo  = db_insert('equipment_templates', ['name' => 'ZZ RT Two', 'slug' => $cat . '-two', 'category' => $cat,
                                               'default_daily_rate' => '90.00', 'default_weekly_rate' => '500.00', 'default_monthly_rate' => '1100.00',
                                               'default_mileage_rate' => '0.0000']);
    $custA = db_insert('customers', ['company_name' => 'ZZ Rates Smoke A']);
    $custB = db_insert('customers', ['company_name' => 'ZZ Rates Smoke B']);
    RateInsights::reset();
    $rNone = RateResolver::resolve($custA, RateResolver::template($tNone), $today);
    $rDef  = RateResolver::resolve($custA, RateResolver::template($tDef), $today);
    $check(array_keys($rNone) === $keys && array_keys($rDef) === $keys, 'every tier returns exactly the lookup_rates keys, in order');
    $check($rNone['source'] === 'none' && $rNone['source_label'] === 'No rates configured for ZZ RT None' && $rNone['mileage_unit'] === 'km', 'none tier (0.0000 mileage default does not count as a price)');
    $check($rDef['source'] === 'template' && $rDef['daily_rate'] === '70.00' && $rDef['mileage_unit'] === 'miles' && $rDef['gps_price'] === null, 'template tier');

    // ── R3 ───────────────────────────────────────────────────────────
    $section('R3 RateCardItems');
    [$it, $err] = RateCardItems::normalize([
        ['equipment_type' => ''],
        ['equipment_type' => $cat, 'daily_rate' => '10', 'weekly_rate' => '60', 'monthly_rate' => '200'],
        ['equipment_type' => $cat, 'daily_rate' => '11', 'weekly_rate' => '61', 'monthly_rate' => '201'],
        ['equipment_type' => $cat . 'x', 'daily_rate' => '-1'],
        ['equipment_type' => $cat . 'y', 'daily_rate' => '10'],
        ['equipment_type' => $cat . 'z', 'hourly_rate' => '4.5', 'minimum_days' => '91'],
        ['equipment_type' => $cat . 'w', 'mileage_rate' => '10000'],
        ['equipment_type' => $cat . 'v', 'currency' => 'EUR', 'daily_rate' => 'abc'],
        ['equipment_type' => $cat . 'u', 'equipment_template_id' => $tTwo, 'hourly_rate' => '5'],
    ]);
    $has = static fn (string $needle) => (bool) array_filter($err, fn ($e) => str_contains($e, $needle));
    $check($has('Item 1: equipment type is required.'), 'blank type refused');
    $check($has('Item 3: this equipment type / template combination is listed more than once.'), 'duplicate line refused');
    $check($has('Item 4: Daily rate cannot be negative.'), 'negative refused');
    $check($has('Item 5: daily, weekly and monthly prices go together'), 'D132: a daily price without weekly / monthly refused');
    $check($has('Item 6: minimum days must be a whole number between 0 and 90.'), 'minimum days range');
    $check($has('Item 7: Mileage rate is too large.'), 'mileage over DECIMAL(8,4) refused');
    $check($has('Item 8: Daily rate must be a valid number.') && $has('Item 8: currency must be CAD or USD.'), 'bad number + currency');
    $check(count($it) === 2 && $it[1]['equipment_type'] === $cat && $it[1]['equipment_template_id'] === $tTwo,
        'valid lines kept; a template line takes its category from the template');
    $snapA = RateCardItems::snapshot([['equipment_type' => $cat, 'daily_rate' => '50', 'weekly_rate' => '300', 'monthly_rate' => '600', 'mileage_rate' => '0.04']]);
    $snapB = RateCardItems::snapshot([['equipment_type' => $cat, 'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '600.00', 'mileage_rate' => '0.0400']]);
    $check($snapA === $snapB && RateCardItems::diff($snapA, $snapB) === [], 'snapshot normalises 50 vs 50.00 (no phantom change)');
    $snapC = RateCardItems::snapshot([['equipment_type' => $cat, 'daily_rate' => '55', 'weekly_rate' => '300', 'monthly_rate' => '600'],
                                      ['equipment_type' => $cat . 'n', 'equipment_template_id' => null, 'hourly_rate' => '4']]);
    $d = RateCardItems::diff($snapA, $snapC);
    $changed = array_values(array_filter($d, fn ($x) => $x['change'] === 'changed'))[0] ?? null;
    $check(count($d) === 2 && $changed && in_array('daily_rate', array_column($changed['fields'], 'field'), true)
        && in_array('mileage_rate', array_column($changed['fields'], 'field'), true)
        && array_filter($d, fn ($x) => $x['change'] === 'added'), 'diff: changed fields + added line');
    $adj = RateCardItems::adjust([['daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '600.00', 'mileage_rate' => '0.0400', 'gps_price' => '1.00']],
        '4.5', '1', RateCardRevision::RENT_FIELDS);
    $check($adj[0]['daily_rate'] === '52.00' && $adj[0]['weekly_rate'] === '314.00' && $adj[0]['monthly_rate'] === '627.00' && $adj[0]['mileage_rate'] === '0.0400',
        '+4.5% to $1: 52.25→52, 313.50→314 (half-up), mileage untouched');
    $adj2 = RateCardItems::adjust([['daily_rate' => '41.00', 'mileage_rate' => '0.0400']], '-10', '0.01', array_keys(RateCardItems::RATE_LABELS));
    $check($adj2[0]['daily_rate'] === '36.90' && $adj2[0]['mileage_rate'] === '0.0360', '-10% to the cent, mileage keeps 4 decimals');
    $check(RateCardItems::roundTo('652.5', '5') === '655.00' && RateCardItems::roundTo('652.4', '5') === '650.00', 'nearest $5');
    $st = static fn ($f, $t) => RateCardItems::status(['effective_from' => $f, 'effective_to' => $t], '2026-09-24');
    $check($st('2026-01-01', null) === 'active' && $st('2026-01-01', '2026-10-10') === 'ending' && $st('2026-10-01', null) === 'upcoming'
        && $st('2026-01-01', '2026-09-23') === 'expired' && $st('2026-01-01', '2026-09-24') === 'ending', 'status windows (ending = ends within 30 days, today counts)');
    $check(RateCardItems::validDate('0001-01-01') === null && RateCardItems::validDate('2026-02-30') === null && RateCardItems::validDate('2026-02-28') === '2026-02-28',
        'validDate rejects year 0001 and impossible dates');

    // ── R4 ───────────────────────────────────────────────────────────
    $section('R4 explain + quote');
    $gen = db_insert('rate_cards', ['name' => 'ZZ RT General ' . $cat, 'effective_from' => '2020-01-01', 'is_default' => 0]);
    db_insert('rate_card_items', ['rate_card_id' => $gen, 'equipment_type' => $cat, 'daily_rate' => '60.00', 'weekly_rate' => '360.00', 'monthly_rate' => '800.00']);
    $cardA = db_insert('rate_cards', ['name' => 'ZZ RT Customer A ' . $cat, 'customer_id' => $custA, 'effective_from' => '2020-01-01', 'is_default' => 0]);
    db_insert('rate_card_items', ['rate_card_id' => $cardA, 'equipment_type' => $cat, 'daily_rate' => '50.00', 'weekly_rate' => '300.00',
                                  'monthly_rate' => '600.00', 'mileage_rate' => '0.0400', 'gps_price' => '1.00', 'minimum_days' => 3]);
    RateInsights::reset();
    $tpl = RateResolver::template($tDef);
    $ex  = RateResolver::explain($custA, $tpl, $today);
    $check($ex['price']['source'] === 'customer' && $ex['price']['rate_card_id'] === $cardA && $ex['price']['card_name'] === 'ZZ RT Customer A ' . $cat, 'customer card wins');
    $check(count($ex['candidates']) === 2 && $ex['candidates'][1]['rate_card_id'] === $gen
        && str_contains($ex['candidates'][1]['why'], "customer's own card"), 'the general line is listed as the runner-up, with the reason');
    $check($ex['tiers'][0]['state'] === 'used' && $ex['tiers'][1]['state'] === 'available' && $ex['tiers'][2]['state'] === 'available', 'tier states');
    $check($ex['standard']['rate_card_id'] === $gen && $ex['standard']['daily_rate'] === '60.00', 'standard = the general card');
    $exB = RateResolver::explain($custB, $tpl, $today);
    $check($exB['price']['source'] === 'rate_card' && $exB['tiers'][0]['state'] === 'none', 'a customer without a card gets the general card');

    $engine = new HolisticLeaseEngine();
    foreach ([['2026-10-01', '2026-10-01'], ['2026-10-01', '2026-10-12'], ['2026-10-05', '2026-12-20']] as [$s, $e]) {
        $q = RateResolver::quote($ex['price'], $tpl, $s, $e, []);
        $cc = $engine->cumulativeCorrect($s, $e, $e, '50.00', '300.00', '600.00', 0);
        $check($q['lines'][0]['amount'] === $cc['amount'] && $q['total'] === $cc['amount'], "quote {$s}→{$e} = engine \${$cc['amount']} (category does not enforce minimums)");
    }
    // Make the category enforce minimums: the line's 3-day minimum now binds.
    db_insert('equipment_categories', ['slug' => $cat, 'label' => 'ZZ RT Cat', 'enforce_minimum_billing_days' => 1]);
    $q1 = RateResolver::quote($ex['price'], $tpl, '2026-10-01', '2026-10-01', ['gps' => true, 'distance_per_day' => '100']);
    $check($q1['minimum_days'] === 3 && $q1['minimum_from'] === 'line' && $q1['lines'][0]['amount'] === '150.00', '1-day rental with an enforced 3-day minimum bills 3 × $50');
    $check(count($q1['lines']) === 3 && $q1['lines'][1]['amount'] === '1.00' && $q1['lines'][2]['amount'] === '4.00' && $q1['total'] === '155.00',
        'GPS 1 × $1 + 100 km × $0.04 → total $155.00');
    $qZero = RateResolver::quote(['daily_rate' => '50.00', 'weekly_rate' => null, 'monthly_rate' => null, 'minimum_days' => null, 'currency' => 'CAD'],
        $tpl, '2026-10-01', '2026-10-20', []);
    $check($qZero['warnings'] !== [], 'a daily-only price over 7 days warns that it bills $0 rent');

    // ── R5 ───────────────────────────────────────────────────────────
    $section('R5 change prices (RateCardRevision)');
    $opts = RateCardRevision::options(['effective_from' => '2031-03-01', 'percent' => '10', 'round' => '1', 'note' => 'smoke']);
    $before = db_row("SELECT COUNT(*) n FROM rate_cards")['n'];
    $dry = RateCardRevision::revise([$cardA], $opts, true, null, 'smoke', '127.0.0.1');
    $check($dry['ok'] && !$dry['applied'] && $dry['results'][0]['new_card_id'] === null, 'dry run: ok, not applied, no real id');
    $check(db_row("SELECT COUNT(*) n FROM rate_cards")['n'] === $before && db_row("SELECT effective_to FROM rate_cards WHERE id = ?", [$cardA])['effective_to'] === null,
        'dry run leaves the database untouched');
    $ln = $dry['results'][0]['lines'][0];
    $check($ln['before']['daily_rate'] === '50.00' && $ln['after']['daily_rate'] === '55.00' && $ln['after']['monthly_rate'] === '660.00'
        && $ln['after']['gps_price'] === '1.00', 'preview: +10% on rent prices, GPS untouched');

    // Refusals.
    try { RateCardRevision::options(['effective_from' => '0001-01-01']); $check(false, 'options: year 0001 refused'); }
    catch (\InvalidArgumentException $e) { $check(isset(json_decode($e->getMessage(), true)['effective_from']), 'options: year 0001 refused'); }
    try { RateCardRevision::options(['effective_from' => '2031-03-01', 'percent' => '-95']); $check(false, 'options: -95% refused'); }
    catch (\InvalidArgumentException $e) { $check(isset(json_decode($e->getMessage(), true)['percent']), 'options: -95% refused'); }
    $early = RateCardRevision::revise([$cardA], RateCardRevision::options(['effective_from' => '2019-06-01']), true, null, 'smoke', '127.0.0.1');
    $check(!$early['ok'] && str_contains($early['results'][0]['error'], 'must start after'), 'new prices must start after the card started');
    $tiny = db_insert('rate_cards', ['name' => 'ZZ RT Tiny ' . $cat, 'customer_id' => $custB, 'effective_from' => '2020-01-01']);
    db_insert('rate_card_items', ['rate_card_id' => $tiny, 'equipment_type' => $cat, 'daily_rate' => '2.00', 'weekly_rate' => '10.00', 'monthly_rate' => '40.00']);
    $z = RateCardRevision::revise([$tiny], RateCardRevision::options(['effective_from' => '2031-03-01', 'round' => '5']), true, null, 'smoke', '127.0.0.1');
    $check(!$z['ok'] && str_contains($z['results'][0]['error'], 'would become $0'), 'D132: $5 rounding that zeroes a price is refused');

    // All-or-nothing: A applies, the second card (a blocker) is refused → nothing saved.
    $blocker = db_insert('rate_cards', ['name' => 'ZZ RT Blocker ' . $cat, 'customer_id' => $custB, 'effective_from' => '2031-01-01']);
    db_insert('rate_card_items', ['rate_card_id' => $blocker, 'equipment_type' => $cat, 'daily_rate' => '5.00', 'weekly_rate' => '30.00', 'monthly_rate' => '100.00']);
    $mixed = RateCardRevision::revise([$cardA, $tiny], RateCardRevision::options(['effective_from' => '2031-03-01']), false, null, 'smoke', '127.0.0.1');
    $check(!$mixed['ok'] && !$mixed['applied'] && $mixed['results'][0]['status'] === 'ok' && $mixed['results'][1]['status'] === 'error'
        && str_contains($mixed['results'][1]['error'], 'already covered'), 'conflict on the 2nd card is reported (ConflictGuard)');
    $check(db_row("SELECT effective_to FROM rate_cards WHERE id = ?", [$cardA])['effective_to'] === null && db_row("SELECT COUNT(*) n FROM rate_cards")['n'] === $before + 2,
        '…and nothing was saved for the 1st card either');
    db_execute("UPDATE rate_cards SET deleted_at = NOW() WHERE id IN (?, ?)", [$tiny, $blocker]);

    // Apply for real (inside the smoke transaction).
    db_execute("UPDATE rate_cards SET is_default = 0 WHERE is_default = 1");
    db_execute("UPDATE rate_cards SET is_default = 1 WHERE id = ?", [$gen]);
    $applied = RateCardRevision::revise([$cardA, $gen], $opts, false, null, 'smoke', '127.0.0.1');
    $newA = $applied['results'][0]['new_card_id'] ?? null;
    $newG = $applied['results'][1]['new_card_id'] ?? null;
    $check($applied['ok'] && $applied['applied'] && $newA && $newG, 'apply: both cards changed');
    $oldA = db_row("SELECT effective_to FROM rate_cards WHERE id = ?", [$cardA]);
    $nA   = db_row("SELECT * FROM rate_cards WHERE id = ?", [$newA]);
    $check($oldA['effective_to'] === '2031-02-28' && $nA['effective_from'] === '2031-03-01' && $nA['effective_to'] === null && (int) $nA['customer_id'] === $custA,
        'old card ends the day before; the new card starts that day, same customer, open-ended');
    $check($nA['name'] === 'ZZ RT Customer A ' . $cat . ' · from Mar 2031', 'suggested name "<old> · from Mar 2031"');
    $nItem = db_row("SELECT daily_rate, monthly_rate, minimum_days, gps_price FROM rate_card_items WHERE rate_card_id = ?", [$newA]);
    $check($nItem['daily_rate'] === '55.00' && $nItem['monthly_rate'] === '660.00' && (int) $nItem['minimum_days'] === 3 && $nItem['gps_price'] === '1.00',
        'new line: adjusted rent, minimum days + GPS carried over');
    $check((int) db_row("SELECT is_default FROM rate_cards WHERE id = ?", [$gen])['is_default'] === 0 && (int) db_row("SELECT is_default FROM rate_cards WHERE id = ?", [$newG])['is_default'] === 1,
        'the main-list flag moved to the new general card');
    $aud = db_select("SELECT entity_id, action, new_values, notes FROM audit_log WHERE entity_type = 'rate_card' AND entity_id IN (?, ?) ORDER BY id", [$cardA, $newA]);
    $oldAud = array_values(array_filter($aud, fn ($a) => (int) $a['entity_id'] === $cardA))[0] ?? null;
    $newAud = array_values(array_filter($aud, fn ($a) => (int) $a['entity_id'] === $newA))[0] ?? null;
    $check($oldAud && (json_decode($oldAud['new_values'], true)['replaced_by'] ?? null) === $newA && str_contains((string) $oldAud['notes'], 'smoke'),
        'audit: the old card points at its replacement (+ note)');
    $check($newAud && (json_decode($newAud['new_values'], true)['replaces_card_id'] ?? null) === $cardA && !empty(json_decode($newAud['new_values'], true)['items']),
        'audit: the new card points back and carries its line snapshot');
    $again = RateCardRevision::revise([$cardA], RateCardRevision::options(['effective_from' => '2031-03-01', 'effective_to' => 'open']), true, null, 'smoke', '127.0.0.1');
    $check(!$again['ok'], 'changing the same card again for the same date is refused (the new card already covers it)');
    // Explicit lines + explicit name.
    $expl = RateCardRevision::revise([$newA], RateCardRevision::options([
        'effective_from' => '2032-01-01', 'effective_to' => '2032-12-31', 'names' => [$newA => 'ZZ RT explicit ' . $cat],
        'items_by_card' => [$newA => [['equipment_type' => $cat, 'daily_rate' => '70', 'weekly_rate' => '400', 'monthly_rate' => '900']]],
    ]), true, null, 'smoke', '127.0.0.1');
    $check($expl['ok'] && $expl['results'][0]['new_name'] === 'ZZ RT explicit ' . $cat && $expl['results'][0]['new_to'] === '2032-12-31'
        && $expl['results'][0]['lines'][0]['after']['daily_rate'] === '70', 'explicit lines, name and end date');
    $check(RateCardRevision::suggestName('ZZ RT Customer A ' . $cat . ' · from Mar 2031', '2031-03-05') === 'ZZ RT Customer A ' . $cat . ' · from Mar 2031 (2)',
        'suggestName strips the old suffix and adds a counter when taken');

    // ── R6 ───────────────────────────────────────────────────────────
    $section('R6 insights');
    // A lease for customer A on a ZZ unit, at prices that differ from the card in force today.
    $unit = db_insert('equipment_units', ['template_id' => $tDef, 'unit_number' => 'ZZRT' . bin2hex(random_bytes(3)), 'ownership_type' => 'owned']);
    $lease = db_insert('leases', ['contract_number' => 'ZZRT-' . bin2hex(random_bytes(3)), 'customer_id' => $custA, 'equipment_unit_id' => $unit,
                                  'start_date' => '2026-01-01', 'status' => 'active', 'daily_rate' => '45.00', 'weekly_rate' => '300.00',
                                  'monthly_rate' => '600.00', 'mileage_unit' => 'km', 'mileage_rate' => '0.0400', 'gps_cost' => '1.00']);
    RateInsights::reset();
    $lo = RateInsights::leasesOnCard($cardA, $custA, $today);
    $check($lo['total'] === 1 && $lo['differ'] === 1 && $lo['leases'][0]['id'] === $lease
        && array_column($lo['leases'][0]['diffs'], 'field') === ['daily_rate'], 'leasesOnCard: the lease resolves to the card; only the daily price differs');
    $check(RateInsights::leasesOnCard($gen, null, $today)['total'] === 0, 'the general card does not claim a lease its customer card prices');

    $cp = RateInsights::customerPricing(['q' => 'ZZ Rates Smoke A'], 1, 20, $today);
    $row = $cp['rows'][0] ?? null;
    $check($row && $row['customer_id'] === $custA && $row['active_leases'] === 1 && count($row['cards']) === 2, 'customerPricing: row with both cards + 1 lease');
    $lineA = array_values(array_filter($row['lines'] ?? [], fn ($l) => $l['card_id'] === $cardA))[0] ?? null;
    $check($lineA && $lineA['card_status'] === 'active' && $lineA['vs']['daily'] === '-16.7' && $lineA['standard']['range'] === null,
        'a whole-category line whose types share one standard (the general card) shows the %');
    // Two types in a category with DIFFERENT standards (no general card) → a range.
    $rs = $cat . 'r';
    db_insert('equipment_templates', ['name' => 'ZZ RT R1', 'slug' => $rs . '-1', 'category' => $rs, 'default_daily_rate' => '70.00', 'default_weekly_rate' => '400.00', 'default_monthly_rate' => '900.00', 'default_mileage_rate' => '0.0000']);
    db_insert('equipment_templates', ['name' => 'ZZ RT R2', 'slug' => $rs . '-2', 'category' => $rs, 'default_daily_rate' => '90.00', 'default_weekly_rate' => '500.00', 'default_monthly_rate' => '1100.00', 'default_mileage_rate' => '0.0000']);
    RateInsights::reset();
    $ls = RateInsights::lineStandard(['equipment_type' => $rs, 'equipment_template_id' => null], $today);
    $check($ls['daily'] === null && $ls['range']['daily'] === ['70.00', '90.00'] && $ls['range']['monthly'] === ['900.00', '1100.00'],
        'a whole-category line over types with different standards carries the range, not a single figure');
    $check(RateInsights::customerPricing(['q' => 'ZZ Rates Smoke A', 'equipment' => 't:' . $tDef], 1, 20, $today)['total'] === 1
        && RateInsights::customerPricing(['q' => 'ZZ Rates Smoke A', 'equipment' => 'c:nope_' . $cat], 1, 20, $today)['total'] === 0,
        'equipment filter (type matches its category line; unknown category matches nothing)');
    $check(RateInsights::customerPricing(['q' => 'ZZ Rates Smoke A', 'status' => 'upcoming'], 1, 20, $today)['total'] === 1
        && RateInsights::customerPricing(['q' => 'ZZ Rates Smoke A', 'status' => 'expired'], 1, 20, $today)['total'] === 0, 'status filter');

    $pb = RateInsights::priceBook($today);
    $zz = null;
    foreach ($pb['groups'] as $g) { foreach ($g['types'] as $t) { if ($t['id'] === $tDef) { $zz = $t; } } }
    $check($zz && $zz['standard']['rate_card_id'] === $gen && $zz['deals']['customers'] === 1 && $zz['on_rent'] === 1 && $zz['units'] === 1,
        'priceBook: standard from the general card, 1 customer deal, 1 of 1 unit on rent');

    $mine = RateInsights::customerPrices($custA, $today);
    $pDef = array_values(array_filter($mine['prices'], fn ($p) => $p['template_id'] === $tDef))[0] ?? null;
    $check($pDef && $pDef['price']['source'] === 'customer' && $pDef['vs']['daily'] === '-16.7' && $pDef['lease']['count'] === 1 && $pDef['lease']['daily_rate'] === '45.00',
        'customerPrices: their price, -16.7% vs the $60 standard, and the lease price');
    $check($mine['prices'][0]['price']['source'] === 'customer', 'their own prices sort first');

    $emptyCard = db_insert('rate_cards', ['name' => 'ZZ RT Empty ' . $cat, 'customer_id' => $custB, 'effective_from' => '2020-01-01']);
    $endingCard = db_insert('rate_cards', ['name' => 'ZZ RT Ending ' . $cat, 'customer_id' => $custB, 'effective_from' => '2020-01-01',
                                            'effective_to' => (new DateTimeImmutable(ff_today()))->modify('+5 days')->format('Y-m-d')]);
    db_insert('rate_card_items', ['rate_card_id' => $endingCard, 'equipment_type' => $cat . 'e', 'hourly_rate' => '5.0000']);
    $issueCard = db_insert('rate_cards', ['name' => 'ZZ RT Issue ' . $cat, 'effective_from' => '2020-01-01']);
    // S-RATES-MINIMUM-OVERLAY: a minimum-only line is valid now; only a line
    // with no prices AND no minimum (it does nothing) needs a look.
    db_insert('rate_card_items', ['rate_card_id' => $issueCard, 'equipment_type' => $cat . 'i']);
    db_insert('rate_card_items', ['rate_card_id' => $issueCard, 'equipment_type' => $cat . 'm', 'minimum_days' => 3]);
    $custC = db_insert('customers', ['company_name' => 'ZZ Rates Smoke C']);
    $unitC = db_insert('equipment_units', ['template_id' => $tTwo, 'unit_number' => 'ZZRTC' . bin2hex(random_bytes(3)), 'ownership_type' => 'owned']);
    db_insert('leases', ['contract_number' => 'ZZRTC-' . bin2hex(random_bytes(3)), 'customer_id' => $custC, 'equipment_unit_id' => $unitC,
                         'start_date' => '2026-01-01', 'status' => 'active']);
    $att = RateInsights::attention(ff_today());
    $kinds = [];
    foreach ($att['items'] as $a) { $kinds[$a['kind']][] = $a['card_id'] ?? $a['customer_id'] ?? null; }
    $check(in_array($emptyCard, $kinds['empty'] ?? [], true), 'attention: empty card');
    $check(in_array($endingCard, $kinds['ending'] ?? [], true), 'attention: ending within 30 days (tone danger at ≤ 7 days)');
    $check(in_array($issueCard, $kinds['line_issue'] ?? [], true), 'attention: a line with no prices and no minimum');
    $issueTitles = array_column(array_filter($att['items'], fn ($a) => $a['kind'] === 'line_issue' && ($a['card_id'] ?? null) === $issueCard), 'title');
    $check(count($issueTitles) === 1, 'attention: the minimum-only line on the same card is NOT flagged');
    $check(in_array($custC, $kinds['no_card'] ?? [], true), 'attention: customer on rent with no card');
    $check($att['items'][0]['kind'] === 'line_issue', 'most urgent (a line that would pre-fill $0) sorts first');
    $k = RateInsights::kpis(ff_today());
    $check($k['customers_with_prices'] >= 2 && $k['ending_soon'] >= 1 && $k['types_active'] >= 3, 'kpis');

    $h = RateInsights::history($newA, $today);
    $tl = $h['timeline'][0] ?? null;
    $check($tl && count($tl['periods']) === 2 && $tl['periods'][0]['card_id'] === $cardA && $tl['periods'][1]['is_this'],
        'history timeline: the line over time across the old and the new card');
    $check(($h['events'][0]['replaces'] ?? null) === $cardA && $h['events'][0]['lines'] !== [], 'history events: created from the old card, with its lines');
    // An update with lines writes before/after snapshots → a per-field diff.
    db_insert('audit_log', ['user_id' => null, 'user_name' => 'smoke', 'action' => 'update', 'module' => 'rates', 'entity_type' => 'rate_card',
        'entity_id' => $newA, 'entity_label' => 'x', 'ip_address' => '127.0.0.1',
        'old_values' => json_encode(['items' => RateCardItems::snapshot([['equipment_type' => $cat, 'daily_rate' => '55', 'weekly_rate' => '330', 'monthly_rate' => '660']])]),
        'new_values' => json_encode(['items' => RateCardItems::snapshot([['equipment_type' => $cat, 'daily_rate' => '57', 'weekly_rate' => '330', 'monthly_rate' => '660']])])]);
    $h2 = RateInsights::history($newA, $today);
    $f = $h2['events'][0]['lines'][0]['fields'][0] ?? null;
    $check($f && $f['field'] === 'daily_rate' && $f['from'] === '55.00' && $f['to'] === '57.00', 'history: an edit shows "daily 55.00 → 57.00"');
} finally {
    $pdo->rollBack();
    RateInsights::reset();
}

// ── R7 ───────────────────────────────────────────────────────────────
$section('R7 static wiring');
$src = static fn (string $f) => (string) file_get_contents(FF_ROOT . '/' . $f);
$gates = [
    'api/v1/rate_cards/overview.php'         => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/customer_pricing.php' => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/price_book.php'       => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/price_check.php'      => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/history.php'          => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/customer_prices.php'  => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/sheet_pdf.php'        => ["require_permission('rates', 'view')"],
    'api/v1/rate_cards/leases.php'           => ["require_permission('rates', 'view')", "require_permission('leases', 'view')"],
    'api/v1/rate_cards/revise.php'           => ["require_permission('rates', 'create')", "require_permission('rates', 'edit')", "require_method('POST')"],
    'api/v1/rate_cards/export.php'           => ["require_permission('rates', 'export')"],
];
foreach ($gates as $file => $needles) {
    $s = $src($file);
    $ok = str_contains($s, 'require_auth_api()');
    foreach ($needles as $n) { $ok = $ok && str_contains($s, $n); }
    $check($ok, "{$file}: authenticates + gates");
}
$check(str_contains($src('api/v1/leases/lookup_rates.php'), 'RateResolver::resolve(') && !str_contains($src('api/v1/leases/lookup_rates.php'), 'FROM rate_card_items'),
    'lookup_rates.php runs the shared resolver (no inline lookup SQL left)');
foreach (['api/v1/rate_cards/create.php', 'api/v1/rate_cards/update.php'] as $f) {
    $check(str_contains($src($f), 'RateCardItems::normalize(') && str_contains($src($f), 'RateCardItems::snapshot('), "{$f}: shared line rules + line snapshot in the audit row");
}
$check(!str_contains($src('api/v1/rate_cards/index.php'), 'ORDER BY rc.$sort'), 'index.php: sort goes through the allowlist map (no interpolated column)');
foreach (['app/admin/rates/index.php', 'app/admin/rates/show.php', 'app/admin/rates/create.php', 'app/admin/customers/show.php'] as $f) {
    $check(str_contains($src($f), 'assets/css/rates.css') && str_contains($src($f), 'assets/js/rates.js'), "{$f}: loads rates.css + rates.js");
}
$check(!preg_match('/#[0-9a-f]{3,6}\b/i', preg_replace('#/\*.*?\*/#s', '', $src('public/assets/css/rates.css'))), 'rates.css: tokens only, no hard-coded colours');

// ── R8 ───────────────────────────────────────────────────────────────
$section('R8 HTTP (read-only + dry runs)');
$minted = [];
$mint = static function (int $userId) use (&$minted): ?array {
    $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(FF_ROOT . '/scripts/walkthrough/mint_session.php') . ' ' . $userId . ' 2>/dev/null'));
    $sid = preg_match('/^[a-zA-Z0-9,-]{20,}$/', $out) ? $out : null;
    if ($sid === null) { return null; }
    $srcFile = rtrim((string) (ini_get('session.save_path') ?: sys_get_temp_dir()), '/') . '/sess_' . $sid;
    $dst = '/var/tmp/sess_' . $sid; // Herd php-fpm's session.save_path
    if (!is_file($srcFile) || !@copy($srcFile, $dst)) { return null; }
    $minted[] = $srcFile;
    $minted[] = $dst;
    preg_match('/csrf_token\|s:\d+:"([a-f0-9]+)"/', (string) file_get_contents($srcFile), $m);
    return ['sid' => $sid, 'csrf' => $m[1] ?? ''];
};
$http = static function (array $sess, string $path, ?array $post = null): array {
    $ch = curl_init(base_url($path));
    $h  = ['X-Requested-With: XMLHttpRequest', 'Cookie: ff_session=' . $sess['sid']];
    if ($post !== null) {
        $h[] = 'Content-Type: application/json';
        $h[] = 'X-CSRF-Token: ' . $sess['csrf'];
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$code, $body, $type];
};

$admin = $mint(54);
$disp  = $mint(56);
if ($admin === null || $disp === null) {
    $check(false, 'could not mint dev sessions (test users 54 / 56) — is this the dev database?');
} else {
    try {
        $card = db_row("SELECT id, customer_id FROM rate_cards WHERE deleted_at IS NULL AND customer_id IS NOT NULL ORDER BY id LIMIT 1");
        $tpl  = db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
        $gets = [
            'api/v1/rate_cards/overview'                                          => ['kpis', 'attention', 'equipment'],
            'api/v1/rate_cards/customer_pricing?per_page=5'                       => ['items', 'pagination'],
            'api/v1/rate_cards/price_book'                                        => ['groups', 'general_cards'],
            'api/v1/rate_cards/index?status=in_force&sort=status&per_page=10'    => ['items'],
            'api/v1/rate_cards/index?equipment=t:' . $tpl['id'] . '&scope=customer' => ['items'],
            'api/v1/rate_cards/history?id=' . $card['id']                         => ['events', 'timeline'],
            'api/v1/rate_cards/leases?id=' . $card['id']                          => ['leases', 'total', 'differ'],
            'api/v1/rate_cards/customer_prices?customer_id=' . $card['customer_id'] => ['prices', 'cards', 'customer'],
            'api/v1/rate_cards/price_check?template_id=' . $tpl['id'] . '&customer_id=' . $card['customer_id'] . '&start=2026-10-01&end=2026-10-12&gps=1'
                                                                                  => ['price', 'standard', 'tiers', 'quote'],
            'api/v1/leases/lookup_rates?customer_id=' . $card['customer_id'] . '&equipment_template_id=' . $tpl['id'] => ['source', 'rate_card_id', 'minimum_days'],
        ];
        foreach ($gets as $path => $keys) {
            [$code, $body] = $http($admin, $path);
            $j = json_decode($body, true);
            $data = $j['data'] ?? [];
            $missing = array_diff($keys, array_keys(is_array($data) ? $data : []));
            $check($code === 200 && ($j['success'] ?? false) === true && $missing === [], "GET {$path} → 200 with " . implode(', ', $keys)
                . ($code !== 200 || $missing ? " (got {$code}, missing " . implode(',', $missing) . ')' : ''));
        }
        [$code, $body, $type] = $http($admin, 'api/v1/rate_cards/sheet_pdf?customer_id=' . $card['customer_id']);
        $check($code === 200 && str_starts_with($body, '%PDF') && str_contains($type, 'pdf'), 'rate sheet PDF streams');
        [$code, $body, $type] = $http($admin, 'api/v1/rate_cards/export');
        $check($code === 200 && str_contains($type, 'text/csv') && str_contains($body, 'Card,"Card ID",For') && !str_contains($body, 'Deprecated'), 'CSV export (clean header, no PHP notices)');
        db_execute("DELETE FROM audit_log WHERE action = 'export' AND module = 'rates' AND entity_label = 'Rate cards CSV' AND created_at >= NOW() - INTERVAL 2 MINUTE AND user_id = 54");

        $cardsBefore = db_row("SELECT COUNT(*) n FROM rate_cards")['n'];
        [$code, $body] = $http($admin, 'api/v1/rate_cards/create', ['name' => 'ZZ RT dry ' . bin2hex(random_bytes(3)), 'effective_from' => '2031-01-01',
            'customer_id' => (int) $card['customer_id'], 'items' => [['equipment_type' => 'zz_nope', 'daily_rate' => '10']], 'dry_run' => 1]);
        $check($code === 422 && str_contains($body, 'daily, weekly and monthly'), 'create dry_run: D132 refusal over HTTP');
        [$code, $body] = $http($admin, 'api/v1/rate_cards/create', ['name' => 'ZZ RT dry ' . bin2hex(random_bytes(3)), 'effective_from' => '2031-01-01',
            'items' => [['equipment_type' => 'zz_nope', 'daily_rate' => '10', 'weekly_rate' => '60', 'monthly_rate' => '200']], 'dry_run' => 1]);
        $check($code === 200 && (json_decode($body, true)['data']['ok'] ?? false) === true, 'create dry_run: a valid general card passes');
        [$code, $body] = $http($admin, 'api/v1/rate_cards/revise', ['card_ids' => [(int) $card['id']], 'effective_from' => '2031-01-01', 'percent' => '3', 'dry_run' => 1]);
        $j = json_decode($body, true);
        $check($code === 200 && ($j['data']['applied'] ?? true) === false && array_key_exists('results', $j['data'] ?? []), 'revise dry_run answers without applying');
        $check(db_row("SELECT COUNT(*) n FROM rate_cards")['n'] === $cardsBefore, 'no rate card was created by the dry runs');

        [$code] = $http($disp, 'api/v1/rate_cards/overview');
        $check($code === 403, 'a dispatcher (rates: none) is refused (403)');
        [$code] = $http($disp, 'api/v1/rate_cards/revise', ['card_ids' => [(int) $card['id']], 'effective_from' => '2031-01-01', 'dry_run' => 1]);
        $check($code === 403, 'and cannot change prices');
    } finally {
        foreach ($minted as $f) { @unlink($f); }
    }
}

echo "\n" . ($failures ? 'FAILED' : 'ALL PASS') . " — {$passes} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "  ✗ {$f}\n"; }
exit($failures ? 1 : 0);
