<?php
declare(strict_types=1);

/**
 * tests/_smoke_time_ago_utc.php
 *
 * MEDIUM [06] — activity-feed time_ago() parsed UTC datetimes as PHP-local
 * (America/Vancouver) then diffed against UTC time(), skewing every relative
 * age by the UTC offset (~7-8h). Fixed to parse the stored string as UTC.
 *
 * Loads the REAL time_ago() source from api/v1/dashboard/activity_feed.php (the
 * function lives inside the endpoint file, so we extract + eval just that
 * function rather than running the whole endpoint) under the app's configured
 * timezone, and asserts a 2-hours-ago UTC event reads "2 hrs ago".
 *
 * PRE-FIX  : the +7h skew drives the diff negative → "just now" → FAIL.
 * POST-FIX : "2 hrs ago".
 *
 * Run:  php tests/_smoke_time_ago_utc.php   Exit: 0 pass / 1 fail.
 *
 * @session MEDIUM-06-TIME-AGO-UTC
 */

require_once dirname(__DIR__) . '/config/app.php'; // sets date_default_timezone_set(America/Vancouver)

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "MEDIUM [06] time_ago() UTC — tz=" . date_default_timezone_get() . "\n";
echo str_repeat('─', 72) . "\n";

// Extract the real time_ago() from the endpoint file and define it here.
$src = (string) file_get_contents(dirname(__DIR__) . '/api/v1/dashboard/activity_feed.php');
if (!preg_match('/function time_ago\(string \$datetimeStr\): string\s*\{.*?\n\}/s', $src, $m)) {
    $fail("could not extract time_ago() from activity_feed.php");
} else {
    eval($m[0]);

    // An event 2 hours ago, stored as the DB stores it (UTC, bare datetime).
    $twoHoursAgoUtc = gmdate('Y-m-d H:i:s', time() - 7200);
    $r = time_ago($twoHoursAgoUtc);
    if (stripos($r, 'hr') !== false && stripos($r, 'just now') === false) {
        $pass("2-hours-ago UTC event reads '{$r}' (correct), not 'just now'");
    } else {
        $fail("2-hours-ago UTC event reads '{$r}' (expected '~2 hrs ago'; pre-fix 'just now' from the +7h local-parse skew)");
    }

    // Sanity: a brand-new event still reads 'just now'.
    $r2 = time_ago(gmdate('Y-m-d H:i:s'));
    if ($r2 === 'just now') {
        $pass("a just-created UTC event reads 'just now' (non-vacuous)");
    } else {
        $fail("a just-created event reads '{$r2}' (expected 'just now')");
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("TIME_AGO UTC — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
