<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CustomerMatcher.php
 *
 * Auto-match FF customers to QBO customers via a cascade of
 * increasingly weaker signals. Operator overrides (confidence=manual)
 * always win — the cascade only writes match rows for customers that
 * don't already have a manual lock.
 *
 * Algorithm (D-QBO-5-2, pass order matters):
 *   1. Normalized exact name match            → confidence='exact'
 *      Strip case, punctuation, corporate suffixes (Inc/Ltd/LLC/
 *      Corp/Limited/Co) then equality compare.
 *   2. Levenshtein distance ≤ 3 on normalized → confidence='high'
 *      Catches typos ("Acmee Corp" ↔ "Acme Corp"), variant spelling,
 *      missing/extra characters. Skipped for names < 5 chars (too
 *      noisy — short names trivially match within distance 3).
 *   3. Email match (case-insensitive)         → confidence='medium'
 *      Strong signal but rare in B2B trucking — customer emails are
 *      often per-person rather than per-company.
 *   4. Phone last-7-digits match              → confidence='low'
 *      Catches country-code variations ("+1 604 555 1234" ↔
 *      "604-555-1234" ↔ "(604) 555 1234"). Last 7 digits handles
 *      international vs. domestic formatting.
 *   5. No match                               → return null
 *
 * Why this order? Confidence reflects false-positive risk:
 *   - Normalized exact: low risk (intentional naming).
 *   - Levenshtein ≤ 3: medium risk (could merge "Acme Corp" + "Acne Corp"
 *     — operator should confirm via UI before pushing).
 *   - Email: low risk IF email is populated and not a personal address.
 *   - Phone: higher risk (shared phones across related entities).
 *
 * Operator can always override via "Override Match" in the UI; the
 * cascade is a starting point, not a final answer.
 *
 * @session  S-QBO-5
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.1 (Customer field map)
 * @decision D-QBO-5-2 (auto-match cascade + normalization rules)
 */

namespace FleetForge\QboPushers;

class CustomerMatcher
{
    /** Minimum length for Levenshtein to be meaningful. Below this,
     *  short names trivially fall within distance 3 (false positives). */
    public const LEVENSHTEIN_MIN_LENGTH = 5;

    /** Maximum Levenshtein distance between normalized names that
     *  still counts as a 'high'-confidence match. Empirically tuned
     *  for trucking-industry company names. */
    public const LEVENSHTEIN_MAX_DISTANCE = 3;

    /** Minimum number of phone digits before a last-7 comparison is
     *  considered reliable. Phones with <7 digits are extensions or
     *  partial numbers — not safe to match on. */
    public const PHONE_MIN_DIGITS = 7;

    /**
     * Normalize a company/customer name for comparison.
     *
     *   - Lowercase
     *   - Replace punctuation with spaces (.,;:'"`-_ → space)
     *   - Strip corporate suffixes (inc, ltd, llc, corp, corporation,
     *     incorporated, limited, co) — whole-word + optional period
     *   - Collapse multiple spaces into one
     *   - Trim leading/trailing whitespace
     *
     * "Acme Corp Ltd." → "acme"
     * "Acme Inc"       → "acme"
     * "ACME, LLC"      → "acme"
     */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = preg_replace('/[.,;:\'"`\-_]+/', ' ', $n) ?? $n;
        // Whole-word corporate suffix strip. The optional trailing
        // period catches "Inc." / "Ltd." after the punctuation pass
        // already converted "." to space, so this is belt-and-braces.
        $n = preg_replace('/\b(inc|incorporated|ltd|limited|llc|corp|corporation|co)\b\.?/i', '', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Find the best QBO match for a single FF customer.
     *
     * @param  array<string, mixed> $ffCustomer    FF customer row — at minimum company_name; optional email, phone
     * @param  array<int, array<string, mixed>> $qboCustomers List of normalized QBO records (CustomerPuller::normalize() output shape)
     * @param  array<string, bool> $claimedQboIds  qbo_id keys already claimed by an earlier iteration in matchAll() — each pass skips claimed candidates (D-QBO-MATCHER-1).
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(array $ffCustomer, array $qboCustomers, array $claimedQboIds = []): ?array
    {
        $ffName  = self::normalizeName((string) ($ffCustomer['company_name'] ?? ''));
        $ffEmail = strtolower(trim((string) ($ffCustomer['email'] ?? '')));
        $ffPhone = preg_replace('/\D+/', '', (string) ($ffCustomer['phone'] ?? '')) ?? '';

        // Pass 1: exact normalized name. Skip when ffName is empty —
        // can't match an empty string against anything sensibly.
        if ($ffName !== '') {
            foreach ($qboCustomers as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboCandidate = $qbo['display_name'] !== '' ? $qbo['display_name'] : $qbo['company_name'];
                $qboName      = self::normalizeName((string) $qboCandidate);
                if ($qboName !== '' && $qboName === $ffName) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact'];
                }
            }
        }

        // Pass 2: Levenshtein ≤ 3. Gate on the LONGER of the two names —
        // requires at least one side to be meaningfully long. Two short
        // names cap out trivially within distance 3 (false positives);
        // one long + one short is fine as long as the long side is real.
        if ($ffName !== '') {
            foreach ($qboCustomers as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboCandidate = $qbo['display_name'] !== '' ? $qbo['display_name'] : $qbo['company_name'];
                $qboName      = self::normalizeName((string) $qboCandidate);
                if ($qboName === '') {
                    continue;
                }
                if (max(strlen($ffName), strlen($qboName)) >= self::LEVENSHTEIN_MIN_LENGTH &&
                    levenshtein($ffName, $qboName) <= self::LEVENSHTEIN_MAX_DISTANCE) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'high'];
                }
            }
        }

        // Pass 3: email match (case-insensitive, both non-empty).
        if ($ffEmail !== '') {
            foreach ($qboCustomers as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboEmail = strtolower(trim((string) ($qbo['email'] ?? '')));
                if ($qboEmail !== '' && $qboEmail === $ffEmail) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'medium'];
                }
            }
        }

        // Pass 4: phone match by last 7 digits.
        if (strlen($ffPhone) >= self::PHONE_MIN_DIGITS) {
            $ffLast7 = substr($ffPhone, -7);
            foreach ($qboCustomers as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboPhone = preg_replace('/\D+/', '', (string) ($qbo['phone'] ?? '')) ?? '';
                if (strlen($qboPhone) >= self::PHONE_MIN_DIGITS &&
                    substr($qboPhone, -7) === $ffLast7) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'low'];
                }
            }
        }

        return null;
    }

    /**
     * Run the cascade across every active FF customer vs the supplied
     * QBO list. Returns a list of mapping decisions ready for upsert
     * into acc_qbo_customer_map.
     *
     * Each decision array shape:
     *   ff_customer_id    int|null
     *   qbo_customer_id   string|null
     *   mapping_status    'mapped'|'ff_only'|'qbo_only'
     *   match_confidence  string|null
     *
     * Operator overrides (confidence='manual') are preserved BY THE
     * CALLER (auto_match.php endpoint) — this function doesn't read
     * acc_qbo_customer_map itself; it just produces decisions from
     * (FF customers) × (QBO customers). The endpoint merges these
     * decisions against existing rows.
     *
     * @param  array<int, array<string, mixed>> $qboCustomers
     * @return array<int, array<string, mixed>>
     */
    public static function matchAll(array $qboCustomers): array
    {
        $ffCustomers = db_select(
            "SELECT id, company_name, email, phone FROM customers WHERE deleted_at IS NULL"
        );

        $decisions     = [];
        $matchedQboIds = [];

        foreach ($ffCustomers as $ff) {
            // Pass $matchedQboIds so claimed QBO ids are skipped in
            // subsequent iterations (D-QBO-MATCHER-1).
            $match = self::findBestMatch($ff, $qboCustomers, $matchedQboIds);
            if ($match !== null) {
                $decisions[] = [
                    'ff_customer_id'   => (int) $ff['id'],
                    'qbo_customer_id'  => $match['qbo_id'],
                    'mapping_status'   => 'mapped',
                    'match_confidence' => $match['confidence'],
                ];
                $matchedQboIds[$match['qbo_id']] = true;
            } else {
                $decisions[] = [
                    'ff_customer_id'   => (int) $ff['id'],
                    'qbo_customer_id'  => null,
                    'mapping_status'   => 'ff_only',
                    'match_confidence' => null,
                ];
            }
        }

        // Anything in QBO that didn't get matched becomes a qbo_only
        // row. The UI surfaces these so the operator can either link
        // them manually or mark them ignored.
        foreach ($qboCustomers as $qbo) {
            if (!isset($matchedQboIds[$qbo['qbo_id']])) {
                $decisions[] = [
                    'ff_customer_id'   => null,
                    'qbo_customer_id'  => (string) $qbo['qbo_id'],
                    'mapping_status'   => 'qbo_only',
                    'match_confidence' => null,
                ];
            }
        }

        return $decisions;
    }
}
