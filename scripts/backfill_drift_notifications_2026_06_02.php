<?php
declare(strict_types=1);

/**
 * scripts/backfill_drift_notifications_2026_06_02.php
 *
 * One-shot fix for drift notification deep-links:
 *
 *   1. Soft-delete stale aggregate drift notifications (entity_id=NULL,
 *      url ends with /quickbooks/drift) — these can never link to a
 *      specific drift profile since no drift ID was stored.
 *
 *   2. Create per-event notifications for every OPEN push_failed and
 *      amount_drift event, each linking to drift_show.php?id=X.
 *      These are the most operator-actionable categories.
 *
 *   3. Create one grouped summary notification per entity_type for
 *      missing_in_ff events (bulk vendor/customer gaps that are
 *      informational rather than individually actionable).
 *
 * USAGE:
 *   php scripts/backfill_drift_notifications_2026_06_02.php            (dry-run)
 *   php scripts/backfill_drift_notifications_2026_06_02.php --execute
 */

require_once realpath(__DIR__ . '/../config/app.php');

use FleetForge\Notifications\NotificationService;

$dryRun = !in_array('--execute', $argv, true);
echo 'Mode: ' . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n\n";

// ── Step 1: count stale aggregate notifications ───────────────────────────
$staleCount = db_count(
    "SELECT COUNT(*) FROM notifications
      WHERE entity_type = 'qbo_drift'
        AND entity_id IS NULL
        AND url LIKE '%/quickbooks/drift'
        AND deleted_at IS NULL"
);
echo "Step 1 — Stale aggregate drift notifications to soft-delete: {$staleCount}\n";

// ── Step 2: open push_failed + amount_drift events ───────────────────────
$actionableEvents = db_select(
    "SELECT id, category, entity_type, entity_id, description
       FROM acc_qbo_drift_events
      WHERE resolved_at IS NULL
        AND category IN ('push_failed', 'amount_drift')
      ORDER BY detected_at DESC"
);
echo 'Step 2 — Actionable open drift events (push_failed + amount_drift): ' . count($actionableEvents) . "\n";

// ── Step 3: missing_in_ff grouped by entity_type ─────────────────────────
$missingGroups = db_select(
    "SELECT entity_type, COUNT(*) AS n
       FROM acc_qbo_drift_events
      WHERE resolved_at IS NULL AND category = 'missing_in_ff'
      GROUP BY entity_type"
);
echo 'Step 3 — missing_in_ff groups: ' . count($missingGroups) . ' entity type(s)';
foreach ($missingGroups as $g) {
    echo " ({$g['entity_type']}:{$g['n']})";
}
echo "\n\n";

if ($dryRun) {
    echo "Dry-run done. Re-run with --execute to apply.\n";
    exit(0);
}

// ── Execute ───────────────────────────────────────────────────────────────

// Step 1 — soft-delete stale
$deleted = db_execute(
    "UPDATE notifications
        SET deleted_at = UTC_TIMESTAMP()
      WHERE entity_type = 'qbo_drift'
        AND entity_id IS NULL
        AND url LIKE '%/quickbooks/drift'
        AND deleted_at IS NULL"
);
echo "Step 1 done — soft-deleted {$deleted} stale notifications.\n";

// Step 2 — per-event notifications for push_failed + amount_drift
$created = 0;
foreach ($actionableEvents as $ev) {
    $eTyp  = str_replace('_', ' ', (string) $ev['entity_type']);
    $cat   = str_replace('_', ' ', (string) $ev['category']);
    $title = ucfirst($eTyp) . ' ' . $cat . ' drift';
    $msg   = $ev['description'] ?: "Open {$eTyp} {$cat} drift event — review and resolve.";
    NotificationService::notify(
        type:       'quickbooks.drift',
        title:      $title,
        message:    $msg,
        entityType: 'qbo_drift',
        entityId:   (int) $ev['id'],
        url:        base_url('quickbooks/drift_show') . '?id=' . $ev['id'],
        severity:   $ev['category'] === 'push_failed' ? 'critical' : 'warning'
    );
    $created++;
}
echo "Step 2 done — created notifications for {$created} actionable drift events.\n";

// Step 3 — one grouped notification per entity_type for missing_in_ff
foreach ($missingGroups as $g) {
    $eTyp = str_replace('_', ' ', (string) $g['entity_type']);
    $n    = (int) $g['n'];
    NotificationService::notify(
        type:       'quickbooks.drift',
        title:      "{$n} {$eTyp} entities missing in FF",
        message:    "{$n} QBO {$eTyp} entities have no FF mapping. Review via the drift dashboard.",
        entityType: 'qbo_drift',
        entityId:   null,
        url:        base_url('quickbooks/drift') . '?category=missing_in_ff&entity_type=' . rawurlencode((string) $g['entity_type']),
        severity:   'warning'
    );
}
echo 'Step 3 done — created ' . count($missingGroups) . " grouped missing_in_ff notification(s).\n";

// Audit log
db_insert('audit_log', [
    'user_id'      => null,
    'user_name'    => 'system',
    'action'       => 'update',
    'module'       => 'notifications',
    'entity_type'  => 'drift_notification_backfill',
    'entity_id'    => null,
    'entity_label' => '2026-06-02 drift notification backfill',
    'ip_address'   => '127.0.0.1',
    'notes'        => "Soft-deleted {$deleted} stale aggregate drift notifications; created per-event notifications for {$created} actionable drift events + " . count($missingGroups) . ' grouped missing_in_ff summaries.',
]);

echo "\n✓ Done.\n";
exit(0);
