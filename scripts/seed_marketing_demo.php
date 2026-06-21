<?php
declare(strict_types=1);

/**
 * FleetForge — Marketing Demo Seeder (S-FF-DEMO-SEED)
 *
 * @file        scripts/seed_marketing_demo.php
 * @description Populates the LOCAL FleetForge database with a realistic, fully
 *              ANONYMIZED dataset so every feature page is polished + populated
 *              for marketing screenshots. Unlike scripts/demo_seed.php (the
 *              live-presentation seeder that depends on a full demo_wipe.php
 *              truncate and uses real personal names), this seeder:
 *                - LAYERS on top of whatever is already in the local DB; it
 *                  NEVER truncates. The local DB already holds unrelated test
 *                  data (36 customers / 198 units / 141 leases at build time),
 *                  so a blanket wipe would destroy it.
 *                - Tags every row it creates with a purge marker so `--fresh`
 *                  can remove ONLY demo-seeded rows (tag-scoped, never blanket).
 *                - Refuses to run anywhere but a confirmed LOCAL fleetforge.test
 *                  environment (fail-closed multi-layer guard).
 *                - Generates invoices through the REAL InvoiceGenerator engine
 *                  (not hand-built rows) so GL/balances/tax are authentic.
 *                - Makes NO live Intuit/Samsara API calls. All external state is
 *                  faked locally.
 *
 * @usage       php scripts/seed_marketing_demo.php            # seed (refuses if demo data present)
 *              php scripts/seed_marketing_demo.php --fresh    # purge prior demo rows, then reseed
 *              php scripts/seed_marketing_demo.php --purge    # remove demo rows only, no reseed
 *              php scripts/seed_marketing_demo.php --help
 *
 * @session     S-FF-DEMO-SEED
 * @decision    Layer-not-wipe; tag-scoped purge; real-engine invoices; local-only guard.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/Billing/InvoiceGenerator.php';

// ════════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ════════════════════════════════════════════════════════════════════════════

/** Purge marker embedded in text columns of every demo-seeded row. */
const DEMO_TAG = 'FFDEMO-MKT';
/** Slug prefix for demo equipment templates (slug is UNIQUE; gives a clean tag handle). */
const DEMO_SLUG_PREFIX = 'ffdemo-';
/** Acting user for created_by/updated_by/recorded_by (dev super-admin). */
const DEMO_USER_ID = 1;

// ════════════════════════════════════════════════════════════════════════════
// HARD GUARD — refuse anywhere that is not a confirmed LOCAL fleetforge.test box
// ════════════════════════════════════════════════════════════════════════════
// Multi-layer + fail-closed (modeled on demo_wipe.php's D-WIPE-PRODUCTION-GUARD):
//   L1  APP_ENV must NOT be production (config defaults APP_ENV→'production' when
//       unset, so a missing .env line ALSO refuses — ambiguity fails closed).
//   L2  APP_URL host must be exactly fleetforge.test.
//   L3  fleetforge.test must DNS-resolve to a loopback address (real local Herd).
//   L4  DB host must be a loopback/local host (never a remote prod DB).
// Any failure aborts before a single write.

(function (): void {
    $reasons = [];

    // L1 — environment
    if (!defined('APP_ENV') || APP_ENV === 'production') {
        $reasons[] = 'APP_ENV is "' . (defined('APP_ENV') ? APP_ENV : '(undefined → production)')
                   . '" — refuses unless explicitly non-production.';
    }

    // L2 — configured app host must be fleetforge.test
    $host = parse_url((string) APP_URL, PHP_URL_HOST) ?: '';
    if (strtolower($host) !== 'fleetforge.test') {
        $reasons[] = 'APP_URL host is "' . $host . '" — expected exactly "fleetforge.test".';
    }

    // L3 — fleetforge.test must resolve to loopback (genuine local dev box)
    $resolved = gethostbyname('fleetforge.test');
    $loopback = in_array($resolved, ['127.0.0.1', '::1'], true)
             || str_starts_with($resolved, '127.');
    if (!$loopback) {
        $reasons[] = 'fleetforge.test resolves to "' . $resolved . '" — expected a loopback (127.x / ::1).';
    }

    // L4 — DB host must be local
    $dbHost = defined('FF_DB_HOST') ? strtolower((string) FF_DB_HOST) : '';
    if (!in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        $reasons[] = 'FF_DB_HOST is "' . $dbHost . '" — expected a loopback/local host.';
    }

    if ($reasons) {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  ┌──────────────────────────────────────────────────────────────┐\n");
        fwrite(STDERR, "  │  HARD REFUSAL — seed_marketing_demo.php is LOCAL-ONLY         │\n");
        fwrite(STDERR, "  └──────────────────────────────────────────────────────────────┘\n\n");
        foreach ($reasons as $r) {
            fwrite(STDERR, "  ✗ {$r}\n");
        }
        fwrite(STDERR, "\n  This script must never touch production. Aborting.\n\n");
        exit(1);
    }
})();

// ════════════════════════════════════════════════════════════════════════════
// CLI ARGS
// ════════════════════════════════════════════════════════════════════════════

$argvFlags = array_slice($argv, 1);
$DO_FRESH  = in_array('--fresh', $argvFlags, true);
$DO_PURGE_ONLY = in_array('--purge', $argvFlags, true) || in_array('--purge-only', $argvFlags, true);

if (in_array('--help', $argvFlags, true) || in_array('-h', $argvFlags, true)) {
    $tag = DEMO_TAG;
    echo <<<TXT

    FleetForge Marketing Demo Seeder (S-FF-DEMO-SEED)

      php scripts/seed_marketing_demo.php           Seed (refuses if demo rows already exist).
      php scripts/seed_marketing_demo.php --fresh   Purge prior demo rows, then reseed.
      php scripts/seed_marketing_demo.php --purge   Remove demo rows only; no reseed.
      php scripts/seed_marketing_demo.php --help    This help.

    All demo rows are tagged "{$tag}" and are the only rows --fresh/--purge touch.

    TXT;
    exit(0);
}

// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════

function line(string $s = ''): void { echo $s . "\n"; }
function hr(): void { line(str_repeat('─', 64)); }

function daysAgo(int $n): string  { return (new DateTime("-{$n} days"))->format('Y-m-d'); }
function daysFromNow(int $n): string { return (new DateTime("+{$n} days"))->format('Y-m-d'); }

/** Random but readable contract/identifier suffix. */
function code6(): string { return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)); }

/** Tagged internal_notes value — visible marker + human context. */
function tagNote(string $context): string {
    return "[" . DEMO_TAG . "] " . $context;
}

/** Money: format a bcmath/string/float to 2dp string (D16 — use bcmath for set math). */
function money($v): string { return number_format((float) $v, 2, '.', ''); }

/** Collect ids from a single-column query. @return int[] */
function ids(string $sql, array $params = []): array {
    return array_map(static fn($r) => (int) array_values($r)[0], db_select($sql, $params));
}

/** Safe DELETE … WHERE col IN (ids); no-op on empty set. Returns rows deleted. */
function delIn(string $table, string $col, array $idList): int {
    $idList = array_values(array_unique(array_filter($idList)));
    if (!$idList) return 0;
    $ph = implode(',', array_fill(0, count($idList), '?'));
    try {
        return db_execute("DELETE FROM `{$table}` WHERE `{$col}` IN ({$ph})", $idList);
    } catch (\Throwable $e) {
        line("    ! delete {$table} skipped: " . $e->getMessage());
        return 0;
    }
}

/** Safe DELETE by a raw WHERE clause (for tag-marker deletes). */
function delWhere(string $table, string $where, array $params = []): int {
    try {
        return db_execute("DELETE FROM `{$table}` WHERE {$where}", $params);
    } catch (\Throwable $e) {
        line("    ! delete {$table} skipped: " . $e->getMessage());
        return 0;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// PURGE — tag-scoped, FK-safe (child → parent). HARD deletes (demo rows are
// throwaway + local); hard delete dodges the soft-delete UNIQUE-index blind spot
// (vin/unit_number/contract_number reuse on reseed).
// ════════════════════════════════════════════════════════════════════════════

function purgeDemo(): array
{
    $like = '%' . DEMO_TAG . '%';

    // Resolve demo id sets by tag.
    $customers = ids("SELECT id FROM customers          WHERE internal_notes LIKE ?", [$like]);
    $templates = ids("SELECT id FROM equipment_templates WHERE slug LIKE ?", [DEMO_SLUG_PREFIX . '%']);
    $units     = ids("SELECT id FROM equipment_units     WHERE internal_notes LIKE ?", [$like]);
    $cards     = ids("SELECT id FROM rate_cards          WHERE description LIKE ?", [$like]);
    $leases    = ids("SELECT id FROM leases              WHERE internal_notes LIKE ?", [$like]);
    $payments  = ids("SELECT id FROM payments            WHERE internal_notes LIKE ?", [$like]);
    // Invoices: tagged directly (engine wrote our internal_notes) OR reachable via demo leases.
    $invoices  = $leases
        ? ids("SELECT id FROM invoices WHERE internal_notes LIKE ? OR lease_id IN (" . implode(',', $leases) . ")", [$like])
        : ids("SELECT id FROM invoices WHERE internal_notes LIKE ?", [$like]);

    $d = [];
    // 1. payment allocations + payments
    $d['payment_allocations'] = delIn('payment_allocations', 'payment_id', $payments);
    $d['payments']            = delIn('payments', 'id', $payments);
    // 2. QBO maps (also CASCADE on customer/invoice delete; explicit = belt+suspenders)
    $d['acc_qbo_invoice_map']  = delIn('acc_qbo_invoice_map', 'ff_invoice_id', $invoices);
    $d['acc_qbo_customer_map'] = delIn('acc_qbo_customer_map', 'ff_customer_id', $customers);
    // 3. invoice children → invoices
    $d['invoice_line_items']     = delIn('invoice_line_items', 'invoice_id', $invoices);
    $d['lease_billing_periods']  = delIn('lease_billing_periods', 'lease_id', $leases);
    $d['invoices']               = delIn('invoices', 'id', $invoices);
    // 4. lease operational children
    $d['lease_status_log']        = delIn('lease_status_log', 'lease_id', $leases);
    $d['equipment_distance_logs'] = delWhere('equipment_distance_logs', 'label LIKE ?', [$like])
                                  + delIn('equipment_distance_logs', 'equipment_unit_id', $units);
    $d['samsara_location_history']= delIn('samsara_location_history', 'equipment_unit_id', $units);
    // 5. leases
    $d['leases']                  = delIn('leases', 'id', $leases);
    // 6. rate cards (rate_cards.customer_id is ON DELETE RESTRICT → must go before customers)
    $d['rate_card_items']         = delIn('rate_card_items', 'rate_card_id', $cards);
    $d['rate_cards']              = delIn('rate_cards', 'id', $cards);
    // 7. units → templates (units last among fleet because leases referenced them)
    $d['equipment_units']         = delIn('equipment_units', 'id', $units);
    $d['equipment_templates']     = delIn('equipment_templates', 'id', $templates);
    // 8. any engine-emitted credit notes for demo customers/invoices, then the customers
    $d['credit_notes'] = delIn('credit_notes', 'customer_id', $customers)
                       + ($invoices ? delWhere('credit_notes', 'invoice_id IN (' . implode(',', $invoices) . ')') : 0);
    $d['customers']               = delIn('customers', 'id', $customers);

    return $d;
}

// ════════════════════════════════════════════════════════════════════════════
// PRE-RUN: blast-radius echo + mode handling
// ════════════════════════════════════════════════════════════════════════════

hr();
line("  FleetForge Marketing Demo Seeder  (S-FF-DEMO-SEED)");
hr();
line("  DB       : " . FF_DB_NAME . " @ " . FF_DB_HOST);
line("  APP_ENV  : " . APP_ENV);
line("  APP_URL  : " . APP_URL);
line("  Tag      : " . DEMO_TAG);
line("  Mode     : " . ($DO_PURGE_ONLY ? 'PURGE-ONLY' : ($DO_FRESH ? 'FRESH (purge + reseed)' : 'SEED')));
hr();

$existingDemoCustomers = db_count("SELECT COUNT(*) FROM customers WHERE internal_notes LIKE ?", ['%' . DEMO_TAG . '%']);

if ($DO_PURGE_ONLY) {
    line("\nPurging demo-seeded rows…");
    $deleted = purgeDemo();
    foreach ($deleted as $t => $n) { if ($n) printf("  − %-26s %d\n", $t, $n); }
    line("\nDone. Demo rows removed.\n");
    exit(0);
}

if ($existingDemoCustomers > 0 && !$DO_FRESH) {
    fwrite(STDERR, "\n  Demo data already present ({$existingDemoCustomers} tagged customers).\n");
    fwrite(STDERR, "  Re-run with --fresh to purge + reseed, or --purge to remove.\n\n");
    exit(1);
}

if ($DO_FRESH) {
    line("\nPurging prior demo rows (--fresh)…");
    $deleted = purgeDemo();
    $tot = array_sum($deleted);
    line("  removed {$tot} demo rows across " . count(array_filter($deleted)) . " tables.");
}

$summary = [];

// ════════════════════════════════════════════════════════════════════════════
// PHASE 1 — CUSTOMERS (anonymized BC/Canada trucking flavour)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 1/8] Customers…");

// tax_rate_id: 1=BC GST+PST, 2=ON HST, 3=AB GST-only (verified in DB).
$customerDefs = [
    // ── Active CAD — BC/AB core ──
    ['Northgate Logistics Inc.',      'Daniel Friesen',  'dispatch@northgatelogistics.ca', '604-555-0142', '13455 Bridgeport Rd',  'Richmond',   'BC', 'V6V 1J5', 'CAD', 'km', 1, 'Net 30', '120000.00', 'low',    'active', 0,0,0, null, 'Anchor account — multi-unit dry van + reefer fleet, 4-year relationship.'],
    ['Pacific Haul Transport Ltd.',   'Simranjit Gill',  'ops@pacifichaul.ca',            '604-555-0188', '8820 132 St',          'Surrey',     'BC', 'V3W 4P1', 'CAD', 'km', 1, 'Net 30', '90000.00',  'low',    'active', 0,0,0, null, 'Long-haul tractor lessee, GPS-tracked. Reliable payer.'],
    ['Cascade Freight Lines',         'Robert Nguyen',   'ar@cascadefreight.ca',          '604-555-0204', '30900 Peardonville Rd','Abbotsford', 'BC', 'V2T 6K2', 'CAD', 'km', 1, 'Net 15', '60000.00',  'medium', 'active', 0,0,0, null, 'Seasonal produce hauler — heavy reefer mileage in summer.'],
    ['Fraser Valley Distribution',    'Amrit Sandhu',    'accounts@fvdist.ca',            '604-555-0233', '19433 96 Ave',         'Langley',    'BC', 'V1M 3B4', 'CAD', 'km', 1, 'Net 30', '75000.00',  'low',    'active', 0,1,0, 'PST-EX-BC-44821', 'Wholesale distribution — PST-exempt resale certificate on file.'],
    ['Summit Carriers Ltd.',          'Kyle Thompson',   'dispatch@summitcarriers.ca',    '250-555-0119', '1840 Kelly Douglas Rd','Kamloops',   'BC', 'V2C 5P5', 'CAD', 'km', 1, 'Net 45', '85000.00',  'low',    'active', 0,0,0, null, 'Interior BC carrier — flatbed + step deck for lumber.'],
    ['Coastal Container Services',    'Mei Lin Chow',    'billing@coastalcontainer.ca',   '604-555-0277', '1500 Stewart St',      'Vancouver',  'BC', 'V5L 4M8', 'CAD', 'km', 1, 'Net 30', '50000.00',  'medium', 'active', 0,0,0, null, 'Port drayage — chassis + container moves, Vancouver terminals.'],
    ['Rocky Mountain Freight Co.',    'Brett Lariviere', 'ap@rockymtnfreight.ca',         '403-555-0166', '5402 86 Ave SE',       'Calgary',    'AB', 'T2C 4J9', 'CAD', 'km', 3, 'Net 30', '110000.00', 'low',    'active', 0,0,0, null, 'Alberta GST-only carrier — cross-province dry van runs.'],
    ['Prairie Line Haulers Inc.',     'Curtis Olson',    'ops@prairieline.ca',            '780-555-0151', '12820 170 St NW',      'Edmonton',   'AB', 'T5V 1L8', 'CAD', 'km', 3, 'Net 30', '70000.00',  'medium', 'active', 0,0,0, null, 'Edmonton-based bulk + tanker. Growing fleet.'],
    // ── Active USD — cross-border (multi-currency demo) ──
    ['Gateway Intermodal LLC',        'Sarah Whitfield', 'ar@gatewayintermodal.com',      '206-555-0143', '4501 Shilshole Ave NW','Seattle',    'WA', '98107',   'USD', 'miles', null, 'Net 45', '160000.00', 'low',    'active', 0,0,1, 'US-CB-EXEMPT', 'US cross-border intermodal — USD billing, Canadian taxes exempt.'],
    ['Cross-Border Logistics USA Inc.','Mark Reyes',     'billing@cblusa.com',            '360-555-0188', '4255 Meridian St',     'Bellingham', 'WA', '98226',   'USD', 'miles', null, 'Net 45', '95000.00',  'low',    'active', 0,0,1, 'US-CB-EXEMPT', 'Bellingham brokerage — USD reefer + dry van north/south.'],
    ['Evergreen Transport Group',     'Jessica Hale',    'ap@evergreentg.com',            '503-555-0120', '7300 NE Killingsworth', 'Portland',   'OR', '97218',   'USD', 'miles', null, 'Net 30', '80000.00',  'medium', 'active', 0,0,1, 'US-CB-EXEMPT', 'Portland LTL consolidator — USD long-haul tractors.'],
    // ── Inactive ──
    ['Harbour City Cartage',          'Tom Beswick',     'tom@harbourcitycartage.ca',     '250-555-0190', '410 Garbally Rd',      'Victoria',   'BC', 'V8T 2K1', 'CAD', 'km', 1, 'Net 30', '30000.00',  'low',    'inactive', 0,0,0, null, 'Account dormant since fleet downsizing — retained for history.'],
    // ── Credit hold (collections watch) ──
    ['Interior Bulk Transport',       'Gary Sahota',     'gary@interiorbulk.ca',          '250-555-0177', '725 Finns Rd',         'Kelowna',    'BC', 'V1X 5B8', 'CAD', 'km', 1, 'Net 15', '40000.00',  'high',   'credit_hold', 0,0,0, null, 'On credit hold — aged receivables under collection review.'],
];

$customerIds = [];   // company_name => id
foreach ($customerDefs as $c) {
    [$company,$contact,$email,$phone,$addr,$city,$prov,$postal,$cur,$munit,$taxId,$terms,$credit,$risk,$status,$gstEx,$pstEx,$taxEx,$taxNum,$ctx] = $c;
    $row = [
        'company_name'      => $company,
        'contact_name'      => $contact,
        'email'             => $email,
        'phone'             => $phone,
        'address'           => $addr,
        'city'              => $city,
        'province'          => $prov,
        'state'             => $prov,
        'country'           => $cur === 'USD' ? 'United States' : 'Canada',
        'postal_code'       => $postal,
        'currency'          => $cur,
        'mileage_unit'      => $munit,
        'tax_rate_id'       => $taxId,
        'gst_exempt'        => $gstEx,
        'pst_exempt'        => $pstEx,
        'tax_exempt'        => $taxEx,
        'tax_exempt_number' => $taxNum,
        'billing_cycle'     => 'monthly',
        'payment_terms'     => $terms,
        'credit_limit'      => $credit,
        'risk_score'        => $risk,
        'status'            => $status,
        'collection_status' => $status === 'credit_hold' ? 'watch' : 'current',
        'notes'             => $ctx,
        'internal_notes'    => tagNote('marketing demo customer'),
        'created_by'        => DEMO_USER_ID,
        'updated_by'        => DEMO_USER_ID,
    ];
    $id = db_insert('customers', $row);
    $customerIds[$company] = $id;
    printf("  + #%-3d %-32s %s %s%s\n", $id, $company, $cur, $status,
        $taxEx ? ' tax-exempt' : ($pstEx ? ' PST-exempt' : ''));
}
$summary['customers'] = count($customerIds);

// ════════════════════════════════════════════════════════════════════════════
// PHASE 2 — EQUIPMENT TEMPLATES + UNITS (realistic makes/models)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 2/8] Equipment templates + units…");

// Templates: [name, category, brand, model, len, ht, wd, cap_lbs, axles, daily, weekly, monthly, mileage, hourly|null]
$templateDefs = [
    ['Freightliner Cascadia Sleeper', 'other',     'Freightliner', 'Cascadia 126',  null, null, null, null, 3, '185.00','1110.00','4200.00','0.3400', null],
    ['Kenworth T680 Day Cab',         'other',     'Kenworth',     'T680',          null, null, null, null, 3, '175.00','1050.00','3950.00','0.3200', null],
    ['Volvo VNL 760 Sleeper',         'other',     'Volvo',        'VNL 760',       null, null, null, null, 3, '180.00','1080.00','4050.00','0.3300', null],
    ["Wabash DuraPlate 53' Dry Van",  'dry_van',   'Wabash',       'DuraPlate',     '53.00','13.50','8.50', 45000, 2, '52.00','312.00','1150.00','0.0000', null],
    ["Great Dane Champion 53' Dry Van",'dry_van',  'Great Dane',   'Champion SE',   '53.00','13.50','8.50', 45000, 2, '50.00','300.00','1100.00','0.0000', null],
    ["Utility 3000R 53' Reefer",      'reefer',    'Utility',      '3000R',         '53.00','13.50','8.50', 44000, 2, '88.00','528.00','1950.00','0.1900', '14.0000'],
    ["Great Dane Everest 53' Reefer", 'reefer',    'Great Dane',   'Everest SS',    '53.00','13.50','8.50', 44000, 2, '92.00','552.00','2050.00','0.2000', '15.0000'],
    ["Doonan 48' Step Deck",          'step_deck', 'Doonan',       'Drop Deck',     '48.00','10.00','8.50', 48000, 3, '68.00','408.00','1500.00','0.1500', null],
    ["Manac 53' Flatbed",             'flatbed',   'Manac',        '53 Flatbed',    '53.00','5.00','8.50',  48000, 3, '58.00','348.00','1300.00','0.1400', null],
    ["Cheetah 40' Container Chassis", 'chassis',   'Cheetah',      'Tri-Axle',      '40.00','4.00','8.50',  44000, 3, '42.00','252.00','900.00', '0.0000', null],
];

$templateIds = [];   // name => id  (also keyed by category for convenience)
$tplByCat    = [];   // category => [ids]
foreach ($templateDefs as $i => $t) {
    [$name,$cat,$brand,$model,$len,$ht,$wd,$cap,$ax,$daily,$weekly,$monthly,$mile,$hourly] = $t;
    $tid = db_insert('equipment_templates', [
        'name'                        => $name,
        'slug'                        => DEMO_SLUG_PREFIX . substr(strtolower(preg_replace('/[^a-z0-9]+/i','-',$name)), 0, 70) . '-' . $i,
        'description'                 => tagNote('marketing demo template'),
        'category'                    => $cat,
        'brand'                       => $brand,
        'model'                       => $model,
        'default_length_ft'           => $len,
        'default_height_ft'           => $ht,
        'default_width_ft'            => $wd,
        'default_weight_capacity_lbs' => $cap,
        'default_axle_count'          => $ax,
        'default_daily_rate'          => $daily,
        'default_weekly_rate'         => $weekly,
        'default_monthly_rate'        => $monthly,
        'default_mileage_rate'        => $mile,
        'default_hourly_rate'         => $hourly,
        'is_active'                   => 1,
    ]);
    $templateIds[$name] = ['id' => $tid, 'cat' => $cat, 'brand' => $brand, 'model' => $model,
                           'daily' => $daily, 'weekly' => $weekly, 'monthly' => $monthly,
                           'mileage' => $mile, 'hourly' => $hourly, 'name' => $name,
                           'len' => $len, 'wd' => $wd, 'ht' => $ht, 'cap' => $cap, 'ax' => $ax];
    $tplByCat[$cat][] = $name;
}
printf("  + %d templates (%s)\n", count($templateIds), implode(', ', array_keys($tplByCat)));

// Units — distribute across templates. Tractors get vehicle entity type + odometer;
// trailers get trailer entity type. ~half are Samsara-tracked.
$yardNames = array_map(static fn($r) => $r['name'], db_select("SELECT name FROM yards WHERE id IN (1,2,3,5,6) ORDER BY id", []));
if (!$yardNames) $yardNames = array_map(static fn($r) => $r['name'], db_select("SELECT name FROM yards ORDER BY id LIMIT 3", []));
if (!$yardNames) $yardNames = ['Surrey Yard'];
$plates = ['BC','BC','BC','AB','AB','BC','WA'];

// How many units per template (≈42 total, varied).
$perTemplate = [
    'Freightliner Cascadia Sleeper' => 5,
    'Kenworth T680 Day Cab'         => 4,
    'Volvo VNL 760 Sleeper'         => 4,
    "Wabash DuraPlate 53' Dry Van"  => 6,
    "Great Dane Champion 53' Dry Van"=> 5,
    "Utility 3000R 53' Reefer"      => 5,
    "Great Dane Everest 53' Reefer" => 4,
    "Doonan 48' Step Deck"          => 3,
    "Manac 53' Flatbed"             => 3,
    "Cheetah 40' Container Chassis" => 4,
];

$unitsByCat = [];   // category => [ {id, unit_number, samsara_vehicle_id, samsara_entity_type, samsara_odometer_km, template...} ]
$unitSeq = 0;
$unitCounter = ['available' => 0, 'on_lease' => 0, 'maintenance' => 0];
foreach ($perTemplate as $tplName => $count) {
    $tpl = $templateIds[$tplName];
    $isTractor = $tpl['cat'] === 'other';
    $prefix = match ($tpl['cat']) {
        'other'     => 'TR',   // tractor
        'reefer'    => 'RF',
        'dry_van'   => 'DV',
        'flatbed'   => 'FB',
        'step_deck' => 'SD',
        'chassis'   => 'CH',
        default     => 'EQ',
    };
    for ($k = 0; $k < $count; $k++) {
        $unitSeq++;
        $unitNo = sprintf('%s-%04d', $prefix, 7000 + $unitSeq);   // e.g. TR-7001 (high range, unlikely to collide)
        $year   = 2019 + ($unitSeq % 7);                          // 2019..2025
        $vin    = 'FFDEMO' . str_pad((string) $unitSeq, 11, '0', STR_PAD_LEFT);  // 17 chars, tag-identifiable, unique
        $samsara = ($unitSeq % 2 === 0);                          // ~half tracked
        $odo    = $isTractor ? (string) random_int(180000, 720000) . '.00' : null;
        $row = [
            'template_id'         => $tpl['id'],
            'unit_number'         => $unitNo,
            'vin'                 => $vin,
            'year'                => $year,
            'tracking_provider'   => $samsara ? 'samsara' : 'none',
            'ownership_type'      => 'owned',
            'length_ft'           => $tpl['len'],
            'height_ft'           => $tpl['ht'],
            'width_ft'            => $tpl['wd'],
            'weight_capacity_lbs' => $tpl['cap'],
            'axle_count'          => $tpl['ax'],
            'license_plate'       => strtoupper(substr(bin2hex(random_bytes(2)),0,2)) . random_int(1000,9999),
            'license_state'       => $plates[$unitSeq % count($plates)],
            'status'              => 'available',   // adjusted later when leased
            'mileage'             => $isTractor ? (int) random_int(180000, 720000) : 0,
            'samsara_vehicle_id'  => $samsara ? ('DEMO-SAM-' . (1000 + $unitSeq)) : null,
            'samsara_entity_type' => $isTractor ? 'vehicle' : 'trailer',
            'samsara_odometer_km' => $samsara ? $odo : null,
            'notes'               => $tpl['brand'] . ' ' . $tpl['model'] . ' (' . $year . ')',
            'internal_notes'      => tagNote('marketing demo unit'),
            'yard_location'       => $yardNames[$unitSeq % count($yardNames)] ?? null,
            'created_by'          => DEMO_USER_ID,
            'updated_by'          => DEMO_USER_ID,
        ];
        $uid = db_insert('equipment_units', $row);
        $unitsByCat[$tpl['cat']][] = [
            'id' => $uid, 'unit_number' => $unitNo, 'vin' => $vin, 'year' => $year,
            'samsara_vehicle_id' => $row['samsara_vehicle_id'], 'samsara_entity_type' => $row['samsara_entity_type'],
            'samsara_odometer_km' => $row['samsara_odometer_km'], 'tracking_provider' => $row['tracking_provider'],
            'mileage' => $row['mileage'], 'tpl' => $tpl,
            'len' => $tpl['len'], 'wd' => $tpl['wd'], 'ht' => $tpl['ht'], 'cap' => $tpl['cap'], 'ax' => $tpl['ax'],
            'license_plate' => $row['license_plate'], 'license_state' => $row['license_state'],
        ];
        $unitCounter['available']++;
    }
}
$totalUnits = array_sum(array_map('count', $unitsByCat));
printf("  + %d units across %d templates (Samsara-tracked ≈ half)\n", $totalUnits, count($templateIds));
$summary['equipment_units']     = $totalUnits;
$summary['equipment_templates'] = count($templateIds);

/** Pop the next available unit of a category (mutates $unitsByCat). */
function popUnit(string $cat) {
    global $unitsByCat;
    if (empty($unitsByCat[$cat])) {
        throw new RuntimeException("Out of available '{$cat}' demo units.");
    }
    return array_shift($unitsByCat[$cat]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3 — RATE CARDS (named; one default; per-category items w/ mileage+gps+hourly)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 3/8] Rate cards…");

// equipment_type stores the CATEGORY slug (rate lookup keys on category — D-RATES).
$rateCardDefs = [
    ['Standard Dry Van 2026', 'Default fleet pricing for dry vans + chassis. CAD, net 30.', 1, null, [
        ['dry_van',   '52.00','312.00','1150.00','0.0000','1.50', null],
        ['chassis',   '42.00','252.00','900.00', '0.0000','1.50', null],
    ]],
    ['Reefer Premium 2026', 'Temperature-controlled premium package — mileage + reefer engine-hours.', 0, null, [
        ['reefer',    '90.00','540.00','2000.00','0.1900','2.00', '14.0000'],
    ]],
    ['Long-Haul Tractor 2026', 'Power-unit pricing for over-the-road tractors. High mileage allowance.', 0, 'Pacific Haul Transport Ltd.', [
        ['other',     '180.00','1080.00','4100.00','0.3300','2.50', null],
    ]],
    ['Flatbed & Step Deck 2026', 'Open-deck equipment for lumber, steel + oversize.', 0, null, [
        ['flatbed',   '58.00','348.00','1300.00','0.1400','1.50', null],
        ['step_deck', '68.00','408.00','1500.00','0.1500','1.50', null],
    ]],
    ['USD Cross-Border 2026', 'Cross-border USD rate card — dry van, reefer + tractor.', 0, 'Gateway Intermodal LLC', [
        ['dry_van',   '48.00','288.00','1050.00','0.0000','1.50', null],
        ['reefer',    '85.00','510.00','1900.00','0.1500','2.00', '13.0000'],
        ['other',     '175.00','1050.00','3950.00','0.3000','2.50', null],
    ]],
];

$rateCardIds = [];
foreach ($rateCardDefs as $rc) {
    [$name,$desc,$isDefault,$boundCustomer,$items] = $rc;
    $cardCurrency = str_contains($name, 'USD') ? 'USD' : 'CAD';
    $card = [
        'name'           => $name,
        'description'    => $desc . ' ' . tagNote('rate card'),
        'is_default'     => $isDefault,
        'effective_from' => daysAgo(300),
        'created_by'     => DEMO_USER_ID,
    ];
    if ($boundCustomer && isset($customerIds[$boundCustomer])) {
        $card['customer_id'] = $customerIds[$boundCustomer];
    }
    try {
        $cid = db_insert('rate_cards', $card);
    } catch (\Throwable $e) {
        unset($card['customer_id']);   // customer_id col absent on some revs
        $cid = db_insert('rate_cards', $card);
    }
    $rateCardIds[$name] = $cid;
    foreach ($items as $it) {
        [$cat,$daily,$weekly,$monthly,$mileage,$gps,$hourly] = $it;
        $item = [
            'rate_card_id'   => $cid,
            'equipment_type' => $cat,
            'daily_rate'     => $daily,
            'weekly_rate'    => $weekly,
            'monthly_rate'   => $monthly,
            'mileage_rate'   => $mileage,
            'mileage_unit'   => $cardCurrency === 'USD' ? 'miles' : 'km',
            'currency'       => $cardCurrency,
        ];
        foreach (['gps_price' => $gps, 'hourly_rate' => $hourly] as $col => $val) {
            if ($val !== null) $item[$col] = $val;
        }
        try {
            db_insert('rate_card_items', $item);
        } catch (\Throwable $e) {
            unset($item['gps_price'], $item['hourly_rate']);
            db_insert('rate_card_items', $item);
        }
    }
    printf("  + #%-3d %-26s %d item(s)%s%s\n", $cid, $name, count($items),
        $isDefault ? ' [default]' : '', $boundCustomer ? " → {$boundCustomer}" : '');
}
$summary['rate_cards'] = count($rateCardIds);

// ════════════════════════════════════════════════════════════════════════════
// PHASE 4 — LEASES (backdated 2-10 months; active/completed/pending; 3 heroes)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 4/8] Leases…");

/** Freeze a customer's effective tax rates onto the lease. */
function freezeTax(?int $taxRateId, int $gstEx, int $pstEx, int $taxEx): array {
    if (!$taxRateId) return ['gst' => '0.0000', 'pst' => '0.0000', 'hst' => '0.0000'];
    $r = db_row("SELECT gst_rate, pst_rate, hst_rate FROM tax_rates WHERE id = ?", [$taxRateId]);
    if (!$r) return ['gst' => '0.0000', 'pst' => '0.0000', 'hst' => '0.0000'];
    return [
        'gst' => ($taxEx || $gstEx) ? '0.0000' : $r['gst_rate'],
        'pst' => ($taxEx || $pstEx) ? '0.0000' : $r['pst_rate'],
        'hst' => $taxEx ? '0.0000' : $r['hst_rate'],
    ];
}

/** Build the equipment snapshot json the lease detail page renders. */
function equipSnap(array $u): string {
    $t = $u['tpl'];
    return json_encode([
        'vin' => $u['vin'], 'year' => (int) $u['year'], 'category' => $t['cat'],
        'unit_number' => $u['unit_number'], 'template_name' => $t['name'],
        'brand' => $t['brand'], 'model' => $t['model'],
        'length_ft' => $u['len'], 'width_ft' => $u['wd'], 'height_ft' => $u['ht'],
        'axle_count' => $u['ax'] ? (int) $u['ax'] : null, 'weight_capacity_lbs' => $u['cap'] ? (int) $u['cap'] : null,
        'license_plate' => $u['license_plate'], 'license_state' => $u['license_state'],
        'tracking_provider' => $u['tracking_provider'], 'mileage_at_snapshot' => (int) $u['mileage'],
    ]);
}

// Lease blueprints. Each: customer, category, status, startDaysAgo, lengthDays,
// flags. Heroes flagged 'hero' get mileage+GPS+addons.
$leaseDefs = [
    // ── HERO 1 — Northgate reefer, 8mo, samsara mileage, insurance+warranty+hourly ──
    ['hero' => 'Reefer Showcase', 'customer' => 'Northgate Logistics Inc.', 'cat' => 'reefer', 'status' => 'active',
     'startAgo' => 245, 'len' => 365, 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true,
     'insurance' => '210.00', 'warranty' => '140.00', 'hourly' => true, 'odoStart' => 38420.0, 'kmPerMonth' => 5200,
     'notes' => 'Flagship reefer contract — full coverage, GPS odometer + reefer engine-hour billing.'],
    // ── HERO 2 — Pacific Haul tractor, 6mo, samsara high mileage ──
    ['hero' => 'Long-Haul Tractor', 'customer' => 'Pacific Haul Transport Ltd.', 'cat' => 'other', 'status' => 'active',
     'startAgo' => 190, 'len' => 365, 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true,
     'odoStart' => 412300.0, 'kmPerMonth' => 11500,
     'notes' => 'Over-the-road Cascadia sleeper — GPS-tracked, mileage-heavy long-haul.'],
    // ── HERO 3 — Cascade dry van, 5mo, GPS, mixed payment history ──
    ['hero' => 'Dry Van + GPS', 'customer' => 'Cascade Freight Lines', 'cat' => 'dry_van', 'status' => 'active',
     'startAgo' => 160, 'len' => 365, 'mileageMode' => 'off', 'gps' => true, 'discountPct' => '5.00',
     'notes' => 'Repeat customer — 5% loyalty discount, GPS service add-on.'],

    // ── Active CAD spread (varied start dates → trend history) ──
    ['customer' => 'Fraser Valley Distribution', 'cat' => 'dry_van', 'status' => 'active', 'startAgo' => 205, 'len' => 365, 'mileageMode' => 'off', 'gps' => true],
    ['customer' => 'Summit Carriers Ltd.',       'cat' => 'flatbed', 'status' => 'active', 'startAgo' => 140, 'len' => 200, 'mileageMode' => 'manual', 'mileage' => true, 'odoStart' => 21500.0, 'kmPerMonth' => 3200],
    ['customer' => 'Summit Carriers Ltd.',       'cat' => 'step_deck','status' => 'active', 'startAgo' => 95,  'len' => 200, 'mileageMode' => 'manual', 'mileage' => true, 'odoStart' => 18800.0, 'kmPerMonth' => 2600, 'po' => 'PO-SUMMIT-2026-118'],
    ['customer' => 'Coastal Container Services',  'cat' => 'chassis', 'status' => 'active', 'startAgo' => 120, 'len' => 365, 'mileageMode' => 'off'],
    ['customer' => 'Coastal Container Services',  'cat' => 'chassis', 'status' => 'active', 'startAgo' => 64,  'len' => 365, 'mileageMode' => 'off', 'billingCycle' => 'on_close_only'],
    ['customer' => 'Rocky Mountain Freight Co.',  'cat' => 'dry_van', 'status' => 'active', 'startAgo' => 175, 'len' => 365, 'mileageMode' => 'off', 'gps' => true],
    ['customer' => 'Rocky Mountain Freight Co.',  'cat' => 'other',   'status' => 'active', 'startAgo' => 88,  'len' => 365, 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true, 'odoStart' => 305000.0, 'kmPerMonth' => 9000],
    ['customer' => 'Prairie Line Haulers Inc.',   'cat' => 'reefer',  'status' => 'active', 'startAgo' => 110, 'len' => 365, 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true, 'insurance' => '175.00', 'odoStart' => 26400.0, 'kmPerMonth' => 4200],
    ['customer' => 'Northgate Logistics Inc.',    'cat' => 'dry_van', 'status' => 'active', 'startAgo' => 72,  'len' => 365, 'mileageMode' => 'off', 'gps' => true],

    // ── Active USD ──
    ['customer' => 'Gateway Intermodal LLC',      'cat' => 'other',   'status' => 'active', 'startAgo' => 150, 'len' => 365, 'currency' => 'USD', 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true, 'odoStart' => 280000.0, 'kmPerMonth' => 10500],
    ['customer' => 'Cross-Border Logistics USA Inc.','cat' => 'reefer','status' => 'active', 'startAgo' => 100, 'len' => 365, 'currency' => 'USD', 'mileageMode' => 'samsara', 'mileage' => true, 'gps' => true, 'insurance' => '160.00', 'warranty' => '95.00', 'odoStart' => 22100.0, 'kmPerMonth' => 3800],
    ['customer' => 'Evergreen Transport Group',   'cat' => 'other',   'status' => 'active', 'startAgo' => 55,  'len' => 365, 'currency' => 'USD', 'mileageMode' => 'off', 'gps' => true],

    // ── Completed (closed history → paid invoices, trend depth) ──
    ['customer' => 'Cascade Freight Lines',       'cat' => 'reefer',  'status' => 'completed', 'startAgo' => 300, 'len' => 120, 'mileageMode' => 'manual', 'mileage' => true, 'odoStart' => 41000.0, 'kmPerMonth' => 4800],
    ['customer' => 'Fraser Valley Distribution',  'cat' => 'dry_van', 'status' => 'completed', 'startAgo' => 270, 'len' => 90,  'mileageMode' => 'off'],
    ['customer' => 'Rocky Mountain Freight Co.',  'cat' => 'flatbed', 'status' => 'completed', 'startAgo' => 220, 'len' => 75,  'mileageMode' => 'manual', 'mileage' => true, 'odoStart' => 15600.0, 'kmPerMonth' => 3000],
    ['customer' => 'Prairie Line Haulers Inc.',   'cat' => 'chassis', 'status' => 'completed', 'startAgo' => 180, 'len' => 60,  'mileageMode' => 'off'],

    // ── Pending (signed, awaiting pickup) ──
    ['customer' => 'Summit Carriers Ltd.',        'cat' => 'dry_van', 'status' => 'pending', 'startAgo' => -7,  'len' => 180, 'mileageMode' => 'off', 'gps' => true],
    ['customer' => 'Interior Bulk Transport',     'cat' => 'other',   'status' => 'pending', 'startAgo' => -14, 'len' => 365, 'mileageMode' => 'samsara', 'gps' => true],
];

$leaseRecords = [];   // each: id, def, unit, currency, mileageMode, odoStart, kmPerMonth, startDate, endDate, status
foreach ($leaseDefs as $def) {
    $company  = $def['customer'];
    $custId   = $customerIds[$company];
    $custRow  = db_row("SELECT currency, mileage_unit, tax_rate_id, gst_exempt, pst_exempt, tax_exempt, payment_terms FROM customers WHERE id = ?", [$custId]);
    $unit     = popUnit($def['cat']);
    $tpl      = $unit['tpl'];
    $currency = $def['currency'] ?? $custRow['currency'];
    $mileUnit = $custRow['mileage_unit'];
    $billingCycle = $def['billingCycle'] ?? 'monthly';
    $status   = $def['status'];

    $startDate = $def['startAgo'] >= 0 ? daysAgo($def['startAgo']) : daysFromNow(abs($def['startAgo']));
    $endDate   = (new DateTime($startDate))->modify('+' . $def['len'] . ' days')->format('Y-m-d');
    $actualReturn = null;
    if ($status === 'completed') {
        // Returned a few days before scheduled end, in the past.
        $endDate = daysAgo(max(5, $def['startAgo'] - $def['len']));
        $actualReturn = $endDate;
    }

    $tax = freezeTax($custRow['tax_rate_id'] ? (int) $custRow['tax_rate_id'] : null,
                     (int) $custRow['gst_exempt'], (int) $custRow['pst_exempt'], (int) $custRow['tax_exempt']);

    $mileageRate = !empty($def['mileage']) ? $tpl['mileage'] : '0.0000';
    if ($mileageRate === '0.0000' && !empty($def['mileage'])) $mileageRate = '0.1800';  // ensure heroes bill mileage

    $row = [
        'contract_number'        => 'CN-DEMO-' . code6() . '-' . date('Y'),
        'customer_id'            => $custId,
        'equipment_unit_id'      => $unit['id'],
        'customer_name_snapshot' => $custRow['contact_name'] ?? null,
        'company_name_snapshot'  => $company,
        'unit_number_snapshot'   => $unit['unit_number'],
        'template_name_snapshot' => $tpl['name'],
        'equipment_snapshot_json'=> equipSnap($unit),
        'status'                 => $status,
        'start_date'             => $startDate,
        'end_date'               => $endDate,
        'actual_return_date'     => $actualReturn,
        'daily_rate'             => $tpl['daily'],
        'weekly_rate'            => $tpl['weekly'],
        'monthly_rate'           => $tpl['monthly'],
        'mileage_rate'           => $mileageRate,
        'estimated_mileage'      => !empty($def['mileage']) ? money(($def['kmPerMonth'] ?? 4000) * 6) : '0.00',
        'currency'               => $currency,
        'mileage_unit'           => $mileUnit,
        'billing_cycle'          => $billingCycle,
        'gst_exempt'             => (int) $custRow['gst_exempt'],
        'pst_exempt'             => (int) $custRow['pst_exempt'],
        'tax_exempt'             => (int) $custRow['tax_exempt'],
        'tax_rate_gst'           => $tax['gst'],
        'tax_rate_pst'           => $tax['pst'],
        'tax_rate_hst'           => $tax['hst'],
        'discount_type'          => isset($def['discountPct']) ? 'percentage' : 'none',
        'discount_value'         => $def['discountPct'] ?? '0.00',
        'insurance_opt_in'       => isset($def['insurance']) ? 1 : 0,
        'insurance_cost'         => $def['insurance'] ?? '0.00',
        'warranty_opt_in'        => isset($def['warranty']) ? 1 : 0,
        'warranty_cost'          => $def['warranty'] ?? '0.00',
        'gps_opt_in'             => !empty($def['gps']) ? 1 : 0,
        'gps_cost'               => !empty($def['gps']) ? '2.00' : '0.00',
        'mileage_tracking_mode'  => $def['mileageMode'] ?? 'off',
        'po_number'              => $def['po'] ?? null,
        'next_billing_date'      => $status === 'active' ? daysFromNow(random_int(1, 28)) : null,
        'notes'                  => $def['notes'] ?? ('Demo lease — ' . $company),
        'internal_notes'         => tagNote('marketing demo lease' . (isset($def['hero']) ? ' [HERO: ' . $def['hero'] . ']' : '')),
        'created_by'             => DEMO_USER_ID,
        'updated_by'             => DEMO_USER_ID,
    ];

    // S-LEASE-UNITS: the engine bills mileage off mileage_rate_km (mileage_rate alone
    // is legacy/inert), normalising distance to km internally. Set the km + informational
    // miles columns so a mileage_usage line actually emits for mileage leases.
    if (!empty($def['mileage'])) {
        $estKm = (float) $row['estimated_mileage'];
        $row['mileage_rate_km']         = $mileageRate;
        $row['mileage_rate_miles']      = number_format((float) $mileageRate / 1.609344, 4, '.', '');
        $row['estimated_mileage_km']    = number_format($estKm, 3, '.', '');
        $row['estimated_mileage_miles'] = number_format($estKm / 1.609344, 3, '.', '');
    }

    // Hourly (reefer engine-hours) hero.
    if (!empty($def['hourly']) && $tpl['hourly']) {
        $row['hourly_rate']           = $tpl['hourly'];
        $row['engine_hours_at_start'] = money(random_int(2000, 8000));
    }
    // Starting odometer for mileage leases.
    if (isset($def['odoStart'])) {
        $row['odometer_start_km']     = money($def['odoStart']);
        $row['odometer_start_source'] = ($def['mileageMode'] ?? 'off') === 'samsara' ? 'gps' : 'manual';
        if (($def['mileageMode'] ?? '') === 'samsara') $row['odometer_start_fetched_at'] = date('Y-m-d H:i:s');
    }
    if ($status === 'completed') {
        $row['closed_at']         = $actualReturn . ' 15:30:00';
        $row['closed_by_user_id'] = DEMO_USER_ID;
        if (isset($def['odoStart'])) {
            $months = max(1, (int) round($def['len'] / 30));
            $row['engine_hours_at_end'] = isset($row['engine_hours_at_start'])
                ? money((float) $row['engine_hours_at_start'] + $months * 320) : null;
        }
    }

    $leaseId = db_insert('leases', $row);

    // Unit status sync.
    $unitStatus = match ($status) {
        'active'    => 'on_lease',
        'pending'   => 'reserved',
        'completed' => 'available',
        default     => 'available',
    };
    db_execute("UPDATE equipment_units SET status = ?, updated_by = ? WHERE id = ?", [$unitStatus, DEMO_USER_ID, $unit['id']]);

    // Status log (FK to leases).
    try {
        db_insert('lease_status_log', [
            'lease_id'   => $leaseId,
            'old_status' => 'none',
            'new_status' => $status,
            'notes'      => tagNote('seed'),
            'changed_by' => 'demo-seeder',
            'user_id'    => DEMO_USER_ID,
        ]);
    } catch (\Throwable $e) { /* non-fatal */ }

    $leaseRecords[] = [
        'id' => $leaseId, 'def' => $def, 'unit' => $unit, 'currency' => $currency,
        'mileageMode' => $def['mileageMode'] ?? 'off', 'odoStart' => $def['odoStart'] ?? null,
        'kmPerMonth' => $def['kmPerMonth'] ?? null, 'startDate' => $startDate, 'endDate' => $endDate,
        'actualReturn' => $actualReturn, 'status' => $status, 'billingCycle' => $billingCycle,
        'terms' => $custRow['payment_terms'] ?? 'Net 30', 'customerId' => $custId,
        'hero' => $def['hero'] ?? null,
        'hourlyRate' => $row['hourly_rate'] ?? null,
        'engineHoursStart' => $row['engine_hours_at_start'] ?? null,
    ];
    printf("  + #%-4d %-26s %-9s %-9s start %s%s\n", $leaseId, $company, $def['cat'], $status, $startDate,
        isset($def['hero']) ? "  ★ {$def['hero']}" : '');
}
$summary['leases'] = count($leaseRecords);

// ════════════════════════════════════════════════════════════════════════════
// PHASE 5 — INVOICES via the REAL InvoiceGenerator engine (+ payments → statuses)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 5/8] Invoices via InvoiceGenerator engine…");

$gen = new \FleetForge\Billing\InvoiceGenerator();
$invoiceRecords = [];   // id, leaseId, customerId, currency, periodEnd, total, status(target), hero

/** Build calendar-monthly billing periods from $start to $end (inclusive). */
function buildPeriods(string $start, string $end, bool $completed, int $cap = 8): array {
    $periods = [];
    $cursor  = new DateTime($start);
    $endDt   = new DateTime($end);
    while ($cursor <= $endDt && count($periods) < $cap) {
        $monthEnd = (clone $cursor)->modify('last day of this month');
        $pEnd     = $monthEnd <= $endDt ? $monthEnd : $endDt;
        $periods[] = [$cursor->format('Y-m-d'), $pEnd->format('Y-m-d')];
        $cursor = (clone $pEnd)->modify('+1 day');
    }
    $n = count($periods);
    foreach ($periods as $i => &$p) {
        if ($n === 1)                          $p[2] = 'single_period';
        elseif ($i === 0)                      $p[2] = (new DateTime($p[0]))->format('d') === '01' ? 'full_month' : 'partial_start';
        elseif ($i === $n - 1 && $completed)   $p[2] = 'partial_end';
        else                                   $p[2] = 'full_month';
    }
    unset($p);
    return $periods;
}

foreach ($leaseRecords as $lr) {
    if ($lr['status'] === 'pending') continue;
    if ($lr['billingCycle'] === 'on_close_only' && $lr['status'] !== 'completed') continue;

    $billEnd = $lr['status'] === 'completed' ? ($lr['actualReturn'] ?? $lr['endDate']) : date('Y-m-d');
    if ($billEnd < $lr['startDate']) continue;
    $periods = buildPeriods($lr['startDate'], $billEnd, $lr['status'] === 'completed');
    if (!$periods) continue;

    // Running odometer + engine-hours cursors (deterministic, no Samsara call).
    $odoCursor   = $lr['odoStart'];
    $kmPerMonth  = $lr['kmPerMonth'] ?? 0;
    $hoursCursor = $lr['engineHoursStart'] !== null ? (float) $lr['engineHoursStart'] : null;

    foreach ($periods as $idx => $p) {
        [$pStart, $pEnd, $billType] = $p;
        $daysIn = max(1, (new DateTime($pStart))->diff(new DateTime($pEnd))->days + 1);
        $params = [
            'lease_id'          => $lr['id'],
            'period_start'      => $pStart,
            'period_end'        => $pEnd,
            'billing_type'      => $billType,
            'invoice_type'      => ($lr['status'] === 'completed' && $idx === count($periods) - 1) ? 'final' : 'regular',
            'generation_source' => 'manual',
            'auto_generated'    => 1,
            'created_by'        => DEMO_USER_ID,
            'internal_notes'    => tagNote('marketing demo invoice'),
        ];
        // Feed explicit odometer for mileage leases so a mileage_usage line emits offline.
        if ($lr['mileageMode'] !== 'off' && $odoCursor !== null && $kmPerMonth > 0) {
            $delta  = round($kmPerMonth * ($daysIn / 30), 1);
            $params['odometer_at_period_start_km'] = money($odoCursor);
            $params['odometer_at_period_end_km']   = money($odoCursor + $delta);
            $params['odometer_source']             = $lr['mileageMode'] === 'samsara' ? 'gps' : 'manual';
            $params['odometer_fetched_at']         = date('Y-m-d H:i:s', strtotime($pEnd . ' 09:00'));
            $odoCursor += $delta;
        }
        // Feed engine-hours per period for the hourly (reefer engine-hours) lease so an
        // hourly_usage line emits each period (~320 reefer-hours/month).
        if ($lr['hourlyRate'] && $hoursCursor !== null) {
            $hoursDelta = round(320 * ($daysIn / 30), 1);
            $params['engine_hours_at_period_start'] = money($hoursCursor);
            $params['engine_hours_at_period_end']   = money($hoursCursor + $hoursDelta);
            $hoursCursor += $hoursDelta;
        }
        try {
            $res = $gen->createFromLease($params);
        } catch (\Throwable $e) {
            line("    ! invoice {$lr['id']} {$pStart}→{$pEnd} failed: " . $e->getMessage());
            continue;
        }
        $invId = (int) $res['invoice_id'];

        // Backdate the invoice so the dashboard trend chart buckets it historically.
        $terms = (int) (preg_match('/(\d+)/', $lr['terms'], $m) ? $m[1] : 30);
        $dueDate = date('Y-m-d', strtotime($pEnd . " +{$terms} days"));
        db_execute(
            "UPDATE invoices SET invoice_date = ?, due_date = ?, created_at = ? WHERE id = ?",
            [$pEnd, $dueDate, $pEnd . ' 12:00:00', $invId]
        );

        $invoiceRecords[] = [
            'id' => $invId, 'leaseId' => $lr['id'], 'customerId' => $lr['customerId'],
            'currency' => $lr['currency'], 'periodEnd' => $pEnd, 'dueDate' => $dueDate,
            'total' => (float) $res['total_amount'], 'leaseStatus' => $lr['status'], 'hero' => $lr['hero'],
            'invoiceNumber' => $res['invoice_number'] ?? null,
        ];
    }
}
printf("  + %d invoices generated across %d billed leases\n", count($invoiceRecords),
    count(array_unique(array_map(fn($i) => $i['leaseId'], $invoiceRecords))));
$summary['invoices'] = count($invoiceRecords);

// ── Payments → realistic status mix (paid / partial / sent / overdue / draft) ──
line("  Applying payments + statuses…");
$paymentSeq = 0;
$statusTally = ['paid' => 0, 'partially_paid' => 0, 'overdue' => 0, 'sent' => 0, 'draft' => 0];
$today = date('Y-m-d');

foreach ($invoiceRecords as &$inv) {
    $ageDays = (int) floor((strtotime($today) - strtotime($inv['periodEnd'])) / 86400);
    $total   = $inv['total'];
    if ($total <= 0) { $statusTally['draft']++; $inv['finalStatus'] = 'draft'; continue; }

    // Decide payment behaviour by age + lease status.
    if ($inv['leaseStatus'] === 'completed' || $ageDays > 55) {
        $mode = 'paid';
    } elseif ($ageDays > 30) {
        $mode = (random_int(0, 9) < 3) ? 'overdue' : 'partial';   // some slip to overdue
    } elseif ($ageDays > 0) {
        $mode = 'sent';
    } else {
        $mode = 'draft';
    }

    $isUsd = $inv['currency'] === 'USD';
    $fx    = $isUsd ? '1.3750' : '1.0000';

    if ($mode === 'paid' || $mode === 'partial') {
        $amount = $mode === 'paid' ? money($total) : money(bcmul(money($total), '0.5', 2));
        $payDate = date('Y-m-d', strtotime($inv['periodEnd'] . ' +' . random_int(5, 25) . ' days'));
        if ($payDate > $today) $payDate = $today;
        $amountCad = $isUsd ? money(bcmul($amount, $fx, 2)) : $amount;
        $paymentSeq++;
        try {
            $pid = db_insert('payments', [
                'payment_number'       => 'PMT-DEMO-' . date('Y') . '-' . str_pad((string) $paymentSeq, 5, '0', STR_PAD_LEFT),
                'customer_id'          => $inv['customerId'],
                'amount'               => $amount,
                'currency'             => $inv['currency'],
                'exchange_rate_to_cad' => $fx,
                'amount_in_cad'        => $amountCad,
                'payment_method'       => ['ach','wire','e_transfer','check'][random_int(0,3)],
                'origin'               => 'ff_native',
                'reference_number'     => 'REF-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6)),
                'payment_date'         => $payDate,
                'status'               => 'cleared',
                'notes'                => $mode === 'paid' ? 'Payment in full' : 'Partial payment received',
                'internal_notes'       => tagNote('marketing demo payment'),
                'recorded_by'          => DEMO_USER_ID,
            ]);
            db_insert('payment_allocations', [
                'payment_id'      => $pid,
                'invoice_id'      => $inv['id'],
                'amount'          => $amount,
                'currency'        => $inv['currency'],
                'allocation_type' => 'manual',
                'allocated_by'    => DEMO_USER_ID,
            ]);
        } catch (\Throwable $e) {
            line("    ! payment for invoice {$inv['id']} skipped: " . $e->getMessage());
        }
        $newStatus = $mode === 'paid' ? 'paid' : 'partially_paid';
        db_execute(
            "UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ?, paid_date = ? WHERE id = ?",
            [$amount, money($total - (float) $amount), $newStatus, $mode === 'paid' ? $payDate : null, $inv['id']]
        );
        $statusTally[$newStatus]++;
        $inv['finalStatus'] = $newStatus;
    } elseif ($mode === 'overdue') {
        db_execute("UPDATE invoices SET status = 'overdue', balance_due = total_amount WHERE id = ?", [$inv['id']]);
        $statusTally['overdue']++;
        $inv['finalStatus'] = 'overdue';
    } elseif ($mode === 'sent') {
        db_execute("UPDATE invoices SET status = 'sent', balance_due = total_amount WHERE id = ?", [$inv['id']]);
        $statusTally['sent']++;
        $inv['finalStatus'] = 'sent';
    } else {
        $statusTally['draft']++;
        $inv['finalStatus'] = 'draft';
    }
}
unset($inv);

// Anything still 'sent' but past due → overdue (mirror real lifecycle).
db_execute(
    "UPDATE invoices SET status = 'overdue'
       WHERE status = 'sent' AND due_date < ? AND balance_due > 0
         AND internal_notes LIKE ?",
    [$today, '%' . DEMO_TAG . '%']
);

// Refresh customer roll-up counters so dashboard/customer tiles agree (Path B: SENT-and-owing only).
db_execute(
    "UPDATE customers c SET
        lease_count = (SELECT COUNT(*) FROM leases WHERE customer_id = c.id AND deleted_at IS NULL),
        active_lease_count = (SELECT COUNT(*) FROM leases WHERE customer_id = c.id AND status='active' AND deleted_at IS NULL),
        total_revenue = COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE customer_id = c.id AND deleted_at IS NULL), 0),
        outstanding_balance = COALESCE((SELECT SUM(balance_due) FROM invoices WHERE customer_id = c.id AND deleted_at IS NULL AND status IN ('sent','partially_paid','overdue')), 0)
      WHERE c.internal_notes LIKE ?",
    ['%' . DEMO_TAG . '%']
);
$summary['payments'] = $paymentSeq;
line("  + {$paymentSeq} payments; status mix: " . json_encode($statusTally));

// ════════════════════════════════════════════════════════════════════════════
// PHASE 6 — GPS / DISTANCE (faked locally; feeds Distance Travelled + breadcrumbs)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 6/8] GPS / distance…");

// BC-ish lat/long anchors for plausible breadcrumb trails.
$anchors = [
    [49.1913, -122.8490], // Surrey
    [49.1044, -122.6604], // Langley
    [49.0504, -122.3045], // Abbotsford
    [49.2827, -123.1207], // Vancouver
    [50.6745, -120.3273], // Kamloops
];
$distLogs = 0; $breadcrumbs = 0;
foreach ($leaseRecords as $lr) {
    if ($lr['status'] !== 'active' || $lr['mileageMode'] === 'off') continue;
    $unitId = $lr['unit']['id'];
    $kmPerMonth = $lr['kmPerMonth'] ?? 3000;

    // A few monthly distance-log rows (last 3 months) for the Distance Travelled section.
    for ($m = 3; $m >= 1; $m--) {
        $pStart = (new DateTime("first day of -{$m} month"))->format('Y-m-d 00:00:00');
        $pEnd   = (new DateTime('first day of -' . ($m - 1) . ' month'))->modify('-1 day')->format('Y-m-d 23:59:59');
        $dist   = round($kmPerMonth * (0.85 + (random_int(0, 30) / 100)), 2);
        try {
            db_insert('equipment_distance_logs', [
                'equipment_unit_id' => $unitId,
                'period_start'      => $pStart,
                'period_end'        => $pEnd,
                'distance'          => money($dist),
                'unit'              => $lr['currency'] === 'USD' ? 'miles' : 'km',
                'source'            => $lr['mileageMode'] === 'samsara' ? 'gps' : 'manual',
                'reading_count'     => random_int(40, 240),
                'first_reading_at'  => $pStart,
                'last_reading_at'   => $pEnd,
                'label'             => DEMO_TAG . ' monthly distance',
                'queried_at'        => date('Y-m-d H:i:s'),
                'created_by'        => DEMO_USER_ID,
            ]);
            $distLogs++;
        } catch (\Throwable $e) { line("    ! distance log skipped: " . $e->getMessage()); }
    }

    // Recent GPS breadcrumbs for Samsara-tracked units (location history map/list).
    if ($lr['mileageMode'] === 'samsara' && $lr['unit']['samsara_vehicle_id']) {
        $a = $anchors[$unitId % count($anchors)];
        for ($h = 0; $h < 8; $h++) {
            $lat = $a[0] + (random_int(-400, 400) / 10000);
            $lng = $a[1] + (random_int(-400, 400) / 10000);
            try {
                db_insert('samsara_location_history', [
                    'equipment_unit_id'   => $unitId,
                    'samsara_vehicle_id'  => $lr['unit']['samsara_vehicle_id'],
                    'samsara_entity_type' => $lr['unit']['samsara_entity_type'],
                    'latitude'            => number_format($lat, 7, '.', ''),
                    'longitude'           => number_format($lng, 7, '.', ''),
                    'speed_kph'           => (string) random_int(0, 105),
                    'heading'             => random_int(0, 359),
                    'address'             => 'Hwy 1, BC',
                    'recorded_at'         => date('Y-m-d H:i:s', strtotime("-{$h} hours")),
                ]);
                $breadcrumbs++;
            } catch (\Throwable $e) { line("    ! breadcrumb skipped: " . $e->getMessage()); break; }
        }
    }
}
printf("  + %d distance-log rows, %d GPS breadcrumbs\n", $distLogs, $breadcrumbs);
$summary['distance_logs'] = $distLogs;
$summary['gps_breadcrumbs'] = $breadcrumbs;

// ════════════════════════════════════════════════════════════════════════════
// PHASE 7 — QBO SYNC STATE (additive; NO live Intuit call; real connection
// already green). Map a SUBSET of demo customers/invoices as pushed so the
// /quickbooks sync surfaces show our demo records synced. Leaves a few
// ff_only/pending → "partially synced". Touches NEITHER the real connection
// settings NOR the existing customer maps; CASCADE cleans these on --fresh.
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 7/8] QBO sync state (additive, offline)…");

$qboConn = db_row("SELECT `value` FROM settings WHERE `key` = 'quickbooks.connection_status'", []);
$qboRealm = (string) (db_row("SELECT `value` FROM settings WHERE `key` = 'quickbooks.realm_id'", [])['value'] ?? '');
$connStatus = (string) ($qboConn['value'] ?? 'disconnected');
line("  QBO connection: status='{$connStatus}', realm='{$qboRealm}' (left untouched)");

$qboCustMapped = 0; $qboInvMapped = 0;
if ($connStatus === 'connected') {
    // Map ~70% of ACTIVE demo customers (skip inactive/credit_hold + a couple to leave ff_only).
    $activeCustomerIds = ids(
        "SELECT id FROM customers WHERE internal_notes LIKE ? AND status='active' ORDER BY id",
        ['%' . DEMO_TAG . '%']
    );
    $mapCount = (int) ceil(count($activeCustomerIds) * 0.7);
    foreach (array_slice($activeCustomerIds, 0, $mapCount) as $cid) {
        $ts = date('Y-m-d H:i:s', strtotime('-' . random_int(1, 20) . ' days'));
        try {
            db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [$cid]);
            db_insert('acc_qbo_customer_map', [
                'ff_customer_id'    => $cid,
                'qbo_customer_id'   => 'DEMO-C-' . (900000 + $cid),
                'qbo_display_name'  => db_row("SELECT company_name FROM customers WHERE id=?", [$cid])['company_name'] ?? null,
                'mapping_status'    => 'mapped',
                'push_status'       => 'pushed',
                'match_confidence'  => 'exact',
                'last_synced_at'    => $ts,
                'pushed_at'         => $ts,
                'last_push_at'      => $ts,
                'created_by_user_id'=> DEMO_USER_ID,
            ]);
            $qboCustMapped++;
        } catch (\Throwable $e) { line("    ! qbo customer map skipped (#{$cid}): " . $e->getMessage()); }
    }

    // Map ~60% of demo invoices (those that are sent/paid/partial — i.e. issued) as pushed.
    $issuedInvoices = array_values(array_filter($invoiceRecords,
        fn($i) => in_array($i['finalStatus'] ?? 'draft', ['sent','paid','partially_paid','overdue'], true)));
    $invCap = (int) ceil(count($issuedInvoices) * 0.6);
    foreach (array_slice($issuedInvoices, 0, $invCap) as $inv) {
        $ts = date('Y-m-d H:i:s', strtotime($inv['periodEnd'] . ' +2 days'));
        try {
            db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?", [$inv['id']]);
            db_insert('acc_qbo_invoice_map', [
                'ff_invoice_id'             => $inv['id'],
                'qbo_invoice_id'            => 'DEMO-I-' . (900000 + $inv['id']),
                'qbo_doc_number'            => $inv['invoiceNumber'],
                'qbo_total_amt'             => money($inv['total']),
                'qbo_currency'              => $inv['currency'],
                'ff_invoice_snapshot_total' => money($inv['total']),
                'ff_engine_version'         => 'holistic',
                'push_status'               => 'pushed',
                'pushed_at'                 => $ts,
                'last_synced_at'            => $ts,
            ]);
            $qboInvMapped++;
        } catch (\Throwable $e) { line("    ! qbo invoice map skipped (#{$inv['id']}): " . $e->getMessage()); }
    }
    printf("  + %d demo customers + %d demo invoices marked synced (rest left ff_only/pending)\n", $qboCustMapped, $qboInvMapped);
    $qboOutcome = "SUCCEEDED — additive maps over the existing connected sandbox (realm {$qboRealm}); no Intuit call.";
} else {
    line("  QBO not connected locally — skipping additive maps (connect-screen path).");
    $qboOutcome = "FELL BACK — local QBO connection_status='{$connStatus}'; no maps written. Screenshot the connect screen.";
}
$summary['qbo_customer_maps'] = $qboCustMapped;
$summary['qbo_invoice_maps']  = $qboInvMapped;

// ════════════════════════════════════════════════════════════════════════════
// PHASE 8 — MANIFEST (hero record IDs + exact local URLs for the capture session)
// ════════════════════════════════════════════════════════════════════════════
line("\n[Phase 8/8] Manifest…");

// Resolve hero record ids.
$heroLeases = array_values(array_filter($leaseRecords, fn($l) => $l['hero'] !== null));
$heroBlocks = [];
foreach ($heroLeases as $hl) {
    $heroInvoices = array_values(array_filter($invoiceRecords, fn($i) => $i['leaseId'] === $hl['id']));
    $sampleInvoice = $heroInvoices[count($heroInvoices) - 1] ?? null;
    $heroBlocks[] = [
        'name'        => $hl['hero'],
        'leaseId'     => $hl['id'],
        'customerId'  => $hl['customerId'],
        'unitId'      => $hl['unit']['id'],
        'unitNumber'  => $hl['unit']['unit_number'],
        'invoiceId'   => $sampleInvoice['id'] ?? null,
        'invoiceNumber' => $sampleInvoice['invoiceNumber'] ?? null,
        'invoiceCount'=> count($heroInvoices),
    ];
}

$bu = static fn(string $p): string => base_url($p);
$generatedAt = date('Y-m-d H:i:s T');

$md  = "# FleetForge Demo Seed Manifest\n\n";
$md .= "> Generated by `scripts/seed_marketing_demo.php` (S-FF-DEMO-SEED) on {$generatedAt}.\n";
$md .= "> Dataset is **fully anonymized**, tagged `" . DEMO_TAG . "`, and lives ONLY on local `fleetforge.test`.\n";
$md .= "> Re-run `php scripts/seed_marketing_demo.php --fresh` to rebuild, or `--purge` to remove.\n\n";

$md .= "## Counts seeded\n\n";
foreach ($summary as $k => $v) {
    $md .= sprintf("- **%s**: %d\n", str_replace('_', ' ', ucfirst($k)), $v);
}
$md .= "\n";

$md .= "## Top-of-funnel pages (populated by this dataset)\n\n";
$md .= "| Page | URL |\n|------|-----|\n";
$md .= "| Dashboard (KPIs + trend) | " . $bu('dashboard') . " |\n";
$md .= "| Leases list | " . $bu('leases') . " |\n";
$md .= "| Invoices / billing | " . $bu('invoices') . " |\n";
$md .= "| Customers | " . $bu('customers') . " |\n";
$md .= "| Equipment / fleet | " . $bu('equipment') . " |\n";
$md .= "| Rate cards | " . $bu('rates') . " |\n";
$md .= "| QuickBooks sync | " . $bu('quickbooks/dashboard') . " |\n";
$md .= "| Accounting | " . $bu('accounting') . " |\n\n";

$md .= "## Hero records (the close-up screenshot stars)\n\n";
foreach ($heroBlocks as $h) {
    $md .= "### ★ {$h['name']}\n\n";
    $md .= "- Lease **#{$h['leaseId']}** — " . $bu('leases/show?id=' . $h['leaseId']) . "\n";
    $md .= "- Customer **#{$h['customerId']}** — " . $bu('customers/show?id=' . $h['customerId']) . "\n";
    $md .= "- Unit **{$h['unitNumber']}** (#{$h['unitId']}) — " . $bu('equipment/show?id=' . $h['unitId']) . "\n";
    if ($h['invoiceId']) {
        $md .= "- Sample invoice **{$h['invoiceNumber']}** (#{$h['invoiceId']}) — " . $bu('invoices/show?id=' . $h['invoiceId']) . "\n";
    }
    $md .= "- Invoices on this lease: {$h['invoiceCount']}\n\n";
}

// A few extra well-populated customers/invoices for variety in the capture set.
$md .= "## Sample anonymized customers\n\n";
foreach (array_slice($customerDefs, 0, 8) as $c) {
    $cid = $customerIds[$c[0]] ?? null;
    if ($cid) $md .= "- {$c[0]} (#{$cid}, {$c[8]}) — " . $bu('customers/show?id=' . $cid) . "\n";
}
$md .= "\n## QuickBooks integration\n\n- " . $qboOutcome . "\n";

$manifestPath = __DIR__ . '/../docs/DEMO_SEED_MANIFEST.md';
file_put_contents($manifestPath, $md);
line("  + wrote docs/DEMO_SEED_MANIFEST.md (" . count($heroBlocks) . " hero records)");

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════════════
hr();
line("  SEED COMPLETE");
hr();
foreach ($summary as $k => $v) printf("  %-22s %d\n", $k, $v);
$ar  = db_row("SELECT COALESCE(SUM(balance_due),0) t FROM invoices WHERE internal_notes LIKE ?", ['%' . DEMO_TAG . '%']);
$rev = db_row("SELECT COALESCE(SUM(amount_paid),0) t FROM invoices WHERE internal_notes LIKE ?", ['%' . DEMO_TAG . '%']);
printf("  %-22s \$%s\n", 'demo outstanding AR', number_format((float) $ar['t'], 2));
printf("  %-22s \$%s\n", 'demo collected', number_format((float) $rev['t'], 2));
hr();
line("  Manifest: docs/DEMO_SEED_MANIFEST.md");
line("  Purge:    php scripts/seed_marketing_demo.php --purge");
line("");
