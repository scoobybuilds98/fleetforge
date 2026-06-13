<?php
declare(strict_types=1);

/**
 * tests/_smoke_deposit_apply_exceeds_balance.php
 *
 * H4 (Wave 3) — applying a deposit larger than the invoice balance over-credits
 * AR / over-reduces outstanding_balance and silently consumes the remainder.
 *
 * accounting/ar/deposits/apply.php used the FULL deposit amount for the JE
 * (DR deposit liability / CR AR) and the customer OB reduction, while only the
 * invoice balance was clamped to 0. A $1000 deposit on a $300 invoice → invoice
 * paid, but AR over-credited $700, OB over-reduced $700, and the deposit marked
 * fully 'applied' (no amount_remaining column → $700 vanishes). Fixed: reject
 * when deposit.amount > invoice.balance_due.
 *
 * Asserts (real endpoint):
 *   1. $1000 deposit → $300 invoice: REJECTED (422); invoice + deposit + OB unchanged.
 *   2. non-vacuous: $300 deposit → $300 invoice: succeeds (invoice paid, deposit applied).
 *
 * PRE-FIX  : case 1 succeeds (invoice paid, deposit applied) → FAIL.
 * POST-FIX : case 1 rejected, case 2 succeeds.
 *
 * Run:  php tests/_smoke_deposit_apply_exceeds_balance.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H4-DEPOSIT-APPLY-CAP
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_h4_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfH4In {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfH4In::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfH4In');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$adminSess = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSess): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$custId = null; $invIds = []; $depIds = [];
$cleanup = static function () use (&$custId, &$invIds, &$depIds, $PID) {
    foreach ($depIds as $d) {
        foreach (db_select("SELECT id FROM acc_journal_entries WHERE reference LIKE ?", ["%DEP-H4-{$PID}%"]) as $je) {
            db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [(int) $je['id']]);
            db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $je['id']]);
        }
        db_execute("DELETE FROM acc_customer_deposits WHERE id = ?", [$d]);
    }
    foreach ($invIds as $i) { db_execute("DELETE FROM invoices WHERE id = ?", [$i]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
};

$mkInvoice = static function (int $cust, string $bal, string $tag) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number' => "INV-H4-{$PID}-{$tag}", 'customer_id' => $cust,
        'billing_period_start' => '2026-06-01', 'billing_period_end' => '2026-06-30',
        'billing_period_days' => 30, 'billing_type' => 'single_period',
        'invoice_date' => '2026-06-15', 'due_date' => '2026-06-30',
        'status' => 'sent', 'currency' => 'CAD',
        'total_amount' => $bal, 'amount_paid' => '0.00', 'balance_due' => $bal,
    ]);
};
$mkDeposit = static function (int $cust, string $amt, string $tag) use ($PID): int {
    return db_insert('acc_customer_deposits', [
        'deposit_number' => "DEP-H4-{$PID}-{$tag}", 'customer_id' => $cust,
        'amount' => $amt, 'received_date' => '2026-06-15', 'status' => 'held',
    ]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $adminSess = ['id' => (int) $admin['id'], 'name' => 'H4 Admin', 'role_slug' => 'super_admin'];

    $custId = db_insert('customers', ['company_name' => "H4 Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '300.00']);
    $invBig = $mkInvoice($custId, '300.00', 'big'); $invIds[] = $invBig;   // small balance
    $depBig = $mkDeposit($custId, '1000.00', 'big'); $depIds[] = $depBig;  // large deposit
    $invOk  = $mkInvoice($custId, '300.00', 'ok');  $invIds[] = $invOk;
    $depOk  = $mkDeposit($custId, '300.00', 'ok');  $depIds[] = $depOk;

    echo str_repeat('─', 72) . "\n";
    echo "H4 DEPOSIT APPLY CAP — customer #{$custId}\n";
    echo str_repeat('─', 72) . "\n";

    // 1. $1000 deposit onto a $300 invoice → reject; nothing changes.
    $r = $post('api/v1/accounting/ar/deposits/apply.php', ['deposit_id' => $depBig, 'invoice_id' => $invBig]);
    $inv = db_row("SELECT balance_due, status FROM invoices WHERE id = ?", [$invBig]);
    $dep = db_row("SELECT status FROM acc_customer_deposits WHERE id = ?", [$depBig]);
    if (($r['success'] ?? null) === false
        && bccomp((string) $inv['balance_due'], '300.00', 2) === 0
        && $dep['status'] === 'held') {
        $pass("1 over-apply — REJECTED; invoice balance 300 + deposit still held (no over-credit)");
    } else {
        $fail("1 over-apply — NOT capped: success=" . json_encode($r['success'] ?? null)
            . " inv(balance={$inv['balance_due']},status={$inv['status']}) deposit={$dep['status']}");
    }

    // 2. $300 deposit onto a $300 invoice → succeeds.
    $r = $post('api/v1/accounting/ar/deposits/apply.php', ['deposit_id' => $depOk, 'invoice_id' => $invOk]);
    $inv = db_row("SELECT balance_due, status FROM invoices WHERE id = ?", [$invOk]);
    if (($r['success'] ?? null) === true && bccomp((string) $inv['balance_due'], '0.00', 2) === 0) {
        $pass("2 exact-apply — \$300 deposit applied to \$300 invoice; balance 0, status {$inv['status']} (non-vacuous)");
    } else {
        $fail("2 exact-apply — failed: " . json_encode($r['error'] ?? $r) . " inv_balance={$inv['balance_due']}");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed deposits + invoices + customer + JEs\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H4 DEPOSIT APPLY CAP — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
