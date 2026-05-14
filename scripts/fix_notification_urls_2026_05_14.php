<?php
declare(strict_types=1);

/**
 * scripts/fix_notification_urls_2026_05_14.php
 *
 * S-NOTIFICATIONS-FULL C2 — One-shot notification-URL remediation.
 *
 * Backfills broken `notifications.url` rows that were created before the
 * S-NOTIFICATION-URL-FIX caller fixes shipped on 2026-05-14. Operator
 * skipped the DB backfill at Option B of that session; this session
 * (S-NOTIFICATIONS-FULL) ran a more thorough audit, found 7 additional
 * broken rows on top of the original 166, and ran the full backfill
 * (173 rows total) in a single transaction.
 *
 * 8 patterns rewritten:
 *
 *   PORTAL FILE-NAME MISMATCH:
 *     /portal/invoices/show?id=N → /portal/invoices/view?id=N      (file is view.php)
 *     /portal/leases/show?id=N   → /portal/leases/view?id=N        (file is view.php)
 *
 *   ADMIN PATH NORMALIZATION:
 *     /equipment/units/show?id=N → /equipment/show?id=N            (no admin units/ dir)
 *     /customers/N (bare ID)     → /customers/show?id=N            (non-router-compliant)
 *     /equipment/N (bare ID)     → /equipment/show?id=N            (non-router-compliant)
 *
 *   MISSING /fleetforge BASE PATH (newly surfaced this session):
 *     /messenger?thread=N           → /fleetforge/messenger?thread=N
 *     /portal/messages?thread=N     → /fleetforge/portal/messages?thread=N
 *     /accounting/collections?…     → /fleetforge/accounting/collections?…   (1 stale row)
 *
 * USAGE:
 *   php scripts/fix_notification_urls_2026_05_14.php --dry-run   (default — no writes)
 *   php scripts/fix_notification_urls_2026_05_14.php --execute   (writes — already ran on 2026-05-14)
 *
 * SAFETY:
 *   - Default mode is --dry-run. Writes nothing, exits 0.
 *   - --execute writes inside a single db_transaction — full rollback on error.
 *   - Idempotent: all 8 patterns are characterised by the WRONG form, which
 *     is replaced with the RIGHT form. Re-running on already-correct rows is
 *     a no-op (the WHERE clauses match zero rows).
 *   - Audit-log row inserted per S-FIX-2 / S-LEASE-21-CLEANUP precedent.
 *
 * SUCCESS CRITERIA:
 *   After --execute, each of the 8 post-condition counts must be zero.
 *   The script verifies and exits non-zero if any pattern still matches.
 *
 * AUTHOR:  S-NOTIFICATIONS-FULL
 * DATE:    2026-05-14
 * SPEC:    STOP 1 findings — see PROGRESS.md S-NOTIFICATIONS-FULL row.
 */

require_once realpath(__DIR__ . '/../config/app.php');

$dryRun = !in_array('--execute', $argv, true);

echo "═══ S-NOTIFICATIONS-FULL C2 — notification URL backfill ═══\n";
echo "Mode: " . ($dryRun ? 'DRY-RUN (no writes)' : 'EXECUTE (writes)') . "\n\n";

// ── Pre-count by pattern ─────────────────────────────────────────────────────
$patterns = [
    'portal_invoices_show' => [
        'where' => ["url LIKE ?", ['%/portal/invoices/show?id=%']],
        'desc'  => '/portal/invoices/show?id=  →  /portal/invoices/view?id=',
    ],
    'portal_leases_show' => [
        'where' => ["url LIKE ?", ['%/portal/leases/show?id=%']],
        'desc'  => '/portal/leases/show?id=    →  /portal/leases/view?id=',
    ],
    'equipment_units_show' => [
        'where' => ["url LIKE ?", ['%/equipment/units/show?id=%']],
        'desc'  => '/equipment/units/show?id=  →  /equipment/show?id=',
    ],
    'bare_customers' => [
        'where' => ["url REGEXP ?", ['/customers/[0-9]+$']],
        'desc'  => '/customers/{ID}            →  /customers/show?id={ID}',
    ],
    'bare_equipment' => [
        'where' => [
            "url REGEXP ? AND url NOT LIKE ? AND url NOT LIKE ?",
            ['/equipment/[0-9]+$', '%/equipment/show%', '%/equipment/units%'],
        ],
        'desc'  => '/equipment/{ID}            →  /equipment/show?id={ID}',
    ],
    'msgr_admin_no_prefix' => [
        'where' => ["url LIKE ?", ['/messenger?thread=%']],
        'desc'  => '/messenger?thread=…        →  /fleetforge prefix added',
    ],
    'msgr_portal_no_prefix' => [
        'where' => ["url LIKE ?", ['/portal/messages?thread=%']],
        'desc'  => '/portal/messages?thread=…  →  /fleetforge prefix added',
    ],
    'stale_accounting' => [
        'where' => ["url LIKE ?", ['/accounting/collections?customer_id=%']],
        'desc'  => '/accounting/collections?…  →  /fleetforge prefix added',
    ],
];

$preCounts = [];
foreach ($patterns as $key => $p) {
    [$sql, $params] = $p['where'];
    $preCounts[$key] = db_count("SELECT COUNT(*) FROM notifications WHERE {$sql}", $params);
}

echo "PRE-BACKFILL counts:\n";
foreach ($patterns as $key => $p) {
    printf("  %-26s  %5d rows    %s\n", $key, $preCounts[$key], $p['desc']);
}
$totalPre = array_sum($preCounts);
printf("  %-26s  %5d\n\n", 'TOTAL', $totalPre);

if ($totalPre === 0) {
    echo "✓ Nothing to backfill — all 8 patterns already clean. Exiting.\n";
    exit(0);
}

if ($dryRun) {
    echo "Dry-run complete. Re-run with --execute to apply.\n";
    exit(0);
}

// ── Execute backfill in a single transaction ─────────────────────────────────
db_transaction(function () use ($preCounts) {
    // Pattern 1 — portal invoices show → view (file is view.php)
    db_execute(
        "UPDATE notifications SET url = REPLACE(url, ?, ?) WHERE url LIKE ?",
        ['/portal/invoices/show?id=', '/portal/invoices/view?id=', '%/portal/invoices/show?id=%']
    );

    // Pattern 2 — portal leases show → view (file is view.php)
    db_execute(
        "UPDATE notifications SET url = REPLACE(url, ?, ?) WHERE url LIKE ?",
        ['/portal/leases/show?id=', '/portal/leases/view?id=', '%/portal/leases/show?id=%']
    );

    // Pattern 3 — admin equipment/units/show → equipment/show (no units/ subdir)
    db_execute(
        "UPDATE notifications SET url = REPLACE(url, ?, ?) WHERE url LIKE ?",
        ['/equipment/units/show?id=', '/equipment/show?id=', '%/equipment/units/show?id=%']
    );

    // Pattern 4 — bare /customers/{ID} → /customers/show?id={ID}
    db_execute(
        "UPDATE notifications
         SET url = CONCAT(
                       SUBSTRING_INDEX(url, '/customers/', 1),
                       '/customers/show?id=',
                       SUBSTRING_INDEX(url, '/customers/', -1)
                   )
         WHERE url REGEXP ?",
        ['/customers/[0-9]+$']
    );

    // Pattern 5 — bare /equipment/{ID} → /equipment/show?id={ID}
    // (NOT LIKE guards exclude already-fixed /equipment/show% and api-path
    //  /equipment/units/% rows for belt-and-suspenders idempotency.)
    db_execute(
        "UPDATE notifications
         SET url = CONCAT(
                       SUBSTRING_INDEX(url, '/equipment/', 1),
                       '/equipment/show?id=',
                       SUBSTRING_INDEX(url, '/equipment/', -1)
                   )
         WHERE url REGEXP ?
           AND url NOT LIKE ?
           AND url NOT LIKE ?",
        ['/equipment/[0-9]+$', '%/equipment/show%', '%/equipment/units%']
    );

    // Patterns 6, 7, 8 — prepend missing /fleetforge base-path prefix.
    db_execute(
        "UPDATE notifications SET url = CONCAT(?, url) WHERE url LIKE ?",
        ['/fleetforge', '/messenger?thread=%']
    );
    db_execute(
        "UPDATE notifications SET url = CONCAT(?, url) WHERE url LIKE ?",
        ['/fleetforge', '/portal/messages?thread=%']
    );
    db_execute(
        "UPDATE notifications SET url = CONCAT(?, url) WHERE url LIKE ?",
        ['/fleetforge', '/accounting/collections?customer_id=%']
    );

    // Audit-log row per S-FIX-2 / S-LEASE-21-CLEANUP precedent.
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'update',
        'module'       => 'notifications',
        'entity_type'  => 'notification_url_backfill',
        'entity_id'    => null,
        'entity_label' => 'S-NOTIFICATIONS-FULL C2',
        'ip_address'   => '127.0.0.1',
        'user_agent'   => 'scripts/fix_notification_urls_2026_05_14.php',
        'notes'        => 'S-NOTIFICATIONS-FULL C2: backfilled '
            . array_sum($preCounts) . ' rows with broken URL patterns. '
            . 'Pre-counts by pattern: ' . json_encode($preCounts),
    ]);
});

// ── Post-count verification (must all be zero) ───────────────────────────────
$postCounts = [];
foreach ($patterns as $key => $p) {
    [$sql, $params] = $p['where'];
    $postCounts[$key] = db_count("SELECT COUNT(*) FROM notifications WHERE {$sql}", $params);
}

echo "\nPOST-BACKFILL counts (must all be 0):\n";
$ok = true;
foreach ($patterns as $key => $p) {
    $v = $postCounts[$key];
    $tick = $v === 0 ? '✓' : '✗ FAIL';
    if ($v !== 0) $ok = false;
    printf("  %-26s  %5d rows  %s\n", $key, $v, $tick);
}
$totalPost = array_sum($postCounts);
printf("  %-26s  %5d  %s\n", 'TOTAL remaining', $totalPost, $totalPost === 0 ? '✓' : '✗ FAIL');

if (!$ok) {
    fwrite(STDERR, "\n✗ FAILED — some patterns still match after backfill. Investigate before re-running.\n");
    exit(1);
}

echo "\n✓ S-NOTIFICATIONS-FULL C2 backfill complete: " . $totalPre . " rows updated.\n";
exit(0);
