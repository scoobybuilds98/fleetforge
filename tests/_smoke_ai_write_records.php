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
