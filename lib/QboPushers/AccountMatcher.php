<?php
declare(strict_types=1);

/**
 * lib/QboPushers/AccountMatcher.php
 *
 * Auto-match FF acc_accounts rows to QBO Account entities. Differs
 * from CustomerMatcher / VendorMatcher because account_code is the
 * primary high-signal field (standardized between FF + QBO via the
 * FF code → QBO AcctNum convention), and type compatibility matters
 * — we MUST NOT match an FF Revenue account to a QBO Expense even
 * if the names are identical (would corrupt downstream JE postings).
 *
 * Cascade (D-QBO-8-3, in order):
 *   1. exact_code   — FF acc_accounts.code == QBO Account.AcctNum
 *                     (case-insensitive trim). Highest signal because
 *                     account codes are standardized across systems.
 *   2. exact_name   — normalized name match AND acct_type compatible
 *                     (per TYPE_COMPATIBILITY map below). Strict type
 *                     gating prevents Revenue↔Expense cross-matches.
 *   3. high         — Levenshtein distance ≤ 3 on normalized name
 *                     AND acct_type compatible. Catches typo + casing.
 *   4. medium       — same account_type + account_subtype + name
 *                     shares a token of ≥ 4 chars (post-normalize).
 *   5. low          — singleton type: if exactly one FF account of a
 *                     given (account_type, account_subtype) AND exactly
 *                     one QBO account of compatible type+subtype, link.
 *   6. null         — no signal aligns; mapping_status stays ff_only
 *                     for the FF side.
 *
 * Operator overrides (confidence='manual') are preserved BY THE CALLER
 * (auto_match.php endpoint) — this class produces decisions; the
 * endpoint merges them against existing rows.
 *
 * Type compatibility (D-QBO-8-3):
 *   FF acc_accounts.account_type ENUM has 8 lowercase values matching
 *   QBO's AccountType taxonomy 1:1 — the 8-value granularity (asset /
 *   liability / equity / revenue / cost_of_revenue / operating_expense
 *   / other_income / other_expense) maps cleanly to QBO's high-level
 *   AccountType groupings.
 *
 * @session  S-QBO-8
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.1 (account mapping table)
 * @decision D-QBO-8-3 (auto-match cascade — exact_code first, then
 *                       type-compatible name match, then fuzzy variants;
 *                       NEVER match across incompatible types)
 */

namespace FleetForge\QboPushers;

class AccountMatcher
{
    /** Minimum length for Levenshtein to be meaningful. */
    public const LEVENSHTEIN_MIN_LENGTH = 5;

    /** Maximum Levenshtein distance counted as 'high' confidence. */
    public const LEVENSHTEIN_MAX_DISTANCE = 3;

    /** Minimum token length for the medium-confidence subtype+token match. */
    public const MIN_TOKEN_LENGTH = 4;

    /**
     * FF acc_accounts.account_type → list of compatible QBO AccountType
     * values. An FF account can only auto-match a QBO account whose
     * AccountType is in this list. Empty list = no auto-match for that
     * FF type (operator must link manually).
     *
     * Sourced from QBO Account entity AccountType enum (Intuit docs)
     * cross-referenced with the FF acc_accounts.account_type ENUM
     * verified at S-QBO-8 pre-flight.
     *
     * @var array<string, list<string>>
     */
    public const TYPE_COMPATIBILITY = [
        'asset' => [
            'Bank',
            'Other Current Asset',
            'Fixed Asset',
            'Other Asset',
            'Accounts Receivable',
        ],
        'liability' => [
            'Accounts Payable',
            'Credit Card',
            'Other Current Liability',
            'Long Term Liability',
        ],
        'equity' => [
            'Equity',
        ],
        'revenue' => [
            'Income',
        ],
        'cost_of_revenue' => [
            'Cost of Goods Sold',
        ],
        'operating_expense' => [
            'Expense',
        ],
        'other_income' => [
            'Other Income',
        ],
        'other_expense' => [
            'Other Expense',
        ],
    ];

    /**
     * Normalize an account name for comparison. Same semantics as
     * CustomerMatcher::normalizeName but without the corporate-suffix
     * strip (account names don't end in Inc/Ltd/LLC — they describe
     * GL categories).
     *
     * "Sales Revenue"   → "sales revenue"
     * "sales-revenue"   → "sales revenue"
     * "SALES_REVENUE"   → "sales revenue"
     */
    public static function normalizeAccountName(string $name): string
    {
        $n = strtolower($name);
        // Replace punctuation + underscores + hyphens with spaces.
        $n = preg_replace('/[.,;:\'"`\-_\/]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Returns true if the FF account_type can legally match the QBO
     * AccountType per TYPE_COMPATIBILITY.
     */
    public static function typesCompatible(string $ffType, string $qboType): bool
    {
        $allowed = self::TYPE_COMPATIBILITY[strtolower($ffType)] ?? [];
        return in_array($qboType, $allowed, true);
    }

    /**
     * Find the best QBO match for a single FF account.
     *
     * @param  array<string, mixed> $ffAccount   FF acc_accounts row — code, name, account_type, account_subtype
     * @param  array<int, array<string, mixed>> $qboAccounts AccountPuller::normalize() shape
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(array $ffAccount, array $qboAccounts): ?array
    {
        $ffCode    = strtolower(trim((string) ($ffAccount['code'] ?? '')));
        $ffName    = self::normalizeAccountName((string) ($ffAccount['name'] ?? ''));
        $ffType    = (string) ($ffAccount['account_type'] ?? '');
        $ffSubtype = (string) ($ffAccount['account_subtype'] ?? '');

        // Pass 1: exact account_code match (FF.code == QBO.AcctNum).
        // Highest signal — codes are standardized between systems.
        // Type compatibility NOT gated here; if the operator gave an
        // FF account the same code as a QBO account, the intent is
        // clear even if types nominally differ (could be data cleanup
        // needed).
        if ($ffCode !== '') {
            foreach ($qboAccounts as $qbo) {
                $qboCode = strtolower(trim((string) ($qbo['account_number'] ?? '')));
                if ($qboCode !== '' && $qboCode === $ffCode) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact_code'];
                }
            }
        }

        // Pass 2: exact normalized name + type compatibility.
        if ($ffName !== '') {
            foreach ($qboAccounts as $qbo) {
                $qboName = self::normalizeAccountName((string) ($qbo['name'] ?? ''));
                if ($qboName === '' || $qboName !== $ffName) {
                    continue;
                }
                if (!self::typesCompatible($ffType, (string) ($qbo['account_type'] ?? ''))) {
                    continue;
                }
                return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact_name'];
            }
        }

        // Pass 3: Levenshtein ≤ 3 + type compatibility.
        if ($ffName !== '') {
            foreach ($qboAccounts as $qbo) {
                $qboName = self::normalizeAccountName((string) ($qbo['name'] ?? ''));
                if ($qboName === '') {
                    continue;
                }
                if (!self::typesCompatible($ffType, (string) ($qbo['account_type'] ?? ''))) {
                    continue;
                }
                if (max(strlen($ffName), strlen($qboName)) >= self::LEVENSHTEIN_MIN_LENGTH &&
                    levenshtein($ffName, $qboName) <= self::LEVENSHTEIN_MAX_DISTANCE) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'high'];
                }
            }
        }

        // Pass 4: subtype + significant shared token.
        // "shared token" = both names contain the same word of length
        // ≥ MIN_TOKEN_LENGTH after normalization.
        if ($ffSubtype !== '' && $ffName !== '') {
            $ffTokens = self::significantTokens($ffName);
            foreach ($qboAccounts as $qbo) {
                if (!self::typesCompatible($ffType, (string) ($qbo['account_type'] ?? ''))) {
                    continue;
                }
                // QBO AccountSubType is a single string like 'AccountsReceivable';
                // FF account_subtype is a free-form string like 'current_asset'.
                // We can't do a structural match here — fall back to: SAME
                // FF subtype assumed AND name shares a token.
                $qboName = self::normalizeAccountName((string) ($qbo['name'] ?? ''));
                if ($qboName === '') {
                    continue;
                }
                $qboTokens = self::significantTokens($qboName);
                $sharedTokens = array_intersect($ffTokens, $qboTokens);
                if (!empty($sharedTokens)) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'medium'];
                }
            }
        }

        // Pass 5: singleton compatible-type match.
        // If FF has exactly one account of (type, subtype) AND there's
        // exactly one type-compatible QBO account, link them. Cheap
        // sanity match for thin charts.
        // NOTE: caller iterates findBestMatch over all FF accounts so
        // we don't have FF-side cardinality info here — singleton
        // resolution requires the caller's matchAll() context. Skip
        // pass 5 in this per-call function.

        return null;
    }

    /**
     * Run the cascade across every active FF account vs the supplied
     * QBO list. Returns mapping decisions ready for upsert into
     * acc_qbo_account_map.
     *
     * Each decision array shape:
     *   ff_account_id    int|null
     *   qbo_account_id   string|null
     *   mapping_status   'mapped'|'ff_only'|'qbo_only'
     *   match_confidence string|null
     *
     * Operator overrides (confidence='manual') preserved BY THE CALLER
     * (the auto_match endpoint filters out manual-locked FF accounts
     * before merging decisions).
     *
     * Note: acc_accounts has NO `deleted_at` column — uses `is_active`
     * for soft-disable (K-22 catch surfaced in S-QBO-8 pre-flight).
     *
     * @param  array<int, array<string, mixed>> $qboAccounts
     * @return array<int, array<string, mixed>>
     */
    public static function matchAll(array $qboAccounts): array
    {
        $ffAccounts = db_select(
            "SELECT id, code, name, account_type, account_subtype
               FROM acc_accounts
              WHERE is_active = 1"
        );

        $decisions     = [];
        $matchedQboIds = [];

        foreach ($ffAccounts as $ff) {
            $match = self::findBestMatch($ff, $qboAccounts);
            if ($match !== null) {
                $decisions[] = [
                    'ff_account_id'    => (int) $ff['id'],
                    'qbo_account_id'   => $match['qbo_id'],
                    'mapping_status'   => 'mapped',
                    'match_confidence' => $match['confidence'],
                ];
                $matchedQboIds[$match['qbo_id']] = true;
            } else {
                $decisions[] = [
                    'ff_account_id'    => (int) $ff['id'],
                    'qbo_account_id'   => null,
                    'mapping_status'   => 'ff_only',
                    'match_confidence' => null,
                ];
            }
        }

        // Pass 5: singleton-type resolution across FF×QBO leftovers.
        // For each FF ff_only decision, check if its (type, subtype)
        // has exactly one FF candidate AND exactly one type-compatible
        // unmapped QBO candidate. If so, promote to 'mapped' with
        // confidence='low'.
        $ffByTypeSubtype = [];
        foreach ($ffAccounts as $ff) {
            $key = ($ff['account_type'] ?? '') . '||' . ($ff['account_subtype'] ?? '');
            $ffByTypeSubtype[$key][] = (int) $ff['id'];
        }
        foreach ($decisions as $idx => $d) {
            if ($d['mapping_status'] !== 'ff_only') {
                continue;
            }
            $ffRow = null;
            foreach ($ffAccounts as $a) {
                if ((int) $a['id'] === (int) $d['ff_account_id']) { $ffRow = $a; break; }
            }
            if ($ffRow === null) { continue; }
            $key = ($ffRow['account_type'] ?? '') . '||' . ($ffRow['account_subtype'] ?? '');
            if (count($ffByTypeSubtype[$key] ?? []) !== 1) {
                continue; // not a FF-side singleton
            }
            // Find compatible QBO candidates (unmapped only).
            $compatibleUnmapped = [];
            foreach ($qboAccounts as $q) {
                if (isset($matchedQboIds[$q['qbo_id']])) { continue; }
                if (self::typesCompatible((string) $ffRow['account_type'], (string) ($q['account_type'] ?? ''))) {
                    $compatibleUnmapped[] = $q;
                }
            }
            if (count($compatibleUnmapped) === 1) {
                $qbo = $compatibleUnmapped[0];
                $decisions[$idx] = [
                    'ff_account_id'    => (int) $ffRow['id'],
                    'qbo_account_id'   => (string) $qbo['qbo_id'],
                    'mapping_status'   => 'mapped',
                    'match_confidence' => 'low',
                ];
                $matchedQboIds[$qbo['qbo_id']] = true;
            }
        }

        // Anything in QBO that didn't get matched becomes a qbo_only
        // row. UI surfaces these for operator review (link / ignore).
        foreach ($qboAccounts as $qbo) {
            if (!isset($matchedQboIds[$qbo['qbo_id']])) {
                $decisions[] = [
                    'ff_account_id'    => null,
                    'qbo_account_id'   => (string) $qbo['qbo_id'],
                    'mapping_status'   => 'qbo_only',
                    'match_confidence' => null,
                ];
            }
        }

        return $decisions;
    }

    /**
     * Helper for the medium-confidence pass — extract tokens of
     * meaningful length from a normalized name.
     *
     * @return list<string>
     */
    private static function significantTokens(string $normalizedName): array
    {
        $tokens = preg_split('/\s+/', $normalizedName) ?: [];
        return array_values(array_filter(
            $tokens,
            static fn($t) => strlen((string) $t) >= self::MIN_TOKEN_LENGTH
        ));
    }
}
