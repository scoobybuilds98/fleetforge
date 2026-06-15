<?php
declare(strict_types=1);

/**
 * tests/_smoke_cvi_overdue_sign.php
 *
 * Stop-condition smoke for S-CVI-OVERDUE-SIGN-FIX.
 *
 * Bug: app/admin/equipment/show.php daysUntil() computed `$date->diff(today)`,
 * which inverts for a FUTURE date — so a CVI expiry months in the future returned
 * a NEGATIVE day count and rendered as "N days overdue" (sign flip). The fix anchors
 * today->$date so future = positive (expires in N days), past = negative (overdue).
 *
 * This harness EXTRACTS the real daysUntil()/complianceDelta() bodies from the
 * shipped file and evals them (non-vacuous — it tests the literal code, not a copy),
 * then asserts the sign + label across the future / past / today / null cases, plus
 * the 30-day stat-card colour thresholds.
 *
 * Tests:
 *  T1  php -l clean
 *  T2  Static: corrected diff direction present; buggy inline renders gone; JS twin present
 *  T3  daysUntil(today+76) = +76  -> complianceDelta = "expires in 76 days" (SC2, NOT overdue)
 *  T4  daysUntil(today-10) = -10  -> complianceDelta = "overdue 10 days"   (SC3)
 *  T5  daysUntil(today)    =  0   -> complianceDelta = "expires today"     (SC4)
 *  T6  daysUntil(null)     = null -> complianceDelta = "" ; singular "1 day" / "overdue 1 day"
 *  T7  Stat-card colour thresholds (SC5): future>30 = teal (not red), <=30 = amber, past = red
 */

$pass = 0; $fail = 0;
function ok(string $t): void  { global $pass; $pass++; echo "  \033[32m✓\033[0m {$t}\n"; }
function bad(string $t, string $d = ''): void {
    global $fail; $fail++;
    echo "  \033[31m✗\033[0m {$t}" . ($d ? " — {$d}" : '') . "\n";
}
function section(string $s): void { echo "\n{$s}\n" . str_repeat('─', 70) . "\n"; }

$ROOT = dirname(__DIR__);
$showPath = $ROOT . '/app/admin/equipment/show.php';

echo "\n════════════════════════════════════════════════════════════════════════\n";
echo "S-CVI-OVERDUE-SIGN-FIX — future expiry must read \"expires in N days\"\n";
echo "════════════════════════════════════════════════════════════════════════\n";

// ── T1: syntax ──────────────────────────────────────────────────────────────
section('T1: php -l (SC1)');
$lint = (string) shell_exec('php -l ' . escapeshellarg($showPath) . ' 2>&1');
str_contains($lint, 'No syntax errors')
    ? ok('T1: show.php passes php -l')
    : bad('T1: syntax error', trim($lint));

$src = (string) file_get_contents($showPath);

// ── T2: static anchors ──────────────────────────────────────────────────────
section('T2: corrected math present, buggy renders gone');
$check = [
    'T2a: daysUntil anchors today->date (fixed direction)'
        => str_contains($src, "(new DateTimeImmutable('today'))->diff(new DateTimeImmutable(\$date))"),
    'T2b: buggy date->today diff direction removed'
        => !str_contains($src, "(new DateTimeImmutable(\$date))->diff(new DateTimeImmutable('today'))"),
    'T2c: PHP complianceDelta() defined'
        => str_contains($src, 'function complianceDelta(?int $days): string'),
    'T2d: stat card uses complianceDelta (old inline "days remaining" gone)'
        => str_contains($src, 'e(complianceDelta($cviDays))') && !str_contains($src, "\$cviDays . ' days remaining'"),
    'T2e: JS complianceDelta() twin defined'
        => str_contains($src, 'complianceDelta(days) {'),
    'T2f: doc-table cell uses complianceDelta (old inline JS overdue gone)'
        => str_contains($src, 'x-text="complianceDelta(doc.days)"') && !str_contains($src, "Math.abs(doc.days) + ' days overdue'"),
];
foreach ($check as $label => $cond) { $cond ? ok($label) : bad($label); }

// ── Extract + eval the REAL helpers (non-vacuous) ───────────────────────────
section('T3–T7: real daysUntil()/complianceDelta() behaviour');
if (!preg_match('/function daysUntil\(\?string \$date\): \?int \{.*?\n\}/s', $src, $m1)) {
    bad('extract daysUntil()', 'regex did not match');
} elseif (!preg_match('/function complianceDelta\(\?int \$days\): string \{.*?\n\}/s', $src, $m2)) {
    bad('extract complianceDelta()', 'regex did not match');
} else {
    eval($m1[0]);
    eval($m2[0]);

    $fut  = (new DateTimeImmutable('today'))->modify('+76 days')->format('Y-m-d');
    $past = (new DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
    $todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
    $yest     = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
    $in20     = (new DateTimeImmutable('today'))->modify('+20 days')->format('Y-m-d');

    // T3 — SC2: future is positive + "expires in 76 days", NOT overdue
    $d = daysUntil($fut);
    if ($d === 76 && complianceDelta($d) === 'expires in 76 days') {
        ok('T3: today+76 → +76 → "expires in 76 days" (SC2, not overdue)');
    } else {
        bad('T3: SC2', "daysUntil=" . var_export($d, true) . " label=" . complianceDelta((int)$d));
    }

    // T4 — SC3: past is negative + "overdue 10 days"
    $d = daysUntil($past);
    if ($d === -10 && complianceDelta($d) === 'overdue 10 days') {
        ok('T4: today-10 → -10 → "overdue 10 days" (SC3)');
    } else {
        bad('T4: SC3', "daysUntil=" . var_export($d, true) . " label=" . complianceDelta((int)$d));
    }

    // T5 — SC4: today
    $d = daysUntil($todayStr);
    if ($d === 0 && complianceDelta($d) === 'expires today') {
        ok('T5: today → 0 → "expires today" (SC4)');
    } else {
        bad('T5: SC4', "daysUntil=" . var_export($d, true) . " label=" . complianceDelta((int)$d));
    }

    // T6 — null + singular grammar
    $okNull = daysUntil(null) === null && complianceDelta(null) === '';
    $okSing = complianceDelta(daysUntil($tomorrow)) === 'expires in 1 day'
           && complianceDelta(daysUntil($yest)) === 'overdue 1 day';
    if ($okNull && $okSing) {
        ok('T6: null → no badge; singular "expires in 1 day" / "overdue 1 day"');
    } else {
        bad('T6: null/singular', "null=" . var_export(complianceDelta(daysUntil(null)), true)
            . " +1=" . complianceDelta(daysUntil($tomorrow)) . " -1=" . complianceDelta(daysUntil($yest)));
    }

    // T7 — SC5: stat-card colour thresholds (replicates the line-248 expression with fixed daysUntil)
    $colour = static function (?string $cvi): string {
        if (!$cvi) return 'teal';
        $d = daysUntil($cvi);
        return $d < 0 ? 'red' : ($d <= 30 ? 'amber' : 'teal');
    };
    $c1 = $colour($fut)  === 'teal';   // 76d future → green/teal, NOT red
    $c2 = $colour($in20) === 'amber';  // within 30d → amber
    $c3 = $colour($past) === 'red';    // overdue → red
    if ($c1 && $c2 && $c3) {
        ok('T7: colour thresholds (SC5) — future>30=teal, ≤30=amber, past=red');
    } else {
        bad('T7: SC5 colours', "fut=" . $colour($fut) . " in20=" . $colour($in20) . " past=" . $colour($past));
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
echo "CVI OVERDUE SIGN — {$pass} passed, {$fail} failed\n";
echo $fail === 0 ? "\033[32m✓ ALL PASSED\033[0m\n" : "\033[31m✗ {$fail} FAILED\033[0m\n";
echo str_repeat('─', 72) . "\n";
exit($fail === 0 ? 0 : 1);
