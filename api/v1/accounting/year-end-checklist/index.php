<?php
declare(strict_types=1);

/**
 * api/v1/accounting/year-end-checklist/index.php
 *
 * List year-end checklist items for a fiscal year. If the requested
 * year has fewer rows than the canonical set, seed the missing items
 * (idempotent — keyed on (year, item_key) UNIQUE).
 *
 * Schema reality: column is `year` (smallint), not `fiscal_year`. The
 * API param uses `fiscal_year` for readability; we translate.
 *
 * @method  GET
 * @query   fiscal_year (required, 4-digit year)
 * @auth    require_permission('journal_entries','view')
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
if (!$fiscalYear || $fiscalYear < 2000 || $fiscalYear > 2100) {
    json_error('VALIDATION_ERROR', 'fiscal_year must be a 4-digit calendar year.', 422);
}

// ── Canonical checklist (matches the 17-item seed shipped in S037-YE) ────
// item_key drives the UNIQUE constraint, so re-seeding is idempotent.
$canonical = [
    ['key' => 'bank_recon_dec',            'label' => 'Reconcile all bank accounts for December {YEAR}',                    'sort' => 10],
    ['key' => 'ap_review',                 'label' => 'Review all outstanding A/P bills and ensure posted',                  'sort' => 20],
    ['key' => 'ar_review',                 'label' => 'Review A/R aging and write off bad debts',                            'sort' => 30],
    ['key' => 'depreciation_q4',           'label' => 'Run Q4 depreciation for all fixed assets',                            'sort' => 40],
    ['key' => 'physical_inv',              'label' => 'Physical inventory count for fleet + spare parts',                    'sort' => 50],
    ['key' => 'inventory_adjust',          'label' => 'Post inventory adjustments from physical count',                      'sort' => 60],
    ['key' => 'accruals',                  'label' => 'Post year-end accruals (unpaid maintenance, insurance)',              'sort' => 70],
    ['key' => 'prepaid_amortize',          'label' => 'Amortize prepaid expenses (insurance, registrations)',                'sort' => 80],
    ['key' => 'tax_provision',             'label' => 'Calculate and post corporate tax provision',                          'sort' => 90],
    ['key' => 'close_periods',             'label' => 'Close all {YEAR} periods (lock against editing)',                     'sort' => 100],
    ['key' => 'gst_return',                'label' => 'File final GST/HST return for {YEAR}',                                'sort' => 110],
    ['key' => 'gifi_export',               'label' => 'Export GIFI-formatted financials for accountant',                     'sort' => 120],
    ['key' => 'retained_earnings',         'label' => 'Post year-end close entry to retained earnings',                      'sort' => 130],
    ['key' => 'annual_reports',            'label' => 'Generate annual financial statements',                                'sort' => 140],
    ['key' => 'archive',                   'label' => 'Archive {YEAR} supporting documents',                                 'sort' => 150],
    ['key' => 'fx_revaluation_ye',         'label' => 'Run FX revaluation for year-end USD balances',                        'sort' => 160],
    ['key' => 'lease_amortization_review', 'label' => 'Review lease amortization schedule completeness for fiscal year',     'sort' => 170],
];

// Seed missing items idempotently
db_transaction(function () use ($canonical, $fiscalYear) {
    foreach ($canonical as $item) {
        $exists = db_row(
            "SELECT id FROM acc_year_end_checklist WHERE `year` = ? AND item_key = ?",
            [$fiscalYear, $item['key']]
        );
        if (!$exists) {
            $label = str_replace('{YEAR}', (string) $fiscalYear, $item['label']);
            db_insert('acc_year_end_checklist', [
                'year'         => $fiscalYear,
                'item_key'     => $item['key'],
                'item_label'   => $label,
                'is_complete'  => 0,
                'sort_order'   => $item['sort'],
            ]);
        }
    }
});

$rows = db_select(
    "SELECT cl.id, cl.year AS fiscal_year, cl.item_key, cl.item_label,
            cl.is_complete, cl.completed_by, cl.completed_at, cl.notes, cl.sort_order,
            u.name AS completed_by_name
       FROM acc_year_end_checklist cl
  LEFT JOIN users u ON u.id = cl.completed_by
      WHERE cl.year = ?
      ORDER BY cl.sort_order ASC, cl.id ASC",
    [$fiscalYear]
);

// Progress summary
$complete = 0;
foreach ($rows as $r) {
    if ((int) $r['is_complete'] === 1) $complete++;
}

json_success([
    'fiscal_year'    => $fiscalYear,
    'items'          => $rows,
    'total_count'    => count($rows),
    'complete_count' => $complete,
    'pct_complete'   => count($rows) > 0 ? round(($complete / count($rows)) * 100, 1) : 0.0,
]);
