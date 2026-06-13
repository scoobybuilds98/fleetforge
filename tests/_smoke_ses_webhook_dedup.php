<?php
declare(strict_types=1);

/**
 * tests/_smoke_ses_webhook_dedup.php
 *
 * MEDIUM [08] — SES/SNS bounce webhook MessageId idempotency.
 *
 * api/v1/webhooks/ses_notifications.php had no dedup, so an SNS redelivery
 * reprocessed the same bounce/complaint (duplicate email_bounces + audit rows,
 * re-disabled email). Added a ses_webhook_events table (UNIQUE message_id) +
 * a SELECT-then-skip on the SNS MessageId before processing.
 *
 * NOTE on scope: the endpoint verifies the SNS message against AWS's real
 * signing cert (Aws\Sns\MessageValidator), which can't be forged in a test, so
 * this exercises the load-bearing pieces directly:
 *   1. the migration shipped the table with a UNIQUE on message_id;
 *   2. that UNIQUE is the race backstop — a duplicate MessageId insert 1062s;
 *   3. the dedup SELECT the endpoint runs finds a prior MessageId (→ would skip);
 *   4. wiring guard — the endpoint references ses_webhook_events + the skip path.
 *
 * PRE-FIX  : the endpoint has no ses_webhook_events wiring (guard fails).
 * POST-FIX : table + UNIQUE + dedup query + wiring all present.
 *
 * Run:  php tests/_smoke_ses_webhook_dedup.php   Exit 0/1.
 *
 * @session MEDIUM-08-SES-DEDUP
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$mid = "smoke-sns-msg-{$PID}";

$cleanup = static function () use ($mid) {
    db_execute("DELETE FROM ses_webhook_events WHERE message_id = ?", [$mid]);
};

try {
    $cleanup();
    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [08] SES WEBHOOK DEDUP\n";
    echo str_repeat('─', 72) . "\n";

    // 1. table + UNIQUE on message_id
    $hasTable = db_row("SHOW TABLES LIKE 'ses_webhook_events'") !== null;
    $uq = false;
    if ($hasTable) {
        foreach (db_select("SHOW INDEX FROM ses_webhook_events WHERE Non_unique = 0", []) as $i) {
            if ($i['Column_name'] === 'message_id') { $uq = true; break; }
        }
    }
    if ($hasTable && $uq) {
        $pass("1 schema — ses_webhook_events exists with a UNIQUE on message_id");
    } else {
        $fail("1 schema — table=" . ($hasTable ? 'Y' : 'N') . " unique_message_id=" . ($uq ? 'Y' : 'N'));
    }

    // 2. UNIQUE backstop — duplicate MessageId insert is rejected (1062)
    db_insert('ses_webhook_events', ['message_id' => $mid, 'notification_type' => 'Bounce']);
    $dupRejected = false;
    try {
        db_insert('ses_webhook_events', ['message_id' => $mid, 'notification_type' => 'Bounce']);
    } catch (\PDOException $e) {
        $dupRejected = $e->getCode() === '23000';
    }
    if ($dupRejected) {
        $pass("2 backstop — second insert of the same MessageId rejected (1062) — race-safe dedup");
    } else {
        $fail("2 backstop — duplicate MessageId was NOT rejected by the UNIQUE key");
    }

    // 3. dedup SELECT (the exact query the endpoint runs) finds the prior message
    $found = db_row("SELECT id FROM ses_webhook_events WHERE message_id = ?", [$mid]) !== null;
    if ($found) {
        $pass("3 dedup query — a prior MessageId is found → endpoint short-circuits to 'duplicate'");
    } else {
        $fail("3 dedup query — prior MessageId not found");
    }

    // 4. wiring guard — endpoint references the dedup table + skip path
    $src = (string) file_get_contents($ROOT . '/api/v1/webhooks/ses_notifications.php');
    if (str_contains($src, 'ses_webhook_events') && str_contains($src, "'duplicate'") && str_contains($src, 'MessageId')) {
        $pass("4 wiring — ses_notifications.php dedups on MessageId via ses_webhook_events");
    } else {
        $fail("4 wiring — endpoint missing ses_webhook_events dedup wiring (pre-fix)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  removed ses_webhook_events smoke row\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("SES WEBHOOK DEDUP — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
