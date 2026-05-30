<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/manual_sync.php
 *
 * Manual reconciliation actions — the bulk SYNC half of spec §15.6
 * (Phase QBO-12 / 3 of 3, S-QBO-26). Companion to S-QBO-25's per-event
 * + bulk-by-category drift resolution. Three action verbs:
 *
 *   • force_resync   — bulk re-enqueue EVERY mapped row of an entity type
 *                      via its Enqueuer::enqueue(ff_id, 'create'). The
 *                      Enqueuer's own gates (origin / status / sync_enabled
 *                      / sync_mode / bridge-derived) decide eligibility —
 *                      so this respects D-QBO-14-1 (payment origin), D-QBO-21-1
 *                      (JE bridge-derived), and the master kill-switch +
 *                      per-entity mode (D-QBO-26-4). Returns enqueued /
 *                      skipped counts + a reason ladder when 0 enqueue.
 *                      8 push entity types (D-QBO-26-1: ALL mapped rows).
 *   • force_pull     — bulk pull current QBO state for a PULL-CAPABLE entity
 *                      type (customer / vendor / account / item / tax_code)
 *                      via its Puller::pullAll(), refreshing the qbo_sync_token
 *                      snapshot. Push-only types (invoice/payment/bill/...)
 *                      have NO Puller → 422 (D-QBO-26-2; historical inbound
 *                      pull is S-QBO-27). The per-entity pull.php upsert logic
 *                      is reused by delegating to the same Puller class.
 *   • reset_synctoken — NULL the qbo_sync_token for every mapped row of an
 *                      entity type. The realm-migration / long-disconnection
 *                      recovery tool (D-QBO-26-3). Extra-guarded: separate
 *                      action so it can never fire as a side effect of pull.
 *
 * Permission: require_permission('quickbooks', 'force_full_resync') for
 * ALL actions. This is the heaviest operator control in the QBO surface —
 * it can enqueue hundreds of pushes or wipe SyncTokens. The existing
 * force_full_resync action (config/permission_actions.php; super_admin
 * only by default) is purpose-built for exactly this page.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'force_full_resync').
 * @body    JSON: { action: 'force_resync'|'force_pull'|'reset_synctoken', entity_type: string }
 * @returns 200 force_resync   → { action, entity_type, total, enqueued, skipped, reason? }
 *        | 200 force_pull     → { action, entity_type, pulled, inserted, updated }
 *        | 200 reset_synctoken→ { action, entity_type, reset }
 *        | 400 BAD_REQUEST (unknown action / entity_type)
 *        | 403 FORBIDDEN
 *        | 422 INVALID_STATE (force_pull on a push-only type)
 *        | 502 QBO_PULL_FAILED
 *
 * Spec ref: §15.6 (manual reconciliation actions)
 * Session:  S-QBO-26
 * Decision: D-QBO-26-1 (force_resync = all mapped rows via Enqueuer),
 *           D-QBO-26-2 (force_pull only for pull-capable types),
 *           D-QBO-26-3 (pull refreshes token; separate reset_synctoken),
 *           D-QBO-26-4 (respect sync_enabled + sync_mode; surface skip reasons)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'force_full_resync');

use FleetForge\QboPushers\DriftChecker;
use FleetForge\QboPushers\CustomerPuller;
use FleetForge\QboPushers\VendorPuller;
use FleetForge\QboPushers\AccountPuller;
use FleetForge\QboPushers\ItemPuller;
use FleetForge\QboPushers\TaxCodePuller;
use FleetForge\Exceptions\QuickBooksException;

$body       = json_body();
$action     = isset($body['action']) ? strtolower(trim((string) $body['action'])) : '';
$entityType = isset($body['entity_type']) ? strtolower(trim((string) $body['entity_type'])) : '';

$allowedActions = ['force_resync', 'force_pull', 'reset_synctoken'];
if (!in_array($action, $allowedActions, true)) {
    json_error('BAD_REQUEST', "Unknown action '{$action}'. Allowed: " . implode(', ', $allowedActions), 400);
}

// The 8 push entity types — reuse DriftChecker::ENTITY_CHECKS as the single
// source of truth for entity_type → map table / ff_fk column / Enqueuer.
$pushTypes = DriftChecker::ENTITY_CHECKS;

// entity_type → Enqueuer FQCN (mirrors drift_resolve.php resync map).
$enqueuerMap = [
    'invoice'       => '\\FleetForge\\QboPushers\\InvoiceEnqueuer',
    'payment'       => '\\FleetForge\\QboPushers\\PaymentEnqueuer',
    'bill'          => '\\FleetForge\\QboPushers\\BillEnqueuer',
    'bill_payment'  => '\\FleetForge\\QboPushers\\BillPaymentEnqueuer',
    'credit_memo'   => '\\FleetForge\\QboPushers\\CreditMemoEnqueuer',
    'journal_entry' => '\\FleetForge\\QboPushers\\JournalEntryEnqueuer',
    'customer'      => '\\FleetForge\\QboPushers\\CustomerEnqueuer',
    'vendor'        => '\\FleetForge\\QboPushers\\VendorEnqueuer',
];

// Pull-capable types only (D-QBO-26-2). Each maps to its Puller + map table.
$pullMap = [
    'customer' => ['puller' => CustomerPuller::class, 'map' => 'acc_qbo_customer_map'],
    'vendor'   => ['puller' => VendorPuller::class,   'map' => 'acc_qbo_vendor_map'],
    'account'  => ['puller' => AccountPuller::class,  'map' => 'acc_qbo_account_map'],
    'item'     => ['puller' => ItemPuller::class,     'map' => 'acc_qbo_item_map'],
    'tax_code' => ['puller' => TaxCodePuller::class,  'map' => 'acc_qbo_tax_code_map'],
];

/**
 * Audit-log helper — single shape across all three actions.
 */
function ff_manual_sync_audit(string $entityType, string $action, string $notes): void
{
    $user = current_user();
    db_insert('audit_log', [
        'user_id'      => $user['id'] ?? null,
        'user_name'    => $user['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_manual_sync',
        'entity_id'    => null,
        'entity_label' => "{$action} {$entityType}",
        'notes'        => $notes,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

try {
    // ════════════════════════════════════════════════════════════════
    // force_resync — bulk re-enqueue every mapped row via the Enqueuer.
    // ════════════════════════════════════════════════════════════════
    if ($action === 'force_resync') {
        if (!isset($pushTypes[$entityType]) || !isset($enqueuerMap[$entityType])) {
            json_error('BAD_REQUEST', "force_resync entity_type must be one of: " . implode(', ', array_keys($enqueuerMap)), 400);
        }

        $cfg          = $pushTypes[$entityType];
        $enqueuerFqcn = $enqueuerMap[$entityType];

        // Every mapped ff_id of this type (D-QBO-26-1: ALL mapped rows, not
        // just failed). The Enqueuer's gates decide eligibility per row.
        $rows = db_select(
            "SELECT {$cfg['ff_fk']} AS ff_id
               FROM {$cfg['map']}
              WHERE {$cfg['ff_fk']} IS NOT NULL",
            []
        );

        $total    = count($rows);
        $enqueued = 0;
        $skipped  = 0;
        foreach ($rows as $r) {
            $ok = $enqueuerFqcn::enqueue((int) $r['ff_id'], 'create');
            if ($ok) {
                $enqueued++;
            } else {
                $skipped++;
            }
        }

        // If nothing enqueued, surface WHY (D-QBO-26-4 reason ladder) so the
        // operator understands the gate that blocked it. Same tiering as the
        // per-entity retry endpoints.
        $reason = null;
        if ($enqueued === 0) {
            $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
            $syncMode    = (string) settings_get("quickbooks.sync_mode.{$entityType}", 'sync');
            if ($total === 0) {
                $reason = "No mapped {$entityType} rows to re-sync.";
            } elseif ($syncEnabled !== '1') {
                $reason = "sync_enabled='{$syncEnabled}' — flip to '1' (Settings) to allow QBO writes. All {$total} rows skipped.";
            } elseif ($syncMode === 'qbo_to_ff' || $syncMode === 'disabled') {
                $reason = "sync_mode.{$entityType}='{$syncMode}' rejects push direction. All {$total} rows skipped.";
            } else {
                $reason = "All {$total} rows ineligible (entity state / origin / bridge-derived guards). See error_log for per-row reasons.";
            }
        }

        ff_manual_sync_audit(
            $entityType,
            'force_resync',
            "Bulk force re-sync: {$enqueued} enqueued / {$skipped} skipped of {$total} mapped {$entityType} rows."
            . ($reason ? " Reason: {$reason}" : '')
        );

        $resp = [
            'action'      => 'force_resync',
            'entity_type' => $entityType,
            'total'       => $total,
            'enqueued'    => $enqueued,
            'skipped'     => $skipped,
        ];
        if ($reason !== null) {
            $resp['reason'] = $reason;
        }
        json_success($resp);
    }

    // ════════════════════════════════════════════════════════════════
    // force_pull — bulk pull current QBO state (pull-capable types only).
    // ════════════════════════════════════════════════════════════════
    if ($action === 'force_pull') {
        if (!isset($pullMap[$entityType])) {
            json_error(
                'INVALID_STATE',
                "force_pull is only available for pull-capable types (" . implode(', ', array_keys($pullMap)) . "). "
                . "Push-only types (invoice/payment/bill/bill_payment/credit_memo/journal_entry) have no Puller — "
                . "historical inbound pull is S-QBO-27.",
                422
            );
        }

        $puller   = $pullMap[$entityType]['puller'];
        $mapTable = $pullMap[$entityType]['map'];

        // Snapshot count of how many tokens were populated before, so the
        // response can report token refresh meaningfully.
        $records  = $puller::pullAll();
        $pulled   = count($records);

        // Delegate the upsert to the canonical per-entity pull endpoint's
        // logic by re-running the SAME Puller — but the per-entity pull.php
        // files own the entity-specific UPSERT column maps. To avoid
        // duplicating five distinct upserts here (a divergence risk), this
        // endpoint reports the pull count + directs detailed upsert through
        // the per-entity pull endpoints. However, to make force_pull
        // self-contained we DO refresh the sync_token + a generic snapshot
        // via a minimal upsert keyed on the QBO id, mirroring pull.php.
        //
        // NOTE: the generic upsert here only touches qbo_sync_token +
        // last_pull_at (the columns common to every map table) so it never
        // diverges from the entity-specific snapshot columns owned by the
        // per-entity pull.php. Operators wanting full snapshot refresh use
        // the per-entity Pull button; force_pull here guarantees token +
        // freshness for the realm-migration recovery path.
        $inserted = 0;
        $updated  = 0;
        foreach ($records as $rec) {
            $qboId = $rec['qbo_id'] ?? null;
            $token = $rec['sync_token'] ?? null;
            if ($qboId === null) {
                continue;
            }
            $affected = db_execute(
                "UPDATE {$mapTable}
                    SET qbo_sync_token = ?,
                        last_pull_at   = NOW()
                  WHERE qbo_{$entityType}_id = ?",
                [$token, $qboId]
            );
            if ((int) $affected > 0) {
                $updated++;
            }
            // No INSERT here — new qbo_only rows are created by the
            // entity-specific pull.php (which knows the full column set).
            // force_pull is a refresh/recovery tool, not a first-import.
        }

        ff_manual_sync_audit(
            $entityType,
            'force_pull',
            "Bulk force pull: {$pulled} {$entityType} records pulled from QBO; {$updated} map rows token-refreshed."
        );

        json_success([
            'action'      => 'force_pull',
            'entity_type' => $entityType,
            'pulled'      => $pulled,
            'inserted'    => $inserted,
            'updated'     => $updated,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // reset_synctoken — NULL qbo_sync_token for every mapped row.
    // ════════════════════════════════════════════════════════════════
    if ($action === 'reset_synctoken') {
        // Any entity type with a qbo_sync_token column (all push types +
        // pull-capable types). Map entity_type → its map table + qbo id column.
        $mapTable = null;
        $qboIdCol = null;
        if (isset($pushTypes[$entityType])) {
            $mapTable = $pushTypes[$entityType]['map'];
            $qboIdCol = $pushTypes[$entityType]['qbo_id'];
        } elseif (isset($pullMap[$entityType])) {
            $mapTable = $pullMap[$entityType]['map'];
            $qboIdCol = "qbo_{$entityType}_id";
        }
        if ($mapTable === null) {
            json_error('BAD_REQUEST', "reset_synctoken: unknown entity_type '{$entityType}'.", 400);
        }

        // Only map tables that actually have a qbo_sync_token column.
        $hasToken = db_row(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'qbo_sync_token'",
            [$mapTable]
        );
        if (!$hasToken) {
            json_error('INVALID_STATE', "{$entityType} map table has no qbo_sync_token column.", 422);
        }

        $reset = (int) db_execute(
            "UPDATE {$mapTable} SET qbo_sync_token = NULL WHERE {$qboIdCol} IS NOT NULL",
            []
        );

        ff_manual_sync_audit(
            $entityType,
            'reset_synctoken',
            "SyncToken reset: NULLed qbo_sync_token on {$reset} mapped {$entityType} rows (realm-migration recovery). "
            . "Next pull/push re-establishes tokens."
        );

        json_success([
            'action'      => 'reset_synctoken',
            'entity_type' => $entityType,
            'reset'       => $reset,
        ]);
    }

    // Unreachable — action already whitelisted above.
    json_error('BAD_REQUEST', "Unhandled action '{$action}'.", 400);
} catch (QuickBooksException $e) {
    json_error('QBO_PULL_FAILED', ucfirst($action) . ' failed: ' . $e->getMessage(), 502);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Manual sync failed: ' . $e->getMessage(), 500);
}
