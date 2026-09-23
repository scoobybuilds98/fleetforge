<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_sync_controls.php
 *
 * I20 / I23 — QuickBooks settings that crons + pushers read but had no screen
 * (bank CDC, drift check, refund receipt ids, per-entity sync modes). Verifies
 * api/v1/quickbooks/save_sync_controls.php and the "Sync & monitoring" card on
 * app/admin/quickbooks/settings.php.
 *
 * Asserts (real endpoint / page through a CLI harness with a fake session;
 * every DB write happens inside ONE transaction rolled back at shutdown):
 *   S1 valid payload (toggles, lookback, refund ids, credit_application /
 *      refund_receipt / invoice_writeoff modes) → saved + audit_log row.
 *   S2 unknown keys (sync_enabled, access_token, sync_mode.item) → 422, nothing written.
 *   S3 bad values (mode 'off', lookback 0/366/1.5, refund id 'abc',
 *      toggle 'true') → 422 with per-field errors.
 *   S4 non-super-admin → 403.
 *   P1 super_admin page shows the editable card incl. credit_application I23
 *      note, refund inputs, and no "settings table directly" instruction.
 *   P2 accountant sees the read-only sync-mode table, not the card.
 *
 * Run:  php tests/_smoke_qbo_sync_controls.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session I20 / I23
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass  = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail  = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$check = static function (bool $ok, string $m) use ($pass, $fail): void { $ok ? $pass($m) : $fail($m); };

$PID = getmypid();

// Child harness: POST (JSON body) or GET a page with a fake session, inside a
// transaction that the shutdown function reports on and then rolls back.
$harnessFile = sys_get_temp_dir() . '/_ff_i20_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$method=\$argv[1]??'POST'; \$target=\$argv[2]??''; \$payload=base64_decode(\$argv[3]??''); \$sess=json_decode(base64_decode(\$argv[4]??''),true);
class FfI20In {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
require '{$ROOT}/config/app.php';
db_pdo()->beginTransaction();
\$auditBefore = (int) db_count("SELECT COALESCE(MAX(id),0) FROM audit_log", []);
register_shutdown_function(function() use (\$auditBefore) {
    \$r = ['settings' => [], 'audit' => []];
    foreach (db_select("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'quickbooks.%'", []) as \$row) { \$r['settings'][\$row['key']] = \$row['value']; }
    \$r['audit'] = db_select("SELECT entity_type, notes FROM audit_log WHERE id > ? AND entity_type = 'qbo_sync_controls'", [\$auditBefore]);
    echo "\\n@@REPORT@@" . json_encode(\$r);
    if (db_pdo()->inTransaction()) { db_pdo()->rollBack(); }
});
FfI20In::set(\$payload);
if (\$method === 'POST') { stream_wrapper_unregister('php'); stream_wrapper_register('php','FfI20In'); }
\$_SERVER['REQUEST_METHOD']=\$method; \$_SERVER['CONTENT_TYPE']='application/json'; \$_SERVER['REQUEST_URI']='/fleetforge/quickbooks/settings';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$target;
PHP);

$run = static function (string $method, string $target, array $payload, array $sess) use ($harnessFile): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($target)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    $parts = explode('@@REPORT@@', $out, 2);
    $body  = $parts[0];
    $s     = strpos($body, '{"success"');
    $resp  = $s !== false ? json_decode(trim(substr($body, $s)), true) : null;
    $rep   = isset($parts[1]) ? json_decode(trim($parts[1]), true) : null;
    return [is_array($resp) ? $resp : ['_raw' => $body], is_array($rep) ? $rep : [], $body];
};

$userFor = static function (string $slug): ?array {
    $u = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id = u.role_id
                  WHERE ur.slug = ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1", [$slug]);
    return $u ? ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'role_slug' => $slug] : null;
};
$admin = $userFor('super_admin');
$acct  = $userFor('accountant');
if (!$admin || !$acct) { echo "SETUP FAIL need a super_admin + an accountant user\n"; exit(2); }
// can() reads permissions baked into the session at login — grant QuickBooks
// view so the accountant clears require_permission() and the super_admin
// check is what S4 / P2 exercise.
$acct['permissions'] = ['quickbooks' => ['view' => true]];

$EP = 'api/v1/quickbooks/save_sync_controls.php';

try {
    echo str_repeat('─', 72) . "\nI20/I23 QBO SYNC CONTROLS\n" . str_repeat('─', 72) . "\n";

    // S1 — valid save.
    $good = ['settings' => [
        'banking.cdc_enabled'           => '0',
        'banking.cdc_lookback_days'     => '45',
        'drift.enabled'                 => '0',
        'drift.gl_balance_enabled'      => '1',
        'refund.deposit_account_id'     => '38',
        'refund.payment_method_id'      => '2',
        'sync_mode.credit_application'  => 'disabled',
        'sync_mode.refund_receipt'      => 'qbo_to_ff',
        'sync_mode.invoice_writeoff'    => 'sync',
        'sync_mode.bill'                => 'queue',
    ]];
    [$r, $rep] = $run('POST', $EP, $good, $admin);
    $s = $rep['settings'] ?? [];
    $check(($r['success'] ?? null) === true
        && ($s['quickbooks.banking.cdc_enabled'] ?? '') === '0'
        && ($s['quickbooks.banking.cdc_lookback_days'] ?? '') === '45'
        && ($s['quickbooks.drift.enabled'] ?? '') === '0'
        && ($s['quickbooks.drift.gl_balance_enabled'] ?? '') === '1'
        && ($s['quickbooks.refund.deposit_account_id'] ?? '') === '38'
        && ($s['quickbooks.refund.payment_method_id'] ?? '') === '2'
        && ($s['quickbooks.sync_mode.credit_application'] ?? '') === 'disabled'
        && ($s['quickbooks.sync_mode.refund_receipt'] ?? '') === 'qbo_to_ff'
        && ($s['quickbooks.sync_mode.invoice_writeoff'] ?? '') === 'sync',
        'S1 valid payload saved (10 keys incl. credit_application / refund_receipt / invoice_writeoff): '
        . json_encode($r['data']['applied'] ?? $r));
    $check(count($rep['audit'] ?? []) === 1, 'S1 one audit_log row (entity_type qbo_sync_controls)');

    // S2 — unknown keys rejected, nothing written.
    [$r, $rep] = $run('POST', $EP, ['settings' => [
        'drift.enabled' => '0', 'sync_enabled' => '1', 'access_token' => 'x', 'sync_mode.item' => 'sync',
    ]], $admin);
    $f = $r['error']['fields'] ?? [];
    $check(($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        && isset($f['sync_enabled'], $f['access_token'], $f['sync_mode.item']) && !isset($f['drift.enabled'])
        && ($rep['audit'] ?? ['x']) === [],
        'S2 unknown keys → 422 per field (' . implode(', ', array_keys($f)) . '), nothing written');

    // S3 — bad values.
    foreach ([
        ['sync_mode.invoice', 'off'], ['sync_mode.invoice', 'inherit_je'],
        ['banking.cdc_lookback_days', '0'], ['banking.cdc_lookback_days', '366'], ['banking.cdc_lookback_days', '1.5'],
        ['refund.deposit_account_id', 'abc'], ['drift.enabled', 'true'],
    ] as [$k, $v]) {
        [$r] = $run('POST', $EP, ['settings' => [$k => $v]], $admin);
        $check(($r['success'] ?? null) === false && isset($r['error']['fields'][$k]),
            "S3 {$k}='{$v}' rejected: " . ($r['error']['fields'][$k] ?? json_encode($r)));
    }
    [$r] = $run('POST', $EP, ['settings' => []], $admin);
    $check(($r['success'] ?? null) === false, 'S3 empty settings → 422');

    // S4 — non-super-admin.
    [$r] = $run('POST', $EP, $good, $acct);
    $check(($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'FORBIDDEN'
        && str_contains((string) ($r['error']['message'] ?? ''), 'super_admin'),
        'S4 accountant with quickbooks.view → FORBIDDEN by the super_admin gate: ' . ($r['error']['message'] ?? json_encode($r)));

    // P1/P2 — page render.
    [, , $html] = $run('GET', 'app/admin/quickbooks/settings.php', [], $admin);
    $check(str_contains($html, 'Sync &amp; monitoring')
        && str_contains($html, 'syncCtl.modes.credit_application')
        && str_contains($html, 'syncCtl.modes.refund_receipt')
        && str_contains($html, 'syncCtl.modes.invoice_writeoff')
        && str_contains($html, 'QuickBooks-side applications are NOT copied back')
        && str_contains($html, 'id="qbo-refund-account"') && str_contains($html, 'id="qbo-refund-method"')
        && !str_contains($html, 'in the settings table directly'),
        'P1 super_admin page renders the editable Sync & monitoring card (' . strlen($html) . ' bytes)');
    [, , $html] = $run('GET', 'app/admin/quickbooks/settings.php', [], $acct);
    $check(str_contains($html, 'Per-Record-Type Sync (read-only)') && !str_contains($html, 'saveSyncControls()"'),
        'P2 accountant sees the read-only table, not the editable card (' . strlen($html) . ' bytes)');
} finally {
    if (file_exists($harnessFile)) { @unlink($harnessFile); }
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("I20/I23 QBO SYNC CONTROLS — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
