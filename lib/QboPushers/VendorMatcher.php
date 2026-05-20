<?php
declare(strict_types=1);

/**
 * lib/QboPushers/VendorMatcher.php
 *
 * Auto-match FF vendors to QBO vendors via the same cascade as
 * CustomerMatcher (S-QBO-5), adapted for the vendor schema:
 *
 *   FF input field is `vendors.name` (NOT `vendors.company_name` — that
 *   column doesn't exist on vendors; vendors use plain `name`). This is
 *   the inverse of customers, which use `company_name`. See K-22 watch
 *   in S-QBO-7 session prompt + Trap #25 docs.
 *
 * Algorithm (mirrors D-QBO-5-2 — same constants, same pass order):
 *   1. Normalized exact name match              → confidence='exact'
 *   2. Levenshtein distance ≤ 3 on normalized   → confidence='high'
 *      (Skipped when max(len(ff), len(qbo)) < 5 to avoid trivial
 *       false positives on short names.)
 *   3. Email match (case-insensitive)           → confidence='medium'
 *   4. Phone last-7-digits match                → confidence='low'
 *   5. No match                                 → null
 *
 * Operator overrides (confidence='manual') always win — the auto_match
 * endpoint filters out manual-locked FF vendors before calling this.
 *
 * @session  S-QBO-7
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.5 (vendor mapping table)
 * @decision D-QBO-5-2 (auto-match cascade — established S-QBO-5,
 *                       reused verbatim for vendors)
 */

namespace FleetForge\QboPushers;

class VendorMatcher
{
    /** Minimum length for Levenshtein to be meaningful. */
    public const LEVENSHTEIN_MIN_LENGTH = 5;

    /** Maximum Levenshtein distance counted as 'high' confidence. */
    public const LEVENSHTEIN_MAX_DISTANCE = 3;

    /** Minimum number of digits before phone last-7 is considered reliable. */
    public const PHONE_MIN_DIGITS = 7;

    /**
     * Normalize a vendor/company name for comparison. Identical
     * semantics to CustomerMatcher::normalizeName — strips case,
     * punctuation, corporate suffixes (inc/ltd/llc/corp/limited/co),
     * and collapses whitespace.
     *
     * "Acme Corp Ltd." → "acme"
     * "Acme Inc"       → "acme"
     * "ACME, LLC"      → "acme"
     */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = preg_replace('/[.,;:\'"`\-_]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\b(inc|incorporated|ltd|limited|llc|corp|corporation|co)\b\.?/i', '', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Find the best QBO match for a single FF vendor.
     *
     * @param  array<string, mixed> $ffVendor      FF vendor row — at minimum `name`; optional email, phone
     * @param  array<int, array<string, mixed>> $qboVendors  List of normalized QBO records (VendorPuller::normalize() shape)
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(array $ffVendor, array $qboVendors): ?array
    {
        // vendors.name (NOT company_name) — this is the FF column for
        // vendors. Customers use company_name; vendors use name. K-22.
        $ffName  = self::normalizeName((string) ($ffVendor['name'] ?? ''));
        $ffEmail = strtolower(trim((string) ($ffVendor['email'] ?? '')));
        $ffPhone = preg_replace('/\D+/', '', (string) ($ffVendor['phone'] ?? '')) ?? '';

        // Pass 1: exact normalized name.
        if ($ffName !== '') {
            foreach ($qboVendors as $qbo) {
                $qboCandidate = $qbo['display_name'] !== '' ? $qbo['display_name'] : $qbo['company_name'];
                $qboName      = self::normalizeName((string) $qboCandidate);
                if ($qboName !== '' && $qboName === $ffName) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact'];
                }
            }
        }

        // Pass 2: Levenshtein ≤ 3, gated on max-side ≥ 5 to suppress
        // trivial false positives on short names.
        if ($ffName !== '') {
            foreach ($qboVendors as $qbo) {
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

        // Pass 3: email match (case-insensitive).
        if ($ffEmail !== '') {
            foreach ($qboVendors as $qbo) {
                $qboEmail = strtolower(trim((string) ($qbo['email'] ?? '')));
                if ($qboEmail !== '' && $qboEmail === $ffEmail) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'medium'];
                }
            }
        }

        // Pass 4: phone match by last 7 digits.
        if (strlen($ffPhone) >= self::PHONE_MIN_DIGITS) {
            $ffLast7 = substr($ffPhone, -7);
            foreach ($qboVendors as $qbo) {
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
     * Run the cascade across every active FF vendor vs the supplied
     * QBO list. Returns mapping decisions ready for upsert into
     * acc_qbo_vendor_map.
     *
     * Each decision array shape:
     *   ff_vendor_id      int|null
     *   qbo_vendor_id     string|null
     *   mapping_status    'mapped'|'ff_only'|'qbo_only'
     *   match_confidence  string|null
     *
     * Operator overrides (confidence='manual') are preserved BY THE
     * CALLER (auto_match.php endpoint); this function produces raw
     * decisions from (FF vendors) × (QBO vendors).
     *
     * @param  array<int, array<string, mixed>> $qboVendors
     * @return array<int, array<string, mixed>>
     */
    public static function matchAll(array $qboVendors): array
    {
        // vendors.name + .email + .phone — soft-delete via deleted_at.
        $ffVendors = db_select(
            "SELECT id, name, email, phone FROM vendors WHERE deleted_at IS NULL"
        );

        $decisions     = [];
        $matchedQboIds = [];

        foreach ($ffVendors as $ff) {
            $match = self::findBestMatch($ff, $qboVendors);
            if ($match !== null) {
                $decisions[] = [
                    'ff_vendor_id'    => (int) $ff['id'],
                    'qbo_vendor_id'   => $match['qbo_id'],
                    'mapping_status'  => 'mapped',
                    'match_confidence'=> $match['confidence'],
                ];
                $matchedQboIds[$match['qbo_id']] = true;
            } else {
                $decisions[] = [
                    'ff_vendor_id'    => (int) $ff['id'],
                    'qbo_vendor_id'   => null,
                    'mapping_status'  => 'ff_only',
                    'match_confidence'=> null,
                ];
            }
        }

        // Anything in QBO that didn't get matched becomes a qbo_only
        // row. The UI surfaces these for manual link or ignore.
        foreach ($qboVendors as $qbo) {
            if (!isset($matchedQboIds[$qbo['qbo_id']])) {
                $decisions[] = [
                    'ff_vendor_id'    => null,
                    'qbo_vendor_id'   => (string) $qbo['qbo_id'],
                    'mapping_status'  => 'qbo_only',
                    'match_confidence'=> null,
                ];
            }
        }

        return $decisions;
    }
}
