<?php
declare(strict_types=1);

/**
 * tests/_smoke_bootstrap_boot.php
 *
 * S-FIX-FLEETFORGE-C — boot-the-bootstrap smoke. Catches the "compile fatal"
 * class that php -l CANNOT: a function declared in two procedural files (or
 * twice in one), which only dies at RUNTIME with "Cannot redeclare ...".
 * (FLEETFORGE-C itself was a transient opcache deploy-window redeclare with NO
 * literal duplicate — fixed by the maintenance gate in bin/deploy.sh. This
 * smoke is the standing guard so a *real* duplicate can never ship silently.)
 *
 * Two layers:
 *   PART 1 (static): scan every procedural function declaration (`^function …`,
 *     column 0 → not a class method) across includes/ + config/ and assert each
 *     name is declared exactly once across the whole bootstrap. A duplicate name
 *     in two files would redeclare-fatal the moment both load in one request.
 *   PART 2 (runtime): actually boot config/app.php (which require_once's the full
 *     function set) + the global help-drawer partial, and call help_button() —
 *     fails on any fatal/redeclare/undefined-function at load time.
 *
 * @session S-FIX-FLEETFORGE-C
 */

$root = dirname(__DIR__);

$failures = [];
$pass     = 0;
$total    = 3;

// ──────────────────────────────────────────────────────────────
// Boot the real bootstrap FIRST — before any output — so config/app.php's
// session ini_set() calls don't warn about "headers already sent". A redeclare
// in the bootstrap chain aborts the process here (caught as non-zero exit +
// missing PASS lines). PART 2 below asserts what booted.
// ──────────────────────────────────────────────────────────────
require_once $root . '/config/app.php';   // pulls functions.php, helpers.php, db.php, auth.php …
$bootedHelpButton = function_exists('help_button');

ob_start();
require_once $root . '/includes/partials/help-drawer.php';   // pulled into every page via footer.php
$btn = $bootedHelpButton && function_exists('help_button') ? help_button('customers') : '';
ob_end_clean();

// ──────────────────────────────────────────────────────────────
// PART 1 — static cross-file duplicate procedural-function scan
// ──────────────────────────────────────────────────────────────
$scanDirs = [$root . '/includes', $root . '/config'];
$decls    = [];   // funcName => [ "file:line", ... ]

$phpFiles = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $phpFiles[] = $f->getPathname();
        }
    }
}
sort($phpFiles);

foreach ($phpFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        // Top-level (column 0) `function name(` — procedural decl, redeclare risk.
        // Indented `function` = class method (own scope, never collides).
        if (preg_match('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $m)) {
            $rel = ltrim(str_replace($root, '', $file), '/');
            $decls[$m[1]][] = $rel . ':' . ($i + 1);
        }
    }
}

$dupes = array_filter($decls, static fn($locs) => count($locs) > 1);
if (empty($dupes)) {
    echo "PASS P1 no duplicate procedural function declarations across includes/ + config/ (" . count($decls) . " unique)\n";
    $pass++;
} else {
    foreach ($dupes as $name => $locs) {
        echo "FAIL P1 '{$name}()' declared " . count($locs) . "× → " . implode(', ', $locs) . "\n";
    }
    $failures[] = 'P1';
}

// ──────────────────────────────────────────────────────────────
// PART 2 — assert the runtime boot (performed above) was clean
// ──────────────────────────────────────────────────────────────
if ($bootedHelpButton) {
    echo "PASS P2a config/app.php booted; help_button() declared once (no redeclare)\n";
    $pass++;
} else {
    echo "FAIL P2a help_button() not defined after booting config/app.php\n";
    $failures[] = 'P2a';
}

if ($btn !== '' && str_contains($btn, 'ff-help-drawer')) {
    echo "PASS P2b help-drawer partial + help_button() render clean (no fatal)\n";
    $pass++;
} else {
    echo "FAIL P2b help-drawer partial / help_button() did not render as expected\n";
    $failures[] = 'P2b';
}

// ──────────────────────────────────────────────────────────────
if (!empty($failures)) {
    echo "\nbootstrap_boot_smoke: {$pass}/{$total} PASS — failures: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\nbootstrap_boot_smoke: {$pass}/{$total} PASS\n";
exit(0);
