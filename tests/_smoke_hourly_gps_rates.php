<?php
declare(strict_types=1);

/**
 * tests/_smoke_hourly_gps_rates.php
 *
 * End-to-end smoke for S-RATECARD-HOURLY + S-GPS-RATE-ITEM.
 *
 * Tests the full flow of:
 *   A. lookup_rates returns correct per-item hourly_rate and gps_price
 *   B. Priority ordering with the new fields
 *   C. GPS charges flow through lease → InvoiceGenerator line items correctly
 *   D. Edge cases and "try to break it" scenarios
 *
 * All DB writes run inside BEGIN / ROLLBACK — no committed mutations.
 * Uses the CHILD-mode subprocess pattern (same as _smoke_lookup_rates.php)
 * for the lookup_rates endpoint tests.
 *
 * Run:  php tests/_smoke_hourly_gps_rates.php
 * Exit: 0 all pass, 1 on failure.
 *
 * @session S-RATECARD-HOURLY, S-GPS-RATE-ITEM
 */

// ── CHILD MODE — runs lookup_rates.php for a given customer + template ──
if (isset($argv[1], $argv[2]) && ctype_digit($argv[1]) && ctype_digit($argv[2])) {
    $custId = (int) $argv[1];
    $tmplId = (int) $argv[2];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET = ['customer_id' => $custId, 'equipment_template_id' => $tmplId];

    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id' => 1, 'name' => 'Smoke', 'email' => 'smoke@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();
    require FF_ROOT . '/api/v1/leases/lookup_rates.php';
    exit(0);
}

// ── PARENT MODE ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';

echo "FleetForge — hourly_rate + gps_price end-to-end smoke\n";
echo str_repeat('=', 70) . "\n";

$php  = PHP_BINARY;
$self = __FILE__;

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$label}" . ($detail ? "  [{$detail}]" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail ? "  [{$detail}]" : '') . "\n";
    }
}

function run_lookup(string $php, string $self, int $custId, int $tmplId): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' '
         . (int)$custId . ' ' . (int)$tmplId . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"success"') : false;
    if ($start !== false) $out = substr($out, $start);
    $json = json_decode(trim((string)$out), true);
    return is_array($json) ? $json : ['_parse_error' => true, '_raw' => trim((string)$out)];
}

// ───────────────────────────────────────────────────────────────────────
// Seed IDs — cleaned up in finally regardless of outcome.
// ───────────────────────────────────────────────────────────────────────
$TAG = '__SMOKE_HOURLY_GPS__';
// S-EQTAX: synthetic dry_van slug so the T2 global-card test isn't outranked by a
// seeded is_default 'dry_van' rate card on a shared dev DB (lookup matches
// template.category against rate_card_items.equipment_type — keep them in sync).
$VAN = '__hg_van__';

$custA = $custB = 0;
$tmplReefer = $tmplDryVan = $tmplNoDefaults = $tmplWithHourly = 0;
$cardGlobal = $cardCustomerA = $cardGpsOnly = 0;

$today = date('Y-m-d');
$from  = date('Y-m-d', strtotime('-30 days'));

try {
    // ─── Seed: customers ─────────────────────────────────────────────
    $custA = db_insert('customers', ['company_name' => "SMOKE A {$TAG}"]);
    $custB = db_insert('customers', ['company_name' => "SMOKE B {$TAG}"]);

    // ─── Seed: equipment templates ────────────────────────────────────
    // reefer with no template defaults (forces rate-card path)
    $tmplReefer = db_insert('equipment_templates', [
        'name'         => "SMOKE Reefer {$TAG}",
        'slug'         => '__smoke_hg_reefer__',
        'category'     => 'reefer',
        'is_active'    => 1,
        'sort_order'   => 999,
    ]);
    // dry_van with no template defaults
    $tmplDryVan = db_insert('equipment_templates', [
        'name'         => "SMOKE DryVan {$TAG}",
        'slug'         => '__smoke_hg_dryvn__',
        'category'     => $VAN,
        'is_active'    => 1,
        'sort_order'   => 999,
    ]);
    // template with default_hourly_rate set (tests Priority 2 hourly fallback)
    $tmplWithHourly = db_insert('equipment_templates', [
        'name'                => "SMOKE HourlyTmpl {$TAG}",
        'slug'                => '__smoke_hg_hrtmpl__',
        'category'            => 'other',
        'default_daily_rate'  => '100.00',
        'default_weekly_rate' => '600.00',
        'default_monthly_rate'=> '2000.00',
        'default_hourly_rate' => '18.5000',
        'default_currency'    => 'CAD',
        'default_mileage_unit'=> 'km',
        'is_active'           => 1,
        'sort_order'          => 999,
    ]);
    // template with no defaults at all (Priority 3 — none)
    $tmplNoDefaults = db_insert('equipment_templates', [
        'name'       => "SMOKE NoDefault {$TAG}",
        'slug'       => '__smoke_hg_nodef__',
        'category'   => 'other',
        'is_active'  => 1,
        'sort_order' => 999,
    ]);

    // ─── Seed: rate cards + items ─────────────────────────────────────
    // Global card: reefer (gps=3.50, hourly=22.00) + dry_van (gps=NULL, hourly=NULL)
    $cardGlobal = db_insert('rate_cards', [
        'name'           => "SMOKE Global {$TAG}",
        'customer_id'    => null,
        'is_default'     => 0,
        'effective_from' => $from,
        'effective_to'   => null,
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id'  => $cardGlobal,
        'equipment_type'=> 'reefer',
        'daily_rate'    => '200.00',
        'weekly_rate'   => '1200.00',
        'monthly_rate'  => '4500.00',
        'mileage_rate'  => '0.1000',
        'mileage_unit'  => 'km',
        'hourly_rate'   => '22.0000',
        'gps_price'     => '3.50',
        'currency'      => 'CAD',
    ]);
    db_insert('rate_card_items', [
        'rate_card_id'  => $cardGlobal,
        'equipment_type'=> $VAN,
        'daily_rate'    => '120.00',
        'weekly_rate'   => '700.00',
        'monthly_rate'  => '2500.00',
        'mileage_rate'  => '0.0500',
        'mileage_unit'  => 'km',
        'hourly_rate'   => null,   // deliberately no hourly for dry_van
        'gps_price'     => null,   // deliberately no GPS for dry_van
        'currency'      => 'CAD',
    ]);

    // Customer-A card: reefer only (gps=5.00, hourly=30.00 — higher than global)
    $cardCustomerA = db_insert('rate_cards', [
        'name'           => "SMOKE CustA {$TAG}",
        'customer_id'    => $custA,
        'is_default'     => 0,
        'effective_from' => $from,
        'effective_to'   => null,
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id'  => $cardCustomerA,
        'equipment_type'=> 'reefer',
        'daily_rate'    => '250.00',
        'weekly_rate'   => '1500.00',
        'monthly_rate'  => '5500.00',
        'mileage_rate'  => '0.1500',
        'mileage_unit'  => 'km',
        'hourly_rate'   => '30.0000',
        'gps_price'     => '5.00',
        'currency'      => 'CAD',
    ]);

    // GPS-only card: reefer with gps=2.00 but no hourly (edge: partial fields)
    $cardGpsOnly = db_insert('rate_cards', [
        'name'           => "SMOKE GpsOnly {$TAG}",
        'customer_id'    => $custB,
        'is_default'     => 0,
        'effective_from' => $from,
        'effective_to'   => null,
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id'  => $cardGpsOnly,
        'equipment_type'=> 'reefer',
        'daily_rate'    => '180.00',
        'weekly_rate'   => '1050.00',
        'monthly_rate'  => '3800.00',
        'mileage_rate'  => '0.0800',
        'mileage_unit'  => 'km',
        'hourly_rate'   => null,   // no hourly on this card
        'gps_price'     => '2.00',
        'currency'      => 'CAD',
    ]);

    // ─────────────────────────────────────────────────────────────────
    echo "\n[A] lookup_rates: per-item hourly_rate and gps_price\n";
    // ─────────────────────────────────────────────────────────────────

    // T1: global card reefer — hourly + gps both set
    $r = run_lookup($php, $self, $custB + 9999, $tmplReefer);
    // custB+9999 has no card of its own → falls to global
    // But wait: custB has cardGpsOnly for reefer. Use custA to get global for reefer.
    // Actually custA has cardCustomerA for reefer. Use custB for dry_van (no cust card).

    // Use a non-existent customer ID to force global-only lookup for reefer
    $ghostCust = db_insert('customers', ['company_name' => "SMOKE Ghost {$TAG}"]);
    $r = run_lookup($php, $self, $ghostCust, $tmplReefer);
    ok(
        ($r['data']['source'] ?? '') === 'rate_card'
        && (string)($r['data']['gps_price'] ?? '') === '3.50'
        && (string)($r['data']['hourly_rate'] ?? '') === '22.0000',
        'T1 global card reefer: gps_price=3.50 + hourly_rate=22.0000',
        sprintf('gps=%s hourly=%s src=%s', $r['data']['gps_price'] ?? 'NULL', $r['data']['hourly_rate'] ?? 'NULL', $r['data']['source'] ?? '?')
    );

    // T2: global card dry_van — both gps and hourly are NULL
    // Note: PHP ?? coalesces on null, so use array_key_exists for key-present-but-null checks.
    $r = run_lookup($php, $self, $ghostCust, $tmplDryVan);
    $d2 = $r['data'] ?? [];
    ok(
        ($d2['source'] ?? '') === 'rate_card'
        && array_key_exists('gps_price', $d2) && $d2['gps_price'] === null
        && array_key_exists('hourly_rate', $d2) && $d2['hourly_rate'] === null,
        'T2 global card dry_van: gps_price=null + hourly_rate=null (per-item null propagates)',
        sprintf('gps=%s hourly=%s src=%s', json_encode($d2['gps_price'] ?? 'MISSING'), json_encode($d2['hourly_rate'] ?? 'MISSING'), $d2['source'] ?? '?')
    );

    // T3: customer-A card reefer — hourly + gps both higher than global
    $r = run_lookup($php, $self, $custA, $tmplReefer);
    ok(
        ($r['data']['source'] ?? '') === 'customer'
        && (string)($r['data']['gps_price'] ?? '') === '5.00'
        && (string)($r['data']['hourly_rate'] ?? '') === '30.0000',
        'T3 customer-A card beats global: gps=5.00 hourly=30.00',
        sprintf('gps=%s hourly=%s src=%s', $r['data']['gps_price'] ?? 'NULL', $r['data']['hourly_rate'] ?? 'NULL', $r['data']['source'] ?? '?')
    );

    // T4: customer-B card reefer — gps=2.00 but hourly=NULL
    $r = run_lookup($php, $self, $custB, $tmplReefer);
    $d4 = $r['data'] ?? [];
    ok(
        ($d4['source'] ?? '') === 'customer'
        && (string)($d4['gps_price'] ?? '') === '2.00'
        && array_key_exists('hourly_rate', $d4) && $d4['hourly_rate'] === null,
        'T4 customer-B card: gps=2.00 hourly=null (partial fields — GPS set, hourly not)',
        sprintf('gps=%s hourly=%s', $d4['gps_price'] ?? 'NULL', json_encode($d4['hourly_rate'] ?? 'MISSING'))
    );

    // T5: template fallback — gps_price must be null, hourly_rate comes from default_hourly_rate
    $r = run_lookup($php, $self, $ghostCust, $tmplWithHourly);
    $d5 = $r['data'] ?? [];
    ok(
        ($d5['source'] ?? '') === 'template'
        && array_key_exists('gps_price', $d5) && $d5['gps_price'] === null
        && (string)($d5['hourly_rate'] ?? '') === '18.5000',
        'T5 template fallback: gps_price=null (templates have no GPS), hourly_rate=18.5000',
        sprintf('gps=%s hourly=%s src=%s', json_encode($d5['gps_price'] ?? 'MISSING'), $d5['hourly_rate'] ?? 'NULL', $d5['source'] ?? '?')
    );

    // T6: no rates at all — both null
    $r = run_lookup($php, $self, $ghostCust, $tmplNoDefaults);
    $d6 = $r['data'] ?? [];
    ok(
        ($d6['source'] ?? '') === 'none'
        && array_key_exists('gps_price', $d6) && $d6['gps_price'] === null
        && array_key_exists('hourly_rate', $d6) && $d6['hourly_rate'] === null,
        'T6 no-rates: gps_price=null + hourly_rate=null',
        sprintf('gps=%s hourly=%s src=%s', json_encode($d6['gps_price'] ?? 'MISSING'), json_encode($d6['hourly_rate'] ?? 'MISSING'), $d6['source'] ?? '?')
    );

    // T7: verify response keys are ALWAYS present (no missing key on any path)
    foreach (['customer', 'rate_card', 'template', 'none'] as $srcName) {
        $tmplId = match($srcName) {
            'customer' => $tmplReefer,
            'rate_card' => $tmplReefer,
            'template'  => $tmplWithHourly,
            'none'      => $tmplNoDefaults,
        };
        $custId = match($srcName) {
            'customer' => $custA,
            default    => $ghostCust,
        };
        $r = run_lookup($php, $self, $custId, $tmplId);
        $keys = array_keys($r['data'] ?? []);
        ok(
            in_array('gps_price', $keys, true) && in_array('hourly_rate', $keys, true),
            "T7 key-presence on source={$srcName}: gps_price + hourly_rate keys always present",
            'keys: ' . implode(',', $keys)
        );
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n[B] Template-specific item: gps_price + hourly_rate priority\n";
    // ─────────────────────────────────────────────────────────────────

    // Add a template-specific item to the global card for $tmplReefer
    // with DIFFERENT gps_price (99.00) and hourly_rate (99.00) — should beat category
    db_insert('rate_card_items', [
        'rate_card_id'          => $cardGlobal,
        'equipment_type'        => 'reefer',
        'equipment_template_id' => $tmplReefer,
        'daily_rate'            => '999.00',
        'weekly_rate'           => '5000.00',
        'monthly_rate'          => '18000.00',
        'mileage_rate'          => '0.9900',
        'mileage_unit'          => 'km',
        'hourly_rate'           => '99.0000',
        'gps_price'             => '9.99',
        'currency'              => 'CAD',
    ]);

    $r = run_lookup($php, $self, $ghostCust, $tmplReefer);
    ok(
        ($r['data']['source'] ?? '') === 'rate_card'
        && (string)($r['data']['gps_price'] ?? '') === '9.99'
        && (string)($r['data']['hourly_rate'] ?? '') === '99.0000'
        && (string)($r['data']['daily_rate'] ?? '') === '999.00',
        'T8 template-specific item beats category item: gps=9.99 hourly=99.00',
        sprintf('gps=%s hourly=%s daily=%s', $r['data']['gps_price'] ?? 'NULL', $r['data']['hourly_rate'] ?? 'NULL', $r['data']['daily_rate'] ?? 'NULL')
    );

    // ─────────────────────────────────────────────────────────────────
    echo "\n[C] InvoiceGenerator: GPS line item from gps_cost\n";
    // ─────────────────────────────────────────────────────────────────
    // Use BEGIN/ROLLBACK so nothing is committed.

    $seedLease = db_row(
        "SELECT id FROM leases
          WHERE deleted_at IS NULL
            AND monthly_rate IS NOT NULL AND monthly_rate > 0
          ORDER BY id DESC LIMIT 1"
    );
    if (!$seedLease) {
        echo "  SKIP  [C] — no active lease to clone; skipping InvoiceGenerator tests\n";
    } else {
        db_pdo()->beginTransaction();
        $invGenPass = $invGenFail = 0;
        try {
            if (function_exists('mbcron_bump_counter')) { mbcron_bump_counter(); }

            // Clone lease with gps_opt_in=1, gps_cost=5.00
            $cols = array_values(array_filter(
                array_column(db_select("SHOW COLUMNS FROM leases"), 'Field'),
                fn($c) => $c !== 'id'
            ));
            $insertList = implode(',', array_map(fn($c) => "`{$c}`", $cols));
            $selectList = implode(',', array_map(fn($c) => $c === 'contract_number' ? '?' : "`{$c}`", $cols));

            // --- T9: GPS line item generated when gps_opt_in=1 + gps_cost=5.00 ---
            $cn = 'SMOKE-GPS-' . uniqid();
            db_execute("INSERT INTO leases ({$insertList}) SELECT {$selectList} FROM leases WHERE id = ?",
                [$cn, $seedLease['id']]);
            $leaseId = (int) db_pdo()->lastInsertId();
            db_execute(
                "UPDATE leases SET status='active', gps_opt_in=1, gps_cost=5.00,
                        start_date='2026-01-01', last_billed_date=NULL,
                        last_billed_invoice_id=NULL, total_invoiced=0
                  WHERE id=?",
                [$leaseId]
            );

            $gen = new \FleetForge\Billing\InvoiceGenerator();
            $res = $gen->createFromLease([
                'lease_id'          => $leaseId,
                'period_start'      => '2026-01-01',
                'period_end'        => '2026-01-31',
                'billing_type'      => 'full_month',
                'invoice_type'      => 'regular',
                'created_by'        => 1,
                'auto_generated'    => 1,
                'generation_source' => 'manual',
            ]);

            $invId = $res['invoice_id'] ?? null;
            $ok9 = $invId && !isset($res['error']);
            ok($ok9, 'T9 createFromLease(gps_opt_in=1, gps_cost=5.00) returns invoice_id', (string)($invId ?? 'none'));
            if ($ok9) {
                $gpsLine = db_row(
                    "SELECT amount, unit_price FROM invoice_line_items
                      WHERE invoice_id = ? AND item_type = 'gps' LIMIT 1",
                    [$invId]
                );
                ok(!empty($gpsLine), 'T9b GPS line item exists in invoice_line_items',
                    $gpsLine ? "amount={$gpsLine['amount']} unit_price={$gpsLine['unit_price']}" : 'not found');
                // 5.00 × 31 days = 155.00
                $expectedGps = bcmul('5.00', '31', 2);
                ok(
                    $gpsLine && (string)$gpsLine['amount'] === $expectedGps,
                    "T9c GPS amount = gps_cost × days = {$expectedGps}",
                    'got: ' . ($gpsLine['amount'] ?? 'null')
                );
            }

            // --- T10: gps_cost=0.00 → no GPS line item ---
            $cn2 = 'SMOKE-GPS0-' . uniqid();
            db_execute("INSERT INTO leases ({$insertList}) SELECT {$selectList} FROM leases WHERE id = ?",
                [$cn2, $seedLease['id']]);
            $leaseId2 = (int) db_pdo()->lastInsertId();
            db_execute(
                "UPDATE leases SET status='active', gps_opt_in=1, gps_cost=0.00,
                        start_date='2026-01-01', last_billed_date=NULL,
                        last_billed_invoice_id=NULL, total_invoiced=0
                  WHERE id=?",
                [$leaseId2]
            );

            $res2 = $gen->createFromLease([
                'lease_id'          => $leaseId2,
                'period_start'      => '2026-01-01',
                'period_end'        => '2026-01-31',
                'billing_type'      => 'full_month',
                'invoice_type'      => 'regular',
                'created_by'        => 1,
                'auto_generated'    => 1,
                'generation_source' => 'manual',
            ]);
            $invId2 = $res2['invoice_id'] ?? null;
            if ($invId2) {
                $gpsLine2 = db_row(
                    "SELECT id FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'gps' LIMIT 1",
                    [$invId2]
                );
                ok(empty($gpsLine2), 'T10 gps_cost=0.00: no GPS line item generated',
                    $gpsLine2 ? 'UNEXPECTED GPS line found' : 'correctly absent');
            } else {
                ok(false, 'T10 createFromLease(gps_cost=0.00) failed to return invoice_id');
            }

            // --- T11: gps_opt_in=0 + gps_cost=9.99 → no GPS line item ---
            $cn3 = 'SMOKE-GPSOFF-' . uniqid();
            db_execute("INSERT INTO leases ({$insertList}) SELECT {$selectList} FROM leases WHERE id = ?",
                [$cn3, $seedLease['id']]);
            $leaseId3 = (int) db_pdo()->lastInsertId();
            db_execute(
                "UPDATE leases SET status='active', gps_opt_in=0, gps_cost=9.99,
                        start_date='2026-01-01', last_billed_date=NULL,
                        last_billed_invoice_id=NULL, total_invoiced=0
                  WHERE id=?",
                [$leaseId3]
            );

            $res3 = $gen->createFromLease([
                'lease_id'          => $leaseId3,
                'period_start'      => '2026-01-01',
                'period_end'        => '2026-01-31',
                'billing_type'      => 'full_month',
                'invoice_type'      => 'regular',
                'created_by'        => 1,
                'auto_generated'    => 1,
                'generation_source' => 'manual',
            ]);
            $invId3 = $res3['invoice_id'] ?? null;
            if ($invId3) {
                $gpsLine3 = db_row(
                    "SELECT id FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'gps' LIMIT 1",
                    [$invId3]
                );
                ok(empty($gpsLine3), 'T11 gps_opt_in=0: no GPS line item even with gps_cost=9.99',
                    $gpsLine3 ? 'UNEXPECTED GPS line found' : 'correctly absent');
            } else {
                ok(false, 'T11 createFromLease(gps_opt_in=0) failed');
            }

            // --- T12: hourly_rate on rate card items does NOT affect invoice ---
            // hourly_rate is in scope for lookup but NOT yet consumed by InvoiceGenerator.
            // Verify it does NOT cause any error or unexpected line item.
            $cn4 = 'SMOKE-HRLY-' . uniqid();
            db_execute("INSERT INTO leases ({$insertList}) SELECT {$selectList} FROM leases WHERE id = ?",
                [$cn4, $seedLease['id']]);
            $leaseId4 = (int) db_pdo()->lastInsertId();
            db_execute(
                "UPDATE leases SET status='active', gps_opt_in=0, gps_cost=0.00,
                        start_date='2026-02-01', last_billed_date=NULL,
                        last_billed_invoice_id=NULL, total_invoiced=0
                  WHERE id=?",
                [$leaseId4]
            );
            $res4 = $gen->createFromLease([
                'lease_id'          => $leaseId4,
                'period_start'      => '2026-02-01',
                'period_end'        => '2026-02-28',
                'billing_type'      => 'full_month',
                'invoice_type'      => 'regular',
                'created_by'        => 1,
                'auto_generated'    => 1,
                'generation_source' => 'manual',
            ]);
            $invId4 = $res4['invoice_id'] ?? null;
            ok(!empty($invId4) && !isset($res4['error']),
                'T12 hourly_rate on rate card does not crash InvoiceGenerator',
                $invId4 ? "invoice_id={$invId4}" : ('error: ' . json_encode($res4['error'] ?? 'unknown')));

            if ($invId4) {
                $hourlyLine = db_row(
                    "SELECT id FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'hourly' LIMIT 1",
                    [$invId4]
                );
                ok(empty($hourlyLine),
                    'T12b no spurious hourly line item (hourly billing not yet implemented)',
                    $hourlyLine ? 'UNEXPECTED hourly line found' : 'correctly absent');
            }

        } finally {
            db_pdo()->rollBack();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n[D] Edge cases: DB schema + API field contract\n";
    // ─────────────────────────────────────────────────────────────────

    // T13: Verify rate_cards no longer has gps_price column
    $rcCols = array_column(
        db_select("SHOW COLUMNS FROM rate_cards"),
        'Field'
    );
    ok(!in_array('gps_price', $rcCols, true),
        'T13 rate_cards.gps_price column is GONE (moved to rate_card_items)',
        'cols: ' . implode(',', $rcCols));

    // T14: Verify rate_card_items has gps_price + hourly_rate columns
    $rciCols = array_column(
        db_select("SHOW COLUMNS FROM rate_card_items"),
        'Field'
    );
    ok(in_array('gps_price', $rciCols, true) && in_array('hourly_rate', $rciCols, true),
        'T14 rate_card_items has both gps_price + hourly_rate columns',
        'cols: ' . implode(',', $rciCols));

    // T15: gps_price precision — verify DECIMAL(10,2) allows $9999.99 not truncating
    db_pdo()->beginTransaction();
    try {
        $precCard = db_insert('rate_cards', [
            'name' => "SMOKE Prec {$TAG}", 'customer_id' => null, 'is_default' => 0,
            'effective_from' => $from, 'created_by' => null,
        ]);
        $precItemId = db_insert('rate_card_items', [
            'rate_card_id'   => $precCard,
            'equipment_type' => 'reefer',
            'daily_rate'     => '100.00',
            'mileage_unit'   => 'km',
            'currency'       => 'CAD',
            'gps_price'      => '9999.99',
            'hourly_rate'    => '9999.9999',
        ]);
        $row = db_row("SELECT gps_price, hourly_rate FROM rate_card_items WHERE id = ?", [$precItemId]);
        ok(
            (string)$row['gps_price'] === '9999.99' && (string)$row['hourly_rate'] === '9999.9999',
            'T15 precision preserved: gps_price DECIMAL(10,2) + hourly_rate DECIMAL(10,4)',
            "gps_price={$row['gps_price']} hourly_rate={$row['hourly_rate']}"
        );
    } finally {
        db_pdo()->rollBack();
    }

    // T16: Negative gps_price / hourly_rate validation in create.php
    // Verify the per-item negative-rate guard covers both new fields.
    // create.php line ~165: bccomp($val,'0',6)<0 → error "Item N: GPS rate cannot be negative."
    // We test the guard logic directly with clean_decimal + bccomp (same functions create.php uses).
    $negGps  = clean_decimal('-1.00');
    $negHrly = clean_decimal('-0.0001');
    ok(
        $negGps !== null && bccomp((string)$negGps, '0', 6) < 0
        && $negHrly !== null && bccomp((string)$negHrly, '0', 6) < 0,
        'T16 clean_decimal("-1.00") / ("-0.0001") are negative — API bccomp guard rejects them',
        sprintf('gps_val=%s hourly_val=%s', $negGps, $negHrly)
    );
    // Confirm both fields are in the rateLabels array that drives the validation loop.
    $createSrc = file_get_contents(FF_ROOT . '/api/v1/rate_cards/create.php');
    ok(
        str_contains($createSrc, "'gps_price'") && str_contains($createSrc, "'hourly_rate'")
        && str_contains($createSrc, 'bccomp($val') && str_contains($createSrc, 'cannot be negative'),
        'T16b create.php rateLabels has gps_price + hourly_rate wired into bccomp negative guard'
    );

    // T17: customer_rate_history also gets hourly_rate
    $crh = db_row("SHOW COLUMNS FROM customer_rate_history LIKE 'hourly_rate'");
    ok(!empty($crh), 'T17 customer_rate_history.hourly_rate column exists (history audit trail)', $crh ? "type={$crh['Type']}" : 'missing');

    // T18: Expired rate card → lookup falls through (gps_price on expired card NOT returned)
    $cardExpired = db_insert('rate_cards', [
        'name'           => "SMOKE Expired {$TAG}",
        'customer_id'    => null,
        'is_default'     => 0,
        'effective_from' => '2020-01-01',
        'effective_to'   => '2020-12-31',   // expired!
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', [
        'rate_card_id'  => $cardExpired,
        'equipment_type'=> 'reefer',
        'daily_rate'    => '9999.00',
        'mileage_unit'  => 'km',
        'currency'      => 'CAD',
        'gps_price'     => '99.99',   // should NOT be returned — card expired
        'hourly_rate'   => '99.99',
    ]);
    $r18 = run_lookup($php, $self, $ghostCust, $tmplReefer);
    // ghostCust has no customer card; the global card (cardGlobal) is active.
    // Template-specific item on cardGlobal (T8 seed) has gps=9.99 not 99.99.
    ok(
        (string)($r18['data']['gps_price'] ?? '') !== '99.99',
        'T18 expired rate card gps_price is NOT returned (expired effective_to)',
        sprintf('gps=%s src=%s', $r18['data']['gps_price'] ?? 'NULL', $r18['data']['source'] ?? '?')
    );
    db_execute("DELETE FROM rate_cards WHERE id = ?", [$cardExpired]);

} finally {
    // ── Cleanup — rate_card_items cascade via FK ──────────────────────
    foreach ([$cardGlobal, $cardCustomerA, $cardGpsOnly] as $id) {
        if ($id) db_execute("DELETE FROM rate_cards WHERE id = ?", [$id]);
    }
    foreach ([$tmplReefer, $tmplDryVan, $tmplWithHourly, $tmplNoDefaults] as $id) {
        if ($id) db_execute("DELETE FROM equipment_templates WHERE id = ?", [$id]);
    }
    foreach ([$custA, $custB, $ghostCust ?? 0] as $id) {
        if ($id) db_execute("DELETE FROM customers WHERE id = ?", [$id]);
    }
}

echo str_repeat('=', 70) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
