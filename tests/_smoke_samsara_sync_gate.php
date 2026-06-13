<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_sync_gate.php
 *
 * WAVE 4 [08] LOW — api/v1/samsara/sync.php was a state-changing GET gated on
 * equipment:view.
 *
 * sync.php writes equipment_units.samsara_* + inserts samsara_location_history,
 * but accepted GET and required only equipment:view — so a read-only user, or a
 * CSRF GET (<img src=…/sync>), could trigger a telemetry write. Every other
 * Samsara mutating endpoint (link/unlink/import) is POST + equipment:edit.
 *
 * Fix: POST-only (CSRF-protected by api/bootstrap.php) + equipment:edit; route
 * sync-one vs sync-all on the presence of equipment_unit_id in the body, not on
 * the HTTP method. The sync-all client (tracking dashboard) now POSTs.
 *
 * Source-level guard (matches the proposed selector-endpoints-style assertion):
 *   1. sync.php require_method is POST-only (no 'GET').
 *   2. sync.php require_permission is equipment:edit.
 *   3. no app/public client reaches the sync endpoint over GET.
 *   4. sync.php no longer dispatches on REQUEST_METHOD (routes on payload).
 *
 * PRE-FIX  : 1, 2, (3 for the dashboard), 4 fail.
 * POST-FIX : all pass.
 *
 * Run:  php tests/_smoke_samsara_sync_gate.php   Exit 0/1.
 *
 * @session WAVE-4-SAMSARA-SYNC-GATE
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [08] SAMSARA SYNC GATE — POST + equipment:edit, CSRF-safe\n";
echo str_repeat('─', 72) . "\n";

$src = (string) file_get_contents($ROOT . '/api/v1/samsara/sync.php');

// ── CHECK 1: require_method is POST-only ────────────────────────────────────
if (preg_match('/require_method\s*\(([^)]*)\)/', $src, $m)) {
    $methods = strtoupper($m[1]);
    if (str_contains($methods, 'POST') && !str_contains($methods, 'GET')) {
        $pass("1 method — require_method('POST') only (GET no longer accepted)");
    } else {
        $fail("1 method — require_method still accepts: " . trim($m[1]));
    }
} else {
    $fail("1 method — no require_method() call found");
}

// ── CHECK 2: permission raised to equipment:edit ────────────────────────────
if (preg_match("/require_permission\s*\(\s*'equipment'\s*,\s*'edit'\s*\)/", $src)) {
    $pass("2 permission — require_permission('equipment','edit') (was 'view')");
} else {
    $hasView = (bool) preg_match("/require_permission\s*\(\s*'equipment'\s*,\s*'view'\s*\)/", $src);
    $fail("2 permission — not gated on equipment:edit" . ($hasView ? " (still 'view')" : ""));
}

// ── CHECK 3: no client reaches the sync endpoint via GET ────────────────────
$getCallers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $c = (string) file_get_contents($f->getPathname());
    // FF_Api.get(...) whose argument mentions the sync endpoint
    if (preg_match('/FF_Api\.get\(\s*[^)]*sync/i', $c) && str_contains($c, 'samsara/sync')) {
        $getCallers[] = str_replace($ROOT . '/', '', $f->getPathname());
    }
}
if (!$getCallers) {
    $pass("3 callers — no app client invokes samsara/sync over GET");
} else {
    $fail("3 callers — GET caller(s) remain: " . implode(', ', $getCallers));
}

// ── CHECK 4: dispatch no longer keys on REQUEST_METHOD ──────────────────────
if (!preg_match('/\$method\s*===\s*[\'"]POST[\'"]/', $src) && !str_contains($src, "REQUEST_METHOD']") ) {
    $pass("4 dispatch — routes on equipment_unit_id payload, not HTTP method");
} else {
    $fail("4 dispatch — still branches on REQUEST_METHOD / \$method");
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("SAMSARA SYNC GATE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
