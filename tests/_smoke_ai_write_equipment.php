<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_write_equipment.php
 *
 * S-AI-WRITE-1 — Schema-real smoke for the AI propose→confirm→apply→undo
 * pipeline on equipment units.
 *
 * Drives the REAL code paths against the REAL database:
 *   - plan_update_equipment via ToolRegistry::execute() (the same dispatch
 *     the chat tool-loop uses) → persists a pending ai_pending_changes row
 *   - ChangeApplier::apply()  → writes the unit + audit_log + before-snapshot
 *   - ChangeApplier::undo()   → restores the original value + audit_log
 *   - negative paths: invalid value, no-op, feature-flag OFF, bad field
 *
 * Permission gate: planner calls can('equipment','edit'), which reads the
 * session permissions — so we seed $_SESSION['ff_user'] with that grant.
 *
 * Idempotent: captures the target unit's original value and restores it at
 * the end; deletes its own proposal rows. Safe to re-run.
 *
 * Usage: php tests/_smoke_ai_write_equipment.php
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php'; // for can()
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FleetForge\AI\ToolRegistry;
use FleetForge\AI\ChangeApplier;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "S-AI-WRITE-1 — AI equipment write pipeline (plan → apply → undo)\n";
echo str_repeat('─', 72) . "\n";

// ── Setup: real user + real unit, seed an edit-permitted session ────────────
$user = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
$unit = db_row("SELECT id, unit_number, mileage FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$user || !$unit) { echo "SETUP FAIL — need a user and an equipment unit\n"; exit(2); }

$userId   = (int) $user['id'];
$unitNo   = $unit['unit_number'];
$origMile = (int) $unit['mileage'];
$newMile  = $origMile + 4242;

@session_start();
$_SESSION['ff_user'] = [
    'id'          => $userId,
    'name'        => 'Smoke Writer',
    'role_slug'   => 'super_admin',
    'permissions' => ['equipment' => ['edit' => 1, 'view' => 1]],
    'permission_overrides' => [], 'role_permission_overrides' => [],
];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Remember the flag's original value; force it ON for the happy-path tests.
$origFlag = settings_get('ai.write_enabled', false);
$setFlag = static function (string $v): void {
    if (db_row("SELECT id FROM settings WHERE `key`=?", ['ai.write_enabled'])) {
        db_update('settings', ['value' => $v], '`key`=?', ['ai.write_enabled']);
    } else {
        db_insert('settings', ['key' => 'ai.write_enabled', 'value' => $v, 'value_type' => 'boolean', 'group_name' => 'ai']);
    }
};
$setFlag('1');

$createdProposalIds = [];

try {
    // ── 1. Planner persists a pending proposal with the right diff ──────────
    $raw = ToolRegistry::execute('plan_update_equipment',
        ['unit_number' => $unitNo, 'field' => 'mileage', 'new_value' => (string) $newMile], $userId, null);
    $res = json_decode($raw, true);
    if (is_array($res) && !empty($res['requires_confirmation']) && !empty($res['proposal_id'])) {
        $pass("planner returned a confirmation proposal (id={$res['proposal_id']})");
    } else {
        $fail("planner did not return a proposal: {$raw}");
    }
    $pid = (int) ($res['proposal_id'] ?? 0);
    if ($pid) $createdProposalIds[] = $pid;

    $prow = $pid ? db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$pid]) : null;
    if ($prow && $prow['status'] === 'pending' && (int) $prow['user_id'] === $userId) {
        $pass("pending row persisted (status=pending, user_id={$userId})");
    } else {
        $fail("pending row missing or wrong: " . var_export($prow, true));
    }

    // ── 2. Apply writes the unit + audit + before-snapshot ──────────────────
    if ($prow) {
        $out = ChangeApplier::apply($prow, $userId, 'Smoke Writer', '127.0.0.1');
        $after = (int) db_row("SELECT mileage FROM equipment_units WHERE id=?", [$unit['id']])['mileage'];
        if ($after === $newMile && $out['count'] === 1) {
            $pass("apply wrote mileage {$origMile} → {$newMile}");
        } else {
            $fail("apply did not write: mileage now {$after}, expected {$newMile}");
        }
        // before-snapshot must capture the ORIGINAL value for undo
        if (($out['diff'][0]['before'] ?? null) == $origMile && ($out['diff'][0]['after'] ?? null) == $newMile) {
            $pass("before/after snapshot captured for undo");
        } else {
            $fail("snapshot wrong: " . json_encode($out['diff']));
        }
        // audit row written
        $aud = db_row("SELECT new_values FROM audit_log WHERE entity_type='equipment_unit' AND entity_id=? AND action='update' ORDER BY id DESC LIMIT 1", [$unit['id']]);
        if ($aud && str_contains((string) $aud['new_values'], (string) $newMile)) {
            $pass("audit_log row written with new value");
        } else {
            $fail("audit_log row missing/incorrect: " . var_export($aud, true));
        }
        // persist applied_diff so undo can read it (endpoint does this)
        db_update('ai_pending_changes', ['status' => 'applied', 'applied_diff' => json_encode($out['diff']), 'applied_at' => date('Y-m-d H:i:s'), 'applied_by' => $userId], 'id=?', [$pid]);
    }

    // ── 3. Undo restores the original value ─────────────────────────────────
    if ($prow) {
        $prow2 = db_row("SELECT * FROM ai_pending_changes WHERE id=?", [$pid]);
        $n = ChangeApplier::undo($prow2, $userId, 'Smoke Writer', '127.0.0.1');
        $restored = (int) db_row("SELECT mileage FROM equipment_units WHERE id=?", [$unit['id']])['mileage'];
        if ($n === 1 && $restored === $origMile) {
            $pass("undo restored mileage → {$origMile}");
        } else {
            $fail("undo failed: mileage now {$restored}, expected {$origMile}");
        }
    }

    // ── 4. Negative: invalid value rejected, no row created ─────────────────
    $before = (int) db_row("SELECT COUNT(*) c FROM ai_pending_changes")['c'] ?? 0;
    $bad = json_decode(ToolRegistry::execute('plan_update_equipment',
        ['unit_number' => $unitNo, 'field' => 'mileage', 'new_value' => 'not-a-number'], $userId, null), true);
    $afterCount = (int) db_row("SELECT COUNT(*) c FROM ai_pending_changes")['c'] ?? 0;
    if (!isset($bad['requires_confirmation']) && $afterCount === $before) {
        $pass("invalid mileage rejected (no proposal created)");
    } else {
        $fail("invalid mileage was NOT rejected cleanly");
    }

    // ── 5. Negative: no-op (same value) returns a friendly message ──────────
    $noop = ToolRegistry::execute('plan_update_equipment',
        ['unit_number' => $unitNo, 'field' => 'mileage', 'new_value' => (string) $origMile], $userId, null);
    $noopDec = json_decode($noop, true);
    if (!is_array($noopDec) || empty($noopDec['requires_confirmation'])) {
        $pass("no-op change declined (already set)");
    } else {
        $fail("no-op change incorrectly produced a proposal");
    }

    // ── 6. Negative: feature flag OFF disables planning ─────────────────────
    $setFlag('0');
    $off = ToolRegistry::execute('plan_update_equipment',
        ['unit_number' => $unitNo, 'field' => 'mileage', 'new_value' => (string) ($origMile + 7), 'x' => 1], $userId, null);
    $offDec = json_decode($off, true);
    if ((!is_array($offDec) || empty($offDec['requires_confirmation'])) && stripos((string) $off, 'disabled') !== false) {
        $pass("feature flag OFF blocks the planner");
    } else {
        $fail("flag OFF did not block the planner: {$off}");
    }
    $setFlag('1');

    // ── 7. Negative: unknown field rejected ─────────────────────────────────
    $badField = ToolRegistry::execute('plan_update_equipment',
        ['unit_number' => $unitNo, 'field' => 'secret_admin_column', 'new_value' => 'x'], $userId, null);
    if (stripos($badField, 'only change') !== false || stripos($badField, 'can only') !== false) {
        $pass("unknown field rejected by allow-list");
    } else {
        $fail("unknown field NOT rejected: {$badField}");
    }

} finally {
    // ── Cleanup: restore unit + flag, delete this run's proposals ───────────
    db_update('equipment_units', ['mileage' => $origMile], 'id=?', [$unit['id']]);
    foreach ($createdProposalIds as $id) {
        db_execute("DELETE FROM ai_pending_changes WHERE id=?", [$id]);
    }
    // restore the operator's original flag value
    if ($origFlag === null) {
        db_execute("DELETE FROM settings WHERE `key`='ai.write_enabled'");
    } else {
        $setFlag((string) $origFlag);
    }
}

echo str_repeat('─', 72) . "\n";
echo $failures ? "\033[31m" . count($failures) . " FAIL\033[0m / {$passes} pass\n"
              : "\033[32mALL {$passes} CHECKS PASS\033[0m\n";
exit($failures ? 1 : 0);
