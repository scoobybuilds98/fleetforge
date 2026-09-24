<?php
declare(strict_types=1);

/**
 * scripts/notifications_switchover.php
 *
 * S-ATTENTION-INBOX one-time switch-over. Staff start at the handful of real
 * open problems instead of thousands of unread notifications (production:
 * ~7,000 unread per manager, 75,857 staff rows since June).
 *
 * What it does (staff rows only — portal notifications are never touched):
 *   1. Rows whose TYPE now belongs to Needs attention (compliance repeats,
 *      overdue invoices, risk / collections, counter drift, customer
 *      requests, QuickBooks alerts…) are ARCHIVED: soft-deleted
 *      (deleted_at). They're superseded by one item per problem and stay in
 *      the table for history.
 *   2. Every other unread staff row (the activity feed) is marked READ, so
 *      Updates starts clean but keeps its history.
 *   3. Runs the full Needs attention sweep once, so the list is built from
 *      what is actually open right now (the hourly cron keeps it current).
 *   4. Writes the settings marker notifications.switchover_at — a second
 *      run refuses unless --force.
 *
 * HOW TO RUN (operator; after the S-ATTENTION-INBOX deploy + migration):
 *   Dry run (default — writes nothing, prints what would change):
 *     sudo -u www-data php scripts/notifications_switchover.php
 *   Apply:
 *     sudo -u www-data php scripts/notifications_switchover.php --apply
 *
 * Batched (5,000 rows per UPDATE) so a large table never holds a long lock.
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Attention\AttentionService;
use FleetForge\Attention\NotificationRouter;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);

$marker = settings_get('notifications.switchover_at', null);
if ($apply && $marker !== null && !$force) {
    fwrite(STDERR, "Already switched over at {$marker} (UTC). Re-run with --force only if you mean it.\n");
    exit(1);
}

// ── Classify every staff notification type ─────────────────────────────────
$types = db_select(
    "SELECT COALESCE(type, '') AS type,
            COUNT(*) AS n,
            SUM(is_read = 0) AS unread
       FROM notifications
      WHERE user_id IS NOT NULL AND deleted_at IS NULL
      GROUP BY COALESCE(type, '')
      ORDER BY n DESC"
);

$archiveTypes = [];
$archiveRows  = 0;
$readRows     = 0;
printf("%-45s %9s %9s  %s\n", 'type', 'rows', 'unread', 'switch-over');
foreach ($types as $t) {
    $kind = $t['type'] !== '' ? NotificationRouter::attentionKindFor((string) $t['type']) : null;
    if ($kind !== null) {
        $archiveTypes[] = (string) $t['type'];
        $archiveRows   += (int) $t['n'];
        $action = 'archive → Needs attention (' . $kind . ')';
    } else {
        $readRows += (int) $t['unread'];
        $action = (int) $t['unread'] > 0 ? 'mark read (stays in Updates)' : 'unchanged';
    }
    printf("%-45s %9d %9d  %s\n", $t['type'] === '' ? '(no type)' : $t['type'], $t['n'], $t['unread'], $action);
}
echo "\n";
printf("Would archive %d row(s) of %d Needs attention type(s); mark %d unread update(s) read.\n",
    $archiveRows, count($archiveTypes), $readRows);

if (!$apply) {
    echo "\nDry run — nothing written. Re-run with --apply.\n";
    exit(0);
}

$now = ff_now_utc();

// ── 1. Archive superseded rows (batched) ─────────────────────────────────────
$archived = 0;
foreach (array_chunk($archiveTypes, 50) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    do {
        $n = db_execute(
            "UPDATE notifications SET deleted_at = ?
              WHERE user_id IS NOT NULL AND deleted_at IS NULL AND type IN ($ph)
              LIMIT 5000",
            array_merge([$now], $chunk)
        );
        $archived += $n;
    } while ($n === 5000);
}

// ── 2. Mark the remaining unread updates read (batched) ──────────────────────
$marked = 0;
do {
    $n = db_execute(
        "UPDATE notifications SET is_read = 1, read_at = ?
          WHERE user_id IS NOT NULL AND deleted_at IS NULL AND is_read = 0
          LIMIT 5000",
        [$now]
    );
    $marked += $n;
} while ($n === 5000);

// ── 3. Build the list from what's open now ───────────────────────────────────
$stats = AttentionService::sweepAll();
$open  = (int) db_row("SELECT COUNT(*) AS c FROM attention_items WHERE status IN ('open','snoozed')")['c'];

// ── 4. Marker + audit ────────────────────────────────────────────────────────
db_execute(
    "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description)
     VALUES ('notifications.switchover_at', ?, 'string', 'attention', 'Notifications switch-over',
             'When scripts/notifications_switchover.php archived the old notifications (UTC).')
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [$now]
);
$notes = "Notifications switch-over: archived {$archived} superseded row(s), marked {$marked} update(s) read; "
       . "Needs attention now has {$open} open item(s)"
       . ($stats['errors'] ? ' — sweep errors: ' . implode(' | ', $stats['errors']) : '') . '.';
db_insert('audit_log', [
    'user_id'      => null,
    'user_name'    => 'system',
    'action'       => 'update',
    'module'       => 'notifications',
    'entity_type'  => 'script',
    'entity_id'    => null,
    'entity_label' => 'notifications_switchover',
    'notes'        => $notes,
    'ip_address'   => '127.0.0.1',
]);

echo $notes, "\n";
exit($stats['errors'] ? 1 : 0);
