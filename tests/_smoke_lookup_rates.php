<?php declare(strict_types=1);

/**
 * tests/_smoke_lookup_rates.php
 *
 * S-LOOKUP-RATES-NAMESPACE — smoke test for api/v1/leases/lookup_rates.php.
 * Verifies the rate-lookup priority chain end-to-end across all 4 sources:
 *
 *   T1, T2 — Priority 1 (customer_equipment_rates) for two known
 *            customer/template pairs that have cer rows.
 *   T3     — Priority 2 (rate_card_items) for a customer with zero cer rows
 *            (customer 9 SMOKE-S4 test co) — proves the chain falls through
 *            cleanly past Priority 1 and lands on Priority 2.
 *   T4     — Priority 3 (equipment_templates default rates) using a
 *            temporary template with category='other' + non-null defaults.
 *   T5     — Priority 4 (none) using a temporary template with category='other'
 *            + NULL defaults.
 *
 * The two temp templates with category='other' are inserted by the parent,
 * IDs are passed to the children, and templates are deleted in a finally
 * block regardless of test outcome (test-pollution discipline).
 *
 * Each test forks a child PHP process because the endpoint exits via
 * json_response(); pattern borrowed from tests/_smoke_payoff_api.php.
 *
 * Pre-S-LOOKUP-RATES-NAMESPACE this entire chain was broken: lookup queried
 * by template name ("53ft Dry Van") but data stored category ("dry_van"),
 * so Priority 1 + 2 always returned 0 rows. Post-fix, T1/T2/T3 prove the
 * fix landed; T4/T5 prove the fall-through paths are still wired.
 *
 * Usage:
 *   php tests/_smoke_lookup_rates.php           # parent — runs all tests
 *   php tests/_smoke_lookup_rates.php <cust> <tmpl>   # child — runs one query
 *
 * Spec: S-LOOKUP-RATES-NAMESPACE
 */

// ── CHILD MODE ──────────────────────────────────────────────
if (isset($argv[1], $argv[2]) && ctype_digit($argv[1]) && ctype_digit($argv[2])) {
    $custId = (int) $argv[1];
    $tmplId = (int) $argv[2];

    $_SERVER['HTTPS']           = 'on';
    $_SERVER['HTTP_HOST']       = 'fleetforge.test';
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = "/api/v1/leases/lookup_rates.php?customer_id={$custId}&equipment_template_id={$tmplId}";
    $_SERVER['SCRIPT_NAME']     = '/api/v1/leases/lookup_rates.php';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'ff-smoke-cli';
    $_GET = ['customer_id' => $custId, 'equipment_template_id' => $tmplId];

    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id'          => 1,
        'name'        => 'Smoke Admin',
        'email'       => 'smoke@fleetforge.test',
        'role_id'     => 1,
        'role_slug'   => 'super_admin',
        'permissions' => [],
        'theme'       => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();

    require FF_ROOT . '/api/v1/leases/lookup_rates.php';
    exit(0); // unreached — endpoint exits via json_response
}

// ── PARENT MODE ─────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

echo "FleetForge — lookup_rates.php smoke test (S-LOOKUP-RATES-NAMESPACE)\n";
echo str_repeat('═', 78), "\n";

$self = __FILE__;
$php  = PHP_BINARY;

// Insert two temporary templates with category='other' for T4 + T5.
// Both deleted in finally regardless of outcome.
$tmplWithDefaults = db_insert('equipment_templates', [
    'name'                 => '__SMOKE_LOOKUP_RATES_TEMPLATE_HIT__',
    'slug'                 => '__smoke_lookup_rates_template_hit__',
    'category'             => 'other',
    'default_daily_rate'   => '99.00',
    'default_weekly_rate'  => '550.00',
    'default_monthly_rate' => '1900.00',
    'default_mileage_rate' => '0.1100',
    'default_currency'     => 'CAD',
    'default_mileage_unit' => 'km',
    'is_active'            => 1,
    'sort_order'           => 999,
]);
$tmplNullDefaults = db_insert('equipment_templates', [
    'name'             => '__SMOKE_LOOKUP_RATES_NONE_HIT__',
    'slug'             => '__smoke_lookup_rates_none_hit__',
    'category'         => 'other',
    'default_currency' => 'CAD',
    'default_mileage_unit' => 'km',
    'is_active'        => 1,
    'sort_order'       => 999,
]);

$cleanup = function () use ($tmplWithDefaults, $tmplNullDefaults) {
    db_execute("DELETE FROM equipment_templates WHERE id IN (?, ?)", [$tmplWithDefaults, $tmplNullDefaults]);
};

$run = function (int $custId, int $tmplId) use ($php, $self): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' '
         . (int)$custId . ' ' . (int)$tmplId . ' 2>&1';
    $out = shell_exec($cmd);
    $json = json_decode($out, true);
    if (!is_array($json)) {
        return ['_raw' => trim((string)$out), '_parse_error' => true];
    }
    return $json;
};

$pass = 0;
$fail = 0;
$report = function (string $name, bool $ok, string $detail) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  PASS  {$name}  {$detail}\n"; }
    else     { $fail++; echo "  FAIL  {$name}  {$detail}\n"; }
};

try {
    // ── T1 — Priority 1 customer hit (LP Logistics + 53ft Dry Van) ──
    $r = $run(1, 1);
    $report(
        'T1 customer hit (cust=1 LP Logistics + tmpl=1 53ft Dry Van)',
        ($r['data']['source'] ?? null) === 'customer'
            && (string)($r['data']['mileage_rate'] ?? '') === '0.1800',
        sprintf('source=%s mileage_rate=%s',
            (string)($r['data']['source'] ?? '?'),
            (string)($r['data']['mileage_rate'] ?? '?')
        )
    );

    // ── T2 — Priority 1 customer hit (Avi Trucking + 53ft Chassis) ──
    $r = $run(4, 5);
    $report(
        'T2 customer hit (cust=4 Avi Trucking + tmpl=5 53ft Chassis)',
        ($r['data']['source'] ?? null) === 'customer'
            && (string)($r['data']['mileage_rate'] ?? '') === '0.1300',
        sprintf('source=%s mileage_rate=%s',
            (string)($r['data']['source'] ?? '?'),
            (string)($r['data']['mileage_rate'] ?? '?')
        )
    );

    // ── T3 — Priority 2 rate_card hit (cust w/ no cer rows) ──
    // Customer 9 SMOKE-S4 test co has zero cer rows; falls through Priority 1.
    // Template 1 (dry_van) has rate_card_items rows → Priority 2 fires.
    $r = $run(9, 1);
    $report(
        'T3 rate_card hit (cust=9 SMOKE Test Co [no cer] + tmpl=1 dry_van)',
        ($r['data']['source'] ?? null) === 'rate_card',
        sprintf('source=%s source_label=%s',
            (string)($r['data']['source'] ?? '?'),
            (string)($r['data']['source_label'] ?? '?')
        )
    );

    // ── T4 — Priority 3 template hit (cust w/ no cer + tmpl w/ no card match) ──
    // Customer 9 + temp 'other' template with non-null defaults.
    // No cer match (cust 9 has zero rows). No rci match ('other' has zero rci).
    // Template defaults exist → Priority 3 fires.
    $r = $run(9, $tmplWithDefaults);
    $report(
        "T4 template hit (cust=9 + tmpl_id={$tmplWithDefaults} other-with-defaults)",
        ($r['data']['source'] ?? null) === 'template'
            && (string)($r['data']['mileage_rate'] ?? '') === '0.1100',
        sprintf('source=%s mileage_rate=%s',
            (string)($r['data']['source'] ?? '?'),
            (string)($r['data']['mileage_rate'] ?? '?')
        )
    );

    // ── T5 — Priority 4 none-found ──
    // Customer 9 + temp 'other' template with NULL defaults. Nothing matches.
    $r = $run(9, $tmplNullDefaults);
    $report(
        "T5 none-found (cust=9 + tmpl_id={$tmplNullDefaults} other-null-defaults)",
        ($r['data']['source'] ?? null) === 'none'
            && array_key_exists('mileage_rate', $r['data'] ?? [])
            && $r['data']['mileage_rate'] === null,
        sprintf('source=%s mileage_rate=%s',
            (string)($r['data']['source'] ?? '?'),
            !array_key_exists('mileage_rate', $r['data'] ?? []) ? 'unset'
              : ($r['data']['mileage_rate'] === null ? 'null' : (string)$r['data']['mileage_rate'])
        )
    );

    // ── Control — pre-fix query shape returns ZERO rows on live data ──
    // Sanity check that the bug WAS real and the fix flipped it. Pre-fix
    // queried equipment_type IN (template names); post-fix queries
    // equipment_type IN (categories).
    $preBugCount = db_count(
        "SELECT COUNT(*) FROM customer_equipment_rates
         WHERE equipment_type IN ('53ft Dry Van','53ft Chassis','48ft Reefer','40ft Flatbed','20ft Container')"
    );
    $postFixCount = db_count(
        "SELECT COUNT(*) FROM customer_equipment_rates
         WHERE equipment_type IN ('dry_van','chassis','reefer','flatbed','container')"
    );
    $report(
        'CONTROL pre-fix query shape (template names) returns 0 on live data',
        $preBugCount === 0 && $postFixCount > 0,
        "pre_form={$preBugCount}  post_form={$postFixCount}"
    );

} finally {
    $cleanup();
}

echo "\n";
echo str_repeat('═', 78), "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
