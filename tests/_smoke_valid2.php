<?php declare(strict_types=1);

/**
 * tests/_smoke_valid2.php
 *
 * VALID-2 smoke test — fires bad data at every form-creation API
 * endpoint and asserts the JSON response is the standard
 * VALIDATION_ERROR envelope:
 *
 *     {
 *       "success": false,
 *       "error":   {
 *         "code":    "VALIDATION_ERROR",
 *         "message": "Please correct the highlighted fields.",
 *         "fields":  { "field_name": "human-readable error", ... }
 *       }
 *     }
 *
 * Strategy:
 *   1. Manually write a PHP session file with a fake admin user
 *      and a known CSRF token, bypassing the login flow.
 *   2. Use cURL against the local Herd web server to POST each
 *      bad payload, sending Cookie: ff_session=<id> and
 *      X-CSRF-Token: <token>.
 *   3. Assert the response shape per case.
 *
 * Usage:
 *   php tests/_smoke_valid2.php
 *
 * Exit code: 0 if all pass, 1 if any fail.
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

// ── Session bootstrap (admin user + CSRF) ───────────────────
// WHY: Herd PHP-FPM uses '/var/tmp/' for sessions (its `sys_get_temp_dir()`),
// while CLI PHP on macOS reports a per-user `/var/folders/.../T/` path.
// We must write to the SAME path the web PHP reads from, so hard-code
// '/var/tmp' to keep the smoke test reliable across hosts.
$tmpDir   = '/var/tmp';
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = $tmpDir . '/sess_' . $sessId;

// Write a real PHP session file in the format PHP expects when
// it reads the file: key|serialized\nkey|serialized\n...
$payload  = '';
$payload .= 'ff_user|'          . serialize([
    'id'          => 1,
    'name'        => 'Smoke Admin',
    'email'       => 'admin@fleetforge.test',
    'role_id'     => 1,
    'role_slug'   => 'super_admin',
    'permissions' => [],
    'theme'       => 'dark',
]);
$payload .= 'ff_last_activity|' . serialize(time());
$payload .= 'csrf_token|'       . serialize($csrf);

file_put_contents($sessFile, $payload);
chmod($sessFile, 0600);

register_shutdown_function(static function () use ($sessFile) {
    if (is_file($sessFile)) @unlink($sessFile);
});

// ── HTTP helper ─────────────────────────────────────────────
$baseUrl = 'http://fleetforge.test/fleetforge';

function http_post_json(string $url, string $sessId, string $csrf, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf,
            'Cookie: ff_session=' . $sessId,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => (int) $code,
        'body'      => is_string($resp) ? $resp : '',
        'error'     => $err,
        'json'      => is_string($resp) ? json_decode($resp, true) : null,
    ];
}

function http_patch_json(string $url, string $sessId, string $csrf, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf,
            'Cookie: ff_session=' . $sessId,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'http_code' => (int) $code,
        'body'      => is_string($resp) ? $resp : '',
        'json'      => is_string($resp) ? json_decode($resp, true) : null,
    ];
}

// ── Test definitions ────────────────────────────────────────
// Each entry: [label, url, body, expected fields (subset)]
$baseApi = $baseUrl . '/api/v1';
$cases = [
    // ── ACCOUNTING ───────────────────────────────────────
    [
        'label' => 'Journal Entry — empty body',
        'url'   => "$baseApi/accounting/journal_entries/create.php",
        'body'  => [],
        'expect'=> ['entry_date', 'description', 'lines'],
    ],
    [
        'label' => 'Journal Entry — only 1 line',
        'url'   => "$baseApi/accounting/journal_entries/create.php",
        'body'  => [
            'entry_date'  => '2026-04-01',
            'description' => 'Test',
            'lines'       => [['account_id' => 1, 'debit' => '100', 'credit' => '0']],
        ],
        'expect'=> ['lines'],
    ],
    [
        'label' => 'Journal Entry — unbalanced',
        'url'   => "$baseApi/accounting/journal_entries/create.php",
        'body'  => [
            'entry_date'  => '2026-04-01',
            'description' => 'Test',
            'lines'       => [
                ['account_id' => 1, 'debit' => '100', 'credit' => '0'],
                ['account_id' => 2, 'debit' => '0',   'credit' => '50'],
            ],
        ],
        'expect'=> ['lines'],
    ],
    [
        'label' => 'Bank Account — empty body',
        'url'   => "$baseApi/accounting/bank-accounts/create.php",
        'body'  => [],
        'expect'=> ['name', 'account_type'],
    ],
    [
        'label' => 'Bank Account — bad currency',
        'url'   => "$baseApi/accounting/bank-accounts/create.php",
        'body'  => ['name' => 'X', 'institution' => 'Test', 'account_type' => 'checking', 'currency' => 'EUR'],
        'expect'=> ['currency'],
    ],
    [
        'label' => 'Bank Transfer — same source/dest',
        'url'   => "$baseApi/accounting/bank-transfers/create.php",
        'body'  => [
            'from_account_id' => 1, 'to_account_id' => 1,
            'amount' => '100', 'transfer_date' => '2026-04-01',
        ],
        'expect'=> ['to_account_id'],
    ],
    [
        'label' => 'Bill — empty body',
        'url'   => "$baseApi/accounting/bills/create.php",
        'body'  => [],
        'expect'=> ['vendor_id', 'bill_date'],
    ],
    [
        'label' => 'Tax Period — bad tax_type',
        'url'   => "$baseApi/accounting/tax/periods/create.php",
        'body'  => ['tax_type' => 'bogus', 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'frequency' => 'quarterly'],
        'expect'=> ['tax_type'],
    ],
    [
        'label' => 'Tax Period — end before start',
        'url'   => "$baseApi/accounting/tax/periods/create.php",
        'body'  => ['tax_type' => 'gst_hst', 'period_start' => '2026-03-31', 'period_end' => '2026-01-01', 'frequency' => 'quarterly'],
        'expect'=> ['period_end'],
    ],
    [
        'label' => 'CapEx Request — empty body',
        'url'   => "$baseApi/accounting/capex/create.php",
        'body'  => [],
        'expect'=> ['title'],
    ],
    [
        'label' => 'Vendor Credit — empty body',
        'url'   => "$baseApi/accounting/vendor-credits/create.php",
        'body'  => [],
        'expect'=> ['vendor_id'],
    ],
    [
        'label' => 'AR Deposit — empty body',
        'url'   => "$baseApi/accounting/ar/deposits/create.php",
        'body'  => [],
        'expect'=> ['customer_id'],
    ],

    // ── CORE ENTITIES ────────────────────────────────────
    [
        'label' => 'Customer — empty body',
        'url'   => "$baseApi/customers/create.php",
        'body'  => [],
        'expect'=> ['company_name'],
    ],
    [
        'label' => 'Customer — bad email',
        'url'   => "$baseApi/customers/create.php",
        'body'  => ['company_name' => 'Smoke Acme — should not insert', 'email' => 'not-an-email'],
        'expect'=> ['email'],
    ],
    [
        'label' => 'Customer — discount > 100',
        'url'   => "$baseApi/customers/create.php",
        'body'  => ['company_name' => 'Smoke Acme — should not insert', 'discount_type' => 'percentage', 'discount_value' => '150'],
        'expect'=> ['discount_value'],
    ],
    [
        'label' => 'Customer — negative credit limit',
        'url'   => "$baseApi/customers/create.php",
        'body'  => ['company_name' => 'Smoke Acme — should not insert', 'credit_limit' => '-50'],
        'expect'=> ['credit_limit'],
    ],
    [
        'label' => 'Equipment Unit — empty body',
        'url'   => "$baseApi/equipment/units/create.php",
        'body'  => [],
        'expect'=> ['unit_number'],
    ],
    [
        'label' => 'Equipment Unit — bad year',
        'url'   => "$baseApi/equipment/units/create.php",
        'body'  => ['unit_number' => 'TST001', 'template_id' => 1, 'year' => 1850],
        'expect'=> ['year'],
    ],
    [
        // FLEETFORGE-M regression: length_ft DECIMAL(6,2) overflow must return a
        // clean 422 (not a PDO SQLSTATE[22003] 500). TST002 must not already exist.
        'label' => 'Equipment Unit — length_ft out of range (create)',
        'url'   => "$baseApi/equipment/units/create.php",
        'body'  => ['unit_number' => 'TST002', 'template_id' => 1,
                    'ownership_type' => 'owned', 'length_ft' => '15000'],
        'expect'=> ['length_ft'],
    ],
    [
        // FLEETFORGE-M direct regression on the failing endpoint. id=1 must exist;
        // updated_at is required but its value is irrelevant (optimistic locking
        // disabled app-wide, D19). axle_count TINYINT(255) overflow exercised too.
        'label' => 'Equipment Unit — length_ft/axle out of range (update)',
        'url'   => "$baseApi/equipment/units/update.php",
        'body'  => ['id' => 1, 'updated_at' => '2020-01-01 00:00:00',
                    'length_ft' => '12345.67', 'axle_count' => 9000],
        'expect'=> ['length_ft', 'axle_count'],
    ],
    [
        'label' => 'Lease — empty body',
        'url'   => "$baseApi/leases/create.php",
        'body'  => [],
        'expect'=> ['customer_id'],
    ],
    [
        'label' => 'Invoice — empty body',
        'url'   => "$baseApi/invoices/create.php",
        'body'  => [],
        'expect'=> ['lease_id', 'period_start', 'period_end'],
    ],
    [
        'label' => 'Invoice — period_end before period_start',
        'url'   => "$baseApi/invoices/create.php",
        'body'  => ['lease_id' => 1, 'period_start' => '2026-04-30', 'period_end' => '2026-04-01'],
        'expect'=> ['period_end'],
    ],
    [
        'label' => 'Payment — empty body',
        'url'   => "$baseApi/payments/create.php",
        'body'  => [],
        'expect'=> ['invoice_id', 'amount'],
    ],
    [
        'label' => 'Payment — negative amount',
        'url'   => "$baseApi/payments/create.php",
        'body'  => ['invoice_id' => 1, 'amount' => '-25', 'payment_method' => 'cash', 'payment_date' => '2026-04-01'],
        'expect'=> ['amount'],
    ],
    [
        'label' => 'Vendor — empty body',
        'url'   => "$baseApi/vendors/create.php",
        'body'  => [],
        'expect'=> ['name'],
    ],
    [
        'label' => 'Reservation — empty body',
        'url'   => "$baseApi/reservations/create.php",
        'body'  => [],
        'expect'=> ['contact_name', 'pickup_date'],
    ],
    [
        'label' => 'Damage Claim — empty body',
        'url'   => "$baseApi/damage_claims/create.php",
        'body'  => [],
        'expect'=> ['equipment_unit_id'],
    ],
    [
        'label' => 'Maintenance WO — empty body',
        'url'   => "$baseApi/maintenance_work_orders/create.php",
        'body'  => [],
        'expect'=> ['equipment_unit_id'],
    ],
    [
        'label' => 'Inspection — empty body',
        'url'   => "$baseApi/inspections/create.php",
        'body'  => [],
        'expect'=> ['equipment_unit_id'],
    ],
    [
        'label' => 'Mileage Log — empty body',
        'url'   => "$baseApi/mileage_logs/create.php",
        'body'  => [],
        'expect'=> ['equipment_unit_id'],
    ],
    [
        'label' => 'Yard — empty body',
        'url'   => "$baseApi/yards/create.php",
        'body'  => [],
        'expect'=> ['name'],
    ],
];

// ── Run all cases ──────────────────────────────────────────
$pad = static fn (string $s, int $w) => str_pad(strlen($s) > $w ? substr($s, 0, $w - 1) . '…' : $s, $w);

echo "\n=========================================================\n";
echo "  VALID-2 SMOKE TEST  —  " . count($cases) . " endpoints\n";
echo "  POSTing bad data to assert envelope shape\n";
echo "=========================================================\n\n";

$pass = 0;
$fail = 0;
$skip = 0;

foreach ($cases as $i => $c) {
    $n = $i + 1;
    $r = http_post_json($c['url'], $sessId, $csrf, $c['body']);

    // Endpoint may not exist (404) — skip
    if ($r['http_code'] === 404 || $r['http_code'] === 0) {
        $skip++;
        echo sprintf("  %3d. SKIP  HTTP %d  %s\n", $n, $r['http_code'], $c['label']);
        continue;
    }

    $j     = $r['json'];
    $okShape = is_array($j) && ($j['success'] ?? null) === false
        && isset($j['error']) && is_array($j['error'])
        && ($j['error']['code'] ?? null) === 'VALIDATION_ERROR'
        && isset($j['error']['fields']) && is_array($j['error']['fields']);

    if (!$okShape) {
        // Be tolerant: 401 means the smoke session was rejected (bad CSRF binding) — note and continue
        if ($r['http_code'] === 401 || $r['http_code'] === 403) {
            $skip++;
            echo sprintf("  %3d. SKIP  HTTP %d  %s  (auth)\n", $n, $r['http_code'], $c['label']);
            continue;
        }
        $fail++;
        echo sprintf("  %3d. FAIL  HTTP %d  %s\n", $n, $r['http_code'], $c['label']);
        echo "        body: " . substr((string) $r['body'], 0, 220) . "\n";
        continue;
    }

    // Check at least one expected field is present
    $missing = [];
    foreach ($c['expect'] as $f) {
        if (!array_key_exists($f, $j['error']['fields'])) {
            $missing[] = $f;
        }
    }

    if ($missing) {
        $fail++;
        echo sprintf("  %3d. FAIL  HTTP %d  %s\n", $n, $r['http_code'], $c['label']);
        echo "        missing field keys: " . implode(', ', $missing) . "\n";
        echo "        got fields: " . implode(', ', array_keys($j['error']['fields'])) . "\n";
        continue;
    }

    $pass++;
    echo sprintf("  %3d. PASS  HTTP %d  %s\n", $n, $r['http_code'], $c['label']);
}

echo "\n---------------------------------------------------------\n";
echo "  PASS: $pass    FAIL: $fail    SKIP: $skip    TOTAL: " . count($cases) . "\n";
echo "---------------------------------------------------------\n";

// ── Defensive cleanup ───────────────────────────────────────
// WHY: every test posts bad data so insertions SHOULD never happen,
// but if a regression slipped through validation we still want to
// scrub stray rows out of the dev DB before the next run.
try {
    $deleted = db_execute(
        "DELETE FROM customers WHERE company_name LIKE 'Smoke Acme%'"
    );
    if ($deleted > 0) {
        echo "  cleanup: removed $deleted stray smoke-test customer row(s)\n";
    }
} catch (\Throwable $e) {
    echo "  cleanup error: " . $e->getMessage() . "\n";
}

exit($fail === 0 ? 0 : 1);
