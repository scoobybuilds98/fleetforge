<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_fa_sync_note.php
 *
 * Smoke for F17 — QBO sync hint on fixed-asset posting surfaces. Structural
 * guards so a page can't silently drop the note partial.
 *
 *   C1 partial exists + lints
 *   C2 depreciation/index.php wires it (context 'depreciation')
 *   C3 impairment/index.php wires it (context 'impairment')
 *   C4 fixed-assets/show.php wires it (context 'fixed-asset')
 *   C5 partial renders without fatal
 *
 * @session F17 (S-QBO-FA-SYNC-NOTE)
 */

require_once __DIR__ . '/../api/bootstrap.php';

$pass = 0; $total = 5; $failures = [];
$root = dirname(__DIR__);

$partial = $root . '/includes/partials/qbo-fa-sync-note.php';
$lint = is_file($partial) ? trim((string) shell_exec('php -l ' . escapeshellarg($partial) . ' 2>&1')) : 'missing';
if (is_file($partial) && strpos($lint, 'No syntax errors') !== false) { echo "PASS C1 partial exists + lints\n"; $pass++; }
else { echo "FAIL C1 {$lint}\n"; $failures[] = 'C1'; }

$pages = [
    'C2' => ['app/admin/accounting/depreciation/index.php', "\$qboFaNote = 'depreciation'"],
    'C3' => ['app/admin/accounting/impairment/index.php',   "\$qboFaNote = 'impairment'"],
    'C4' => ['app/admin/accounting/fixed-assets/show.php',  "\$qboFaNote = 'fixed-asset'"],
];
foreach ($pages as $label => [$rel, $ctx]) {
    $src = is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';
    if (strpos($src, 'qbo-fa-sync-note.php') !== false && strpos($src, $ctx) !== false) {
        echo "PASS {$label} " . basename(dirname($rel)) . "/" . basename($rel) . " wires note\n"; $pass++;
    } else { echo "FAIL {$label} {$rel} missing note/context\n"; $failures[] = $label; }
}

try {
    $qboFaNote = 'impairment';
    ob_start(); require $partial; ob_get_clean();
    echo "PASS C5 partial renders without fatal\n"; $pass++;
} catch (\Throwable $e) {
    @ob_end_clean(); echo "FAIL C5 " . $e->getMessage() . "\n"; $failures[] = 'C5';
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_fa_sync_note_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass === $total ? 0 : 1);
