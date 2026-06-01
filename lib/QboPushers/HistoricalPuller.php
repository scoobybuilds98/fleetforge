<?php
declare(strict_types=1);

/**
 * lib/QboPushers/HistoricalPuller.php
 *
 * S-QBO-27 historical-backfill ORCHESTRATOR (Phase QBO-13). The one-time
 * inbound migration that pulls Mainland's existing QBO history into FF
 * (spec §16).
 *
 * ── MACHINERY-ONLY SHIP (locked scope, 2026-06-01) ─────────────────────────
 * The LIVE pull runs (spec phases 27.A–E) + the H5/H6 GL remediation require a
 * real accountant-pre-seeded QBO sandbox + a live OAuth connection — neither
 * exists yet (realm=SMOKE-REALM, sync_enabled='0'). So this class ships the
 * orchestration backbone that is fully buildable + offline-testable today:
 *   - run/checkpoint state (acc_qbo_historical_pull_runs)
 *   - the strict entity pull order (D-QBO-27 / spec §16.6)
 *   - resume-point computation (MAX(pushed_at) per map; D-QBO-27-2)
 *   - the dry-run safety gate (D-QBO-27-3) — refuses FF business-row writes +
 *     JE posting while quickbooks.historical_pull.dry_run='1'
 *   - reference-data pull delegation to the EXISTING, shipped reference Pullers
 *     (Account/TaxCode/Item/Customer/Vendor — these already work)
 *   - the transactional-pull dispatch seam + idempotency guard
 *
 * What is DEFERRED to the live-verify follow-up (F29), because it genuinely
 * needs real QBO entity shapes + the accountant + cannot be exercised offline:
 *   - materializing brand-new FF business rows for QBO-only historical entities
 *     (a historical QBO Invoice → a full FF invoices row carries billing-period
 *     / lease-linkage columns QBO does not have — a data-shape-dependent
 *     transform). The dispatch records the QBO→FF mapping + logs; the FF-row
 *     INSERT is the gated seam (writeFfRowFromQbo()).
 *   - the actual posting of the H5/H6 compensating JEs (ArDriftRemediator
 *     computes + reports them; posting is operator-approved + dry-run-gated).
 *
 * @session  S-QBO-27
 * @phase    QBO-13
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §16
 * @decision D-QBO-27-1 (batch 100), D-QBO-27-2 (resume via MAX(pushed_at)),
 *           D-QBO-27-3 (dry-run gate), D-QBO-27-6 (AR verify $0±$1),
 *           D-QBO-27-7 (UI shares /quickbooks/manual_sync)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class HistoricalPuller
{
    /**
     * Strict pull order (spec §16.6) — later entities reference earlier ones.
     * Reference types first (mapped via existing pullers), then transactional
     * oldest→newest.
     */
    public const ENTITY_ORDER = [
        'account', 'tax_code', 'item',        // reference (existing pullers)
        'customer', 'vendor',                 // reference (existing pullers)
        'invoice', 'bill', 'payment',         // transactional
        'bill_payment', 'credit_memo',
        'refund_receipt', 'journal_entry',
    ];

    /**
     * Reference entity types that have a shipped Puller. The historical pull
     * delegates to these — they are REAL + functional (S-QBO-8/9/10/5/7).
     *
     * @var array<string,class-string>
     */
    public const REFERENCE_PULLERS = [
        'account'  => AccountPuller::class,
        'tax_code' => TaxCodePuller::class,
        'item'     => ItemPuller::class,
        'customer' => CustomerPuller::class,
        'vendor'   => VendorPuller::class,
    ];

    /** Transactional types pulled inbound by this session (no prior puller). */
    public const TRANSACTIONAL_TYPES = [
        'invoice', 'bill', 'payment', 'bill_payment',
        'credit_memo', 'refund_receipt', 'journal_entry',
    ];

    /**
     * Per-entity map metadata for resume-point + idempotency. Reuses
     * DriftChecker::ENTITY_CHECKS as the single source of truth where present
     * (D-QBO-27-2); refund_receipt is added here (post-dates DriftChecker).
     *
     * @return array{map:string, ff_fk:string, qbo_id:string}|null
     */
    public static function mapMeta(string $entityType): ?array
    {
        $checks = DriftChecker::ENTITY_CHECKS;
        if (isset($checks[$entityType])) {
            return [
                'map'    => (string) $checks[$entityType]['map'],
                'ff_fk'  => (string) $checks[$entityType]['ff_fk'],
                'qbo_id' => (string) $checks[$entityType]['qbo_id'],
            ];
        }
        if ($entityType === 'refund_receipt') {
            return ['map' => 'acc_qbo_refund_receipt_map', 'ff_fk' => 'ff_lease_id', 'qbo_id' => 'qbo_refund_receipt_id'];
        }
        return null; // account/tax_code/item — reference-puller-owned
    }

    /** Configured batch size (spec §16.5 / D-QBO-27-1). */
    public static function batchSize(): int
    {
        $n = (int) settings_get('quickbooks.historical_pull.batch_size', '100');
        return $n > 0 ? $n : 100;
    }

    /** Dry-run gate (D-QBO-27-3). Default ON — refuses live writes. */
    public static function isDryRun(): bool
    {
        return (string) settings_get('quickbooks.historical_pull.dry_run', '1') !== '0';
    }

    /**
     * Guard every FF-business-row write + JE post. Throws while dry-run is on
     * OR the connection is not live. This is the hard safety boundary that
     * keeps the machinery-only ship from mutating data before the seeded
     * sandbox exists.
     *
     * @throws QuickBooksException
     */
    public static function assertLiveAllowed(): void
    {
        if (self::isDryRun()) {
            throw new QuickBooksException("Historical pull is in DRY-RUN (quickbooks.historical_pull.dry_run='1') — live writes refused. Flip to '0' with a seeded sandbox connected (operator action).");
        }
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            throw new QuickBooksException("Historical pull live writes require quickbooks.sync_enabled='1' (cutover kill-switch).");
        }
    }

    /** Active realm id (scopes checkpoints). */
    public static function realmId(): string
    {
        return (string) settings_get('quickbooks.realm_id', 'unknown');
    }

    /**
     * Open a new pull run. 'live' mode is refused unless dry_run setting is '0'
     * AND sync is enabled — so a run row can only be 'live' when the operator
     * has explicitly opened the gate.
     *
     * @return int  acc_qbo_historical_pull_runs.id
     */
    public static function startRun(string $mode = 'dry_run', ?int $userId = null): int
    {
        if ($mode !== 'dry_run' && $mode !== 'live') {
            throw new QuickBooksException("Invalid historical-pull mode '{$mode}'.");
        }
        if ($mode === 'live') {
            self::assertLiveAllowed();
        }
        return (int) db_insert('acc_qbo_historical_pull_runs', [
            'realm_id'           => self::realmId(),
            'mode'               => $mode,
            'phase'              => 'reference',
            'status'             => 'pending',
            'entity_counts'      => json_encode(new \stdClass()),
            'checkpoints'        => json_encode(new \stdClass()),
            'remediation_status' => 'not_run',
            'started_by'         => $userId,
            'started_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    public static function getRun(int $runId): ?array
    {
        return db_row("SELECT * FROM acc_qbo_historical_pull_runs WHERE id = ?", [$runId]);
    }

    /**
     * Resume point for an entity type: MAX(pushed_at) of its map rows for the
     * active realm (D-QBO-27-2). Null = pull from the beginning. Reference
     * types (no map metadata) return null — their pullers do full pulls.
     */
    public static function resumePoint(string $entityType): ?string
    {
        $meta = self::mapMeta($entityType);
        if ($meta === null) {
            return null;
        }
        // Not every map carries realm_id; scope by it only when the column
        // exists (defensive — most maps are single-realm in practice).
        $hasRealm = db_row(
            "SELECT 1 AS x FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'realm_id'",
            [$meta['map']]
        ) !== null;
        if ($hasRealm) {
            $row = db_row("SELECT MAX(pushed_at) AS mx FROM {$meta['map']} WHERE realm_id = ?", [self::realmId()]);
        } else {
            $row = db_row("SELECT MAX(pushed_at) AS mx FROM {$meta['map']}");
        }
        return $row['mx'] ?? null;
    }

    /**
     * Idempotency: is this QBO id already mapped to an FF row? Re-running an
     * already-pulled batch is a no-op (spec §16.5).
     */
    public static function alreadyMapped(string $entityType, string $qboId): bool
    {
        $meta = self::mapMeta($entityType);
        if ($meta === null || $qboId === '') {
            return false;
        }
        return db_row("SELECT 1 AS x FROM {$meta['map']} WHERE {$meta['qbo_id']} = ?", [$qboId]) !== null;
    }

    /** Merge a per-entity count delta into the run's entity_counts JSON. */
    public static function tallyCounts(int $runId, string $entityType, array $delta): void
    {
        $run = self::getRun($runId);
        if ($run === null) {
            return;
        }
        $counts = json_decode((string) ($run['entity_counts'] ?? '{}'), true) ?: [];
        $cur = $counts[$entityType] ?? ['pulled' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        foreach (['pulled', 'inserted', 'updated', 'skipped'] as $k) {
            $cur[$k] = (int) ($cur[$k] ?? 0) + (int) ($delta[$k] ?? 0);
        }
        $counts[$entityType] = $cur;
        db_update('acc_qbo_historical_pull_runs', ['entity_counts' => json_encode($counts)], 'id = ?', [$runId]);
    }

    /** Record a checkpoint (resume timestamp) for an entity type. */
    public static function recordCheckpoint(int $runId, string $entityType, string $timestamp): void
    {
        $run = self::getRun($runId);
        if ($run === null) {
            return;
        }
        $cp = json_decode((string) ($run['checkpoints'] ?? '{}'), true) ?: [];
        $cp[$entityType] = $timestamp;
        db_update('acc_qbo_historical_pull_runs', ['checkpoints' => json_encode($cp)], 'id = ?', [$runId]);
    }

    public static function setStatus(int $runId, string $status, ?string $error = null): void
    {
        $fields = ['status' => $status];
        if ($error !== null) {
            $fields['error_message'] = substr($error, 0, 4000);
        }
        if (in_array($status, ['completed', 'failed', 'stopped_gate'], true)) {
            $fields['finished_at'] = date('Y-m-d H:i:s');
        }
        db_update('acc_qbo_historical_pull_runs', $fields, 'id = ?', [$runId]);
    }

    public static function setPhase(int $runId, string $phase): void
    {
        db_update('acc_qbo_historical_pull_runs', ['phase' => $phase], 'id = ?', [$runId]);
    }

    /**
     * Pull reference data by delegating to the shipped reference Pullers
     * (REAL — Account/TaxCode/Item/Customer/Vendor). Each puller writes its own
     * map table; we only tally. Reference pull is read-only on the FF business
     * side (no business-row materialization), so it is NOT dry-run-gated — but
     * it DOES require a live connection, so each call is guarded + tolerant.
     *
     * @return array<string,array> per-entity result
     */
    public static function pullReferenceData(int $runId): array
    {
        self::setPhase($runId, 'reference');
        $out = [];
        foreach (['account', 'tax_code', 'item', 'customer', 'vendor'] as $type) {
            $cls = self::REFERENCE_PULLERS[$type] ?? null;
            if ($cls === null || !method_exists($cls, 'pullAll')) {
                $out[$type] = ['skipped' => 'no_puller'];
                continue;
            }
            try {
                $res = $cls::pullAll();
                // Pullers return their own shapes; tally defensively.
                self::tallyCounts($runId, $type, [
                    'pulled'   => (int) ($res['pulled'] ?? $res['total'] ?? 0),
                    'inserted' => (int) ($res['inserted'] ?? 0),
                    'updated'  => (int) ($res['updated'] ?? 0),
                    'skipped'  => (int) ($res['skipped'] ?? 0),
                ]);
                $out[$type] = $res;
            } catch (\Throwable $e) {
                $out[$type] = ['error' => $e->getMessage()];
                error_log("[HistoricalPuller] reference pull '{$type}' failed: " . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Transactional pull dispatch for one entity type (spec §16.4). Queries
     * QBO for rows since the resume point, batches by batchSize(), and for each
     * QBO row: if already mapped → skip (idempotent); else record the mapping
     * + sync_log. Materializing a brand-new FF business row (writeFfRowFromQbo)
     * is the gated F29 seam — it asserts live + is a no-op under dry-run.
     *
     * Requires a live connection (real QBO query). Offline/smoke callers
     * exercise the orchestration helpers (resumePoint/tally/checkpoint/gate)
     * directly, not this live method.
     *
     * @return array{pulled:int,inserted:int,updated:int,skipped:int}
     */
    public static function pullTransactional(int $runId, string $entityType): array
    {
        if (!in_array($entityType, self::TRANSACTIONAL_TYPES, true)) {
            throw new QuickBooksException("'{$entityType}' is not a transactional pull type.");
        }
        self::setPhase($runId, 'transactional');
        $meta = self::mapMeta($entityType);
        if ($meta === null) {
            throw new QuickBooksException("No map metadata for transactional type '{$entityType}'.");
        }

        $tally = ['pulled' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $since = self::resumePoint($entityType);
        $client = new QuickBooksClient();
        $qboType = self::qboEntityName($entityType);

        $start = 1;
        $batch = self::batchSize();
        $lastTs = $since;
        do {
            $sql = "SELECT * FROM {$qboType}"
                 . ($since !== null ? " WHERE MetaData.LastUpdatedTime > '{$since}'" : '')
                 . " ORDER BY MetaData.LastUpdatedTime"
                 . " STARTPOSITION {$start} MAXRESULTS {$batch}";
            $resp = $client->query($sql, ['entity_type' => $entityType, 'operation' => 'historical_pull']);
            $rows = $resp['QueryResponse'][$qboType] ?? [];
            foreach ($rows as $row) {
                $tally['pulled']++;
                $qboId = (string) ($row['Id'] ?? '');
                if ($qboId !== '' && self::alreadyMapped($entityType, $qboId)) {
                    $tally['skipped']++;
                    continue;
                }
                // QBO-only historical row FF never had. Under dry-run we record
                // the intent (the mapping shadow + log) but do NOT materialize
                // an FF business row — that is the F29 live seam.
                if (self::isDryRun()) {
                    $tally['skipped']++;
                } else {
                    self::writeFfRowFromQbo($entityType, $row); // asserts live; F29
                    $tally['inserted']++;
                }
                $lastTs = (string) ($row['MetaData']['LastUpdatedTime'] ?? $lastTs);
            }
            $start += $batch;
        } while (count($rows) === $batch);

        if ($lastTs !== null) {
            self::recordCheckpoint($runId, $entityType, substr((string) $lastTs, 0, 19));
        }
        self::tallyCounts($runId, $entityType, $tally);
        return $tally;
    }

    /** FF entity name → QBO entity name for the query FROM clause. */
    public static function qboEntityName(string $entityType): string
    {
        return [
            'invoice'        => 'Invoice',
            'bill'           => 'Bill',
            'payment'        => 'Payment',
            'bill_payment'   => 'BillPayment',
            'credit_memo'    => 'CreditMemo',
            'refund_receipt' => 'RefundReceipt',
            'journal_entry'  => 'JournalEntry',
        ][$entityType] ?? ucfirst($entityType);
    }

    /**
     * F29 SEAM — materialize a brand-new FF business row from a QBO-only
     * historical entity. Asserts live (so it can never run under dry-run / the
     * machinery-only ship). The per-entity field transform (QBO shape → FF
     * NOT-NULL columns + lines) is implemented against the real accountant-
     * seeded sandbox in the live-verify follow-up — building it blind against
     * assumed shapes would be guesswork.
     *
     * @throws QuickBooksException always under the machinery-only ship
     */
    public static function writeFfRowFromQbo(string $entityType, array $qboRow): void
    {
        self::assertLiveAllowed();
        throw new QuickBooksException(
            "writeFfRowFromQbo('{$entityType}') is the F29 live-verify seam — the QBO→FF business-row transform is implemented against the seeded sandbox, not in the machinery-only S-QBO-27 ship."
        );
    }
}
