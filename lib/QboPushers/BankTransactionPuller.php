<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BankTransactionPuller.php
 *
 * Phase QBO-9 / 1 of 1 (S-QBO-20) — Exception #2 per D-QBO-CORE-2.
 *
 * QBO is canonical for bank reconciliation. FF mirrors bank transactions
 * via daily CDC (Change Data Capture) pull at 02:30 server time. FF rows
 * are tagged source='qbo_cdc' + is_readonly=1 — they appear in the FF
 * acc_bank_transactions list for in-FF audit / drift detection but are
 * EXCLUDED from FF-side reconciliation workflows (which would double-count
 * if they tried to reconcile what QBO is already reconciling).
 *
 * Pattern: stateless static methods, fixture-mode-aware via QuickBooksClient,
 * idempotent via the qbo_bank_txn_id composite-key UNIQUE constraint.
 * Mirror of PaymentWebhookHandler (S-QBO-13) shape — both are QBO→FF read
 * paths, both return result codes on anomalies, both never throw on normal
 * "nothing to pull" / "skipped" outcomes. Only auth + connection failures
 * propagate as exceptions.
 *
 * Idempotency model:
 *   composite qbo_bank_txn_id = "{EntityType}:{Id}" is UNIQUE in
 *   acc_qbo_bank_transaction_map. Re-pull of the same transaction:
 *     - map row exists, snapshot matches  → 'unchanged'
 *     - map row exists, snapshot differs  → UPDATE both FF row + map → 'updated'
 *     - map row missing                   → INSERT both → 'inserted'
 *     - QBO transaction $0 amount         → 'skipped_zero_amount' (audit log;
 *                                            no map row)
 *     - QBO BankAccountRef not mapped     → 'skipped_unmapped_account' (audit
 *                                            log; no map row; operator maps
 *                                            account + re-runs to pick up)
 *   All map-row writes happen INSIDE db_transaction with the FF row insert,
 *   so partial state cannot leak.
 *
 * Per-entity sourcing per spec §8.12 + Intuit Query API capabilities:
 *   - Purchase     : top-level AccountRef = bank account → SQL filter
 *                    SELECT * FROM Purchase WHERE TxnDate>='since' AND AccountRef='{qboId}'
 *                    Maps to FF transaction_type='withdrawal'.
 *   - Deposit      : top-level DepositToAccountRef → SQL filter
 *                    Maps to FF transaction_type='deposit'.
 *   - Transfer     : FromAccountRef + ToAccountRef both top-level → SQL OR
 *                    not directly supported; we pull all + app-side filter.
 *                    Maps to FF transaction_type='transfer'.
 *   - JournalEntry : Line items carry AccountRef; SQL filter not supported
 *                    at the Line level → pull all + app-side filter on
 *                    Line[].AccountRef matching the mapped qbo_account_id.
 *                    Maps to FF transaction_type='other' (catch-all).
 *
 * Multi-currency: qbo_currency_snapshot + qbo_exchange_rate_snapshot
 * captured on map row for forensic trail; FF acc_bank_transactions.amount
 * is the home-currency value as emitted by QBO. FX revaluation deferred
 * to S-QBO-FX-RECON-FOLLOWUP per D-QBO-20 design notes.
 *
 * @session  S-QBO-20
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.11 (Bank Account),
 *           §8.12 (Bank Transaction read-only mirror)
 * @decision D-QBO-20-2 (composite {entity}:{id} key),
 *           D-QBO-20-3 (source ENUM extended with 'qbo_cdc'),
 *           D-QBO-20-4 (qbo_cdc rows MUST set is_readonly=1),
 *           D-QBO-20-5 (Transfer captured from pulling account side),
 *           D-QBO-20-6 (cdc_lookback_days first-run safety; 90-day default),
 *           D-QBO-20-7 (cron_skipped_unmapped='1' default skip-with-audit)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class BankTransactionPuller
{
    /**
     * QBO entity types this CDC pull covers per spec §8.12.
     * Public const for fixture-parity testing.
     */
    public const BANK_ENTITY_TYPES = ['Purchase', 'Deposit', 'Transfer', 'JournalEntry'];

    /**
     * Map QBO entity type → FF acc_bank_transactions.transaction_type
     * ENUM value. JournalEntry resolves via signed-amount inspection;
     * for the others the entity itself implies the direction.
     */
    public const QBO_TO_FF_TYPE = [
        'Purchase'     => 'withdrawal',
        'Deposit'      => 'deposit',
        'Transfer'     => 'transfer',
        // JournalEntry: 'deposit' if net debit positive (DR cash); 'withdrawal'
        // otherwise; falls back to 'other' if mixed-sign or zero.
        'JournalEntry' => 'other',
    ];

    /**
     * Main CDC entrypoint. Called by:
     *   - cron/qbo_bank_cdc.php (daily 02:30)
     *   - admin /quickbooks/bank_accounts "Run CDC Now" button
     *
     * @param ?string $sinceOverride  ISO datetime; when null uses settings.
     * @return array{enabled: bool, since: ?string, pulled: int, updated: int, unchanged: int, skipped: int, errors: int, by_account: array<int, array<string, int>>}
     */
    public static function runCdc(?string $sinceOverride = null, ?QuickBooksClient $client = null): array
    {
        $enabled = (string) settings_get('quickbooks.banking.cdc_enabled', '1') === '1';
        if (!$enabled) {
            return self::aggregateZero([
                'enabled' => false,
                'since'   => null,
            ]);
        }

        $client = $client ?? new QuickBooksClient();
        $since  = $sinceOverride ?? self::computeSince();

        // Mapped + active accounts only. Inactive FF bank accounts are
        // skipped at the SQL level — operator deactivating a bank account
        // in FF means "stop pulling for this account".
        $mappedAccounts = db_select(
            "SELECT m.id AS map_id,
                    m.ff_bank_account_id,
                    m.qbo_bank_account_id,
                    ba.currency AS ff_currency
               FROM acc_qbo_bank_account_map m
               JOIN acc_bank_accounts ba ON ba.id = m.ff_bank_account_id
              WHERE m.mapping_status = 'mapped'
                AND ba.is_active = 1"
        );

        $agg = [
            'enabled'    => true,
            'since'      => $since,
            'pulled'     => 0,
            'updated'    => 0,
            'unchanged'  => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'by_account' => [],
        ];

        foreach ($mappedAccounts as $row) {
            $ffId  = (int) $row['ff_bank_account_id'];
            $perAccount = self::pullForAccount(
                $ffId,
                (string) $row['qbo_bank_account_id'],
                $since,
                $client
            );
            $agg['pulled']    += $perAccount['pulled'];
            $agg['updated']   += $perAccount['updated'];
            $agg['unchanged'] += $perAccount['unchanged'];
            $agg['skipped']   += $perAccount['skipped'];
            $agg['errors']    += $perAccount['errors'];
            $agg['by_account'][$ffId] = $perAccount;
        }

        // Stamp last_bank_cdc_at at the WHOLE-run level (not per-account)
        // — atomicity at the cron level matches the spec §8.12 pseudocode.
        $runStamp = date('c');
        db_execute(
            "INSERT INTO settings (`key`, `value`, value_type, group_name, is_public, is_sensitive)
                  VALUES (?, ?, 'string', 'quickbooks', 0, 0)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['quickbooks.banking.last_bank_cdc_at', $runStamp]
        );
        $agg['last_bank_cdc_at'] = $runStamp;

        return $agg;
    }

    /**
     * Per-account CDC pull. Queries QBO for each of the 4 BANK_ENTITY_TYPES
     * scoped to the mapped QBO bank account + TxnDate >= since, then
     * dispatches each row through upsertTransaction.
     *
     * @return array{pulled: int, updated: int, unchanged: int, skipped: int, errors: int, by_entity: array<string, int>}
     */
    public static function pullForAccount(
        int $ffBankAccountId,
        string $qboBankAccountId,
        string $sinceIso,
        ?QuickBooksClient $client = null
    ): array {
        $client = $client ?? new QuickBooksClient();
        $sinceDate = substr($sinceIso, 0, 10);  // YYYY-MM-DD slice; QBO Query accepts date prefix

        $stats = [
            'pulled'    => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'by_entity' => array_fill_keys(self::BANK_ENTITY_TYPES, 0),
        ];

        foreach (self::BANK_ENTITY_TYPES as $entityType) {
            try {
                $rows = self::queryEntities($client, $entityType, $qboBankAccountId, $sinceDate);
            } catch (\Throwable $e) {
                error_log(
                    "[BankTransactionPuller] queryEntities failed for {$entityType} / "
                    . "qbo_acct={$qboBankAccountId}: " . $e->getMessage()
                );
                $stats['errors']++;
                continue;
            }

            foreach ($rows as $qboRow) {
                if (!self::rowMatchesAccount($entityType, $qboRow, $qboBankAccountId)) {
                    continue;
                }

                try {
                    $result = self::upsertTransaction(
                        $ffBankAccountId,
                        $qboBankAccountId,
                        $entityType,
                        $qboRow
                    );
                } catch (\Throwable $e) {
                    error_log(
                        "[BankTransactionPuller] upsertTransaction failed for "
                        . "{$entityType}/{$qboRow['Id']}: " . $e->getMessage()
                    );
                    $stats['errors']++;
                    continue;
                }

                switch ($result) {
                    case 'inserted':                 $stats['pulled']++;    break;
                    case 'updated':                  $stats['updated']++;   break;
                    case 'unchanged':                $stats['unchanged']++; break;
                    case 'skipped_zero_amount':      $stats['skipped']++;   break;
                    case 'skipped_unmapped_account': $stats['skipped']++;   break;
                }
                $stats['by_entity'][$entityType]++;
            }
        }

        return $stats;
    }

    /**
     * Atomic upsert of an FF acc_bank_transactions row + matching
     * acc_qbo_bank_transaction_map row.
     *
     * Returns one of: 'inserted' | 'updated' | 'unchanged' |
     * 'skipped_zero_amount' | 'skipped_unmapped_account'.
     */
    public static function upsertTransaction(
        int $ffBankAccountId,
        string $qboBankAccountId,
        string $entityType,
        array $qboRow
    ): string {
        $qboEntityId = (string) ($qboRow['Id'] ?? '');
        if ($qboEntityId === '') {
            throw new \InvalidArgumentException("QBO row missing Id field for {$entityType}");
        }

        $composite = "{$entityType}:{$qboEntityId}";

        // Extract economic data BEFORE opening the transaction so we can
        // short-circuit on zero-amount without paying the row-lock cost.
        $extracted = self::extractEconomicData($entityType, $qboRow, $qboBankAccountId);
        $amount    = $extracted['amount'];   // positive (FF convention)
        $rawSigned = $extracted['signed_raw'];
        $txnDate   = $extracted['txn_date'];
        $currency  = $extracted['currency'];
        $exchange  = $extracted['exchange_rate'];
        $desc      = $extracted['description'];

        if (bccomp($amount, '0.00', 2) === 0) {
            self::auditSkip(
                $ffBankAccountId,
                'skipped_zero_amount',
                "QBO {$composite} amount=0; no economic significance, skipping mirror insert"
            );
            return 'skipped_zero_amount';
        }

        $ffTxnType = self::deriveFfType($entityType, $rawSigned);

        return db_transaction(function () use (
            $ffBankAccountId, $qboBankAccountId, $entityType, $qboEntityId, $composite,
            $amount, $txnDate, $currency, $exchange, $desc, $ffTxnType, $rawSigned
        ): string {
            $existing = db_row(
                "SELECT m.id AS map_id, m.ff_bank_transaction_id, m.qbo_amount, m.qbo_txn_date,
                        m.qbo_description_snapshot, m.pull_status
                   FROM acc_qbo_bank_transaction_map m
                  WHERE m.qbo_bank_txn_id = ?",
                [$composite]
            );

            $now = date('Y-m-d H:i:s');

            if ($existing) {
                $unchanged =
                    bccomp((string) $existing['qbo_amount'], $amount, 2) === 0
                    && (string) $existing['qbo_txn_date'] === $txnDate
                    && (string) ($existing['qbo_description_snapshot'] ?? '') === (string) $desc
                    && (string) $existing['pull_status'] === 'pulled';
                if ($unchanged) {
                    db_execute(
                        "UPDATE acc_qbo_bank_transaction_map SET last_pulled_at = ? WHERE id = ?",
                        [$now, (int) $existing['map_id']]
                    );
                    return 'unchanged';
                }
                // Drift detected → update FF row + map snapshot.
                if ($existing['ff_bank_transaction_id'] !== null) {
                    db_execute(
                        "UPDATE acc_bank_transactions
                            SET transaction_date = ?,
                                description      = ?,
                                amount           = ?,
                                transaction_type = ?
                          WHERE id = ?",
                        [$txnDate, substr($desc, 0, 500), $amount, $ffTxnType, (int) $existing['ff_bank_transaction_id']]
                    );
                }
                db_execute(
                    "UPDATE acc_qbo_bank_transaction_map
                        SET qbo_txn_date                = ?,
                            qbo_amount                  = ?,
                            qbo_currency_snapshot       = ?,
                            qbo_exchange_rate_snapshot  = ?,
                            qbo_description_snapshot    = ?,
                            pull_status                 = 'pulled',
                            pull_error                  = NULL,
                            last_pulled_at              = ?
                      WHERE id = ?",
                    [$txnDate, $amount, $currency, $exchange, substr($desc, 0, 500), $now, (int) $existing['map_id']]
                );
                return 'updated';
            }

            // Fresh insert path. Compose FF row first because the map row
            // has FK NOT NULL on ff_bank_transaction_id.
            $ffId = db_insert('acc_bank_transactions', [
                'bank_account_id'   => $ffBankAccountId,
                'transaction_date'  => $txnDate,
                'description'       => substr($desc !== '' ? $desc : "QBO {$composite}", 0, 500),
                'reference'         => substr($composite, 0, 255),
                'amount'            => $amount,
                'transaction_type'  => $ffTxnType,
                'source'            => 'qbo_cdc',          // D-QBO-20-3
                'is_readonly'       => 1,                  // D-QBO-20-4 invariant
                'qbo_bank_txn_id'   => $composite,
                'status'            => 'unmatched',        // mirror rows can't participate in FF rec
                'is_cleared'        => 0,
                'notes'             => "QBO CDC mirror — entity={$entityType} id={$qboEntityId}",
                'created_by'        => null,               // system origin
            ]);

            db_insert('acc_qbo_bank_transaction_map', [
                'ff_bank_transaction_id'     => $ffId,
                'qbo_bank_txn_id'            => $composite,
                'qbo_entity_type'            => $entityType,
                'qbo_entity_id'              => $qboEntityId,
                'qbo_account_id'             => $qboBankAccountId,
                'qbo_txn_date'               => $txnDate,
                'qbo_amount'                 => $amount,
                'qbo_currency_snapshot'      => $currency,
                'qbo_exchange_rate_snapshot' => $exchange,
                'qbo_description_snapshot'   => substr($desc, 0, 500),
                'pull_status'                => 'pulled',
                'last_pulled_at'             => $now,
                'first_seen_at'              => $now,
            ]);
            return 'inserted';
        });
    }

    /**
     * Mark a pulled mirror row as superseded — typically called when
     * verifyMappingStillValid sees that a previously-pulled QBO transaction
     * no longer exists (deleted/voided in QBO). FF row preserved with a
     * notes suffix so the audit trail survives.
     */
    public static function markStale(int $ffBankTransactionId, string $reason): void
    {
        $map = db_row(
            "SELECT id, qbo_bank_txn_id
               FROM acc_qbo_bank_transaction_map
              WHERE ff_bank_transaction_id = ?
                AND pull_status = 'pulled'",
            [$ffBankTransactionId]
        );
        if (!$map) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $note = "[markStale {$now}] {$reason}";
        db_execute(
            "UPDATE acc_qbo_bank_transaction_map
                SET pull_status   = 'superseded',
                    pull_error    = ?,
                    last_pulled_at = ?
              WHERE id = ?",
            [$reason, $now, (int) $map['id']]
        );
        db_execute(
            "UPDATE acc_bank_transactions
                SET notes = CONCAT(COALESCE(notes,''), '\n', ?)
              WHERE id = ?",
            [$note, $ffBankTransactionId]
        );
    }

    // ── Internal helpers ───────────────────────────────────────────────

    /**
     * Build + execute the QBO Query for a single entity type scoped to a
     * bank account + TxnDate. Returns the entity collection (possibly
     * empty) without throwing on a "0 rows" response.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryEntities(QuickBooksClient $client, string $entityType, string $qboBankAccountId, string $sinceDate): array
    {
        switch ($entityType) {
            case 'Purchase':
                $sql = "SELECT * FROM Purchase "
                     . "WHERE TxnDate >= '{$sinceDate}' "
                     . "AND AccountRef = '{$qboBankAccountId}'";
                break;
            case 'Deposit':
                $sql = "SELECT * FROM Deposit "
                     . "WHERE TxnDate >= '{$sinceDate}' "
                     . "AND DepositToAccountRef = '{$qboBankAccountId}'";
                break;
            case 'Transfer':
                // QBO Query doesn't support OR across two top-level refs;
                // pull all + filter app-side via rowMatchesAccount().
                $sql = "SELECT * FROM Transfer WHERE TxnDate >= '{$sinceDate}'";
                break;
            case 'JournalEntry':
                // QBO Query can't filter on Line[].AccountRef; pull all
                // + filter app-side.
                $sql = "SELECT * FROM JournalEntry WHERE TxnDate >= '{$sinceDate}'";
                break;
            default:
                return [];
        }
        $resp = $client->query($sql);
        return (array) ($resp['QueryResponse'][$entityType] ?? []);
    }

    /**
     * Defensive app-side filter — confirms the QBO row actually touches
     * the mapped account. Necessary for Transfer + JournalEntry where the
     * SQL filter is too coarse (or impossible) at the Query API level.
     */
    private static function rowMatchesAccount(string $entityType, array $row, string $qboBankAccountId): bool
    {
        switch ($entityType) {
            case 'Purchase':
                return (string) ($row['AccountRef']['value'] ?? '') === $qboBankAccountId;
            case 'Deposit':
                return (string) ($row['DepositToAccountRef']['value'] ?? '') === $qboBankAccountId;
            case 'Transfer':
                $from = (string) ($row['FromAccountRef']['value'] ?? '');
                $to   = (string) ($row['ToAccountRef']['value'] ?? '');
                return $from === $qboBankAccountId || $to === $qboBankAccountId;
            case 'JournalEntry':
                foreach ((array) ($row['Line'] ?? []) as $line) {
                    $acct = (string) ($line['JournalEntryLineDetail']['AccountRef']['value'] ?? '');
                    if ($acct === $qboBankAccountId) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Pull the economic shape out of the QBO row in entity-specific ways.
     * Returns:
     *   amount        — positive decimal string (FF convention, sign in transaction_type)
     *   signed_raw    — signed decimal string for JE type derivation
     *   txn_date      — YYYY-MM-DD
     *   currency      — 3-char or null
     *   exchange_rate — decimal string or null
     *   description   — best-effort description string
     */
    private static function extractEconomicData(string $entityType, array $row, string $qboBankAccountId): array
    {
        $txnDate  = substr((string) ($row['TxnDate'] ?? date('Y-m-d')), 0, 10);
        $currency = self::extractCurrency($row);
        $exchange = isset($row['ExchangeRate']) ? (string) $row['ExchangeRate'] : null;

        $signedRaw = '0.00';
        $desc      = '';

        switch ($entityType) {
            case 'Purchase':
                $signedRaw = '-' . (string) ($row['TotalAmt'] ?? '0.00');  // money out
                $desc = (string) ($row['PrivateNote'] ?? $row['Memo'] ?? ($row['Line'][0]['Description'] ?? ''));
                break;
            case 'Deposit':
                $signedRaw = (string) ($row['TotalAmt'] ?? '0.00');  // money in
                $desc = (string) ($row['PrivateNote'] ?? ($row['Line'][0]['Description'] ?? ''));
                break;
            case 'Transfer':
                $from = (string) ($row['FromAccountRef']['value'] ?? '');
                $to   = (string) ($row['ToAccountRef']['value'] ?? '');
                $amt  = (string) ($row['Amount'] ?? '0.00');
                if ($from === $qboBankAccountId) {
                    $signedRaw = '-' . $amt;
                    $desc = "Transfer to QBO account {$to}";
                } else {
                    $signedRaw = $amt;
                    $desc = "Transfer from QBO account {$from}";
                }
                if (!empty($row['PrivateNote'])) {
                    $desc = (string) $row['PrivateNote'] . ' — ' . $desc;
                }
                break;
            case 'JournalEntry':
                // Sum DR - CR for matching lines on the pulling account.
                $net = '0.00';
                foreach ((array) ($row['Line'] ?? []) as $line) {
                    $acct = (string) ($line['JournalEntryLineDetail']['AccountRef']['value'] ?? '');
                    if ($acct !== $qboBankAccountId) continue;
                    $lineAmt = (string) ($line['Amount'] ?? '0.00');
                    $postType = (string) ($line['JournalEntryLineDetail']['PostingType'] ?? 'Debit');
                    if ($postType === 'Debit') {
                        $net = bcadd($net, $lineAmt, 2);
                    } else {
                        $net = bcsub($net, $lineAmt, 2);
                    }
                }
                $signedRaw = $net;
                $docNumber = (string) ($row['DocNumber'] ?? '');
                $desc = (string) ($row['PrivateNote'] ?? ($docNumber !== '' ? "JE {$docNumber}" : 'QBO JournalEntry'));
                break;
        }

        $amount = bccomp($signedRaw, '0.00', 2) < 0
            ? substr($signedRaw, 1)
            : $signedRaw;
        $amount = number_format((float) $amount, 2, '.', '');

        return [
            'amount'        => $amount,
            'signed_raw'    => $signedRaw,
            'txn_date'      => $txnDate,
            'currency'      => $currency,
            'exchange_rate' => $exchange,
            'description'   => $desc,
        ];
    }

    private static function extractCurrency(array $row): ?string
    {
        $cur = strtoupper((string) ($row['CurrencyRef']['value'] ?? ''));
        return $cur === '' ? null : $cur;
    }

    /**
     * Derive FF transaction_type from the QBO entity type + signed amount.
     * For JournalEntry where direction depends on net debits vs credits.
     */
    private static function deriveFfType(string $entityType, string $signedRaw): string
    {
        if ($entityType !== 'JournalEntry') {
            return self::QBO_TO_FF_TYPE[$entityType] ?? 'other';
        }
        $cmp = bccomp($signedRaw, '0.00', 2);
        if ($cmp > 0)  return 'deposit';
        if ($cmp < 0)  return 'withdrawal';
        return 'other';
    }

    /**
     * Resolve effective `since` for a cron run: explicit settings value
     * wins; otherwise fall back to (now - cdc_lookback_days) per D-QBO-20-6.
     */
    private static function computeSince(): string
    {
        $stored = (string) settings_get('quickbooks.banking.last_bank_cdc_at', '');
        if ($stored !== '') {
            return $stored;
        }
        $lookbackDays = (int) settings_get('quickbooks.banking.cdc_lookback_days', '90');
        if ($lookbackDays < 1) {
            $lookbackDays = 90;
        }
        return date('c', strtotime("-{$lookbackDays} days"));
    }

    private static function aggregateZero(array $extras): array
    {
        return $extras + [
            'pulled'     => 0,
            'updated'    => 0,
            'unchanged'  => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'by_account' => [],
        ];
    }

    private static function auditSkip(int $ffBankAccountId, string $kind, string $notes): void
    {
        // K-22: audit_log.action ENUM doesn't include 'skipped_*' values;
        // 'status_change' is the closest semantic fit for "we observed a QBO
        // event but chose not to mirror it". The notes column carries the
        // specific skip reason for forensic search.
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'status_change',
            'module'      => 'quickbooks',
            'entity_type' => 'bank_transaction_mirror',
            'entity_id'   => $ffBankAccountId,
            'notes'       => "qbo_cdc_{$kind}: {$notes}",
            'ip_address'  => '127.0.0.1',
        ]);
    }
}
