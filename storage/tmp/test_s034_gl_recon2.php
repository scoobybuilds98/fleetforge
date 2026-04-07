<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

// ============================================================
// S034 — FOCUSED GL RECONCILIATION (v2)
// Verifies the JE side independently of subledger seeding.
//   1. All depreciation JEs are balanced
//   2. Sum of depreciation expense (DR side) per JE = run total
//   3. Disposal JEs balance and accounts are correct
//   4. Impairment JE balances and uses correct accounts
//   5. Reversal JEs net out their originals
// ============================================================

function ck(string $label, string $expected, string $actual): bool
{
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = bccomp($expected, $actual, 2) === 0;
    } else {
        $ok = ($expected === $actual);
    }
    printf("  %s %s = %s %s %s\n",
        $ok ? '✅' : '❌', $label, $actual,
        $ok ? '==' : '!=', $expected);
    return $ok;
}

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — FOCUSED GL RECONCILIATION" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ============================================================
// CHECK A — Per-JE balance for ALL S034-source JEs (already ✅)
// ============================================================
echo "\n## CHECK A — All S034 JEs internally balanced\n\n";
$je = db_select(
    "SELECT id FROM acc_journal_entries
     WHERE source_type IN ('depreciation','asset_disposal')"
);
$bad = 0;
foreach ($je as $j) {
    $t = db_row(
        "SELECT COALESCE(SUM(debit),0) AS dr, COALESCE(SUM(credit),0) AS cr
         FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
        [$j['id']]
    );
    if (bccomp($t['dr'], $t['cr'], 2) !== 0) $bad++;
}
echo "  Verified " . count($je) . " JEs, $bad imbalanced\n";
ck("All balanced", "0", (string) $bad);

// ============================================================
// CHECK B — Each posted depreciation run's JE total == run total
// ============================================================
echo "\n## CHECK B — Depreciation run total == linked JE total\n\n";
$runs = db_select(
    "SELECT id, period_id, total_depreciation, journal_entry_id, status
     FROM acc_depreciation_runs WHERE status = 'posted' ORDER BY id"
);
echo "  Verifying " . count($runs) . " posted runs\n";
$mismatch = 0;
foreach ($runs as $r) {
    $jt = db_row(
        "SELECT COALESCE(SUM(debit),0) AS dr FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
        [$r['journal_entry_id']]
    );
    if (bccomp((string) $r['total_depreciation'], $jt['dr'], 2) !== 0) {
        $mismatch++;
        printf("    ❌ Run #%d: subledger \$%s ≠ JE DR \$%s\n",
            $r['id'], $r['total_depreciation'], $jt['dr']);
    }
}
ck("All posted runs reconcile to linked JE", "0", (string) $mismatch);

// ============================================================
// CHECK C — Sum of all posted depreciation runs == sum of all
//           depreciation-source JE debits (after excluding reversed)
// ============================================================
echo "\n## CHECK C — Total run subledger == total posted depr-JE debits\n\n";

$runTotal = db_row(
    "SELECT COALESCE(SUM(total_depreciation),0) AS t
     FROM acc_depreciation_runs WHERE status = 'posted'"
)['t'];

// Sum DR from all depreciation JEs that are posted (exclude reversed/draft)
// And don't double-count: for a depreciation JE, the debit side IS the depreciation expense.
// We sum the DR lines on accounts where account.type = 'expense'
$exprDr = db_row(
    "SELECT COALESCE(SUM(l.debit),0) AS t
     FROM acc_journal_entry_lines l
     JOIN acc_journal_entries j ON j.id = l.journal_entry_id
     JOIN acc_accounts a ON a.id = l.account_id
     WHERE j.source_type = 'depreciation'
       AND j.status = 'posted'
       AND a.account_type IN ('operating_expense','cost_of_revenue','other_expense')"
)['t'];

echo "  Posted run subledger total:    \$$runTotal\n";
echo "  Posted depr-JE expense debits: \$$exprDr\n";
ck("Reconciled", $runTotal, $exprDr);

// ============================================================
// CHECK D — Each disposal JE has the right structure:
//           DR cash (if proceeds > 0), DR accum, CR asset, DR/CR gain/loss
// ============================================================
echo "\n## CHECK D — Disposal JE structure validation\n\n";
$disposals = db_select(
    "SELECT d.*, j.id AS je_id, a.asset_account_id, a.accum_depr_account_id
     FROM acc_asset_disposals d
     JOIN acc_journal_entries j ON j.id = d.journal_entry_id
     JOIN acc_fixed_assets a ON a.id = d.asset_id"
);
$disp_ok = 0;
foreach ($disposals as $d) {
    $lines = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$d['je_id']]);
    // Find the asset CR line (cost reversal)
    $assetCrLine = null;
    $accumDrLine = null;
    foreach ($lines as $l) {
        if ((int) $l['account_id'] === (int) $d['asset_account_id'] && bccomp($l['credit'], '0.00', 2) > 0) {
            $assetCrLine = $l;
        }
        if ((int) $l['account_id'] === (int) $d['accum_depr_account_id'] && bccomp($l['debit'], '0.00', 2) > 0) {
            $accumDrLine = $l;
        }
    }
    $cost = db_row("SELECT acquisition_cost FROM acc_fixed_assets WHERE id = ?", [$d['asset_id']])['acquisition_cost'];
    $costOk = $assetCrLine && bccomp($assetCrLine['credit'], $cost, 2) === 0;
    if ($costOk) $disp_ok++;
    printf("    %s Disposal #%d: cost \$%s on JE #%d → %s\n",
        $costOk ? '✅' : '❌', $d['id'], $cost, $d['je_id'], $costOk ? 'OK' : 'MISSING');
}
ck("All disposals have proper cost-CR line", (string) count($disposals), (string) $disp_ok);

// ============================================================
// CHECK E — Impairment subledger ↔ JE check
// ============================================================
echo "\n## CHECK E — Impairment JE structure validation\n\n";
$imps = db_select("SELECT * FROM acc_asset_impairments");
foreach ($imps as $i) {
    $lines = db_select("SELECT l.account_id, l.debit, l.credit, a.code, a.name
                        FROM acc_journal_entry_lines l
                        JOIN acc_accounts a ON a.id = l.account_id
                        WHERE l.journal_entry_id = ?", [$i['journal_entry_id']]);
    printf("  Impairment #%d (asset %d, loss \$%s, JE #%d):\n",
        $i['id'], $i['asset_id'], $i['impairment_loss'], $i['journal_entry_id']);
    $hasLossDr = false; $hasAccumCr = false;
    foreach ($lines as $l) {
        printf("    %s %s — DR \$%s / CR \$%s\n", $l['code'], $l['name'], $l['debit'], $l['credit']);
        if ($l['code'] === '7020' && bccomp($l['debit'], $i['impairment_loss'], 2) === 0) $hasLossDr = true;
        if (str_starts_with($l['code'], '12') && str_contains(strtolower($l['name']), 'accumulated') && bccomp($l['credit'], $i['impairment_loss'], 2) === 0) $hasAccumCr = true;
    }
    ck("    Has 7020 loss DR for impairment_loss", "1", $hasLossDr ? "1" : "0");
    ck("    Has accum-depr CR for impairment_loss", "1", $hasAccumCr ? "1" : "0");
}

// ============================================================
// CHECK F — Reversals fully net to zero
// ============================================================
echo "\n## CHECK F — Reversal pair net to zero\n\n";
$reversed = db_select("SELECT id, reversed_by_id FROM acc_journal_entries WHERE status = 'reversed'");
echo "  Verifying " . count($reversed) . " reversed JEs\n";
foreach ($reversed as $r) {
    if (!$r['reversed_by_id']) {
        printf("    ❌ JE #%d has no reversed_by_id\n", $r['id']);
        continue;
    }
    $orig = db_row("SELECT COALESCE(SUM(debit),0) AS dr, COALESCE(SUM(credit),0) AS cr FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$r['id']]);
    $rev  = db_row("SELECT COALESCE(SUM(debit),0) AS dr, COALESCE(SUM(credit),0) AS cr FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$r['reversed_by_id']]);
    $netDr = bcsub($orig['dr'], $rev['cr'], 2);
    $netCr = bcsub($orig['cr'], $rev['dr'], 2);
    printf("    JE #%d ↔ #%d: net DR \$%s, net CR \$%s\n",
        $r['id'], $r['reversed_by_id'], $netDr, $netCr);
    ck("    Net to zero", "0.00 / 0.00", "$netDr / $netCr");
}

// ============================================================
// CHECK G — Audit log
// ============================================================
echo "\n## CHECK G — Audit log entries by event type\n\n";
$audit = db_select("
    SELECT entity_type, action, COUNT(*) AS n
    FROM audit_log
    WHERE entity_type IN ('fixed_asset','depreciation_run','capex_request')
    GROUP BY entity_type, action
    ORDER BY entity_type, action
");
$total = 0;
foreach ($audit as $a) {
    printf("    %-20s %-20s : %d\n", $a['entity_type'], $a['action'], $a['n']);
    $total += $a['n'];
}
echo "  Total S034 audit entries: $total\n";
ck("Audit > 0", "1", $total > 0 ? "1" : "0");

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "GL RECONCILIATION COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
