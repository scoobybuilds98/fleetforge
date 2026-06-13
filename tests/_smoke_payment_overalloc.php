<?php
declare(strict_types=1);

/**
 * tests/_smoke_payment_overalloc.php
 *
 * WAVE 1 — the shared payment over-allocation invariant, across both the
 * customer (AR) and AP paths. Drives the REAL endpoints (subprocess, real
 * db.php + schema + CSRF + auth).
 *
 * Invariant under test:
 *   (a) Σ allocations for a payment ≤ the payment/bill total.
 *   (b) Σ per target ≤ the target's balance.
 *   (c) payment.customer_id == invoice.customer_id (no cross-customer apply).
 *
 * Cases:
 *   C4   customer over-allocation — allocate.php never bounds cumulative
 *        allocations against payments.amount. A $100 payment can be applied to
 *        invoice A AND invoice B (each 201). POST-FIX: 2nd call 422.
 *   XC   cross-customer apply — allocate.php loads payment.customer_id and
 *        invoice.customer_id but never compares them. Customer A's payment can
 *        be applied to Customer B's invoice. POST-FIX: 422.
 *   C3   AP duplicate bill_id — ap-payments/create.php validates each row vs the
 *        bill balance in a pre-loop, so the same bill listed twice passes both.
 *        POST-FIX: clean 422 duplicate-rejection; the bill is untouched.
 *
 * Non-vacuous: a legit single AR allocation and a legit single AP payment both
 * succeed (the guards don't over-block).
 *
 * Real writes (high-id AP scaffolding + tagged AR rows) removed in finally.
 *
 * Run:  php tests/_smoke_payment_overalloc.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session WAVE1-OVERALLOC (C3 + C4 + cross-customer)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';

if (!isset($_SESSION)) $_SESSION = [];

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// High id block for AP scaffolding (deleted in finally).
$BANK_GL = 999900; $BANK = 999900; $VEND = 999900; $BILL1 = 999901; $BILL2 = 999902;

// ── POST harness (super_admin session + CSRF + php://input) ──────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_overalloc_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfOAInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfOAInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfOAInput');
\$_SERVER['REQUEST_METHOD']    = 'POST';
\$_SERVER['CONTENT_TYPE']      = 'application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
\$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
\$_SERVER['HTTP_HOST']         = 'localhost';
@session_start();
\$_SESSION['csrf_token'] = 'smoketoken';
\$_SESSION['ff_user']    = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"');
    if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

// ── seed helpers ─────────────────────────────────────────────────────────────
$mkInvoice = static function (int $custId, string $balance, string $tag) use ($PID): int {
    return db_insert('invoices', [
        'invoice_number'       => "INV-OA-{$PID}-{$tag}",
        'customer_id'          => $custId,
        'lease_id'             => null,
        'billing_period_start' => '2026-06-01',
        'billing_period_end'   => '2026-06-30',
        'billing_period_days'  => 30,
        'billing_type'         => 'single_period',
        'invoice_date'         => '2026-06-01',
        'due_date'             => '2026-06-30',
        'status'               => 'sent',
        'currency'             => 'CAD',
        'total_amount'         => $balance,
        'amount_paid'          => '0.00',
        'balance_due'          => $balance,
    ]);
};
$mkPayment = static function (int $custId, string $amount, string $tag) use ($PID): int {
    return db_insert('payments', [
        'payment_number' => "PAY-OA-{$PID}-{$tag}",
        'customer_id'    => $custId,
        'amount'         => $amount,
        'currency'       => 'CAD',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-01',
        'status'         => 'cleared',
    ]);
};

$invIds = [];
$payIds = [];

$cleanup = static function () use (&$invIds, &$payIds, $BANK_GL, $BANK, $VEND, $BILL1, $BILL2, $PID) {
    foreach ($payIds as $pid) {
        db_execute("DELETE FROM payment_allocations WHERE payment_id = ?", [$pid]);
        db_execute("DELETE FROM payments WHERE id = ?", [$pid]);
    }
    foreach ($invIds as $iid) {
        db_execute("DELETE FROM payment_allocations WHERE invoice_id = ?", [$iid]);
        db_execute("DELETE FROM invoices WHERE id = ?", [$iid]);
    }
    // AP scaffolding + anything the endpoint created against it.
    $aps = db_select("SELECT id FROM acc_ap_payments WHERE vendor_id = ?", [$VEND]);
    foreach ($aps as $a) {
        db_execute("DELETE FROM acc_ap_payment_allocations WHERE ap_payment_id = ?", [(int) $a['id']]);
        db_execute("DELETE FROM acc_ap_payments WHERE id = ?", [(int) $a['id']]);
    }
    // Tear down any JE that touches the seeded bank GL (robust to orphans from a
    // prior aborted run) so the acc_journal_entry_lines→acc_accounts FK doesn't
    // block the GL-account delete. Deleting both lines of each such entry also
    // frees its AP-account (id 21) reference.
    $jeIds = array_column(
        db_select("SELECT DISTINCT journal_entry_id FROM acc_journal_entry_lines WHERE account_id = ?", [$BANK_GL]),
        'journal_entry_id'
    );
    foreach ($jeIds as $jeId) {
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [(int) $jeId]);
        db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $jeId]);
    }
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id IN (?, ?)", [$BILL1, $BILL2]);
    db_execute("DELETE FROM acc_bills WHERE id IN (?, ?)", [$BILL1, $BILL2]);
    db_execute("DELETE FROM acc_bank_accounts WHERE id = ?", [$BANK]);
    db_execute("DELETE FROM acc_accounts WHERE id = ?", [$BANK_GL]);
    db_execute("DELETE FROM vendors WHERE id = ?", [$VEND]);
    db_execute("DELETE FROM audit_log WHERE module IN ('payments','accounting') AND user_name = 'OA Smoke'");
};

try {
    $admin = db_row("SELECT u.id,u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'OA Smoke', 'role_slug' => 'super_admin'];

    $custs = db_select("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 2", []);
    if (count($custs) < 2) { echo "SETUP FAIL need 2 customers\n"; exit(2); }
    $custA = (int) $custs[0]['id'];
    $custB = (int) $custs[1]['id'];

    $cleanup();

    // AP scaffolding (open period for the JE date).
    $period = db_row("SELECT id, start_date FROM acc_periods WHERE status='open' ORDER BY id DESC LIMIT 1");
    if (!$period) { echo "SETUP FAIL no open period\n"; exit(2); }
    $payDate = $period['start_date'];

    db_execute("INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
                VALUES (?, '1010-OA', 'OA Bank GL', 'asset', 'current_asset', 0, 1)", [$BANK_GL]);
    db_execute("INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active)
                VALUES (?, 'OA Bank', 'checking', 'CAD', ?, 1)", [$BANK, $BANK_GL]);
    db_execute("INSERT INTO vendors (id, name, vendor_type) VALUES (?, 'OA Vendor', 'other')", [$VEND]);
    foreach ([$BILL1, $BILL2] as $bid) {
        db_execute(
            "INSERT INTO acc_bills (id, bill_number, vendor_id, vendor_bill_number, bill_date, due_date,
                                    period_id, status, currency, subtotal, tax_gst_amount, tax_pst_amount,
                                    tax_hst_amount, tax_total, total_amount, balance_due, amount_paid)
             VALUES (?, ?, ?, 'V-OA', ?, ?, ?, 'approved', 'CAD',
                     '500.00','0.00','0.00','0.00','0.00','500.00','500.00','0.00')",
            [$bid, "OA-BILL-{$bid}", $VEND, $payDate, $payDate, (int) $period['id']]
        );
    }

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 1 OVER-ALLOCATION — custA={$custA} custB={$custB} pid={$PID}\n";
    echo str_repeat('─', 72) . "\n";

    // ════════════════════════ C4 — customer over-allocation ════════════════════
    $invA = $mkInvoice($custA, '100.00', 'A'); $invIds[] = $invA;
    $invA2 = $mkInvoice($custA, '100.00', 'A2'); $invIds[] = $invA2;
    $payC4 = $mkPayment($custA, '100.00', 'C4'); $payIds[] = $payC4;

    $r1 = $post('api/v1/payments/allocate.php', ['payment_id' => $payC4, 'invoice_id' => $invA, 'amount' => '100.00']);
    if (($r1['success'] ?? null) === true) {
        $pass("N1 allocate — first \$100 allocation to invoice A succeeds (non-vacuous)");
    } else {
        $fail("N1 allocate — first allocation failed: " . json_encode($r1['error'] ?? $r1));
    }

    $r2 = $post('api/v1/payments/allocate.php', ['payment_id' => $payC4, 'invoice_id' => $invA2, 'amount' => '100.00']);
    $sumAlloc = db_row("SELECT COALESCE(SUM(amount),'0') s FROM payment_allocations WHERE payment_id=?", [$payC4])['s'];
    $boundOk = ($r2['success'] ?? null) === false && bccomp((string) $sumAlloc, '100.00', 2) <= 0;
    if ($boundOk) {
        $pass("C4 over-alloc — 2nd allocation REJECTED; Σ allocations ({$sumAlloc}) ≤ payment (100.00)");
    } else {
        $fail("C4 over-alloc — payment over-allocated: 2nd success=" . json_encode($r2['success'] ?? null)
            . " Σ=" . $sumAlloc . " (>100.00 = drift) err=" . json_encode($r2['error']['code'] ?? null));
    }

    // ════════════════════════ XC — cross-customer apply ════════════════════════
    $invB = $mkInvoice($custB, '100.00', 'B'); $invIds[] = $invB;
    $payXC = $mkPayment($custA, '100.00', 'XC'); $payIds[] = $payXC;  // customer A's money

    $rx = $post('api/v1/payments/allocate.php', ['payment_id' => $payXC, 'invoice_id' => $invB, 'amount' => '100.00']);
    $allocXc = (int) db_row("SELECT COUNT(*) c FROM payment_allocations WHERE payment_id=?", [$payXC])['c'];
    if (($rx['success'] ?? null) === false && $allocXc === 0) {
        $pass("XC cross-customer — custA payment to custB invoice REJECTED (code=" . ($rx['error']['code'] ?? '?') . ")");
    } else {
        $fail("XC cross-customer — custA payment applied to custB invoice: success="
            . json_encode($rx['success'] ?? null) . " allocations=" . $allocXc);
    }

    // ════════════════════════ C3 — AP duplicate bill_id ════════════════════════
    $rDup = $post('api/v1/accounting/ap-payments/create.php', [
        'vendor_id'       => $VEND,
        'bank_account_id' => $BANK,
        'payment_date'    => $payDate,
        'payment_method'  => 'eft',
        'reference_number'=> 'OA-DUP',
        'allocations'     => [
            ['bill_id' => $BILL1, 'amount_applied' => '500.00'],
            ['bill_id' => $BILL1, 'amount_applied' => '500.00'],
        ],
    ]);
    $bill1 = db_row("SELECT balance_due, amount_paid, status FROM acc_bills WHERE id=?", [$BILL1]);
    $billUntouched = bccomp((string) $bill1['balance_due'], '500.00', 2) === 0
                  && bccomp((string) $bill1['amount_paid'], '0.00', 2) === 0;
    // Pre-fix: NOT a clean duplicate rejection (either 201 success or a 500). Post-fix: success=false + bill untouched.
    $dupRejected = ($rDup['success'] ?? null) === false
        && in_array($rDup['error']['code'] ?? '', ['VALIDATION_ERROR', 'DUPLICATE_BILL', 'ALLOCATION_EXCEEDS_BALANCE'], true)
        && $billUntouched;
    if ($dupRejected) {
        $pass("C3 AP dup-bill — duplicate bill_id rejected cleanly; bill untouched (balance 500, paid 0)");
    } else {
        $fail("C3 AP dup-bill — not a clean rejection: success=" . json_encode($rDup['success'] ?? null)
            . " code=" . json_encode($rDup['error']['code'] ?? null)
            . " bill(balance={$bill1['balance_due']},paid={$bill1['amount_paid']},status={$bill1['status']})"
            . " msg=" . json_encode($rDup['error']['message'] ?? null));
    }

    // AP non-vacuous: a single valid allocation succeeds and pays the bill.
    $rOk = $post('api/v1/accounting/ap-payments/create.php', [
        'vendor_id'       => $VEND,
        'bank_account_id' => $BANK,
        'payment_date'    => $payDate,
        'payment_method'  => 'eft',
        'reference_number'=> 'OA-OK',
        'allocations'     => [['bill_id' => $BILL2, 'amount_applied' => '500.00']],
    ]);
    $bill2 = db_row("SELECT balance_due, status FROM acc_bills WHERE id=?", [$BILL2]);
    if (($rOk['success'] ?? null) === true && bccomp((string) $bill2['balance_due'], '0.00', 2) === 0) {
        $pass("N2 AP single — valid single-bill AP payment succeeds; bill paid (non-vacuous)");
    } else {
        $fail("N2 AP single — valid AP payment failed: success=" . json_encode($rOk['success'] ?? null)
            . " err=" . json_encode($rOk['error'] ?? null) . " bill2_balance={$bill2['balance_due']}");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  cleaned AR + AP smoke rows\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("WAVE 1 OVER-ALLOC — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
