<?php
declare(strict_types=1);

/**
 * tests/_smoke_ses_webhook_topic_allowlist.php
 *
 * S-NORTHLAND-P0 — SES/SNS bounce webhook TopicArn allowlist.
 *
 * api/v1/webhooks/ses_notifications.php authenticated callers by SNS signature
 * ALONE. AWS signs SNS messages for every AWS account, so the signature proves
 * "AWS sent this", never "OUR topic sent this". Any AWS customer could create a
 * topic, subscribe this endpoint (auto-confirmed), publish forged permanent
 * bounces, and have us set email_disabled=1 on arbitrary customer addresses —
 * a denial-of-email attack. Fix: pin the TopicArn.
 *
 * NOTE on scope: like _smoke_ses_webhook_dedup.php, the endpoint validates
 * against AWS's real signing cert (Aws\Sns\MessageValidator), which cannot be
 * forged from a test — and that check runs BEFORE the allowlist, so a
 * black-box request always 403s at the signature and can never isolate this
 * logic. So this exercises the load-bearing pieces directly:
 *   1. wiring — the endpoint reads both the setting and the env fallback;
 *   2. ORDER — the allowlist runs BEFORE the SubscriptionConfirmation handler
 *      (placed after, it would be decorative: the subscription is already
 *      confirmed by then);
 *   3. timing-safe comparison via hash_equals(), not ===;
 *   4. the four-way decision matrix, replicated verbatim from the endpoint.
 *
 * PRE-FIX  : no allowlist wiring at all (guards 1-3 fail).
 * POST-FIX : wiring + ordering + hash_equals + matrix all hold.
 *
 * Run:  php tests/_smoke_ses_webhook_topic_allowlist.php   Exit 0/1.
 *
 * @session S-NORTHLAND-P0
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "S-NORTHLAND-P0 — SES WEBHOOK TOPIC ALLOWLIST\n";
echo str_repeat('─', 72) . "\n";

$endpoint = $ROOT . '/api/v1/webhooks/ses_notifications.php';
$src      = is_readable($endpoint) ? (string) file_get_contents($endpoint) : '';

if ($src === '') {
    $fail('0 endpoint — api/v1/webhooks/ses_notifications.php is missing or unreadable');
} else {
    $pass('0 endpoint — source readable');

    // 0b. THE DEPENDENCY GUARD. \Aws\Sns\MessageValidator is NOT part of
    //     aws/aws-sdk-php — it ships in the separate
    //     aws/aws-php-sns-message-validator package. That package was missing
    //     from composer.json, so EVERY SNS delivery threw "Class not found",
    //     was swallowed by the endpoint's catch (\Throwable), and 403'd:
    //     bounce/complaint auto-disable was silently dead in production from
    //     the day it shipped (confirmed in prod's nginx error log).
    //     Complaints that never disable email are how an account loses SES
    //     production access — so this guard is load-bearing, not cosmetic.
    foreach ([
        '\Aws\Sns\MessageValidator',
        '\Aws\Sns\Message',
        '\Aws\Sns\Exception\InvalidSnsMessageException',
    ] as $cls) {
        if (class_exists($cls)) {
            $pass("0b dependency — {$cls} is installed");
        } else {
            $fail("0b dependency — {$cls} MISSING (composer require aws/aws-php-sns-message-validator) — every SNS message will 403");
        }
    }

    // 1. wiring — both the settings key and the .env fallback are read
    $hasSetting = str_contains($src, "settings_get('aws.sns_topic_arn'");
    $hasEnv     = str_contains($src, "AWS_SNS_TOPIC_ARN");
    $hasArnRead = str_contains($src, "\$msg['TopicArn']");
    if ($hasSetting && $hasEnv && $hasArnRead) {
        $pass('1 wiring — reads aws.sns_topic_arn, AWS_SNS_TOPIC_ARN, and $msg[TopicArn]');
    } else {
        $fail(sprintf(
            '1 wiring — missing: %s%s%s',
            $hasSetting ? '' : 'aws.sns_topic_arn ',
            $hasEnv     ? '' : 'AWS_SNS_TOPIC_ARN ',
            $hasArnRead ? '' : '$msg[TopicArn]'
        ));
    }

    // 2. ORDER — the allowlist must gate the SubscriptionConfirmation handler.
    //    If it ran afterwards, the subscription would already be confirmed and
    //    the check would protect nothing.
    $posAllowlist = strpos($src, 'Topic allowlist');
    $posConfirm   = strpos($src, "if (\$msgType === 'SubscriptionConfirmation') {");
    if ($posAllowlist !== false && $posConfirm !== false && $posAllowlist < $posConfirm) {
        $pass('2 order — allowlist precedes the SubscriptionConfirmation handler');
    } else {
        $fail('2 order — allowlist does NOT run before SubscriptionConfirmation (decorative check)');
    }

    // 3. timing-safe comparison
    if (str_contains($src, 'hash_equals($expectedTopicArn, $incomingTopicArn)')) {
        $pass('3 compare — uses hash_equals() (timing-safe), not ===');
    } else {
        $fail('3 compare — TopicArn comparison is not hash_equals()');
    }
}

// 4. decision matrix — replicated verbatim from the endpoint's logic.
//    Returns 'allow' | 'reject'.
$decide = static function (string $expected, string $incoming, string $msgType): string {
    $expected = trim($expected);
    $incoming = trim($incoming);
    if ($expected !== '') {
        return hash_equals($expected, $incoming) ? 'allow' : 'reject';
    }
    return $msgType === 'SubscriptionConfirmation' ? 'reject' : 'allow';
};

$OURS   = 'arn:aws:sns:us-west-2:035837222410:fleetforge-ses-bounces';
$THEIRS = 'arn:aws:sns:us-west-2:999999999999:attacker-topic';

$matrix = [
    // [expected, incoming, type, want, why]
    [$OURS, $OURS,   'Notification',             'allow',  'configured + our topic  → process bounces'],
    [$OURS, $THEIRS, 'Notification',             'reject', 'configured + foreign topic → THE ATTACK, blocked'],
    [$OURS, $THEIRS, 'SubscriptionConfirmation', 'reject', 'configured + foreign subscribe → blocked'],
    [$OURS, '',      'Notification',             'reject', 'configured + absent TopicArn → blocked'],
    ['',    $THEIRS, 'SubscriptionConfirmation', 'reject', 'unconfigured + subscribe → refused (attack entry point)'],
    ['',    $THEIRS, 'Notification',             'allow',  'unconfigured + notify → allowed (no silent regression)'],
];

foreach ($matrix as $i => [$exp, $inc, $type, $want, $why]) {
    $got = $decide($exp, $inc, $type);
    if ($got === $want) {
        $pass(sprintf('4.%d matrix — %s', $i + 1, $why));
    } else {
        $fail(sprintf('4.%d matrix — %s (wanted %s, got %s)', $i + 1, $why, $want, $got));
    }
}

echo str_repeat('─', 72) . "\n";
if ($failures) {
    echo "\033[31m{$passes} passed, " . count($failures) . " FAILED\033[0m\n";
    foreach ($failures as $f) { echo "  · {$f}\n"; }
    exit(1);
}
echo "\033[32mAll {$passes} checks passed.\033[0m\n";
exit(0);
