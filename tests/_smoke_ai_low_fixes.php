<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_low_fixes.php
 *
 * S-AI-AUDIT-HIGH-FIX (MEDIUM/LOW batch 2) — guards three follow-up fixes from
 * the AI-module audit. Real code + live schema; every DB write is rolled back.
 *
 * A — insights.php anomaly query: the corrected SELECT (alert_type/title/
 *     description, not the nonexistent category/summary) runs and returns a
 *     seeded alert (was 42S22 → swallowed → Insights always showed 0 anomalies).
 * B — NOT-NULL date guard: WriteRegistry::validateField rejects clearing a
 *     required date (reservations.pickup_date) up front instead of letting it
 *     1048 into a stuck proposal; still allows clearing a nullable date and
 *     accepts a valid one.
 * C — summary cache read-side: SummaryEngine::cachedSummary returns null when
 *     ai.cache_summaries is off, and the cached row when it's on (the read the
 *     streaming endpoint now performs to skip a paid AI call).
 *
 * @session S-AI-AUDIT-HIGH-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\WriteRegistry;
use FleetForge\AI\SummaryEngine;

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

// ── B: NOT-NULL date guard (pure, no DB) ─────────────────────────────────────
echo "\n# B — NOT-NULL date guard in validateField\n";
$resEntry = WriteRegistry::get('reservation');
$rec = ['pickup_date' => '2026-01-01'];
$clear = WriteRegistry::validateField($resEntry, 'pickup_date', '', $rec);
ok('clearing required pickup_date is rejected', isset($clear['error']) && str_contains($clear['error'], 'required'), json_encode($clear));
$valid = WriteRegistry::validateField($resEntry, 'pickup_date', '2026-07-01', $rec);
ok('a valid pickup_date is accepted (no error)', !isset($valid['error']) && ($valid['column'] ?? '') === 'pickup_date', json_encode($valid));

$woEntry = WriteRegistry::get('maintenance_work_order');
$woRec = ['scheduled_date' => '2026-01-01'];
$clearNullable = WriteRegistry::validateField($woEntry, 'scheduled_date', '', $woRec);
ok('clearing a NULLABLE date (scheduled_date) is allowed', !isset($clearNullable['error']), json_encode($clearNullable));

// ── A + C: live-schema checks inside a rolled-back transaction ───────────────
$pdo = db_pdo();
$pdo->beginTransaction();
try {
    echo "\n# A — insights.php anomaly query returns rows (was always empty)\n";
    $eu = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    $aid = db_insert('ai_anomaly_alerts', [
        'alert_type'  => 'utilization_drop',
        'severity'    => 'high',
        'title'       => 'AUDIT smoke anomaly',
        'description' => 'seeded by _smoke_ai_low_fixes',
        'entity_type' => 'equipment_unit',
        'entity_id'   => (int) ($eu['id'] ?? 0),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    // The EXACT query insights.php now runs (real columns).
    $rows = db_select(
        "SELECT id, alert_type, severity, title, description, created_at, acknowledged_at
           FROM ai_anomaly_alerts
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ORDER BY id DESC LIMIT 20"
    );
    $found = null;
    foreach ($rows as $r) { if ((int) $r['id'] === (int) $aid) { $found = $r; break; } }
    ok('insights anomaly SELECT runs (no 42S22)', is_array($rows));
    ok('seeded alert is returned with real columns', $found !== null
        && $found['alert_type'] === 'utilization_drop'
        && $found['title'] === 'AUDIT smoke anomaly', json_encode($found));

    echo "\n# C — summary cache read-side honors ai.cache_summaries\n";
    // Snapshot the setting so we can toggle it hermetically (restored on rollback).
    $eid = 2000000003;
    db_execute(
        "INSERT INTO ai_summaries
            (entity_type, entity_id, summary_type, content, tokens_used, model_used,
             generated_at, expires_at, generated_by, is_current)
         VALUES ('lease', ?, 'lease_summary', 'CACHED body', 10, 'claude-sonnet-4-6', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR), NULL, 1)",
        [$eid]
    );

    // Setting OFF → no cache served (audit: settings were inert; now they decide).
    db_execute("UPDATE settings SET value='0' WHERE `key`='ai.cache_summaries'");
    $offHit = SummaryEngine::cachedSummary('lease', $eid, 'lease_summary');
    ok('cache_summaries=0 → cachedSummary returns null', $offHit === null);

    // Setting ON → cache served.
    db_execute("UPDATE settings SET value='1' WHERE `key`='ai.cache_summaries'");
    $onHit = SummaryEngine::cachedSummary('lease', $eid, 'lease_summary');
    ok('cache_summaries=1 → cachedSummary returns the row', is_array($onHit) && $onHit['content'] === 'CACHED body', json_encode($onHit));

    // Date-range narrative never caches even when the setting is on.
    $dateRange = SummaryEngine::cachedSummary('accounting', 0, 'pl_narrative');
    ok('date-range narrative (pl_narrative) never reads cache', $dateRange === null);
} finally {
    $pdo->rollBack();
    echo "\n(rolled back — DB + settings unchanged)\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
