<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BankAccountMatcher.php
 *
 * Phase QBO-9 / 1 of 1 (S-QBO-20) — bank account mapping for the
 * Exception #2 read-only mirror (D-QBO-CORE-2).
 *
 * One-time-import pattern mirroring AccountMatcher (S-QBO-8). Direct
 * mapping FF acc_bank_accounts ↔ QBO Account (AccountType IN Bank /
 * CreditCard). Provides an alternate path to the gl_account_id chain
 * pivot used by S-QBO-19 BillPaymentPusher (which traverses
 * acc_bank_accounts.gl_account_id → acc_accounts.id → acc_qbo_account_map
 * → QBO id). The direct map is preferred for the BankTransactionPuller
 * CDC pull where each per-account loop needs O(1) lookup.
 *
 * Candidate ranking (D-QBO-20-BAM-1):
 *   1. currency-match first  — FF acc_bank_accounts.currency must equal
 *                              QBO CurrencyRef.value. Strongly preferred
 *                              because cross-currency mappings are a
 *                              design smell (bank accounts hold one
 *                              currency by definition).
 *   2. fuzzy name match     — Levenshtein on lowercased + punctuation-
 *                              stripped name; smaller distance = higher
 *                              rank.
 *   3. is_active first      — active QBO accounts ranked above inactive
 *                              within the same fuzzy-distance bucket.
 *   4. account_type match   — FF account_type 'credit_card' prefers QBO
 *                              CreditCard; FF 'checking' / 'savings' /
 *                              'line_of_credit' prefer QBO Bank.
 *
 * Returns top 10 candidates per FF bank account.
 *
 * Drift detection (D-QBO-20-1 snapshot columns):
 *   verifyMappingStillValid() iterates mapped rows, fetches each QBO
 *   account via getEntity, and flags one of:
 *     - currency_drift   — QBO CurrencyRef changed
 *     - name_drift       — QBO Account.Name changed
 *     - became_inactive  — QBO Account.Active dropped to false
 *     - type_drift       — QBO AccountType changed (rare; usually means
 *                          accountant manually converted bank → other type)
 *   Drift events surface in the admin UI; operator decides whether to
 *   update FF or unmap.
 *
 * Audit trail: every assignMapping / unmapping / drift_detected event
 * writes an audit_log row with module='quickbooks', entity_type=
 * 'bank_account', entity_id=ff_bank_account_id.
 *
 * @session  S-QBO-20
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.11 (Bank Account)
 * @decision D-QBO-20-1 (one-time-import pattern mirrors S-QBO-8),
 *           D-QBO-20-BAM-1 (candidate ranking: currency → fuzzy → active → type)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class BankAccountMatcher
{
    /**
     * QBO AccountType values that represent bank-or-bank-like accounts
     * for CDC pull purposes. Per spec §8.11 these are the entity types
     * the BankAccountMatcher pulls; CreditCard is included because
     * Purchase entities posted against a credit card account flow through
     * the same CDC channel (and FF acc_bank_accounts ENUM includes
     * 'credit_card' as a tracked type).
     */
    public const BANK_ACCOUNT_TYPES = ['Bank', 'CreditCard'];

    /**
     * Pull every QBO bank-or-credit-card account via Query API. Returns
     * normalized rows ready for getCandidates / assignMapping. Fixture
     * mode is transparent — QuickBooksClient short-circuits to seeded
     * fixtures internally.
     *
     * @return list<array{qbo_id: string, name: string, currency: string, active: bool, account_type: string, account_subtype: string, account_number: string}>
     */
    public static function pullFromQbo(?QuickBooksClient $client = null): array
    {
        $client = $client ?? new QuickBooksClient();

        // QBO Query API doesn't support OR across AccountType values cleanly
        // — single AccountType='Bank' is the canonical query. We issue two
        // queries (Bank + CreditCard) and merge. Caller treats it as one
        // list; the qbo_account_type column carries the QBO-side type.
        $combined = [];
        foreach (self::BANK_ACCOUNT_TYPES as $qboType) {
            $resp = $client->query("SELECT * FROM Account WHERE AccountType = '{$qboType}'");
            $accounts = $resp['QueryResponse']['Account'] ?? [];
            foreach ($accounts as $a) {
                $combined[] = [
                    'qbo_id'          => (string) ($a['Id'] ?? ''),
                    'name'            => (string) ($a['Name'] ?? ''),
                    'currency'        => strtoupper((string) ($a['CurrencyRef']['value'] ?? 'CAD')),
                    'active'          => (bool) ($a['Active'] ?? true),
                    'account_type'    => (string) ($a['AccountType'] ?? $qboType),
                    'account_subtype' => (string) ($a['AccountSubType'] ?? ''),
                    'account_number'  => (string) ($a['AcctNum'] ?? ''),
                ];
            }
        }
        return $combined;
    }

    /**
     * Return ranked candidate QBO bank accounts for a given FF
     * acc_bank_accounts row. Top 10. Empty list if FF row missing.
     *
     * Ranking order per D-QBO-20-BAM-1:
     *   - currency_match (FF.currency == QBO.currency) → primary sort key
     *   - type_compatible (FF.account_type → QBO AccountType) → secondary
     *   - levenshtein distance on normalized name → tertiary
     *   - active first → quaternary
     *
     * @param array<int, array<string, mixed>> $qboBankAccounts  Output of
     *        pullFromQbo() — passed in by caller so this method is pure.
     * @return list<array<string, mixed>>  Top 10 candidates with rank metadata.
     */
    public static function getCandidates(int $ffBankAccountId, array $qboBankAccounts): array
    {
        $ff = db_row(
            "SELECT id, name, currency, account_type
               FROM acc_bank_accounts WHERE id = ?",
            [$ffBankAccountId]
        );
        if (!$ff) {
            return [];
        }
        $ffCurrency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
        $ffName     = self::normalizeName((string) ($ff['name'] ?? ''));
        $ffType     = (string) ($ff['account_type'] ?? '');

        $scored = [];
        foreach ($qboBankAccounts as $qbo) {
            $qboName     = self::normalizeName((string) ($qbo['name'] ?? ''));
            $qboCurrency = strtoupper((string) ($qbo['currency'] ?? 'CAD'));
            $qboType     = (string) ($qbo['account_type'] ?? '');
            $qboActive   = (bool) ($qbo['active'] ?? true);

            $currencyMatch = $qboCurrency === $ffCurrency;
            $typeMatch     = self::typeCompatible($ffType, $qboType);
            $distance      = self::lev($ffName, $qboName);

            $scored[] = $qbo + [
                'rank_currency_match' => $currencyMatch ? 1 : 0,
                'rank_type_match'     => $typeMatch ? 1 : 0,
                'rank_distance'       => $distance,
                'rank_active'         => $qboActive ? 1 : 0,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['rank_currency_match'] !== $b['rank_currency_match']) {
                return $b['rank_currency_match'] <=> $a['rank_currency_match'];
            }
            if ($a['rank_type_match'] !== $b['rank_type_match']) {
                return $b['rank_type_match'] <=> $a['rank_type_match'];
            }
            if ($a['rank_distance'] !== $b['rank_distance']) {
                return $a['rank_distance'] <=> $b['rank_distance'];
            }
            return $b['rank_active'] <=> $a['rank_active'];
        });

        return array_slice($scored, 0, 10);
    }

    /**
     * Upsert a mapping row. Snapshot QBO metadata for drift detection.
     * Throws when the QBO bank account id is already claimed by another
     * FF row (uq_qbo violation surfaced as a friendly exception).
     *
     * @param  array<string, mixed> $qboSnapshot  Subset from pullFromQbo()
     *         for the matching row — name/currency/active/account_type
     *         used for snapshot columns.
     * @return array{mapping_id: int, action: 'inserted'|'updated', snapshot: array<string, mixed>}
     */
    public static function assignMapping(
        int $ffBankAccountId,
        string $qboBankAccountId,
        array $qboSnapshot,
        ?int $userId = null
    ): array {
        if ($qboBankAccountId === '') {
            throw new \InvalidArgumentException('qboBankAccountId cannot be empty');
        }

        // uq_qbo conflict guard with a typed message — UNIQUE constraint
        // would surface as a generic PDO error otherwise.
        $conflict = db_row(
            "SELECT id, ff_bank_account_id
               FROM acc_qbo_bank_account_map
              WHERE qbo_bank_account_id = ? AND ff_bank_account_id != ?",
            [$qboBankAccountId, $ffBankAccountId]
        );
        if ($conflict) {
            throw new \RuntimeException(
                "QBO bank account {$qboBankAccountId} is already mapped to FF bank "
                . "account #{$conflict['ff_bank_account_id']}. Unmap it first or "
                . "pick a different QBO account."
            );
        }

        $now = date('Y-m-d H:i:s');
        $snapshotRow = [
            'qbo_account_name_snapshot' => substr((string) ($qboSnapshot['name'] ?? ''), 0, 255),
            'qbo_currency_snapshot'     => self::canonicalCurrency((string) ($qboSnapshot['currency'] ?? 'CAD')),
            'qbo_active_snapshot'       => (int) (bool) ($qboSnapshot['active'] ?? true),
            'qbo_account_type_snapshot' => substr((string) ($qboSnapshot['account_type'] ?? ''), 0, 20),
        ];

        $existing = db_row(
            "SELECT id FROM acc_qbo_bank_account_map WHERE ff_bank_account_id = ?",
            [$ffBankAccountId]
        );

        if ($existing) {
            db_execute(
                "UPDATE acc_qbo_bank_account_map
                    SET qbo_bank_account_id = ?,
                        qbo_account_name_snapshot = ?,
                        qbo_currency_snapshot = ?,
                        qbo_active_snapshot = ?,
                        qbo_account_type_snapshot = ?,
                        mapping_status = 'mapped',
                        last_synced_at = ?,
                        mapped_by = ?,
                        mapped_at = ?
                  WHERE id = ?",
                [
                    $qboBankAccountId,
                    $snapshotRow['qbo_account_name_snapshot'],
                    $snapshotRow['qbo_currency_snapshot'],
                    $snapshotRow['qbo_active_snapshot'],
                    $snapshotRow['qbo_account_type_snapshot'],
                    $now,
                    $userId,
                    $now,
                    (int) $existing['id'],
                ]
            );
            $mappingId = (int) $existing['id'];
            $action    = 'updated';
        } else {
            $mappingId = db_insert('acc_qbo_bank_account_map', [
                'ff_bank_account_id'        => $ffBankAccountId,
                'qbo_bank_account_id'       => $qboBankAccountId,
                'qbo_account_name_snapshot' => $snapshotRow['qbo_account_name_snapshot'],
                'qbo_currency_snapshot'     => $snapshotRow['qbo_currency_snapshot'],
                'qbo_active_snapshot'       => $snapshotRow['qbo_active_snapshot'],
                'qbo_account_type_snapshot' => $snapshotRow['qbo_account_type_snapshot'],
                'mapping_status'            => 'mapped',
                'last_synced_at'            => $now,
                'mapped_by'                 => $userId,
                'mapped_at'                 => $now,
            ]);
            $action = 'inserted';
        }

        // K-22: audit_log.action is an ENUM that does NOT include semantic
        // labels like 'mapping_assigned' — the closest fit is 'create' for
        // first link or 'update' for re-link. The notes column carries the
        // mapping-specific phrasing for forensic search.
        self::auditLog(
            $action === 'inserted' ? 'create' : 'update',
            $ffBankAccountId,
            $userId,
            "qbo_mapping_assigned: FF bank account #{$ffBankAccountId} mapped to QBO {$qboBankAccountId} (action={$action})"
        );

        return [
            'mapping_id' => $mappingId,
            'action'     => $action,
            'snapshot'   => $snapshotRow,
        ];
    }

    /**
     * Remove a mapping. Preserves no row — FK CASCADE on FF deletion
     * handles the forensic case; explicit unmapping by operator means
     * the mapping was wrong + should disappear.
     */
    public static function unmapping(int $ffBankAccountId, ?int $userId = null): bool
    {
        $existing = db_row(
            "SELECT id, qbo_bank_account_id
               FROM acc_qbo_bank_account_map WHERE ff_bank_account_id = ?",
            [$ffBankAccountId]
        );
        if (!$existing) {
            return false;
        }
        db_execute(
            "DELETE FROM acc_qbo_bank_account_map WHERE id = ?",
            [(int) $existing['id']]
        );
        self::auditLog(
            'delete',
            $ffBankAccountId,
            $userId,
            "qbo_mapping_unmapped: FF bank account #{$ffBankAccountId} unmapped from QBO {$existing['qbo_bank_account_id']}"
        );
        return true;
    }

    /**
     * Iterate every mapped row + fetch the current QBO Account; compare
     * snapshot fields. Report any drift detected. Updates last_synced_at
     * on every row touched (regardless of outcome).
     *
     * @return list<array{ff_bank_account_id: int, qbo_bank_account_id: string, drift_types: list<string>, snapshot_now: array<string, mixed>}>
     */
    public static function verifyMappingStillValid(?QuickBooksClient $client = null): array
    {
        $client = $client ?? new QuickBooksClient();
        $now    = date('Y-m-d H:i:s');

        $mapped = db_select(
            "SELECT id, ff_bank_account_id, qbo_bank_account_id,
                    qbo_account_name_snapshot, qbo_currency_snapshot,
                    qbo_active_snapshot, qbo_account_type_snapshot
               FROM acc_qbo_bank_account_map
              WHERE mapping_status = 'mapped'"
        );

        $report = [];
        foreach ($mapped as $row) {
            try {
                $resp = $client->getEntity('account', (string) $row['qbo_bank_account_id']);
            } catch (\Throwable $e) {
                error_log(
                    "[BankAccountMatcher] verifyMappingStillValid getEntity failed for "
                    . "qbo_id={$row['qbo_bank_account_id']}: " . $e->getMessage()
                );
                continue;
            }
            $qbo = $resp['Account'] ?? null;
            if (!is_array($qbo)) {
                continue;
            }

            $nowName     = (string) ($qbo['Name'] ?? '');
            $nowCurrency = strtoupper((string) ($qbo['CurrencyRef']['value'] ?? 'CAD'));
            $nowActive   = (bool) ($qbo['Active'] ?? true);
            $nowType     = (string) ($qbo['AccountType'] ?? '');

            $driftTypes = [];
            if ($row['qbo_account_name_snapshot'] !== null
                && $nowName !== '' && $nowName !== $row['qbo_account_name_snapshot']) {
                $driftTypes[] = 'name_drift';
            }
            if ($row['qbo_currency_snapshot'] !== null
                && $nowCurrency !== $row['qbo_currency_snapshot']) {
                $driftTypes[] = 'currency_drift';
            }
            if ($row['qbo_active_snapshot'] !== null
                && (int) $nowActive !== (int) $row['qbo_active_snapshot']
                && !$nowActive) {
                $driftTypes[] = 'became_inactive';
            }
            if ($row['qbo_account_type_snapshot'] !== null && $row['qbo_account_type_snapshot'] !== ''
                && $nowType !== $row['qbo_account_type_snapshot']) {
                $driftTypes[] = 'type_drift';
            }

            $newStatus = empty($driftTypes) ? 'mapped' : 'conflict';
            db_execute(
                "UPDATE acc_qbo_bank_account_map
                    SET mapping_status = ?,
                        last_synced_at = ?,
                        qbo_account_name_snapshot = ?,
                        qbo_currency_snapshot = ?,
                        qbo_active_snapshot = ?,
                        qbo_account_type_snapshot = ?
                  WHERE id = ?",
                [
                    $newStatus, $now,
                    substr($nowName, 0, 255),
                    self::canonicalCurrency($nowCurrency),
                    (int) $nowActive,
                    substr($nowType, 0, 20),
                    (int) $row['id'],
                ]
            );

            if (!empty($driftTypes)) {
                // K-22: status_change is the closest ENUM match — the
                // mapping_status transitioned from 'mapped' to 'conflict'.
                self::auditLog(
                    'status_change',
                    (int) $row['ff_bank_account_id'],
                    null,
                    "qbo_mapping_drift_detected: FF bank #{$row['ff_bank_account_id']} ↔ QBO {$row['qbo_bank_account_id']}: "
                    . implode(', ', $driftTypes)
                );
            }
            $report[] = [
                'ff_bank_account_id'  => (int) $row['ff_bank_account_id'],
                'qbo_bank_account_id' => (string) $row['qbo_bank_account_id'],
                'drift_types'         => $driftTypes,
                'snapshot_now'        => [
                    'name'         => $nowName,
                    'currency'     => $nowCurrency,
                    'active'       => $nowActive,
                    'account_type' => $nowType,
                ],
            ];
        }
        return $report;
    }

    /**
     * Type-compatibility map: FF acc_bank_accounts.account_type
     * (operational ENUM) → list of compatible QBO AccountType values.
     */
    public static function typeCompatible(string $ffType, string $qboType): bool
    {
        $map = [
            'checking'        => ['Bank'],
            'savings'         => ['Bank'],
            'line_of_credit'  => ['Bank', 'CreditCard'],
            'credit_card'     => ['CreditCard'],
        ];
        return in_array($qboType, $map[strtolower($ffType)] ?? [], true);
    }

    /**
     * Normalize name for fuzzy compare. Same shape as AccountMatcher's
     * normalizeAccountName — lowercase + punctuation/hyphen/underscore
     * stripped to single spaces.
     */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = preg_replace('/[.,;:\'"`\-_\/]+/', ' ', $n) ?? $n;
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        return trim($n);
    }

    /**
     * Defensive Levenshtein wrapper — short strings or one empty side
     * returns a sentinel distance so they don't outrank longer matches.
     */
    private static function lev(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return PHP_INT_MAX;
        }
        if (strlen($a) < 3 || strlen($b) < 3) {
            return $a === $b ? 0 : PHP_INT_MAX;
        }
        return levenshtein($a, $b);
    }

    /**
     * QBO emits currency codes as 3-char ISO; FF schema enforces
     * ('CAD','USD'). Anything else gets nulled (preserves NULL semantics
     * in the ENUM column) so we don't surface garbage values via the
     * mapping row.
     */
    private static function canonicalCurrency(string $raw): ?string
    {
        $u = strtoupper(trim($raw));
        return in_array($u, ['CAD', 'USD'], true) ? $u : null;
    }

    private static function auditLog(string $action, int $entityId, ?int $userId, string $notes): void
    {
        db_insert('audit_log', [
            'user_id'     => $userId,
            'user_name'   => $userId !== null ? null : 'system',
            'action'      => $action,
            'module'      => 'quickbooks',
            'entity_type' => 'bank_account',
            'entity_id'   => $entityId,
            'notes'       => $notes,
            'ip_address'  => '127.0.0.1',
        ]);
    }
}
