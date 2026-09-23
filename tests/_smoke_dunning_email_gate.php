<?php
declare(strict_types=1);

/**
 * tests/_smoke_dunning_email_gate.php
 *
 * I22 — dunning letters bypassed the Customer Emails gate. Both the manual
 * Collections "Generate & Send" (api/v1/accounting/ar/dunning_letter.php) and
 * the nightly digest (cron/notification_digest.php run_dunning_letters) called
 * Mailer::send() directly, ignoring the master switch and the do-not-email
 * list, and never logged to notification_log.
 *
 * Asserts:
 *   H  CustomerReminders::mayEmailCustomer() — master off / type off / global
 *      suppression / audience / bounced main email / recipient precedence.
 *   E  Real endpoint (CLI harness, fake super_admin session, everything inside
 *      ONE transaction that is rolled back at shutdown):
 *        E1 master OFF → letter generated, NOT emailed, reason given,
 *           recorded sent_method='mail' + sent_to_email NULL, no log row.
 *        E2 master ON (type OFF — manual ignores it) → emailed to
 *           invoice_email via deliver(): notification_log 'sent' row.
 *        E3 on the do-not-email list → NOT emailed.
 *        E4 main email bounced, no other address → NOT emailed.
 *        E5 Mailer refuses (address disabled) → email_sent=false + email_error.
 *        E6 sent_method=mail → no email attempted.
 *   D  run_dunning_letters() (in-process, transaction rolled back):
 *        D1 type OFF → no letters at all (early exit).
 *        D2 type ON, audience 'selected' → only the allowed customer gets a
 *           letter + logged email; the suppressed / bounced ones are skipped
 *           BEFORE generation (no acc_dunning_letters row).
 *
 * NO REAL EMAIL: aborts unless Mailer is in log mode (APP_ENV != production and
 * no AWS keys in settings or env); the harness also blanks the keys in-txn, and
 * every recipient is on the reserved .test TLD.
 *
 * Run:  php tests/_smoke_dunning_email_gate.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session I22
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Notifications\CustomerReminders;
use FleetForge\Storage\StorageClient;

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$check = static function (bool $ok, string $m) use ($pass, $fail): void { $ok ? $pass($m) : $fail($m); };

// ── Safety: refuse to run unless mail can only go to logs/mail.log ──────────
if (APP_ENV === 'production'
    || (string) settings_get('aws.access_key_id', '') !== ''
    || (string) env('AWS_ACCESS_KEY_ID', '') !== '') {
    echo "SETUP ABORT — Mailer would use SES (production or AWS keys set). Not risking a real email.\n";
    exit(2);
}

$PID = getmypid();
$pdfKeys = [];   // storage keys of generated PDFs (files are outside the txn)

// ── Child harness: runs a real endpoint inside one rolled-back transaction ──
// setup (PHP, b64) builds fixtures and returns vars; the payload's "{{name}}"
// placeholders are replaced from them; report (PHP, b64) runs in the shutdown
// function AFTER the endpoint exits and BEFORE the rollback.
$harnessFile = sys_get_temp_dir() . '/_ff_i22_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payloadT=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
\$setup=base64_decode(\$argv[4]??''); \$report=base64_decode(\$argv[5]??'');
// Force Mailer log-mode in THIS process too (belt and braces).
\$_ENV['AWS_ACCESS_KEY_ID']=''; \$_ENV['AWS_SECRET_ACCESS_KEY']=''; putenv('AWS_ACCESS_KEY_ID='); putenv('AWS_SECRET_ACCESS_KEY=');
class FfI22In {
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
db_execute("UPDATE settings SET `value`='' WHERE `key` IN ('aws.access_key_id','aws.secret_access_key')");
\$vars = (function() use (\$setup) { return eval(\$setup); })();
\$payload = preg_replace_callback('/"\\{\\{(\\w+)\\}\\}"/', fn(\$m) => json_encode(\$vars[\$m[1]] ?? null), \$payloadT);
register_shutdown_function(function() use (\$report, \$vars) {
    try { \$r = (function() use (\$report, \$vars) { return eval(\$report); })(); }
    catch (\\Throwable \$e) { \$r = ['report_error' => \$e->getMessage()]; }
    echo "\\n@@REPORT@@" . json_encode(\$r);
    if (db_pdo()->inTransaction()) { db_pdo()->rollBack(); }
});
FfI22In::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfI22In');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
$adminSess = ['id' => (int) $admin['id'], 'name' => 'I22 Admin', 'role_slug' => 'super_admin'];

/**
 * Run the endpoint in the child harness; returns [response, report].
 *
 * @return array{0:array,1:array}
 */
$run = static function (string $endpoint, array $payload, string $setup, string $report) use ($harnessFile, $adminSess): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSess)))
        . ' ' . escapeshellarg(base64_encode($setup))
        . ' ' . escapeshellarg(base64_encode($report)) . (getenv('FF_SMOKE_DEBUG') ? ' 2>&1' : ' 2>/dev/null'));
    if (getenv("FF_SMOKE_DEBUG")) { fwrite(STDERR, $out . "\n"); }
    $parts = explode('@@REPORT@@', $out, 2);
    $body  = $parts[0];
    $s = strpos($body, '{"success"');
    $resp = $s !== false ? json_decode(trim(substr($body, $s)), true) : null;
    $rep  = isset($parts[1]) ? json_decode(trim($parts[1]), true) : null;
    return [is_array($resp) ? $resp : ['_raw' => $body], is_array($rep) ? $rep : ['_raw' => $parts[1] ?? '']];
};

// Shared fixture builder (child-side PHP). $opts keys: master, suppress,
// email_disabled, invoice_email, billing_email, email.
$setupFor = static function (array $opts) use ($PID): string {
    $o = var_export($opts, true);
    return <<<PHP
\$o = {$o};
\$set = function (string \$k, string \$v) { db_execute(
    "INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`updated_at`) VALUES (?,?,'string','customer_notifications',NOW())
     ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [\$k, \$v]); };
\$set('customer_notifications.master_enabled', \$o['master'] ?? '1');
\$set('customer_notifications.dunning.enabled', '0');   // manual path must ignore the type toggle
\$set('customer_notifications.bcc', '');
\$cust = (int) db_insert('customers', [
    'company_name' => 'I22 Dunning Co {$PID}', 'currency' => 'CAD',
    'email' => \$o['email'] ?? 'main-{$PID}@example.test',
    'invoice_email' => \$o['invoice_email'] ?? null, 'billing_email' => \$o['billing_email'] ?? null,
    'email_disabled' => (int) (\$o['email_disabled'] ?? 0), 'outstanding_balance' => '500.00',
]);
db_insert('invoices', [
    'invoice_number' => 'INV-I22-{$PID}-' . \$cust, 'customer_id' => \$cust,
    'billing_period_start' => '2026-06-01', 'billing_period_end' => '2026-06-30',
    'billing_period_days' => 30, 'billing_type' => 'single_period',
    'invoice_date' => '2026-06-01', 'due_date' => '2026-06-30',
    'status' => 'sent', 'currency' => 'CAD',
    'total_amount' => '500.00', 'amount_paid' => '0.00', 'balance_due' => '500.00',
]);
if (!empty(\$o['suppress'])) {
    db_insert('customer_notification_audience', ['reminder_key' => '*', 'customer_id' => \$cust, 'mode' => 'exclude']);
}
return ['cust' => \$cust];
PHP;
};

$reportCode = <<<'PHP'
$l = db_row("SELECT id, sent_method, sent_to_email, pdf_path FROM acc_dunning_letters WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$vars['cust']]);
$n = db_select("SELECT recipient, status, notification_type, error_message FROM notification_log WHERE notification_type = 'customer_dunning_letter' AND entity_type = 'customer' AND entity_id = ?", [$vars['cust']]);
return ['letter' => $l, 'log' => $n];
PHP;

$EP = 'api/v1/accounting/ar/dunning_letter.php';

try {
    echo str_repeat('─', 72) . "\nI22 DUNNING EMAIL GATE\n" . str_repeat('─', 72) . "\n";

    // ── H: helper, in-process + rolled back ─────────────────────────────────
    db_execute('START TRANSACTION');
    try {
        $w = static fn(string $k, string $v) => db_execute(
            "INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`updated_at`) VALUES (?,?,'string','customer_notifications',NOW())
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k, $v]);
        $mk = static fn(array $c) => (int) db_insert('customers', $c + ['company_name' => "I22 H {$PID}", 'currency' => 'CAD']);
        $a = $mk(['email' => "a-{$PID}@example.test", 'billing_email' => "bill-{$PID}@example.test", 'invoice_email' => "inv-{$PID}@example.test"]);
        $b = $mk(['email' => "b-{$PID}@example.test", 'billing_email' => "bill-b-{$PID}@example.test"]);
        $c = $mk(['email' => "c-{$PID}@example.test", 'email_disabled' => 1]);
        $d = $mk(['email' => "d-{$PID}@example.test"]);
        db_insert('customer_notification_audience', ['reminder_key' => '*', 'customer_id' => $d, 'mode' => 'exclude']);

        $w('customer_notifications.master_enabled', '0');
        $r = CustomerReminders::mayEmailCustomer('dunning', $a, false);
        $check(!$r['ok'] && str_contains($r['reason'], 'master switch'), 'H1 master OFF blocks even a manual send: ' . $r['reason']);

        $w('customer_notifications.master_enabled', '1');
        $w('customer_notifications.dunning.enabled', '0');
        $r = CustomerReminders::mayEmailCustomer('dunning', $a, true);
        $check(!$r['ok'] && str_contains($r['reason'], 'switched off'), 'H2 type OFF blocks the automatic send: ' . $r['reason']);
        $r = CustomerReminders::mayEmailCustomer('dunning', $a, false);
        $check($r['ok'] && $r['to'] === "inv-{$PID}@example.test", 'H3 manual ignores type toggle; recipient = invoice_email (' . ($r['to'] ?? 'null') . ')');
        $r = CustomerReminders::mayEmailCustomer('dunning', $b, false);
        $check($r['ok'] && $r['to'] === "bill-b-{$PID}@example.test", 'H4 no invoice_email → billing_email (' . ($r['to'] ?? 'null') . ')');
        $r = CustomerReminders::mayEmailCustomer('dunning', $c, false);
        $check(!$r['ok'] && str_contains($r['reason'], 'bounce'), 'H5 bounced main email, no alternative → blocked: ' . $r['reason']);
        $r = CustomerReminders::mayEmailCustomer('dunning', $d, false);
        $check(!$r['ok'] && str_contains($r['reason'], 'do-not-email'), 'H6 global do-not-email list blocks a manual send: ' . $r['reason']);

        $w('customer_notifications.dunning.enabled', '1');
        $w('customer_notifications.dunning.audience_mode', 'selected');
        $r = CustomerReminders::mayEmailCustomer('dunning', $a, true);
        $check(!$r['ok'] && str_contains($r['reason'], 'audience'), 'H7 audience "selected" without include row blocks the automatic send');
        $r = CustomerReminders::mayEmailCustomer('dunning', $a, false);
        $check($r['ok'], 'H8 per-type audience does NOT block the manual send');
        $r = CustomerReminders::mayEmailCustomer('dunning', 999999999, false);
        $check(!$r['ok'] && $r['reason'] === 'Customer not found.', 'H9 unknown customer → blocked');
    } finally {
        db_execute('ROLLBACK');
    }

    // ── E: real endpoint via harness ────────────────────────────────────────
    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_60', 'sent_method' => 'email'], $setupFor(['master' => '0', 'invoice_email' => "e1-{$PID}@example.test"]), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $check(($resp['success'] ?? null) === true && ($d['email_sent'] ?? null) === false
        && str_contains((string) ($d['email_skipped_reason'] ?? ''), 'master switch')
        && ($rep['letter']['sent_method'] ?? '') === 'mail'
        && is_array($rep['letter'] ?? null) && array_key_exists('sent_to_email', $rep['letter']) && $rep['letter']['sent_to_email'] === null
        && ($rep['log'] ?? ['x']) === [],
        'E1 master OFF → letter generated (recorded mail, no recipient), NOT emailed, no log row; reason: ' . ($d['email_skipped_reason'] ?? json_encode($resp)));

    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_60', 'sent_method' => 'email'], $setupFor(['master' => '1', 'invoice_email' => "e2-{$PID}@example.test"]), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $log = $rep['log'][0] ?? [];
    $check(($d['email_sent'] ?? null) === true && ($d['email_to'] ?? '') === "e2-{$PID}@example.test"
        && ($rep['letter']['sent_method'] ?? '') === 'email' && ($rep['letter']['sent_to_email'] ?? '') === "e2-{$PID}@example.test"
        && ($log['status'] ?? '') === 'sent' && ($log['recipient'] ?? '') === "e2-{$PID}@example.test",
        'E2 master ON (type OFF, manual) → emailed to invoice_email via deliver(); notification_log sent row; letter records recipient');

    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_30', 'sent_method' => 'both'], $setupFor(['master' => '1', 'suppress' => true]), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $check(($d['email_sent'] ?? null) === false && str_contains((string) ($d['email_skipped_reason'] ?? ''), 'do-not-email')
        && ($rep['letter']['sent_method'] ?? '') === 'mail' && ($rep['log'] ?? ['x']) === [],
        'E3 do-not-email list → NOT emailed (sent_method both → recorded mail): ' . ($d['email_skipped_reason'] ?? json_encode($resp)));

    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_30', 'sent_method' => 'email'], $setupFor(['master' => '1', 'email_disabled' => 1]), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $check(($d['email_sent'] ?? null) === false && str_contains((string) ($d['email_skipped_reason'] ?? ''), 'bounce'),
        'E4 bounced main email, no alternative → NOT emailed: ' . ($d['email_skipped_reason'] ?? json_encode($resp)));

    // E5: invoice_email == the customer's own bounced main email → the gate
    // passes (invoice_email is its own column) but Mailer's email_disabled
    // check refuses → honest failure, logged 'failed'.
    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_30', 'sent_method' => 'email'], $setupFor(['master' => '1', 'email' => "e5-{$PID}@example.test", 'invoice_email' => "e5-{$PID}@example.test", 'email_disabled' => 1]), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $check(($resp['success'] ?? null) === true && ($d['email_sent'] ?? null) === false && !empty($d['email_error'])
        && (($rep['log'][0]['status'] ?? '') === 'failed'),
        'E5 Mailer refuses → email_sent=false + email_error reported; notification_log row status=failed');

    [$resp, $rep] = $run($EP, ['customer_id' => '{{cust}}', 'letter_type' => 'reminder_30', 'sent_method' => 'mail'], $setupFor(['master' => '1']), $reportCode);
    $d = $resp['data'] ?? [];
    if (!empty($rep['letter']['pdf_path'])) { $pdfKeys[] = $rep['letter']['pdf_path']; }
    $check(($d['email_requested'] ?? null) === false && ($d['email_sent'] ?? null) === false && ($rep['log'] ?? ['x']) === [],
        'E6 sent_method=mail → no email attempted, no log row');

    // ── D: nightly digest, in-process + rolled back ─────────────────────────
    if (!defined('FF_NOTIFICATION_DIGEST_INCLUDE')) {
        define('FF_NOTIFICATION_DIGEST_INCLUDE', true);
    }
    require_once $ROOT . '/cron/notification_digest.php';
    $_ENV['AWS_ACCESS_KEY_ID'] = ''; $_ENV['AWS_SECRET_ACCESS_KEY'] = '';
    db_execute('START TRANSACTION');
    try {
        db_execute("UPDATE settings SET `value`='' WHERE `key` IN ('aws.access_key_id','aws.secret_access_key')");
        $w = static fn(string $k, string $v) => db_execute(
            "INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`updated_at`) VALUES (?,?,'string','customer_notifications',NOW())
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k, $v]);
        $mkC = static function (string $tag, array $extra) use ($PID): int {
            $id = (int) db_insert('customers', $extra + ['company_name' => "I22 D {$tag} {$PID}", 'currency' => 'CAD', 'email' => "d{$tag}-{$PID}@example.test"]);
            db_insert('invoices', [
                'invoice_number' => "INV-I22D-{$PID}-{$tag}", 'customer_id' => $id,
                'billing_period_start' => '2026-06-01', 'billing_period_end' => '2026-06-30',
                'billing_period_days' => 30, 'billing_type' => 'single_period',
                'invoice_date' => '2026-06-01', 'due_date' => '2026-06-30',
                'status' => 'sent', 'currency' => 'CAD',
                'total_amount' => '400.00', 'amount_paid' => '0.00', 'balance_due' => '400.00',
            ]);
            // audience 'selected' below: include every test customer so the
            // audience is not what blocks B/C — suppression / bounce must.
            db_insert('customer_notification_audience', ['reminder_key' => 'dunning', 'customer_id' => $id, 'mode' => 'include']);
            return $id;
        };
        $okC   = $mkC('ok', []);
        $supC  = $mkC('sup', []);
        $bncC  = $mkC('bnc', ['email_disabled' => 1]);
        db_insert('customer_notification_audience', ['reminder_key' => '*', 'customer_id' => $supC, 'mode' => 'exclude']);
        $w('customer_notifications.master_enabled', '1');
        $w('customer_notifications.bcc', '');
        // 'selected' keeps every REAL dev customer out (no include row) so the
        // run only ever generates letters for the fixtures above.
        $w('customer_notifications.dunning.audience_mode', 'selected');
        $letters = static fn(int $cid) => (int) db_count("SELECT COUNT(*) FROM acc_dunning_letters WHERE customer_id = ?", [$cid]);

        $w('customer_notifications.dunning.enabled', '0');
        [$counts] = run_dunning_letters();
        $check(array_sum($counts) === 0 && $letters($okC) === 0, 'D1 "Dunning letters" OFF → digest generates/sends nothing (was: unconditional)');

        $w('customer_notifications.dunning.enabled', '1');
        [$counts, $skipped, $errors] = run_dunning_letters();
        foreach (db_select("SELECT pdf_path FROM acc_dunning_letters WHERE customer_id IN (?,?,?)", [$okC, $supC, $bncC]) as $row) {
            if (!empty($row['pdf_path'])) { $pdfKeys[] = $row['pdf_path']; }
        }
        $okLog = db_row("SELECT recipient, status FROM notification_log WHERE notification_type='customer_dunning_letter' AND entity_id = ?", [$okC]);
        $okLet = db_row("SELECT letter_type, sent_to_email FROM acc_dunning_letters WHERE customer_id = ?", [$okC]);
        $check($letters($okC) === 1 && ($okLog['status'] ?? '') === 'sent' && ($okLet['sent_to_email'] ?? '') === "dok-{$PID}@example.test",
            "D2a allowed customer → 1 letter ({$okLet['letter_type']}) + logged email to " . ($okLog['recipient'] ?? 'none'));
        $check($letters($supC) === 0 && $letters($bncC) === 0,
            'D2b do-not-email + bounced customers skipped BEFORE generation (no letter rows)');
        $check($counts['reminder_60'] >= 1 && $errors === 0, "D2c counts " . json_encode($counts) . " skipped={$skipped} errors={$errors}");
    } finally {
        db_execute('ROLLBACK');
    }
} finally {
    echo "\n=== CLEANUP ===\n";
    foreach (array_unique($pdfKeys) as $k) {
        try { StorageClient::delete($k); } catch (\Throwable) { /* best effort */ }
    }
    if (file_exists($harnessFile)) { @unlink($harnessFile); }
    echo '  all DB writes rolled back; removed ' . count(array_unique($pdfKeys)) . " generated PDF(s)\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("I22 DUNNING EMAIL GATE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
