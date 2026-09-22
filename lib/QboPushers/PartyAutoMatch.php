<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PartyAutoMatch.php
 *
 * Applies CustomerMatcher / VendorMatcher decisions to the customer /
 * vendor map tables — the write half shared by
 * api/v1/quickbooks/{customers,vendors}/auto_match.php (S-QBO-GOLIVE-AUDIT;
 * the two endpoints carried near-identical loops, and only the customer
 * copy had the atomic transaction + stale-link detach).
 *
 * Shared company file policy (quickbooks.shared_company_file, default '1'):
 * the QuickBooks company holds several businesses' customers and vendors,
 * so only an EXACT normalized-name match links automatically. A weaker
 * signal (similar name, shared email, shared phone) could just as well be
 * another business's record — it is stored as a SUGGESTION on the FF row
 * (match_notes = 'suggest:{json}') for a person to confirm with Link.
 * With the flag off, the original cascade (every confidence links) applies.
 *
 * Operator decisions (match_confidence='manual') are never touched, and a
 * row that is already linked is never demoted by a later run.
 *
 * @session S-QBO-GOLIVE-AUDIT
 * @decision D-QBO-5-2 (cascade), D-QBO-5-2 manual-lock preservation
 */

namespace FleetForge\QboPushers;

class PartyAutoMatch
{
    /** Marker prefix of a stored suggestion in match_notes. */
    public const SUGGEST_PREFIX = 'suggest:';

    private const PARTIES = [
        'customer' => ['map' => 'acc_qbo_customer_map', 'ff' => 'ff_customer_id', 'qbo' => 'qbo_customer_id'],
        'vendor'   => ['map' => 'acc_qbo_vendor_map',   'ff' => 'ff_vendor_id',   'qbo' => 'qbo_vendor_id'],
        // Accounts: decorateSuggestions() only — accounts/auto_match.php
        // writes its own rows (manual-lock + critical-category handling).
        'account'  => ['map' => 'acc_qbo_account_map',  'ff' => 'ff_account_id',  'qbo' => 'qbo_account_id'],
    ];

    /** Human reason for each matcher confidence. */
    private const WHY = [
        'high'   => 'similar name',
        'medium' => 'same email',
        'low'    => 'same phone number',
    ];

    /** Why an ACCOUNT was suggested (AccountMatcher tiers). */
    public const ACCOUNT_WHY = [
        'high'   => 'similar name',
        'medium' => 'same type + a shared word',
        'low'    => 'only account of this type',
    ];

    public static function sharedFile(): bool
    {
        return (string) settings_get('quickbooks.shared_company_file', '1') === '1';
    }

    /**
     * @param 'customer'|'vendor'          $party
     * @param list<array<string,mixed>>    $decisions        Matcher::matchAll() output
     * @param array<int,true>              $manualLockedFfIds FF ids with a manual decision
     * @return array{matched:int, suggested:int, ff_only:int, qbo_only:int}
     */
    public static function apply(string $party, array $decisions, array $manualLockedFfIds, ?int $userId): array
    {
        $cfg = self::PARTIES[$party] ?? null;
        if ($cfg === null) {
            throw new \InvalidArgumentException("Unknown party '{$party}'.");
        }
        $shared = self::sharedFile();
        $now    = ff_now_utc();

        return db_transaction(function () use ($cfg, $decisions, $manualLockedFfIds, $userId, $shared, $now): array {
            $counts = ['matched' => 0, 'suggested' => 0, 'ff_only' => 0, 'qbo_only' => 0];
            ['map' => $map, 'ff' => $ffCol, 'qbo' => $qboCol] = $cfg;

            foreach ($decisions as $d) {
                $ffId = $d[$ffCol] !== null ? (int) $d[$ffCol] : null;
                if ($ffId !== null && isset($manualLockedFfIds[$ffId])) {
                    continue;
                }

                if ($d['mapping_status'] === 'mapped') {
                    $qboId = (string) $d[$qboCol];
                    $conf  = (string) $d['match_confidence'];

                    if ($shared && $conf !== 'exact') {
                        $current = db_row("SELECT {$qboCol} AS q FROM {$map} WHERE {$ffCol} = ?", [$ffId]);
                        if ($current !== null && $current['q'] !== null) {
                            continue; // already linked — a suggestion never overrides a link
                        }
                        $qboRow = db_row("SELECT qbo_display_name FROM {$map} WHERE {$qboCol} = ?", [$qboId]);
                        $note = self::SUGGEST_PREFIX . json_encode([
                            'qbo_id'     => $qboId,
                            'name'       => (string) ($qboRow['qbo_display_name'] ?? ''),
                            'confidence' => $conf,
                            'why'        => self::WHY[$conf] ?? $conf,
                        ], JSON_UNESCAPED_UNICODE);
                        db_execute(
                            "INSERT INTO {$map} ({$ffCol}, mapping_status, match_notes, created_by_user_id)
                             VALUES (?, 'ff_only', ?, ?)
                             ON DUPLICATE KEY UPDATE
                                match_notes = IF(match_confidence = 'manual' OR {$qboCol} IS NOT NULL, match_notes, VALUES(match_notes))",
                            [$ffId, $note, $userId]
                        );
                        $counts['suggested']++;
                        continue;
                    }

                    // Detach this FF record from any other row so linking the
                    // target cannot collide on the FF unique key:
                    //  - its stale single-sided row goes;
                    //  - a stale link to a DIFFERENT QuickBooks record is
                    //    demoted back to qbo_only.
                    db_execute("DELETE FROM {$map} WHERE {$ffCol} = ? AND {$qboCol} IS NULL", [$ffId]);
                    db_execute(
                        "UPDATE {$map}
                            SET {$ffCol} = NULL, mapping_status = 'qbo_only', match_confidence = NULL
                          WHERE {$ffCol} = ? AND {$qboCol} IS NOT NULL AND {$qboCol} <> ?",
                        [$ffId, $qboId]
                    );
                    $updated = db_execute(
                        "UPDATE {$map}
                            SET {$ffCol} = ?, mapping_status = 'mapped', match_confidence = ?, match_notes = NULL, last_synced_at = ?
                          WHERE {$qboCol} = ?",
                        [$ffId, $conf, $now, $qboId]
                    );
                    if ($updated > 0) {
                        $counts['matched']++;
                    }
                    continue;
                }

                if ($d['mapping_status'] === 'ff_only') {
                    // Keep manual decisions and existing links; otherwise the
                    // row is (again) plain ff_only and a stale suggestion goes.
                    // MySQL applies these assignments left to right, so
                    // match_notes sees the already-updated match_confidence.
                    db_execute(
                        "INSERT INTO {$map} ({$ffCol}, mapping_status, created_by_user_id)
                         VALUES (?, 'ff_only', ?)
                         ON DUPLICATE KEY UPDATE
                            mapping_status   = IF(match_confidence = 'manual' OR {$qboCol} IS NOT NULL, mapping_status, 'ff_only'),
                            match_confidence = IF(match_confidence = 'manual' OR {$qboCol} IS NOT NULL, match_confidence, NULL),
                            match_notes      = IF(match_confidence = 'manual' OR {$qboCol} IS NOT NULL, match_notes, NULL)",
                        [$ffId, $userId]
                    );
                    $counts['ff_only']++;
                    continue;
                }

                if ($d['mapping_status'] === 'qbo_only') {
                    $counts['qbo_only']++;
                }
            }
            return $counts;
        });
    }

    /**
     * Decode the stored suggestion on list rows (and drop one whose
     * QuickBooks record has since been linked to someone else).
     *
     * @param list<array<string,mixed>> $rows list rows with mapping_status + match_notes
     * @return list<array<string,mixed>> same rows + 'suggestion' (array|null)
     */
    public static function decorateSuggestions(string $party, array $rows): array
    {
        $cfg = self::PARTIES[$party];
        $wanted = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['suggestion'] = null;
            $notes = (string) ($r['match_notes'] ?? '');
            if (($r['mapping_status'] ?? '') !== 'ff_only' || !str_starts_with($notes, self::SUGGEST_PREFIX)) {
                continue;
            }
            $s = json_decode(substr($notes, strlen(self::SUGGEST_PREFIX)), true);
            if (is_array($s) && !empty($s['qbo_id'])) {
                $rows[$i]['suggestion'] = $s;
                $wanted[(string) $s['qbo_id']] = true;
            }
        }
        if ($wanted === []) {
            return $rows;
        }
        $ids = array_keys($wanted);
        $free = [];
        foreach (db_select(
            "SELECT {$cfg['qbo']} AS q FROM {$cfg['map']}
              WHERE {$cfg['qbo']} IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                AND {$cfg['ff']} IS NULL AND mapping_status = 'qbo_only'",
            array_map('strval', $ids)
        ) as $f) {
            $free[(string) $f['q']] = true;
        }
        foreach ($rows as $i => $r) {
            if ($r['suggestion'] !== null && !isset($free[(string) $r['suggestion']['qbo_id']])) {
                $rows[$i]['suggestion'] = null;
            }
        }
        return $rows;
    }
}
