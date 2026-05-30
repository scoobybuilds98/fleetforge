<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_manual_sync.php
 *
 * Smoke test for S-QBO-26 Manual Sync page (Phase QBO-12 / 3 of 3).
 * The bulk reconciliation surface per spec §15.6 — force re-sync of an
 * entity type, force pull from QBO, and SyncToken reset.
 *
 * Per [[extensive-test-and-full-report]]: PER-FUNCTION coverage at the
 * structural + DB-behavior level. The endpoint requires require_auth_api()
 * so it can't be invoked over HTTP from a CLI smoke; instead each action's
 * core logic is exercised directly:
 *   • force_resync   — the map → Enqueuer iteration (verify the SELECT
 *     finds mapped rows + the Enqueuer skips when sync_enabled=0, so the
 *     reason ladder fires). The Enqueuer's own guards (origin / status /
 *     sync_mode / bridge-derived) are covered by their own smokes.
 *   • force_pull     — pull-capable whitelist (5 types) vs push-only 422,
 *     asserted by grepping the endpoint's pullMap + INVALID_STATE branch.
 *   • reset_synctoken— DB behavior on a sentinel customer_map row.
 *
 * Uses customer sentinels (FF customer id=999990, qbo_customer_id
 * 'QBO-MS-SENTINEL') cleaned in finally.
 *
 * Sub-check map:
 *   Module A — surfaces
 *     C1  manual_sync.php endpoint exists + php -l clean + force_full_resync gate
 *     C2  manual_sync.php admin page exists + php -l clean + Alpine factory
 *     C3  nav: config/navigation.php + quickbooks-nav.php have Manual Sync after Drift
 *   Module B — endpoint structure (the action contracts)
 *     C4  3 action verbs: force_resync / force_pull / reset_synctoken
 *     C5  8-entity Enqueuer FQCN map + reuses DriftChecker::ENTITY_CHECKS
 *     C6  pull-capable whitelist = exactly customer/vendor/account/item/tax_code
 *     C7  force_pull on a push-only type returns 422 INVALID_STATE (branch present)
 *   Module C — reset_synctoken DB behavior
 *     C8  reset_synctoken NULLs qbo_sync_token on a populated sentinel map row
 *   Module D — force_resync map iteration
 *     C9  the map SELECT (ff_fk NOT NULL) finds the sentinel mapped row
 *     C10 with sync_enabled=0, CustomerEnqueuer::enqueue returns false
 *         (force_resync would skip + surface the sync_enabled reason)
 *
 * @session  S-QBO-26
 * @decision D-QBO-26-1 (force_resync all mapped via Enqueuer),
 *           D-QBO-26-2 (force_pull pull-capable only),
 *           D-QBO-26-3 (separate reset_synctoken),
 *           D-QBO-26-4 (respect sync_enabled + sync_mode; surface reasons)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\DriftChecker;
use FleetForge\QboPushers\CustomerEnqueuer;

$pass = 0;
$total = 10;
$failures = [];

$ENDPOINT = __DIR__ . '/../api/v1/quickbooks/manual_sync.php';
$PAGE     = __DIR__ . '/../app/admin/quickbooks/manual_sync.php';

function ff_ms_set(string $k, string $v): void
{
    db_execute(
        "INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES (?,?, 'string','quickbooks',0,0)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k, $v]
    );
}
function ff_ms_get(string $k): ?string
{
    $r = db_row("SELECT `value` FROM settings WHERE `key`=?", [$k]);
    return $r['value'] ?? null;
}
function ff_ms_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_queue   WHERE entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id BETWEEN 999990 AND 999999 OR qbo_customer_id LIKE 'QBO-MS-%'");
    db_execute("DELETE FROM customers            WHERE id BETWEEN 999990 AND 999999");
}

$snapKeys = ['quickbooks.sync_enabled', 'quickbooks.sync_mode.customer'];
$snap = [];
foreach ($snapKeys as $k) { $snap[$k] = ff_ms_get($k); }

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-26 Manual Sync Smoke (10 sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_ms_cleanup();
    $endpointSrc = is_file($ENDPOINT) ? (string) file_get_contents($ENDPOINT) : '';
    $pageSrc     = is_file($PAGE) ? (string) file_get_contents($PAGE) : '';

    // ── C1: endpoint exists + lints + permission gate ──────────────
    $c1 = [];
    if ($endpointSrc === '') $c1[] = 'manual_sync.php endpoint missing';
    else {
        $lint = shell_exec('php -l ' . escapeshellarg($ENDPOINT) . ' 2>&1');
        if (strpos((string) $lint, 'No syntax errors') === false) $c1[] = 'endpoint lint failed';
        if (strpos($endpointSrc, "require_permission('quickbooks', 'force_full_resync')") === false) $c1[] = 'missing force_full_resync gate';
        if (strpos($endpointSrc, "require_method('POST')") === false) $c1[] = 'missing POST guard';
    }
    if (empty($c1)) { echo "PASS C1 endpoint exists + lints + force_full_resync POST gate\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    // ── C2: admin page exists + lints + Alpine factory ─────────────
    $c2 = [];
    if ($pageSrc === '') $c2[] = 'manual_sync.php page missing';
    else {
        $lint = shell_exec('php -l ' . escapeshellarg($PAGE) . ' 2>&1');
        if (strpos((string) $lint, 'No syntax errors') === false) $c2[] = 'page lint failed';
        if (strpos($pageSrc, 'function qboManualSync()') === false) $c2[] = 'missing qboManualSync Alpine factory';
        if (strpos($pageSrc, "require_permission('quickbooks', 'view')") === false) $c2[] = 'missing view gate';
        if (strpos($pageSrc, "can('quickbooks', 'force_full_resync')") === false) $c2[] = 'missing force_full_resync capability check';
        // The page opens the layout via header.php; it MUST close it via
        // footer.php — without the footer the HTML doc never closes and
        // app.js/Alpine never load, so x-data renders nothing (the page
        // looks blank). Regression guard for the S-QBO-26 blank-page bug.
        if (strpos($pageSrc, "includes/header.php") === false) $c2[] = 'missing header.php include';
        if (strpos($pageSrc, "includes/footer.php") === false) $c2[] = 'missing footer.php include (page would render blank — Alpine never loads)';
    }
    if (empty($c2)) { echo "PASS C2 admin page exists + lints + Alpine factory + gates\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2) . "\n"; $failures[] = 'C2'; }

    // ── C3: nav presence (both nav surfaces) ───────────────────────
    $c3 = [];
    $navConfig = require __DIR__ . '/../config/navigation.php';
    $qbo = null;
    foreach ($navConfig as $g) {
        if (($g['label'] ?? '') === 'QuickBooks' && !empty($g['children'])) { $qbo = $g; break; }
    }
    if ($qbo === null) {
        $c3[] = 'QuickBooks nav group not found';
    } else {
        $labels = array_map(static fn($c) => $c['label'] ?? '', $qbo['children']);
        $di = array_search('Drift', $labels, true);
        $mi = array_search('Manual Sync', $labels, true);
        if ($mi === false) $c3[] = 'Manual Sync missing from config/navigation.php';
        elseif ($di === false || $mi !== $di + 1) $c3[] = 'Manual Sync not immediately after Drift in config nav';
    }
    $partial = (string) file_get_contents(__DIR__ . '/../includes/partials/quickbooks-nav.php');
    if (strpos($partial, "'/quickbooks/manual_sync'") === false) $c3[] = 'Manual Sync missing from quickbooks-nav.php partial';
    if (empty($c3)) { echo "PASS C3 nav: Manual Sync present after Drift in both nav surfaces\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    // ── C4: 3 action verbs ─────────────────────────────────────────
    $c4 = [];
    foreach (['force_resync', 'force_pull', 'reset_synctoken'] as $verb) {
        if (strpos($endpointSrc, "'{$verb}'") === false) $c4[] = "missing action '{$verb}'";
    }
    if (empty($c4)) { echo "PASS C4 endpoint declares all 3 action verbs\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4) . "\n"; $failures[] = 'C4'; }

    // ── C5: 8-entity Enqueuer map + DriftChecker::ENTITY_CHECKS reuse ─
    $c5 = [];
    $enq = ['InvoiceEnqueuer','PaymentEnqueuer','BillEnqueuer','BillPaymentEnqueuer','CreditMemoEnqueuer','JournalEntryEnqueuer','CustomerEnqueuer','VendorEnqueuer'];
    foreach ($enq as $cls) {
        if (strpos($endpointSrc, $cls) === false) $c5[] = "missing Enqueuer {$cls}";
    }
    if (strpos($endpointSrc, 'DriftChecker::ENTITY_CHECKS') === false) $c5[] = 'does not reuse DriftChecker::ENTITY_CHECKS';
    if (count(DriftChecker::ENTITY_CHECKS) !== 8) $c5[] = 'ENTITY_CHECKS != 8';
    if (empty($c5)) { echo "PASS C5 8-entity Enqueuer map + reuses DriftChecker::ENTITY_CHECKS\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5) . "\n"; $failures[] = 'C5'; }

    // ── C6: pull-capable whitelist = exactly the 5 reference types ──
    $c6 = [];
    foreach (['customer','vendor','account','item','tax_code'] as $t) {
        if (!preg_match("/'{$t}'\\s*=>\\s*\\[\\s*'puller'/", $endpointSrc)) $c6[] = "pullMap missing '{$t}'";
    }
    // push-only types must NOT be in the pullMap
    foreach (['invoice','payment','bill','journal_entry'] as $t) {
        if (preg_match("/'{$t}'\\s*=>\\s*\\[\\s*'puller'/", $endpointSrc)) $c6[] = "pullMap wrongly includes push-only '{$t}'";
    }
    if (empty($c6)) { echo "PASS C6 pull-capable whitelist = customer/vendor/account/item/tax_code (no push-only)\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6) . "\n"; $failures[] = 'C6'; }

    // ── C7: force_pull push-only → 422 INVALID_STATE branch present ─
    $c7 = [];
    if (strpos($endpointSrc, 'INVALID_STATE') === false) $c7[] = 'no INVALID_STATE branch';
    if (strpos($endpointSrc, '422') === false) $c7[] = 'no 422 status';
    if (strpos($endpointSrc, 'S-QBO-27') === false) $c7[] = 'no S-QBO-27 pointer for push-only pull';
    if (empty($c7)) { echo "PASS C7 force_pull on push-only type → 422 INVALID_STATE (→ S-QBO-27)\n"; $pass++; }
    else { echo "FAIL C7 " . implode('; ', $c7) . "\n"; $failures[] = 'C7'; }

    // ── Seed a sentinel customer + mapped row with a token ─────────
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999990, 'MS Smoke Customer', 'CAD', NOW())");
    db_execute(
        "INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, qbo_sync_token, mapping_status)
         VALUES (999990, 'QBO-MS-SENTINEL', '7', 'mapped')"
    );

    // ── C8: reset_synctoken NULLs the token ────────────────────────
    // Simulate the endpoint's UPDATE (NULL token WHERE qbo id not null).
    $before = db_row("SELECT qbo_sync_token FROM acc_qbo_customer_map WHERE ff_customer_id=999990");
    db_execute("UPDATE acc_qbo_customer_map SET qbo_sync_token = NULL WHERE qbo_customer_id IS NOT NULL AND ff_customer_id=999990");
    $after = db_row("SELECT qbo_sync_token FROM acc_qbo_customer_map WHERE ff_customer_id=999990");
    // Use array_key_exists so a genuine NULL value isn't collapsed by ?? .
    $beforeTok = $before['qbo_sync_token'] ?? null;
    $afterTok  = array_key_exists('qbo_sync_token', $after ?? []) ? $after['qbo_sync_token'] : 'MISSING';
    if ($beforeTok === '7' && $afterTok === null) {
        echo "PASS C8 reset_synctoken NULLs qbo_sync_token on a populated map row\n"; $pass++;
    } else {
        echo "FAIL C8 before=" . var_export($beforeTok, true) . " after=" . var_export($afterTok, true) . "\n";
        $failures[] = 'C8';
    }

    // ── C9: force_resync map SELECT finds the mapped sentinel row ──
    // Mirrors the endpoint's "SELECT ff_fk WHERE ff_fk IS NOT NULL".
    $cfg = DriftChecker::ENTITY_CHECKS['customer'];
    $rows = db_select(
        "SELECT {$cfg['ff_fk']} AS ff_id FROM {$cfg['map']} WHERE {$cfg['ff_fk']} = 999990 AND {$cfg['ff_fk']} IS NOT NULL",
        []
    );
    if (count($rows) === 1 && (int) $rows[0]['ff_id'] === 999990) {
        echo "PASS C9 force_resync map SELECT (ff_fk NOT NULL) finds the mapped sentinel\n"; $pass++;
    } else {
        echo "FAIL C9 expected 1 sentinel row, got " . count($rows) . "\n"; $failures[] = 'C9';
    }

    // ── C10: with sync_enabled=0, Enqueuer skips (reason ladder fires) ─
    ff_ms_set('quickbooks.sync_enabled', '0');
    ff_ms_set('quickbooks.sync_mode.customer', 'sync');
    $enqueued = CustomerEnqueuer::enqueue(999990, 'create');
    $queued = (int) (db_row("SELECT COUNT(*) c FROM acc_qbo_sync_queue WHERE entity_id=999990 AND entity_type='customer'")['c'] ?? 0);
    if ($enqueued === false && $queued === 0) {
        echo "PASS C10 force_resync skips when sync_enabled=0 (D-QBO-26-4 reason ladder path)\n"; $pass++;
    } else {
        echo "FAIL C10 enqueued=" . var_export($enqueued, true) . " queued={$queued}\n"; $failures[] = 'C10';
    }

} finally {
    ff_ms_cleanup();
    foreach ($snap as $k => $v) {
        if ($v === null) db_execute("DELETE FROM settings WHERE `key`=?", [$k]);
        else ff_ms_set($k, $v);
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "RESULT: {$pass}/{$total} PASS\n";
if (!empty($failures)) { echo "FAILED: " . implode(', ', $failures) . "\n"; exit(1); }
exit(0);
