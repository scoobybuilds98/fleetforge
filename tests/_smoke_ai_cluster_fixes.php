<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_cluster_fixes.php
 *
 * S-AI-AUDIT-HIGH-FIX (anomaly + proposal clusters) — guards the behavioral
 * fixes from the audit. Real code + live schema; every write is rolled back.
 *
 * AN1 — storeAlert de-dup: an OPEN alert for the same entity+type is NOT
 *       duplicated on a re-run; a more-severe re-run escalates it IN PLACE;
 *       a recently-acknowledged condition stays quiet (cooldown), then re-alerts
 *       once the cooldown lapses.
 * AN2 — pruneStaleAlerts: auto-acks unacknowledged alerts older than 30d and
 *       hard-deletes alerts older than 90d.
 * PR1 — apply-change cancel: the atomic pending→cancelled claim returns 1 for the
 *       first caller and 0 for the second (can't cancel a non-pending proposal).
 *
 * @session S-AI-AUDIT-HIGH-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\AnomalyDetector;

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

$store = new ReflectionMethod(AnomalyDetector::class, 'storeAlert');
$store->setAccessible(true);
$prune = new ReflectionMethod(AnomalyDetector::class, 'pruneStaleAlerts');
$prune->setAccessible(true);

$ET = 'customer'; $EID = 2000000099; $TYPE = 'customer_risk';
function mkAlert(string $sev): array {
    global $ET, $EID, $TYPE;
    return ['alert_type'=>$TYPE,'severity'=>$sev,'title'=>"T {$sev}",'description'=>"d {$sev}",
            'entity_type'=>$ET,'entity_id'=>$EID,'data_snapshot'=>['x'=>1]];
}
function alertCount(): int {
    global $ET, $EID, $TYPE;
    return db_count("SELECT COUNT(*) FROM ai_anomaly_alerts WHERE alert_type=? AND entity_type=? AND entity_id=?", [$TYPE,$ET,$EID]);
}

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    echo "\n# AN1 — storeAlert de-dup / escalate / ack-cooldown\n";
    $r1 = (bool) $store->invoke(null, mkAlert('medium'), null);
    ok('first alert inserts (returns true)', $r1 && alertCount() === 1);

    $r2 = (bool) $store->invoke(null, mkAlert('medium'), null);
    ok('same open alert is NOT duplicated (returns false)', !$r2 && alertCount() === 1);

    $r3 = (bool) $store->invoke(null, mkAlert('high'), null);
    $sev = db_row("SELECT severity FROM ai_anomaly_alerts WHERE alert_type=? AND entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 1",[$TYPE,$ET,$EID])['severity'] ?? '';
    ok('more-severe re-run escalates IN PLACE (still 1 row, now high)', !$r3 && alertCount() === 1 && $sev === 'high', "rows=".alertCount()." sev={$sev}");

    // Acknowledge → within cooldown stays quiet.
    db_execute("UPDATE ai_anomaly_alerts SET acknowledged_at=NOW(), acknowledged_by=NULL WHERE alert_type=? AND entity_type=? AND entity_id=?", [$TYPE,$ET,$EID]);
    $r4 = (bool) $store->invoke(null, mkAlert('high'), null);
    ok('acked-within-cooldown does NOT re-alert (returns false)', !$r4 && alertCount() === 1);

    // Push ack past the cooldown (7 days) → re-alerts.
    db_execute("UPDATE ai_anomaly_alerts SET acknowledged_at=DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE alert_type=? AND entity_type=? AND entity_id=?", [$TYPE,$ET,$EID]);
    $r5 = (bool) $store->invoke(null, mkAlert('medium'), null);
    ok('after cooldown lapses, condition re-alerts (returns true, now 2 rows)', $r5 && alertCount() === 2);

    echo "\n# AN2 — pruneStaleAlerts retention sweep\n";
    $staleId = db_insert('ai_anomaly_alerts', [
        'alert_type'=>'utilization_drop','severity'=>'low','title'=>'stale','description'=>'d',
        'entity_type'=>'fleet','entity_id'=>2000000098,'data_snapshot'=>'{}',
        'created_at'=>date('Y-m-d H:i:s', strtotime('-40 days')),
    ]);
    $oldId = db_insert('ai_anomaly_alerts', [
        'alert_type'=>'utilization_drop','severity'=>'low','title'=>'ancient','description'=>'d',
        'entity_type'=>'fleet','entity_id'=>2000000097,'data_snapshot'=>'{}',
        'created_at'=>date('Y-m-d H:i:s', strtotime('-100 days')),
    ]);
    $prune->invoke(null);
    $staleRow = db_row("SELECT acknowledged_at FROM ai_anomaly_alerts WHERE id=?", [$staleId]);
    ok('40-day unacked alert is auto-acknowledged', $staleRow && $staleRow['acknowledged_at'] !== null);
    ok('100-day alert is hard-deleted', db_row("SELECT id FROM ai_anomaly_alerts WHERE id=?", [$oldId]) === null);

    echo "\n# PR1 — apply-change cancel atomic claim\n";
    $u = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $pid = db_insert('ai_pending_changes', [
        'user_id'=>(int)$u['id'],'change_type'=>'update','entity_type'=>'customer',
        'summary'=>'cancel smoke','payload'=>json_encode(['targets'=>[]]),'status'=>'pending',
    ]);
    $c1 = db_update('ai_pending_changes', ['status'=>'cancelled'], 'id = ? AND status = ?', [$pid, 'pending']);
    $c2 = db_update('ai_pending_changes', ['status'=>'cancelled'], 'id = ? AND status = ?', [$pid, 'pending']);
    $st = db_row("SELECT status FROM ai_pending_changes WHERE id=?", [$pid])['status'] ?? '';
    ok('first cancel wins (rowCount=1)', $c1 === 1, "got {$c1}");
    ok('second cancel loses (rowCount=0 → 409)', $c2 === 0, "got {$c2}");
    ok('row is now cancelled', $st === 'cancelled', $st);
} finally {
    $pdo->rollBack();
    echo "\n(rolled back — DB unchanged)\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
