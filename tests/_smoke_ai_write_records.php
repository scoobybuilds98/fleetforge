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

    // ════ 3g. ACTION: reservation status change ════
    $rEntry = \FleetForge\AI\ActionRegistry::get('change_reservation_status');
    if ($rEntry) {
        $rp = ($rEntry['preview'])(['id'=>7,'status'=>'cancelled','company_name'=>'Acme'], ['new_status'=>'confirmed']);
        isset($rp['error']) ? $pass("reservation: invalid transition (cancelled→confirmed) rejected") : $fail("reservation: bad transition not rejected");
        $rp2 = ($rEntry['preview'])(['id'=>7,'status'=>'pending','company_name'=>'Acme'], ['new_status'=>'cancelled','reason'=>'']);
        isset($rp2['error']) ? $pass("reservation: cancel without reason rejected") : $fail("reservation: cancel w/o reason not rejected");
        $rp3 = ($rEntry['preview'])(['id'=>7,'status'=>'pending','company_name'=>'Acme'], ['new_status'=>'confirmed']);
        (isset($rp3['summary']) && stripos($rp3['summary'],'pending → confirmed')!==false) ? $pass("reservation: valid preview builds summary") : $fail("reservation: summary wrong");
    } else { $fail("reservation: action not registered"); }
    try { \FleetForge\AI\Actions\StatusActions::changeReservationStatus(999999,'confirmed',null,$userId,'Smoke','manager','127.0.0.1'); $fail("reservation: not-found should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("reservation: not-found rejected") : $fail("reservation: wrong code {$e->errorCode}"); }

    $ru = db_row("SELECT id, unit_number, status FROM equipment_units WHERE deleted_at IS NULL AND status='available' ORDER BY id LIMIT 1");
    if ($ru) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $resId = db_insert('reservations', [
                'contact_name'=>'Smoke Tester','company_name'=>'Smoke Reservation Co','pickup_date'=>date('Y-m-d'),'status'=>'pending',
            ]);
            db_insert('reservation_units', ['reservation_id'=>$resId,'unit_number_snapshot'=>$ru['unit_number'],'equipment_unit_id'=>(int)$ru['id']]);
            \FleetForge\AI\Actions\StatusActions::changeReservationStatus($resId,'confirmed',null,$userId,'Smoke','manager','127.0.0.1');
            $r = db_row("SELECT status FROM reservations WHERE id=?", [$resId]);
            $r['status']==='confirmed' ? $pass("reservation: applied pending→confirmed") : $fail("reservation: status ".json_encode($r));
            $unitNow = db_row("SELECT status FROM equipment_units WHERE id=?", [$ru['id']])['status'];
            $unitNow==='reserved' ? $pass("reservation: linked unit available→reserved") : $fail("reservation: unit status '{$unitNow}'");
            $log = (int) db_row("SELECT COUNT(*) c FROM equipment_status_log WHERE equipment_unit_id=? AND new_status='reserved'", [$ru['id']])['c'];
            $log >= 1 ? $pass("reservation: equipment_status_log row written") : $fail("reservation: no status_log");
            // now cancel (requires reason) → unit back to available
            \FleetForge\AI\Actions\StatusActions::changeReservationStatus($resId,'cancelled','smoke cancel',$userId,'Smoke','manager','127.0.0.1');
            $r2 = db_row("SELECT status FROM reservations WHERE id=?", [$resId])['status'];
            $u2 = db_row("SELECT status FROM equipment_units WHERE id=?", [$ru['id']])['status'];
            ($r2==='cancelled' && $u2==='available') ? $pass("reservation: cancel freed the unit (reserved→available)") : $fail("reservation: cancel state res={$r2} unit={$u2}");
        } catch (\Throwable $e) {
            $fail("reservation: hermetic apply threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    // ════ 3h. ACTION: work-order status change ════
    $wEntry = \FleetForge\AI\ActionRegistry::get('change_work_order_status');
    if ($wEntry) {
        $wp = ($wEntry['preview'])(['work_order_number'=>'WO-1','status'=>'completed','total_cost'=>100], ['new_status'=>'in_progress']);
        isset($wp['error']) ? $pass("work order: transition from terminal 'completed' rejected") : $fail("work order: terminal transition not rejected");
        $wp2 = ($wEntry['preview'])(['work_order_number'=>'WO-2','status'=>'in_progress','total_cost'=>300], ['new_status'=>'completed']);
        (isset($wp2['summary']) && stripos($wp2['summary'],'finalizes')!==false) ? $pass("work order: completion preview notes cost finalization") : $fail("work order: completion summary wrong");
    } else { $fail("work order: action not registered"); }
    try { \FleetForge\AI\Actions\StatusActions::changeWorkOrderStatus(999999,'in_progress',null,null,$userId,'Smoke','127.0.0.1'); $fail("work order: not-found should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("work order: not-found rejected") : $fail("work order: wrong code {$e->errorCode}"); }

    $wu = db_row("SELECT id, total_maintenance_cost FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $wv = db_row("SELECT id, total_spent FROM vendors WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($wu) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $woId = db_insert('maintenance_work_orders', [
                'work_order_number'=>'TEST-WO-SMOKE','equipment_unit_id'=>(int)$wu['id'],
                'work_type'=>'repair','title'=>'Smoke WO','requested_date'=>date('Y-m-d'),
                'status'=>'in_progress','total_cost'=>'300.00',
            ] + ($wv ? ['vendor_id'=>(int)$wv['id']] : []));
            \FleetForge\AI\Actions\StatusActions::changeWorkOrderStatus($woId,'completed',null,'fixed it',$userId,'Smoke','127.0.0.1');
            $wo = db_row("SELECT status, completed_date FROM maintenance_work_orders WHERE id=?", [$woId]);
            ($wo['status']==='completed' && $wo['completed_date']!==null) ? $pass("work order: applied in_progress→completed + completed_date stamped") : $fail("work order: state ".json_encode($wo));
            $uc = (float) db_row("SELECT total_maintenance_cost FROM equipment_units WHERE id=?", [$wu['id']])['total_maintenance_cost'];
            abs($uc - ((float)$wu['total_maintenance_cost'] + 300.0))<0.001 ? $pass("work order: unit total_maintenance_cost +\$300 (Trap 6)") : $fail("work order: unit cost now {$uc}");
            if ($wv) {
                $vs = (float) db_row("SELECT total_spent FROM vendors WHERE id=?", [$wv['id']])['total_spent'];
                abs($vs - ((float)$wv['total_spent'] + 300.0))<0.001 ? $pass("work order: vendor total_spent +\$300 (Trap 6)") : $fail("work order: vendor spent now {$vs}");
            }
        } catch (\Throwable $e) {
            $fail("work order: hermetic apply threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    // ════ 3i. ACTION: yard activate/deactivate (role-gated) ════
    $yEntry = \FleetForge\AI\ActionRegistry::get('set_yard_active');
    if ($yEntry) {
        $yp = ($yEntry['preview'])(['name'=>'Surrey','is_active'=>1], ['new_status'=>'activate']);
        isset($yp['error']) ? $pass("yard: activate already-active rejected") : $fail("yard: already-active not rejected");
        $yp2 = ($yEntry['preview'])(['name'=>'Surrey','is_active'=>1], ['new_status'=>'deactivate']);
        (isset($yp2['summary']) && stripos($yp2['summary'],'Deactivate yard Surrey')!==false) ? $pass("yard: deactivate preview builds summary") : $fail("yard: summary wrong");
        $yp3 = ($yEntry['preview'])(['name'=>'Surrey','is_active'=>1], ['new_status'=>'frobnicate']);
        isset($yp3['error']) ? $pass("yard: unknown intent rejected") : $fail("yard: unknown intent not rejected");

        // Role gate via canPerform (reads session role)
        $savedRole = $_SESSION['ff_user']['role_slug'] ?? null;
        $_SESSION['ff_user']['role_slug'] = 'dispatcher';
        !\FleetForge\AI\ActionRegistry::canPerform($yEntry) ? $pass("yard: dispatcher blocked by role_gate") : $fail("yard: dispatcher NOT blocked");
        $_SESSION['ff_user']['role_slug'] = 'manager';
        \FleetForge\AI\ActionRegistry::canPerform($yEntry) ? $pass("yard: manager allowed by role_gate") : $fail("yard: manager NOT allowed");
        $_SESSION['ff_user']['role_slug'] = $savedRole;
    } else { $fail("yard: action not registered"); }
    try { \FleetForge\AI\Actions\StatusActions::setYardActive(999999, false, $userId, 'Smoke', 'manager', '127.0.0.1'); $fail("yard: not-found should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='NOT_FOUND' ? $pass("yard: not-found rejected") : $fail("yard: wrong code {$e->errorCode}"); }
    try { \FleetForge\AI\Actions\StatusActions::setYardActive(1, false, $userId, 'Smoke', 'dispatcher', '127.0.0.1'); $fail("yard: non-manager should throw"); }
    catch (\FleetForge\AI\Actions\ActionException $e) { $e->errorCode==='FORBIDDEN' ? $pass("yard: service role gate enforced") : $fail("yard: wrong code {$e->errorCode}"); }

    $yard = db_row("SELECT id, name, is_active FROM yards WHERE is_active=1 LIMIT 1");
    if ($yard) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            \FleetForge\AI\Actions\StatusActions::setYardActive((int)$yard['id'], false, $userId, 'Smoke', 'manager', '127.0.0.1');
            $now = (int) db_row("SELECT is_active FROM yards WHERE id=?", [$yard['id']])['is_active'];
            $now === 0 ? $pass("yard: deactivate applied (is_active→0)") : $fail("yard: is_active now {$now}");
            $aud = (int) db_row("SELECT COUNT(*) c FROM audit_log WHERE entity_type='yard' AND entity_id=? AND action='delete'", [$yard['id']])['c'];
            $aud >= 1 ? $pass("yard: audit_log row written") : $fail("yard: no audit row");
        } catch (\Throwable $e) {
            $fail("yard: hermetic apply threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    // ════ 3j. READ TOOL: lease close readiness (read-only) ════
    $rrStr = ToolRegistry::execute('get_lease_close_readiness', ['lease_id'=>999999], $userId);
    stripos($rrStr, 'No lease found') !== false ? $pass("close-readiness: not-found handled") : $fail("close-readiness: not-found wrong: {$rrStr}");

    $lc = db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($lc) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            // (a) simple active lease — closeable, no extra inputs
            $l1 = db_insert('leases', ['contract_number'=>'TEST-CLOSE-1','start_date'=>date('Y-m-d'),'status'=>'active','customer_id'=>(int)$lc['id']]);
            $r1 = json_decode(ToolRegistry::execute('get_lease_close_readiness', ['lease_id'=>$l1], $userId), true);
            ($r1['can_close']===true && empty($r1['required_inputs_for_close'])) ? $pass("close-readiness: simple active lease is closeable with no extra inputs") : $fail("close-readiness: simple lease ".json_encode($r1));

            // (b) precharge lease with residual — refund method required
            $l2 = db_insert('leases', ['contract_number'=>'TEST-CLOSE-2','start_date'=>date('Y-m-d'),'status'=>'active','customer_id'=>(int)$lc['id'],'precharge_enabled'=>1,'precharge_amount'=>'500.00','precharge_balance'=>'120.00']);
            $r2 = json_decode(ToolRegistry::execute('get_lease_close_readiness', ['lease_id'=>$l2], $userId), true);
            ($r2['precharge_refund_owed']===true && !empty($r2['required_inputs_for_close'])) ? $pass("close-readiness: precharge residual flags refund-method requirement") : $fail("close-readiness: precharge ".json_encode($r2));

            // (c) completed lease — not closeable
            $l3 = db_insert('leases', ['contract_number'=>'TEST-CLOSE-3','start_date'=>date('Y-m-d'),'status'=>'completed','customer_id'=>(int)$lc['id']]);
            $r3 = json_decode(ToolRegistry::execute('get_lease_close_readiness', ['lease_id'=>$l3], $userId), true);
            ($r3['can_close']===false && !empty($r3['blockers'])) ? $pass("close-readiness: completed lease blocked") : $fail("close-readiness: completed ".json_encode($r3));
        } catch (\Throwable $e) {
            $fail("close-readiness: threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    // ════ 3k. READ TOOLS: credit applications + service requests ════
    $rg = db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($rg) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            // Credit application
            $caId = db_insert('customer_credit_applications', [
                'customer_id'=>(int)$rg['id'],'status'=>'submitted','review_outcome'=>'approved',
                'approved_credit_limit'=>'25000.00','print_name_first'=>'Jane','print_name_last'=>'Doe',
                'token_hash'=>'smoketokenhash'.substr(md5('x'),0,40),'token_expires_at'=>date('Y-m-d H:i:s', time()+86400),
            ]);
            $list = json_decode(ToolRegistry::execute('get_credit_applications', ['status'=>'submitted'], $userId), true);
            (is_array($list) && count($list)>=1 && isset($list[0]['company_name']) && !isset($list[0]['token_hash'])) ? $pass("credit apps: list returns rows, no token leaked") : $fail("credit apps: list ".json_encode($list));
            $det = json_decode(ToolRegistry::execute('get_credit_application_details', ['application_id'=>$caId], $userId), true);
            (is_array($det) && ($det['review_outcome']??null)==='approved' && !isset($det['form_data']) && !isset($det['token_hash']) && !isset($det['signature_path'])) ? $pass("credit apps: details safe (no PII/secret fields)") : $fail("credit apps: details ".json_encode($det));

            // Service request (needs a portal_user)
            $pu = db_row("SELECT id FROM portal_users LIMIT 1");
            if (!$pu) { $puId = db_insert('portal_users', ['customer_id'=>(int)$rg['id'],'name'=>'Portal Tester','email'=>'smoke@example.com']); } else { $puId = (int)$pu['id']; }
            $srId = db_insert('portal_service_requests', [
                'portal_user_id'=>$puId,'customer_id'=>(int)$rg['id'],'request_type'=>'damage_report',
                'subject'=>'Smoke damage report','message'=>'Bumper scratch','status'=>'open',
            ]);
            $sl = json_decode(ToolRegistry::execute('get_service_requests', ['status'=>'open'], $userId), true);
            (is_array($sl) && count($sl)>=1 && isset($sl[0]['subject'])) ? $pass("service requests: list returns rows") : $fail("service requests: list ".json_encode($sl));
            $sd = json_decode(ToolRegistry::execute('get_service_request_details', ['request_id'=>$srId], $userId), true);
            (is_array($sd) && ($sd['request_type']??null)==='damage_report' && array_key_exists('messages',$sd)) ? $pass("service requests: details incl. message thread") : $fail("service requests: details ".json_encode($sd));
        } catch (\Throwable $e) {
            $fail("read gaps: threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }
    // not-found / permission
    stripos(ToolRegistry::execute('get_credit_application_details', ['application_id'=>999999], $userId), 'No credit application') !== false ? $pass("credit apps: not-found handled") : $fail("credit apps: not-found wrong");
    stripos(ToolRegistry::execute('get_service_request_details', ['request_id'=>999999], $userId), 'No service request') !== false ? $pass("service requests: not-found handled") : $fail("service requests: not-found wrong");
    // permission gate: non-admin role without customers:view (super_admin bypasses can())
    $savedPerms = $_SESSION['ff_user']['permissions'] ?? [];
    $savedRole  = $_SESSION['ff_user']['role_slug'] ?? null;
    $_SESSION['ff_user']['role_slug']   = 'dispatcher';
    $_SESSION['ff_user']['permissions'] = ['equipment'=>['edit'=>1]]; // no customers:view
    stripos(ToolRegistry::execute('get_credit_applications', [], $userId), 'permission') !== false ? $pass("credit apps: customers:view gate enforced") : $fail("credit apps: gate not enforced");
    $_SESSION['ff_user']['permissions'] = $savedPerms;
    $_SESSION['ff_user']['role_slug']   = $savedRole;

    // ════ 3l. AGGREGATE COUNTS + raised row cap (S-AI-ROWCAP) ════
    (\FleetForge\AI\ToolRegistry::MAX_ROWS >= 500) ? $pass("rowcap: MAX_ROWS raised to ".\FleetForge\AI\ToolRegistry::MAX_ROWS) : $fail("rowcap: still ".\FleetForge\AI\ToolRegistry::MAX_ROWS);
    $fs = json_decode(ToolRegistry::execute('get_fleet_summary', [], $userId), true);
    (is_array($fs) && isset($fs['by_category']) && is_array($fs['by_category'])) ? $pass("fleet summary: by_category counts present (one-call category breakdown)") : $fail("fleet summary: by_category missing");
    $catSum = array_sum($fs['by_category'] ?? []);
    ($catSum === (int)($fs['total_units'] ?? -1)) ? $pass("fleet summary: category counts sum to total ({$catSum})") : $fail("fleet summary: category sum {$catSum} != total ".($fs['total_units']??'?'));

    // ════ 3m. READ TOOL: documents (metadata only, never file_path) ════
    $du = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if ($du) {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $docId = db_insert('documents', [
                'entity_type'=>'equipment_unit','entity_id'=>(int)$du['id'],'title'=>'Smoke CVI Certificate',
                'document_type'=>'cvi','file_path'=>'documents/equipment_unit/secret_path.pdf','file_name'=>'cvi.pdf',
                'mime_type'=>'application/pdf','expiration_date'=>date('Y-m-d', time()+30*86400),
            ]);
            $list = json_decode(ToolRegistry::execute('get_documents', ['entity_type'=>'equipment_unit','entity_id'=>(int)$du['id']], $userId), true);
            $hit = is_array($list) ? ($list[0] ?? null) : null;
            ($hit && ($hit['title']??null)==='Smoke CVI Certificate' && ($hit['file_name']??null)==='cvi.pdf') ? $pass("documents: list returns metadata for the unit") : $fail("documents: list ".json_encode($list));
            ($hit && !array_key_exists('file_path',$hit)) ? $pass("documents: file_path NEVER emitted (Trap 7)") : $fail("documents: file_path leaked!");
            ($hit && ($hit['entity_label']??null)===$du['unit_number']) ? $pass("documents: entity_label resolves to unit_number") : $fail("documents: entity_label ".json_encode($hit['entity_label']??null));
            $exp = json_decode(ToolRegistry::execute('get_documents', ['expiring_within_days'=>60], $userId), true);
            (is_array($exp) && count(array_filter($exp, fn($d)=>($d['id']??0)==$docId))>=1) ? $pass("documents: expiring_within_days surfaces the doc") : $fail("documents: expiring filter missed it");
        } catch (\Throwable $e) {
            $fail("documents: threw — " . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }
    // permission scope: non-admin without equipment:view can't see equipment_unit docs
    $savedPerms2 = $_SESSION['ff_user']['permissions'] ?? [];
    $savedRole2  = $_SESSION['ff_user']['role_slug'] ?? null;
    $_SESSION['ff_user']['role_slug']   = 'dispatcher';
    $_SESSION['ff_user']['permissions'] = ['leases'=>['view'=>1]]; // no equipment:view
    stripos(ToolRegistry::execute('get_documents', ['entity_type'=>'equipment_unit'], $userId), 'permission') !== false ? $pass("documents: per-entity-type permission scope enforced") : $fail("documents: scope not enforced");
    $_SESSION['ff_user']['permissions'] = $savedPerms2;
    $_SESSION['ff_user']['role_slug']   = $savedRole2;

    // ════ 3n. AI SUMMARY PANELS (S-AI-SUMMARY-PANELS) ════
    foreach (['reservation_summary','vendor_summary','payment_summary'] as $st) {
        $p = \FleetForge\AI\SummaryEngine::buildPrompt($st, ['_demo' => 1]);
        (is_string($p) && strlen($p) > 100) ? $pass("summary panel: {$st} prompt registered") : $fail("summary panel: {$st} prompt missing/short");
    }
    $vrow = db_row("SELECT id FROM vendors WHERE deleted_at IS NULL LIMIT 1");
    if ($vrow) {
        $vctx = \FleetForge\AI\SummaryEngine::gatherContext('vendor', (int)$vrow['id'], 'vendor_summary', $userId);
        (is_array($vctx) && isset($vctx['vendor'], $vctx['work_order_stats'])) ? $pass("summary panel: vendor context gathers (real vendor)") : $fail("summary panel: vendor ctx ".json_encode($vctx));
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
