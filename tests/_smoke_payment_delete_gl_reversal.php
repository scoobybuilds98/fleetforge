<?php
declare(strict_types=1);

/**
 * tests/_smoke_payment_delete_gl_reversal.php
 *
 * Wave 2 (Codex) — deleting a payment must reverse its GL journal entry.
 *
 * payments/delete.php reversed the operational counters (amount_paid /
 * leases.total_paid / customers.outstanding_balance) but had ZERO references to
 * the accounting layer, so the JE posted by onPaymentReceived (DR Cash / CR AR,
 * source_type='payment') stayed posted → the GL showed cash still received
 * after the payment was deleted (cash/AR overstated, subledger ≠ GL).
 *
 * This smoke seeds a payment with a POSTED payment JE (source_type='payment'),
 * drives the REAL delete endpoint (subprocess, super_admin + CSRF), and asserts
 * the JE was reversed: status='reversed', reversed_by_id set, and a 'reversing'
 * entry exists. Non-vacuous: the payment is soft-deleted.
 *
 * PRE-FIX  : JE stays 'posted', reversed_by_id NULL → FAIL.
 * POST-FIX : JE reversed + reversing entry created.
 *
 * All writes removed in finally.
 *
 * Run:  php tests/_smoke_payment_delete_gl_reversal.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session WAVE2-PAYMENT-DELETE-GL
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

$harnessFile = sys_get_temp_dir() . '/_ff_paydel_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$payload = base64_decode(\$argv[2] ?? '');
\$sess = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfPDInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfPDInput::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php', 'FfPDInput');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$payId = null;
$cleanup = static function () use (&$payId, $PID) {
    // Null the reversal self-FKs, then drop both the original + reversing JE.
    db_execute("UPDATE acc_journal_entries SET reversed_by_id=NULL, reversal_of_id=NULL
                 WHERE source_type='payment' AND source_id = ?", [$payId]);
    foreach (db_select("SELECT id FROM acc_journal_entries WHERE source_type='payment' AND source_id = ?", [$payId]) as $je) {
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [(int) $je['id']]);
        db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $je['id']]);
    }
    if ($payId) {
        db_execute("DELETE FROM payments WHERE id = ?", [$payId]);
    }
};

try {
    $admin = db_row("SELECT u.id,u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'PayDel Smoke', 'role_slug' => 'super_admin'];

    $custId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $accts  = db_select("SELECT id FROM acc_accounts WHERE is_active=1 AND is_header=0 ORDER BY id LIMIT 2", []);
    $cashAcct = (int) $accts[0]['id']; $arAcct = (int) $accts[1]['id'];
    $period   = db_row("SELECT id FROM acc_periods WHERE status='open' AND start_date<=CURDATE() AND end_date>=CURDATE() LIMIT 1")
             ?? db_row("SELECT id FROM acc_periods WHERE status='open' ORDER BY id LIMIT 1");
    if (!$period) { echo "SETUP FAIL no open period\n"; exit(2); }

    // Payment (no allocations needed — the GL reversal is independent).
    $payId = db_insert('payments', [
        'payment_number' => "PAY-GLDEL-{$PID}",
        'customer_id'    => $custId,
        'amount'         => '500.00',
        'currency'       => 'CAD',
        'payment_method' => 'cash',
        'payment_date'   => date('Y-m-d'),
        'status'         => 'cleared',
    ]);

    // Posted payment JE (DR Cash / CR AR), source_type='payment'.
    $jeId = db_insert('acc_journal_entries', [
        'entry_number' => "JE-GLDEL-{$PID}",
        'period_id'    => (int) $period['id'],
        'entry_date'   => date('Y-m-d'),
        'description'  => "Payment PAY-GLDEL-{$PID} received",
        'entry_type'   => 'system',
        'status'       => 'posted',
        'source_type'  => 'payment',
        'source_id'    => $payId,
    ]);
    db_insert('acc_journal_entry_lines', ['journal_entry_id' => $jeId, 'account_id' => $cashAcct, 'debit' => '500.00', 'credit' => '0.00']);
    db_insert('acc_journal_entry_lines', ['journal_entry_id' => $jeId, 'account_id' => $arAcct,   'debit' => '0.00', 'credit' => '500.00']);

    echo str_repeat('─', 72) . "\n";
    echo "PAYMENT DELETE GL REVERSAL — payment #{$payId}, JE #{$jeId}\n";
    echo str_repeat('─', 72) . "\n";

    $r = $post('api/v1/payments/delete.php', ['id' => $payId, 'reason' => 'gl reversal smoke']);

    $deleted = db_row("SELECT deleted_at FROM payments WHERE id = ?", [$payId]);
    if (($r['success'] ?? null) === true && ($deleted['deleted_at'] ?? null) !== null) {
        $pass("delete — payment soft-deleted (non-vacuous; endpoint ran)");
    } else {
        $fail("delete — payment not soft-deleted: resp=" . json_encode($r) . " deleted_at=" . json_encode($deleted['deleted_at'] ?? null));
    }

    $je = db_row("SELECT status, reversed_by_id FROM acc_journal_entries WHERE id = ?", [$jeId]);
    $reversingExists = (int) (db_row(
        "SELECT COUNT(*) c FROM acc_journal_entries
          WHERE entry_type='reversing' AND reversal_of_id = ?", [$jeId])['c'] ?? 0) > 0;
    if (($je['status'] ?? '') === 'reversed' && !empty($je['reversed_by_id']) && $reversingExists) {
        $pass("GL reversed — payment JE status='reversed', reversed_by_id set, reversing entry created");
    } else {
        $fail("GL NOT reversed — je.status=" . json_encode($je['status'] ?? null)
            . " reversed_by_id=" . json_encode($je['reversed_by_id'] ?? null)
            . " reversing_entry=" . ($reversingExists ? 'Y' : 'N') . " (pre-fix: stays posted → cash still on the books)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed payment + JEs\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("PAYMENT DELETE GL REVERSAL — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
