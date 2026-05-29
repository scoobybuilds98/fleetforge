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
 * Idempotency: recordDrift() upserts on (entity_type, entity_id, category) —
 * an existing OPEN event (resolved_at IS NULL) is refreshed (detected_at +
 * values), never duplicated. So re-running the cron daily doesn't pile up
 * rows; a resolved event re-opens only if the drift recurs.
 *
 * Scope boundary: S-QBO-24 = DETECTION + notification + Run-now. Resolution
 * workflows (resolve/accept/suppress, bulk actions — spec §15.4/§15.6) are
 * S-QBO-25. GL-account-balance drift (§15.2 step 4) is S-QBO-24-GL-BALANCE-
 * FOLLOWUP per D-QBO-24-3.
 *
 * @session  S-QBO-24
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15
 * @decision D-QBO-24-1 (hybrid snapshot + live), D-QBO-24-2 (live drift_events
 *           schema), D-QBO-24-3 (GL-balance deferred), D-QBO-24-4 (tolerances)
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
     * @return array{skipped?:string, checked:int, drift_events:int, by_entity:array, notified:int, live:bool}
     */
    public static function runCheck(?bool $forceLive = null): array
    {
        if ((string) settings_get('quickbooks.drift.enabled', '1') !== '1') {
            return ['skipped' => 'disabled', 'checked' => 0, 'drift_events' => 0, 'by_entity' => [], 'notified' => 0, 'live' => false];
        }

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
            if ($cfg['ff_total'] !== null) {
                self::checkSnapshotAmountDrift($entityType, $cfg, $stats);
            }

            // ── LIVE layer (connected only) ──────────────────────────
            if ($live) {
                try {
                    self::checkLive($entityType, $cfg, $stats);
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

    /**
     * Idempotent upsert into acc_qbo_drift_events keyed on
     * (entity_type, entity_id, category, open). An existing OPEN event is
     * refreshed (detected_at + values); otherwise a new row is inserted.
     * Never throws (best-effort, like the Pushers' recording helpers).
     *
     * @param array<string,mixed> $d
     */
    public static function recordDrift(array $d): void
    {
        try {
            $realmId     = (string) settings_get('quickbooks.realm_id', '');
            $environment = (string) settings_get('quickbooks.environment', 'sandbox');

            // Find an existing OPEN event for this key (resolved_at IS NULL).
            // entity_id may be NULL (missing_in_ff) — match on qbo_entity_id then.
            if ($d['entity_id'] !== null) {
                $existing = db_row(
                    "SELECT id FROM acc_qbo_drift_events
                      WHERE entity_type = ? AND entity_id = ? AND category = ? AND resolved_at IS NULL
                      ORDER BY id DESC LIMIT 1",
                    [$d['entity_type'], (int) $d['entity_id'], $d['category']]
                );
            } else {
                $existing = db_row(
                    "SELECT id FROM acc_qbo_drift_events
                      WHERE entity_type = ? AND qbo_entity_id = ? AND category = ? AND resolved_at IS NULL
                      ORDER BY id DESC LIMIT 1",
                    [$d['entity_type'], (string) ($d['qbo_entity_id'] ?? ''), $d['category']]
                );
            }

            if ($existing) {
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
