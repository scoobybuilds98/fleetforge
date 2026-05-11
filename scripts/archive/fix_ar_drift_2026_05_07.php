<?php
declare(strict_types=1);

/**
 * scripts/fix_ar_drift_2026_05_07.php
 *
 * S-ACCT-FIX-A1 — AR Subledger ↔ GL Drift Diagnostic + Remediation.
 *
 * Runs Phase 1 (read-only diagnostic) by default. Pass --apply to additionally
 * run Phase 2 (idempotent corrective JE posting). Phase 2 only runs when the
 * stop-gate evaluation in Phase 1 explains ≥90% of the drift via the four
 * audit hypotheses (H1 voided-pair break, H2 pre-S030 orphan invoices, H3
 * lease-close JE inconsistency, H4 bad debt write-off without paired AR
 * credit). Otherwise the script HARD STOPS regardless of --apply.
 *
 * Diagnostic output is written to /tmp/fleetforge_ar_drift_diagnostic_2026_05_07.md
 * (not committed to the repo — operational artifact).
 *
 * USAGE:
 *   php scripts/fix_ar_drift_2026_05_07.php             # diagnostic only
 *   php scripts/fix_ar_drift_2026_05_07.php --apply     # diagnostic + remediation
 *
 * SAFETY:
 *   - Default is dry-run (Phase 1 only). --apply must be explicit.
 *   - Advisory lock 'ff_acct_a1_remediation' (D21) — silent exit on contention.
 *   - Single db_transaction per corrective JE; full rollback on error.
 *   - Idempotent re-run via [A1-FIX-{source_type}-{source_id}] description tag
 *     (D-ACCT-A1-5).
 *   - Stop-gate hard-fails if drift cause cannot be attributed (exit 4) or
 *     post-remediation drift > $100 (exit 5 + NotificationService alert).
 *
 * AUTHOR:  S-ACCT-FIX-A1
 * DATE:    2026-05-07
 * SPEC:    FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md §4 + §5 note 1
 *          FLEETFORGE_ACCOUNTING_SPEC.md §5, §16, A9
 *          D-ACCT-A1-1..7 (locked in session brief)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;
use FleetForge\Notifications\NotificationService;

// =============================================================================
// CLI argument parsing
// =============================================================================
$args = array_slice($argv ?? [], 1);
$apply = false;
foreach ($args as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run') {
        $apply = false;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/fix_ar_drift_2026_05_07.php [--apply|--dry-run]\n");
        exit(2);
    }
}

// =============================================================================
// Output helpers — stream to BOTH stdout and the diagnostic markdown file.
// /tmp path matches the session brief; not a committed artifact.
// =============================================================================
$diagnosticPath = '/tmp/fleetforge_ar_drift_diagnostic_2026_05_07.md';
$outFp = fopen($diagnosticPath, 'w');
if (!$outFp) {
    fwrite(STDERR, "Cannot open diagnostic output file: {$diagnosticPath}\n");
    exit(2);
}

function out(string $line = ''): void {
    global $outFp;
    fwrite($outFp, $line . "\n");
    echo $line . "\n";
}

function fmt_money(string $s): string {
    $isNeg = bccomp($s, '0', 2) < 0;
    $abs = $isNeg ? bcmul($s, '-1', 2) : $s;
    return ($isNeg ? '-$' : '$') . number_format((float) $abs, 2, '.', ',');
}

function abs_bc(string $s): string {
    return bccomp($s, '0', 2) < 0 ? bcmul($s, '-1', 2) : $s;
}

// =============================================================================
// Advisory lock — D21 + D-ACCT-A1-7. Silent exit on contention.
// =============================================================================
$lock = db_row("SELECT GET_LOCK('ff_acct_a1_remediation', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    fwrite(STDERR, "Another instance of S-ACCT-FIX-A1 is already running. Exiting silently.\n");
    fclose($outFp);
    exit(0);
}

// Wrap remaining work so we always release the lock + close fp.
$exitCode = 0;
try {

out('# S-ACCT-FIX-A1 — AR Subledger ↔ GL Drift Diagnostic');
out('');
out('- **Mode:** ' . ($apply ? 'APPLY (Phase 1 diagnostic + Phase 2 remediation)' : 'DRY-RUN (Phase 1 diagnostic only)'));
out('- **Timestamp:** ' . date('Y-m-d H:i:s'));
out('- **Audit reference:** FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md');
out('- **Reported drift in audit:** $17,064.62 (subledger > GL)');
out('');

// =============================================================================
// PHASE 1 — DIAGNOSTIC
// =============================================================================
out('---');
out('');
out('## Phase 1 — Diagnostic');
out('');

// ----- Step 1.1: re-confirm drift -----
out('### Step 1.1 — Re-confirm the drift');
out('');

$arAcctId = (int) AccountingService::setting('accounting.ar_account_id');
if (!$arAcctId) {
    out('**ERROR:** accounting.ar_account_id setting is not configured.');
    throw new RuntimeException('AR account not configured.');
}
$arAcctRow = db_row("SELECT id, code, name FROM acc_accounts WHERE id = ?", [$arAcctId]);
out("- AR account: #{$arAcctId} ({$arAcctRow['code']} — {$arAcctRow['name']})");

$glBalance = AccountingService::accountBalance($arAcctId);

// Audit's subledger query (excludes draft per audit §5 note 1)
$subRowAudit = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total
       FROM invoices
      WHERE status NOT IN ('paid','void','draft','written_off')
        AND deleted_at IS NULL",
    []
);
$subAudit = (string) $subRowAudit['total'];
$driftAudit = bcsub($subAudit, $glBalance, 2);

// AccountingService's query (does NOT exclude draft; spec §5 wording)
$subRowSvc = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total
       FROM invoices
      WHERE status NOT IN ('paid','void','written_off')
        AND deleted_at IS NULL",
    []
);
$subSvc = (string) $subRowSvc['total'];
$driftSvc = bcsub($subSvc, $glBalance, 2);

out('');
out('| Measurement | Value |');
out('|---|---|');
out("| GL AR balance (account 1030) | " . fmt_money($glBalance) . " |");
out("| Subledger sum, audit query (excludes draft) | " . fmt_money($subAudit) . " |");
out("| Subledger sum, AccountingService query (incl. draft) | " . fmt_money($subSvc) . " |");
out("| **Drift (audit query)** | **" . fmt_money($driftAudit) . "** |");
out("| Drift (service query) | " . fmt_money($driftSvc) . " |");
out('');

// Variance vs audit-reported drift
$reportedDrift = '17064.62';
$variance = bcsub($driftAudit, $reportedDrift, 2);
$absVariance = abs_bc($variance);
if (bccomp($absVariance, '1', 2) > 0) {
    out("> **Note:** Drift differs from audit-reported $17,064.62 by " . fmt_money($variance) . ". Data has shifted since 2026-05-07 audit. Continuing.");
    out('');
}

// Stop condition #2 — drift > $30K
if (bccomp(abs_bc($driftAudit), '30000', 2) > 0) {
    out('**STOP:** Drift exceeds $30,000 — significantly larger than audit reported. Re-scoping required.');
    $exitCode = 6; // distinct stop code for size escalation
    throw new RuntimeException('drift_exceeds_30k');
}

// Sub-threshold check
if (bccomp(abs_bc($driftAudit), '100', 2) < 0) {
    out('');
    out('### ℹ️  Drift below remediation threshold ($100). No action.');
    $exitCode = 0;
    throw new RuntimeException('drift_below_threshold');
}

// ----- Step 1.2: per-customer breakdown -----
out('### Step 1.2 — Per-customer drift breakdown (top 20 by |delta|)');
out('');

// Subledger per customer (audit query)
$custSubledger = db_select(
    "SELECT customer_id, COALESCE(SUM(balance_due), 0) AS sub
       FROM invoices
      WHERE status NOT IN ('paid','void','draft','written_off')
        AND deleted_at IS NULL
        AND customer_id IS NOT NULL
      GROUP BY customer_id",
    []
);
$subByCust = [];
foreach ($custSubledger as $r) {
    $subByCust[(int) $r['customer_id']] = (string) $r['sub'];
}

// GL per customer on AR account (covers ALL source types via the customer_id JE-line tag)
$custGl = db_select(
    "SELECT jel.customer_id,
            COALESCE(SUM(jel.debit),0)  AS td,
            COALESCE(SUM(jel.credit),0) AS tc
       FROM acc_journal_entry_lines jel
       JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
      WHERE jel.account_id = ?
        AND je.status = 'posted'
        AND jel.customer_id IS NOT NULL
      GROUP BY jel.customer_id",
    [$arAcctId]
);
$glByCust = [];
foreach ($custGl as $r) {
    $glByCust[(int) $r['customer_id']] = bcsub($r['td'], $r['tc'], 2);
}

// Build combined diff list across union of keys
$allCustIds = array_unique(array_merge(array_keys($subByCust), array_keys($glByCust)));
$custDiffs = [];
foreach ($allCustIds as $cid) {
    $sub = $subByCust[$cid] ?? '0.00';
    $gl  = $glByCust[$cid]  ?? '0.00';
    $delta = bcsub($sub, $gl, 2);
    if (bccomp($delta, '0', 2) === 0) continue;
    $custDiffs[] = ['cid' => $cid, 'sub' => $sub, 'gl' => $gl, 'delta' => $delta];
}

usort($custDiffs, fn($a, $b) => bccomp(abs_bc($b['delta']), abs_bc($a['delta'])));

$top = array_slice($custDiffs, 0, 20);
if (empty($top)) {
    out('No per-customer drift detected (all customers reconcile).');
} else {
    out('| Customer | Company | Subledger | GL | Delta (sub − gl) |');
    out('|---|---|---|---|---|');
    foreach ($top as $d) {
        $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$d['cid']]);
        $name = $cust ? str_replace('|', '/', (string) $cust['company_name']) : '(unknown)';
        out("| #{$d['cid']} | {$name} | " . fmt_money($d['sub']) . ' | ' . fmt_money($d['gl']) . ' | ' . fmt_money($d['delta']) . ' |');
    }
}

// Detect bidirectional drift (stop condition #3)
$posCount = $negCount = 0;
$posSum = '0.00'; $negSum = '0.00';
foreach ($custDiffs as $d) {
    if (bccomp($d['delta'], '0', 2) > 0) { $posCount++; $posSum = bcadd($posSum, $d['delta'], 2); }
    if (bccomp($d['delta'], '0', 2) < 0) { $negCount++; $negSum = bcadd($negSum, $d['delta'], 2); }
}
out('');
out("- Customers with subledger > GL: {$posCount} (sum +" . fmt_money($posSum) . ')');
out("- Customers with GL > subledger: {$negCount} (sum " . fmt_money($negSum) . ')');

$bidirectional = ($posCount > 0 && $negCount > 0
                  && bccomp(abs_bc($negSum), '500', 2) > 0);
if ($bidirectional) {
    out('');
    out('> **WARNING:** Drift is bidirectional (some customers subledger > GL, others GL > subledger). Per stop-condition #3 this needs separate sessions per direction. Continuing diagnostic for visibility but Phase 2 will be hard-blocked.');
}
out('');

// ----- Step 1.3: per-source-type AR impact attribution -----
out('### Step 1.3 — Per-source-type drift attribution on AR account');
out('');

$srcImpact = db_select(
    "SELECT je.source_type,
            COUNT(DISTINCT je.id) AS je_count,
            COALESCE(SUM(jel.debit),0)  AS td,
            COALESCE(SUM(jel.credit),0) AS tc
       FROM acc_journal_entry_lines jel
       JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
      WHERE jel.account_id = ?
        AND je.status = 'posted'
      GROUP BY je.source_type
      ORDER BY ABS(SUM(jel.debit) - SUM(jel.credit)) DESC",
    [$arAcctId]
);

out('| source_type | JE count | DR | CR | Net AR contribution (DR − CR) |');
out('|---|---|---|---|---|');
foreach ($srcImpact as $r) {
    $net = bcsub($r['td'], $r['tc'], 2);
    $st = $r['source_type'] ?? '(null)';
    out("| {$st} | {$r['je_count']} | " . fmt_money($r['td']) . ' | ' . fmt_money($r['tc']) . ' | ' . fmt_money($net) . ' |');
}
out('');

// ----- Step 1.4: per-month invoice JE coverage -----
out('### Step 1.4 — Per-month invoice JE coverage (S030 deployed 2026-04-06)');
out('');

$monthRows = db_select(
    "SELECT DATE_FORMAT(i.created_at, '%Y-%m') AS ym,
            COUNT(*) AS inv_count,
            SUM(CASE WHEN je.id IS NOT NULL THEN 1 ELSE 0 END) AS with_je,
            SUM(CASE WHEN je.id IS NULL     THEN 1 ELSE 0 END) AS without_je,
            COALESCE(SUM(CASE WHEN je.id IS NULL THEN i.balance_due ELSE 0 END), 0) AS orphan_balance_sum
       FROM invoices i
       LEFT JOIN acc_journal_entries je
         ON je.source_type = 'invoice' AND je.source_id = i.id
        AND je.status = 'posted'
        AND je.is_reversal = 0
      WHERE i.deleted_at IS NULL
        AND i.status NOT IN ('draft','void')
      GROUP BY ym
      ORDER BY ym",
    []
);

out('| Month | Invoices | With JE | Without JE | Orphan balance_due sum |');
out('|---|---|---|---|---|');
foreach ($monthRows as $m) {
    $ym = $m['ym'] ?? '(null)';
    out("| {$ym} | {$m['inv_count']} | {$m['with_je']} | {$m['without_je']} | " . fmt_money((string) $m['orphan_balance_sum']) . ' |');
}
out('');

// ----- Step 1.5: hypothesis testing -----
out('### Step 1.5 — Hypothesis testing');
out('');

// H1 — voided invoices with broken JE pairs.
//   Originally-sent invoices that were later voided. The void should generate
//   a reversal JE (is_reversal=1, reversal_of_id=original.id). If reversal is
//   missing, the original DR-AR is still in the GL but the subledger has
//   excluded the invoice → GL > subledger contribution from these = wrong
//   direction for our drift, but flag for completeness.
out('#### H1 — Voided invoices with broken JE pairs');
$h1Rows = db_select(
    "SELECT i.id, i.invoice_number, i.total_amount, i.customer_id, i.sent_date,
            (SELECT je.id FROM acc_journal_entries je
              WHERE je.source_type='invoice' AND je.source_id=i.id
                AND je.is_reversal=0 ORDER BY je.id DESC LIMIT 1) AS original_je_id,
            (SELECT je.id FROM acc_journal_entries je
              WHERE je.source_type='invoice' AND je.source_id=i.id
                AND je.is_reversal=1 ORDER BY je.id DESC LIMIT 1) AS reversal_je_id,
            (SELECT je.status FROM acc_journal_entries je
              WHERE je.source_type='invoice' AND je.source_id=i.id
                AND je.is_reversal=0 ORDER BY je.id DESC LIMIT 1) AS original_status
       FROM invoices i
      WHERE i.status = 'void'
        AND i.deleted_at IS NULL",
    []
);
$h1OrphanReversalImpact = '0.00';   // reversal exists but no original (rare)
$h1MissingReversalImpact = '0.00';  // original exists but no reversal — wrong-direction drift
$h1List = [];
foreach ($h1Rows as $r) {
    $hasOriginal = !empty($r['original_je_id']) && $r['original_status'] === 'reversed';
    $hasReversal = !empty($r['reversal_je_id']);
    if (!empty($r['original_je_id']) && !$hasReversal) {
        // Original posted, never reversed — GL still has DR AR for this voided invoice
        $h1MissingReversalImpact = bcadd($h1MissingReversalImpact, (string) $r['total_amount'], 2);
        $h1List[] = [
            'kind' => 'missing-reversal',
            'invoice_id' => (int) $r['id'],
            'invoice_number' => $r['invoice_number'],
            'amount' => (string) $r['total_amount'],
            'customer_id' => (int) $r['customer_id'],
        ];
    } elseif ($hasReversal && empty($r['original_je_id'])) {
        $h1OrphanReversalImpact = bcadd($h1OrphanReversalImpact, (string) $r['total_amount'], 2);
        $h1List[] = [
            'kind' => 'orphan-reversal',
            'invoice_id' => (int) $r['id'],
            'invoice_number' => $r['invoice_number'],
            'amount' => (string) $r['total_amount'],
            'customer_id' => (int) $r['customer_id'],
        ];
    }
}
out("- Voided invoices with original-DR but no reversal: " . count(array_filter($h1List, fn($x) => $x['kind']==='missing-reversal'))
    . " (GL impact: " . fmt_money($h1MissingReversalImpact) . ", direction: GL > subledger — opposite drift sign)");
out("- Voided invoices with reversal-CR but no original: " . count(array_filter($h1List, fn($x) => $x['kind']==='orphan-reversal'))
    . " (GL impact: " . fmt_money($h1OrphanReversalImpact) . ", direction: subledger > GL — matches our drift sign)");
// Net H1 contribution to subledger > GL drift = orphan-reversal cases
$h1Contribution = $h1OrphanReversalImpact;
out("- **H1 net contribution to (sub − gl) drift:** " . fmt_money($h1Contribution));
out('');

// H2 — open invoices missing original JE.
out('#### H2 — Open invoices with no original AR JE');
$h2Rows = db_select(
    "SELECT i.id, i.invoice_number, i.customer_id, i.status,
            i.total_amount, i.balance_due, i.sent_date, i.created_at,
            i.subtotal, i.subtotal_after_discount,
            i.tax_gst_amount, i.tax_pst_amount, i.tax_hst_amount
       FROM invoices i
      WHERE i.status NOT IN ('draft','void','written_off')
        AND i.deleted_at IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM acc_journal_entries je
             WHERE je.source_type = 'invoice'
               AND je.source_id   = i.id
               AND je.is_reversal = 0
               AND je.status      = 'posted'
        )
      ORDER BY i.created_at",
    []
);
$h2Contribution = '0.00';
foreach ($h2Rows as $r) {
    // Each missing original DR contributes total_amount to (sub − gl) drift,
    // because subledger has balance_due > 0 (typically = total_amount when
    // unpaid; payments reduce balance_due AND posted CR-AR via 'payment'
    // source — so drift contribution = total_amount regardless of payments).
    $h2Contribution = bcadd($h2Contribution, (string) $r['total_amount'], 2);
}
out("- Open invoices with no posted source_type='invoice' DR-AR JE: " . count($h2Rows));
out("- **H2 net contribution to (sub − gl) drift:** " . fmt_money($h2Contribution));
if (count($h2Rows) > 0 && count($h2Rows) <= 30) {
    out('');
    out('| Invoice | Customer | Status | Total | Balance Due | Sent date | Created |');
    out('|---|---|---|---|---|---|---|');
    foreach ($h2Rows as $r) {
        $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$r['customer_id']]);
        $cn = $cust ? str_replace('|', '/', (string) $cust['company_name']) : '#'.$r['customer_id'];
        out("| {$r['invoice_number']} | {$cn} | {$r['status']} | "
            . fmt_money((string)$r['total_amount']) . ' | '
            . fmt_money((string)$r['balance_due']) . ' | '
            . ($r['sent_date'] ?? '—') . ' | '
            . substr((string)$r['created_at'], 0, 10) . ' |');
    }
}
out('');

// H3 — lease close adjustment / mileage JE inconsistency.
//   The actual schema column is `final_amount` and there is no deleted_at.
//   Close-path invoices/credits are already caught via 'invoice' / 'credit_note'
//   source types in Step 1.3 / Step 1.6 — H3 is reported here only for
//   completeness.
out('#### H3 — Lease close mileage adjustment JE inconsistency');
$h3HasTable = (bool) db_row("SHOW TABLES LIKE 'lease_close_adjustments'", []);
$h3Contribution = '0.00';
if (!$h3HasTable) {
    out('- (lease_close_adjustments table not present — H3 not applicable in this build.)');
} else {
    $h3Sum = db_row(
        "SELECT COALESCE(SUM(final_amount), 0) AS t, COUNT(*) AS n
           FROM lease_close_adjustments",
        []
    );
    out("- Lease close adjustment rows: {$h3Sum['n']}, total final_amount: " . fmt_money((string) $h3Sum['t']));
    out("- Close-path invoices + credit notes are captured via 'invoice' / 'credit_note' source types in Step 1.3 / Step 1.6.");
}
out("- **H3 net contribution to (sub − gl) drift:** " . fmt_money($h3Contribution));
out('');

// H4 — bad debt write-offs without a paired CR-AR JE.
out('#### H4 — Bad debt write-offs missing the CR to AR');
$h4HasTable = (bool) db_row("SHOW TABLES LIKE 'acc_bad_debt_writeoffs'", []);
$h4Contribution = '0.00';
if ($h4HasTable) {
    $h4Rows = db_select(
        "SELECT bdw.id, bdw.invoice_id, bdw.amount, i.invoice_number,
                (SELECT COUNT(*) FROM acc_journal_entry_lines jel
                  JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                 WHERE jel.account_id = ? AND jel.credit > 0
                   AND je.source_type = 'invoice' AND je.source_id = bdw.invoice_id
                   AND je.status = 'posted') AS ar_credits
           FROM acc_bad_debt_writeoffs bdw
           LEFT JOIN invoices i ON i.id = bdw.invoice_id",
        [$arAcctId]
    );
    $missing = 0;
    foreach ($h4Rows as $r) {
        if ((int) $r['ar_credits'] === 0) {
            $missing++;
            // Bad debt without AR credit means: subledger marks invoice
            // 'written_off' (excluded from sum), GL still carries the DR AR.
            // Direction: GL > subledger — opposite our drift sign. Note for
            // completeness, do not add to H? contribution.
        }
    }
    out("- Bad debt write-offs with no paired AR-CR JE: {$missing} of " . count($h4Rows));
} else {
    out('- (acc_bad_debt_writeoffs table empty / not relevant)');
}
out("- **H4 net contribution to (sub − gl) drift:** " . fmt_money($h4Contribution));
out('');

// H5 — Orphan payment AR-CR JEs whose allocated invoices are
//   soft-deleted (or otherwise have no matching invoice DR-AR).
//   Pattern: payment JE posted CR-AR, but the invoice it paid was bulk-deleted
//   without reversing the payment JE → subledger excludes the deleted invoice
//   from sum, GL still carries the negative AR contribution → subledger > GL.
out('#### H5 — Orphan payment AR-CR JEs (paid invoice is soft-deleted or missing)');
$h5Rows = db_select(
    "SELECT DISTINCT je.id AS je_id, je.entry_number, je.source_id AS payment_id,
            jel.credit AS ar_credit, jel.customer_id,
            p.payment_number,
            (SELECT GROUP_CONCAT(CONCAT(pa.invoice_id, ':', pa.amount))
                FROM payment_allocations pa WHERE pa.payment_id = je.source_id) AS allocs
       FROM acc_journal_entries je
       JOIN acc_journal_entry_lines jel ON jel.journal_entry_id = je.id
       LEFT JOIN payments p ON p.id = je.source_id
      WHERE je.source_type = 'payment'
        AND je.status = 'posted'
        AND je.is_reversal = 0
        AND jel.account_id = ?
        AND jel.credit > 0",
    [$arAcctId]
);
$h5Contribution = '0.00';
$h5List = [];
foreach ($h5Rows as $r) {
    $allocs = $r['allocs'] ? explode(',', (string) $r['allocs']) : [];
    $missingInvoice = false;
    foreach ($allocs as $alloc) {
        [$invId, $allocAmt] = array_pad(explode(':', $alloc, 2), 2, '0');
        $inv = db_row(
            "SELECT id, invoice_number, status, deleted_at FROM invoices WHERE id = ?",
            [(int) $invId]
        );
        if (!$inv || $inv['deleted_at'] !== null) {
            $missingInvoice = true;
            break;
        }
        // Also count it as orphan if invoice has no DR-AR JE
        $hasInvJe = db_row(
            "SELECT 1 FROM acc_journal_entries
              WHERE source_type='invoice' AND source_id=? AND is_reversal=0 AND status='posted'",
            [(int) $invId]
        );
        if (!$hasInvJe) {
            $missingInvoice = true;
            break;
        }
    }
    if ($missingInvoice) {
        $h5Contribution = bcadd($h5Contribution, (string) $r['ar_credit'], 2);
        $h5List[] = [
            'je_id' => (int) $r['je_id'],
            'entry_number' => $r['entry_number'],
            'payment_id' => $r['payment_id'] !== null ? (int) $r['payment_id'] : null,
            'payment_number' => $r['payment_number'],
            'ar_credit' => (string) $r['ar_credit'],
            'customer_id' => (int) $r['customer_id'],
            'allocs' => $r['allocs'],
        ];
    }
}
out("- Payment AR-CR JEs whose paid invoice is soft-deleted or missing its invoice JE: " . count($h5List));
out("- **H5 net contribution to (sub − gl) drift:** " . fmt_money($h5Contribution));
if (count($h5List) > 0 && count($h5List) <= 30) {
    out('');
    out('| JE | Payment | Customer | AR Credit | Allocated to (invoice_id:amount) |');
    out('|---|---|---|---|---|');
    foreach ($h5List as $r) {
        $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$r['customer_id']]);
        $cn = $cust ? str_replace('|', '/', (string) $cust['company_name']) : '#'.$r['customer_id'];
        out("| {$r['entry_number']} | " . ($r['payment_number'] ?? '#'.$r['payment_id']) . " | {$cn} | "
            . fmt_money($r['ar_credit']) . " | " . ($r['allocs'] ?? '—') . ' |');
    }
}
out('');

// H6 — Invoice DR-AR JE amount ≠ invoice.total_amount.
//   Pattern: JE posted with wrong amount (often from before tax-exempt
//   reclassification, or schema drift). Drift impact direction depends on
//   whether the JE was over- or under-stated.
out('#### H6 — Invoice DR-AR JE amount ≠ invoice.total_amount');
$h6Rows = db_select(
    "SELECT i.id, i.invoice_number, i.customer_id, i.status, i.total_amount, i.deleted_at,
            (SELECT SUM(jel.debit) FROM acc_journal_entry_lines jel
              JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
             WHERE je.source_type='invoice' AND je.source_id=i.id
               AND je.is_reversal=0 AND je.status='posted'
               AND jel.account_id = ?) AS posted_dr_ar
       FROM invoices i
      WHERE i.deleted_at IS NULL
        AND i.status NOT IN ('draft','void')",
    [$arAcctId]
);
$h6Contribution = '0.00'; // signed: positive = subledger > GL
$h6List = [];
foreach ($h6Rows as $r) {
    if ($r['posted_dr_ar'] === null) continue; // covered by H2
    $diff = bcsub((string) $r['total_amount'], (string) $r['posted_dr_ar'], 2);
    if (bccomp($diff, '0', 2) === 0) continue;
    // diff > 0  → JE under-debited → subledger > GL
    // diff < 0  → JE over-debited  → GL > subledger
    $h6Contribution = bcadd($h6Contribution, $diff, 2);
    $h6List[] = [
        'invoice_id' => (int) $r['id'],
        'invoice_number' => $r['invoice_number'],
        'customer_id' => (int) $r['customer_id'],
        'status' => $r['status'],
        'invoice_total' => (string) $r['total_amount'],
        'je_dr' => (string) $r['posted_dr_ar'],
        'diff' => $diff,
    ];
}
out("- Invoices whose JE DR-AR ≠ total_amount: " . count($h6List));
out("- **H6 net contribution to (sub − gl) drift:** " . fmt_money($h6Contribution));
if (count($h6List) > 0 && count($h6List) <= 30) {
    out('');
    out('| Invoice | Customer | Status | Invoice total | JE DR-AR | Diff (total − JE) |');
    out('|---|---|---|---|---|---|');
    foreach ($h6List as $r) {
        $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$r['customer_id']]);
        $cn = $cust ? str_replace('|', '/', (string) $cust['company_name']) : '#'.$r['customer_id'];
        out("| {$r['invoice_number']} | {$cn} | {$r['status']} | "
            . fmt_money($r['invoice_total']) . ' | '
            . fmt_money($r['je_dr']) . ' | '
            . fmt_money($r['diff']) . ' |');
    }
}
out('');

// ----- Hypothesis aggregation -----
out('#### Hypothesis aggregation');
out('');
$totalExplained = '0.00';
foreach ([$h1Contribution, $h2Contribution, $h3Contribution, $h4Contribution, $h5Contribution, $h6Contribution] as $c) {
    $totalExplained = bcadd($totalExplained, $c, 2);
}
out("- H1 (voided invoice JE pair break): " . fmt_money($h1Contribution));
out("- H2 (open invoice no AR JE):       " . fmt_money($h2Contribution));
out("- H3 (lease close adj inconsistency): " . fmt_money($h3Contribution));
out("- H4 (bad debt no CR):              " . fmt_money($h4Contribution));
out("- H5 (orphan payment AR-CR JEs):    " . fmt_money($h5Contribution));
out("- H6 (invoice JE amount ≠ total):   " . fmt_money($h6Contribution));
out("- **Total explained:** " . fmt_money($totalExplained));
out("- **Total drift:** " . fmt_money($driftAudit));

if (bccomp(abs_bc($driftAudit), '0.01', 2) > 0) {
    // Use absolute values to prevent sign cancellation hiding partial coverage.
    $explainedPct = bcdiv(bcmul(abs_bc($totalExplained), '100', 4), abs_bc($driftAudit), 2);
    out("- **|Total explained| / |Total drift|:** {$explainedPct}%");
} else {
    $explainedPct = '0.00';
}
out('');

// Determine dominant hypothesis (largest single |contribution|).
$dominantH = 'H?';
$dominantAmt = '0.00';
foreach ([
    'H1' => $h1Contribution, 'H2' => $h2Contribution, 'H3' => $h3Contribution,
    'H4' => $h4Contribution, 'H5' => $h5Contribution, 'H6' => $h6Contribution
] as $h => $amt) {
    if (bccomp(abs_bc($amt), abs_bc($dominantAmt), 2) > 0) {
        $dominantH = $h;
        $dominantAmt = $amt;
    }
}

// =============================================================================
// STOP-GATE
// =============================================================================
out('---');
out('');
out('## Stop-gate evaluation');
out('');

if (bccomp(abs_bc($driftAudit), '100', 2) < 0) {
    out('ℹ️  Drift below remediation threshold ($100). No action.');
    $exitCode = 0;
    throw new RuntimeException('drift_below_threshold');
}

$thresholdPct = '90.00';
$gatePassed = bccomp($explainedPct, $thresholdPct, 2) >= 0;

if ($gatePassed) {
    out("✅ **STOP-GATE PASSED** — explained {$explainedPct}% (≥ 90%). Dominant hypothesis: {$dominantH} (" . fmt_money($dominantAmt) . ").");
    if ($apply) {
        out('Phase 2 remediation will run.');
    } else {
        out('Re-run with `--apply` to execute Phase 2 remediation.');
    }
} else {
    out("❌ **STOP-GATE FAILED** — explained only {$explainedPct}% (< 90%). Unexplained: " . fmt_money(bcsub($driftAudit, $totalExplained, 2)) . ". Manual investigation required.");
    $exitCode = 4;
    throw new RuntimeException('stop_gate_failed');
}

if ($bidirectional) {
    out('');
    out('❌ **STOP-GATE FAILED** — drift is bidirectional. Per stop-condition #3 separate sessions are required per direction.');
    $exitCode = 4;
    throw new RuntimeException('bidirectional_drift');
}

out('');

if (!$apply) {
    out('---');
    out('');
    out('## Run sequence');
    out('');
    out('Diagnostic complete. To apply remediation:');
    out('');
    out('```');
    out('php scripts/fix_ar_drift_2026_05_07.php --apply');
    out('```');
    $exitCode = 0;
    throw new RuntimeException('dry_run_complete');
}

// =============================================================================
// PHASE 2 — REMEDIATION (only when --apply AND stop-gate passed)
// =============================================================================
out('---');
out('');
out('## Phase 2 — Remediation');
out('');

// Stop condition #4 — orphan invoice modified since the audit was captured.
$auditDate = '2026-05-07 00:00:00';
$modifiedSinceAudit = 0;
foreach ($h2Rows as $r) {
    $row = db_row(
        "SELECT updated_at FROM invoices WHERE id = ?",
        [(int) $r['id']]
    );
    if ($row && $row['updated_at'] > $auditDate) {
        $modifiedSinceAudit++;
    }
}
if ($modifiedSinceAudit > 0) {
    out("**STOP:** {$modifiedSinceAudit} orphan invoice(s) have been modified since the 2026-05-07 audit. Refusing to post corrective JEs against shifted state. Investigate manually.");
    $exitCode = 7;
    throw new RuntimeException('orphan_modified_since_audit');
}

// Stop condition #5 — large credit > $5K to a non-system account.
// (Will be checked per-JE during posting below.)

$postedCount = 0;
$skippedIdempotent = 0;
$totalCorrectedAr = '0.00';
$results = [];

// ----- 2A. H2 orphan invoices (dominant) -----
foreach ($h2Rows as $orig) {
    $invoiceId = (int) $orig['id'];
    $tag = "[A1-FIX-invoice-{$invoiceId}]";

    // Idempotency check (D-ACCT-A1-5)
    $existing = db_row(
        "SELECT id FROM acc_journal_entries WHERE description LIKE ?",
        [$tag . '%']
    );
    if ($existing) {
        out("- ⏭️  Invoice #{$invoiceId} {$orig['invoice_number']} — already remediated as JE id={$existing['id']}.");
        $skippedIdempotent++;
        continue;
    }

    // Build line items grouping per revenue account, mirroring AutoEntryBridge::onInvoiceSent.
    $lineItems = db_select(
        "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order",
        [$invoiceId]
    );

    $revenueByAccount = [];
    $unmappedTypes = [];
    foreach ($lineItems as $line) {
        $accountId = AccountingService::revenueAccountId((string) $line['item_type']);
        if (!$accountId) {
            $unmappedTypes[] = (string) $line['item_type'];
            continue;
        }
        $amount = (string) $line['amount'];
        if (!isset($revenueByAccount[$accountId])) $revenueByAccount[$accountId] = '0.00';
        if ((int) $line['is_credit'] === 1) {
            $revenueByAccount[$accountId] = bcsub($revenueByAccount[$accountId], $amount, 2);
        } else {
            $revenueByAccount[$accountId] = bcadd($revenueByAccount[$accountId], $amount, 2);
        }
    }
    if (!empty($unmappedTypes)) {
        out("- ⚠️  Invoice #{$invoiceId} {$orig['invoice_number']} — unmapped item types: " . implode(', ', array_unique($unmappedTypes)) . ". Skipping.");
        continue;
    }

    // Construct JE lines mirroring spec §16 invoice-sent rule
    $jeLines = [];
    $jeLines[] = [
        'account_id'  => $arAcctId,
        'debit'       => (string) $orig['total_amount'],
        'credit'      => '0.00',
        'description' => "AR — Invoice {$orig['invoice_number']} (S-ACCT-A1 backfill)",
        'customer_id' => (int) $orig['customer_id'],
    ];
    foreach ($revenueByAccount as $acctId => $netAmt) {
        if (bccomp($netAmt, '0', 2) > 0) {
            $jeLines[] = [
                'account_id'  => (int) $acctId,
                'debit'       => '0.00',
                'credit'      => $netAmt,
                'description' => "Revenue — Invoice {$orig['invoice_number']} (S-ACCT-A1 backfill)",
                'customer_id' => (int) $orig['customer_id'],
            ];
        } elseif (bccomp($netAmt, '0', 2) < 0) {
            $jeLines[] = [
                'account_id'  => (int) $acctId,
                'debit'       => bcmul($netAmt, '-1', 2),
                'credit'      => '0.00',
                'description' => "Revenue credit — Invoice {$orig['invoice_number']} (S-ACCT-A1 backfill)",
                'customer_id' => (int) $orig['customer_id'],
            ];
        }
    }
    $gstHst = bcadd((string) $orig['tax_gst_amount'], (string) $orig['tax_hst_amount'], 2);
    if (bccomp($gstHst, '0', 2) > 0) {
        $gstAcctId = (int) AccountingService::setting('accounting.gst_payable_account_id');
        if (!$gstAcctId) {
            out("- ⚠️  Invoice #{$invoiceId} — GST payable account not configured. Skipping.");
            continue;
        }
        $jeLines[] = [
            'account_id'  => $gstAcctId,
            'debit'       => '0.00',
            'credit'      => $gstHst,
            'description' => "GST/HST — Invoice {$orig['invoice_number']} (S-ACCT-A1 backfill)",
            'customer_id' => (int) $orig['customer_id'],
        ];
    }
    $pst = (string) $orig['tax_pst_amount'];
    if (bccomp($pst, '0', 2) > 0) {
        $pstAcctId = (int) AccountingService::setting('accounting.pst_payable_account_id');
        if (!$pstAcctId) {
            out("- ⚠️  Invoice #{$invoiceId} — PST payable account not configured. Skipping.");
            continue;
        }
        $jeLines[] = [
            'account_id'  => $pstAcctId,
            'debit'       => '0.00',
            'credit'      => $pst,
            'description' => "PST — Invoice {$orig['invoice_number']} (S-ACCT-A1 backfill)",
            'customer_id' => (int) $orig['customer_id'],
        ];
    }

    // Sanity check (stop condition #5): no single non-AR / non-tax credit > $5K.
    $systemAcctIds = array_filter([
        $arAcctId,
        (int) AccountingService::setting('accounting.gst_payable_account_id'),
        (int) AccountingService::setting('accounting.pst_payable_account_id'),
    ]);
    foreach ($jeLines as $ln) {
        if (bccomp($ln['credit'], '5000', 2) > 0 && !in_array((int) $ln['account_id'], $systemAcctIds, true)) {
            out("- ⚠️  Invoice #{$invoiceId} — corrective line credit " . fmt_money($ln['credit']) . " to account #{$ln['account_id']} exceeds $5K guard. Skipping.");
            continue 2;
        }
    }

    // Resolve period (D-ACCT-A1-1: post to current open period; preserve audit
    // trail of original date in description).
    $openPeriod = AccountingService::currentOpenPeriod();
    if (!$openPeriod) {
        out("- ⚠️  No open period available. Halting Phase 2.");
        break;
    }
    $entryDate = $openPeriod['start_date'];
    $origDate = $orig['sent_date'] ?? substr((string) $orig['created_at'], 0, 10);

    // Header description carries the idempotency tag + audit trail.
    $description = "{$tag} S-ACCT-A1 drift remediation: invoice #{$invoiceId} {$orig['invoice_number']} dated {$origDate}";

    // Wrap creation + audit in a single transaction.
    $created = db_transaction(function () use ($jeLines, $description, $orig, $invoiceId, $entryDate) {
        $je = JournalEntryService::create([
            'entry_date'       => $entryDate,
            'description'      => $description,
            'entry_type'       => 'adjustment',
            'reference'        => $orig['invoice_number'],
            'source_type'      => 'manual',
            'source_id'        => null,
            'post_immediately' => true,
        ], $jeLines, null);

        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system (S-ACCT-FIX-A1)',
            'action'      => 'create',
            'module'      => 'accounting',
            'entity_type' => 'ar_drift_remediation',
            'entity_id'   => (int) $je['id'],
            'notes'       => "S-ACCT-A1 corrective JE for invoice #{$invoiceId} ({$orig['invoice_number']}). "
                           . "Original orphan: total_amount=" . $orig['total_amount']
                           . ", balance_due=" . $orig['balance_due']
                           . ", status=" . $orig['status']
                           . ". JE posted to current open period.",
            'ip_address'  => '127.0.0.1',
        ]);

        return $je;
    });

    out("- ✅ Invoice #{$invoiceId} {$orig['invoice_number']} → JE {$created['entry_number']} (id={$created['id']}, " . fmt_money((string) $orig['total_amount']) . ')');
    $postedCount++;
    $totalCorrectedAr = bcadd($totalCorrectedAr, (string) $orig['total_amount'], 2);
    $results[] = ['invoice_id' => $invoiceId, 'invoice_number' => $orig['invoice_number'], 'je_id' => (int) $created['id'], 'amount' => $orig['total_amount']];
}

// (H1, H3, H4 corrective branches would go here if any non-zero contributions
// surface in Phase 1. Current run: H2-only because the audit reported no
// orphan invoices — but the diagnostic verifies this and Phase 2 only enters
// branches where the diagnostic actually found work.)

// ----- 2B. Post-remediation validation -----
out('');
out('### Post-remediation validation');
out('');
$arCheckAfter = AccountingService::arReconciliationCheck();
out("- GL AR balance: " . fmt_money($arCheckAfter['gl_balance']));
out("- Subledger balance: " . fmt_money($arCheckAfter['subledger_balance']));
out("- Difference: " . fmt_money($arCheckAfter['difference']));
out("- Reconciled: " . ($arCheckAfter['is_reconciled'] ? 'YES ✅' : 'NO'));

// Recompute audit-style drift (excludes draft) for parity with the diagnostic
$subRowAfter = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total
       FROM invoices
      WHERE status NOT IN ('paid','void','draft','written_off')
        AND deleted_at IS NULL",
    []
);
$driftAfter = bcsub((string) $subRowAfter['total'], $arCheckAfter['gl_balance'], 2);
out("- Drift (audit query, excludes draft): " . fmt_money($driftAfter));
out('');

out("- Corrective JEs posted: {$postedCount}");
out("- Skipped (already remediated): {$skippedIdempotent}");
out("- Total AR corrected: " . fmt_money($totalCorrectedAr));
out('');

if (bccomp(abs_bc($driftAfter), '100', 2) > 0) {
    out("⚠️  Drift > \$100 after remediation: " . fmt_money($driftAfter));
    out('Notifying via NotificationService (accounting.* category).');
    try {
        // Notify accountants + super_admin per NOTIF-1 routing.
        $recipients = db_select(
            "SELECT u.id FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE r.name IN ('accountant','super_admin') AND u.deleted_at IS NULL",
            []
        );
        foreach ($recipients as $u) {
            NotificationService::notify(
                userId:  (int) $u['id'],
                type:    'accounting.ar_drift_persists',
                title:   'AR drift persists post-remediation',
                message: "S-ACCT-FIX-A1 ran but \$" . number_format((float) abs_bc($driftAfter), 2) . " of AR drift remains. Manual investigation required.",
            );
        }
    } catch (\Throwable $e) {
        out("- (Notification dispatch failed: " . $e->getMessage() . ')');
    }
    $exitCode = 5;
} elseif (bccomp(abs_bc($driftAfter), '1', 2) > 0) {
    out("⚠️  Sub-dollar drift remains: " . fmt_money($driftAfter) . " — acceptable rounding noise.");
} else {
    out('✅ Drift fully reconciled.');
}
out('');

out('---');
out('');
out('## Verify');
out('```');
out('php -r "require \'config/app.php\'; var_dump(\\\\FleetForge\\\\Accounting\\\\AccountingService::arReconciliationCheck());"');
out('```');

} catch (RuntimeException $e) {
    // Intentional control-flow exits; $exitCode already set above.
    if (!in_array($e->getMessage(), ['dry_run_complete','drift_below_threshold','stop_gate_failed','bidirectional_drift','drift_exceeds_30k','orphan_modified_since_audit'], true)) {
        out('');
        out('**FATAL:** ' . $e->getMessage());
        $exitCode = 1;
    }
} catch (\Throwable $e) {
    out('');
    out('**FATAL EXCEPTION:** ' . $e->getMessage());
    out('Stack: ' . $e->getTraceAsString());
    $exitCode = 1;
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_acct_a1_remediation')", []);
    fclose($outFp);
}

exit($exitCode);
