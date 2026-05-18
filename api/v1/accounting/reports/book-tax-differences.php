<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/book-tax-differences.php
 *
 * Book vs Tax temporary-difference schedule per spec §23.4. Produces the
 * 4-row reconciliation between ASPE-book depreciation/accruals and the
 * CRA tax positions (CCA / unpaid bills).
 *
 * Mainland uses the taxes-payable method (ASPE 3465 alternative — no
 * deferred-tax asset/liability accrued). This schedule is for disclosure
 * context + T2 preparer reference.
 *
 * @method  GET
 * @query   fiscal_year (required), format? (json|pdf, default json)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { fiscal_year, method, items[], total_temp_diff, disclosure_note }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.4
 * Session: S-ACCT-CCA-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
$format     = strtolower((string) ($_GET['format'] ?? 'json'));

if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}
if (!in_array($format, ['json', 'pdf'], true)) {
    json_error('VALIDATION_ERROR', 'format must be json or pdf.', 422);
}

// ── Item 1: Book depreciation vs CCA claimed ───────────────────────────────
// Book depreciation = sum of acc_depreciation_run_lines.depreciation for all
// runs whose period_id falls in this fiscal year. Joins through
// acc_depreciation_runs.period_id → acc_periods.year.
// K-22 catch: column is `depreciation` (decimal(15,2)) — confirmed at pre-flight.
$bookDeprRow = db_row(
    "SELECT COALESCE(SUM(drl.depreciation), 0) AS total
       FROM acc_depreciation_run_lines drl
       JOIN acc_periods p ON p.id = drl.period_id
      WHERE p.year = ?",
    [$fiscalYear]
);
$bookDepreciation = (string) ($bookDeprRow['total'] ?? '0.00');

// Tax depreciation = sum of cca_claimed across all classes for the year.
$ccaRow = db_row(
    "SELECT COALESCE(SUM(cca_claimed), 0) AS total
       FROM acc_cca_continuity
      WHERE fiscal_year = ?",
    [$fiscalYear]
);
$ccaClaimed = (string) ($ccaRow['total'] ?? '0.00');

$tempDiffDepreciation = bcsub($bookDepreciation, $ccaClaimed, 2);

// ── Item 2: Accruals not yet paid (book-deductible, tax-deferred) ──────────
// acc_bills with status='approved' AND amount_paid=0 in the fiscal year are
// the simplest accrual surface. (Partial-paid accruals require a more nuanced
// per-bill split that is out of scope for this disclosure schedule.)
// K-22 catch: column is `total_amount` (not `amount`) — confirmed at pre-flight.
$accrualsRow = db_row(
    "SELECT COALESCE(SUM(total_amount), 0) AS total
       FROM acc_bills
      WHERE status = 'approved'
        AND amount_paid = 0
        AND YEAR(bill_date) = ?",
    [$fiscalYear]
);
$accrualsBook = (string) ($accrualsRow['total'] ?? '0.00');
$accrualsTax  = '0.00';
$tempDiffAccruals = bcsub($accrualsBook, $accrualsTax, 2);

// ── Item 3: Reserves (placeholder for now) ─────────────────────────────────
// Reserves are a future-engagement scope (e.g. warranty reserve, AR allowance
// for doubtful accounts treated as a tax reserve under ITA s.20(1)(l)).
$reservesBook = '0.00';
$reservesTax  = '0.00';
$tempDiffReserves = '0.00';

// ── Item 4: Other (manual entry slot) ──────────────────────────────────────
$otherBook = '0.00';
$otherTax  = '0.00';
$tempDiffOther = '0.00';

$items = [
    [
        'item'        => 'Depreciation (ASPE 3061) vs CCA',
        'book_amount' => $bookDepreciation,
        'tax_amount'  => $ccaClaimed,
        'temp_diff'   => $tempDiffDepreciation,
        'nature'      => 'timing',
        'note'        => 'Reverses over the asset life. Positive temp diff = book depreciation > tax CCA (slower book recovery).',
    ],
    [
        'item'        => 'Accruals not yet deductible (approved bills, unpaid)',
        'book_amount' => $accrualsBook,
        'tax_amount'  => $accrualsTax,
        'temp_diff'   => $tempDiffAccruals,
        'nature'      => 'timing',
        'note'        => 'Approved bills with amount_paid=0 dated in the fiscal year. Partially-paid bills are excluded from this simplified surface; expand to per-bill split when needed.',
    ],
    [
        'item'        => 'Reserves',
        'book_amount' => $reservesBook,
        'tax_amount'  => $reservesTax,
        'temp_diff'   => $tempDiffReserves,
        'nature'      => 'timing',
        'note'        => 'Reserves not applicable — expand in future engagement if needed (warranty reserve, ITA s.20(1)(l) AR allowance, etc.).',
    ],
    [
        'item'        => 'Other',
        'book_amount' => $otherBook,
        'tax_amount'  => $otherTax,
        'temp_diff'   => $tempDiffOther,
        'nature'      => 'manual',
        'note'        => 'Manual entry — adjust at the T2 preparer if other temp diffs surface (e.g. capitalized R&D, lease accounting differences).',
    ],
];

$totalTempDiff = '0.00';
foreach ($items as $i) {
    $totalTempDiff = bcadd($totalTempDiff, $i['temp_diff'], 2);
}

$report = [
    'fiscal_year'      => $fiscalYear,
    'method'           => 'taxes_payable',
    'items'            => $items,
    'total_temp_diff'  => $totalTempDiff,
    'disclosure_note'  => 'Mainland uses the taxes-payable method (ASPE 3465 alternative). No deferred tax asset/liability is accrued. This schedule is prepared for disclosure context and T2 preparer reference only.',
];

if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::bookTaxDifferences($report);
    exit;
}

json_success($report);
