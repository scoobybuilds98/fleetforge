<?php
// End-to-end API smoke test for fixed_assets / depreciation / capex endpoints.
// Uses cURL against the dev server at 127.0.0.1:7799 with a logged-in admin session.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$BASE = 'http://127.0.0.1:7799';
$COOKIES = sys_get_temp_dir() . '/ff_api_test_cookies.txt';
@unlink($COOKIES);

function check(string $label, bool $pass, string $extra = ''): void {
    $marker = $pass ? '[PASS]' : '[FAIL]';
    echo "  $marker  $label" . ($extra ? "  ($extra)" : '') . "\n";
}
function header_print(string $t): void { echo "\n========== $t ==========\n"; }

function curl_request(string $method, string $url, array $opts = []): array {
    global $COOKIES;
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if (isset($opts['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $opts['csrf'];
    }
    if (!empty($opts['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
    } elseif (!empty($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $COOKIES,
        CURLOPT_COOKIEFILE     => $COOKIES,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json   = json_decode((string) $body, true);
    return ['code' => $code, 'body' => $body, 'json' => $json];
}

// ============================================================
// 1. Login
// ============================================================
header_print('Login as admin@fleetforge.test');

// Step 1a: GET login page to obtain initial CSRF token
$loginPage = curl_request('GET', "$BASE/app/auth/login.php");
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', (string) $loginPage['body'], $m);
$loginCsrf = $m[1] ?? '';
echo "  initial CSRF token: " . substr($loginCsrf, 0, 12) . "...\n";
check('login page loaded', $loginPage['code'] === 200);
check('CSRF token extracted', !empty($loginCsrf));

// Step 1b: POST credentials (with form encoding, not JSON)
$loginPost = curl_request('POST', "$BASE/app/auth/login.php", [
    'form' => [
        'csrf_token' => $loginCsrf,
        'email'      => 'admin@fleetforge.test',
        'password'   => 'admin123',
    ],
]);
echo "  login response code: {$loginPost['code']}\n";
// Successful login redirects (302) to dashboard
check('login succeeded (302 redirect)', $loginPost['code'] === 302);

// Step 1c: CSRF token persists across the session — reuse the one from the login page.
// (The 302 from login confirms the session was established successfully.)
$csrf = $loginCsrf;
echo "  session CSRF (reused): " . substr($csrf, 0, 12) . "...\n";

// ============================================================
// 2. GET /api/v1/accounting/fixed_assets/index.php
// ============================================================
header_print('GET /api/v1/accounting/fixed_assets/');
$res = curl_request('GET', "$BASE/api/v1/accounting/fixed_assets/index.php?per_page=10", ['csrf' => $csrf]);
echo "  code={$res['code']}\n";
echo "  response: " . substr((string) $res['body'], 0, 200) . "...\n";
check('list returned 200', $res['code'] === 200);
check('payload has items array', isset($res['json']['data']['items']));
$initialCount = count($res['json']['data']['items'] ?? []);
echo "  asset count returned: $initialCount\n";

// ============================================================
// 3. GET /api/v1/accounting/fixed_assets/show.php?id=<existing>
// ============================================================
$ids = json_decode(file_get_contents(sys_get_temp_dir() . '/ff_test_assets.json'), true);
$asset3Id = $ids['asset3'];  // 2022 Trailer Fleet Unit #47 — active UOP

header_print("GET fixed_assets/show.php?id=$asset3Id (active asset 3 — 2022 Trailer)");
$res = curl_request('GET', "$BASE/api/v1/accounting/fixed_assets/show.php?id=$asset3Id", ['csrf' => $csrf]);
echo "  code={$res['code']}\n";
check('show returned 200', $res['code'] === 200);
check('asset data present', isset($res['json']['data']['asset']));
check('schedule present', isset($res['json']['data']['schedule']));
echo "  asset name: " . ($res['json']['data']['asset']['name'] ?? '?') . "\n";
echo "  schedule rows (UOP — empty without unit history): " . count($res['json']['data']['schedule'] ?? []) . "\n";

// Now check an impaired asset to confirm schedule is empty (correct behavior)
$asset1Id = $ids['asset1'];
$res = curl_request('GET', "$BASE/api/v1/accounting/fixed_assets/show.php?id=$asset1Id", ['csrf' => $csrf]);
check('impaired asset has 0 schedule rows', count($res['json']['data']['schedule'] ?? []) === 0);
echo "  asset 1 (impaired) status: " . ($res['json']['data']['asset']['status'] ?? '?') . "\n";

// ============================================================
// 4. POST fixed_assets/create.php — create a brand-new asset
// ============================================================
header_print('POST fixed_assets/create.php');
$accts = $ids['accounts'];
$res = curl_request('POST', "$BASE/api/v1/accounting/fixed_assets/create.php", [
    'csrf' => $csrf,
    'json' => [
        'name'                    => 'API Test Asset',
        'asset_class'             => 'office_equipment',
        'description'             => 'Created via API smoke test',
        'acquisition_date'        => '2026-04-15',
        'depreciation_start_date' => '2026-04-15',
        'acquisition_cost'        => '12000.00',
        'salvage_value'           => '1000.00',
        'useful_life_years'       => '4',
        'depreciation_method'     => 'straight_line',
        'asset_account_id'        => $accts['office_cost'],
        'accum_depr_account_id'   => $accts['office_accum'],
        'depr_expense_account_id' => $accts['depr_nonfleet'],
        'serial_number'           => 'API-TEST-001',
        'location'                => 'API Test Lab',
    ],
]);
echo "  code={$res['code']}\n";
echo "  response: " . substr((string) $res['body'], 0, 300) . "\n";
check('create returned 200/201', in_array($res['code'], [200, 201], true));
$apiAsset = $res['json']['data'] ?? null;
check('asset id assigned', !empty($apiAsset['id']));
check('asset_number assigned', !empty($apiAsset['asset_number']));
check('depreciable_cost = 11000.00', ($apiAsset['depreciable_cost'] ?? '') === '11000.00');
$apiAssetId = (int) ($apiAsset['id'] ?? 0);
$apiAssetUpdatedAt = $apiAsset['updated_at'] ?? null;

// ============================================================
// 5. POST update.php — D19 optimistic lock
// ============================================================
header_print('POST fixed_assets/update.php (optimistic lock OK path)');
$res = curl_request('POST', "$BASE/api/v1/accounting/fixed_assets/update.php", [
    'csrf' => $csrf,
    'json' => [
        'id'         => $apiAssetId,
        'updated_at' => $apiAssetUpdatedAt,
        'location'   => 'API Test Lab — Bay 2',
        'notes'      => 'Updated via API test',
    ],
]);
echo "  code={$res['code']}\n";
echo "  response: " . substr((string) $res['body'], 0, 200) . "\n";
check('update returned 200', $res['code'] === 200);
check('location updated', ($res['json']['data']['location'] ?? '') === 'API Test Lab — Bay 2');
$newUpdatedAt = $res['json']['data']['updated_at'] ?? null;

// ============================================================
// 6. POST update.php with stale updated_at — should 409
// ============================================================
header_print('POST update.php (stale lock — expect 409)');
$res = curl_request('POST', "$BASE/api/v1/accounting/fixed_assets/update.php", [
    'csrf' => $csrf,
    'json' => [
        'id'         => $apiAssetId,
        'updated_at' => $apiAssetUpdatedAt,  // intentionally stale
        'location'   => 'should fail',
    ],
]);
echo "  code={$res['code']}  body=" . substr((string) $res['body'], 0, 200) . "\n";
check('stale lock returns 409', $res['code'] === 409);
check('error code = STALE_DATA', ($res['json']['error']['code'] ?? '') === 'STALE_DATA');

// ============================================================
// 7. POST capex/create.php
// ============================================================
header_print('POST capex/create.php');
$res = curl_request('POST', "$BASE/api/v1/accounting/capex/create.php", [
    'csrf' => $csrf,
    'json' => [
        'title'         => 'API Test CapEx',
        'asset_class'   => 'office_equipment',
        'budget_amount' => '5000.00',
        'description'   => 'Test from API smoke test',
        'justification' => 'Required for API test coverage',
    ],
]);
echo "  code={$res['code']}\n";
echo "  response: " . substr((string) $res['body'], 0, 200) . "\n";
check('capex create returned 200/201', in_array($res['code'], [200, 201], true));
check('status = pending_approval', ($res['json']['data']['status'] ?? '') === 'pending_approval');
$capexId = (int) ($res['json']['data']['id'] ?? 0);

// ============================================================
// 8. POST capex/approve.php
// ============================================================
header_print("POST capex/approve.php (id=$capexId)");
$res = curl_request('POST', "$BASE/api/v1/accounting/capex/approve.php", [
    'csrf' => $csrf,
    'json' => ['id' => $capexId],
]);
echo "  code={$res['code']}\n";
echo "  response: " . substr((string) $res['body'], 0, 200) . "\n";
check('approve returned 200', $res['code'] === 200);
check('status = approved', ($res['json']['data']['status'] ?? '') === 'approved');

// ============================================================
// 9. GET capex/index.php (listing)
// ============================================================
header_print('GET capex/index.php');
$res = curl_request('GET', "$BASE/api/v1/accounting/capex/index.php", ['csrf' => $csrf]);
echo "  code={$res['code']}\n";
check('capex list returned 200', $res['code'] === 200);
check('capex has items', !empty($res['json']['data']['items']));
echo "  capex count: " . count($res['json']['data']['items'] ?? []) . "\n";

// ============================================================
// 10. GET depreciation/index.php
// ============================================================
header_print('GET depreciation/index.php');
$res = curl_request('GET', "$BASE/api/v1/accounting/depreciation/index.php", ['csrf' => $csrf]);
echo "  code={$res['code']}\n";
check('depreciation list returned 200', $res['code'] === 200);
echo "  runs: " . count($res['json']['data']['items'] ?? []) . "\n";

// ============================================================
// 11. Permission negative test — read_only role can't create
//   (skipped: would require a separate user; service-level role
//   guards already tested in test_disposal/impairment)
// ============================================================

echo "\n=== API ENDPOINT TESTS COMPLETE ===\n";
