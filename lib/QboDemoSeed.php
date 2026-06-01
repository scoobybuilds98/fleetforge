<?php
declare(strict_types=1);

/**
 * lib/QboDemoSeed.php
 *
 * Offline demo-seed orchestrator (S-QBO-OFFLINE-TESTBED). Drives the REAL
 * QBO sync pipeline against the fixture HTTP layer (QboFixture +
 * QuickBooksClient::executeFixture) to populate every /quickbooks/* admin
 * surface with realistic, browseable data — without a live Intuit
 * connection.
 *
 * What load() actually does (D-QBO-FIXTURE-4 — "run the real pipeline"):
 *   1. Production guard — refuses to load against a real-realm /
 *      production environment. The guard lives in
 *      QuickBooksClient::fixtureMode() but is re-asserted here so the
 *      operator can never accidentally seed canned data into a real
 *      sandbox.
 *   2. Snapshot current settings (fixture_mode, realm_id, sync_enabled,
 *      per-entity sync_mode) so the demo run is fully reversible.
 *   3. Flip fixture_mode='1' + realm_id='FIXTURE-DEMO' + sync_enabled='1' +
 *      per-entity sync_mode='sync' for the duration of the seed call.
 *   4. INSERT a handful of synthetic FF customers + vendors in the
 *      reserved id range [999000, 999989] (smokes own 999990-999999).
 *      Names prefixed 'FIXTURE-DEMO' so even a column-name probe shows
 *      origin.
 *   5. Drive each through the canonical Enqueuer → worker dispatcher →
 *      Pusher pipeline — exact same code path live traffic takes; only
 *      the wire round-trip is intercepted by QboFixture::respond.
 *   6. Manufacture failure / skip states via QboFixture::injectError +
 *      sync_mode flips so the operator sees the full lifecycle:
 *      pushed / failed / skipped_by_mode / skipped_soft_deleted.
 *   7. Run AccountPuller / ItemPuller / TaxCodePuller / CustomerPuller /
 *      VendorPuller — reference data + qbo_only mappings for the
 *      missing_in_ff drift category.
 *   8. DriftChecker::runCheck(forceLive=true) — populates
 *      acc_qbo_drift_events from both snapshot + live layers.
 *   9. Restore the snapshotted settings.
 *
 * wipe() removes ONLY rows we tagged. Two tags compose:
 *   • realm_id='FIXTURE-DEMO'                — sync_log, drift_events
 *   • ff_*_id BETWEEN 999000 AND 999989     — map tables + FF entity tables,
 *                                              acc_qbo_sync_queue.entity_id,
 *                                              acc_qbo_sync_log.entity_id
 *   • qbo_*_id LIKE 'QBO-FIX-%'              — defence-in-depth on map tables
 *
 * The wipe path snapshots real-row counts before/after and the
 * accompanying smoke asserts they're unchanged.
 *
 * @session  S-QBO-OFFLINE-TESTBED
 * @decision D-QBO-FIXTURE-1 (intercept), D-QBO-FIXTURE-2 (production guard),
 *           D-QBO-FIXTURE-3 (in-code fixture), D-QBO-FIXTURE-4 (real-pipeline
 *           demo-seed over direct INSERT)
 */

namespace FleetForge;

use FleetForge\QboPushers\AccountPuller;
use FleetForge\QboPushers\CustomerEnqueuer;
use FleetForge\QboPushers\CustomerPuller;
use FleetForge\QboPushers\CustomerPusher;
use FleetForge\QboPushers\DriftChecker;
use FleetForge\QboPushers\ItemPuller;
use FleetForge\QboPushers\TaxCodePuller;
use FleetForge\QboPushers\VendorEnqueuer;
use FleetForge\QboPushers\VendorPuller;
use FleetForge\QboPushers\VendorPusher;

class QboDemoSeed
{
    /** Reserved FF id range for fixture entities. Smokes own 999990-999999. */
    public const FF_ID_MIN = 999000;
    public const FF_ID_MAX = 999989;

    /**
     * Map tables touched by wipe — keyed by table name, value is
     * [ffCol, qboCol]. Pairings cannot be derived from naive
     * ff_*→qbo_* substitution (credit_memo uses ff_credit_note_id ↔
     * qbo_credit_memo_id; bill_payment uses ff_ap_payment_id ↔
     * qbo_bill_payment_id), so the canonical pairs are listed
     * explicitly here (mirrors DriftChecker::ENTITY_CHECKS).
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const MAP_TABLES = [
        'acc_qbo_customer_map'      => ['ff_customer_id',      'qbo_customer_id'],
        'acc_qbo_vendor_map'        => ['ff_vendor_id',        'qbo_vendor_id'],
        'acc_qbo_invoice_map'       => ['ff_invoice_id',       'qbo_invoice_id'],
        'acc_qbo_bill_map'          => ['ff_bill_id',          'qbo_bill_id'],
        'acc_qbo_payment_map'       => ['ff_payment_id',       'qbo_payment_id'],
        'acc_qbo_bill_payment_map'  => ['ff_ap_payment_id',    'qbo_bill_payment_id'],
        'acc_qbo_credit_memo_map'   => ['ff_credit_note_id',   'qbo_credit_memo_id'],
        'acc_qbo_journal_entry_map' => ['ff_journal_entry_id', 'qbo_journal_entry_id'],
    ];

    /** FF entity tables we INSERT into during load — wipe deletes by id range. */
    private const FF_ENTITY_TABLES = ['customers', 'vendors'];

    /**
     * Execute a full offline seed run. Returns a summary the API endpoint
     * can hand back to the UI ({pushed, failed, skipped, pulled, drift_events}).
     *
     * @return array<string,mixed>
     * @throws \RuntimeException when the production guard refuses.
     */
    public static function load(): array
    {
        // ── Production guard (D-QBO-FIXTURE-2 re-assertion) ────────────
        $env   = (string) settings_get('quickbooks.environment', 'sandbox');
        $realm = (string) settings_get('quickbooks.realm_id', '');
        if ($env === 'production') {
            throw new \RuntimeException(
                'QboDemoSeed::load refused — environment is production. Fixture data must NEVER mix with a real Intuit realm (D-QBO-FIXTURE-2).'
            );
        }
        if ($realm !== '' && $realm !== 'SMOKE-REALM' && $realm !== QboFixture::REALM_SENTINEL) {
            throw new \RuntimeException(
                "QboDemoSeed::load refused — realm_id='{$realm}' looks like a real Intuit realm. Disconnect from QBO first (D-QBO-FIXTURE-2)."
            );
        }

        // ── Snapshot mutated settings (restore at the end) ────────────
        $snapshot = self::snapshotSettings([
            'quickbooks.fixture_mode',
            'quickbooks.realm_id',
            'quickbooks.environment',
            'quickbooks.sync_enabled',
            'quickbooks.connection_status',
            'quickbooks.multi_currency_enabled',
            'quickbooks.sync_mode.customer',
            'quickbooks.sync_mode.vendor',
            'quickbooks.sync_mode.invoice',
            'quickbooks.sync_mode.payment',
        ]);

        // Reset any prior fixture state (idempotent — load() can be
        // re-run; we don't accumulate stale injected errors).
        QboFixture::reset();

        $summary = [
            'pushed'       => 0,
            'failed'       => 0,
            'skipped'      => 0,
            'pulled'       => ['customer' => 0, 'vendor' => 0, 'account' => 0, 'item' => 0, 'tax_code' => 0],
            'drift_events' => 0,
            'queue_drained'=> 0,
        ];

        try {
            // ── Idempotency pre-clean (D-QBO-FIXTURE-5) ───────────────
            // Clear any fixture-tagged rows left by a prior load that
            // crashed before its finally, or by an operator clicking
            // "Load" twice. Without this, the DETERMINISTIC synthetic ids
            // (QBO-FIX-<entity>-<n>, counter resets per process) collide
            // with surviving rows on the uq_qbo_* UNIQUE constraints.
            self::deleteFixtureRows();

            // ── Activate fixture mode + sandbox-permissive settings ───
            self::writeSetting('quickbooks.environment',           'sandbox');
            self::writeSetting('quickbooks.realm_id',              QboFixture::REALM_SENTINEL);
            self::writeSetting('quickbooks.fixture_mode',          '1');
            self::writeSetting('quickbooks.sync_enabled',          '1');
            self::writeSetting('quickbooks.connection_status',     'connected');
            self::writeSetting('quickbooks.multi_currency_enabled','1');
            self::writeSetting('quickbooks.sync_mode.customer',    'sync');
            self::writeSetting('quickbooks.sync_mode.vendor',      'sync');

            // ── Seed FF entities + enqueue ────────────────────────────
            $customerIds = self::seedCustomers();
            $vendorIds   = self::seedVendors();

            // Manufacture a permanent failure on the FIRST customer push so
            // the operator sees a failed/push_failed row in the admin UI.
            // Single-shot — pops on first match, subsequent calls succeed.
            QboFixture::injectError(
                'customer:create',
                400,
                ['Fault' => [
                    'type'  => 'ValidationFault',
                    'Error' => [[
                        'code'    => '610',
                        'Message' => 'Object Not Found',
                        'Detail'  => 'Synthetic injected failure for offline demo (S-QBO-OFFLINE-TESTBED).',
                    ]],
                ]]
            );

            // Enqueue all customers (failure injection fires on FIRST dispatch)
            foreach ($customerIds as $cid) {
                CustomerEnqueuer::enqueue($cid, 'create');
            }
            foreach ($vendorIds as $vid) {
                VendorEnqueuer::enqueue($vid, 'create');
            }

            // ── Drain the worker inline (no separate cron run needed) ─
            $summary['queue_drained'] = self::drainQueue($summary);

            // ── Manufacture a skipped_by_mode row ─────────────────────
            // Briefly flip mode to qbo_to_ff and run pushCreate against
            // the LAST customer so it records skipped_by_mode in map +
            // sync_log (without going through the queue).
            $skipCustomer = self::seedSingleCustomer('FIXTURE-DEMO Skipped Mode Customer');
            self::writeSetting('quickbooks.sync_mode.customer', 'qbo_to_ff');
            CustomerPusher::pushCreate($skipCustomer);
            self::writeSetting('quickbooks.sync_mode.customer', 'sync');
            $summary['skipped']++;

            // ── Manufacture a skipped_soft_deleted row ────────────────
            $sdCustomer = self::seedSingleCustomer('FIXTURE-DEMO Soft-Deleted Customer', true);
            CustomerPusher::pushCreate($sdCustomer);
            $summary['skipped']++;

            // ── Pull reference data + customer/vendor (qbo_only) ──────
            // CustomerPuller + VendorPuller bring back canned QBO records
            // with no FF link — exactly the "missing_in_ff" drift case.
            // AccountPuller / ItemPuller / TaxCodePuller populate the
            // reference admin pages.
            $summary['pulled']['account']  = self::runPull(AccountPuller::class,  'account');
            $summary['pulled']['item']     = self::runPull(ItemPuller::class,     'item');
            $summary['pulled']['tax_code'] = self::runPull(TaxCodePuller::class,  'tax_code');
            $summary['pulled']['customer'] = self::runPull(CustomerPuller::class, 'customer');
            $summary['pulled']['vendor']   = self::runPull(VendorPuller::class,   'vendor');

            // ── DriftChecker (forceLive=true so live layer runs against fixture) ──
            $drift = DriftChecker::runCheck(true);
            $summary['drift_events'] = (int) ($drift['drift_events'] ?? 0);
        } finally {
            // ── Always restore settings, even on exception ────────────
            self::restoreSettings($snapshot);
            QboFixture::reset();
        }

        return $summary;
    }

    /**
     * Wipe ONLY rows tagged as fixture-originated. Snapshot real-row
     * counts before + after for the smoke to assert no real data was
     * touched.
     *
     * @return array{deleted:array<string,int>, real_row_diff:array<string,int>}
     */
    public static function wipe(): array
    {
        // ── Pre-wipe real-row counts ──────────────────────────────────
        // Real = NOT in the fixture range AND NOT realm_id=FIXTURE-DEMO.
        $before  = self::countRealRows();
        $deleted = self::deleteFixtureRows();
        $after   = self::countRealRows();

        // ── Post-wipe real-row counts — must match before ─────────────
        $realDiff = [];
        foreach ($before as $table => $count) {
            $realDiff[$table] = ($after[$table] ?? 0) - $count;
        }

        return [
            'deleted'        => $deleted,
            'real_row_diff'  => $realDiff,
        ];
    }

    /**
     * Delete every fixture-tagged row across the QBO surface. Returns a
     * per-table deleted count. Shared by wipe() (which wraps it with
     * before/after real-row count assertions) AND by load() (which calls
     * it FIRST so a seed is idempotent — a prior load that crashed before
     * its finally, or an operator clicking "Load" twice, can't leave a
     * survivor that collides with the next run's DETERMINISTIC synthetic
     * id on a UNIQUE qbo_*_id constraint).
     *
     * D-QBO-FIXTURE-5 (idempotency): the fixture id counter resets per
     * process so `QBO-FIX-<entity>-<n>` ids repeat across runs; combined
     * with the `uq_qbo_*` UNIQUE keys, ANY surviving fixture map row would
     * make the next run's INSERT collide. Clearing fixture rows at the
     * START of every load/seed is the structural guarantee that makes the
     * operation safe to repeat. (Surfaced 2026-06-01 by an operator
     * `_smoke_qbo_fixture_pipeline` run that hit a 1062 duplicate on
     * acc_qbo_customer_map.uq_qbo_customer.)
     *
     * @return array<string,int>  table → rows deleted
     */
    private static function deleteFixtureRows(): array
    {
        $deleted = [];

        // ── sync_log + drift_events: realm-tagged OR fixture-entity-id ─
        $deleted['acc_qbo_sync_log'] = (int) db_execute(
            "DELETE FROM acc_qbo_sync_log
              WHERE realm_id = ?
                 OR (entity_id BETWEEN ? AND ?)",
            [QboFixture::REALM_SENTINEL, self::FF_ID_MIN, self::FF_ID_MAX]
        );
        $deleted['acc_qbo_drift_events'] = (int) db_execute(
            "DELETE FROM acc_qbo_drift_events
              WHERE realm_id = ?
                 OR (entity_id BETWEEN ? AND ?)
                 OR qbo_entity_id LIKE ?",
            [QboFixture::REALM_SENTINEL, self::FF_ID_MIN, self::FF_ID_MAX, QboFixture::ID_PREFIX . '%']
        );

        // ── sync_queue: by FF entity_id range only (no realm column) ──
        $deleted['acc_qbo_sync_queue'] = (int) db_execute(
            "DELETE FROM acc_qbo_sync_queue
              WHERE entity_id BETWEEN ? AND ?",
            [self::FF_ID_MIN, self::FF_ID_MAX]
        );

        // ── Map tables: FF id range + defence-in-depth on QBO-FIX- prefix ──
        foreach (self::MAP_TABLES as $table => [$ffCol, $qboCol]) {
            $deleted[$table] = (int) db_execute(
                "DELETE FROM `{$table}`
                  WHERE ({$ffCol} BETWEEN ? AND ?)
                     OR {$qboCol} LIKE ?",
                [self::FF_ID_MIN, self::FF_ID_MAX, QboFixture::ID_PREFIX . '%']
            );
        }

        // Reference pulls (account/item/tax_code) — qbo_only rows we created.
        foreach (['acc_qbo_account_map', 'acc_qbo_item_map', 'acc_qbo_tax_code_map'] as $table) {
            $qboCol = ($table === 'acc_qbo_tax_code_map')
                ? 'qbo_tax_code_id'
                : str_replace(['acc_qbo_', '_map'], ['qbo_', '_id'], $table);
            $deleted[$table] = (int) db_execute(
                "DELETE FROM `{$table}` WHERE {$qboCol} LIKE ?",
                [QboFixture::ID_PREFIX . '%']
            );
        }

        // ── FF entity tables: by id range ─────────────────────────────
        foreach (self::FF_ENTITY_TABLES as $table) {
            $deleted[$table] = (int) db_execute(
                "DELETE FROM `{$table}` WHERE id BETWEEN ? AND ?",
                [self::FF_ID_MIN, self::FF_ID_MAX]
            );
        }

        return $deleted;
    }

    // ============================================================
    // INTERNAL — settings snapshot/restore
    // ============================================================

    /** @param string[] $keys @return array<string,?string> */
    private static function snapshotSettings(array $keys): array
    {
        $snap = [];
        foreach ($keys as $k) {
            $snap[$k] = settings_get($k, null);
        }
        return $snap;
    }

    /** @param array<string,?string> $snapshot */
    private static function restoreSettings(array $snapshot): void
    {
        foreach ($snapshot as $k => $v) {
            if ($v === null) {
                db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
            } else {
                self::writeSetting($k, (string) $v);
            }
        }
    }

    private static function writeSetting(string $key, string $value): void
    {
        db_execute(
            "INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`)
                  VALUES (?, ?, 'string', 'quickbooks')
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$key, $value]
        );
    }

    // ============================================================
    // INTERNAL — FF entity seeding
    // ============================================================

    /**
     * Insert 3 synthetic customers in the reserved id range. Returns the
     * inserted ids (in seed order so the worker drain processes the
     * injection-failure target first).
     *
     * @return int[]
     */
    private static function seedCustomers(): array
    {
        $rows = [
            [999000, 'FIXTURE-DEMO Acme Trucking Ltd.',  'ops@acme.fixture',  'CAD'],
            [999001, 'FIXTURE-DEMO Beaver Haulage Inc.', 'ap@beaver.fixture', 'CAD'],
            [999002, 'FIXTURE-DEMO Cascade Freight Co.', 'ar@cascade.fixture','USD'],
        ];
        $ids = [];
        foreach ($rows as [$id, $name, $email, $currency]) {
            // Best-effort: if a prior seed left rows behind, replace cleanly.
            db_execute("DELETE FROM customers WHERE id = ?", [$id]);
            db_execute(
                "INSERT INTO customers (id, company_name, email, currency) VALUES (?, ?, ?, ?)",
                [$id, $name, $email, $currency]
            );
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Insert 2 synthetic vendors in the reserved id range.
     * @return int[]
     */
    private static function seedVendors(): array
    {
        $rows = [
            [999100, 'FIXTURE-DEMO Petro-Can Cards',  'ar@petrocan.fixture'],
            [999101, 'FIXTURE-DEMO Coastal Tires',    'ap@coastal.fixture'],
        ];
        $ids = [];
        foreach ($rows as [$id, $name, $email]) {
            db_execute("DELETE FROM vendors WHERE id = ?", [$id]);
            db_execute(
                "INSERT INTO vendors (id, name, vendor_type, email, currency) VALUES (?, ?, 'other', ?, 'CAD')",
                [$id, $name, $email]
            );
            $ids[] = $id;
        }
        return $ids;
    }

    private static function seedSingleCustomer(string $name, bool $softDeleted = false): int
    {
        // Pick the next free slot in the reserved range.
        $row = db_row(
            "SELECT COALESCE(MAX(id), ?) + 1 AS next_id FROM customers WHERE id BETWEEN ? AND ?",
            [self::FF_ID_MIN - 1, self::FF_ID_MIN, self::FF_ID_MAX]
        );
        $id = (int) ($row['next_id'] ?? self::FF_ID_MIN);
        if ($softDeleted) {
            db_execute(
                "INSERT INTO customers (id, company_name, currency, deleted_at) VALUES (?, ?, 'CAD', NOW())",
                [$id, $name]
            );
        } else {
            db_execute(
                "INSERT INTO customers (id, company_name, currency) VALUES (?, ?, 'CAD')",
                [$id, $name]
            );
        }
        return $id;
    }

    // ============================================================
    // INTERNAL — worker drain + reference pulls
    // ============================================================

    /**
     * Drain the sync queue inline. Mirrors the cron/qbo_sync_worker.php
     * decision tree per row (sync_mode → hasImplementation → dispatch),
     * scoped to fixture-range entity ids so we don't disturb anything
     * already queued. Increments summary['pushed'|'failed'|'skipped']
     * per row by inspecting the Pusher's return outcome.
     *
     * @return int Number of rows drained.
     */
    private static function drainQueue(array &$summary): int
    {
        $rows = db_select(
            "SELECT id, entity_type, entity_id, operation, retry_count, max_retries, payload_snapshot
               FROM acc_qbo_sync_queue
              WHERE status='queued'
                AND entity_id BETWEEN ? AND ?
              ORDER BY id ASC",
            [self::FF_ID_MIN, self::FF_ID_MAX]
        );

        $drained = 0;
        foreach ($rows as $row) {
            $qid        = (int) $row['id'];
            $entityType = (string) $row['entity_type'];
            $entityId   = (int) $row['entity_id'];
            $operation  = (string) $row['operation'];
            $payload    = $row['payload_snapshot'] !== null
                ? json_decode((string) $row['payload_snapshot'], true)
                : null;

            db_execute(
                "UPDATE acc_qbo_sync_queue SET status='processing', picked_up_at=NOW() WHERE id=?",
                [$qid]
            );

            QuickBooksClient::setWorkerContext($qid, $entityType, $entityId);

            try {
                $result  = QboPusherDispatcher::dispatch($entityType, $operation, $entityId, $payload);
                $outcome = $result['outcome'] ?? (empty($result['success']) ? 'failed' : 'created');

                if ($outcome === 'created' || $outcome === 'updated') {
                    db_execute(
                        "UPDATE acc_qbo_sync_queue SET status='completed', completed_at=NOW(), error_code=NULL, error_message=NULL WHERE id=?",
                        [$qid]
                    );
                    $summary['pushed']++;
                } elseif ($outcome === 'skipped') {
                    db_execute(
                        "UPDATE acc_qbo_sync_queue SET status='skipped', completed_at=NOW(), error_code=?, error_message=? WHERE id=?",
                        [(string) ($result['status'] ?? 'skipped'), 'Skipped: ' . (string) ($result['status'] ?? ''), $qid]
                    );
                    $summary['skipped']++;
                } else {
                    db_execute(
                        "UPDATE acc_qbo_sync_queue SET status='failed', completed_at=NOW(), error_code=?, error_message=? WHERE id=?",
                        [substr((string) ($result['error_code'] ?? 'pusher_failed'), 0, 50), substr((string) ($result['error'] ?? 'fixture-injected failure'), 0, 500), $qid]
                    );
                    $summary['failed']++;
                }
            } catch (\Throwable $e) {
                db_execute(
                    "UPDATE acc_qbo_sync_queue SET status='failed', completed_at=NOW(), error_code='unexpected_error', error_message=? WHERE id=?",
                    [substr($e->getMessage(), 0, 500), $qid]
                );
                $summary['failed']++;
            } finally {
                QuickBooksClient::setWorkerContext(null, null, null);
            }
            $drained++;
        }
        return $drained;
    }

    /**
     * Invoke a Puller::pullAll() and bulk-upsert the returned records into
     * the matching map table as qbo_only rows (mapping_status='qbo_only').
     * Per-entity column shapes differ; we only persist the common subset
     * (qbo_id, qbo_sync_token, name, active) — enough for the admin page
     * to render a meaningful row.
     *
     * @return int Number of qbo_only rows inserted.
     */
    private static function runPull(string $pullerFqcn, string $entityType): int
    {
        $records = $pullerFqcn::pullAll();
        $inserted = 0;
        foreach ($records as $rec) {
            $qboId = (string) ($rec['qbo_id'] ?? '');
            if ($qboId === '') {
                continue;
            }
            $name   = (string) ($rec['display_name'] ?? $rec['name'] ?? ($entityType . ' ' . $qboId));
            $active = (int) ($rec['active'] ?? 1);
            try {
                self::upsertPullRow($entityType, $qboId, $name, $active, $rec);
                $inserted++;
            } catch (\Throwable $e) {
                error_log('[QboDemoSeed::runPull] upsert failed for ' . $entityType . '#' . $qboId . ': ' . $e->getMessage());
            }
        }
        return $inserted;
    }

    private static function upsertPullRow(string $entityType, string $qboId, string $name, int $active, array $rec): void
    {
        switch ($entityType) {
            case 'customer':
                db_execute(
                    "INSERT IGNORE INTO acc_qbo_customer_map (qbo_customer_id, qbo_display_name, qbo_active, mapping_status, last_pull_at)
                          VALUES (?, ?, ?, 'qbo_only', NOW())",
                    [$qboId, $name, $active]
                );
                return;
            case 'vendor':
                db_execute(
                    "INSERT IGNORE INTO acc_qbo_vendor_map (qbo_vendor_id, qbo_display_name, qbo_active, mapping_status, last_pull_at)
                          VALUES (?, ?, ?, 'qbo_only', NOW())",
                    [$qboId, $name, $active]
                );
                return;
            case 'account':
                db_execute(
                    "INSERT IGNORE INTO acc_qbo_account_map (qbo_account_id, qbo_name, qbo_active, mapping_status, last_pull_at)
                          VALUES (?, ?, ?, 'qbo_only', NOW())",
                    [$qboId, $name, $active]
                );
                return;
            case 'item':
                db_execute(
                    "INSERT IGNORE INTO acc_qbo_item_map (qbo_item_id, qbo_name, qbo_active, mapping_status, last_pull_at)
                          VALUES (?, ?, ?, 'qbo_only', NOW())",
                    [$qboId, $name, $active]
                );
                return;
            case 'tax_code':
                db_execute(
                    "INSERT IGNORE INTO acc_qbo_tax_code_map (qbo_tax_code_id, qbo_name, qbo_active, mapping_status, last_pull_at)
                          VALUES (?, ?, ?, 'qbo_only', NOW())",
                    [$qboId, $name, $active]
                );
                return;
        }
    }

    // ============================================================
    // INTERNAL — real-row count snapshot (used by wipe assertion)
    // ============================================================

    /**
     * Count rows that are definitely NOT fixture-tagged across the tables
     * the wipe touches. Smoke asserts the diff is zero between pre-wipe
     * and post-wipe counts.
     *
     * @return array<string,int>
     */
    public static function countRealRows(): array
    {
        $counts = [];

        $counts['acc_qbo_sync_log'] = (int) db_count(
            "SELECT COUNT(*) FROM acc_qbo_sync_log WHERE realm_id <> ? AND (entity_id IS NULL OR entity_id NOT BETWEEN ? AND ?)",
            [QboFixture::REALM_SENTINEL, self::FF_ID_MIN, self::FF_ID_MAX]
        );
        $counts['acc_qbo_drift_events'] = (int) db_count(
            "SELECT COUNT(*) FROM acc_qbo_drift_events WHERE realm_id <> ? AND (entity_id IS NULL OR entity_id NOT BETWEEN ? AND ?)",
            [QboFixture::REALM_SENTINEL, self::FF_ID_MIN, self::FF_ID_MAX]
        );
        $counts['acc_qbo_sync_queue'] = (int) db_count(
            "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id NOT BETWEEN ? AND ?",
            [self::FF_ID_MIN, self::FF_ID_MAX]
        );

        foreach (self::MAP_TABLES as $table => [$ffCol, $qboCol]) {
            $counts[$table] = (int) db_count(
                "SELECT COUNT(*) FROM `{$table}`
                  WHERE ({$ffCol} IS NULL OR {$ffCol} NOT BETWEEN ? AND ?)
                    AND ({$qboCol} IS NULL OR {$qboCol} NOT LIKE ?)",
                [self::FF_ID_MIN, self::FF_ID_MAX, QboFixture::ID_PREFIX . '%']
            );
        }
        foreach (['acc_qbo_account_map' => 'qbo_account_id', 'acc_qbo_item_map' => 'qbo_item_id', 'acc_qbo_tax_code_map' => 'qbo_tax_code_id'] as $table => $qboCol) {
            $counts[$table] = (int) db_count(
                "SELECT COUNT(*) FROM `{$table}` WHERE {$qboCol} IS NULL OR {$qboCol} NOT LIKE ?",
                [QboFixture::ID_PREFIX . '%']
            );
        }
        foreach (self::FF_ENTITY_TABLES as $table) {
            $counts[$table] = (int) db_count(
                "SELECT COUNT(*) FROM `{$table}` WHERE id NOT BETWEEN ? AND ?",
                [self::FF_ID_MIN, self::FF_ID_MAX]
            );
        }

        return $counts;
    }
}
