<?php
declare(strict_types=1);

/**
 * tests/_smoke_account_automatch_manual_lock.php
 *
 * MEDIUM [01e] — account auto-match must not steal a manually-locked QBO account.
 *
 * accounts/auto_match.php skipped re-matching FF accounts the operator had
 * manually locked (keyed on ff_account_id), but a 'mapped' decision UPDATEs
 * `WHERE qbo_account_id = ?` — so a DIFFERENT, unlocked FF account matched to a
 * locked row's qbo_account_id overwrote (stole) that lock. Fixed: also skip any
 * decision whose qbo_account_id is held by a manual lock.
 *
 * Setup: a manual lock X → QBO-Q, and an unlocked FF account Y whose code equals
 * QBO-Q's account_number (so the matcher's exact_code tier routes Y → QBO-Q).
 * After auto-match, the QBO-Q mapping must still point at X.
 *
 * SAFETY: the whole acc_qbo_account_map is snapshotted and fully restored in
 * finally, so the 84 real local mappings are left exactly as they were.
 *
 * PRE-FIX  : the QBO-Q mapping is stolen by Y → FAIL.
 * POST-FIX : it still points at X.
 *
 * Run:  php tests/_smoke_account_automatch_manual_lock.php   Exit 0/1/2.
 *
 * @session MEDIUM-01e-ACCOUNT-AUTOMATCH-LOCK
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID  = getmypid();
$QBO  = "QBO-LOCK-{$PID}";
$CODE = "ZZ{$PID}";           // unique account_number / FF code → exact_code match

$harnessFile = sys_get_temp_dir() . '/_ff_am_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$sess=json_decode(base64_decode(\$argv[1]??''),true);
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/api/v1/quickbooks/accounts/auto_match.php';
PHP);

$snapshot = null; $xId = null; $yId = null;

$restore = static function () use (&$snapshot, &$xId, &$yId, $CODE) {
    // Restore acc_qbo_account_map exactly from the pre-test snapshot — inside a
    // transaction so a failed reinsert rolls back the DELETE (never lose the
    // real rows).
    if (is_array($snapshot)) {
        db_transaction(function () use ($snapshot) {
            db_execute("DELETE FROM acc_qbo_account_map", []);
            foreach ($snapshot as $row) { db_insert('acc_qbo_account_map', $row); }
        });
    }
    db_execute("DELETE FROM acc_accounts WHERE code IN (?, ?)", [$CODE . '-X', $CODE]);
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'AM Smoke', 'role_slug' => 'super_admin'];

    // Snapshot the ENTIRE map before touching anything.
    $snapshot = db_select("SELECT * FROM acc_qbo_account_map", []);

    // FF accounts: X (locked) and Y (unlocked, code == QBO-Q account_number).
    $xId = db_insert('acc_accounts', ['code' => $CODE . '-X', 'name' => "AM Lock X {$PID}", 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1, 'is_header' => 0]);
    $yId = db_insert('acc_accounts', ['code' => $CODE,        'name' => "AM Auto Y {$PID}", 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => 1, 'is_header' => 0]);

    // Manual lock: X → QBO-Q, with QBO account_number == Y's code.
    db_insert('acc_qbo_account_map', [
        'ff_account_id' => $xId, 'qbo_account_id' => $QBO, 'qbo_name' => "Locked QBO {$PID}",
        'qbo_account_number' => $CODE, 'qbo_account_type' => 'Asset',
        'mapping_status' => 'mapped', 'match_confidence' => 'manual',
    ]);

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [01e] ACCOUNT AUTO-MATCH LOCK — X#{$xId} locked→{$QBO}, Y#{$yId} code={$CODE}\n";
    echo str_repeat('─', 72) . "\n";

    // Run auto-match.
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');

    $owner = (int) (db_row("SELECT ff_account_id FROM acc_qbo_account_map WHERE qbo_account_id = ?", [$QBO])['ff_account_id'] ?? 0);
    if ($owner === $xId) {
        $pass("manual lock preserved — {$QBO} still mapped to X#{$xId} (Y did not steal it)");
    } else {
        $fail("manual lock STOLEN — {$QBO} now mapped to ff_account_id={$owner} (expected X#{$xId}" . ($owner === $yId ? "; stolen by Y" : "") . ")");
    }

} finally {
    echo "\n=== CLEANUP / RESTORE ===\n";
    $restore();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  restored acc_qbo_account_map (" . (is_array($snapshot) ? count($snapshot) : 0) . " rows) + removed test accounts\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("ACCOUNT AUTO-MATCH LOCK — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
