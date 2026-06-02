<?php
declare(strict_types=1);

/**
 * scripts/fix_notification_urls_2026_06_02.php
 *
 * One-shot backfill: prepend missing /fleetforge prefix to any
 * notification.url that starts with /quickbooks/ or /sync_log/.
 *
 * Root cause: DriftChecker::dispatchNotification() and
 * qbo_sync_worker::dispatchFailureNotification() used bare paths
 * (/quickbooks/drift, /quickbooks/sync_log?queue_id=…) before the
 * 2026-06-02 source fix that switched them to base_url().
 *
 * USAGE:
 *   php scripts/fix_notification_urls_2026_06_02.php            (dry-run)
 *   php scripts/fix_notification_urls_2026_06_02.php --execute  (writes)
 */

require_once realpath(__DIR__ . '/../config/app.php');

$dryRun = !in_array('--execute', $argv, true);
echo 'Mode: ' . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n\n";

$patterns = [
    ['url LIKE ?', ['/quickbooks/%'], 'bare /quickbooks/* → /fleetforge/quickbooks/*'],
    ['url LIKE ?', ['/sync_log%'],    'bare /sync_log*    → /fleetforge/sync_log*'],
];

$total = 0;
foreach ($patterns as [$where, $params, $desc]) {
    $n = db_count("SELECT COUNT(*) FROM notifications WHERE $where", $params);
    printf("  %5d rows — %s\n", $n, $desc);
    $total += $n;
}
printf("\n  %5d total rows affected\n\n", $total);

if ($total === 0) {
    echo "Nothing to fix.\n";
    exit(0);
}

if ($dryRun) {
    echo "Dry-run done. Re-run with --execute to apply.\n";
    exit(0);
}

db_transaction(function () use ($patterns) {
    foreach ($patterns as [$where, $params, $_]) {
        db_execute(
            "UPDATE notifications SET url = CONCAT(?, url) WHERE $where",
            array_merge(['/fleetforge'], $params)
        );
    }

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'update',
        'module'       => 'notifications',
        'entity_type'  => 'notification_url_backfill',
        'entity_id'    => null,
        'entity_label' => '2026-06-02 qbo url prefix fix',
        'ip_address'   => '127.0.0.1',
        'notes'        => 'Prepended /fleetforge to bare /quickbooks/* and /sync_log* notification URLs.',
    ]);
});

// Verify
$remaining = 0;
foreach ($patterns as [$where, $params, $desc]) {
    $n = db_count("SELECT COUNT(*) FROM notifications WHERE $where", $params);
    printf("  POST: %d rows still matching — %s\n", $n, $desc);
    $remaining += $n;
}

if ($remaining > 0) {
    fwrite(STDERR, "\n✗ FAILED — $remaining rows still broken.\n");
    exit(1);
}

echo "\n✓ Done.\n";
exit(0);
