<?php
declare(strict_types=1);

/**
 * tests/_smoke_report_multicurrency.php
 *
 * H1 (Wave 2) — reports aggregate money in CAD (operator policy 2026-06-13).
 *
 * Every money SUM/AVG in reports/dashboard/analytics summed CAD and USD rows at
 * face value (1 USD treated as 1 CAD). Fixed by converting USD via
 * exchange_rate_to_cad in each aggregate (ReportBuilder::cad() canonical rule).
 *
 * Seeds an isolated far-future period (2099) with one CAD invoice ($1000) and
 * one USD invoice ($1000 @ rate 1.37), then invokes the REAL reports/revenue.php
 * and asserts gross_revenue == 2370.00 (= 1000 CAD + 1000 × 1.37), not the
 * currency-blind raw 2000.00.
 *
 * PRE-FIX  : gross_revenue = 2000.00 → FAIL.
 * POST-FIX : 2370.00.
 *
 * Run:  php tests/_smoke_report_multicurrency.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H1-REPORT-CAD
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

$harnessFile = sys_get_temp_dir() . '/_ff_mc_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$qs = \$argv[2] ?? '';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'.\$endpoint.'?'.\$qs;
\$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user'] = ['id'=>1,'role_slug'=>'super_admin'];
require '{$ROOT}/' . \$endpoint;
PHP);
$invoke = static function (string $endpoint, string $qs) use ($harnessFile): ?array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null');
    if (!is_string($out)) return null;
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : null;
};
$findGross = static function (array $a) use (&$findGross) {
    foreach ($a as $k => $v) {
        if ($k === 'gross_revenue') return (string) $v;
        if (is_array($v)) { $r = $findGross($v); if ($r !== null) return $r; }
    }
    return null;
};

$mkInvoice = static function (int $cust, string $currency, string $amount, ?string $rate, string $tag) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number'       => "INV-MC-{$PID}-{$tag}",
        'customer_id'          => $cust,
        'billing_period_start' => '2099-06-01',
        'billing_period_end'   => '2099-06-30',
        'billing_period_days'  => 30,
        'billing_type'         => 'single_period',
        'invoice_date'         => '2099-06-15',
        'due_date'             => '2099-07-15',
        'status'               => 'sent',
        'currency'             => $currency,
        'exchange_rate_to_cad' => $rate,
        'subtotal_after_discount' => $amount,
        'total_amount'         => $amount,
        'amount_paid'          => '0.00',
        'balance_due'          => $amount,
    ]);
};

$cleanup = static function () use (&$custId, &$invIds) {
    foreach ($invIds as $iid) { db_execute("DELETE FROM invoices WHERE id = ?", [$iid]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'revenue_%'");
};

try {
    $custId   = db_insert('customers', ['company_name' => "H1 MC Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $invIds[] = $mkInvoice($custId, 'CAD', '1000.00', '1.000000', 'cad');
    $invIds[] = $mkInvoice($custId, 'USD', '1000.00', '1.370000', 'usd');
    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'revenue_%'");

    echo str_repeat('─', 72) . "\n";
    echo "H1 REPORT MULTICURRENCY — customer #{$custId}, isolated 2099 period (CAD 1000 + USD 1000 @ 1.37)\n";
    echo str_repeat('─', 72) . "\n";

    $r = $invoke('api/v1/reports/revenue.php', 'preset=custom&date_from=2099-01-01&date_to=2099-12-31');
    if ($r === null || ($r['success'] ?? null) !== true) {
        $fail("revenue.php — no/invalid response: " . json_encode($r));
    } else {
        $gross = $findGross($r['data'] ?? $r);
        if ($gross !== null && bccomp($gross, '2370.00', 2) === 0) {
            $pass("revenue.php gross_revenue = 2370.00 — USD converted (1000 CAD + 1000 × 1.37)");
        } else {
            $fail("revenue.php gross_revenue = " . json_encode($gross) . " (expected 2370.00; pre-fix 2000.00 = currency-blind raw sum)");
        }
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed H1 customer + invoices; cleared revenue cache\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H1 REPORT MULTICURRENCY — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
