<?php declare(strict_types=1);

/**
 * tests/_smoke_lookup_rates.php
 *
 * S-RATES-CONSOLIDATE — end-to-end smoke for api/v1/leases/lookup_rates.php.
 *
 * Verifies the post-consolidation rate-lookup priority chain (overrides
 * retired). Self-seeding: the parent inserts its own temp customers, rate
 * cards, items and templates (SMOKE-tagged), runs the real endpoint in a
 * child process for each tier, asserts the result, and deletes everything
 * in a finally — so it needs no external seed dataset and is verifiable on
 * any DB.
 *
 *   T1 — customer-specific rate card wins (source='customer').
 *   T2 — falls through to a global rate card (source='rate_card').
 *   T3 — falls through to equipment_templates defaults (source='template').
 *   T4 — nothing matches (source='none').
 *   T5 — customer card is preferred over a global card for the SAME type.
 *
 * The endpoint exits via json_response(), so each lookup runs in a forked
 * child (pattern borrowed from the prior version of this smoke).
 *
 * Usage:  php tests/_smoke_lookup_rates.php
 * Exit:   0 all pass, 1 on failure.
 *
 * @session S-RATES-CONSOLIDATE
 */

// ── CHILD MODE: run the endpoint with a super_admin session ─────────────
if (isset($argv[1], $argv[2]) && ctype_digit($argv[1]) && ctype_digit($argv[2])) {
    $custId = (int) $argv[1];
    $tmplId = (int) $argv[2];

    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_GET = ['customer_id' => $custId, 'equipment_template_id' => $tmplId];

    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id' => 1, 'name' => 'Smoke Admin', 'email' => 'smoke@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();

    require FF_ROOT . '/api/v1/leases/lookup_rates.php';
    exit(0); // unreached — endpoint exits via json_response
}

// ── PARENT MODE ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

echo "FleetForge — lookup_rates.php smoke (S-RATES-CONSOLIDATE)\n";
echo str_repeat('=', 70), "\n";

$self = __FILE__;
$php  = PHP_BINARY;
$TAG  = '__SMOKE_LOOKUP_RATES__';

// S-EQTAX: use SYNTHETIC equipment-type slugs (not the real 'dry_van'/'chassis')
// so this test's global-card-path assertions (T2/T7) can't be outranked by a
// seeded is_default rate card of the same real type on a shared dev DB. The
// lookup matches template.category against rate_card_items.equipment_type, so as
// long as the fixture template + its cards share the slug, every tier still
// resolves — and no production/seed card competes. (`category` is VARCHAR post
// S-EQTAX-1, so a synthetic slug is valid.)
$VAN = '__lr_van__';
$CHS = '__lr_chs__';

// IDs to clean up regardless of outcome.
$custWith = $custWithout = 0;
$cardCust = $cardGlobal = 0;
$tmplDryVan = $tmplDefaults = $tmplNull = 0;
$tmplChassis = 0;                       // S-LEASE-MIN-DAYS
$custOverride = $cardOverride = 0;      // S-LEASE-MIN-DAYS

$pass = 0; $fail = 0;
$report = function (string $name, bool $ok, string $detail) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  PASS  {$name}  {$detail}\n"; }
    else     { $fail++; echo "  FAIL  {$name}  {$detail}\n"; }
};

$run = function (int $custId, int $tmplId) use ($php, $self): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' '
         . (int)$custId . ' ' . (int)$tmplId . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"success"') : false;
    if ($start !== false) $out = substr($out, $start);
    $json = json_decode(trim((string)$out), true);
    return is_array($json) ? $json : ['_parse_error' => true, '_raw' => trim((string)$out)];
};

try {
    $today = date('Y-m-d');
    $from  = date('Y-m-d', strtotime('-30 days'));

    // ── Seed: two customers (one with a custom card, one without) ──────────
    $custWith    = db_insert('customers', ['company_name' => "SMOKE With Card {$TAG}"]);
    $custWithout = db_insert('customers', ['company_name' => "SMOKE No Card {$TAG}"]);

    // Templates: a dry_van (no defaults → forces card tiers) + two 'other'.
    $tmplDryVan = db_insert('equipment_templates', [
        'name' => "SMOKE DryVan {$TAG}", 'slug' => '__smoke_lr_dryvan__', 'category' => $VAN,
        'is_active' => 1, 'sort_order' => 999,
    ]);
    $tmplDefaults = db_insert('equipment_templates', [
        'name' => "SMOKE Defaults {$TAG}", 'slug' => '__smoke_lr_def__', 'category' => 'other',
        'default_daily_rate' => '99.00', 'default_weekly_rate' => '550.00', 'default_monthly_rate' => '1900.00',
        'default_mileage_rate' => '0.1100', 'default_currency' => 'CAD', 'default_mileage_unit' => 'km',
        'is_active' => 1, 'sort_order' => 999,
    ]);
    $tmplNull = db_insert('equipment_templates', [
        'name' => "SMOKE Null {$TAG}", 'slug' => '__smoke_lr_null__', 'category' => 'other',
        'default_currency' => 'CAD', 'default_mileage_unit' => 'km', 'is_active' => 1, 'sort_order' => 999,
    ]);

    // Global card with a dry_van item (mileage 0.0500).
    $cardGlobal = db_insert('rate_cards', [
        'name' => "SMOKE Global {$TAG}", 'customer_id' => null, 'is_default' => 0,
        'effective_from' => $from, 'effective_to' => null, 'created_by' => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id' => $cardGlobal, 'equipment_type' => $VAN,
        'daily_rate' => '120.00', 'mileage_rate' => '0.0500', 'mileage_unit' => 'km', 'currency' => 'CAD',
    ]);

    // Customer-specific card for $custWith, dry_van item (mileage 0.1800).
    $cardCust = db_insert('rate_cards', [
        'name' => "SMOKE Custom {$TAG}", 'customer_id' => $custWith, 'is_default' => 0,
        'effective_from' => $from, 'effective_to' => null, 'created_by' => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id' => $cardCust, 'equipment_type' => $VAN,
        'daily_rate' => '150.00', 'mileage_rate' => '0.1800', 'mileage_unit' => 'km', 'currency' => 'CAD',
    ]);

    // ── S-LEASE-MIN-DAYS: chassis items proving the minimum_days fallback ───
    //   Operator's question: "do I set Min days under each customer card?"
    //   These three items prove: a BLANK customer card returns minimum_days=null
    //   (so the lease form falls back to the global Settings default — you do
    //   NOT set it per card), a global card CAN carry a value, and a per-card
    //   value is only needed to OVERRIDE the default.
    $tmplChassis = db_insert('equipment_templates', [
        'name' => "SMOKE Chassis {$TAG}", 'slug' => '__smoke_lr_chassis__', 'category' => $CHS,
        'is_active' => 1, 'sort_order' => 999,
    ]);
    // Global chassis item carries an explicit, distinctive minimum_days=7.
    db_insert('rate_card_items', [
        'rate_card_id' => $cardGlobal, 'equipment_type' => $CHS,
        'daily_rate' => '90.00', 'currency' => 'CAD', 'minimum_days' => 7,
    ]);
    // Customer chassis item is BLANK (minimum_days omitted → NULL): the
    // "I didn't set Min days on my customer's card" case.
    db_insert('rate_card_items', [
        'rate_card_id' => $cardCust, 'equipment_type' => $CHS,
        'daily_rate' => '100.00', 'currency' => 'CAD',
    ]);
    // A third customer whose own chassis card EXPLICITLY overrides to 5 days.
    $custOverride = db_insert('customers', ['company_name' => "SMOKE Override {$TAG}"]);
    $cardOverride = db_insert('rate_cards', [
        'name' => "SMOKE Override Card {$TAG}", 'customer_id' => $custOverride, 'is_default' => 0,
        'effective_from' => $from, 'effective_to' => null, 'created_by' => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id' => $cardOverride, 'equipment_type' => $CHS,
        'daily_rate' => '100.00', 'currency' => 'CAD', 'minimum_days' => 5,
    ]);

    // ── T1 — customer card wins ────────────────────────────────────────────
    $r = $run($custWith, $tmplDryVan);
    $report('T1 customer-specific card',
        ($r['data']['source'] ?? null) === 'customer' && (string)($r['data']['mileage_rate'] ?? '') === '0.1800',
        sprintf('source=%s mileage=%s', $r['data']['source'] ?? '?', $r['data']['mileage_rate'] ?? '?'));
    // S-RATE-CARD-LABEL-EQTYPE: the banner names the matched EQUIPMENT TYPE, then
    // the card it came from — so a correct rate from a card named after a different
    // product (e.g. a Combo rate inside a "53' T/A Dry HWY" card) isn't mistaken
    // for that product's rate. The label prefix is the selected template name.
    $report('T1b label = "<type> rate · custom card <name>"',
        (string)($r['data']['source_label'] ?? '') === "SMOKE DryVan {$TAG} rate · custom card \"SMOKE Custom {$TAG}\"",
        'label=' . ($r['data']['source_label'] ?? '?'));

    // ── T2 — global card (customer has no custom card) ─────────────────────
    $r = $run($custWithout, $tmplDryVan);
    $report('T2 global card fallback',
        ($r['data']['source'] ?? null) === 'rate_card' && (string)($r['data']['mileage_rate'] ?? '') === '0.0500',
        sprintf('source=%s mileage=%s', $r['data']['source'] ?? '?', $r['data']['mileage_rate'] ?? '?'));
    $report('T2b label = "<type> rate · card <name>"',
        (string)($r['data']['source_label'] ?? '') === "SMOKE DryVan {$TAG} rate · card \"SMOKE Global {$TAG}\"",
        'label=' . ($r['data']['source_label'] ?? '?'));

    // ── T3 — template defaults (no card for category 'other') ──────────────
    $r = $run($custWithout, $tmplDefaults);
    $report('T3 template defaults',
        ($r['data']['source'] ?? null) === 'template' && (string)($r['data']['mileage_rate'] ?? '') === '0.1100',
        sprintf('source=%s mileage=%s', $r['data']['source'] ?? '?', $r['data']['mileage_rate'] ?? '?'));

    // ── T4 — nothing matches ───────────────────────────────────────────────
    $r = $run($custWithout, $tmplNull);
    $report('T4 none-found',
        ($r['data']['source'] ?? null) === 'none'
            && array_key_exists('mileage_rate', $r['data'] ?? [])
            && $r['data']['mileage_rate'] === null,
        sprintf('source=%s mileage=%s', $r['data']['source'] ?? '?',
            array_key_exists('mileage_rate', $r['data'] ?? []) ? ($r['data']['mileage_rate'] === null ? 'null' : $r['data']['mileage_rate']) : 'unset'));

    // ── T5 — customer card preferred over global for SAME type ─────────────
    //    $custWith sees 0.1800 (custom), not 0.0500 (global) — already implied
    //    by T1 vs T2, asserted explicitly here for the precedence guarantee.
    $r = $run($custWith, $tmplDryVan);
    $report('T5 customer card beats global',
        (string)($r['data']['mileage_rate'] ?? '') === '0.1800' && (string)($r['data']['daily_rate'] ?? '') === '150.00',
        sprintf('daily=%s mileage=%s', $r['data']['daily_rate'] ?? '?', $r['data']['mileage_rate'] ?? '?'));

    // ── S-LEASE-MIN-DAYS minimum_days fallback ─────────────────────────────
    // T6 — BLANK customer chassis card → minimum_days null. The lease form then
    //      falls back to the global Settings default (lease.minimum_billing_days),
    //      so the operator does NOT have to set Min days under each customer card.
    $r = $run($custWith, $tmplChassis);
    $report('T6 blank customer card → minimum_days null (falls back to global default)',
        ($r['data']['source'] ?? null) === 'customer'
            && array_key_exists('minimum_days', $r['data'] ?? [])
            && $r['data']['minimum_days'] === null,
        sprintf('source=%s minimum_days=%s', $r['data']['source'] ?? '?',
            array_key_exists('minimum_days', $r['data'] ?? []) ? ($r['data']['minimum_days'] === null ? 'null' : $r['data']['minimum_days']) : 'unset'));

    // T7 — customer with NO card → global chassis card's minimum_days=7 flows through
    //      (a global card CAN carry a fleet-wide value).
    $r = $run($custWithout, $tmplChassis);
    $report('T7 global card carries minimum_days=7',
        ($r['data']['source'] ?? null) === 'rate_card' && (string)($r['data']['minimum_days'] ?? '') === '7',
        sprintf('source=%s minimum_days=%s', $r['data']['source'] ?? '?', $r['data']['minimum_days'] ?? '?'));

    // T8 — per-card EXPLICIT override → minimum_days=5 (the ONLY case you set it
    //      under a card: when that customer/equipment must differ from the default).
    $r = $run($custOverride, $tmplChassis);
    $report('T8 per-card explicit override → minimum_days=5',
        ($r['data']['source'] ?? null) === 'customer' && (string)($r['data']['minimum_days'] ?? '') === '5',
        sprintf('source=%s minimum_days=%s', $r['data']['source'] ?? '?', $r['data']['minimum_days'] ?? '?'));

} finally {
    // ── Cleanup (rate_card_items cascade via FK on rate_card delete) ───────
    foreach ([$cardCust, $cardGlobal, $cardOverride] as $id) if ($id) db_execute("DELETE FROM rate_cards WHERE id = ?", [$id]);
    foreach ([$tmplDryVan, $tmplDefaults, $tmplNull, $tmplChassis] as $id) if ($id) db_execute("DELETE FROM equipment_templates WHERE id = ?", [$id]);
    foreach ([$custWith, $custWithout, $custOverride] as $id) if ($id) db_execute("DELETE FROM customers WHERE id = ?", [$id]);
}

echo str_repeat('=', 70), "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
