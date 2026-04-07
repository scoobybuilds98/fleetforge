<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ledger/index.php
 *
 * General Ledger account ledger — all posted JE lines for a specific account,
 * filtered by date range, with running balance calculation.
 *
 * @method  GET
 * @query   account_id (required), date_from?, date_to?, page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated { rows[], opening_balance, closing_balance }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §4 (GL views)
 * Session: S030
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

use FleetForge\Accounting\AccountingService;

// ── Input ────────────────────────────────────────────────────
$accountId = clean_int($_GET['account_id'] ?? null);
if (!$accountId) {
    json_error('MISSING_REQUIRED', 'account_id is required.', 422);
}

$account = db_row(
    "SELECT id, code, name, normal_balance, account_type FROM acc_accounts WHERE id = ?",
    [$accountId]
);
if (!$account) {
    json_error('NOT_FOUND', 'Account not found.', 404);
}

$dateFrom = clean_date($_GET['date_from'] ?? null);
$dateTo   = clean_date($_GET['date_to'] ?? null);

$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 50) ?? 50));
$offset  = ($page - 1) * $perPage;

// ── Build WHERE ──────────────────────────────────────────────
$where  = "je.status = 'posted' AND jel.account_id = ?";
$params = [$accountId];

if ($dateFrom) {
    $where .= " AND je.entry_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where .= " AND je.entry_date <= ?";
    $params[] = $dateTo;
}

// ── Opening balance (all posted entries BEFORE date_from) ────
$openingBalance = '0.00';
if ($dateFrom) {
    $obRow = db_row(
        "SELECT COALESCE(SUM(jel.debit), 0) AS td, COALESCE(SUM(jel.credit), 0) AS tc
         FROM acc_journal_entry_lines jel
         JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
         WHERE je.status = 'posted' AND jel.account_id = ? AND je.entry_date < ?",
        [$accountId, $dateFrom]
    );
    if ($obRow) {
        // WHY: Debit-normal → debit-credit; Credit-normal → credit-debit
        $openingBalance = ($account['normal_balance'] === 'debit')
            ? bcsub($obRow['td'], $obRow['tc'], 2)
            : bcsub($obRow['tc'], $obRow['td'], 2);
    }
}

// ── Total count ──────────────────────────────────────────────
$total = db_count(
    "SELECT COUNT(*) FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE {$where}",
    $params
);

// ── Fetch rows ───────────────────────────────────────────────
$rows = db_select(
    "SELECT jel.id, jel.journal_entry_id, jel.description AS line_description,
            jel.debit, jel.credit, jel.customer_id,
            je.entry_number, je.entry_date, je.description, je.reference,
            je.source_type, je.source_id, je.entry_type
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE {$where}
     ORDER BY je.entry_date ASC, je.id ASC, jel.line_number ASC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── Compute running balance for this page ────────────────────
// If not on page 1, get the cumulative balance up to this page's first row
$runningBalance = $openingBalance;
if ($offset > 0) {
    // Sum all rows before this page
    $preRows = db_row(
        "SELECT COALESCE(SUM(jel.debit), 0) AS td, COALESCE(SUM(jel.credit), 0) AS tc
         FROM acc_journal_entry_lines jel
         JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
         WHERE {$where}
         ORDER BY je.entry_date ASC, je.id ASC, jel.line_number ASC
         LIMIT {$offset}",
        $params
    );
    // That LIMIT on aggregate won't work — use a subquery approach instead
    $preSql = "SELECT COALESCE(SUM(sub.debit), 0) AS td, COALESCE(SUM(sub.credit), 0) AS tc FROM (
        SELECT jel.debit, jel.credit
        FROM acc_journal_entry_lines jel
        JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
        WHERE {$where}
        ORDER BY je.entry_date ASC, je.id ASC, jel.line_number ASC
        LIMIT {$offset}
    ) sub";
    $preRows = db_row($preSql, $params);
    if ($preRows) {
        if ($account['normal_balance'] === 'debit') {
            $runningBalance = bcadd($openingBalance, bcsub($preRows['td'], $preRows['tc'], 2), 2);
        } else {
            $runningBalance = bcadd($openingBalance, bcsub($preRows['tc'], $preRows['td'], 2), 2);
        }
    }
}

// Add running_balance to each row
foreach ($rows as &$row) {
    if ($account['normal_balance'] === 'debit') {
        $runningBalance = bcadd($runningBalance, bcsub($row['debit'], $row['credit'], 2), 2);
    } else {
        $runningBalance = bcadd($runningBalance, bcsub($row['credit'], $row['debit'], 2), 2);
    }
    $row['running_balance'] = $runningBalance;
}
unset($row);

$closingBalance = $runningBalance;

json_success([
    'account'         => $account,
    'opening_balance' => $openingBalance,
    'closing_balance' => $closingBalance,
    'rows'            => $rows,
    'total'           => $total,
    'page'            => $page,
    'per_page'        => $perPage,
]);
