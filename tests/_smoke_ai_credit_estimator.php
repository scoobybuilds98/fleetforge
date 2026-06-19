<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_credit_estimator.php
 *
 * Smoke for S-AI-CREDIT-TILE — the internal "Claude credit remaining" estimate.
 * Exercises the REAL code path (Pricing + CreditEstimator against real db.php +
 * the real ai_query_log schema + the real settings table), not just php -l.
 *
 * C1 — Pricing: configured default model is priced; a bogus model is unpriced.
 * C2 — spentSince(): hand-computed bcmath value over seeded ai_query_log rows.
 * C3 — remaining(): baseline − spentSince(as_of) == hand value.
 * C4 — over-budget: baseline < spend → remaining is NEGATIVE (allowed).
 * C5 — no baseline: empty ai.credit_balance → remaining() === null.
 * C6 — unpriced model: configured model not in Pricing → spend 0 + flagged.
 * C7 — round2(): half-up rounding to 2 decimals (incl. negative).
 * C8 — timezone: as_of renders via format_datetime() in APP_TIMEZONE, NOT raw UTC.
 *
 * Hermetic: original ai.model / ai.credit_balance / ai.credit_balance_as_of are
 * snapshotted and restored, and all seeded ai_query_log rows are deleted, in a
 * finally block — the dev DB is left byte-identical.
 *
 * @session S-AI-CREDIT-TILE
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\Pricing;
use FleetForge\AI\CreditEstimator;

$pass = 0; $fail = 0; $fails = [];
function smoke_assert(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label . ($detail ? ": $detail" : ''); }
}

// ── helpers to read/write a single settings key directly ─────────────────────
function _smoke_set_setting(string $key, string $val): void {
    db_execute(
        "INSERT INTO `settings` (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`)
         VALUES (?, ?, 'string', 'ai', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $val]
    );
}
function _smoke_get_setting(string $key): ?string {
    $r = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $r === null ? null : (string) $r['value'];
}

$PRICED_MODEL = 'claude-sonnet-4-20250514';   // must match a Pricing key
$INPUT_PRICE  = Pricing::forModel($PRICED_MODEL)['input']  ?? null;
$OUTPUT_PRICE = Pricing::forModel($PRICED_MODEL)['output'] ?? null;

// Snapshot originals for restore
$orig = [
    'ai.model'                   => _smoke_get_setting('ai.model'),
    'ai.credit_balance'          => _smoke_get_setting('ai.credit_balance'),
    'ai.credit_balance_as_of'    => _smoke_get_setting('ai.credit_balance_as_of'),
];
$seededIds = [];

try {
    // ── C1 — Pricing table ───────────────────────────────────────────────────
    echo "\nC1 — Pricing table\n";
    smoke_assert('default model is priced', Pricing::isPriced($PRICED_MODEL));
    smoke_assert('bogus model is unpriced', !Pricing::isPriced('claude-totally-made-up'));
    // The exact regression the audit caught: the LIVE ai.model must be priced, or
    // every real call's spend silently counts as $0 in the credit tile.
    smoke_assert('LIVE ai.model is priced', Pricing::isPriced((string) ($orig['ai.model'] ?? '')),
        'live model=' . ($orig['ai.model'] ?? 'NULL'));
    smoke_assert('priced model exposes input+output strings',
        is_string($INPUT_PRICE) && is_string($OUTPUT_PRICE) && is_numeric($INPUT_PRICE) && is_numeric($OUTPUT_PRICE),
        "in={$INPUT_PRICE} out={$OUTPUT_PRICE}");

    // ── Seed: configured model = priced; two known-token rows ─────────────────
    _smoke_set_setting('ai.model', $PRICED_MODEL);
    // Use a FAR-FUTURE UTC anchor so no real ai_query_log rows fall in the window
    // (spentSince sums ALL rows since $since, priced by the current ai.model — a
    // 2026 anchor let ~14 real dev rows bleed into the hand-computed assertions).
    // S-AI-AUDIT-HIGH-FIX.
    $rowUtc   = '2031-06-07 04:30:00';      // 04:30 UTC, far future
    $sinceUtc = '2031-06-07 00:00:00';      // before the rows, no real data here
    // Row A: 1,000,000 input tokens, 0 output  → 1e6 * INPUT /1e6 = INPUT
    $idA = db_insert('ai_query_log', [
        'user_id' => null, 'query_type' => 'smoke', 'prompt_tokens' => 1000000,
        'completion_tokens' => 0, 'total_tokens' => 1000000, 'cost_usd' => 0,
        'latency_ms' => 0, 'was_cached' => 0, 'created_at' => $rowUtc,
    ]);
    // Row B: 0 input, 200,000 output → 200000 * OUTPUT /1e6
    $idB = db_insert('ai_query_log', [
        'user_id' => null, 'query_type' => 'smoke', 'prompt_tokens' => 0,
        'completion_tokens' => 200000, 'total_tokens' => 200000, 'cost_usd' => 0,
        'latency_ms' => 0, 'was_cached' => 0, 'created_at' => $rowUtc,
    ]);
    $seededIds[] = (int) $idA; $seededIds[] = (int) $idB;

    // Hand-computed expected spend (bcmath, scale 6)
    $expA  = bcdiv(bcmul('1000000', $INPUT_PRICE, 6), '1000000', 6);
    $expB  = bcdiv(bcmul('200000',  $OUTPUT_PRICE, 6), '1000000', 6);
    $expSpend = bcadd($expA, $expB, 6);

    // ── C2 — spentSince ────────────────────────────────────────────────────────
    echo "\nC2 — spentSince()\n";
    $spent = CreditEstimator::spentSince($sinceUtc);
    smoke_assert('spentSince == hand-computed bcmath', bccomp($spent, $expSpend, 6) === 0, "got={$spent} exp={$expSpend}");
    // Sanity: a $since AFTER the rows yields 0
    $spentAfter = CreditEstimator::spentSince('2031-06-07 05:00:00');
    smoke_assert('spentSince after rows == 0', bccomp($spentAfter, '0', 6) === 0, "got={$spentAfter}");

    // ── C3 — remaining (positive) ──────────────────────────────────────────────
    echo "\nC3 — remaining() positive\n";
    _smoke_set_setting('ai.credit_balance', '100.00');
    _smoke_set_setting('ai.credit_balance_as_of', $sinceUtc);
    $remaining = CreditEstimator::remaining();
    $expRem    = bcsub('100.00', $expSpend, 6);
    smoke_assert('remaining == baseline − spent', $remaining !== null && bccomp($remaining, $expRem, 6) === 0, "got={$remaining} exp={$expRem}");
    $summary = CreditEstimator::summary();
    smoke_assert('summary has_baseline true + no unpriced', $summary['has_baseline'] === true && $summary['unpriced_models'] === [], json_encode($summary['unpriced_models']));

    // ── C4 — over-budget (negative) ──────────────────────────────────────────────
    echo "\nC4 — over-budget negative\n";
    _smoke_set_setting('ai.credit_balance', '1.00');   // less than spend (~6)
    $remNeg = CreditEstimator::remaining();
    smoke_assert('remaining is negative when baseline < spend', $remNeg !== null && bccomp($remNeg, '0', 6) < 0, "got={$remNeg}");

    // ── C5 — no baseline ─────────────────────────────────────────────────────────
    echo "\nC5 — no baseline\n";
    _smoke_set_setting('ai.credit_balance', '');
    smoke_assert('remaining() === null when balance empty', CreditEstimator::remaining() === null);
    smoke_assert('summary has_baseline false', CreditEstimator::summary()['has_baseline'] === false);

    // ── C6 — unpriced model ──────────────────────────────────────────────────────
    echo "\nC6 — unpriced model\n";
    _smoke_set_setting('ai.model', 'claude-totally-made-up');
    $bd = CreditEstimator::breakdownSince($sinceUtc);
    smoke_assert('unpriced model contributes $0 total', bccomp($bd['total'], '0', 6) === 0, "got={$bd['total']}");
    smoke_assert('unpriced model flagged', in_array('claude-totally-made-up', $bd['unpriced_models'], true), json_encode($bd['unpriced_models']));
    smoke_assert('token sums still reported under unpriced model', $bd['input_tokens'] === 1000000 && $bd['output_tokens'] === 200000, "in={$bd['input_tokens']} out={$bd['output_tokens']}");
    _smoke_set_setting('ai.model', $PRICED_MODEL);  // restore for remaining checks

    // ── C7 — round2 half-up ────────────────────────────────────────────────────────
    echo "\nC7 — round2()\n";
    smoke_assert("round2('94.000000')=='94.00'", CreditEstimator::round2('94.000000') === '94.00', CreditEstimator::round2('94.000000'));
    smoke_assert("round2('2.345')=='2.35'", CreditEstimator::round2('2.345') === '2.35', CreditEstimator::round2('2.345'));
    smoke_assert("round2('-1.005')=='-1.01'", CreditEstimator::round2('-1.005') === '-1.01', CreditEstimator::round2('-1.005'));

    // ── C8 — timezone (format_datetime, NOT raw UTC) ─────────────────────────────────
    echo "\nC8 — timezone rendering\n";
    $asOfUtc   = '2026-06-07 04:30:00';                       // 04:30 UTC
    $rendered  = format_datetime($asOfUtc);                   // app tz
    // Expected = same instant converted into APP_TIMEZONE via DateTime.
    $expDt = (new DateTime($asOfUtc, new DateTimeZone('UTC')));
    $expDt->setTimezone(new DateTimeZone(APP_TIMEZONE));
    $expRendered = $expDt->format('M j, Y g:i A');
    smoke_assert('format_datetime converts to APP_TIMEZONE (not raw UTC)', $rendered === $expRendered, "got='{$rendered}' exp='{$expRendered}' tz=" . APP_TIMEZONE);
    smoke_assert('rendered differs from a raw-UTC echo (proves conversion)', $rendered !== '2026-06-07 04:30:00' && $rendered !== $asOfUtc, "got='{$rendered}'");
    // When APP_TIMEZONE is the canonical America/Vancouver, 04:30 UTC = 21:30 PDT on Jun 6.
    if (APP_TIMEZONE === 'America/Vancouver') {
        smoke_assert("Vancouver: 04:30 UTC → 'Jun 6, 2026 9:30 PM'", $rendered === 'Jun 6, 2026 9:30 PM', "got='{$rendered}'");
    } else {
        echo "  NOTE  APP_TIMEZONE={" . APP_TIMEZONE . "} (not Vancouver on this box) — conversion still asserted above\n";
    }

} finally {
    // ── Cleanup: delete seeded rows + restore settings ───────────────────────────
    foreach ($seededIds as $id) {
        try { db_execute("DELETE FROM ai_query_log WHERE id = ?", [$id]); } catch (\Throwable) {}
    }
    foreach ($orig as $k => $v) {
        try {
            if ($v === null) {
                db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
            } else {
                _smoke_set_setting($k, $v);
            }
        } catch (\Throwable) {}
    }
}

echo "\n────────────────────────────────────────\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "FAILURES:\n"; foreach ($fails as $f) echo "  - {$f}\n"; exit(1); }
echo "ALL GREEN\n";
exit(0);
