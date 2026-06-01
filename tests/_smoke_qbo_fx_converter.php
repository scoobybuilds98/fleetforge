<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_fx_converter.php
 *
 * Smoke for F15 — multi-currency display (FxConverter + bank-transaction badge).
 * Tests the deterministic conversion core + the UI wiring. The deferred live
 * "revaluation as rates change" needs a live FX feed (verify-at-cutover) and
 * is out of scope.
 *
 *   C1 FxConverter surfaces
 *   C2 homeCurrency reads quickbooks.home_currency
 *   C3 isForeign: USD true / CAD false / null false / '' false
 *   C4 homeEquivalent: positive rate computes; null/''/0/negative → null
 *   C5 homeEquivalentLabel: foreign+rate → "≈ CAD X"; home → ''; foreign+no-rate → ''
 *   C6 bank-transactions/index.php wires FxConverter + the map snapshot join
 *
 * @session F15 (S-QBO-FX-RECON-FOLLOWUP)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\FxConverter;

$pass = 0; $total = 6; $failures = [];
$root = dirname(__DIR__);

$homeBefore = (function () { $r = db_row("SELECT `value` FROM settings WHERE `key`='quickbooks.home_currency'"); return $r['value'] ?? null; })();
function ff_fx_set_home(?string $v): void {
    if ($v === null) { db_execute("DELETE FROM settings WHERE `key`='quickbooks.home_currency'"); return; }
    db_execute("INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES ('quickbooks.home_currency',?,'string','quickbooks',0,0) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$v]);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-FX-RECON-FOLLOWUP Smoke ({$total} sub-checks; F15)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_fx_set_home('CAD');

    $c1 = method_exists(FxConverter::class,'homeCurrency') && method_exists(FxConverter::class,'isForeign')
       && method_exists(FxConverter::class,'homeEquivalent') && method_exists(FxConverter::class,'homeEquivalentLabel');
    if ($c1) { echo "PASS C1 FxConverter surfaces\n"; $pass++; } else { echo "FAIL C1\n"; $failures[]='C1'; }

    ff_fx_set_home('USD');
    $c2 = FxConverter::homeCurrency() === 'USD';
    ff_fx_set_home('CAD');
    if ($c2 && FxConverter::homeCurrency() === 'CAD') { echo "PASS C2 homeCurrency reads setting\n"; $pass++; } else { echo "FAIL C2\n"; $failures[]='C2'; }

    $c3 = FxConverter::isForeign('USD')===true && FxConverter::isForeign('CAD')===false
       && FxConverter::isForeign(null)===false && FxConverter::isForeign('')===false;
    if ($c3) { echo "PASS C3 isForeign\n"; $pass++; } else { echo "FAIL C3\n"; $failures[]='C3'; }

    $c4 = FxConverter::homeEquivalent('100.00','1.350000')==='135.00'
       && FxConverter::homeEquivalent('100.00',null)===null
       && FxConverter::homeEquivalent('100.00','')===null
       && FxConverter::homeEquivalent('100.00','0')===null
       && FxConverter::homeEquivalent('100.00','-1.5')===null;
    if ($c4) { echo "PASS C4 homeEquivalent math + null cases\n"; $pass++; } else { echo "FAIL C4\n"; $failures[]='C4'; }

    $lblForeign = FxConverter::homeEquivalentLabel('100.00','USD','1.350000');
    $lblHome    = FxConverter::homeEquivalentLabel('100.00','CAD','1.350000');
    $lblNoRate  = FxConverter::homeEquivalentLabel('100.00','USD',null);
    $c5 = strpos($lblForeign,'CAD')!==false && strpos($lblForeign,'135.00')!==false
       && $lblHome==='' && $lblNoRate==='';
    if ($c5) { echo "PASS C5 homeEquivalentLabel (foreign / home / no-rate)\n"; $pass++; } else { echo "FAIL C5 f={$lblForeign} h=[{$lblHome}] n=[{$lblNoRate}]\n"; $failures[]='C5'; }

    $idx = (string) file_get_contents($root . '/app/admin/accounting/bank-transactions/index.php');
    $c6 = strpos($idx,'FxConverter')!==false
       && strpos($idx,'qbo_currency_snapshot')!==false
       && strpos($idx,'qbo_exchange_rate_snapshot')!==false
       && strpos($idx,'acc_qbo_bank_transaction_map')!==false;
    if ($c6) { echo "PASS C6 bank-transactions/index wires FxConverter + map join\n"; $pass++; } else { echo "FAIL C6\n"; $failures[]='C6'; }

} finally {
    ff_fx_set_home($homeBefore);
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_fx_converter_smoke: {$pass}/{$total} " . ($pass===$total?'PASS':'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass===$total?0:1);
