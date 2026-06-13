<?php
declare(strict_types=1);

/**
 * tests/_smoke_revenue_written_off.php
 *
 * H2 (Wave 2) — written_off invoices are NOT revenue (operator policy locked
 * 2026-06-13). reports/revenue.php, reports/customer.php, reports/fleet.php and
 * analytics excluded only ('draft','void') → they counted written_off as
 * revenue, while the dashboard charts excluded it → two different revenue
 * numbers for the same period. Fixed by adding 'written_off' to those
 * exclusion lists so every surface agrees.
 *
 * This smoke seeds an isolated FAR-FUTURE period (2099, where no other invoices
 * exist) with one 'sent' ($1000) and one 'written_off' ($500) invoice, then
 * invokes the REAL reports/revenue.php for that range and asserts
 * gross_revenue == 1000.00 (the written_off $500 is excluded).
 *
 * PRE-FIX  : gross_revenue = 1500.00 (written_off counted) → FAIL.
 * POST-FIX : 1000.00.
 *
 * Also a static guard: no revenue surface still excludes only ('draft','void').
 *
 * Run:  php tests/_smoke_revenue_written_off.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H2-WRITTEN-OFF-REVENUE
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$custId = null;
$invIds = [];

// GET harness (super_admin session) — invoke a report endpoint, return decoded JSON.
$harnessFile = sys_get_temp_dir() . '/_ff_wo_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$qs = \$argv[2] ?? '';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'.\$endpoint.'?'.\$qs;
\$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start();
\$_SESSION['ff_user'] = ['id'=>1,'role_slug'=>'super_admin'];
require '{$ROOT}/' . \$endpoint;
PHP);
$invoke = static function (string $endpoint, string $qs) use ($harnessFile): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return null;
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : null;
};
// Recursively find the first 'gross_revenue' value in the response.
$findGross = static function (array $a) use (&$findGross) {
    foreach ($a as $k => $v) {
        if ($k === 'gross_revenue') return (string) $v;
        if (is_array($v)) { $r = $findGross($v); if ($r !== null) return $r; }
    }
    return null;
};

$mkInvoice = static function (int $cust, string $status, string $amount, string $tag) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number'       => "INV-H2-{$PID}-{$tag}",
        'customer_id'          => $cust,
        'billing_period_start' => '2099-06-01',
        'billing_period_end'   => '2099-06-30',
        'billing_period_days'  => 30,
        'billing_type'         => 'single_period',
        'invoice_date'         => '2099-06-15',
        'due_date'             => '2099-07-15',
        'status'               => $status,
        'currency'             => 'CAD',
        'subtotal_after_discount' => $amount,
        'total_amount'         => $amount,
        'amount_paid'          => '0.00',
        'balance_due'          => $status === 'written_off' ? '0.00' : $amount,
    ]);
};

$cleanup = static function () use (&$custId, &$invIds) {
    foreach ($invIds as $iid) { db_execute("DELETE FROM invoices WHERE id = ?", [$iid]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'revenue_%'");
};

try {
    // ── Static guard: no revenue surface excludes only ('draft','void') ──────
    $offenders = [];
    foreach (['api/v1/reports/revenue.php','api/v1/reports/customer.php','api/v1/reports/fleet.php','api/v1/analytics/index.php'] as $f) {
        $src = (string) file_get_contents("{$ROOT}/{$f}");
        if (preg_match("/NOT IN \('draft', ?'void'\)|NOT IN \('void', ?'draft'\)/", $src)) {
            $offenders[] = $f;
        }
    }
    if ($offenders === []) {
        $pass("static — no revenue surface excludes only ('draft','void'); written_off is in every list");
    } else {
        $fail("static — these still omit written_off from the revenue exclusion: " . implode(', ', $offenders));
    }

    $custId = db_insert('customers', ['company_name' => "H2 WO Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $invIds[] = $mkInvoice($custId, 'sent', '1000.00', 'sent');
    $invIds[] = $mkInvoice($custId, 'written_off', '500.00', 'wo');

    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'revenue_%'");

    echo str_repeat('─', 72) . "\n";
    echo "H2 WRITTEN-OFF REVENUE — customer #{$custId}, isolated 2099 period\n";
    echo str_repeat('─', 72) . "\n";

    $r = $invoke('api/v1/reports/revenue.php', 'preset=custom&date_from=2099-01-01&date_to=2099-12-31');
    if ($r === null || ($r['success'] ?? null) !== true) {
        $fail("revenue.php — no/invalid response: " . json_encode($r));
    } else {
        $gross = $findGross($r['data'] ?? $r);
        if ($gross !== null && bccomp($gross, '1000.00', 2) === 0) {
            $pass("revenue.php gross_revenue = 1000.00 — written_off \$500 excluded (sent \$1000 counted)");
        } else {
            $fail("revenue.php gross_revenue = " . json_encode($gross) . " (expected 1000.00; pre-fix 1500.00 counted the written_off)");
        }
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed H2 customer + invoices; cleared revenue cache\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H2 WRITTEN-OFF REVENUE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
