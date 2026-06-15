<?php
declare(strict_types=1);

/**
 * tests/_smoke_optimistic_lock_disabled.php
 *
 * Stop-condition smoke for S-DISABLE-OPTLOCK.
 *
 * Operator decision (2026-06-16): last-write-wins app-wide — an edit must NEVER be
 * blocked by a "modified by another user" 409, no matter how many users touch a
 * record. Implemented as a single flag-gated enforcement point: every editor checks
 * `if (!optimistic_lock_matches(...)) { 409 STALE_DATA }`, and optimistic_lock_matches()
 * now returns true whenever FF_OPTIMISTIC_LOCKING is off (the default). So the 409 is
 * universally suppressed without editing 29 call sites, and is reversible by flipping
 * the flag. The pure comparison (optimistic_lock_compare) is preserved + still tested.
 *
 * Business-rule 409s (already-sent/paid, invalid transition, precharge-locked,
 * immutable-record) do NOT route through optimistic_lock_matches() and are untouched.
 *
 * Tests:
 *  T1  php -l clean on touched files
 *  T2  FF_OPTIMISTIC_LOCKING defined and false (locking disabled by default)
 *  T3  optimistic_lock_matches() returns TRUE for a wildly stale token, equal token,
 *      AND malformed input → the gate is universally non-blocking (last-write-wins)
 *  T4  optimistic_lock_compare() (pure algorithm) still correct: stale→false,
 *      equal→true, malformed→false → re-enabling locking would still work
 *  T5  Every editor's lock check routes through optimistic_lock_matches() (so the
 *      flag governs ALL of them); the 3 former raw-equality sites no longer compare
 *      updated_at/created_at with raw !==
 *  T6  Logical completeness — across many stale/real pairs the gate never reports a
 *      conflict (no editor can emit STALE_DATA while the flag is off)
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

$pass = 0; $fail = 0;
function ok(string $t): void  { global $pass; $pass++; echo "  \033[32m✓\033[0m {$t}\n"; }
function bad(string $t, string $d = ''): void { global $fail; $fail++; echo "  \033[31m✗\033[0m {$t}" . ($d ? " — {$d}" : '') . "\n"; }
function section(string $s): void { echo "\n{$s}\n" . str_repeat('─', 70) . "\n"; }

$ROOT = FF_ROOT;
echo "\n════════════════════════════════════════════════════════════════════════\n";
echo "S-DISABLE-OPTLOCK — last-write-wins, no edit ever blocked\n";
echo "════════════════════════════════════════════════════════════════════════\n";

// Every editor whose D19 lock must now be governed by the flag.
$editors = [
    'api/v1/customers/update.php',
    'api/v1/accounting/tax/remittances/create.php',
    'api/v1/accounting/tax/periods/mark_filed.php',
    'api/v1/accounting/categorization-rules/save.php',
    'api/v1/accounting/bills/update.php',
    'api/v1/accounting/accounts/update.php',
    'api/v1/accounting/recurring/update.php',   // was raw !==
    'api/v1/vendors/update.php',
    'api/v1/payments/update.php',
    'api/v1/invoices/update.php',
    'api/v1/reservations/update.php',
    'api/v1/compliance/update.php',
    'api/v1/inspections/update.php',
    'api/v1/leases/update.php',
    'api/v1/leases/amend_rate.php',
    'api/v1/maintenance_work_orders/update.php',
    'api/v1/users/update.php',
    'api/v1/yards/update.php',
    'api/v1/customer_equipment_rates/upsert.php',
    'api/v1/credit_notes/update.php',
    'api/v1/credit_applications/review.php',
    'api/v1/rate_cards/update.php',
    'api/v1/damage_claims/update.php',
    'api/v1/mileage_logs/update.php',           // was raw !==
    'api/v1/equipment/units/update.php',
    'api/v1/equipment/templates/update.php',
    'api/v1/email/templates/update.php',
    'lib/Accounting/FixedAssetService.php',
    'lib/Accounting/BudgetService.php',         // was raw !==
];

// ── T1: syntax ──────────────────────────────────────────────────────────────
section('T1: php -l on touched files (SC1)');
$touched = [
    'includes/db.php', 'config/app.php',
    'api/v1/accounting/recurring/update.php', 'lib/Accounting/BudgetService.php',
    'api/v1/mileage_logs/update.php',
];
$lintOk = true;
foreach ($touched as $f) {
    $out = (string) shell_exec('php -l ' . escapeshellarg("$ROOT/$f") . ' 2>&1');
    if (!str_contains($out, 'No syntax errors')) { bad('T1: ' . $f, trim($out)); $lintOk = false; }
}
if ($lintOk) ok('T1: all ' . count($touched) . ' touched files pass php -l');

// ── T2: flag ────────────────────────────────────────────────────────────────
section('T2: FF_OPTIMISTIC_LOCKING disabled by default');
if (defined('FF_OPTIMISTIC_LOCKING') && FF_OPTIMISTIC_LOCKING === false) {
    ok('T2: FF_OPTIMISTIC_LOCKING defined and false');
} else {
    bad('T2: flag', defined('FF_OPTIMISTIC_LOCKING') ? var_export(FF_OPTIMISTIC_LOCKING, true) : 'undefined');
}

// ── T3: gate is universally non-blocking ────────────────────────────────────
section('T3: optimistic_lock_matches() never reports a conflict (last-write-wins)');
$t3 = optimistic_lock_matches('2020-01-01 00:00:00', '2026-06-16 19:10:57') // wildly stale
   && optimistic_lock_matches('2026-06-16 19:10:57', '2026-06-16 19:10:57') // equal
   && optimistic_lock_matches('not-a-date', '2026-06-16 19:10:57');          // malformed
$t3 ? ok('T3: returns true for stale, equal, AND malformed tokens → no 409 ever')
    : bad('T3: gate still blocking', 'a case returned false while locking is off');

// ── T4: pure comparison still correct (re-enable safety) ────────────────────
section('T4: optimistic_lock_compare() algorithm preserved');
$t4 = (optimistic_lock_compare('2020-01-01 00:00:00', '2026-06-16 19:10:57') === false)
   && (optimistic_lock_compare('2026-06-16 19:10:57', '2026-06-16 19:10:57') === true)
   && (optimistic_lock_compare('garbage', '2026-06-16 19:10:57') === false);
$t4 ? ok('T4: stale→false, equal→true, malformed→false (locking re-enableable)')
    : bad('T4: pure comparison broken');

// ── T5: every editor routes through the gate ────────────────────────────────
section('T5: all ' . count($editors) . ' editors gated by optimistic_lock_matches()');
$missing = []; $rawLeft = [];
foreach ($editors as $rel) {
    $src = @file_get_contents("$ROOT/$rel");
    if ($src === false) { $missing[] = "$rel (unreadable)"; continue; }
    if (!str_contains($src, 'optimistic_lock_matches')) $missing[] = $rel;
    // No raw timestamp-token equality left (the 3 former raw sites + anyone new):
    if (preg_match('/!==\s*\(string\)\s*\$\w+\[\x27(updated_at)\x27\]/', $src)
        || preg_match('/\$\w+\[\x27created_at\x27\]\s*!==\s*\$lockAt/', $src)) {
        $rawLeft[] = $rel;
    }
}
$missing ? bad('T5a: editors NOT routed through the gate', implode(', ', $missing))
         : ok('T5a: all ' . count($editors) . ' editors reference optimistic_lock_matches()');
$rawLeft ? bad('T5b: raw timestamp-token equality still present', implode(', ', $rawLeft))
         : ok('T5b: no raw updated_at/created_at !== comparisons remain');

// ── T6: logical completeness ────────────────────────────────────────────────
section('T6: gate never conflicts across many pairs');
$allTrue = true;
for ($i = 0; $i < 50; $i++) {
    $client = sprintf('20%02d-%02d-%02d 0%d:00:00', $i % 30, ($i % 12) + 1, ($i % 28) + 1, $i % 10);
    if (!optimistic_lock_matches($client, '2026-06-16 12:00:00')) { $allTrue = false; break; }
}
$allTrue ? ok('T6: 50 arbitrary stale tokens → all pass (no editor can 409)')
         : bad('T6: a stale token produced a conflict');

echo "\n" . str_repeat('─', 72) . "\n";
echo "DISABLE OPTLOCK — {$pass} passed, {$fail} failed\n";
echo $fail === 0 ? "\033[32m✓ ALL PASSED\033[0m\n" : "\033[31m✗ {$fail} FAILED\033[0m\n";
echo str_repeat('─', 72) . "\n";
exit($fail === 0 ? 0 : 1);
