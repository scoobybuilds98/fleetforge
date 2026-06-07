<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_payments_cca.php
 *
 * S-PORTAL-PAYMENTS-CCA smoke test — verifies the two new customer portal
 * sections render without fatal errors:
 *
 *   T1  portal/payments              — HTTP 200, no fatal
 *   T2  portal/credit-applications   — HTTP 200, no fatal
 *   T3  sidebar contains "Payments" nav link
 *   T4  sidebar contains "Credit Application" nav link
 *   T5  payments query is Trap-8-safe (customer_id filter present)
 *   T6  CCA query is Trap-8-safe (customer_id filter present)
 *   T7  No token_hash / signature_path / review_notes leaked in CCA page source
 *   T8  CCA page uses cca_badge() for status rendering (function present)
 *   T9  payments page source references initiate_qbo_payment endpoint
 *   T10 payments page contains portalPayBtn() Alpine component
 *
 * Strategy: manufactures a PHP portal session in /var/tmp (Herd's session dir)
 * using a real portal_user + customer from the DB, then GETs both pages with
 * cURL.  Uses a customer that exists; if no portal_user exists, test aborts
 * with SKIP rather than fail.
 *
 * Usage: php tests/_smoke_portal_payments_cca.php
 * Exit:  0 = all pass, 1 = any fail
 *
 * @session S-PORTAL-PAYMENTS-CCA
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$pass     = 0;
$failures = [];
$total    = 10;

function smoke_record(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $failures;
    if ($ok) {
        echo "PASS {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$label}" . ($detail ? ": {$detail}" : '') . "\n";
        $failures[] = $label;
    }
}

// ── Hermetic fixtures: create a temp customer + portal user ──────────────────
// WHY: dev DB has only one portal_user whose customer is suspended.
//      The portal auth gate rejects suspended customers → 302, not 200.
//      We create a minimal active customer + portal_user, run the HTTP tests,
//      and clean up in a shutdown function.
$tmpEmail    = '_smoke_pca_portal_' . bin2hex(random_bytes(4)) . '@fleetforge.test';
$tmpCustName = '_Smoke PCA Customer ' . bin2hex(random_bytes(3));

// Minimal customer row (required NOT NULL columns only)
$tmpCustId = db_insert('customers', [
    'company_name'        => $tmpCustName,
    'status'              => 'active',
    'outstanding_balance' => '0.00',
    'credit_limit'        => '0.00',
]);

// Minimal portal_user row
$tmpPuId = db_insert('portal_users', [
    'customer_id'  => $tmpCustId,
    'name'         => 'Smoke PCA User',
    'email'        => $tmpEmail,
    'password_hash'=> password_hash('SmokeOnly!99', PASSWORD_BCRYPT),
    'status'       => 'active',
    'is_primary'   => 1,
]);

$portalUser = [
    'id'           => $tmpPuId,
    'customer_id'  => $tmpCustId,
    'name'         => 'Smoke PCA User',
    'email'        => $tmpEmail,
    'is_primary'   => true,
    'company_name' => $tmpCustName,
];

register_shutdown_function(static function () use ($tmpCustId, $tmpPuId): void {
    // Purge the hermetic fixtures (hard-delete is safe: smoke-only rows)
    try {
        db_execute('DELETE FROM portal_users WHERE id = ?', [$tmpPuId]);
        db_execute('DELETE FROM customers    WHERE id = ?', [$tmpCustId]);
    } catch (Throwable) {}
});

// ── Build portal session file in /var/tmp (Herd FPM session dir) ─────────────
$tmpDir   = '/var/tmp';
$sessId   = bin2hex(random_bytes(16));   // pure hex — matches Herd's session ID format
$csrf     = bin2hex(random_bytes(32));
$sessFile = $tmpDir . '/sess_' . $sessId;

$sessionPayload  = '';
$sessionPayload .= 'ff_portal_user|' . serialize([
    'id'           => (int) $portalUser['id'],
    'customer_id'  => (int) $portalUser['customer_id'],
    'name'         => $portalUser['name'],
    'email'        => $portalUser['email'],
    'is_primary'   => (bool) $portalUser['is_primary'],
    'company_name' => $portalUser['company_name'] ?? '',
]);
$sessionPayload .= 'ff_portal_last_activity|' . serialize(time());
$sessionPayload .= 'csrf_token|'              . serialize($csrf);

file_put_contents($sessFile, $sessionPayload);
chmod($sessFile, 0600);

register_shutdown_function(static function () use ($sessFile): void {
    if (is_file($sessFile)) @unlink($sessFile);
});

// ── HTTP GET helper ───────────────────────────────────────────────────────────
$baseUrl = rtrim(APP_URL, '/') . '/' . ltrim(FF_BASE_PATH, '/');

/**
 * @return array{code:int, body:string, error:string}
 */
function smoke_get(string $url, string $sessId): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Cookie: ff_session=' . $sessId,
            'Accept: text/html',
        ],
    ]);
    $body  = (string) curl_exec($ch);
    $code  = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $error];
}

// ── Patterns that indicate a PHP fatal ───────────────────────────────────────
function has_fatal(string $body): bool
{
    $fatals = [
        'Fatal error',
        'Uncaught Error',
        'Uncaught TypeError',
        'Call to undefined',
        'Warning:',
        'Notice:',
        'Parse error',
    ];
    foreach ($fatals as $f) {
        if (str_contains($body, $f)) return true;
    }
    return false;
}

// ── T1: portal/payments renders 200, no fatal ────────────────────────────────
$r1 = smoke_get($baseUrl . '/portal/payments', $sessId);
$t1ok = ($r1['code'] === 200) && !has_fatal($r1['body']) && empty($r1['error']);
smoke_record(
    'T1 portal/payments HTTP 200 + no fatal',
    $t1ok,
    $t1ok ? '' : "HTTP {$r1['code']}, curl={$r1['error']}, " .
        (has_fatal($r1['body']) ? 'FATAL in body' : 'no fatal')
);

// ── T2: portal/credit-applications renders 200, no fatal ─────────────────────
$r2 = smoke_get($baseUrl . '/portal/credit-applications', $sessId);
$t2ok = ($r2['code'] === 200) && !has_fatal($r2['body']) && empty($r2['error']);
smoke_record(
    'T2 portal/credit-applications HTTP 200 + no fatal',
    $t2ok,
    $t2ok ? '' : "HTTP {$r2['code']}, curl={$r2['error']}, " .
        (has_fatal($r2['body']) ? 'FATAL in body' : 'no fatal')
);

// ── T3/T4: sidebar contains both new nav links ────────────────────────────────
// We read the sidebar source directly (static analysis) since it's
// pure PHP rendering — no JS-driven nav.
$sidebarSrc = (string) file_get_contents(FF_ROOT . '/app/portal/includes/sidebar.php');

smoke_record(
    'T3 sidebar has Payments nav item',
    str_contains($sidebarSrc, "'/portal/payments'") || str_contains($sidebarSrc, '"/portal/payments"'),
    'sidebar.php does not contain /portal/payments entry'
);
smoke_record(
    'T4 sidebar has Credit Application nav item',
    str_contains($sidebarSrc, "'/portal/credit-applications'") || str_contains($sidebarSrc, '"/portal/credit-applications"'),
    'sidebar.php does not contain /portal/credit-applications entry'
);

// ── T5: payments query is Trap-8 safe ────────────────────────────────────────
$pmtSrc = (string) file_get_contents(FF_ROOT . '/app/portal/payments/index.php');
smoke_record(
    'T5 payments/index.php Trap-8 (customer_id filter)',
    str_contains($pmtSrc, 'customer_id = ?') || str_contains($pmtSrc, 'customer_id=?'),
    'payments/index.php missing customer_id = ? Trap-8 filter'
);

// ── T6: CCA query is Trap-8 safe ─────────────────────────────────────────────
$ccaSrc = (string) file_get_contents(FF_ROOT . '/app/portal/credit-applications/index.php');
smoke_record(
    'T6 credit-applications/index.php Trap-8 (customer_id filter)',
    str_contains($ccaSrc, 'customer_id = ?') || str_contains($ccaSrc, 'customer_id=?'),
    'credit-applications/index.php missing customer_id = ? Trap-8 filter'
);

// ── T7: no privileged field VALUES leaked in rendered CCA output ──────────────
// WHY: The PHP source legitimately names these columns in SELECT queries to
// pull them from DB; what matters is they are NOT echoed to the browser.
// We audit the rendered body (if T2 passed) and verify the PHP source never
// calls e($app['<field>']) or echos the values directly.
$leakPatterns = ['token_hash', 'signature_path', 'review_notes', 'submitted_ip', 'submitted_user_agent'];
$leaks = [];
foreach ($leakPatterns as $lp) {
    // Source: the file must not echo any of these fields
    if (preg_match('/echo\s+.*\$app\s*\[\s*[\'"]' . preg_quote($lp, '/') . '[\'"]\s*\]/', $ccaSrc)) {
        $leaks[] = "source-echo:{$lp}";
    }
    if (preg_match('/e\s*\(\s*\$app\s*\[\s*[\'"]' . preg_quote($lp, '/') . '[\'"]\s*\]\s*\)/', $ccaSrc)) {
        $leaks[] = "source-e():{$lp}";
    }
    // Rendered body: field name should not appear as visible text
    if ($t2ok && str_contains($r2['body'], $lp)) {
        $leaks[] = "rendered-body:{$lp}";
    }
}
smoke_record(
    'T7 CCA page does not leak privileged fields in output',
    empty($leaks),
    'Leaked: ' . implode(', ', $leaks)
);

// ── T8: cca_badge() function present in CCA source ───────────────────────────
smoke_record(
    'T8 CCA page defines cca_badge() status helper',
    str_contains($ccaSrc, 'function cca_badge('),
    'cca_badge() not found in credit-applications/index.php'
);

// ── T9: payments page references the QBO payment initiation endpoint ─────────
smoke_record(
    'T9 payments/index.php references initiate_qbo_payment endpoint',
    str_contains($pmtSrc, 'initiate_qbo_payment'),
    'initiate_qbo_payment endpoint reference missing from payments/index.php'
);

// ── T10: payments page defines the portalPayBtn() Alpine component ────────────
smoke_record(
    'T10 payments/index.php defines portalPayBtn() Alpine component',
    str_contains($pmtSrc, 'function portalPayBtn('),
    'portalPayBtn() function not found in payments/index.php'
);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n{$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — FAIL: " . implode(', ', $failures);
}
echo "\n";
exit(empty($failures) ? 0 : 1);
