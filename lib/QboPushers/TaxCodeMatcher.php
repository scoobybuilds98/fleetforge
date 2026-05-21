<?php
declare(strict_types=1);

/**
 * lib/QboPushers/TaxCodeMatcher.php
 *
 * Auto-match FF tax_rates rows to QBO TaxCode entities. The cascade
 * is informational only (D-QBO-9-3 — FF computes tax authoritatively;
 * QBO accepts override via TxnTaxDetail.TotalTax + TaxCodeRef='NON'
 * in S-QBO-11 invoice push). The mapping table exists so the
 * accountant sees meaningful labels in QBO reports rather than
 * everything reading as 'NON'.
 *
 * Cascade (D-QBO-9):
 *   1. exact_name   — FF tax_rates.name == QBO TaxCode.Name
 *                     (case-insensitive trim). High signal because
 *                     accountant typically names FF rates after
 *                     QBO label conventions.
 *   2. exact_rate   — FF jurisdiction (province) match AND FF rate
 *                     sum (gst_rate + pst_rate + hst_rate) within
 *                     ±0.01% of QBO effective rate. Requires
 *                     rate-resolution which v1 does NOT support
 *                     (QBO TaxRate refs not resolved per Puller
 *                     caveat); pass skipped until TaxRatePuller
 *                     follow-up ships.
 *   3. high         — FF province + name-substring match. E.g. FF
 *                     "Ontario HST" + QBO Name contains "HST ON" or
 *                     "Ontario" — high-confidence partial match.
 *   4. manual       — operator override (preserved across re-runs)
 *
 * D-QBO-9-2 override target identification: identifyOverrideTarget()
 * finds the QBO TaxCode with Name='NON' (case-insensitive) in the
 * pulled list. This is the load-bearing piece of S-QBO-9 — the
 * setting written from this discovery (settings.quickbooks.
 * tax_override_code_id) is what makes S-QBO-11 invoice push functional.
 *
 * @session  S-QBO-9
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.2 (tax code mapping table)
 * @decision D-QBO-9-2 (NON override target — auto-wire to settings),
 *           D-QBO-9-3 (FF→QBO mapping informational; FF authoritative),
 *           D-QBO-9-4 (effective-date awareness — snapshot CURRENT
 *                       active FF rate at mapping time),
 *           D-QBO-9-5 (active-only filter)
 */

namespace FleetForge\QboPushers;

class TaxCodeMatcher
{
    /** Tolerance for exact_rate confidence: ±0.01% (0.0001 in decimal). */
    public const RATE_TOLERANCE = 0.0001;

    /**
     * Normalize a tax code name for comparison. Lowercase + collapse
     * whitespace + strip punctuation. Tax names are short and
     * standardized; no corporate-suffix strip needed.
     */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = preg_replace('/[.,;:\'"`\-_\/]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Identify the 'NON' override target from a pulled QBO TaxCode list.
     * D-QBO-9-2: returns the QBO TaxCode entry where Name='NON'
     * case-insensitive exact. Returns null if not found (critical
     * error condition — S-QBO-11 invoice push would be blocked).
     *
     * @param  array<int, array<string, mixed>> $qboTaxCodes TaxCodePuller::normalize() output shape
     * @return array<string, mixed>|null
     */
    public static function identifyOverrideTarget(array $qboTaxCodes): ?array
    {
        foreach ($qboTaxCodes as $tc) {
            $name = trim((string) ($tc['name'] ?? ''));
            if (strcasecmp($name, 'NON') === 0) {
                return $tc;
            }
        }
        return null;
    }

    /**
     * Find the best QBO match for a single FF tax_rate.
     *
     * @param  array<string, mixed> $ffTaxRate    FF tax_rates row — at minimum name; optional province + 3 rate columns
     * @param  array<int, array<string, mixed>> $qboTaxCodes TaxCodePuller::normalize() shape
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(array $ffTaxRate, array $qboTaxCodes): ?array
    {
        $ffName     = self::normalizeName((string) ($ffTaxRate['name']     ?? ''));
        $ffProvince = strtolower(trim((string) ($ffTaxRate['province']     ?? '')));

        // Pass 1: exact normalized name match.
        if ($ffName !== '') {
            foreach ($qboTaxCodes as $qbo) {
                $qboName = self::normalizeName((string) ($qbo['name'] ?? ''));
                if ($qboName !== '' && $qboName === $ffName) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact_name'];
                }
            }
        }

        // Pass 2: exact_rate — SKIPPED in v1. Requires resolved QBO
        // rate values which need a separate TaxRatePuller (deferred).
        // When added, signature: jurisdiction match + rate within
        // RATE_TOLERANCE.

        // Pass 3: high partial — province match + name substring.
        // E.g. FF "Ontario HST" + QBO Name "HST ON" → both contain "ON"
        // and FF has province=ON. Heuristic: FF province appears in
        // QBO name (case-insensitive substring).
        if ($ffProvince !== '' && $ffName !== '') {
            foreach ($qboTaxCodes as $qbo) {
                $qboName = self::normalizeName((string) ($qbo['name'] ?? ''));
                if ($qboName === '') {
                    continue;
                }
                // Province appears in QBO name AND a name token from FF
                // (gst/hst/pst/qst) also appears in QBO name.
                if (str_contains($qboName, $ffProvince)) {
                    $ffTokens = ['gst', 'hst', 'pst', 'qst'];
                    foreach ($ffTokens as $tok) {
                        if (str_contains($ffName, $tok) && str_contains($qboName, $tok)) {
                            return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'high'];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Run the cascade across every active FF tax_rate vs the supplied
     * QBO list. Returns mapping decisions ready for upsert into
     * acc_qbo_tax_code_map.
     *
     * D-QBO-9-5: filter to FF tax_rates with is_active=1 AND currently
     * effective (effective_from <= today AND (effective_to IS NULL OR
     * effective_to > today)) to exclude historical rows from auto-match.
     * Operator can manually link historical rates if needed via UI.
     *
     * Each decision array shape:
     *   ff_tax_rate_id    int|null
     *   qbo_tax_code_id   string|null
     *   mapping_status    'mapped'|'ff_only'|'qbo_only'
     *   match_confidence  string|null
     *
     * @param  array<int, array<string, mixed>> $qboTaxCodes
     * @return array<int, array<string, mixed>>
     */
    public static function matchAll(array $qboTaxCodes): array
    {
        $ffRates = db_select(
            "SELECT id, name, province, country, gst_rate, pst_rate, hst_rate, effective_from, effective_to
               FROM tax_rates
              WHERE is_active = 1
                AND effective_from <= CURDATE()
                AND (effective_to IS NULL OR effective_to > CURDATE())"
        );

        $decisions     = [];
        $matchedQboIds = [];

        foreach ($ffRates as $ff) {
            $match = self::findBestMatch($ff, $qboTaxCodes);
            if ($match !== null) {
                $decisions[] = [
                    'ff_tax_rate_id'   => (int) $ff['id'],
                    'qbo_tax_code_id'  => $match['qbo_id'],
                    'mapping_status'   => 'mapped',
                    'match_confidence' => $match['confidence'],
                ];
                $matchedQboIds[$match['qbo_id']] = true;
            } else {
                $decisions[] = [
                    'ff_tax_rate_id'   => (int) $ff['id'],
                    'qbo_tax_code_id'  => null,
                    'mapping_status'   => 'ff_only',
                    'match_confidence' => null,
                ];
            }
        }

        // Anything in QBO not matched → qbo_only.
        foreach ($qboTaxCodes as $qbo) {
            if (!isset($matchedQboIds[$qbo['qbo_id']])) {
                $decisions[] = [
                    'ff_tax_rate_id'   => null,
                    'qbo_tax_code_id'  => (string) $qbo['qbo_id'],
                    'mapping_status'   => 'qbo_only',
                    'match_confidence' => null,
                ];
            }
        }

        return $decisions;
    }

    /**
     * Helper for snapshotting an FF tax_rate row's effective rate as a
     * single-number proxy for divergence detection. Sums the 3 rate
     * columns since they're mutually-exclusive in CRA-context
     * (GST-only / PST+GST composite / HST single-rate). Returns
     * decimal in the same shape as the source columns (decimal(8,6)).
     */
    public static function rateSum(array $ffTaxRate): float
    {
        return (float) ($ffTaxRate['gst_rate'] ?? 0)
             + (float) ($ffTaxRate['pst_rate'] ?? 0)
             + (float) ($ffTaxRate['hst_rate'] ?? 0);
    }
}
