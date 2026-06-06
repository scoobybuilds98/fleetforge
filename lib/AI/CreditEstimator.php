<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/CreditEstimator.php
 *
 * Internal estimate of remaining Claude credit:  baseline − spend-since-baseline.
 * This is NOT authoritative — the Anthropic Console is the source of truth. It is
 * a convenience figure so the operator can eyeball burn-down between top-ups.
 *
 * Spend is recomputed FROM TOKENS via the editable lib/AI/Pricing.php table (so a
 * pricing change is reflected immediately) rather than read from the historical
 * ai_query_log.cost_usd column (which was stamped at record time with whatever
 * price was current then). All money math is bcmath (D16): internal scale 6,
 * display rounded to 2 decimals.
 *
 * ── IMPORTANT data-shape caveat ─────────────────────────────────────────────
 * ai_query_log persists input/output tokens (prompt_tokens / completion_tokens)
 * and created_at, but has NO per-row `model` column (verified vs DATABASE_MASTER
 * + live schema). FleetForge calls a single model — the `ai.model` setting
 * (default 'claude-sonnet-4-20250514', see lib/AI/ClaudeClient.php) — so every
 * logged row is attributed to the currently-configured model for pricing. If the
 * configured model is absent from the Pricing table it is "unpriced": its spend
 * contributes $0 and the model is surfaced in `unpriced_models` so the UI can say
 * "(excludes N unpriced models)". The per-model structure below is kept so that
 * if a `model` column is ever added to ai_query_log this generalises cleanly.
 *
 * Cache tokens are not logged (ClaudeClient records only input/output), so cache
 * pricing is not applied here.
 *
 * @depends includes/db.php (db_row), includes/functions.php (settings_get),
 *          lib/AI/Pricing.php, ext-bcmath
 * @session S-AI-CREDIT-TILE
 */
final class CreditEstimator
{
    /** Internal bcmath working scale. */
    private const SCALE = 6;

    /** Settings keys (seeded by the S-AI-CREDIT-TILE migration). */
    public const KEY_BALANCE = 'ai.credit_balance';
    public const KEY_AS_OF   = 'ai.credit_balance_as_of';

    /**
     * Σ estimated USD spend over ai_query_log rows with created_at >= $sinceUtc.
     * Returns a bcmath string at internal scale (6). Unknown/unpriced models
     * contribute 0. Pass a UTC 'Y-m-d H:i:s' string.
     */
    public static function spentSince(string $sinceUtc): string
    {
        return self::breakdownSince($sinceUtc)['total'];
    }

    /**
     * Full spend breakdown since $sinceUtc.
     *
     * @return array{
     *     total: string,                       // bcmath string, scale 6
     *     by_model: array<string,string>,      // model => spend (priced only)
     *     unpriced_models: list<string>,       // models seen but not in Pricing
     *     input_tokens: int,
     *     output_tokens: int
     * }
     */
    public static function breakdownSince(string $sinceUtc): array
    {
        // ai_query_log carries no per-row model → bucket all rows under the
        // currently-configured model. (See class docblock caveat.)
        $model = (string) (settings_get('ai.model') ?: 'claude-sonnet-4-20250514');

        $row = db_row(
            "SELECT COALESCE(SUM(prompt_tokens), 0)     AS in_tok,
                    COALESCE(SUM(completion_tokens), 0) AS out_tok
               FROM ai_query_log
              WHERE created_at >= ?",
            [$sinceUtc]
        );

        $inTok  = (int) ($row['in_tok'] ?? 0);
        $outTok = (int) ($row['out_tok'] ?? 0);

        $total          = '0';
        $byModel        = [];
        $unpricedModels = [];

        // One bucket today; loop keeps the per-model generalisation intact.
        $buckets = [$model => ['in' => $inTok, 'out' => $outTok]];

        foreach ($buckets as $m => $toks) {
            // Skip empty buckets so a zero-usage period doesn't flag a model.
            if ($toks['in'] === 0 && $toks['out'] === 0) {
                continue;
            }

            $price = Pricing::forModel($m);
            if ($price === null) {
                // Unpriced: contributes $0, raise the flag.
                $unpricedModels[] = $m;
                continue;
            }

            // cost = tokens * pricePerMtok / 1_000_000  (multiply before divide
            // to preserve precision in bcmath).
            $inCost  = bcdiv(bcmul((string) $toks['in'],  $price['input'],  self::SCALE), '1000000', self::SCALE);
            $outCost = bcdiv(bcmul((string) $toks['out'], $price['output'], self::SCALE), '1000000', self::SCALE);
            $modelCost = bcadd($inCost, $outCost, self::SCALE);

            $byModel[$m] = $modelCost;
            $total       = bcadd($total, $modelCost, self::SCALE);
        }

        return [
            'total'           => $total,
            'by_model'        => $byModel,
            'unpriced_models' => $unpricedModels,
            'input_tokens'    => $inTok,
            'output_tokens'   => $outTok,
        ];
    }

    /**
     * Real remaining credit = baseline − spend-since-(baseline as-of).
     * Returns a bcmath string (scale 6), or null when no baseline is set.
     * May be NEGATIVE (intentional — the UI warns on over-budget).
     */
    public static function remaining(): ?string
    {
        $baseline = self::baselineValue();
        if ($baseline === null) {
            return null;
        }
        $asOf  = self::baselineAsOf();
        // No as-of → treat the baseline as "as of now": zero spend counted yet.
        $spent = ($asOf !== null && $asOf !== '')
            ? self::spentSince($asOf)
            : '0';

        return bcsub($baseline, $spent, self::SCALE);
    }

    /**
     * Convenience bundle for the UI. Everything the credit tile needs in one call.
     *
     * @return array{
     *     has_baseline: bool,
     *     baseline: ?string,            // raw decimal string as stored
     *     as_of_utc: ?string,          // raw UTC datetime string (UI must format_datetime it)
     *     spent: string,               // bcmath, scale 6
     *     remaining: ?string,          // bcmath, scale 6, may be negative; null if no baseline
     *     unpriced_models: list<string>,
     *     model: string
     * }
     */
    public static function summary(): array
    {
        $baseline = self::baselineValue();
        $asOf     = self::baselineAsOf();
        $model    = (string) (settings_get('ai.model') ?: 'claude-sonnet-4-20250514');

        if ($baseline === null) {
            return [
                'has_baseline'    => false,
                'baseline'        => null,
                'as_of_utc'       => null,
                'spent'           => '0',
                'remaining'       => null,
                'unpriced_models' => Pricing::isPriced($model) ? [] : [$model],
                'model'           => $model,
            ];
        }

        $bd    = ($asOf !== null && $asOf !== '')
            ? self::breakdownSince($asOf)
            : ['total' => '0', 'unpriced_models' => (Pricing::isPriced($model) ? [] : [$model])];
        $spent = $bd['total'];

        return [
            'has_baseline'    => true,
            'baseline'        => $baseline,
            'as_of_utc'       => $asOf,
            'spent'           => $spent,
            'remaining'       => bcsub($baseline, $spent, self::SCALE),
            'unpriced_models' => $bd['unpriced_models'] ?? [],
            'model'           => $model,
        ];
    }

    /**
     * Round a bcmath string to 2 decimals (half-up), returning a clean display
     * string like "12.35" or "-1.20". bcmath truncates, so we nudge by ±0.005.
     */
    public static function round2(string $bcValue): string
    {
        $neg  = str_starts_with($bcValue, '-');
        $nudge = $neg ? '-0.005' : '0.005';
        return bcadd($bcValue, $nudge, 2);
    }

    // ── settings accessors ────────────────────────────────────────────────

    /** Stored baseline as a trimmed decimal string, or null if unset/blank. */
    private static function baselineValue(): ?string
    {
        $raw = settings_get(self::KEY_BALANCE);
        if ($raw === null) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return $raw;
    }

    /** Stored UTC 'Y-m-d H:i:s' as-of, or null if unset/blank. */
    private static function baselineAsOf(): ?string
    {
        $raw = settings_get(self::KEY_AS_OF);
        if ($raw === null) {
            return null;
        }
        $raw = trim((string) $raw);
        return $raw === '' ? null : $raw;
    }
}
