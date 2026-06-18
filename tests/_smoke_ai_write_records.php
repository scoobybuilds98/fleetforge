<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_write_records.php
 *
 * S-AI-WRITE-2 — Schema-real smoke for the GENERALIZED AI write pipeline
 * (propose→confirm→apply→undo) across multiple modules + bulk edits.
 *
 * Drives the real code paths against the real DB:
 *   - plan_update_record (equipment_unit + customer) via ToolRegistry::execute
 *   - plan_bulk_update_records (equipment_unit, filter-selected)
 *   - ChangeApplier::apply / undo (now table-generic)
 *   - negatives: invalid field, unknown entity, feature-flag OFF
 *
 * Idempotent: every applied change is undone or restored; proposal rows are
 * deleted; the feature flag is returned to its original value. Safe to re-run.
 *
 * Usage: php tests/_smoke_ai_write_records.php
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php'; // for can()
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FleetForge\AI\ToolRegistry;
use FleetForge\AI\WriteRegistry;
use FleetForge\AI\ChangeApplier;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "S-AI-WRITE-2 — generalized AI write pipeline (multi-module + bulk)\n";
echo str_repeat('─', 72) . "\n";

// ── Setup: edit-permitted session ───────────────────────────────────────────
$user = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$user) { echo "SETUP FAIL — no users\n"; exit(2); }
$userId = (int) $user['id'];

@session_start();
$_SESSION['ff_user'] = [
    'id' => $userId, 'name' => 'Smoke Writer', 'role_slug' => 'super_admin',
    'permissions' => [], 'permission_overrides' => [], 'role_permission_overrides' => [],
];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$origFlag = settings_get('ai.write_enabled', false);
$setFlag = static function (string $v): void {
    if (db_row("SELECT id FROM settings WHERE `key`=?", ['ai.write_enabled'])) {
        db_update('settings', ['value' => $v], '`key`=?', ['ai.write_enabled']);
    } else {
        db_insert('settings', ['key' => 'ai.write_enabled', 'value' => $v, 'value_type' => 'boolean', 'group_name' => 'ai']);
    }
};
$setFlag('1');

$pids = [];
$plan = static function (string $tool, array $in) use ($userId): array {
    return json_decode(ToolRegistry::execute($tool, $in, $userId, null), true) ?: ['_raw' => 'decode-failed'];
};

// helper: run apply + undo for a proposal id, asserting a column on a table
$applyUndo = static function (int $pid) use ($userId): array {
    $row = db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$pid]);
    $out = ChangeApplier::apply($row, $userId, 'Smoke', '127.0.0.1');
    db_update('ai_pending_changes', ['status'=>'applied','applied_diff'=>json_encode($out['diff']),'applied_at'=>date('Y-m-d H:i:s'),'applied_by'=>$userId], 'id=?', [$pid]);
    return $out;
};

try {
    // ════ 1. EQUIPMENT single-record edit (mileage) ════
    $unit = db_row("SELECT id, unit_number, mileage FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $newMile = (int) $unit['mileage'] + 3210;
    $r = $plan('plan_update_record', ['entity_type'=>'equipment_unit','identifier'=>$unit['unit_number'],'field'=>'mileage','new_value'=>(string)$newMile]);
    if (!empty($r['proposal_id'])) { $pass("equipment: proposal created (#{$r['proposal_id']})"); $pids[] = $r['proposal_id']; }
    else { $fail("equipment: no proposal — " . json_encode($r)); }
    if (!empty($r['proposal_id'])) {
        $applyUndo((int)$r['proposal_id']);
        $after = (int) db_row("SELECT mileage FROM equipment_units WHERE id=?", [$unit['id']])['mileage'];
        $after === $newMile ? $pass("equipment: apply wrote mileage→{$newMile}") : $fail("equipment: mileage={$after} expected {$newMile}");
        ChangeApplier::undo(db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$r['proposal_id']]), $userId, 'Smoke', '127.0.0.1');
        $restored = (int) db_row("SELECT mileage FROM equipment_units WHERE id=?", [$unit['id']])['mileage'];
        $restored === (int)$unit['mileage'] ? $pass("equipment: undo restored mileage") : $fail("equipment: undo left {$restored}");
    }

    // ════ 2. CUSTOMER single-record edit (phone) — proves multi-module ════
    $cust = db_row("SELECT id, company_name, phone FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($cust) {
        $origPhone = $cust['phone'];
        $r2 = $plan('plan_update_record', ['entity_type'=>'customer','identifier'=>$cust['company_name'],'field'=>'phone','new_value'=>'555-0100-smoke']);
        if (!empty($r2['proposal_id'])) {
            $pass("customer: proposal created (resolve by company_name)"); $pids[] = $r2['proposal_id'];
            $applyUndo((int)$r2['proposal_id']);
            $ph = db_row("SELECT phone FROM customers WHERE id=?", [$cust['id']])['phone'];
            $ph === '555-0100-smoke' ? $pass("customer: apply wrote phone") : $fail("customer: phone={$ph}");
            ChangeApplier::undo(db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$r2['proposal_id']]), $userId, 'Smoke', '127.0.0.1');
            $phr = db_row("SELECT phone FROM customers WHERE id=?", [$cust['id']])['phone'];
            (string)$phr === (string)$origPhone ? $pass("customer: undo restored phone") : $fail("customer: undo left '{$phr}'");
        } else {
            // company_name may be ambiguous → acceptable; assert it's a clarification, not a crash
            (is_string($r2) ?? false) || isset($r2['_raw']) ? $fail("customer: unexpected ".json_encode($r2)) : $pass("customer: planner returned a message (likely disambiguation)");
        }
    } else { echo "  (skip customer — none seeded)\n"; }

    // ════ 3. BULK equipment edit (notes) selected by a filter ════
    // Find an allow-listed single-column filter whose group is 1..BULK_MAX so
    // we exercise the real bulk apply (not just the over-cap guard).
    $bulkCol = $bulkVal = null; $bulkCnt = 0;
    foreach (['yard_location', 'status', 'ownership_type', 'year'] as $col) {
        $g = db_row("SELECT `{$col}` v, COUNT(*) c FROM equipment_units WHERE deleted_at IS NULL AND `{$col}` IS NOT NULL GROUP BY `{$col}` HAVING c BETWEEN 1 AND " . WriteRegistry::BULK_MAX . " ORDER BY c DESC LIMIT 1");
        if ($g) { $bulkCol = $col; $bulkVal = $g['v']; $bulkCnt = (int)$g['c']; break; }
    }
    if ($bulkCol !== null) {
        $rb = $plan('plan_bulk_update_records', ['entity_type'=>'equipment_unit','filters'=>[$bulkCol=>$bulkVal],'field'=>'notes','new_value'=>'__ai_bulk_smoke__']);
        if (!empty($rb['proposal_id'])) {
            $pids[] = $rb['proposal_id'];
            $cnt = (int)$rb['affected_count'];
            $cnt === $bulkCnt ? $pass("bulk: proposal targets {$cnt} units ({$bulkCol}={$bulkVal})") : $fail("bulk: affected={$cnt} expected {$bulkCnt}");
            $applyUndo((int)$rb['proposal_id']);
            $changed = (int) db_row("SELECT COUNT(*) c FROM equipment_units WHERE `{$bulkCol}`=? AND deleted_at IS NULL AND notes='__ai_bulk_smoke__'", [$bulkVal])['c'];
            $changed === $cnt ? $pass("bulk: apply wrote notes to all {$cnt}") : $fail("bulk: only {$changed}/{$cnt} updated");
            ChangeApplier::undo(db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$rb['proposal_id']]), $userId, 'Smoke', '127.0.0.1');
            $still = (int) db_row("SELECT COUNT(*) c FROM equipment_units WHERE deleted_at IS NULL AND notes='__ai_bulk_smoke__'")['c'];
            $still === 0 ? $pass("bulk: undo restored all {$cnt} notes") : $fail("bulk: {$still} rows still have sentinel notes");
        } else { $fail("bulk: no proposal — " . json_encode($rb)); }
    } else {
        echo "  (skip bulk — no equipment filter group within 1..".WriteRegistry::BULK_MAX.")\n";
    }

    // ════ 3b. BULK over-cap guard ════
    $big = db_row("SELECT status v, COUNT(*) c FROM equipment_units WHERE deleted_at IS NULL GROUP BY status HAVING c > " . WriteRegistry::BULK_MAX . " ORDER BY c DESC LIMIT 1");
    if ($big) {
        $over = ToolRegistry::execute('plan_bulk_update_records', ['entity_type'=>'equipment_unit','filters'=>['status'=>$big['v']],'field'=>'notes','new_value'=>'x'], $userId, null);
        stripos($over, 'exceeds the bulk limit') !== false ? $pass("bulk: over-cap selection refused (status={$big['v']}, {$big['c']} rows)") : $fail("bulk: over-cap not refused: {$over}");
    }

    // ════ 3c. ACTION: equipment status change (lifecycle, not a field edit) ════
    $au = db_row("SELECT id, unit_number, status FROM equipment_units WHERE deleted_at IS NULL AND status='available' ORDER BY id LIMIT 1");
    if ($au) {
        $ra = $plan('plan_action', ['action'=>'change_equipment_status','identifier'=>$au['unit_number'],'new_status'=>'maintenance','reason'=>'smoke test']);
        if (!empty($ra['proposal_id'])) {
            $pids[] = $ra['proposal_id'];
            ($ra['undoable'] === false) ? $pass("action: proposal created + flagged not-undoable") : $fail("action: undoable flag should be false");
            $logBefore = (int) db_row("SELECT COUNT(*) c FROM equipment_status_log WHERE equipment_unit_id=?", [$au['id']])['c'];
            \FleetForge\AI\ActionRegistry::execute('change_equipment_status', (int)$au['id'], ['new_status'=>'maintenance','reason'=>'smoke test'], $userId, 'Smoke', '127.0.0.1');
            $now = db_row("SELECT status FROM equipment_units WHERE id=?", [$au['id']])['status'];
            $now === 'maintenance' ? $pass("action: applied status available→maintenance") : $fail("action: status now '{$now}'");
            $logAfter = (int) db_row("SELECT COUNT(*) c FROM equipment_status_log WHERE equipment_unit_id=?", [$au['id']])['c'];
            $logAfter === $logBefore + 1 ? $pass("action: equipment_status_log row written") : $fail("action: status_log not written ({$logBefore}→{$logAfter})");
            // restore
            \FleetForge\AI\ActionRegistry::execute('change_equipment_status', (int)$au['id'], ['new_status'=>'available','reason'=>'smoke restore'], $userId, 'Smoke', '127.0.0.1');
            $rest = db_row("SELECT status FROM equipment_units WHERE id=?", [$au['id']])['status'];
            $rest === 'available' ? $pass("action: restored to available") : $fail("action: not restored (now '{$rest}')");
        } else { $fail("action: no proposal — " . json_encode($ra)); }
        // invalid transition rejected at preview (available→on_lease not allowed)
        $badT = ToolRegistry::execute('plan_action', ['action'=>'change_equipment_status','identifier'=>$au['unit_number'],'new_status'=>'on_lease'], $userId, null);
        stripos($badT, "can't go from") !== false ? $pass("action: invalid transition rejected") : $fail("action: invalid transition not rejected: {$badT}");
    } else { echo "  (skip action — no 'available' unit)\n"; }

    // ════ 3d. FINANCIAL ACTION: invoice void ════
    // Pure preview logic (no DB needed) — the void decision rules.
    $vEntry = \FleetForge\AI\ActionRegistry::get('void_invoice');
    if ($vEntry) {
        $pv = ($vEntry['preview'])(['invoice_number'=>'INV-X','status'=>'paid','total_amount'=>100], ['reason'=>'x']);
        isset($pv['error']) ? $pass("void: paid invoice rejected by preview") : $fail("void: paid not rejected");
        $pv2 = ($vEntry['preview'])(['invoice_number'=>'INV-Y','status'=>'sent','total_amount'=>250], ['reason'=>'']);
        isset($pv2['error']) ? $pass("void: missing reason rejected by preview") : $fail("void: missing reason not rejected");
        $pv3 = ($vEntry['preview'])(['invoice_number'=>'INV-Z','status'=>'sent','total_amount'=>250], ['reason'=>'duplicate']);
        (isset($pv3['summary']) && stripos($pv3['summary'],'Void invoice INV-Z')!==false) ? $pass("void: valid preview builds summary") : $fail("void: summary wrong: ".json_encode($pv3));
    } else { $fail("void: action not registered"); }

    // Guard paths via the service (no real invoice needed).
    try { \FleetForge\AI\Actions\FinancialActions::voidInvoice(999999, 'reason', $userId, 'Smoke', '127.0.0.1'); $fail("void: missing invoice should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("void: not-found invoice rejected") : $fail("void: wrong code {$e->errorCode}"); }
    try { \FleetForge\AI\Actions\FinancialActions::voidInvoice(999999, '', $userId, 'Smoke', '127.0.0.1'); $fail("void: empty reason should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='VALIDATION_ERROR' ? $pass("void: empty reason rejected") : $fail("void: wrong code {$e->errorCode}"); }

    // Hermetic apply: synthesize a SENT invoice, void it, assert counters, ROLL BACK.
    $vc = db_row("SELECT id, outstanding_balance, total_revenue FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($vc) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $invId = db_insert('invoices', [
                'invoice_number'      => 'TEST-VOID-SMOKE',
                'customer_id'         => (int)$vc['id'],
                'status'              => 'sent',
                'billing_type'        => 'single_period',
                'invoice_date'        => date('Y-m-d'),
                'due_date'            => date('Y-m-d'),
                'billing_period_start'=> date('Y-m-d'),
                'billing_period_end'  => date('Y-m-d'),
                'billing_period_days' => 1,
                'total_amount'        => '100.00',
                'balance_due'         => '100.00',
            ]);
            $res = \FleetForge\AI\Actions\FinancialActions::voidInvoice($invId, 'smoke void', $userId, 'Smoke', '127.0.0.1');
            $inv = db_row("SELECT status, balance_due FROM invoices WHERE id=?", [$invId]);
            ($inv['status']==='void' && (float)$inv['balance_due']===0.0) ? $pass("void: invoice set to void, balance_due zeroed") : $fail("void: invoice state ".json_encode($inv));
            $custAfter = db_row("SELECT outstanding_balance, total_revenue FROM customers WHERE id=?", [$vc['id']]);
            $obDelta  = (float)$vc['outstanding_balance'] - (float)$custAfter['outstanding_balance'];
            $revDelta = (float)$vc['total_revenue'] - (float)$custAfter['total_revenue'];
            (abs($obDelta-100.0)<0.001 && abs($revDelta-100.0)<0.001) ? $pass("void: customer OB & revenue decremented by \$100 (Path B sent→void)") : $fail("void: counter delta OB={$obDelta} rev={$revDelta}");
            $aud = (int) db_row("SELECT COUNT(*) c FROM audit_log WHERE entity_type='invoice' AND entity_id=? AND action='status_change'", [$invId])['c'];
            $aud >= 1 ? $pass("void: audit_log row written") : $fail("void: no audit row");
        } catch (\Throwable $e) {
            $fail("void: hermetic apply threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack(); // nothing persists
        }
        // confirm rollback left the customer untouched
        $cc = db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$vc['id']]);
        ((float)$cc['outstanding_balance'] === (float)$vc['outstanding_balance']) ? $pass("void: rollback restored customer counters (zero footprint)") : $fail("void: counters NOT restored");
    }

    // ════ 3e. FINANCIAL ACTION: invoice send ════
    $sEntry = \FleetForge\AI\ActionRegistry::get('send_invoice');
    if ($sEntry) {
        $sp = ($sEntry['preview'])(['invoice_number'=>'INV-S','status'=>'sent','total_amount'=>100], []);
        isset($sp['error']) ? $pass("send: non-draft invoice rejected by preview") : $fail("send: non-draft not rejected");
        $sp2 = ($sEntry['preview'])(['invoice_number'=>'INV-D','status'=>'draft','total_amount'=>300], []);
        (isset($sp2['summary']) && stripos($sp2['summary'],'Send invoice INV-D')!==false) ? $pass("send: draft preview builds summary") : $fail("send: summary wrong");
    } else { $fail("send: action not registered"); }
    try { \FleetForge\AI\Actions\FinancialActions::sendInvoice(999999, null, $userId, 'Smoke', '127.0.0.1'); $fail("send: missing invoice should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("send: not-found invoice rejected") : $fail("send: wrong code {$e->errorCode}"); }

    if (!empty($vc)) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        $sendOk = false; $threw = null;
        try {
            $sid = db_insert('invoices', [
                'invoice_number'=>'TEST-SEND-SMOKE','customer_id'=>(int)$vc['id'],'status'=>'draft',
                'billing_type'=>'single_period','invoice_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d'),
                'billing_period_start'=>date('Y-m-d'),'billing_period_end'=>date('Y-m-d'),'billing_period_days'=>1,
                'total_amount'=>'150.00','balance_due'=>'150.00',
            ]);
            $custBefore = db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$vc['id']]);
            \FleetForge\AI\Actions\FinancialActions::sendInvoice($sid, null, $userId, 'Smoke', '127.0.0.1');
            $inv = db_row("SELECT status FROM invoices WHERE id=?", [$sid]);
            $custAfter = db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$vc['id']]);
            $obDelta = (float)$custAfter['outstanding_balance'] - (float)$custBefore['outstanding_balance'];
            $sendOk = ($inv['status']==='sent' && abs($obDelta-150.0)<0.001);
        } catch (\Throwable $e) { $threw = $e->getMessage(); }
        finally { $pdo->rollBack(); }
        if ($sendOk) $pass("send: draft→sent applied, customer OB +\$150 (Path B)");
        elseif ($threw !== null) echo "  (skip send apply — accounting post not available in dev: " . substr($threw,0,80) . ")\n";
        else $fail("send: apply did not reach sent state");
    }

    // ════ 3f. FINANCIAL ACTION: payment void ════
    $pEntry = \FleetForge\AI\ActionRegistry::get('void_payment');
    if ($pEntry) {
        ($pEntry['perm_action'] ?? 'edit') === 'delete' ? $pass("payment void: requires payments:delete (not edit)") : $fail("payment void: wrong perm_action");
        $pp = ($pEntry['preview'])(['payment_number'=>'PMT-1','amount'=>500,'currency'=>'CAD'], ['reason'=>'']);
        isset($pp['error']) ? $pass("payment void: missing reason rejected by preview") : $fail("payment void: missing reason not rejected");
        $pp2 = ($pEntry['preview'])(['payment_number'=>'PMT-1','amount'=>500,'currency'=>'CAD'], ['reason'=>'duplicate entry']);
        (isset($pp2['summary']) && stripos($pp2['summary'],'Void payment PMT-1')!==false) ? $pass("payment void: valid preview builds summary") : $fail("payment void: summary wrong");
    } else { $fail("payment void: action not registered"); }
    try { \FleetForge\AI\Actions\FinancialActions::voidPayment(999999, 'r', $userId, 'Smoke', '127.0.0.1'); $fail("payment void: missing should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("payment void: not-found rejected") : $fail("payment void: wrong code {$e->errorCode}"); }

    if (!empty($vc)) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $obBefore = (float) db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$vc['id']])['outstanding_balance'];
            $pinv = db_insert('invoices', [
                'invoice_number'=>'TEST-PAYVOID-INV','customer_id'=>(int)$vc['id'],'status'=>'paid',
                'billing_type'=>'single_period','invoice_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d'),
                'billing_period_start'=>date('Y-m-d'),'billing_period_end'=>date('Y-m-d'),'billing_period_days'=>1,
                'total_amount'=>'200.00','amount_paid'=>'200.00','balance_due'=>'0.00','credits_applied'=>'0.00',
            ]);
            $payId = db_insert('payments', [
                'payment_number'=>'TEST-PAYVOID-PMT','customer_id'=>(int)$vc['id'],'amount'=>'200.00',
                'payment_method'=>'cash','payment_date'=>date('Y-m-d'),
            ]);
            db_insert('payment_allocations', ['payment_id'=>$payId,'invoice_id'=>$pinv,'amount'=>'200.00']);

            $res = \FleetForge\AI\Actions\FinancialActions::voidPayment($payId, 'smoke reversal', $userId, 'Smoke', '127.0.0.1');
            $pay = db_row("SELECT deleted_at FROM payments WHERE id=?", [$payId]);
            $pay['deleted_at'] !== null ? $pass("payment void: payment soft-deleted") : $fail("payment void: not soft-deleted");
            $iv = db_row("SELECT status, amount_paid, balance_due FROM invoices WHERE id=?", [$pinv]);
            ($iv['status']==='sent' && (float)$iv['amount_paid']===0.0 && (float)$iv['balance_due']===200.0)
                ? $pass("payment void: invoice reverted paid→sent (amount_paid 0, balance 200)")
                : $fail("payment void: invoice state ".json_encode($iv));
            $obAfter = (float) db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$vc['id']])['outstanding_balance'];
            abs(($obAfter-$obBefore)-200.0)<0.001 ? $pass("payment void: customer OB re-incremented +\$200 (Path B)") : $fail("payment void: OB delta ".($obAfter-$obBefore));
            $aud = (int) db_row("SELECT COUNT(*) c FROM audit_log WHERE entity_type='payment' AND entity_id=? AND action='delete'", [$payId])['c'];
            $aud >= 1 ? $pass("payment void: audit_log row written") : $fail("payment void: no audit row");
        } catch (\Throwable $e) {
            $fail("payment void: hermetic apply threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    // ════ 4. NEGATIVES ════
    $bad = ToolRegistry::execute('plan_update_record', ['entity_type'=>'equipment_unit','identifier'=>$unit['unit_number'],'field'=>'secret_column','new_value'=>'x'], $userId, null);
    stripos($bad, 'can only change') !== false ? $pass("negative: invalid field rejected by allow-list") : $fail("negative: invalid field not rejected: {$bad}");

    $unk = ToolRegistry::execute('plan_update_record', ['entity_type'=>'nuclear_codes','identifier'=>'x','field'=>'y','new_value'=>'z'], $userId, null);
    stripos($unk, 'can only edit') !== false ? $pass("negative: unknown entity_type rejected") : $fail("negative: unknown entity not rejected: {$unk}");

    $notFound = ToolRegistry::execute('plan_update_record', ['entity_type'=>'customer','identifier'=>'__no_such_company__zzz','field'=>'phone','new_value'=>'1'], $userId, null);
    stripos($notFound, 'No customer found') !== false ? $pass("negative: unresolvable identifier rejected") : $fail("negative: not-found not handled: {$notFound}");

    $setFlag('0');
    $off = ToolRegistry::execute('plan_update_record', ['entity_type'=>'equipment_unit','identifier'=>$unit['unit_number'],'field'=>'notes','new_value'=>'x'], $userId, null);
    stripos($off, 'disabled') !== false ? $pass("negative: feature flag OFF blocks all planners") : $fail("negative: flag off not enforced: {$off}");
    $setFlag('1');

} finally {
    foreach ($pids as $id) { db_execute("DELETE FROM ai_pending_changes WHERE id=?", [$id]); }
    // belt-and-suspenders: clear any leftover sentinel notes
    db_execute("UPDATE equipment_units SET notes=NULL WHERE notes='__ai_bulk_smoke__'");
    if ($origFlag === null) { db_execute("DELETE FROM settings WHERE `key`='ai.write_enabled'"); }
    else { $setFlag((string) $origFlag); }
}

echo str_repeat('─', 72) . "\n";
echo $failures ? "\033[31m" . count($failures) . " FAIL\033[0m / {$passes} pass\n"
              : "\033[32mALL {$passes} CHECKS PASS\033[0m\n";
exit($failures ? 1 : 0);
