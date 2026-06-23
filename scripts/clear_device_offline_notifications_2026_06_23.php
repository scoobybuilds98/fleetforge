<?php
/**
 * scripts/clear_device_offline_notifications_2026_06_23.php
 *
 * One-time backlog cleanup for the Samsara "device offline" notification flood.
 *
 * Context: the generator for device-offline ("N units offline / Not connected
 * for 8+ hours") notifications was removed in S-SAMSARA-OFFLINE-NOTIF-OFF
 * (cron/samsara_sync.php, commit 43f9ce3) — no NEW ones are created. But the
 * backlog that accumulated before the fix (type = 'samsara.not_connected',
 * 2026-06-06 → 2026-06-23) is still sitting in the notifications table, one row
 * per recipient, so it keeps filling every user's bell. As of 2026-06-23 prod
 * held 21,187 such rows (18,356 unread) — ~92% of all unread notifications.
 *
 * This script clears that backlog. It is SCOPED PRECISELY to
 * type = 'samsara.not_connected' (verified on prod: title-match and type-match
 * agree exactly — zero false positives), so battery alerts, lease/invoice
 * notifications, etc. are never touched. Other users' copies are cleared too
 * (the predicate is type-only, not user-scoped), which is intended — everyone
 * got flooded.
 *
 * It is DRY RUN by default and idempotent (re-running changes nothing once done).
 *
 * Two modes (the bell badge filters `is_read = 0 AND deleted_at IS NULL`; the
 * dropdown list filters `deleted_at IS NULL`):
 *   --mark-read    Set is_read = 1. Clears the unread badge; the rows remain
 *                  visible in the "See all notifications" history as read.
 *   --soft-delete  Set deleted_at = NOW(). Removes them from BOTH the badge and
 *                  the entire list. Non-destructive — the rows stay in the DB and
 *                  can be restored by nulling deleted_at. (Recommended.)
 *
 * USAGE (operator, on prod — prod is read-only for the agent, so run this yourself):
 *   php scripts/clear_device_offline_notifications_2026_06_23.php                 # dry run (counts only)
 *   php scripts/clear_device_offline_notifications_2026_06_23.php --apply --soft-delete
 *   php scripts/clear_device_offline_notifications_2026_06_23.php --apply --mark-read
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';

const NOTIF_TYPE = 'samsara.not_connected';

$args       = $argv;
$apply      = in_array('--apply', $args, true);
$softDelete = in_array('--soft-delete', $args, true);
$markRead   = in_array('--mark-read', $args, true);

// Force an explicit mode choice when applying — never guess which one the
// operator wants for a 20k-row prod mutation.
if ($softDelete && $markRead) {
    fwrite(STDERR, "ERROR: choose exactly ONE of --mark-read / --soft-delete.\n");
    exit(1);
}
if ($apply && !$softDelete && !$markRead) {
    fwrite(STDERR, "ERROR: --apply requires a mode: --soft-delete (recommended) or --mark-read.\n");
    exit(1);
}
$mode = $softDelete ? 'soft-delete' : 'mark-read';

// ── Current state ────────────────────────────────────────────────────────────
$total     = db_count("SELECT COUNT(*) FROM notifications WHERE type = ?", [NOTIF_TYPE]);
$unread    = db_count("SELECT COUNT(*) FROM notifications WHERE type = ? AND is_read = 0", [NOTIF_TYPE]);
$undeleted = db_count("SELECT COUNT(*) FROM notifications WHERE type = ? AND deleted_at IS NULL", [NOTIF_TYPE]);

echo "Device-offline backlog (type = '" . NOTIF_TYPE . "')\n";
echo "  total rows:           {$total}\n";
echo "  unread:               {$unread}\n";
echo "  not yet soft-deleted: {$undeleted}\n";
echo "  selected mode:        {$mode}" . ($apply ? "  [APPLY]" : "  [dry run]") . "\n";

if (!$apply) {
    echo "\nDRY RUN — nothing changed. Would affect:\n";
    echo "  --mark-read   → set is_read=1 on {$unread} unread row(s)\n";
    echo "  --soft-delete → set deleted_at=NOW() on {$undeleted} row(s)\n";
    echo "\nRe-run with --apply and a mode to execute, e.g.:\n";
    echo "  php " . basename(__FILE__) . " --apply --soft-delete\n";
    exit(0);
}

// ── Apply ────────────────────────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');

if ($mode === 'mark-read') {
    // Only touch still-unread rows so re-runs are no-ops and read_at stays the
    // first time it was cleared.
    $n = db_update(
        'notifications',
        ['is_read' => 1, 'read_at' => $now],
        "type = ? AND is_read = 0",
        [NOTIF_TYPE]
    );
    echo "\nMarked {$n} device-offline notification(s) as read.\n";
} else {
    // Only touch not-yet-deleted rows (idempotent).
    $n = db_update(
        'notifications',
        ['deleted_at' => $now],
        "type = ? AND deleted_at IS NULL",
        [NOTIF_TYPE]
    );
    echo "\nSoft-deleted {$n} device-offline notification(s) (recoverable: set deleted_at = NULL to restore).\n";
}

// ── Post-state confirmation ──────────────────────────────────────────────────
$unreadAfter    = db_count("SELECT COUNT(*) FROM notifications WHERE type = ? AND is_read = 0", [NOTIF_TYPE]);
$undeletedAfter = db_count("SELECT COUNT(*) FROM notifications WHERE type = ? AND deleted_at IS NULL", [NOTIF_TYPE]);
echo "After: unread={$unreadAfter}, not-soft-deleted={$undeletedAfter}.\n";
echo "Done.\n";
