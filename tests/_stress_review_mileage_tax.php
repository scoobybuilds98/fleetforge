<?php
declare(strict_types=1);

/**
 * tests/_stress_review_mileage_tax.php
 *
 * S-REVIEW-MILEAGE-TAX-FIX stress test — verify the per-line tax math
 * in api/v1/invoices/review_mileage.php uses the canonical decimal-
 * fraction multiplication pattern (matching TaxCalculator.php:62) and
 * NOT the dormant divide-by-100 bug that previously sat at
 * review_mileage.php:213-218.
 *
 * The bug surfaced in S-MILEAGE-ALLOWANCE-ZERO-FIX C5: tax_rates stores
 * province rates as decimal fractions already (0.13 = 13%), so the buggy
 * `bcdiv(bcmul($amount, $rate, 6), '100', 2)` reduces line tax by 100×
 * (e.g. $91.26 × 0.13 = $11.86 correct, but $91.26 × 0.13 / 100 = $0.12
 * via bcdiv-truncation produced from the bug).
 *
 * Two sets:
 *
 *   SET A — Canonical formula assertions
 *       Lock the bcround(bcmul(amount, rate, 6), 2) pattern with concrete
 *       numerical inputs covering ON HST, AB GST, BC PST, zero-amount
 *       edge, zero-rate edge, rounding edges. These document the canonical
 *       math with executable examples. Any future tax-math caller in this
 *       codebase should match these outputs for the same inputs.
 *
 *   SET B — Source-pattern regression sentinel against review_mileage.php
 *       (a) Presence of bcround(bcmul($appliedAmount, $<rate>, 6), 2) for
 *           gst/pst/hst — the canonical pattern.
 *       (b) ABSENCE of `bcdiv(bcmul($appliedAmount` — the bug shape.
 *       If any future edit re-introduces /100 divisor on the per-line tax
 *       block in review_mileage.php, this test goes red.
 *
 * NOT a D131 gate. One-shot regression sentinel — run on demand or before
 * any change touches the per-line tax math in review_mileage.php.
 *
 * @session  S-REVIEW-MILEAGE-TAX-FIX (2026-05-11)
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0;
$fail = 0;
$out  = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$out) {
    if ($ok) { $pass++; $out[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $out[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — S-REVIEW-MILEAGE-TAX-FIX stress test\n";
echo str_repeat('═', 78), "\n\n";

// ────────────────────────────────────────────────────────────────────────────
// SET A — Canonical formula assertions
// ────────────────────────────────────────────────────────────────────────────
// The canonical formula is bcround(bcmul($amount, $rate, 6), 2) where $rate
// is a decimal fraction (0.13 = 13%). Inputs below are real production-
// shaped numbers; expected outputs are computed by hand.
echo "SET A — Canonical formula: bcround(bcmul(amount, rate, 6), 2)\n";
echo str_repeat('─', 78), "\n";

$canon = function (string $amount, string $rate): string {
    return bcround(bcmul($amount, $rate, 6), 2);
};

// Anchor case: $100 × 0.13 = $13.00. The "obvious" sanity case — anyone who
// runs the broken /100 formula gets $0.13 here, immediately spotted.
$report('A1 ON HST anchor: $100 × 0.13 = $13.00', $canon('100.00', '0.1300') === '13.00',
    'got=$' . $canon('100.00', '0.1300'));

// Real production case from INV-2026-00092 line 175 (the bug-applied row).
// $699.84 × 0.13 = $90.9792 → bcround → $90.98. Bug produced $0.90.
$report('A2 INV-92 case: $699.84 × 0.13 = $90.98', $canon('699.84', '0.1300') === '90.98',
    'got=$' . $canon('699.84', '0.1300'));

// AB GST-only path: $50 × 0.05 = $2.50.
$report('A3 AB GST: $50.00 × 0.05 = $2.50', $canon('50.00', '0.0500') === '2.50',
    'got=$' . $canon('50.00', '0.0500'));

// BC PST piece: $50 × 0.07 = $3.50.
$report('A4 BC PST: $50.00 × 0.07 = $3.50', $canon('50.00', '0.0700') === '3.50',
    'got=$' . $canon('50.00', '0.0700'));

// QC PST (decimal-heavy): $200 × 0.09975 = $19.95.
$report('A5 QC PST: $200.00 × 0.09975 = $19.95', $canon('200.00', '0.099750') === '19.95',
    'got=$' . $canon('200.00', '0.099750'));

// Zero amount: $0 × any = $0.00.
$report('A6 zero amount: $0.00 × 0.13 = $0.00', $canon('0.00', '0.1300') === '0.00',
    'got=$' . $canon('0.00', '0.1300'));

// Zero rate (province with no GST/no HST applied to that line): $100 × 0 = $0.
// NB: the runtime code short-circuits on `bccomp($rate, '0', 4) > 0` BEFORE
// calling the math; this assertion confirms the math itself behaves if it
// were called with zero rate.
$report('A7 zero rate: $100.00 × 0.00 = $0.00', $canon('100.00', '0.0000') === '0.00',
    'got=$' . $canon('100.00', '0.0000'));

// Rounding edge — exact half cent (rounds-half-away-from-zero per bcround
// helper at includes/functions.php:413).
// $33.34 × 0.13 = $4.3342 → bcround → $4.33.
$report('A8 round-down edge: $33.34 × 0.13 = $4.33', $canon('33.34', '0.1300') === '4.33',
    'got=$' . $canon('33.34', '0.1300'));

// Rounding edge — round-up.
// $99.99 × 0.13 = $12.9987 → bcround → $13.00.
$report('A9 round-up edge: $99.99 × 0.13 = $13.00', $canon('99.99', '0.1300') === '13.00',
    'got=$' . $canon('99.99', '0.1300'));

foreach ($out as $line) echo "  {$line}\n";
$out = [];

// ────────────────────────────────────────────────────────────────────────────
// SET B — Source-pattern regression sentinel
// ────────────────────────────────────────────────────────────────────────────
// Read api/v1/invoices/review_mileage.php verbatim and assert that:
//   (a) the canonical pattern bcround(bcmul($appliedAmount, $<rate>, 6), 2)
//       is present for gst/pst/hst
//   (b) the bug shape `bcdiv(bcmul($appliedAmount` is absent
echo "\nSET B — Source-pattern regression sentinel (review_mileage.php)\n";
echo str_repeat('─', 78), "\n";

$source = file_get_contents(dirname(__DIR__) . '/api/v1/invoices/review_mileage.php');
if ($source === false) {
    echo "  FAIL  unable to read review_mileage.php\n";
    $fail++;
} else {
    // Canonical pattern present for each rate
    $canonGst = strpos($source, 'bcround(bcmul($appliedAmount, $gstRate, 6), 2)') !== false;
    $canonPst = strpos($source, 'bcround(bcmul($appliedAmount, $pstRate, 6), 2)') !== false;
    $canonHst = strpos($source, 'bcround(bcmul($appliedAmount, $hstRate, 6), 2)') !== false;

    $report('B1 canonical bcround(bcmul(appliedAmount, gstRate)) present', $canonGst);
    $report('B2 canonical bcround(bcmul(appliedAmount, pstRate)) present', $canonPst);
    $report('B3 canonical bcround(bcmul(appliedAmount, hstRate)) present', $canonHst);

    // Bug shape absent
    $hasBug = strpos($source, "bcdiv(bcmul(\$appliedAmount") !== false;
    $report('B4 bug shape `bcdiv(bcmul($appliedAmount, ...)` absent', !$hasBug,
        $hasBug ? 'BUG SHAPE STILL PRESENT' : 'OK');

    // Stronger negative: no `/ 100` or "100" divisor in the per-line tax block
    // by lexical proximity. Crude but catches obvious re-introductions.
    $blockStart = strpos($source, '$lineGst');
    $blockEnd   = strpos($source, '$linePst', $blockStart);
    $blockEnd   = $blockEnd !== false ? strpos($source, ';', strpos($source, '$lineHst', $blockEnd)) : false;
    $block      = $blockStart !== false && $blockEnd !== false
        ? substr($source, $blockStart, $blockEnd - $blockStart)
        : '';
    $hasDiv100  = strpos($block, "'100'") !== false || strpos($block, ', 100') !== false;
    $report('B5 no /100 divisor in per-line tax block', !$hasDiv100,
        $hasDiv100 ? "found '100' divisor" : 'OK');
}

foreach ($out as $line) echo "  {$line}\n";

// ────────────────────────────────────────────────────────────────────────────
// Summary
// ────────────────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('═', 78), "\n";
echo "Result: pass={$pass}  fail={$fail}\n";

exit($fail > 0 ? 1 : 0);
