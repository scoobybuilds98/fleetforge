<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/working-trial-balance.php
 *
 * Working Trial Balance v2 — 10-column practitioner-grade trial balance
 * per spec §23.2. Separates posted activity into "Unadjusted CY" (non-AJE
 * entries) and "AJEs" (adjusting/reclassifying/prior_period entries),
 * compares to PY end-of-period balance, flags materiality breaches, and
 * surfaces lead schedule codes for drill-down.
 *
 * Columns: GL# | Account | Lead | PY Balance | Unadj CY | AJEs |
 *          Adj CY | Var $ | Var % | Ref
 *
 * @method  GET
 * @query   period_id (required), materiality? (decimal string, default '0.00'),
 *          comparison_period_id? (PY period; if omitted, PY end = current
 *          period's year - 1, Dec 31)
 * @auth    Session required; require_permission('journal_entries','view')
 *          (existing reports use this module — no 'financial_reports' module
 *          exists on disk per pre-flight scan)
 * @returns 200 { period, py_period, materiality, accounts[], totals,
 *                is_balanced, annotations_count }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.2
 * Session: S-ACCT-WTB
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\AccountingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$periodId           = clean_int($_GET['period_id'] ?? null);
$materiality        = clean_decimal($_GET['materiality'] ?? '0.00') ?? '0.00';
$comparisonPeriodId = clean_int($_GET['comparison_period_id'] ?? null);

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}
if (bccomp($materiality, '0', 2) < 0) {
    json_error('VALIDATION_ERROR', 'materiality must be non-negative.', 422);
}

$period = db_row(
    "SELECT id, name, start_date, end_date, year, status
     FROM acc_periods WHERE id = ?",
    [$periodId]
);
if (!$period) {
    json_error('NOT_FOUND', "Period {$periodId} not found.", 404);
}

// WHY: PY balance is point-in-time as of (current period year - 1) Dec 31,
// unless caller passes a specific comparison_period_id.
$pyPeriod = null;
if ($comparisonPeriodId) {
    $pyPeriod = db_row(
        "SELECT id, name, end_date FROM acc_periods WHERE id = ?",
        [$comparisonPeriodId]
    );
    if (!$pyPeriod) {
        json_error('NOT_FOUND', "Comparison period {$comparisonPeriodId} not found.", 404);
    }
    $pyEndDate = $pyPeriod['end_date'];
} else {
    $pyEndDate = ((int) $period['year'] - 1) . '-12-31';
    $pyPeriod = ['id' => null, 'name' => 'PY end of fiscal year', 'end_date' => $pyEndDate];
}

// AJE types per S-ACCT-AJE (D-AJE-3 locked).
$ajeTypes = ['adjusting', 'reclassifying', 'prior_period'];
$ajeIn    = "'" . implode("','", $ajeTypes) . "'";

// Pull all active, non-header accounts up front.
$accounts = db_select(
    "SELECT id, code, name, account_type, normal_balance, lead_schedule_code, coa_group, sort_order
     FROM acc_accounts
     WHERE is_active = 1 AND is_header = 0
     ORDER BY sort_order ASC, code ASC"
);

// Pull period-only activity broken by AJE flag in one pass.
$activity = db_select(
    "SELECT jel.account_id,
            COALESCE(SUM(CASE WHEN je.entry_type IN ({$ajeIn}) THEN jel.debit  ELSE 0 END), 0) AS aje_debit,
            COALESCE(SUM(CASE WHEN je.entry_type IN ({$ajeIn}) THEN jel.credit ELSE 0 END), 0) AS aje_credit,
            COALESCE(SUM(CASE WHEN je.entry_type NOT IN ({$ajeIn}) THEN jel.debit  ELSE 0 END), 0) AS unadj_debit,
            COALESCE(SUM(CASE WHEN je.entry_type NOT IN ({$ajeIn}) THEN jel.credit ELSE 0 END), 0) AS unadj_credit
       FROM acc_journal_entry_lines jel
       JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
      WHERE je.status = 'posted'
        AND je.period_id = ?
      GROUP BY jel.account_id",
    [$periodId]
);
$activityByAcct = [];
foreach ($activity as $a) {
    $activityByAcct[(int) $a['account_id']] = $a;
}

// AJE-line drilldown (most recent 5 per account in this period).
$ajeLines = db_select(
    "SELECT jel.account_id, je.id AS je_id, je.entry_number, je.description,
            jel.debit, jel.credit, je.entry_date
       FROM acc_journal_entry_lines jel
       JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
      WHERE je.status = 'posted'
        AND je.period_id = ?
        AND je.entry_type IN ({$ajeIn})
      ORDER BY je.entry_date DESC, je.id DESC",
    [$periodId]
);
$ajeLinesByAcct = [];
foreach ($ajeLines as $line) {
    $aid = (int) $line['account_id'];
    if (!isset($ajeLinesByAcct[$aid])) $ajeLinesByAcct[$aid] = [];
    if (count($ajeLinesByAcct[$aid]) < 5) {
        // Net amount on the account's normal side for display.
        $ajeLinesByAcct[$aid][] = [
            'je_id'        => (int) $line['je_id'],
            'entry_number' => $line['entry_number'],
            'description'  => $line['description'],
            'entry_date'   => $line['entry_date'],
            'debit'        => $line['debit'],
            'credit'       => $line['credit'],
        ];
    }
}

$result      = [];
$totalPy     = '0.00';
$totalUnadj  = '0.00';
$totalAjes   = '0.00';
$totalAdj    = '0.00';
$totalDebit  = '0.00';
$totalCredit = '0.00';

foreach ($accounts as $acct) {
    $aid = (int) $acct['id'];

    $unadjD = $activityByAcct[$aid]['unadj_debit']  ?? '0.00';
    $unadjC = $activityByAcct[$aid]['unadj_credit'] ?? '0.00';
    $ajeD   = $activityByAcct[$aid]['aje_debit']    ?? '0.00';
    $ajeC   = $activityByAcct[$aid]['aje_credit']   ?? '0.00';

    // Normal-side signed amount: debit-normal → debits add, credits subtract;
    // credit-normal → credits add, debits subtract.
    if ($acct['normal_balance'] === 'debit') {
        $unadjCy = bcsub($unadjD, $unadjC, 2);
        $ajeAmt  = bcsub($ajeD,   $ajeC,   2);
    } else {
        $unadjCy = bcsub($unadjC, $unadjD, 2);
        $ajeAmt  = bcsub($ajeC,   $ajeD,   2);
    }
    $adjCy = bcadd($unadjCy, $ajeAmt, 2);

    // PY balance via existing helper (point-in-time, all-time → pyEndDate).
    $pyBal = AccountingService::accountBalance($aid, $pyEndDate);

    // Skip rows that are zero across the board.
    if (bccomp($pyBal,    '0', 2) === 0
        && bccomp($unadjCy, '0', 2) === 0
        && bccomp($ajeAmt,  '0', 2) === 0) {
        continue;
    }

    $varAmt = bcsub($adjCy, $pyBal, 2);
    $varPct = null;
    if (bccomp($pyBal, '0', 2) !== 0) {
        // ltrim sign for absolute value (bcabs equivalent).
        $absPy = ltrim($pyBal, '-');
        if (bccomp($absPy, '0', 2) !== 0) {
            $varPct = bcmul(bcdiv($varAmt, $absPy, 6), '100', 2);
        }
    }

    // Materiality flags (only when materiality > 0).
    $balanceFlag  = null;
    $varianceFlag = null;
    if (bccomp($materiality, '0', 2) > 0) {
        if (bccomp(ltrim($adjCy,  '-'), $materiality, 2) > 0) $balanceFlag  = 'red';
        if (bccomp(ltrim($varAmt, '-'), $materiality, 2) > 0) $varianceFlag = 'yellow';
    }

    $totalPy    = bcadd($totalPy,    $pyBal,   2);
    $totalUnadj = bcadd($totalUnadj, $unadjCy, 2);
    $totalAjes  = bcadd($totalAjes,  $ajeAmt,  2);
    $totalAdj   = bcadd($totalAdj,   $adjCy,   2);

    // For is_balanced: sum debits and credits on the underlying side, not
    // on the normal-side signed amount (since debit-normal + positive vs
    // credit-normal + positive don't cancel naturally).
    if ($acct['normal_balance'] === 'debit') {
        if (bccomp($adjCy, '0', 2) >= 0) $totalDebit  = bcadd($totalDebit,  $adjCy, 2);
        else                              $totalCredit = bcadd($totalCredit, ltrim($adjCy, '-'), 2);
    } else {
        if (bccomp($adjCy, '0', 2) >= 0) $totalCredit = bcadd($totalCredit, $adjCy, 2);
        else                              $totalDebit  = bcadd($totalDebit,  ltrim($adjCy, '-'), 2);
    }

    $result[] = [
        'account_id'         => $aid,
        'code'               => $acct['code'],
        'name'               => $acct['name'],
        'account_type'       => $acct['account_type'],
        'normal_balance'     => $acct['normal_balance'],
        'lead_schedule_code' => $acct['lead_schedule_code'],
        'coa_group'          => $acct['coa_group'],
        'py_balance'         => $pyBal,
        'unadj_cy'           => $unadjCy,
        'ajes'               => $ajeAmt,
        'adj_cy'             => $adjCy,
        'var_amt'            => $varAmt,
        'var_pct'            => $varPct,
        'balance_flag'       => $balanceFlag,
        'variance_flag'      => $varianceFlag,
        'ref'                => $acct['lead_schedule_code'],
        'aje_entries'        => $ajeLinesByAcct[$aid] ?? [],
    ];
}

$annotationsCount = (int) db_count(
    "SELECT COUNT(*) FROM acc_workpaper_annotations
      WHERE workpaper_type = 'trial_balance'
        AND period_id = ?",
    [$periodId]
);

$report = [
    'period'    => $period,
    'py_period' => $pyPeriod,
    'materiality' => $materiality,
    'accounts'  => $result,
    'totals'    => [
        'py_balance' => $totalPy,
        'unadj_cy'   => $totalUnadj,
        'ajes'       => $totalAjes,
        'adj_cy'     => $totalAdj,
        'debits'     => $totalDebit,
        'credits'    => $totalCredit,
    ],
    'is_balanced'       => bccomp($totalDebit, $totalCredit, 2) === 0,
    'annotations_count' => $annotationsCount,
];

$format = strtolower((string) ($_GET['format'] ?? 'json'));
if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::workingTrialBalance($report);
    exit;
}

json_success($report);
