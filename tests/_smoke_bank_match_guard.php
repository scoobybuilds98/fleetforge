<?php
declare(strict_types=1);

/**
 * tests/_smoke_bank_match_guard.php
 *
 * MEDIUM [01b] — bank-transaction match guards.
 *   - matched_id is REQUIRED for payment/ap_payment/journal_entry (was optional
 *     → a 'payment' match could be saved pointing at nothing).
 *   - DOUBLE-MATCH guard: a payment may reconcile to at most one bank txn (was
 *     unguarded → the same cash event reconciled twice).
 *
 * Asserts (real endpoint, super_admin):
 *   1. match txn1 → payment P succeeds.
 *   2. match txn2 → the SAME payment P is rejected (409 ALREADY_MATCHED).
 *   3. match with matched_type=payment + no matched_id is rejected (422).
 *
 * PRE-FIX  : cases 2 and 3 succeed → FAIL.
 * POST-FIX : 2 rejected ALREADY_MATCHED, 3 rejected validation.
 *
 * Run:  php tests/_smoke_bank_match_guard.php   Exit 0 pass / 1 fail / 2 setup.
 *
 * @session MEDIUM-01b-BANK-MATCH
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$GL = 999700; $BANK = 999700;
$custId = $payId = null; $txnIds = [];

$harnessFile = sys_get_temp_dir() . '/_ff_bm_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfBmIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfBmIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfBmIn');
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

$cleanup = static function () use (&$custId, &$payId, &$txnIds, $GL, $BANK) {
    foreach ($txnIds as $t) { db_execute("DELETE FROM acc_bank_transactions WHERE id = ?", [$t]); }
    if ($payId)  { db_execute("DELETE FROM payments WHERE id = ?", [$payId]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    db_execute("DELETE FROM acc_bank_accounts WHERE id = ?", [$BANK]);
    db_execute("DELETE FROM acc_accounts WHERE id = ?", [$GL]);
};

$mkTxn = static function (int $bank, string $tag) use ($PID): int {
    return db_insert('acc_bank_transactions', [
        'bank_account_id' => $bank, 'transaction_date' => '2026-06-15',
        'description' => "BM smoke {$tag}", 'amount' => '500.00',
        'transaction_type' => 'deposit', 'status' => 'unmatched',
    ]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'BM Smoke', 'role_slug' => 'super_admin'];
    $cleanup();

    db_execute("INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
                VALUES (?, '1010-BM', 'BM Bank GL', 'asset', 'current_asset', 0, 1)", [$GL]);
    db_execute("INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active)
                VALUES (?, 'BM Bank', 'checking', 'CAD', ?, 1)", [$BANK, $GL]);
    $custId = db_insert('customers', ['company_name' => "BM Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $payId  = db_insert('payments', ['payment_number' => "PAY-BM-{$PID}", 'customer_id' => $custId, 'amount' => '500.00', 'currency' => 'CAD', 'payment_method' => 'cash', 'payment_date' => '2026-06-15', 'status' => 'cleared']);
    $txn1 = $mkTxn($BANK, 't1'); $txnIds[] = $txn1;
    $txn2 = $mkTxn($BANK, 't2'); $txnIds[] = $txn2;

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [01b] BANK MATCH GUARD — payment #{$payId}, txns #{$txn1}/#{$txn2}\n";
    echo str_repeat('─', 72) . "\n";

    // 1. txn1 → payment
    $r = $post('api/v1/accounting/bank-transactions/match.php', ['id' => $txn1, 'matched_type' => 'payment', 'matched_id' => $payId]);
    if (($r['success'] ?? null) === true) $pass("1 first match — txn1 → payment succeeds (non-vacuous)");
    else $fail("1 first match failed: " . json_encode($r['error'] ?? $r));

    // 2. txn2 → same payment → 409 ALREADY_MATCHED
    $r = $post('api/v1/accounting/bank-transactions/match.php', ['id' => $txn2, 'matched_type' => 'payment', 'matched_id' => $payId]);
    $t2status = db_row("SELECT status FROM acc_bank_transactions WHERE id = ?", [$txn2])['status'] ?? '';
    if (($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'ALREADY_MATCHED' && $t2status !== 'matched') {
        $pass("2 double-match — second txn to the same payment REJECTED (txn2 still {$t2status})");
    } else {
        $fail("2 double-match — not guarded: " . json_encode($r) . " txn2_status={$t2status}");
    }

    // 3. payment match with no matched_id → 422
    $r = $post('api/v1/accounting/bank-transactions/match.php', ['id' => $txn2, 'matched_type' => 'payment']);
    if (($r['success'] ?? null) === false && stripos(json_encode($r['error'] ?? []), 'matched_id') !== false) {
        $pass("3 missing matched_id — payment match without an id REJECTED");
    } else {
        $fail("3 missing matched_id — not required: " . json_encode($r));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed bank txns + payment + accounts\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("BANK MATCH GUARD — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
