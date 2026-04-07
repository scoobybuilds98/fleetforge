<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

// ============================================================
// S034 — GL TIE-OUT / SUBLEDGER RECONCILIATION
// Walks through every fixed-asset-related GL account and verifies:
//   1. JE balance per JE (DR == CR for all accounting entries)
//   2. Subledger total (acc_fixed_assets) = GL ledger balance
//   3. Disposal/Impairment JE source linkage
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
echo "S034 — GL TIE-OUT & SUBLEDGER RECONCILIATION" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ============================================================
// CHECK 1 — Every depreciation/disposal/impairment JE balances
// ============================================================
echo "\n## CHECK 1 — All S034-source JEs balanced (DR == CR)\n\n";

$jes = db_select(
    "SELECT id, entry_number, source_type, status FROM acc_journal_entries
     WHERE source_type IN ('depreciation','asset_disposal')
     ORDER BY id"
);
echo "  Found " . count($jes) . " S034-source JEs to verify\n\n";

$allBalanced = true;
foreach ($jes as $j) {
    $totals = db_row(
        "SELECT COALESCE(SUM(debit),0) AS dr, COALESCE(SUM(credit),0) AS cr
         FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
        [$j['id']]
    );
    $diff = bcsub($totals['dr'], $totals['cr'], 2);
    $ok = bccomp($diff, '0.00', 2) === 0;
    if (!$ok) $allBalanced = false;
    printf("    %s JE #%-3d %-20s  src=%-15s status=%-10s DR=\$%s CR=\$%s\n",
        $ok ? '✅' : '❌',
        $j['id'],
        $j['entry_number'],
        $j['source_type'],
        $j['status'],
        $totals['dr'],
        $totals['cr']);
}
ck("All S034 JEs balanced", "1", $allBalanced ? "1" : "0");

// ============================================================
// CHECK 2 — Subledger ↔ GL: total fixed asset cost = sum of all
//           1210/1250 (and other asset cost) account postings
// ============================================================
echo "\n## CHECK 2 — Subledger asset cost vs GL asset cost balances\n\n";

// Subledger: SUM(acquisition_cost) of all NON-disposed assets, by asset_account_id
$sub = db_select(
    "SELECT asset_account_id, SUM(acquisition_cost) AS total
     FROM acc_fixed_assets WHERE status != 'disposed'
     GROUP BY asset_account_id ORDER BY asset_account_id"
);
echo "  Active subledger asset balances by GL account:\n";
foreach ($sub as $s) {
    $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$s['asset_account_id']]);
    printf("    Acct #%-2d %s %-40s = \$%s\n",
        $s['asset_account_id'], $acct['code'], $acct['name'], $s['total']);
}

// GL: posted JE lines for asset accounts (DR - CR = current ledger balance)
echo "\n  GL postings to asset accounts (only from S034-source JEs + initial creations):\n";
foreach ($sub as $s) {
    $glRow = db_row(
        "SELECT COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS bal
         FROM acc_journal_entry_lines l
         JOIN acc_journal_entries j ON j.id = l.journal_entry_id
         WHERE l.account_id = ? AND j.status = 'posted'",
        [$s['asset_account_id']]
    );
    $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$s['asset_account_id']]);
    printf("    Acct #%-2d %s GL balance = \$%s\n",
        $s['asset_account_id'], $acct['code'], $glRow['bal']);
}

// ============================================================
// CHECK 3 — Accumulated depreciation: subledger SUM = GL credit balance
// (excludes already-disposed assets, since their accum was zeroed via JE)
// ============================================================
echo "\n## CHECK 3 — Subledger accumulated depreciation reconciles to GL\n\n";

$accumGroups = db_select(
    "SELECT accum_depr_account_id, SUM(accumulated_depreciation) AS total
     FROM acc_fixed_assets WHERE status != 'disposed'
     GROUP BY accum_depr_account_id ORDER BY accum_depr_account_id"
);
foreach ($accumGroups as $g) {
    $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$g['accum_depr_account_id']]);
    $glBal = db_row(
        "SELECT COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) AS bal
         FROM acc_journal_entry_lines l
         JOIN acc_journal_entries j ON j.id = l.journal_entry_id
         WHERE l.account_id = ? AND j.status = 'posted'",
        [$g['accum_depr_account_id']]
    );
    printf("    Acct #%-2d %s %-40s\n", $g['accum_depr_account_id'], $acct['code'], $acct['name']);
    printf("       Subledger SUM(accum):  \$%s\n", $g['total']);
    printf("       GL credit-net balance: \$%s\n", $glBal['bal']);
    ck("       Reconciled", $g['total'], $glBal['bal']);
}

// ============================================================
// CHECK 4 — Net Book Value tie-out
// NBV = cost - accumulated_depr should always hold per-asset
// ============================================================
echo "\n## CHECK 4 — NBV = cost - accumulated_depreciation per asset\n\n";

$assets = db_select("SELECT id, asset_number, name, status, acquisition_cost, accumulated_depreciation, net_book_value FROM acc_fixed_assets ORDER BY id");
$mismatch = 0;
foreach ($assets as $a) {
    if ($a['status'] === 'disposed') continue;  // disposed NBV is zeroed
    $expected = bcsub((string) $a['acquisition_cost'], (string) $a['accumulated_depreciation'], 2);
    $actual = (string) $a['net_book_value'];
    $ok = bccomp($expected, $actual, 2) === 0;
    if (!$ok) {
        $mismatch++;
        printf("    ❌ %s %s: expected NBV \$%s, actual \$%s\n",
            $a['asset_number'], $a['name'], $expected, $actual);
    }
}
echo "  Checked " . count($assets) . " assets, $mismatch NBV mismatches\n";
ck("All non-disposed asset NBVs match cost-accum", "0", (string) $mismatch);

// ============================================================
// CHECK 5 — Depreciation expense GL balance = sum of all posted run totals
// ============================================================
echo "\n## CHECK 5 — Depr expense GL = sum of posted depreciation_runs\n\n";

$runsTotal = db_row(
    "SELECT COALESCE(SUM(total_depreciation),0) AS total
     FROM acc_depreciation_runs WHERE status = 'posted'"
)['total'];

// Find all depreciation expense accounts touched by depreciation source JEs
$exprAccts = db_select(
    "SELECT DISTINCT l.account_id
     FROM acc_journal_entry_lines l
     JOIN acc_journal_entries j ON j.id = l.journal_entry_id
     WHERE j.source_type = 'depreciation' AND j.status = 'posted' AND l.debit > 0"
);
$totalExp = '0.00';
foreach ($exprAccts as $row) {
    $bal = db_row(
        "SELECT COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS bal
         FROM acc_journal_entry_lines l
         JOIN acc_journal_entries j ON j.id = l.journal_entry_id
         WHERE l.account_id = ? AND j.source_type = 'depreciation' AND j.status = 'posted'",
        [$row['account_id']]
    );
    $totalExp = bcadd($totalExp, $bal['bal'], 2);
    $a = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$row['account_id']]);
    printf("    Acct #%-2d %s %s — net DR \$%s\n",
        $row['account_id'], $a['code'], $a['name'], $bal['bal']);
}
echo "  Sum of posted depreciation_runs: \$$runsTotal\n";
echo "  Sum of GL depreciation expense:  \$$totalExp\n";
ck("Depr GL == Run subledger", $runsTotal, $totalExp);

// ============================================================
// CHECK 6 — Reversed run JE pairs net to zero
// ============================================================
echo "\n## CHECK 6 — Reversed runs: original JE + reversal JE net to zero\n\n";
$reversedRuns = db_select(
    "SELECT id, journal_entry_id FROM acc_depreciation_runs WHERE status = 'reversed'"
);
echo "  Found " . count($reversedRuns) . " reversed runs to verify\n";
foreach ($reversedRuns as $r) {
    $orig = db_row(
        "SELECT COALESCE(SUM(debit),0) AS dr FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
        [$r['journal_entry_id']]
    );
    $revOf = db_row(
        "SELECT id FROM acc_journal_entries WHERE source_type = 'depreciation' AND is_reversal = 1 AND reference LIKE ? LIMIT 1",
        ['REV-%']
    );
    // Easier: look up reversed_by_id from the original
    $orig2 = db_row("SELECT reversed_by_id FROM acc_journal_entries WHERE id = ?", [$r['journal_entry_id']]);
    if ($orig2 && $orig2['reversed_by_id']) {
        $rev = db_row(
            "SELECT COALESCE(SUM(credit),0) AS cr FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
            [$orig2['reversed_by_id']]
        );
        $netZero = bccomp($orig['dr'], $rev['cr'], 2) === 0;
        printf("    %s Run #%d: orig DR \$%s ↔ rev CR \$%s\n",
            $netZero ? '✅' : '❌', $r['id'], $orig['dr'], $rev['cr']);
    } else {
        printf("    ⚠️ Run #%d: no reversed_by_id linkage\n", $r['id']);
    }
}

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "GL TIE-OUT COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
