<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_webhook_reprocess.php
 *
 * MEDIUM [01e] — QBO payment webhook must reprocess a previously-FAILED event.
 *
 * The idempotency check treated ANY existing acc_qbo_webhook_events row as
 * "duplicate → 200, skip". Intuit re-delivers failed webhooks, so a redelivery
 * of an event whose first attempt errored was dropped → a payment recorded in
 * QBO stayed unpaid in FF forever. Fixed: skip only when the prior attempt
 * succeeded (or is in-flight: processing_result NULL); reprocess 'error' rows
 * (reusing the existing row, no duplicate INSERT).
 *
 * Drives the REAL webhook endpoint (computes a valid Intuit signature against a
 * test verifier token; webhook_event_id via the intuit header).
 *
 * Asserts:
 *   1. redelivery of an 'error' event → 'received' (reprocessed).
 *   2. redelivery of a succeeded event → 'duplicate' (still skipped; non-regression).
 *
 * PRE-FIX  : case 1 → 'duplicate' → FAIL.
 * POST-FIX : 'received'.
 *
 * Run:  php tests/_smoke_qbo_webhook_reprocess.php   Exit 0 pass / 1 fail / 2 setup.
 *
 * @session MEDIUM-01e-QBO-WEBHOOK-REPROCESS
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\QboPushers\QboWebhookSignature;

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID   = getmypid();
$TOKEN = 'smoke-verifier-' . $PID;
$body  = json_encode(['eventNotifications' => [[
    'realmId'         => 'SMOKE_REALM_NO_MATCH',  // won't match connected realm → handler skips after the echo
    'dataChangeEvent' => ['entities' => [['name' => 'Payment', 'id' => '999000', 'operation' => 'Create']]],
]]]);
$sig = QboWebhookSignature::compute($body, $TOKEN);

// ── harness: POST the webhook with Intuit signature + event-id headers ──
$harnessFile = sys_get_temp_dir() . '/_ff_qbowh_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$body=base64_decode(\$argv[1]??''); \$sig=\$argv[2]??''; \$eventId=\$argv[3]??'';
class FfWhIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfWhIn::set(\$body);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfWhIn');
\$_SERVER['REQUEST_METHOD']='POST';
\$_SERVER['HTTP_INTUIT_SIGNATURE']=\$sig;
\$_SERVER['HTTP_INTUIT_WEBHOOK_EVENT_ID']=\$eventId;
require '{$ROOT}/api/v1/webhooks/qbo_payment_notifications.php';
PHP);
$invoke = static function (string $body, string $sig, string $eventId) use ($harnessFile): string {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg(base64_encode($body)) . ' ' . escapeshellarg($sig)
        . ' ' . escapeshellarg($eventId) . ' 2>/dev/null');
    return is_string($out) ? $out : '';
};
$statusOf = static function (string $out): string {
    if (preg_match('/"status":"([a-z_]+)"/', $out, $m)) return $m[1];
    return '(none)';
};

$errId = "smoke-rp-err-{$PID}";
$okId  = "smoke-rp-ok-{$PID}";
$settingExisted = null; $settingOld = null;

$cleanup = static function () use ($errId, $okId, &$settingExisted, &$settingOld) {
    db_execute("DELETE FROM acc_qbo_webhook_events WHERE webhook_event_id IN (?, ?)", [$errId, $okId]);
    if ($settingExisted === true) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'quickbooks.webhook_verifier_token'", [$settingOld]);
    } elseif ($settingExisted === false) {
        db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.webhook_verifier_token'");
    }
};

try {
    $cur = db_row("SELECT `value` FROM settings WHERE `key` = 'quickbooks.webhook_verifier_token'");
    if ($cur !== null) { $settingExisted = true; $settingOld = $cur['value'];
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'quickbooks.webhook_verifier_token'", [$TOKEN]);
    } else { $settingExisted = false;
        db_insert('settings', ['key' => 'quickbooks.webhook_verifier_token', 'value' => $TOKEN, 'value_type' => 'string', 'group_name' => 'quickbooks']);
    }

    db_execute("DELETE FROM acc_qbo_webhook_events WHERE webhook_event_id IN (?, ?)", [$errId, $okId]);
    db_insert('acc_qbo_webhook_events', ['webhook_event_id' => $errId, 'event_type' => 'Payment', 'payload' => '{}', 'signature_verified' => 1, 'processing_result' => 'error']);
    db_insert('acc_qbo_webhook_events', ['webhook_event_id' => $okId,  'event_type' => 'Payment', 'payload' => '{}', 'signature_verified' => 1, 'processing_result' => 'recorded']);

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [01e] QBO WEBHOOK REPROCESS — error vs succeeded redelivery\n";
    echo str_repeat('─', 72) . "\n";

    // 1. error event redelivered → reprocess
    $st = $statusOf($invoke($body, $sig, $errId));
    if ($st === 'received') {
        $pass("1 errored event redelivered — REPROCESSED ('received')");
    } else {
        $fail("1 errored event redelivered — status='{$st}' (expected 'received'; pre-fix 'duplicate' dropped the retry)");
    }

    // 2. succeeded event redelivered → still skip
    $st = $statusOf($invoke($body, $sig, $okId));
    if ($st === 'duplicate') {
        $pass("2 succeeded event redelivered — still skipped ('duplicate'; non-regression)");
    } else {
        $fail("2 succeeded event redelivered — status='{$st}' (expected 'duplicate')");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed webhook events; restored verifier token\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("QBO WEBHOOK REPROCESS — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
