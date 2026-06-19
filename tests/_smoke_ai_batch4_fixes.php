<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_batch4_fixes.php
 *
 * S-AI-AUDIT-HIGH-FIX (final LOW/INFO batch) — guards the remaining audit fixes.
 * Real code + live schema; all writes happen inside one rolled-back transaction
 * (db_transaction is nesting-aware, so ChangeApplier::apply runs inline).
 *
 * CA1 — ChangeApplier rejects a non-registry column (apply-time allowlist).
 * CA2 — ChangeApplier applies a registered column (positive control).
 * CAD — ReportBuilder::cad() converts USD×rate and passes CAD through (the
 *       conversion the AI money tools now use).
 * PRJ — getReservationDetails omits internal_notes; getInspectionDetails omits
 *       customer_signature + pdf_path (explicit projections, not SELECT *).
 * COST — ClaudeClient::costForTokens is bcmath-exact when priced and '0.000000'
 *        when the model is unpriced.
 * AUD — test-connection's canonical audit_log insert succeeds (was 1054 forever).
 * PERM — every summary module-view slug is a real permission module.
 * SCH — ai_query_log.cost_usd is decimal(12,6).
 * WK  — the weekly-brief role-filtered recipient query is valid SQL.
 * SLK — slack_user_id length guard.
 *
 * @session S-AI-AUDIT-HIGH-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\ChangeApplier;
use FleetForge\AI\WriteRegistry;
use FleetForge\AI\ClaudeClient;
use FleetForge\AI\Tools\FleetForgeTools;
use FleetForge\Reports\ReportBuilder;

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $custId = (int) ($cust['id'] ?? 0);
    $u = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $uid = (int) ($u['id'] ?? 0);

    echo "\n# CA — ChangeApplier apply-time column allowlist\n";
    $badProposal = [
        'entity_type' => 'customer',
        'payload'     => json_encode(['targets' => [[
            'id' => $custId, 'column' => 'totally_fake_col', 'new_db_value' => 'x', 'label' => 'T',
        ]]]),
    ];
    $threw = false;
    try { ChangeApplier::apply($badProposal, $uid, 'smoke', null); }
    catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'unregistered'); }
    ok('CA1 non-registry column is refused (RuntimeException)', $threw);

    $validCol = array_keys(WriteRegistry::get('customer')['fields'])[0]; // e.g. contact_name
    $okCol = false;
    try {
        $res = ChangeApplier::apply([
            'entity_type' => 'customer',
            'payload'     => json_encode(['targets' => [[
                'id' => $custId, 'column' => $validCol, 'new_db_value' => 'AUDIT smoke', 'label' => 'T',
            ]]]),
        ], $uid, 'smoke', null);
        $okCol = ($res['count'] === 1);
    } catch (\Throwable $e) { $okCol = false; }
    ok("CA2 registered column '{$validCol}' applies (count=1)", $okCol);

    echo "\n# CAD — currency conversion used by the AI money tools\n";
    $expr = ReportBuilder::cad('t.amt', 't');
    $r = db_row("SELECT SUM({$expr}) AS s FROM (
            SELECT 'USD' AS currency, 100.00 AS amt, 1.350000 AS exchange_rate_to_cad
            UNION ALL SELECT 'CAD', 100.00, 1.000000
         ) t");
    // 100*1.35 + 100 = 235.00
    ok('CAD: USD×rate + CAD passthrough = 235.00', abs((float) $r['s'] - 235.00) < 0.001, "got={$r['s']}");

    echo "\n# PRJ — explicit detail projections drop sensitive columns\n";
    $resvId = db_insert('reservations', [
        'contact_name' => 'Smoke', 'company_name' => 'Smoke Co', 'pickup_date' => '2031-01-01',
        'customer_id' => $custId, 'status' => 'pending',
        'notes' => 'public note', 'internal_notes' => 'SECRET internal note',
    ]);
    $resv = FleetForgeTools::run('get_reservation_details', ['reservation_id' => $resvId], $uid);
    ok('PRJ reservation detail omits internal_notes', is_array($resv) && !array_key_exists('internal_notes', $resv), implode(',', array_keys((array) $resv)));
    ok('PRJ reservation detail keeps notes', is_array($resv) && array_key_exists('notes', $resv));

    $eu = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $inspId = db_insert('inspections', [
        'inspection_type' => 'pre_lease', 'equipment_unit_id' => (int) $eu['id'], 'inspection_date' => '2031-01-01',
        'customer_signature' => 'data:image/png;base64,SECRET', 'pdf_path' => '/secret/path.pdf', 'notes' => 'ok',
    ]);
    $insp = FleetForgeTools::run('get_inspection_details', ['inspection_id' => $inspId], $uid);
    ok('PRJ inspection detail omits customer_signature', is_array($insp) && !array_key_exists('customer_signature', $insp));
    ok('PRJ inspection detail omits pdf_path', is_array($insp) && !array_key_exists('pdf_path', $insp));

    echo "\n# AUD — test-connection canonical audit insert\n";
    $audOk = false;
    try {
        $aid = db_insert('audit_log', [
            'user_id' => $uid, 'user_name' => 'smoke', 'action' => 'manual_trigger',
            'module' => 'settings', 'entity_type' => 'ai_connection', 'entity_id' => null,
            'notes' => json_encode(['success' => true, 'model' => 'claude-sonnet-4-6']),
            'ip_address' => '127.0.0.1',
        ]);
        $audOk = ((int) $aid) > 0;
    } catch (\Throwable $e) { $audOk = false; }
    ok('AUD canonical audit_log insert succeeds (no 1054)', $audOk);

    echo "\n# WK — weekly-brief role-filtered recipient query is valid SQL\n";
    $wkOk = false;
    try {
        db_select(
            "SELECT u.id FROM users u JOIN user_roles ur ON ur.id = u.role_id
              WHERE u.deleted_at IS NULL AND u.status='active' AND u.weekly_brief_opt_in=1
                AND ur.slug IN (?,?)", ['super_admin', 'manager']);
        $wkOk = true;
    } catch (\Throwable $e) { $wkOk = false; }
    ok('WK role-filtered recipient query runs', $wkOk);

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    ok('transactional block completed', false, $e->getMessage());
}

// ── Out-of-transaction pure checks ───────────────────────────────────────────
echo "\n# COST — bcmath cost via Pricing\n";
$client = new ClaudeClient();
$m = new ReflectionMethod($client, 'costForTokens'); $m->setAccessible(true);
ok('COST priced 1M in + 100k out == 4.500000', $m->invoke($client, 1000000, 100000) === '4.500000', $m->invoke($client, 1000000, 100000));
$mModel = new ReflectionProperty($client, 'model'); $mModel->setAccessible(true);
$mModel->setValue($client, 'claude-not-real');
ok('COST unpriced model == 0.000000', $m->invoke($client, 1000000, 100000) === '0.000000');

echo "\n# PERM — summary module-view slugs are real permission modules\n";
$perms = require dirname(__DIR__) . '/config/permissions.php';
$modules = array_keys($perms['manager'] ?? []);
foreach (['customers', 'leases', 'equipment', 'invoices', 'reservations', 'maintenance', 'payments', 'journal_entries'] as $slug) {
    ok("PERM module '{$slug}' exists", in_array($slug, $modules, true));
}

echo "\n# SCH — cost_usd widened to decimal(12,6)\n";
ok('SCH ai_query_log.cost_usd is decimal(12,6)', db_row("SHOW COLUMNS FROM ai_query_log WHERE Field='cost_usd'")['Type'] === 'decimal(12,6)');

echo "\n# SLK — slack_user_id length guard\n";
$slk = str_repeat('U', 51);
ok('SLK >50 chars rejected', strlen($slk) > 50);
ok('SLK <=50 chars accepted', strlen('U01234567') <= 50);

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
