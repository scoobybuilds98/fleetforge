<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bill_itc_tax_rate.php
 *
 * Smoke for F9 — ITC tax-rate mapping (gated per-rate bill-tax emission). The
 * crux: override mode (default) is BYTE-IDENTICAL to pre-F9, and per_rate emits
 * TaxLine[] only when mapped, falling back to override on any unmapped non-zero
 * component (never ships a wrong tax detail).
 *
 *   C1 surfaces (buildBillTaxDetail/qboTaxRateId) + acc_qbo_tax_rate_map schema + tax_mode setting
 *   C2 override mode → {TotalTax}, NO TaxLine (proven path untouched)
 *   C3 per_rate + all non-zero mapped → TaxLine[] per component + TotalTax
 *   C4 per_rate + a non-zero component UNMAPPED → falls back to override
 *   C5 per_rate + zero tax → plain TotalTax (no TaxLine)
 *   C6 qboTaxRateId mapped→id / unmapped→null
 *   C7 save_tax_rate_map endpoint (gate+validation) + tax_codes.php hosts the section
 *
 * Snapshot-restores the gst/pst/hst map rows + bill.tax_mode. No bill seeded —
 * buildBillTaxDetail takes a literal $ff array.
 *
 * @session S-QBO-BILL-ITC-TAX-RATE (F9)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\BillPusher;

$pass = 0; $total = 7; $failures = [];
$root = dirname(__DIR__);

function ff_itc_set_mode(string $m): void {
    db_execute("INSERT INTO settings (`key`,`value`,`value_type`,`group_name`,`is_public`,`is_sensitive`) VALUES ('quickbooks.bill.tax_mode',?,'string','quickbooks',0,0) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$m]);
}
function ff_itc_map(string $comp, ?string $id): void {
    db_execute("DELETE FROM acc_qbo_tax_rate_map WHERE ff_tax_component=?", [$comp]);
    db_execute("INSERT INTO acc_qbo_tax_rate_map (ff_tax_component, qbo_tax_rate_id, mapping_status) VALUES (?,?,?)",
        [$comp, $id, $id !== null ? 'mapped' : 'unmapped']);
}
function ff_itc_clear(): void { db_execute("DELETE FROM acc_qbo_tax_rate_map WHERE ff_tax_component IN ('gst','pst','hst')"); }

// Snapshot.
$modeBefore = (function(){ $r=db_row("SELECT `value` FROM settings WHERE `key`='quickbooks.bill.tax_mode'"); return $r['value']??null; })();
$rowsBefore = db_select("SELECT ff_tax_component, qbo_tax_rate_id, qbo_tax_rate_name, qbo_tax_percent, mapping_status FROM acc_qbo_tax_rate_map WHERE ff_tax_component IN ('gst','pst','hst')");

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-BILL-ITC-TAX-RATE Smoke ({$total} sub-checks; F9)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_itc_clear();

    // C1 — surfaces + schema.
    $cols = array_column(db_select("SHOW COLUMNS FROM acc_qbo_tax_rate_map"), 'Field');
    $c1 = method_exists(BillPusher::class,'buildBillTaxDetail') && method_exists(BillPusher::class,'qboTaxRateId')
       && in_array('ff_tax_component',$cols,true) && in_array('qbo_tax_rate_id',$cols,true) && in_array('mapping_status',$cols,true);
    if ($c1) { echo "PASS C1 surfaces + acc_qbo_tax_rate_map schema\n"; $pass++; } else { echo "FAIL C1\n"; $failures[]='C1'; }

    // C2 — override default (byte-identical).
    ff_itc_set_mode('override');
    $d2 = BillPusher::buildBillTaxDetail(['id'=>1,'tax_gst_amount'=>'10.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00']);
    if (($d2['TotalTax'] ?? null) === 10.0 && !isset($d2['TaxLine'])) { echo "PASS C2 override → {TotalTax:10.0}, no TaxLine\n"; $pass++; }
    else { echo "FAIL C2 ".json_encode($d2)."\n"; $failures[]='C2'; }

    // C3 — per_rate, gst+hst mapped, pst zero.
    ff_itc_set_mode('per_rate');
    ff_itc_map('gst','TR-GST'); ff_itc_map('hst','TR-HST');
    $d3 = BillPusher::buildBillTaxDetail(['id'=>2,'tax_gst_amount'=>'5.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'13.00']);
    $lines3 = $d3['TaxLine'] ?? [];
    $refs3 = array_map(fn($l)=>$l['TaxLineDetail']['TaxRateRef']['value'] ?? '', $lines3);
    if (($d3['TotalTax'] ?? null) === 18.0 && count($lines3) === 2 && in_array('TR-GST',$refs3,true) && in_array('TR-HST',$refs3,true)) {
        echo "PASS C3 per_rate all-mapped → 2 TaxLines + TotalTax 18.0\n"; $pass++;
    } else { echo "FAIL C3 ".json_encode($d3)."\n"; $failures[]='C3'; }

    // C4 — per_rate, pst non-zero but UNMAPPED → fallback to override.
    ff_itc_clear();
    ff_itc_map('gst','TR-GST'); // pst left unmapped
    $d4 = BillPusher::buildBillTaxDetail(['id'=>3,'tax_gst_amount'=>'5.00','tax_pst_amount'=>'7.00','tax_hst_amount'=>'0.00']);
    if (($d4['TotalTax'] ?? null) === 12.0 && !isset($d4['TaxLine'])) { echo "PASS C4 per_rate unmapped-component → fallback to override\n"; $pass++; }
    else { echo "FAIL C4 ".json_encode($d4)."\n"; $failures[]='C4'; }

    // C5 — per_rate, zero tax → plain TotalTax.
    $d5 = BillPusher::buildBillTaxDetail(['id'=>4,'tax_gst_amount'=>'0.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00']);
    if (($d5['TotalTax'] ?? null) === 0.0 && !isset($d5['TaxLine'])) { echo "PASS C5 per_rate zero-tax → plain TotalTax\n"; $pass++; }
    else { echo "FAIL C5 ".json_encode($d5)."\n"; $failures[]='C5'; }

    // C6 — qboTaxRateId.
    ff_itc_clear();
    ff_itc_map('gst','TR-GST');
    if (BillPusher::qboTaxRateId('gst') === 'TR-GST' && BillPusher::qboTaxRateId('pst') === null) { echo "PASS C6 qboTaxRateId mapped→id / unmapped→null\n"; $pass++; }
    else { echo "FAIL C6\n"; $failures[]='C6'; }

    // C7 — endpoint + UI wiring.
    $ep = $root.'/api/v1/quickbooks/save_tax_rate_map.php';
    $epLint = is_file($ep) ? trim((string)shell_exec('php -l '.escapeshellarg($ep).' 2>&1')) : 'missing';
    $epSrc = is_file($ep) ? (string)file_get_contents($ep) : '';
    $tc = (string)file_get_contents($root.'/app/admin/quickbooks/tax_codes.php');
    $c7 = is_file($ep) && strpos($epLint,'No syntax errors')!==false
       && strpos($epSrc,"require_permission('quickbooks', 'edit_credentials')")!==false
       && strpos($epSrc,'acc_qbo_tax_rate_map')!==false
       && strpos($tc,'qboTaxRateMapping')!==false && strpos($tc,'save_tax_rate_map.php')!==false;
    if ($c7) { echo "PASS C7 save_tax_rate_map endpoint + tax_codes.php section\n"; $pass++; } else { echo "FAIL C7 lint={$epLint}\n"; $failures[]='C7'; }

} finally {
    ff_itc_clear();
    foreach ($rowsBefore as $r) {
        db_execute("INSERT INTO acc_qbo_tax_rate_map (ff_tax_component, qbo_tax_rate_id, qbo_tax_rate_name, qbo_tax_percent, mapping_status) VALUES (?,?,?,?,?)",
            [$r['ff_tax_component'], $r['qbo_tax_rate_id'], $r['qbo_tax_rate_name'], $r['qbo_tax_percent'], $r['mapping_status']]);
    }
    if ($modeBefore === null) { db_execute("DELETE FROM settings WHERE `key`='quickbooks.bill.tax_mode'"); }
    else { ff_itc_set_mode((string)$modeBefore); }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "bill_itc_tax_rate_smoke: {$pass}/{$total} ".($pass===$total?'PASS':'FAIL')."\n";
if (!empty($failures)) { echo "Failed: ".implode(', ',$failures)."\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass===$total?0:1);
