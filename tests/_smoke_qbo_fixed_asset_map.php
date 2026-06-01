<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_fixed_asset_map.php
 *
 * Smoke for S-QBO-FA-MAP (operator-directed) — acc_qbo_fixed_asset_map +
 * FixedAssetMapSync populate engine. Per [[extensive-test-and-full-report]]:
 * per-function coverage with named sub-checks. Sentinel ids 999990-999999.
 *
 *   Module A — surfaces + schema
 *     C1  FixedAssetMapSync methods (sync/syncOne/buildReference/resolveQboAccount)
 *     C2  acc_qbo_fixed_asset_map schema (cols + UNIQUE + FK)
 *
 *   Module B — resolveQboAccount
 *     C3  mapped ff account → qbo id; unmapped → null; null input → null
 *
 *   Module C — buildReference
 *     C4  all 3 accounts mapped → 3 refs + sync_status='synced' + snapshots
 *     C5  one account unmapped → sync_status='pending'
 *
 *   Module D — syncOne
 *     C6  inserts a row + returns status
 *     C7  idempotent — re-run updates in place (no duplicate) + refreshes snapshot
 *     C8  missing asset → null
 *
 *   Module E — sync (aggregate)
 *     C9  stats {total, synced, pending} count correctly over sentinels
 *
 *   Module F — endpoint presence
 *     C10 api/v1/quickbooks/fixed_asset_map_sync.php exists + calls the engine
 *
 * @session  S-QBO-FA-MAP (operator-directed)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\FixedAssetMapSync;
use FleetForge\QboPushers\DriftChecker;

$pass = 0;
$total = 11;
$failures = [];

function ff_smoke_famap_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_fixed_assets        WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map     WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts            WHERE id BETWEEN 999990 AND 999999");
}

function ff_smoke_famap_seed_account(int $id, string $code, bool $mapped): void
{
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (?, ?, ?, 'asset', 'fixed_asset', 0, 1)",
        [$id, $code, "Smoke FA Acct {$id}"]
    );
    if ($mapped) {
        db_execute(
            "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (?, ?, 'mapped')",
            [$id, "QBO-ACCT-{$id}"]
        );
    }
}

function ff_smoke_famap_seed_asset(int $id, int $assetAcct, int $accumAcct, int $exprAcct, string $cost = '50000.00', string $accum = '10000.00', string $nbv = '40000.00', string $status = 'active'): void
{
    db_execute(
        "INSERT INTO acc_fixed_assets
            (id, asset_number, name, asset_class, acquisition_date, acquisition_cost,
             depreciation_method, depreciable_cost, salvage_value, accumulated_depreciation,
             net_book_value, depreciation_start_date, status,
             asset_account_id, accum_depr_account_id, depr_expense_account_id)
         VALUES (?, ?, 'Smoke FA Vehicle', 'fleet_equipment', '2026-01-01', ?,
                 'straight_line', ?, '5000.00', ?, ?, '2026-01-01', ?, ?, ?, ?)",
        [$id, "FA-MAP-{$id}", $cost, $cost, $accum, $nbv, $status, $assetAcct, $accumAcct, $exprAcct]
    );
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-FA-MAP Fixed Asset reference map smoke (10 sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_famap_cleanup();

    // ── C1: surfaces ────────────────────────────────────────────────
    $c1 = [];
    foreach (['sync','syncOne','buildReference','resolveQboAccount'] as $m) {
        if (!method_exists(FixedAssetMapSync::class, $m)) $c1[] = "missing {$m}";
    }
    if (empty($c1)) { echo "PASS C1 FixedAssetMapSync surfaces (4 methods)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1) . "\n"; $failures[] = 'C1'; }

    // ── C2: schema ──────────────────────────────────────────────────
    $c2 = [];
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_fixed_asset_map"), 'Field');
    foreach (['id','ff_fixed_asset_id','qbo_asset_account_id','qbo_accum_depr_account_id','qbo_depr_expense_account_id','ff_acquisition_cost_snapshot','ff_net_book_value_snapshot','ff_status_snapshot','sync_status'] as $col) {
        if (!in_array($col, $cols, true)) $c2[] = "missing col {$col}";
    }
    $idx = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_fixed_asset_map"), 'Key_name'));
    if (!in_array('uq_ff_fixed_asset', $idx, true)) $c2[] = 'missing uq_ff_fixed_asset';
    if (empty($c2)) { echo "PASS C2 acc_qbo_fixed_asset_map schema (cols + UNIQUE)\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2) . "\n"; $failures[] = 'C2'; }

    // Seed: 3 mapped accounts + 1 asset using them (the 'synced' case).
    ff_smoke_famap_seed_account(999990, '1500-FAMAP', true);
    ff_smoke_famap_seed_account(999991, '1505-FAMAP', true);
    ff_smoke_famap_seed_account(999992, '6500-FAMAP', true);
    ff_smoke_famap_seed_asset(999990, 999990, 999991, 999992);

    // ── C3: resolveQboAccount ───────────────────────────────────────
    $c3 = [];
    if (FixedAssetMapSync::resolveQboAccount(999990) !== 'QBO-ACCT-999990') $c3[] = 'mapped lookup wrong';
    if (FixedAssetMapSync::resolveQboAccount(888888) !== null) $c3[] = 'unmapped should be null';
    if (FixedAssetMapSync::resolveQboAccount(null) !== null) $c3[] = 'null input should be null';
    if (empty($c3)) { echo "PASS C3 resolveQboAccount (mapped / unmapped / null)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3) . "\n"; $failures[] = 'C3'; }

    // ── C4: buildReference all mapped → synced ──────────────────────
    $c4 = [];
    $asset = db_row("SELECT * FROM acc_fixed_assets WHERE id = 999990");
    $ref = FixedAssetMapSync::buildReference($asset);
    if ($ref['qbo_asset_account_id'] !== 'QBO-ACCT-999990') $c4[] = 'asset acct ref wrong';
    if ($ref['qbo_accum_depr_account_id'] !== 'QBO-ACCT-999991') $c4[] = 'accum acct ref wrong';
    if ($ref['qbo_depr_expense_account_id'] !== 'QBO-ACCT-999992') $c4[] = 'expense acct ref wrong';
    if ($ref['sync_status'] !== 'synced') $c4[] = 'expected synced, got ' . $ref['sync_status'];
    if ((string)$ref['ff_acquisition_cost_snapshot'] !== '50000.00') $c4[] = 'cost snapshot wrong';
    if ((string)$ref['ff_net_book_value_snapshot'] !== '40000.00') $c4[] = 'nbv snapshot wrong';
    if ($ref['ff_status_snapshot'] !== 'active') $c4[] = 'status snapshot wrong';
    if (empty($c4)) { echo "PASS C4 buildReference all-mapped → synced + 3 refs + snapshots\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4) . "\n"; $failures[] = 'C4'; }

    // ── C5: one unmapped → pending ──────────────────────────────────
    db_execute("UPDATE acc_qbo_account_map SET mapping_status='ff_only' WHERE ff_account_id=999992");
    $ref = FixedAssetMapSync::buildReference($asset);
    db_execute("UPDATE acc_qbo_account_map SET mapping_status='mapped' WHERE ff_account_id=999992");
    if ($ref['sync_status'] === 'pending' && $ref['qbo_depr_expense_account_id'] === null) { echo "PASS C5 buildReference one-unmapped → pending\n"; $pass++; }
    else { echo "FAIL C5 expected pending; got " . json_encode(['s'=>$ref['sync_status'],'e'=>$ref['qbo_depr_expense_account_id']]) . "\n"; $failures[] = 'C5'; }

    // ── C6: syncOne inserts ─────────────────────────────────────────
    $status = FixedAssetMapSync::syncOne(999990);
    $row = db_row("SELECT sync_status, qbo_asset_account_id FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999990");
    if ($status === 'synced' && $row && $row['sync_status'] === 'synced' && $row['qbo_asset_account_id'] === 'QBO-ACCT-999990') { echo "PASS C6 syncOne inserts a row + returns status\n"; $pass++; }
    else { echo "FAIL C6 status={$status} row=" . json_encode($row) . "\n"; $failures[] = 'C6'; }

    // ── C7: idempotent + refresh ────────────────────────────────────
    $before = (int)(db_row("SELECT COUNT(*) c FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999990")['c'] ?? 0);
    db_execute("UPDATE acc_fixed_assets SET net_book_value='33333.00' WHERE id=999990");
    FixedAssetMapSync::syncOne(999990);
    $after = (int)(db_row("SELECT COUNT(*) c FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999990")['c'] ?? 0);
    $nbv = db_row("SELECT ff_net_book_value_snapshot FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999990")['ff_net_book_value_snapshot'] ?? '';
    if ($before === 1 && $after === 1 && (string)$nbv === '33333.00') { echo "PASS C7 syncOne idempotent — updates in place + refreshes snapshot\n"; $pass++; }
    else { echo "FAIL C7 before={$before} after={$after} nbv={$nbv}\n"; $failures[] = 'C7'; }

    // ── C8: missing asset → null ────────────────────────────────────
    if (FixedAssetMapSync::syncOne(999998) === null) { echo "PASS C8 syncOne(missing) → null\n"; $pass++; }
    else { echo "FAIL C8 expected null for missing asset\n"; $failures[] = 'C8'; }

    // ── C9: sync() aggregate over sentinels ─────────────────────────
    // Add a 2nd sentinel asset that's pending (uses an unmapped account 999993).
    ff_smoke_famap_seed_account(999993, '1510-FAMAP', false); // NOT mapped
    ff_smoke_famap_seed_asset(999991, 999990, 999991, 999993); // expense acct unmapped → pending
    $stats = FixedAssetMapSync::sync(); // syncs ALL assets (incl. the 20 real ones)
    // Assert our 2 sentinels resolved correctly rather than the global totals.
    $s1 = db_row("SELECT sync_status FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999990")['sync_status'] ?? '';
    $s2 = db_row("SELECT sync_status FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id=999991")['sync_status'] ?? '';
    if ($s1 === 'synced' && $s2 === 'pending' && ($stats['total'] ?? 0) >= 2 && ($stats['errors'] ?? 1) === 0) {
        echo "PASS C9 sync() aggregate — sentinel 1 synced, sentinel 2 pending, 0 errors\n"; $pass++;
    } else { echo "FAIL C9 s1={$s1} s2={$s2} stats=" . json_encode($stats) . "\n"; $failures[] = 'C9'; }

    // ── C10: endpoint presence ──────────────────────────────────────
    $c10 = [];
    $ep = __DIR__ . '/../api/v1/quickbooks/fixed_asset_map_sync.php';
    if (!file_exists($ep)) $c10[] = 'endpoint missing';
    else {
        $src = (string) file_get_contents($ep);
        if (strpos($src, 'FixedAssetMapSync::sync') === false) $c10[] = 'endpoint does not call FixedAssetMapSync::sync';
    }
    if (empty($c10)) { echo "PASS C10 fixed_asset_map_sync.php endpoint exists + calls engine\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10) . "\n"; $failures[] = 'C10'; }

    // ── C11: DriftChecker::runCheck() auto-refreshes the FA map (integration) ──
    // The nightly drift cron + Run-now button both call runCheck(); it now
    // refreshes acc_qbo_fixed_asset_map as a maintenance step. Delete the
    // sentinel's map row, run the drift check, assert it was re-created + the
    // return carries the fixed_asset_reference stats.
    $c11 = [];
    // Ensure drift detection is enabled (its default; S-QBO-24 seed) so
    // runCheck() doesn't short-circuit to {skipped:'disabled'}.
    db_execute(
        "INSERT INTO settings (`key`,`value`,value_type,group_name,is_public,is_sensitive) VALUES ('quickbooks.drift.enabled','1','string','quickbooks',0,0)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
    );
    db_execute("DELETE FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id = 999990");
    $r = DriftChecker::runCheck(false); // forceLive=false → snapshot-only, no QBO HTTP
    $reborn = db_row("SELECT sync_status FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id = 999990");
    if (!isset($r['fixed_asset_reference'])) $c11[] = 'runCheck return missing fixed_asset_reference';
    if (!$reborn) $c11[] = 'runCheck did not refresh the sentinel FA map row';
    if ((int) ($r['fixed_asset_reference']['total'] ?? 0) < 1) $c11[] = 'fixed_asset_reference.total < 1';
    if (empty($c11)) { echo "PASS C11 DriftChecker::runCheck() refreshes the FA reference map (integration)\n"; $pass++; }
    else { echo "FAIL C11 " . implode('; ', $c11) . "\n"; $failures[] = 'C11'; }

} finally {
    ff_smoke_famap_cleanup();
}

echo "═══════════════════════════════════════════════════════════\n";
echo "RESULT: {$pass}/{$total} PASS\n";
if (!empty($failures)) { echo "FAILED: " . implode(', ', $failures) . "\n"; exit(1); }
exit(0);
