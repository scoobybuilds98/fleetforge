<?php
declare(strict_types=1);

/**
 * lib/QboPushers/DriftChecker.php
 *
 * Drift-detection engine (S-QBO-24, Phase QBO-12 / 1 of 3). The daily
 * safety net that surfaces FF↔QBO divergence into acc_qbo_drift_events
 * (table created S-QBO-3; consumed by the /quickbooks/drift dashboard +
 * drift_list.php). Per spec §15.
 *
 * HYBRID comparison model (D-QBO-24-1):
 *
 *   SNAPSHOT layer — ALWAYS runs (no QBO API calls; valuable pre-cutover):
 *     • push_failed   — map rows in a failed/failed_preflight* state →
 *                       operator-actionable "retry me" signal. Highest-value
 *                       pre-cutover check.
 *     • amount_drift  — pushed map rows whose FF entity's CURRENT total no
 *                       longer matches the qbo_total_amt snapshot taken at
 *                       push time (i.e. FF changed after push, never
 *                       re-pushed). Gated by per-entity tolerance (§15.5).
 *
 *   LIVE layer — runs ONLY when connected + sync_enabled='1' + NOT fixture
 *   (activates at S-QBO-30 cutover; fixture-mode-exercised in smoke):
 *     • missing_in_ff — a QBO entity with no FF map row (manual QBO creation).
 *     • missing_in_qbo— an eligible FF entity with no successful map row.
 *     • live amount_drift — live QBO total vs FF current total (authoritative).
 *
 * Idempotency: recordDrift() upserts on (entity_type, entity_id, category):
 *   • existing OPEN event (resolved_at IS NULL + resolution_type IS NULL) →
 *     refreshed (detected_at + values), never duplicated;
 *   • existing TERMINAL event (resolution_type IN ('accepted','suppressed'))
 *     → return early — operator's accept/suppress decision persists across
 *     cron runs (S-QBO-25, D-QBO-25-1). Without this guard, a suppressed
 *     event would be re-created as a fresh OPEN row on the next cron run
 *     because resolved_at is SET → no OPEN match → INSERT — defeating
 *     suppress entirely. Tested explicitly in _smoke_qbo_drift_check.php.
 *   • 'resolved' rows (manual or auto-resolved) do NOT block — drift
 *     recurring is a new event worth surfacing.
 *
 * Auto-resolve-on-parity (S-QBO-25, D-QBO-25-2): at the end of runCheck(),
 * any OPEN drift_cron event whose (entity_type, entity_id, category) was
 * NOT re-detected this run is auto-resolved with
 * resolution_type='resolved' + note 'auto-resolved: parity confirmed on
 * <date>'. Scope = detection_source='drift_cron' only (push_failure events
 * resolve via the operator's retry, not the parity cron) AND only
 * categories actually scanned this run (snapshot layer always covers
 * push_failed + amount_drift-where-ff_total-defined; missing_in_ff
 * auto-resolve only when the live layer ran successfully — a live-layer
 * exception for an entity_type leaves its missing_in_ff events alone).
 *
 * Scope boundary: S-QBO-24 = DETECTION + notification + Run-now. Resolution
 * workflows (resolve/accept/suppress, bulk actions — spec §15.4/§15.6) are
 * S-QBO-25 (per-event + bulk-by-category accept/suppress + auto-resolve-
 * on-parity, this session). Bulk force-resync/force-pull are S-QBO-26
 * (Manual Sync page). GL-account-balance drift (§15.2 step 4) is
 * S-QBO-24-GL-BALANCE-FOLLOWUP per D-QBO-24-3.
 *
 * @session  S-QBO-24 (detection), S-QBO-25 (resolution-state interactions)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15
 * @decision D-QBO-24-1 (hybrid snapshot + live), D-QBO-24-2 (live drift_events
 *           schema), D-QBO-24-3 (GL-balance deferred), D-QBO-24-4 (tolerances),
 *           D-QBO-25-1 (resolution_type terminal-state guard in recordDrift),
 *           D-QBO-25-2 (auto-resolve-on-parity scoped to drift_cron + categories
 *           actually scanned this run)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class DriftChecker
{
    /**
     * Map-row push_status values that represent a failed push → push_failed
     * drift. Mirrors the typed-status families across the Pushers.
     */
    public const FAILED_STATUSES = [
        'failed',
        'failed_preflight',
        'failed_preflight_currency_mismatch',
        'failed_preflight_field_too_long',
    ];

    /**
     * Per-run accumulator of (entity_type → set of "ENTITY:{id}|{category}"
     * or "QBO:{qbo_id}|{category}" keys) for events detected this run.
     * Reset at the start of runCheck(); read by autoResolveParity() at end
     * to identify open drift_cron events whose drift is gone. Static so
     * recordDrift() can append without threading state through the call
     * chain. S-QBO-25 / D-QBO-25-2.
     *
     * @var array<string, array<string, true>>
     */
    private static array $detectedKeys = [];

    /**
     * Per-run set of (entity_type → list of categories that were actually
     * scanned this run). Auto-resolve only fires for categories in scope:
     * a missing_in_ff event must NOT auto-resolve if the live layer didn't
     * run (or threw) for its entity_type, because we don't have a complete
     * picture to compare against. S-QBO-25 / D-QBO-25-2.
     *
     * @var array<string, array<int, string>>
     */
    private static array $checkedCategories = [];

    /**
     * Per-entity drift-check configuration. Each entry:
     *   map        — acc_qbo_*_map table
     *   ff_table   — FF source table
     *   ff_fk      — map column → ff_table.id
     *   qbo_id     — map column holding the QBO entity id (NULL until pushed)
     *   ff_total   — FF source column for the live total (null = no amount
     *                drift check: customer/vendor are identity-only;
     *                journal_entry is immutable so uses the snapshot baseline)
     *   ff_deleted — true if ff_table has a deleted_at soft-delete column
     *   tol_key    — settings tolerance key suffix
     *
     * @var array<string, array<string,mixed>>
     */
    public const ENTITY_CHECKS = [
        'invoice' => [
            'map' => 'acc_qbo_invoice_map', 'ff_table' => 'invoices',
            'ff_fk' => 'ff_invoice_id', 'qbo_id' => 'qbo_invoice_id',
            'ff_total' => 'total_amount', 'ff_deleted' => true, 'tol_key' => 'invoice',
        ],
        'payment' => [
            'map' => 'acc_qbo_payment_map', 'ff_table' => 'payments',
            'ff_fk' => 'ff_payment_id', 'qbo_id' => 'qbo_payment_id',
            'ff_total' => 'amount', 'ff_deleted' => true, 'tol_key' => 'payment',
        ],
        'bill' => [
            'map' => 'acc_qbo_bill_map', 'ff_table' => 'acc_bills',
            'ff_fk' => 'ff_bill_id', 'qbo_id' => 'qbo_bill_id',
            'ff_total' => 'total_amount', 'ff_deleted' => false, 'tol_key' => 'bill',
        ],
        'bill_payment' => [
            'map' => 'acc_qbo_bill_payment_map', 'ff_table' => 'acc_ap_payments',
            'ff_fk' => 'ff_ap_payment_id', 'qbo_id' => 'qbo_bill_payment_id',
            'ff_total' => 'amount', 'ff_deleted' => false, 'tol_key' => 'bill_payment',
        ],
        'credit_memo' => [
            'map' => 'acc_qbo_credit_memo_map', 'ff_table' => 'credit_notes',
            'ff_fk' => 'ff_credit_note_id', 'qbo_id' => 'qbo_credit_memo_id',
            'ff_total' => 'amount', 'ff_deleted' => true, 'tol_key' => 'credit_memo',
        ],
        'journal_entry' => [
            'map' => 'acc_qbo_journal_entry_map', 'ff_table' => 'acc_journal_entries',
            'ff_fk' => 'ff_journal_entry_id', 'qbo_id' => 'qbo_journal_entry_id',
            // JEs are immutable once posted (D12-style) — no live FF total
            // column to recompute; the snapshot baseline (ff_je_snapshot_total
            // vs qbo_total_amt) is used. ff_total=null → amount_drift skipped
            // in snapshot layer; push_failed still applies.
            'ff_total' => null, 'ff_deleted' => false, 'tol_key' => 'journal_entry',
        ],
        'customer' => [
            'map' => 'acc_qbo_customer_map', 'ff_table' => 'customers',
            'ff_fk' => 'ff_customer_id', 'qbo_id' => 'qbo_customer_id',
            'ff_total' => null, 'ff_deleted' => true, 'tol_key' => 'customer',
        ],
        'vendor' => [
            'map' => 'acc_qbo_vendor_map', 'ff_table' => 'vendors',
            'ff_fk' => 'ff_vendor_id', 'qbo_id' => 'qbo_vendor_id',
            'ff_total' => null, 'ff_deleted' => true, 'tol_key' => 'vendor',
        ],
    ];

    /**
     * Main entrypoint — called by cron/qbo_drift_check.php + the admin
     * "Run drift check now" button.
     *
     * @param  bool|null $forceLive  null = auto-detect (connected + sync_enabled
     *                   + not fixture); true/false override (smoke/testing).
     * @return array{skipped?:string, checked:int, drift_events:int, by_entity:array, notified:int, live:bool, resolved:int}
     */
    public static function runCheck(?bool $forceLive = null): array
    {
        if ((string) settings_get('quickbooks.drift.enabled', '1') !== '1') {
            return ['skipped' => 'disabled', 'checked' => 0, 'drift_events' => 0, 'by_entity' => [], 'notified' => 0, 'live' => false, 'resolved' => 0];
        }

        // Reset per-run state for auto-resolve-on-parity (D-QBO-25-2). Static
        // accumulators are populated by recordDrift() during the scan; read
        // by autoResolveParity() after the scan completes.
        self::$detectedKeys      = [];
        self::$checkedCategories = [];

        $live = $forceLive ?? self::liveModeAvailable();

        $byEntity = [];
        $totalDrift = 0;
        $notified = 0;

        foreach (self::ENTITY_CHECKS as $entityType => $cfg) {
            $stats = [
                'push_failed'    => 0,
                'amount_drift'   => 0,
                'missing_in_qbo' => 0,
                'missing_in_ff'  => 0,
                'aggregate_drift'=> '0.00',
            ];

            // ── SNAPSHOT layer (always) ──────────────────────────────
            self::checkPushFailed($entityType, $cfg, $stats);
            self::$checkedCategories[$entityType] = ['push_failed'];

            if ($cfg['ff_total'] !== null) {
                self::checkSnapshotAmountDrift($entityType, $cfg, $stats);
                self::$checkedCategories[$entityType][] = 'amount_drift';
            }

            // ── LIVE layer (connected only) ──────────────────────────
            if ($live) {
                try {
                    self::checkLive($entityType, $cfg, $stats);
                    // Only mark missing_in_ff as "scanned this run" if the
                    // live scan completed without exception — otherwise
                    // we'd auto-resolve missing_in_ff events on partial data.
                    self::$checkedCategories[$entityType][] = 'missing_in_ff';
                } catch (\Throwable $e) {
                    // Best-effort — a live-layer failure for one entity must not
                    // abort the whole run or the snapshot-layer findings.
                    error_log("[DriftChecker] live check failed for {$entityType}: " . $e->getMessage());
                }
            }

            // ── Per-entity notification on aggregate amount drift ────
            $threshold = (string) settings_get('quickbooks.drift.notify_threshold', '10.00');
            if (bccomp($stats['aggregate_drift'], $threshold, 2) > 0) {
                if (self::dispatchNotification($entityType, $stats)) {
                    $notified++;
                }
            }

            $byEntity[$entityType] = $stats;
            $totalDrift += $stats['push_failed'] + $stats['amount_drift'] + $stats['missing_in_qbo'] + $stats['missing_in_ff'];
        }

        // ── GL-account-balance drift (F23, gated default-off) ────────
        // Compares FF account natural balance vs QBO CurrentBalance per mapped
        // account. Off by default → no effect on the existing entity-drift run.
        $glStats = ['balance_drift' => 0];
        $glDrift = self::checkGlAccountBalances($glStats, $live);
        if ($glDrift > 0 || isset(self::$checkedCategories['gl_account'])) {
            $byEntity['gl_account'] = $glStats;
            $totalDrift += $glDrift;
        }

        // ── Auto-resolve-on-parity (D-QBO-25-2) ──────────────────────
        // After all entities scanned, close any OPEN drift_cron events whose
        // (entity_type, entity_id, category) was NOT re-detected — drift is
        // gone, mark resolved automatically. Scoped to categories actually
        // scanned this run (push_failure-sourced events untouched; missing_in_ff
        // only auto-resolves when the live layer ran successfully).
        $autoResolved = self::autoResolveParity();

        // Stamp last-check timestamp (whole-run level). Direct upsert —
        // matches BankTransactionPuller::runCdc's last_bank_cdc_at pattern
        // (no settings_set helper exists in this codebase).
        db_execute(
            "INSERT INTO settings (`key`, `value`, value_type, group_name, is_public, is_sensitive)
                  VALUES (?, ?, 'string', 'quickbooks', 0, 0)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['quickbooks.drift.last_check_at', date('Y-m-d H:i:s')]
        );

        return [
            'checked'      => count(self::ENTITY_CHECKS),
            'drift_events' => $totalDrift,
            'by_entity'    => $byEntity,
            'notified'     => $notified,
            'live'         => $live,
            'resolved'     => $autoResolved,
        ];
    }

    /**
     * True when a live QBO query is possible: master sync enabled + connected.
     * Pre-cutover this is false (sync_enabled='0' per D-CPA-5), so the cron
     * runs the snapshot layer only — which is exactly the intended pre-cutover
     * behavior (push_failed + FF-side amount_drift need no QBO API).
     */
    public static function liveModeAvailable(): bool
    {
        if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
            return false;
        }
        if ((string) settings_get('quickbooks.connection_status', '') !== 'connected') {
            return false;
        }
        return true;
    }

    /**
     * SNAPSHOT layer — push_failed: every map row in a failed/failed_preflight*
     * state is an operator-actionable drift (the FF entity tried to push and
     * didn't land in QBO).
     */
    public static function checkPushFailed(string $entityType, array $cfg, array &$stats): void
    {
        $placeholders = implode(',', array_fill(0, count(self::FAILED_STATUSES), '?'));
        $rows = db_select(
            "SELECT {$cfg['ff_fk']} AS ff_id, {$cfg['qbo_id']} AS qbo_id, push_status, push_error
               FROM {$cfg['map']}
              WHERE push_status IN ({$placeholders})",
            self::FAILED_STATUSES
        );
        foreach ($rows as $r) {
            self::recordDrift([
                'entity_type' => $entityType,
                'entity_id'   => (int) $r['ff_id'],
                'qbo_entity_id' => $r['qbo_id'] !== null ? (string) $r['qbo_id'] : null,
                'category'    => 'push_failed',
                'ff_value'    => "push_status={$r['push_status']}",
                'qbo_value'   => null,
                'drift_amount'=> null,
                'description' => "Push failed ({$r['push_status']}): " . substr((string) ($r['push_error'] ?? ''), 0, 400),
            ]);
            $stats['push_failed']++;
        }
    }

    /**
     * SNAPSHOT layer — amount_drift: for each successfully-pushed map row,
     * compare the FF entity's CURRENT total against the qbo_total_amt snapshot
     * captured at push time. A difference beyond tolerance means the FF entity
     * changed after push and was never re-pushed (FF-side drift).
     */
    public static function checkSnapshotAmountDrift(string $entityType, array $cfg, array &$stats): void
    {
        $tol = self::tolerance($cfg['tol_key']);
        $deletedClause = $cfg['ff_deleted'] ? " AND ff.deleted_at IS NULL" : "";
        $rows = db_select(
            "SELECT m.{$cfg['ff_fk']} AS ff_id, m.{$cfg['qbo_id']} AS qbo_id,
                    m.qbo_total_amt AS qbo_snapshot, ff.{$cfg['ff_total']} AS ff_current
               FROM {$cfg['map']} m
               JOIN {$cfg['ff_table']} ff ON ff.id = m.{$cfg['ff_fk']}
              WHERE m.push_status = 'pushed'
                AND m.{$cfg['qbo_id']} IS NOT NULL
                AND m.qbo_total_amt IS NOT NULL{$deletedClause}"
        );
        foreach ($rows as $r) {
            $ffCur = (string) ($r['ff_current'] ?? '0');
            $qboSnap = (string) ($r['qbo_snapshot'] ?? '0');
            $delta = bcsub($ffCur, $qboSnap, 2);
            $absDelta = ltrim($delta, '-');
            if (bccomp($absDelta, $tol, 2) > 0) {
                self::recordDrift([
                    'entity_type' => $entityType,
                    'entity_id'   => (int) $r['ff_id'],
                    'qbo_entity_id' => (string) $r['qbo_id'],
                    'category'    => 'amount_drift',
                    'ff_value'    => $ffCur,
                    'qbo_value'   => $qboSnap,
                    'drift_amount'=> $delta,
                    'description' => "FF current total {$ffCur} ≠ QBO snapshot {$qboSnap} (Δ {$delta}; tolerance {$tol}). FF entity changed after push without re-sync.",
                ]);
                $stats['amount_drift']++;
                $stats['aggregate_drift'] = bcadd($stats['aggregate_drift'], $absDelta, 2);
            }
        }
    }

    /**
     * LIVE layer — queries QBO for the entity type + compares against FF maps.
     * Only invoked when liveModeAvailable() (or forceLive). In fixture mode
     * the QuickBooksClient::query returns seeded fixtures so the smoke can
     * exercise this path offline.
     *
     * v1 detects missing_in_ff (QBO entity with no FF map row). missing_in_qbo
     * + live amount_drift are recorded for entities present in both sides.
     * The QBO entity name per type follows Intuit's PascalCase (Invoice,
     * Payment, Bill, BillPayment, CreditMemo, JournalEntry, Customer, Vendor).
     */
    public static function checkLive(string $entityType, array $cfg, array &$stats): void
    {
        $qboEntity = self::qboEntityName($entityType);
        $client = new QuickBooksClient();
        $resp = $client->query("SELECT * FROM {$qboEntity} MAXRESULTS 1000");
        $list = $resp['QueryResponse'][$qboEntity] ?? [];
        if (!is_array($list)) {
            return;
        }

        // Build the set of QBO ids FF knows about (any map row, pushed or not).
        $mapped = [];
        foreach (db_select("SELECT {$cfg['qbo_id']} AS qid FROM {$cfg['map']} WHERE {$cfg['qbo_id']} IS NOT NULL") as $m) {
            $mapped[(string) $m['qid']] = true;
        }

        foreach ($list as $qbo) {
            $qboId = (string) ($qbo['Id'] ?? '');
            if ($qboId === '') {
                continue;
            }
            if (!isset($mapped[$qboId])) {
                self::recordDrift([
                    'entity_type' => $entityType,
                    'entity_id'   => null,
                    'qbo_entity_id' => $qboId,
                    'category'    => 'missing_in_ff',
                    'ff_value'    => null,
                    'qbo_value'   => isset($qbo['TotalAmt']) ? (string) $qbo['TotalAmt'] : ($qbo['DisplayName'] ?? $qbo['DocNumber'] ?? null),
                    'drift_amount'=> null,
                    'description' => "QBO {$qboEntity} #{$qboId} has no FF mapping (likely manual creation in QBO).",
                ]);
                $stats['missing_in_ff']++;
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  GL-ACCOUNT-BALANCE DRIFT (F23 / S-QBO-24-GL-BALANCE-FOLLOWUP)
    //
    //  For each mapped acc_qbo_account_map row, compare the FF account's
    //  natural-balance (computed from posted JE lines) against QBO's
    //  CurrentBalance; |delta| > tolerance → category='balance_drift' event
    //  (spec §15.2 step 4 + §15.5 GL row).
    //
    //  GATED default-off (quickbooks.drift.gl_balance_enabled='0') — GL
    //  balances legitimately diverge intra-period (timing of bridge JEs vs
    //  QBO posting), so this is noise until the accountant defines the
    //  reconciliation cadence; enabled at cutover (D-QBO-GL-BALANCE-1). The
    //  SNAPSHOT comparison (FF balance vs acc_qbo_account_map.qbo_current_balance,
    //  the last-pulled QBO balance) runs offline; the LIVE layer refreshes
    //  qbo_current_balance from QBO first (gated on liveModeAvailable).
    // ════════════════════════════════════════════════════════════════════

    /** Debit-normal FF account types (natural balance = debit − credit). */
    public const DEBIT_NORMAL_TYPES = ['asset', 'cost_of_revenue', 'operating_expense', 'other_expense'];

    /**
     * FF account NATURAL balance from posted JE lines, signed per the account's
     * normal balance side so it aligns with QBO's positive-normal CurrentBalance
     * convention: debit-normal (asset/expense) → SUM(debit)−SUM(credit);
     * credit-normal (liability/equity/revenue) → SUM(credit)−SUM(debit).
     *
     * @return string|null  bcmath 2dp, or null if the account does not exist
     */
    public static function ffAccountNaturalBalance(int $ffAccountId): ?string
    {
        $acct = db_row("SELECT account_type FROM acc_accounts WHERE id = ?", [$ffAccountId]);
        if ($acct === null) {
            return null;
        }
        $sums = db_row(
            "SELECT COALESCE(SUM(jel.debit),0) AS d, COALESCE(SUM(jel.credit),0) AS c
               FROM acc_journal_entry_lines jel
               JOIN acc_journal_entries je ON je.id = jel.journal_entry_id AND je.status = 'posted'
              WHERE jel.account_id = ?",
            [$ffAccountId]
        );
        $debit  = bcadd((string) ($sums['d'] ?? '0'), '0', 2);
        $credit = bcadd((string) ($sums['c'] ?? '0'), '0', 2);
        $debitNormal = in_array((string) $acct['account_type'], self::DEBIT_NORMAL_TYPES, true);
        return $debitNormal ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }

    /** True when |ffBalance − qboBalance| exceeds the tolerance. */
    public static function glBalanceDrifts(string $ffBalance, string $qboBalance, string $tol): bool
    {
        $delta = bcsub($ffBalance, $qboBalance, 2);
        if (bccomp($delta, '0', 2) < 0) {
            $delta = bcmul($delta, '-1', 2);
        }
        return bccomp($delta, $tol, 2) > 0;
    }

    /**
     * GL-account-balance drift check (F23). Gated default-off. For each mapped
     * account with a known QBO balance, records a balance_drift event when the
     * FF natural balance diverges beyond tolerance. When $live, first refreshes
     * acc_qbo_account_map.qbo_current_balance from QBO (gated; best-effort).
     *
     * @return int  number of balance_drift events recorded this run
     */
    public static function checkGlAccountBalances(array &$stats, bool $live): int
    {
        if ((string) settings_get('quickbooks.drift.gl_balance_enabled', '0') !== '1') {
            return 0;
        }

        // Mark the GL-balance scope as scanned this run so autoResolveParity()
        // can auto-close balance_drift events whose drift is gone — but ONLY
        // because the check actually ran (gated). When off, gl_account is never
        // in checkedCategories, so no auto-resolve touches balance_drift.
        self::$checkedCategories['gl_account'] = ['balance_drift'];

        $tol = self::tolerance('gl_account');
        $drift = 0;

        $maps = db_select(
            "SELECT id, ff_account_id, qbo_account_id, qbo_current_balance
               FROM acc_qbo_account_map
              WHERE mapping_status = 'mapped'
                AND ff_account_id IS NOT NULL
                AND qbo_account_id IS NOT NULL"
        );

        foreach ($maps as $m) {
            $ffAccountId = (int) $m['ff_account_id'];
            $qboAccountId = (string) $m['qbo_account_id'];

            // LIVE layer (gated): refresh qbo_current_balance from QBO.
            $qboBalance = $m['qbo_current_balance'];
            if ($live) {
                try {
                    $client = new QuickBooksClient();
                    $resp = $client->getEntity('account', $qboAccountId);
                    $qboAcct = $resp['Account'] ?? null;
                    if (is_array($qboAcct) && array_key_exists('CurrentBalance', $qboAcct)) {
                        $qboBalance = bcadd((string) $qboAcct['CurrentBalance'], '0', 2);
                        db_update('acc_qbo_account_map', ['qbo_current_balance' => $qboBalance], 'id = ?', [(int) $m['id']]);
                    }
                } catch (\Throwable $e) {
                    error_log("[DriftChecker] GL-balance live refresh failed for account {$qboAccountId}: " . $e->getMessage());
                }
            }

            // No QBO balance to compare against (never pulled) — skip.
            if ($qboBalance === null || $qboBalance === '') {
                continue;
            }
            $qboBalance = bcadd((string) $qboBalance, '0', 2);

            $ffBalance = self::ffAccountNaturalBalance($ffAccountId);
            if ($ffBalance === null) {
                continue;
            }

            if (self::glBalanceDrifts($ffBalance, $qboBalance, $tol)) {
                $delta = bcsub($ffBalance, $qboBalance, 2);
                self::recordDrift([
                    'entity_type'   => 'gl_account',
                    'entity_id'     => $ffAccountId,
                    'qbo_entity_id' => $qboAccountId,
                    'category'      => 'balance_drift',
                    'ff_value'      => $ffBalance,
                    'qbo_value'     => $qboBalance,
                    'drift_amount'  => $delta,
                    'description'   => "FF GL balance {$ffBalance} ≠ QBO CurrentBalance {$qboBalance} (Δ {$delta}; tolerance {$tol}) for account #{$ffAccountId}.",
                ]);
                $drift++;
            }
        }

        $stats['balance_drift'] = $drift;
        return $drift;
    }

    /**
     * Idempotent upsert into acc_qbo_drift_events keyed on
     * (entity_type, entity_id, category). State machine (S-QBO-25):
     *   • existing TERMINAL row (resolution_type IN ('accepted','suppressed'))
     *     → return early. Operator's accept/suppress decision persists
     *     across cron runs; we never re-flag the same drift.
     *   • existing OPEN row (resolved_at IS NULL + resolution_type IS NULL)
     *     → refresh (detected_at + values).
     *   • otherwise (no row, or only 'resolved' rows) → insert new.
     *
     * Always tracks the detected key (S-QBO-25 D-QBO-25-2 auto-resolve) so
     * autoResolveParity() at the end of runCheck() knows not to auto-resolve
     * this key (even if it was the terminal-suppress branch — the operator
     * has already decided this event is handled).
     *
     * Never throws (best-effort, like the Pushers' recording helpers).
     *
     * @param array<string,mixed> $d
     */
    public static function recordDrift(array $d): void
    {
        try {
            $realmId     = (string) settings_get('quickbooks.realm_id', '');
            $environment = (string) settings_get('quickbooks.environment', 'sandbox');

            // Track this key for auto-resolve scope BEFORE any early-return —
            // we don't want autoResolveParity to touch a key the cron detected
            // this run regardless of whether the row is OPEN, terminal, or new.
            self::trackDetectedKey($d);

            // Find an existing event for this key. Match either an OPEN row
            // (resolved_at IS NULL) OR a TERMINAL row (accept/suppress) so we
            // can both refresh the OPEN case and short-circuit the terminal
            // case. 'resolved' rows do NOT match — drift recurring after a
            // resolve creates a fresh event. ORDER BY id DESC picks the most
            // recent if both an OPEN and a terminal exist (data anomaly).
            // entity_id may be NULL (missing_in_ff) — match on qbo_entity_id then.
            if ($d['entity_id'] !== null) {
                $existing = db_row(
                    "SELECT id, resolution_type FROM acc_qbo_drift_events
                      WHERE entity_type = ? AND entity_id = ? AND category = ?
                        AND (resolved_at IS NULL OR resolution_type IN ('accepted','suppressed'))
                      ORDER BY id DESC LIMIT 1",
                    [$d['entity_type'], (int) $d['entity_id'], $d['category']]
                );
            } else {
                $existing = db_row(
                    "SELECT id, resolution_type FROM acc_qbo_drift_events
                      WHERE entity_type = ? AND qbo_entity_id = ? AND category = ?
                        AND (resolved_at IS NULL OR resolution_type IN ('accepted','suppressed'))
                      ORDER BY id DESC LIMIT 1",
                    [$d['entity_type'], (string) ($d['qbo_entity_id'] ?? ''), $d['category']]
                );
            }

            if ($existing) {
                // Terminal — operator has already decided; do nothing.
                if (in_array($existing['resolution_type'], ['accepted', 'suppressed'], true)) {
                    return;
                }
                // Open — refresh.
                db_update('acc_qbo_drift_events', [
                    'detected_at'  => date('Y-m-d H:i:s'),
                    'ff_value'     => $d['ff_value'] ?? null,
                    'qbo_value'    => $d['qbo_value'] ?? null,
                    'drift_amount' => $d['drift_amount'] ?? null,
                    'description'  => $d['description'] ?? null,
                ], 'id = ?', [(int) $existing['id']]);
                return;
            }

            db_insert('acc_qbo_drift_events', [
                'detection_source' => 'drift_cron',
                'category'         => $d['category'],
                'entity_type'      => $d['entity_type'],
                'entity_id'        => $d['entity_id'] !== null ? (int) $d['entity_id'] : null,
                'qbo_entity_id'    => $d['qbo_entity_id'] ?? null,
                'ff_value'         => $d['ff_value'] ?? null,
                'qbo_value'        => $d['qbo_value'] ?? null,
                'drift_amount'     => $d['drift_amount'] ?? null,
                'description'      => $d['description'] ?? null,
                'realm_id'         => $realmId !== '' ? $realmId : 'unknown',
                'environment'      => $environment,
            ]);
        } catch (\Throwable $e) {
            error_log("[DriftChecker] recordDrift failed for {$d['entity_type']}#" . ($d['entity_id'] ?? '?') . ": " . $e->getMessage());
        }
    }

    /**
     * Track a drift key in the per-run detected-set so autoResolveParity()
     * knows it's still active. Key format: "ENTITY:{entity_id}|{category}"
     * when entity_id is set (push_failed/amount_drift), or
     * "QBO:{qbo_entity_id}|{category}" for missing_in_ff (entity_id null).
     * S-QBO-25 / D-QBO-25-2.
     */
    private static function trackDetectedKey(array $d): void
    {
        $et  = (string) $d['entity_type'];
        $cat = (string) $d['category'];
        $key = $d['entity_id'] !== null
            ? 'ENTITY:' . (int) $d['entity_id'] . '|' . $cat
            : 'QBO:'    . (string) ($d['qbo_entity_id'] ?? '') . '|' . $cat;
        if (!isset(self::$detectedKeys[$et])) {
            self::$detectedKeys[$et] = [];
        }
        self::$detectedKeys[$et][$key] = true;
    }

    /**
     * Close OPEN drift_cron events whose drift is no longer detected this
     * run. Scoped to (entity_type, category) pairs that were actually
     * scanned (self::$checkedCategories) — a missing_in_ff event must NOT
     * auto-resolve if the live layer didn't run (or threw) for its
     * entity_type, because we don't have a complete picture to compare.
     *
     * Best-effort/no-throw — caller is non-recoverable.
     *
     * S-QBO-25 / D-QBO-25-2. Spec §15.4 "auto-marks resolved when next
     * drift check confirms parity".
     *
     * @return int  count of events auto-resolved
     */
    private static function autoResolveParity(): int
    {
        $resolved = 0;
        try {
            foreach (self::$checkedCategories as $entityType => $categoriesInScope) {
                if (empty($categoriesInScope)) {
                    continue;
                }
                $detected = self::$detectedKeys[$entityType] ?? [];

                // Pull OPEN drift_cron events for this entity_type +
                // category-in-scope. Limit by category WHERE so we don't
                // even read events outside scope (push_failure-sourced is
                // already filtered by detection_source).
                $catPlaceholders = implode(',', array_fill(0, count($categoriesInScope), '?'));
                $rows = db_select(
                    "SELECT id, entity_id, qbo_entity_id, category
                       FROM acc_qbo_drift_events
                      WHERE entity_type = ?
                        AND detection_source = 'drift_cron'
                        AND resolved_at IS NULL
                        AND resolution_type IS NULL
                        AND category IN ({$catPlaceholders})",
                    array_merge([$entityType], $categoriesInScope)
                );

                $toResolveIds = [];
                foreach ($rows as $r) {
                    $key = $r['entity_id'] !== null
                        ? 'ENTITY:' . (int) $r['entity_id'] . '|' . (string) $r['category']
                        : 'QBO:'    . (string) ($r['qbo_entity_id'] ?? '') . '|' . (string) $r['category'];
                    if (!isset($detected[$key])) {
                        $toResolveIds[] = (int) $r['id'];
                    }
                }

                if ($toResolveIds === []) {
                    continue;
                }

                $note = 'auto-resolved: parity confirmed on ' . date('Y-m-d');
                $idPlaceholders = implode(',', array_fill(0, count($toResolveIds), '?'));
                // Re-check the gate in the UPDATE WHERE — defends against
                // a concurrent operator click landing between SELECT and
                // UPDATE (defensive; cron runs serially under GET_LOCK but
                // an admin "Run drift check now" could overlap).
                $affected = (int) db_execute(
                    "UPDATE acc_qbo_drift_events
                        SET resolved_at     = NOW(),
                            resolution_type = 'resolved',
                            resolution_note = ?
                      WHERE id IN ({$idPlaceholders})
                        AND resolved_at IS NULL
                        AND resolution_type IS NULL
                        AND detection_source = 'drift_cron'",
                    array_merge([$note], $toResolveIds)
                );
                $resolved += $affected;
            }
        } catch (\Throwable $e) {
            error_log('[DriftChecker] autoResolveParity failed: ' . $e->getMessage());
        }
        return $resolved;
    }

    /**
     * Dispatch a quickbooks notification when a per-entity aggregate amount
     * drift exceeds the threshold (spec §15.2 step 3). Best-effort — never
     * throws. Audience = super_admin + accountant (resolved by the caller's
     * NotificationService module rules; explicit IDs not required here).
     */
    private static function dispatchNotification(string $entityType, array $stats): bool
    {
        try {
            $msg = "QBO drift: {$entityType} aggregate amount drift {$stats['aggregate_drift']} "
                 . "({$stats['amount_drift']} entities) exceeds notify threshold.";
            if (class_exists('\\FleetForge\\Notifications\\NotificationService')) {
                \FleetForge\Notifications\NotificationService::notify(
                    'quickbooks',                 // type → quickbooks module/category
                    'QBO Drift Detected',         // title
                    $msg,                          // message
                    'qbo_drift',                  // entityType
                    null,                          // entityId (aggregate, no single row)
                    '/quickbooks/drift',          // url (drilldown)
                    null,                          // specificUserIds (module rules resolve audience)
                    'warning'                      // severity
                );
            }
            return true;
        } catch (\Throwable $e) {
            error_log("[DriftChecker] notification dispatch failed for {$entityType}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-entity tolerance from settings (spec §15.5), defaulting to a sane
     * value per entity if the setting is absent.
     */
    public static function tolerance(string $tolKey): string
    {
        $defaults = [
            'invoice' => '0.05', 'payment' => '0.01', 'bill' => '0.05',
            'bill_payment' => '0.01', 'credit_memo' => '0.05',
            'journal_entry' => '0.01', 'customer' => '0.00', 'vendor' => '0.00',
            'gl_account' => '1.00', // F23 — GL-account-balance drift tolerance (spec §15.5)
        ];
        $v = (string) settings_get("quickbooks.drift.tolerance.{$tolKey}", $defaults[$tolKey] ?? '0.00');
        return $v === '' ? ($defaults[$tolKey] ?? '0.00') : $v;
    }

    /**
     * FF entity_type → Intuit QBO entity name (PascalCase) for live queries.
     */
    public static function qboEntityName(string $entityType): string
    {
        return [
            'invoice'       => 'Invoice',
            'payment'       => 'Payment',
            'bill'          => 'Bill',
            'bill_payment'  => 'BillPayment',
            'credit_memo'   => 'CreditMemo',
            'journal_entry' => 'JournalEntry',
            'customer'      => 'Customer',
            'vendor'        => 'Vendor',
        ][$entityType] ?? ucfirst($entityType);
    }
}
