<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/lead-schedule.php
 *
 * Lead schedule per-code drill-down per spec §23.2. For every account
 * carrying lead_schedule_code = $code, returns the standard CPA-workpaper
 * continuity layout: opening balance, period activity grouped by source,
 * closing balance, and a reconciliation check (closing − opening − Σ
 * activity should equal 0). Also returns workpaper annotations attached
 * to this lead schedule + period.
 *
 * @method  GET
 * @query   code (required — e.g. 'A-100'), period_id (required)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { code, period, accounts[], annotations[] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.2
 * Session: S-ACCT-WTB
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\AccountingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$code     = clean_string($_GET['code'] ?? null, 10);
$periodId = clean_int($_GET['period_id'] ?? null);

if (!$code)     json_error('MISSING_REQUIRED', 'code is required.', 422);
if (!$periodId) json_error('MISSING_REQUIRED', 'period_id is required.', 422);

$period = db_row(
    "SELECT id, name, start_date, end_date, year, status FROM acc_periods WHERE id = ?",
    [$periodId]
);
if (!$period) {
    json_error('NOT_FOUND', "Period {$periodId} not found.", 404);
}

$accounts = db_select(
    "SELECT id, code, name, account_type, normal_balance, coa_group, sort_order
     FROM acc_accounts
     WHERE is_active = 1 AND is_header = 0 AND lead_schedule_code = ?
     ORDER BY sort_order ASC, code ASC",
    [$code]
);

// Opening balance = balance as of (start_date - 1 day).
$openingAsOf = date('Y-m-d', strtotime($period['start_date'] . ' -1 day'));

$accountDetails = [];
foreach ($accounts as $acct) {
    $aid = (int) $acct['id'];

    $opening = AccountingService::accountBalance($aid, $openingAsOf);
    $closing = AccountingService::accountBalance($aid, $period['end_date']);

    // Activity in this period: posted JE lines grouped by source_type.
    $activity = db_select(
        "SELECT je.id AS je_id, je.entry_number, je.entry_date, je.entry_type,
                je.source_type, je.source_id, je.description AS je_description,
                jel.description AS line_description, jel.debit, jel.credit
           FROM acc_journal_entry_lines jel
           JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
          WHERE je.status = 'posted'
            AND je.period_id = ?
            AND jel.account_id = ?
          ORDER BY je.entry_date ASC, je.id ASC, jel.line_number ASC",
        [$periodId, $aid]
    );

    // Walk activity to compute running balance per normal_balance side,
    // grouped sums by source_type for the activity-by-source block.
    $isDebitNormal = $acct['normal_balance'] === 'debit';
    $running       = $opening;
    $bySource      = [];
    $rows          = [];
    $totalDebit    = '0.00';
    $totalCredit   = '0.00';

    foreach ($activity as $a) {
        $debit  = $a['debit']  ?? '0.00';
        $credit = $a['credit'] ?? '0.00';
        $delta  = $isDebitNormal ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
        $running = bcadd($running, $delta, 2);

        $src = $a['source_type'] ?? 'manual';
        if (!isset($bySource[$src])) {
            $bySource[$src] = ['debit' => '0.00', 'credit' => '0.00', 'net' => '0.00', 'count' => 0];
        }
        $bySource[$src]['debit']  = bcadd($bySource[$src]['debit'],  $debit,  2);
        $bySource[$src]['credit'] = bcadd($bySource[$src]['credit'], $credit, 2);
        $bySource[$src]['net']    = bcadd($bySource[$src]['net'],    $delta,  2);
        $bySource[$src]['count']++;

        $totalDebit  = bcadd($totalDebit,  $debit,  2);
        $totalCredit = bcadd($totalCredit, $credit, 2);

        $rows[] = [
            'je_id'           => (int) $a['je_id'],
            'entry_number'    => $a['entry_number'],
            'entry_date'      => $a['entry_date'],
            'entry_type'      => $a['entry_type'],
            'source_type'     => $a['source_type'],
            'source_id'       => $a['source_id'] !== null ? (int) $a['source_id'] : null,
            'description'     => $a['line_description'] ?? $a['je_description'],
            'debit'           => $debit,
            'credit'          => $credit,
            'running_balance' => $running,
        ];
    }

    // Reconciliation check: closing should equal opening + Σ normal-side delta.
    $expectedClosing  = $running;
    $reconDiff        = bcsub($closing, $expectedClosing, 2);
    $isReconciled     = bccomp($reconDiff, '0', 2) === 0;

    $accountDetails[] = [
        'account_id'      => $aid,
        'code'            => $acct['code'],
        'name'            => $acct['name'],
        'account_type'    => $acct['account_type'],
        'normal_balance'  => $acct['normal_balance'],
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'expected_closing' => $expectedClosing,
        'recon_diff'      => $reconDiff,
        'is_reconciled'   => $isReconciled,
        'activity_count'  => count($rows),
        'total_debit'     => $totalDebit,
        'total_credit'    => $totalCredit,
        'by_source'       => $bySource,
        'activity'        => $rows,
    ];
}

$annotations = db_select(
    "SELECT wpa.id, wpa.workpaper_type, wpa.workpaper_ref, wpa.period_id, wpa.account_id,
            wpa.tickmark, wpa.note, wpa.created_by, wpa.created_at,
            u.name AS created_by_name,
            a.code AS account_code, a.name AS account_name
       FROM acc_workpaper_annotations wpa
       LEFT JOIN users u        ON u.id = wpa.created_by
       LEFT JOIN acc_accounts a ON a.id = wpa.account_id
      WHERE wpa.workpaper_type = 'lead_schedule'
        AND wpa.workpaper_ref  = ?
        AND wpa.period_id      = ?
      ORDER BY wpa.created_at ASC",
    [$code, $periodId]
);

json_success([
    'code'        => $code,
    'period'      => $period,
    'accounts'    => $accountDetails,
    'annotations' => $annotations,
]);
