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
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │  // VERIFY — set to current Anthropic pricing.                         │
 * │  The seeded numbers below are PLACEHOLDERS. The operator will supply   │
 * │  the exact current list prices after recap; update PRICES then.        │
 * │  Source of truth for live prices: https://www.anthropic.com/pricing    │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Keys are the exact `model` strings FleetForge sends to the API (the
 * `ai.model` setting; default 'claude-sonnet-4-20250514' — see
 * lib/AI/ClaudeClient.php). A model NOT present in this map is treated as
 * "unpriced": CreditEstimator counts its spend as $0 and raises an
 * "unpriced models" flag so the UI can disclose the gap.
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
     *
     * // VERIFY — placeholder list prices; replace with current Anthropic numbers.
     */
    private const PRICES = [
        // Claude Sonnet 4 — the model FleetForge calls by default (ai.model).
        // // VERIFY — set to current Anthropic pricing.
        'claude-sonnet-4-20250514' => [
            'input'       => '3.00',   // // VERIFY $/Mtok input
            'output'      => '15.00',  // // VERIFY $/Mtok output
            'cache_read'  => '0.30',   // // VERIFY $/Mtok cache read  (not logged yet)
            'cache_write' => '3.75',   // // VERIFY $/Mtok cache write (not logged yet)
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
