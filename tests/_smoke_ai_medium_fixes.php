<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_medium_fixes.php
 *
 * S-AI-AUDIT-HIGH-FIX (MEDIUM batch) — guards the four MEDIUM fixes from the
 * end-to-end AI-module audit. Exercises the REAL code + live schema, not php -l.
 *
 * M1 — Pricing: the LIVE ai.model is priced (the unpriced-model regression that
 *      zeroed the credit estimate). Also a bogus model stays unpriced.
 * M2 — cacheSummary upserts in place: regenerating the same (entity,type) tuple
 *      PERSISTS the 2nd content (was 1062-dup-key swallowed → cache one-shot).
 * M3 — every summary-stream whitelist type exists in the ai_summaries ENUM, and
 *      a panel type (vendor_summary) now caches without 1265.
 * M5 — apply-change atomic claim: the conditional "WHERE id=? AND status='pending'"
 *      UPDATE returns 1 for the first claimant and 0 for the second (the gate that
 *      stops a concurrent double-apply).
 *
 * Hermetic: every DB write runs inside one outer transaction that is rolled back,
 * so the dev DB is left byte-identical.
 *
 * @session S-AI-AUDIT-HIGH-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\Pricing;
use FleetForge\AI\SummaryEngine;

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

// ── M1: live model pricing ───────────────────────────────────────────────────
echo "\n# M1 — model pricing (no silent \$0 credit)\n";
$liveModel = (string) settings_get('ai.model');
ok("live ai.model ('{$liveModel}') is priced", Pricing::isPriced($liveModel), 'unpriced → credit estimate silently reports $0');
ok("claude-sonnet-4-6 is priced", Pricing::isPriced('claude-sonnet-4-6'));
$rates = Pricing::forModel('claude-sonnet-4-6');
ok("claude-sonnet-4-6 input rate = 3.00", is_array($rates) && $rates['input'] === '3.00', $rates['input'] ?? 'null');
ok("claude-sonnet-4-6 output rate = 15.00", is_array($rates) && $rates['output'] === '15.00', $rates['output'] ?? 'null');
ok("bogus model stays unpriced", !Pricing::isPriced('claude-not-a-real-model'));

// ── M3: ENUM covers every summary-stream whitelist type ──────────────────────
echo "\n# M3 — ai_summaries.summary_type ENUM covers the stream whitelist\n";
// Mirror api/v1/ai/summary-stream.php $validSummaryTypes (keep in sync).
$whitelist = [
    'customer_insights', 'lease_summary', 'unit_analysis', 'fleet_health',
    'payment_risk', 'forecast', 'anomaly', 'accounting_overview',
    'invoice_analysis', 'reservation_summary', 'vendor_summary', 'payment_summary',
    'pl_narrative', 'bs_narrative', 'cashflow_narrative', 'budget_variance',
];
$enumType = (string) (db_row("SHOW COLUMNS FROM ai_summaries WHERE Field='summary_type'")['Type'] ?? '');
$missing = array_filter($whitelist, static fn($t) => strpos($enumType, "'{$t}'") === false);
ok('every stream summary_type is an ENUM member', $missing === [], 'missing: ' . implode(', ', $missing));

// ── M2 + M3 (write path): hermetic upsert round-trip ─────────────────────────
echo "\n# M2 — cacheSummary upserts (regen persists, no 1062/1265)\n";
$pdo = db_pdo();
$pdo->beginTransaction();
try {
    // Use a far-out synthetic entity_id so we never touch a real cached row.
    $eid = 2000000001;

    // First write (a valid ENUM type).
    SummaryEngine::cacheSummary('lease', $eid, 'lease_summary', 'FIRST content', 100, 'claude-sonnet-4-6', null);
    $row1 = db_row("SELECT content, is_current FROM ai_summaries WHERE entity_type='lease' AND entity_id=? AND summary_type='lease_summary'", [$eid]);
    ok('first cacheSummary wrote a current row', $row1 && (int)$row1['is_current'] === 1 && $row1['content'] === 'FIRST content');

    // Regenerate the SAME tuple — the old path 1062-failed here and left is_current=0.
    SummaryEngine::cacheSummary('lease', $eid, 'lease_summary', 'SECOND content', 200, 'claude-sonnet-4-6', null);
    $cnt = db_count("SELECT COUNT(*) FROM ai_summaries WHERE entity_type='lease' AND entity_id=? AND summary_type='lease_summary'", [$eid]);
    $row2 = db_row("SELECT content, is_current, tokens_used FROM ai_summaries WHERE entity_type='lease' AND entity_id=? AND summary_type='lease_summary'", [$eid]);
    ok('regen kept exactly ONE row (upsert, not insert)', $cnt === 1, "rows={$cnt}");
    ok('regen PERSISTED the new content', $row2 && $row2['content'] === 'SECOND content', $row2['content'] ?? 'null');
    ok('regen row is still current', $row2 && (int)$row2['is_current'] === 1);
    ok('regen updated tokens_used', $row2 && (int)$row2['tokens_used'] === 200);

    // M3: a panel type (vendor_summary) now caches without 1265.
    $eid2 = 2000000002;
    SummaryEngine::cacheSummary('vendor', $eid2, 'vendor_summary', 'VENDOR panel', 50, 'claude-sonnet-4-6', null);
    $rowV = db_row("SELECT content FROM ai_summaries WHERE entity_type='vendor' AND entity_id=? AND summary_type='vendor_summary'", [$eid2]);
    ok('panel type vendor_summary cached (no 1265)', $rowV && $rowV['content'] === 'VENDOR panel');

    // ── M5: apply-change atomic claim ────────────────────────────────────────
    echo "\n# M5 — apply-change atomic claim (first wins, second 409s)\n";
    $u = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $pid = db_insert('ai_pending_changes', [
        'user_id'     => (int)$u['id'],
        'change_type' => 'update',
        'entity_type' => 'customer',
        'summary'     => 'smoke claim test',
        'payload'     => json_encode(['targets' => []]),
        'status'      => 'pending',
    ]);
    // The exact gate apply-change.php uses.
    $claim1 = db_update('ai_pending_changes', ['status' => 'applied'], 'id = ? AND status = ?', [$pid, 'pending']);
    $claim2 = db_update('ai_pending_changes', ['status' => 'applied'], 'id = ? AND status = ?', [$pid, 'pending']);
    ok('first claim wins (rowCount=1)', $claim1 === 1, "got {$claim1}");
    ok('second claim loses (rowCount=0 → 409)', $claim2 === 0, "got {$claim2}");
} finally {
    $pdo->rollBack();
    echo "\n(rolled back — DB unchanged)\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
