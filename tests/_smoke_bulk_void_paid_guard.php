<?php
declare(strict_types=1);

/**
 * tests/_smoke_bulk_void_paid_guard.php
 *
 * MEDIUM [02] — bulk_void must not void paid/partially_paid invoices.
 *
 * bulk_void.php let a super_admin void ANY status, but the counter reversal only
 * un-books total_revenue when the prior status was 'sent' (decRevenue), so
 * voiding a 'paid' invoice left total_revenue overstated and never reversed the
 * payment allocations. Single void.php is safe (draft/sent only). Fixed: bulk
 * void is now draft/sent-only for everyone.
 *
 * Asserts (real endpoint, super_admin): a 'paid' invoice is SKIPPED (stays paid)
 * while a 'sent' invoice in the same call IS voided.
 *
 * PRE-FIX  : the paid invoice is voided → FAIL.
 * POST-FIX : paid skipped, sent voided.
 *
 * Run:  php tests/_smoke_bulk_void_paid_guard.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session MEDIUM-02-BULK-VOID-PAID
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_bv_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfBvIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfBvIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfBvIn');
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

$custId = null; $invIds = [];
$cleanup = static function () use (&$custId, &$invIds) {
    foreach ($invIds as $i) { db_execute("DELETE FROM invoices WHERE id = ?", [$i]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
};
$mkInvoice = static function (int $cust, string $status, string $total, string $bal, string $tag) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number' => "INV-BV-{$PID}-{$tag}", 'customer_id' => $cust,
        'billing_period_start' => '2026-06-01', 'billing_period_end' => '2026-06-30',
        'billing_period_days' => 30, 'billing_type' => 'single_period',
        'invoice_date' => '2026-06-15', 'due_date' => '2026-06-30',
        'status' => $status, 'currency' => 'CAD',
        'total_amount' => $total, 'amount_paid' => bcsub($total, $bal, 2), 'balance_due' => $bal,
    ]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'BV Smoke', 'role_slug' => 'super_admin'];

    $custId = db_insert('customers', ['company_name' => "BV Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '500.00', 'total_revenue' => '1500.00']);
    $invPaid = $mkInvoice($custId, 'paid', '1000.00', '0.00', 'paid'); $invIds[] = $invPaid;
    $invSent = $mkInvoice($custId, 'sent', '500.00', '500.00', 'sent'); $invIds[] = $invSent;

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [02] BULK_VOID PAID GUARD — paid #{$invPaid}, sent #{$invSent} (super_admin)\n";
    echo str_repeat('─', 72) . "\n";

    $post('api/v1/invoices/bulk_void.php', ['ids' => [$invPaid, $invSent], 'void_reason' => 'bv smoke']);

    $paidStatus = db_row("SELECT status FROM invoices WHERE id = ?", [$invPaid])['status'] ?? '';
    $sentStatus = db_row("SELECT status FROM invoices WHERE id = ?", [$invSent])['status'] ?? '';
    if ($paidStatus === 'paid') {
        $pass("paid invoice SKIPPED — status still 'paid' (not voided, revenue intact)");
    } else {
        $fail("paid invoice — status now '{$paidStatus}' (pre-fix: 'void' → total_revenue overstated, allocation orphaned)");
    }
    if ($sentStatus === 'void') {
        $pass("sent invoice voided in the same call (non-vacuous; endpoint works)");
    } else {
        $fail("sent invoice — status '{$sentStatus}' (expected 'void')");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed invoices + customer\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("BULK_VOID PAID GUARD — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
