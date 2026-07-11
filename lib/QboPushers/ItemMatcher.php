<?php
declare(strict_types=1);

/**
 * lib/QboPushers/ItemMatcher.php
 *
 * Auto-match FF invoice_line_items.item_type ENUM values to QBO Item
 * entities. Different from CustomerMatcher / VendorMatcher / AccountMatcher
 * because the source side is an ENUM (17 fixed values), not a table row.
 * The iteration list is derived via INFORMATION_SCHEMA introspection
 * (NOT a hardcoded list) so future ENUM additions automatically appear
 * in the mapping UI.
 *
 * Cascade (D-QBO-10):
 *   1. exact_name — case-insensitive normalized match between
 *      displayNameFor(itemType, variant) and QBO Item.Name
 *   2. high       — Levenshtein distance ≤ 3 on normalized names
 *                   (gated on max-side length ≥ 5 to avoid trivial
 *                   distance false positives, same shape as
 *                   CustomerMatcher)
 *   3. medium     — significant-token overlap (≥ 4-char shared
 *                   normalized token)
 *   4. null       — no match; the FF item_type row stays ff_only
 *
 * No type-compatibility gating (unlike AccountMatcher) because all
 * FF item_types map to QBO Type='Service' per D-QBO-10-3. Items don't
 * have the Revenue/Expense cross-match risk that GL accounts do.
 *
 * Per D-QBO-10-2, the 'gps' FF item_type expands to TWO variants:
 *   - (gps, 'net')   → 'GPS Service (Net)'    — for customers with
 *                                                gps_revenue_presentation='net'
 *   - (gps, 'gross') → 'GPS Service (Gross)'  — for customers with
 *                                                gps_revenue_presentation='gross'
 * Both variants need separate QBO Items because IncomeAccountRef
 * differs per presentation rule. S-QBO-11 invoice push reads the
 * customer's presentation flag at line-emission time and selects the
 * matching variant from acc_qbo_item_map.
 *
 * Per D-QBO-10-1 option B, 'base_rental_reconciliation_credit' is its
 * own dedicated Item with the 'Rental Reconciliation Credit' display
 * name — handled as a normal (non-variant) row whose is_credit_variant
 * flag = 1 in acc_qbo_item_map.
 *
 * @session  S-QBO-10
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.3
 * @decision D-QBO-10-1 (reconciliation credit as dedicated Item),
 *           D-QBO-10-2 (GPS net/gross variant Items)
 */

namespace FleetForge\QboPushers;

class ItemMatcher
{
    /** Minimum length for Levenshtein to be meaningful. */
    public const LEVENSHTEIN_MIN_LENGTH = 5;

    /** Maximum Levenshtein distance counted as 'high' confidence. */
    public const LEVENSHTEIN_MAX_DISTANCE = 3;

    /** Minimum token length for the medium-confidence shared-token match. */
    public const MIN_TOKEN_LENGTH = 4;

    /**
     * Display-name mapping for the 27 actual FF item_type ENUM values
     * (verified at S-QBO-10 pre-flight against invoice_line_items.item_type;
     * 'mileage' added when the ENUM was extended; service charges
     * cartage/sweep/wash/fuel + engine-hours types hourly_usage/
     * hours_estimate/hours_adjustment/hours_credit added 2026-07-11
     * to catch up with the service-charge and S-HOURS-EST-DAILY ENUM
     * extensions).
     * Used as:
     *   - the auto-match comparison key (normalized) against QBO Item.Name
     *   - the default Name when ItemCreator authors a new QBO Item
     *   - the column label in the mapping UI
     *
     * @var array<string, string>
     */
    public const DISPLAY_NAMES = [
        'base_rental'                      => 'Base Rental',
        'base_rental_reconciliation_credit'=> 'Rental Reconciliation Credit',
        'gps'                              => 'GPS Service',
        'mileage_precharge'                => 'Mileage Precharge',
        'mileage_estimate'                 => 'Estimated Mileage',
        'mileage_adjustment'               => 'Mileage Adjustment',
        'mileage_credit'                   => 'Mileage Credit',
        'mileage_usage'                    => 'Mileage Usage',
        'mileage_drawdown_credit'          => 'Mileage Drawdown Credit',
        'mileage'                          => 'Mileage',
        'hourly_usage'                     => 'Engine Hours Usage',
        'hours_estimate'                   => 'Estimated Engine Hours',
        'hours_adjustment'                 => 'Engine Hours Adjustment',
        'hours_credit'                     => 'Engine Hours Credit',
        'cartage'                          => 'Cartage',
        'sweep'                            => 'Sweep Service',
        'wash'                             => 'Wash Service',
        'fuel'                             => 'Fuel Charge',
        'damage'                           => 'Damage Recovery',
        'late_fee'                         => 'Late Fee',
        'early_return_credit'              => 'Early Return Credit',
        'insurance'                        => 'Insurance',
        'warranty'                         => 'Warranty',
        'manual_adjustment'                => 'Manual Adjustment',
        'discount'                         => 'Discount',
        'account_credit_applied'           => 'Account Credit Applied',
        'other'                            => 'Other',
    ];

    /**
     * UI grouping for the items mapping table. Operator-friendly
     * categorization; not load-bearing for matching logic.
     *
     * @var array<string, list<string>>
     */
    public const UI_CATEGORIES = [
        'Rental & Mileage' => [
            'base_rental',
            'base_rental_reconciliation_credit',
            'gps',
            'mileage_precharge',
            'mileage_estimate',
            'mileage_adjustment',
            'mileage_credit',
            'mileage_usage',
            'mileage_drawdown_credit',
            'mileage',
        ],
        'Engine Hours' => [
            'hourly_usage',
            'hours_estimate',
            'hours_adjustment',
            'hours_credit',
        ],
        'Service Charges' => [
            'cartage',
            'sweep',
            'wash',
            'fuel',
        ],
        'Fees' => [
            'late_fee',
            'manual_adjustment',
        ],
        'Other Recoveries' => [
            'damage',
            'insurance',
            'warranty',
        ],
        'Adjustments' => [
            'discount',
            'early_return_credit',
            'account_credit_applied',
            'other',
        ],
    ];

    /**
     * Generate display name for an FF item_type. Handles the GPS
     * variant case per D-QBO-10-2.
     */
    public static function displayNameFor(string $itemType, ?string $variant = null): string
    {
        $base = self::DISPLAY_NAMES[$itemType] ?? ucwords(str_replace('_', ' ', $itemType));

        if ($itemType === 'gps' && $variant !== null) {
            if ($variant === 'net') {
                return 'GPS Service (Net)';
            }
            if ($variant === 'gross') {
                return 'GPS Service (Gross)';
            }
        }

        return $base;
    }

    /**
     * Returns the canonical list of (ff_item_type, variant) tuples
     * the mapping UI should iterate.
     *
     * Sources the ENUM values from INFORMATION_SCHEMA against the live
     * invoice_line_items.item_type column — never hardcoded. This
     * means a future ENUM addition shows up automatically.
     *
     * For 'gps', expands to 2 variant tuples (net, gross) per
     * D-QBO-10-2 — the plain (gps, NULL) tuple is NOT emitted because
     * S-QBO-11 invoice push always picks net or gross based on the
     * customer's gps_revenue_presentation flag.
     *
     * @return array<int, array{ff_item_type: string, variant: ?string}>
     */
    public static function ffItemTypes(): array
    {
        $col = db_row(
            "SELECT COLUMN_TYPE
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'invoice_line_items'
                AND COLUMN_NAME  = 'item_type'"
        );
        if (!$col || empty($col['COLUMN_TYPE'])) {
            return [];
        }

        // COLUMN_TYPE looks like: enum('base_rental','mileage_precharge',...)
        // Parse out the values between the outer parens.
        $type = $col['COLUMN_TYPE'];
        if (preg_match("/^enum\\((.+)\\)$/i", $type, $m)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $vm);
            $values = $vm[1] ?? [];
        } else {
            $values = [];
        }

        $out = [];
        foreach ($values as $v) {
            if ($v === 'gps') {
                $out[] = ['ff_item_type' => 'gps', 'variant' => 'net'];
                $out[] = ['ff_item_type' => 'gps', 'variant' => 'gross'];
            } else {
                $out[] = ['ff_item_type' => $v, 'variant' => null];
            }
        }
        return $out;
    }

    /**
     * Normalize a name for comparison. Same semantics as
     * AccountMatcher::normalizeAccountName.
     */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = preg_replace('/[.,;:\'"`\-_\/()]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Find the best QBO Item match for a single FF (item_type, variant).
     *
     * @param  string                              $ffItemType
     * @param  array<int, array<string, mixed>>    $qboItems    ItemPuller::normalize() shape
     * @param  ?string                             $variant
     * @param  array<string, bool>                 $claimedQboIds  qbo_id keys already claimed by an earlier iteration in matchAll() — each pass skips claimed candidates (D-QBO-MATCHER-1).
     * @return array{qbo_id: string, confidence: string}|null
     */
    public static function findBestMatch(string $ffItemType, array $qboItems, ?string $variant = null, array $claimedQboIds = []): ?array
    {
        $target = self::normalizeName(self::displayNameFor($ffItemType, $variant));
        if ($target === '') {
            return null;
        }

        // Pass 1: exact normalized name.
        foreach ($qboItems as $qbo) {
            if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
            $qboName = self::normalizeName((string) ($qbo['name'] ?? ''));
            if ($qboName !== '' && $qboName === $target) {
                return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'exact_name'];
            }
        }

        // Pass 2: Levenshtein ≤ 3 (max-side ≥ 5 gate).
        foreach ($qboItems as $qbo) {
            if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
            $qboName = self::normalizeName((string) ($qbo['name'] ?? ''));
            if ($qboName === '') {
                continue;
            }
            if (max(strlen($target), strlen($qboName)) >= self::LEVENSHTEIN_MIN_LENGTH &&
                levenshtein($target, $qboName) <= self::LEVENSHTEIN_MAX_DISTANCE) {
                return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'high'];
            }
        }

        // Pass 3: significant-token overlap.
        $targetTokens = self::significantTokens($target);
        if (!empty($targetTokens)) {
            foreach ($qboItems as $qbo) {
                if (isset($claimedQboIds[(string) $qbo['qbo_id']])) { continue; }
                $qboName = self::normalizeName((string) ($qbo['name'] ?? ''));
                if ($qboName === '') {
                    continue;
                }
                $qboTokens = self::significantTokens($qboName);
                if (!empty(array_intersect($targetTokens, $qboTokens))) {
                    return ['qbo_id' => (string) $qbo['qbo_id'], 'confidence' => 'medium'];
                }
            }
        }

        return null;
    }

    /**
     * Run the cascade across every FF (item_type, variant) tuple vs
     * the supplied QBO list. Returns mapping decisions ready for
     * upsert into acc_qbo_item_map.
     *
     * Operator overrides (confidence='manual') preserved BY THE CALLER
     * (the auto_match endpoint filters out manual-locked rows before
     * merging decisions).
     *
     * Each decision array shape:
     *   ff_item_type         string
     *   ff_item_type_variant ?string
     *   qbo_item_id          string|null
     *   mapping_status       'mapped'|'ff_only'|'qbo_only'
     *   match_confidence     string|null
     *   is_credit_variant    int (0/1) — 1 for reconciliation credit per D-QBO-10-1
     *   presentation_variant ?string  — 'net'/'gross' for GPS variants per D-QBO-10-2
     *
     * @param  array<int, array<string, mixed>> $qboItems
     * @return array<int, array<string, mixed>>
     */
    public static function matchAll(array $qboItems): array
    {
        $tuples         = self::ffItemTypes();
        $decisions      = [];
        $matchedQboIds  = [];

        // Pass 0: wedge half-state rescue (D-MATCHER-WEDGE-RESCUE).
        // See AccountMatcher::rescueHalfStateRows for full rationale.
        // Item rescue matches on qbo_fully_qualified_name then qbo_name.
        $matchedQboIds = self::rescueHalfStateRows($matchedQboIds);

        foreach ($tuples as $tuple) {
            $itemType    = $tuple['ff_item_type'];
            $variant     = $tuple['variant'];
            // Claimed-set tracking (D-QBO-MATCHER-1).
            $match       = self::findBestMatch($itemType, $qboItems, $variant, $matchedQboIds);

            $isCredit    = ($itemType === 'base_rental_reconciliation_credit') ? 1 : 0;
            $presentation = null;
            if ($itemType === 'gps' && ($variant === 'net' || $variant === 'gross')) {
                $presentation = $variant;
            }

            if ($match !== null) {
                $decisions[] = [
                    'ff_item_type'         => $itemType,
                    'ff_item_type_variant' => $variant,
                    'qbo_item_id'          => $match['qbo_id'],
                    'mapping_status'       => 'mapped',
                    'match_confidence'     => $match['confidence'],
                    'is_credit_variant'    => $isCredit,
                    'presentation_variant' => $presentation,
                ];
                $matchedQboIds[$match['qbo_id']] = true;
            } else {
                $decisions[] = [
                    'ff_item_type'         => $itemType,
                    'ff_item_type_variant' => $variant,
                    'qbo_item_id'          => null,
                    'mapping_status'       => 'ff_only',
                    'match_confidence'     => null,
                    'is_credit_variant'    => $isCredit,
                    'presentation_variant' => $presentation,
                ];
            }
        }

        // Anything in QBO that didn't get matched becomes a qbo_only
        // row. UI surfaces these for operator review (link / ignore).
        foreach ($qboItems as $qbo) {
            if (!isset($matchedQboIds[$qbo['qbo_id']])) {
                $decisions[] = [
                    'ff_item_type'         => null,
                    'ff_item_type_variant' => null,
                    'qbo_item_id'          => (string) $qbo['qbo_id'],
                    'mapping_status'       => 'qbo_only',
                    'match_confidence'     => null,
                    'is_credit_variant'    => 0,
                    'presentation_variant' => null,
                ];
            }
        }

        return $decisions;
    }

    /**
     * Extract tokens of meaningful length from a normalized name.
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
     * acc_qbo_item_map. Mirrors AccountMatcher::rescueHalfStateRows
     * with item-specific breadcrumb fields. See AccountMatcher for full
     * rationale + D-MATCHER-WEDGE-RESCUE.
     */
    private static function rescueHalfStateRows(array $existingClaimedQboIds): array
    {
        $wedged = db_select(
            "SELECT id, ff_item_type, ff_item_type_variant, qbo_name, qbo_fully_qualified_name
               FROM acc_qbo_item_map
              WHERE mapping_status = 'ff_only'
                AND qbo_item_id IS NULL
                AND (qbo_name IS NOT NULL OR qbo_fully_qualified_name IS NOT NULL)"
        );

        $claimedQboIds = $existingClaimedQboIds;
        $rescuedCount  = 0;
        $now           = date('Y-m-d H:i:s');

        foreach ($wedged as $w) {
            $candidate = null;
            $matchedBy = null;

            if (!empty($w['qbo_fully_qualified_name'])) {
                $candidate = db_row(
                    "SELECT id, qbo_item_id, qbo_sync_token, qbo_name, qbo_fully_qualified_name,
                            qbo_description, qbo_type, qbo_active,
                            qbo_income_account_id, qbo_income_account_name,
                            qbo_expense_account_id, qbo_expense_account_name
                       FROM acc_qbo_item_map
                      WHERE mapping_status = 'qbo_only'
                        AND qbo_item_id IS NOT NULL
                        AND qbo_fully_qualified_name = ?
                      ORDER BY id ASC
                      LIMIT 1",
                    [(string) $w['qbo_fully_qualified_name']]
                );
                if ($candidate !== null) { $matchedBy = 'qbo_fully_qualified_name'; }
            }

            if ($candidate === null && !empty($w['qbo_name'])) {
                $candidate = db_row(
                    "SELECT id, qbo_item_id, qbo_sync_token, qbo_name, qbo_fully_qualified_name,
                            qbo_description, qbo_type, qbo_active,
                            qbo_income_account_id, qbo_income_account_name,
                            qbo_expense_account_id, qbo_expense_account_name
                       FROM acc_qbo_item_map
                      WHERE mapping_status = 'qbo_only'
                        AND qbo_item_id IS NOT NULL
                        AND qbo_name = ?
                      ORDER BY id ASC
                      LIMIT 1",
                    [(string) $w['qbo_name']]
                );
                if ($candidate !== null) { $matchedBy = 'qbo_name'; }
            }

            if ($candidate === null) {
                continue;
            }

            $candidateQboId = (string) $candidate['qbo_item_id'];
            if (isset($claimedQboIds[$candidateQboId])) {
                continue;
            }

            $matchedValue = $matchedBy === 'qbo_fully_qualified_name'
                ? (string) $w['qbo_fully_qualified_name']
                : (string) $w['qbo_name'];
            $note = "wedge_recovery: linked by {$matchedBy}='{$matchedValue}'";

            // DELETE candidate first to clear uq_qbo_item before UPDATE.
            db_execute("DELETE FROM acc_qbo_item_map WHERE id = ?", [(int) $candidate['id']]);
            db_execute(
                "UPDATE acc_qbo_item_map
                    SET qbo_item_id              = ?,
                        qbo_sync_token           = ?,
                        qbo_name                 = COALESCE(?, qbo_name),
                        qbo_fully_qualified_name = COALESCE(?, qbo_fully_qualified_name),
                        qbo_description          = COALESCE(?, qbo_description),
                        qbo_type                 = COALESCE(?, qbo_type),
                        qbo_active               = COALESCE(?, qbo_active),
                        qbo_income_account_id    = COALESCE(?, qbo_income_account_id),
                        qbo_income_account_name  = COALESCE(?, qbo_income_account_name),
                        qbo_expense_account_id   = COALESCE(?, qbo_expense_account_id),
                        qbo_expense_account_name = COALESCE(?, qbo_expense_account_name),
                        mapping_status           = 'mapped',
                        match_confidence         = 'high',
                        match_notes              = ?,
                        last_synced_at           = ?
                  WHERE id = ?",
                [
                    $candidateQboId,
                    (string) ($candidate['qbo_sync_token'] ?? '0'),
                    $candidate['qbo_name'] ?? null,
                    $candidate['qbo_fully_qualified_name'] ?? null,
                    $candidate['qbo_description'] ?? null,
                    $candidate['qbo_type'] ?? null,
                    $candidate['qbo_active'] ?? null,
                    $candidate['qbo_income_account_id'] ?? null,
                    $candidate['qbo_income_account_name'] ?? null,
                    $candidate['qbo_expense_account_id'] ?? null,
                    $candidate['qbo_expense_account_name'] ?? null,
                    $note,
                    $now,
                    (int) $w['id'],
                ]
            );

            $claimedQboIds[$candidateQboId] = true;
            $rescuedCount++;
        }

        if ($rescuedCount > 0) {
            error_log("[ItemMatcher] rescued {$rescuedCount} wedged half-state row(s) (S-QBO-MATCHER-WEDGE-RECOVERY)");
        }

        return $claimedQboIds;
    }
}
