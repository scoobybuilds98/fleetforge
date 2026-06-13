<?php
declare(strict_types=1);

/**
 * tests/_smoke_bulk_close_tail_invoice.php
 *
 * MEDIUM [03] — bulk-close must not silently drop a lease's final billable period.
 *
 * bulk_close.php flips active leases to 'completed' WITHOUT generating the
 * partial_end final invoice that close.php produces, and the monthly cron only
 * bills 'active' leases — so the days between the last billed period and the
 * close date went unbilled (revenue loss). Fix: refuse a lease with an unbilled
 * tail (billing coverage doesn't reach today) and direct it to close.php, which
 * runs the full finalization (mirrors the existing precharge guard).
 *
 * Asserts (real endpoint, super_admin):
 *   1. lease with an unbilled tail (no covering invoice) → SKIPPED, stays active.
 *   2. lease already billed through today (invoice covers it) → completed.
 *
 * PRE-FIX  : case 1 is bulk-closed (status completed) → FAIL.
 * POST-FIX : case 1 skipped with an "unbilled final period" reason.
 *
 * Run:  php tests/_smoke_bulk_close_tail_invoice.php   Exit 0/1/2.
 *
 * @session MEDIUM-03-BULK-CLOSE-TAIL
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_bc_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfBcIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfBcIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfBcIn');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$sess = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$sess): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$custId = null; $leaseTail = null; $leaseCovered = null; $invId = null;
$cleanup = static function () use (&$custId, &$leaseTail, &$leaseCovered, &$invId) {
    if ($invId) { db_execute("DELETE FROM invoices WHERE id = ?", [$invId]); }
    foreach ([$leaseTail, $leaseCovered] as $l) {
        if ($l) { db_execute("DELETE FROM lease_status_log WHERE lease_id = ?", [$l]);
                  db_execute("DELETE FROM leases WHERE id = ?", [$l]); }
    }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
};
$mkLease = static function (int $cust, string $tag) use ($PID): int {
    return db_insert('leases', [
        'contract_number' => "BC-{$PID}-{$tag}", 'customer_id' => $cust, 'equipment_unit_id' => null,
        'company_name_snapshot' => 'BC Co', 'customer_name_snapshot' => 'BC',
        'status' => 'active', 'start_date' => '2026-05-01',
        'daily_rate' => '100.00', 'weekly_rate' => '600.00', 'monthly_rate' => '2000.00',
        'currency' => 'CAD', 'billing_cycle' => 'monthly', 'discount_type' => 'none', 'discount_value' => '0.0000',
        'mileage_rate' => '0.0000', 'mileage_unit' => 'km', 'total_invoiced' => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
    ]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'BC Smoke', 'role_slug' => 'super_admin'];

    $custId       = db_insert('customers', ['company_name' => "BC Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $leaseTail    = $mkLease($custId, 'tail');     // no invoice → unbilled tail
    $leaseCovered = $mkLease($custId, 'covered');  // invoice covering through year-end
    $invId = db_insert('invoices', [
        'invoice_number' => "INV-BC-{$PID}", 'customer_id' => $custId, 'lease_id' => $leaseCovered,
        'billing_period_start' => '2026-05-01', 'billing_period_end' => '2026-12-31', 'billing_period_days' => 245,
        'billing_type' => 'single_period', 'invoice_date' => '2026-05-01', 'due_date' => '2026-06-01',
        'status' => 'sent', 'currency' => 'CAD', 'total_amount' => '2000.00', 'amount_paid' => '0.00', 'balance_due' => '2000.00',
    ]);

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [03] BULK-CLOSE TAIL — tail lease #{$leaseTail}, covered lease #{$leaseCovered}\n";
    echo str_repeat('─', 72) . "\n";

    $post('api/v1/leases/bulk_close.php', ['ids' => [$leaseTail, $leaseCovered]]);

    $tailStatus    = db_row("SELECT status FROM leases WHERE id = ?", [$leaseTail])['status'] ?? '';
    $coveredStatus = db_row("SELECT status FROM leases WHERE id = ?", [$leaseCovered])['status'] ?? '';

    if ($tailStatus === 'active') {
        $pass("tail lease — SKIPPED, stays active (final period not silently dropped)");
    } else {
        $fail("tail lease — status now '{$tailStatus}' (pre-fix: bulk-closed to 'completed', tail unbilled)");
    }
    if ($coveredStatus === 'completed') {
        $pass("covered lease — bulk-closed to 'completed' (non-vacuous; no tail to bill)");
    } else {
        $fail("covered lease — status '{$coveredStatus}' (expected 'completed')");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed leases + invoice + customer\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("BULK-CLOSE TAIL — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
