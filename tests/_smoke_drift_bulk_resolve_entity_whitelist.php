<?php
declare(strict_types=1);

/**
 * tests/_smoke_drift_bulk_resolve_entity_whitelist.php
 *
 * WAVE 4 [01e] LOW — drift_bulk_resolve entity_type whitelist drifted from DriftChecker.
 *
 * DriftChecker emits balance_drift events with entity_type='gl_account'
 * (checkGlAccountBalances), but api/v1/quickbooks/drift_bulk_resolve.php's
 * $allowedEntityTypes omitted gl_account — so an operator could create those
 * events but never bulk-resolve them scoped by entity (422). Fixed by adding
 * gl_account to the whitelist.
 *
 * Contract guard (derives the producer set from DriftChecker, not a hand list):
 *   1. every DriftChecker entity_type — ENTITY_CHECKS keys ∪ {gl_account} — is
 *      present in the endpoint's $allowedEntityTypes. Durable: a new producer
 *      entity_type that the bulk endpoint can't resolve re-fails this.
 *
 * PRE-FIX  : gl_account is produced but not whitelisted → FAIL.
 * POST-FIX : pass.
 *
 * Run:  php tests/_smoke_drift_bulk_resolve_entity_whitelist.php   Exit 0/1.
 *
 * @session WAVE-4-DRIFT-BULK-ENTITY-WHITELIST
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\QboPushers\DriftChecker;

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [01e] DRIFT BULK RESOLVE — entity_type whitelist ⊇ DriftChecker output\n";
echo str_repeat('─', 72) . "\n";

// Producer set: every entity DriftChecker can emit a drift row for.
// = ENTITY_CHECKS keys (the per-entity scans) ∪ {gl_account} (the GL balance scan).
$produced = array_keys(DriftChecker::ENTITY_CHECKS);
$produced[] = 'gl_account';
$produced = array_values(array_unique($produced));

// Extract the endpoint's $allowedEntityTypes array literal.
$src = (string) file_get_contents($ROOT . '/api/v1/quickbooks/drift_bulk_resolve.php');
$allowed = [];
if (preg_match('/\$allowedEntityTypes\s*=\s*\[(.*?)\];/s', $src, $m)) {
    if (preg_match_all("/'([a-z_]+)'/", $m[1], $mm)) {
        $allowed = $mm[1];
    }
}

if (!$allowed) {
    $fail("could not parse \$allowedEntityTypes from drift_bulk_resolve.php");
} else {
    $missing = array_values(array_diff($produced, $allowed));
    if (!$missing) {
        $pass("1 whitelist — all " . count($produced) . " DriftChecker entity_types are bulk-resolvable");
    } else {
        $fail("1 whitelist — produced but NOT bulk-resolvable: " . implode(', ', $missing));
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DRIFT BULK RESOLVE ENTITY WHITELIST — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
