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
 *   4. medium       — type-compatible + name shares a token of ≥ 4
 *                     chars (post-normalize) AND subtype-agreement
 *                     per SUBTYPE_EQUIVALENCE (D-QBO-MATCHER-2).
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
 * Claimed-set tracking (D-QBO-MATCHER-1): matchAll owns a $claimedQboIds
 * set and passes it into findBestMatch. Each pass skips QBO candidates
 * already claimed by an earlier FF row, preventing the auto_match.php
 * UPDATE loop from overwriting an earlier mapping when two FF rows
 * compete for the same QBO Account.
 *
 * Type compatibility (D-QBO-8-3):
 *   FF acc_accounts.account_type ENUM has 8 lowercase values matching
 *   QBO's AccountType taxonomy 1:1 — the 8-value granularity (asset /
 *   liability / equity / revenue / cost_of_revenue / operating_expense
 *   / other_income / other_expense) maps cleanly to QBO's high-level
 *   AccountType groupings.
 *
 * @session  S-QBO-8, S-QBO-MATCHER-GREEDY-FIX (Bug 1 + Bug 2 fix)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.1 (account mapping table)
 * @decision D-QBO-8-3 (auto-match cascade — exact_code first, then
 *                       type-compatible name match, then fuzzy variants;
 *                       NEVER match across incompatible types),
 *           D-QBO-MATCHER-1 (claimed-set tracking — findBestMatch sees
 *                       qbo_ids already claimed by an earlier iteration
 *                       and skips them across all passes),
 *           D-QBO-MATCHER-2 (subtype-agreement gate at medium pass —
 *                       FF.acct_subtype must map to a compatible QBO
 *                       AccountSubType per SUBTYPE_EQUIVALENCE; FF keys
 *                       are lowercase snake_case per the acc_accounts
 *                       ENUM, NOT QBO PascalCase — K-22 catch resolved
 *                       at session pre-flight per
 *                       feedback_trust_file_over_prompt)
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
     * Subtype-agreement gate for the medium-confidence pass (D-QBO-MATCHER-2).
     * FF.acct_subtype (lowercase snake_case per acc_accounts ENUM) →
     * list of compatible QBO AccountSubType values (PascalCase per QBO
     * API taxonomy; note OtherCurrent* are PLURAL).
     *
     * The gate ONLY applies to the medium-confidence pass — exact_code
     * and exact_name passes have stronger signal and don't need
     * subtype gating on top. Subtypes NOT listed here (operating_expense,
     * other) fall back to TYPE_COMPATIBILITY-only checking, which is
     * permissive on subtype but still type-gated. Empty QBO subtype is
     * permissive (QBO has many subtypes we don't enumerate; operator
     * can manual-link the edge cases).
     *
     * K-22 (S-QBO-MATCHER-GREEDY-FIX pre-flight): the original session
     * prompt baseline used PascalCase keys (as if FF used QBO naming);
     * actual FF.acct_subtype is lowercase snake_case. Resolved via
     * AskUserQuestion per feedback_trust_file_over_prompt; keys here
     * follow the live FF schema.
     *
     * @var array<string, list<string>>
     */
    public const SUBTYPE_EQUIVALENCE = [
        'current_asset' => [
            'AccountsReceivable',
            'OtherCurrentAssets',
            'PrepaidExpenses',
            'Inventory',
            'UndepositedFunds',
            'AllowanceForBadDebts',
        ],
        'fixed_asset' => [
            'AccumulatedDepreciation',
            'Vehicles',
            'Equipment',
            'MachineryAndEquipment',
            'Buildings',
            'Land',
            'LeaseholdImprovements',
            'OtherFixedAssets',
            'FurnitureAndFixtures',
            'FixedAssetOtherToolsEquipment',
        ],
        'current_liability' => [
            'AccountsPayable',
            'CreditCard',
            'OtherCurrentLiabilities',
            'GlobalTaxPayable',
            'PayrollTaxPayable',
            'SalesTaxPayable',
            'LineOfCredit',
        ],
        'long_term_liability' => [
            'OtherLongTermLiabilities',
            'NotesPayable',
            'LongTermDebt',
            'ShareholderNotesPayable',
        ],
        'equity' => [
            'OpeningBalanceEquity',
            'RetainedEarnings',
            'Equity',
            'PartnersEquity',
            'OwnersEquity',
            'PaidInCapitalOrSurplus',
            'CommonStock',
            'PreferredStock',
        ],
        'revenue' => [
            'SalesOfProductIncome',
            'ServiceFeeIncome',
            'OtherPrimaryIncome',
            'NonProfitIncome',
            'DiscountsRefundsGiven',
        ],
        'cost_of_revenue' => [
            'SuppliesMaterialsCogs',
            'CostOfLaborCos',
            'EquipmentRentalCos',
            'ShippingFreightDeliveryCos',
            'OtherCostsOfServiceCos',
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
     * @param  array<string, bool> $claimedQboIds  qbo_id keys already claimed by an earlier iteration in matchAll(). Each pass skips candidates whose qbo_id is in this set, preventing the auto_match.php UPDATE loop from overwriting an earlier mapping (D-QBO-MATCHER-1).
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(array $ffAccount, array $qboAccounts, array $claimedQboIds = []): ?array
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
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboCode = strtolower(trim((string) ($qbo['account_number'] ?? '')));
                if ($qboCode !== '' && $qboCode === $ffCode) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact_code'];
                }
            }
        }

        // Pass 2: exact normalized name + type compatibility.
        if ($ffName !== '') {
            foreach ($qboAccounts as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
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
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
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

        // Pass 4: type-compatible + significant shared token + subtype-agreement.
        // "shared token" = both names contain the same word of length
        // ≥ MIN_TOKEN_LENGTH after normalization.
        // Subtype-agreement (D-QBO-MATCHER-2): when FF.acct_subtype is in
        // SUBTYPE_EQUIVALENCE, QBO.AccountSubType must be in the compat
        // list. Unknown FF subtypes fall through to type-only check
        // (existing behavior). Empty QBO subtype is permissive.
        if ($ffName !== '') {
            $ffTokens = self::significantTokens($ffName);
            foreach ($qboAccounts as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                if (!self::typesCompatible($ffType, (string) ($qbo['account_type'] ?? ''))) {
                    continue;
                }
                $qboName = self::normalizeAccountName((string) ($qbo['name'] ?? ''));
                if ($qboName === '') {
                    continue;
                }
                if (isset(self::SUBTYPE_EQUIVALENCE[$ffSubtype])) {
                    $qboSubtype = (string) ($qbo['account_subtype'] ?? '');
                    if ($qboSubtype !== '' && !in_array($qboSubtype, self::SUBTYPE_EQUIVALENCE[$ffSubtype], true)) {
                        continue;
                    }
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

        // Pass 0: wedge half-state rescue (D-MATCHER-WEDGE-RESCUE).
        // Detects acc_qbo_account_map rows in the "wedged half-state"
        // surfaced by S-QBO-LIVE-VERIFY-RERUN-2026-05-26 (row 4302):
        // mapping_status='ff_only' + qbo_account_id IS NULL but QBO
        // breadcrumb columns populated (residue from a prior pull cycle
        // that an operator unlinked, leaving FF→QBO type-info but no
        // claimable qbo_only counterpart). The standard cascade's UPDATE
        // is WHERE qbo_account_id=? which can't reach NULL, so wedged
        // rows are otherwise invisible until manual SQL intervention.
        //
        // Rescue strategy: look up matching qbo_only rows by exact
        // qbo_fully_qualified_name (preferred — most unique) then
        // qbo_name (fallback). When a match is found, the wedged row
        // absorbs the qbo_only row's qbo_account_id + sync_token and
        // the redundant qbo_only row is deleted. Claimed qbo_ids feed
        // into the standard $matchedQboIds set so the subsequent
        // cascade doesn't double-claim (D-MATCHER-CLAIMED-SET).
        $matchedQboIds = self::rescueHalfStateRows($matchedQboIds);

        foreach ($ffAccounts as $ff) {
            // Pass $matchedQboIds so findBestMatch skips QBO candidates
            // already claimed by an earlier FF row (D-QBO-MATCHER-1).
            // Iteration order = chart_of_accounts.id ascending; first
            // FF to claim a QBO Account wins. A future ranking pass
            // (S-QBO-MATCHER-PRIORITY-ORDER) could prioritize critical
            // accounts and exact-code candidates first.
            $match = self::findBestMatch($ff, $qboAccounts, $matchedQboIds);
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

    /**
     * Pass 0 of matchAll(): rescue wedged half-state rows in
     * acc_qbo_account_map.
     *
     * Wedge definition: mapping_status='ff_only' + qbo_account_id IS NULL
     * + at least one populated QBO breadcrumb column (qbo_name OR
     * qbo_fully_qualified_name). This is leftover residue from a prior
     * pull→unlink cycle: the operator unlinked the FF↔QBO pair via
     * save_mapping's unlink action which sets ff_only + nulls
     * qbo_account_id but preserves the QBO breadcrumb metadata as a
     * forensic trail. The standard cascade can't reach these (its UPDATE
     * uses WHERE qbo_account_id=?, which NULL never matches), so they
     * stay wedged until manual SQL intervention.
     *
     * For each wedged row, look for a matching qbo_only candidate by
     * exact qbo_fully_qualified_name (preferred — usually unique in the
     * COA) then qbo_name (fallback). When matched, the wedged row
     * absorbs the candidate's qbo_account_id + qbo_sync_token, flips
     * to mapping_status='mapped' with match_confidence='high' +
     * match_notes='wedge_recovery: linked by <breadcrumb>'; the
     * redundant qbo_only candidate row is deleted (data is now carried
     * by the wedged row, which has the FF link).
     *
     * Claimed qbo_account_ids returned so the outer matchAll() cascade
     * doesn't try to re-match them (D-MATCHER-CLAIMED-SET discipline).
     *
     * @session  S-QBO-MATCHER-WEDGE-RECOVERY
     * @decision D-MATCHER-WEDGE-RESCUE
     *
     * @param  array<string, bool> $existingClaimedQboIds  any qbo_ids
     *         already claimed before this pass (currently empty; the
     *         param exists to keep the signature future-compatible).
     * @return array<string, bool>  Updated claimed-set keyed by qbo_id.
     */
    private static function rescueHalfStateRows(array $existingClaimedQboIds): array
    {
        $wedged = db_select(
            "SELECT id, ff_account_id, qbo_name, qbo_fully_qualified_name
               FROM acc_qbo_account_map
              WHERE mapping_status = 'ff_only'
                AND qbo_account_id IS NULL
                AND (qbo_name IS NOT NULL OR qbo_fully_qualified_name IS NOT NULL)"
        );

        $claimedQboIds = $existingClaimedQboIds;
        $rescuedCount  = 0;
        $now           = date('Y-m-d H:i:s');

        foreach ($wedged as $w) {
            // Prefer qbo_fully_qualified_name (more unique — disambiguates
            // sub-accounts like 'Sales:Service Income' vs 'Sales:Product
            // Income'). Fall back to qbo_name when fully-qualified is null.
            $candidate = null;
            $matchedBy = null;

            if (!empty($w['qbo_fully_qualified_name'])) {
                $candidate = db_row(
                    "SELECT id, qbo_account_id, qbo_sync_token, qbo_name, qbo_fully_qualified_name
                       FROM acc_qbo_account_map
                      WHERE mapping_status = 'qbo_only'
                        AND qbo_account_id IS NOT NULL
                        AND qbo_fully_qualified_name = ?
                      ORDER BY id ASC
                      LIMIT 1",
                    [(string) $w['qbo_fully_qualified_name']]
                );
                if ($candidate !== null) { $matchedBy = 'qbo_fully_qualified_name'; }
            }

            if ($candidate === null && !empty($w['qbo_name'])) {
                $candidate = db_row(
                    "SELECT id, qbo_account_id, qbo_sync_token, qbo_name, qbo_fully_qualified_name
                       FROM acc_qbo_account_map
                      WHERE mapping_status = 'qbo_only'
                        AND qbo_account_id IS NOT NULL
                        AND qbo_name = ?
                      ORDER BY id ASC
                      LIMIT 1",
                    [(string) $w['qbo_name']]
                );
                if ($candidate !== null) { $matchedBy = 'qbo_name'; }
            }

            if ($candidate === null) {
                continue;  // No match — leave wedged row alone (no false positives).
            }

            // Claimed-set discipline: if this candidate's qbo_account_id was
            // already claimed by an earlier wedge in this same rescue pass,
            // skip — first wedge wins. Defensive; rare in practice (would
            // require two wedged rows pointing at the same breadcrumb).
            $candidateQboId = (string) $candidate['qbo_account_id'];
            if (isset($claimedQboIds[$candidateQboId])) {
                continue;
            }

            $note = "wedge_recovery: linked by {$matchedBy}='" .
                    ($matchedBy === 'qbo_fully_qualified_name'
                        ? (string) $w['qbo_fully_qualified_name']
                        : (string) $w['qbo_name']) . "'";

            // ORDER MATTERS: DELETE the qbo_only candidate FIRST, then
            // UPDATE the wedge to absorb its qbo_account_id. If we did
            // UPDATE → DELETE, the UPDATE would briefly create two rows
            // with the same qbo_account_id, violating the UNIQUE
            // constraint `uq_qbo_account`. The DELETE→UPDATE order is
            // the only path through the UNIQUE constraint short of a
            // transaction with DEFERRABLE constraints (which MySQL
            // doesn't support).
            db_execute(
                "DELETE FROM acc_qbo_account_map WHERE id = ?",
                [(int) $candidate['id']]
            );
            db_execute(
                "UPDATE acc_qbo_account_map
                    SET qbo_account_id   = ?,
                        qbo_sync_token   = ?,
                        qbo_name         = COALESCE(?, qbo_name),
                        qbo_fully_qualified_name = COALESCE(?, qbo_fully_qualified_name),
                        mapping_status   = 'mapped',
                        match_confidence = 'high',
                        match_notes      = ?,
                        last_synced_at   = ?
                  WHERE id = ?",
                [
                    $candidateQboId,
                    (string) ($candidate['qbo_sync_token'] ?? '0'),
                    $candidate['qbo_name'] ?? null,
                    $candidate['qbo_fully_qualified_name'] ?? null,
                    $note,
                    $now,
                    (int) $w['id'],
                ]
            );

            $claimedQboIds[$candidateQboId] = true;
            $rescuedCount++;
        }

        if ($rescuedCount > 0) {
            error_log("[AccountMatcher] rescued {$rescuedCount} wedged half-state row(s) (S-QBO-MATCHER-WEDGE-RECOVERY)");
        }

        return $claimedQboIds;
    }
}
