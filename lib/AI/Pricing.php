<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/Pricing.php
 *
 * Editable Claude model price table — the single source of truth for the
 * internal "Claude credit remaining" estimate (S-AI-CREDIT-TILE).
 *
 * Prices are USD per 1,000,000 tokens (per-Mtok), stored as DECIMAL STRINGS so
 * CreditEstimator can feed them straight into bcmath without float drift (D16).
 *
 * Prices are current Anthropic list prices (per-Mtok). Keep this map in sync
 * whenever the `ai.model` setting changes — a model bump that lands an unpriced
 * id here silently zeroes the credit estimate (S-AI-AUDIT-HIGH-FIX surfaced
 * exactly that: 'claude-sonnet-4-6' was the live model but only the retired
 * 'claude-sonnet-4-20250514' was priced). tests/_smoke_ai_model_priced.php
 * guards against re-introducing that gap.
 *
 * Keys are the exact `model` strings FleetForge sends to the API (the
 * `ai.model` setting; default 'claude-sonnet-4-6' — see lib/AI/ClaudeClient.php).
 * A model NOT present in this map is treated as "unpriced": CreditEstimator
 * counts its spend as $0 and raises an "unpriced models" flag so the UI can
 * disclose the gap. Source of truth for prices: https://www.anthropic.com/pricing
 *
 * NOTE on cache tokens: ai_query_log does NOT currently persist cache
 * read/write token counts (ClaudeClient only logs input_tokens/output_tokens),
 * so the cache_read / cache_write entries below are declared for completeness
 * but are NOT consumed by the estimator today. They become live only if/when
 * cache token columns are added to the AI log.
 *
 * @session S-AI-CREDIT-TILE
 */
final class Pricing
{
    /**
     * model => [input, output, cache_read, cache_write] in USD per 1,000,000 tokens.
     * All values are decimal strings (bcmath-safe).
     */
    private const PRICES = [
        // Claude Sonnet 4.6 — the model FleetForge calls by default (ai.model).
        // Anthropic list prices: $3/Mtok in, $15/Mtok out; cache read 0.1×,
        // cache write (5-min TTL) 1.25× of input.
        'claude-sonnet-4-6' => [
            'input'       => '3.00',
            'output'      => '15.00',
            'cache_read'  => '0.30',   // not logged yet (ai_query_log has no cache cols)
            'cache_write' => '3.75',   // not logged yet
        ],
        // Retired Sonnet 4 snapshot — kept so historical ai_query_log rows logged
        // under the old id still price correctly. Same list prices as 4.6.
        'claude-sonnet-4-20250514' => [
            'input'       => '3.00',
            'output'      => '15.00',
            'cache_read'  => '0.30',
            'cache_write' => '3.75',
        ],
    ];

    /**
     * Return the price row for a model, or null if the model is unpriced.
     *
     * @return array{input:string,output:string,cache_read:string,cache_write:string}|null
     */
    public static function forModel(string $model): ?array
    {
        $model = trim($model);
        return self::PRICES[$model] ?? null;
    }

    /** True when the model has an entry in the price table. */
    public static function isPriced(string $model): bool
    {
        return isset(self::PRICES[trim($model)]);
    }

    /** All model strings that currently carry a price (for diagnostics/tests). */
    public static function pricedModels(): array
    {
        return array_keys(self::PRICES);
    }
}
