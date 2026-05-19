<?php
declare(strict_types=1);

/**
 * lib/Accounting/ReportPdfRenderer.php
 *
 * mPDF rendering for the 4 S036 financial reports. Inline HTML + CSS
 * matches the on-screen layouts. The branded header reads from the
 * `company.*` and `brand.*` settings populated by S-DESIGN-SETTINGS-
 * FOOTER-LOGIN.
 *
 * Each public method emits the PDF directly to the response body and
 * sets Content-Type — the caller must NOT json_success() after invoking.
 *
 * Session: S036
 */

namespace FleetForge\Accounting;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ReportPdfRenderer
{
    /**
     * Render the P&L statement.
     */
    public static function profitAndLoss(array $report): void
    {
        $title  = 'Profit & Loss';
        $period = $report['period']['from'] . ' to ' . $report['period']['to'];

        $hasCompare = $report['compare_mode'] !== 'none' && !empty($report['compare_total']);

        $html  = self::headerHtml($title, $period);
        $html .= '<table class="rpt">';

        $renderGroup = static function (string $label, array $rows, string $total) use ($hasCompare) {
            $colCount = $hasCompare ? 4 : 2;
            $h  = '<tr class="group"><td colspan="' . $colCount . '"><strong>' . htmlspecialchars($label) . '</strong></td></tr>';
            foreach ($rows as $r) {
                $h .= '<tr><td class="acct">' . htmlspecialchars($r['code'] . ' &mdash; ' . $r['name']) . '</td>';
                $h .= '<td class="amt">' . self::money($r['amount']) . '</td>';
                if ($hasCompare) {
                    $h .= '<td class="amt">' . self::money($r['compare_amount'] ?? '0.00') . '</td>';
                    $h .= '<td class="amt">' . self::money($r['var_amt'] ?? '0.00') . '</td>';
                }
                $h .= '</tr>';
            }
            $h .= '<tr class="total"><td><strong>Total ' . htmlspecialchars($label) . '</strong></td>';
            $h .= '<td class="amt"><strong>' . self::money($total) . '</strong></td>';
            if ($hasCompare) $h .= '<td colspan="2"></td>';
            $h .= '</tr>';
            return $h;
        };

        $html .= $renderGroup('Revenue', $report['revenue'], $report['revenue_total']);
        $html .= $renderGroup('Cost of Revenue', $report['direct_costs'], $report['direct_costs_total']);
        $html .= '<tr class="subtotal"><td><strong>Gross Profit</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['gross_profit']) . '</strong></td>';
        if ($hasCompare) $html .= '<td colspan="2"></td>';
        $html .= '</tr>';

        $html .= $renderGroup('Operating Expenses', $report['operating_expenses'], $report['opex_total']);
        $html .= '<tr class="subtotal"><td><strong>Operating Income</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['operating_income']) . '</strong></td>';
        if ($hasCompare) $html .= '<td colspan="2"></td>';
        $html .= '</tr>';

        $html .= $renderGroup('Other Income / Expense', $report['other'], $report['other_total']);

        $html .= '<tr class="grand"><td><strong>Net Income Before Tax</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['net_income_before_tax']) . '</strong></td>';
        if ($hasCompare) $html .= '<td colspan="2"></td>';
        $html .= '</tr>';
        $html .= '<tr><td>Tax Provision</td><td class="amt">' . self::money($report['tax_provision']) . '</td>';
        if ($hasCompare) $html .= '<td colspan="2"></td>';
        $html .= '</tr>';
        $html .= '<tr class="grand"><td><strong>Net Income</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['net_income']) . '</strong></td>';
        if ($hasCompare) $html .= '<td colspan="2"></td>';
        $html .= '</tr>';
        $html .= '</table>';

        self::emit($html, $title, 'A4', 'L');
    }

    /**
     * Render the Working Trial Balance v2 (10-column landscape A4).
     * Session: S-ACCT-WTB
     */
    public static function workingTrialBalance(array $report): void
    {
        $title  = 'Working Trial Balance';
        $period = $report['period']['name'];
        if (!empty($report['py_period']['end_date'])) {
            $period .= ' — PY as of ' . $report['py_period']['end_date'];
        }

        $html  = self::headerHtml($title, $period);

        if (!$report['is_balanced']) {
            $diff = bcsub($report['totals']['debits'], $report['totals']['credits'], 2);
            $html .= '<div class="banner-amber">WTB unbalanced — total debits and credits differ by '
                   . self::money(ltrim($diff, '-')) . '. This may reflect the known AR drift; '
                   . 'verify on screen before signing off.</div>';
        }

        $mat = $report['materiality'] ?? '0.00';
        if (bccomp($mat, '0', 2) > 0) {
            $html .= '<div class="banner-amber" style="background:#eef;border-color:#88a;color:#224;">'
                   . 'Materiality: ' . self::money($mat) . ' — accounts breaching balance/variance flagged.</div>';
        }

        $html .= '<table class="rpt"><thead><tr>';
        $html .= '<th>GL#</th><th>Account</th><th>Lead</th>';
        $html .= '<th style="text-align:right;">PY Balance</th>';
        $html .= '<th style="text-align:right;">Unadj CY</th>';
        $html .= '<th style="text-align:right;">AJEs</th>';
        $html .= '<th style="text-align:right;">Adj CY</th>';
        $html .= '<th style="text-align:right;">Var $</th>';
        $html .= '<th style="text-align:right;">Var %</th>';
        $html .= '<th>Ref</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($report['accounts'] as $r) {
            $balStyle = $r['balance_flag']  === 'red'    ? ' style="background:#ffe6e6;"' : '';
            $varStyle = $r['variance_flag'] === 'yellow' ? ' style="background:#fff7d6;"' : '';
            $pct      = $r['var_pct'] !== null ? $r['var_pct'] . '%' : '—';
            $lead     = $r['lead_schedule_code'] ?? '';
            $html    .= '<tr>';
            $html    .= '<td>' . htmlspecialchars($r['code']) . '</td>';
            $html    .= '<td>' . htmlspecialchars($r['name']) . '</td>';
            $html    .= '<td>' . htmlspecialchars($lead) . '</td>';
            $html    .= '<td class="amt">' . self::money($r['py_balance']) . '</td>';
            $html    .= '<td class="amt">' . self::money($r['unadj_cy']) . '</td>';
            $html    .= '<td class="amt">' . self::money($r['ajes']) . '</td>';
            $html    .= '<td class="amt"' . $balStyle . '>' . self::money($r['adj_cy']) . '</td>';
            $html    .= '<td class="amt"' . $varStyle . '>' . self::money($r['var_amt']) . '</td>';
            $html    .= '<td class="amt"' . $varStyle . '>' . htmlspecialchars($pct) . '</td>';
            $html    .= '<td>' . htmlspecialchars($r['ref'] ?? '') . '</td>';
            $html    .= '</tr>';
        }

        $t = $report['totals'];
        $html .= '<tr class="grand"><td colspan="3"><strong>Totals</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($t['py_balance']) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($t['unadj_cy']) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($t['ajes']) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($t['adj_cy']) . '</strong></td>';
        $html .= '<td colspan="3"></td></tr>';

        $html .= '</tbody></table>';

        self::emit($html, $title, 'A4', 'L');
    }

    /**
     * Render the T2 Schedule 8 CCA continuity (landscape A4).
     * Session: S-ACCT-CCA-1
     */
    public static function ccaSchedule8(array $schedule): void
    {
        $title  = 'CCA Schedule 8';
        $period = 'Fiscal Year ' . $schedule['fiscal_year'];

        $html  = self::headerHtml($title, $period);

        $rows = $schedule['rows'] ?? [];
        if (!$rows) {
            $html .= '<div class="banner-amber">No CCA continuity rows exist for FY '
                   . htmlspecialchars((string) $schedule['fiscal_year'])
                   . '. Run Compute first.</div>';
        }

        $html .= '<table class="rpt"><thead><tr>';
        $html .= '<th>Class</th><th>Description</th>';
        $html .= '<th style="text-align:right;">Opening UCC</th>';
        $html .= '<th style="text-align:right;">Additions</th>';
        $html .= '<th style="text-align:right;">Disposals</th>';
        $html .= '<th style="text-align:right;">UCC Before CCA</th>';
        $html .= '<th style="text-align:right;">Rate</th>';
        $html .= '<th style="text-align:right;">Half-Year</th>';
        $html .= '<th style="text-align:right;">CCA Claimed</th>';
        $html .= '<th style="text-align:right;">Closing UCC</th>';
        $html .= '<th style="text-align:right;">Recapture</th>';
        $html .= '<th style="text-align:right;">Terminal Loss</th>';
        $html .= '</tr></thead><tbody>';

        $totOpening = '0.00'; $totAdd = '0.00'; $totDisp = '0.00';
        $totUcc = '0.00'; $totHalf = '0.00'; $totCca = '0.00';
        $totClose = '0.00'; $totRecap = '0.00'; $totTerm = '0.00';

        foreach ($rows as $r) {
            $rate = number_format(((float) $r['class_rate']) * 100, 2) . '%';
            $hl   = (bccomp($r['recapture'], '0', 2) > 0
                    || bccomp($r['terminal_loss'], '0', 2) > 0)
                ? ' style="background:#fff7d6;"' : '';

            $html .= '<tr' . $hl . '>';
            $html .= '<td>' . htmlspecialchars($r['class_number']) . '</td>';
            $html .= '<td>' . htmlspecialchars(substr($r['class_description'], 0, 40)) . '</td>';
            $html .= '<td class="amt">' . self::money($r['opening_ucc']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['cost_of_additions']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['proceeds_of_disposition']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['ucc_after_additions_dispositions']) . '</td>';
            $html .= '<td class="amt">' . $rate . '</td>';
            $html .= '<td class="amt">' . self::money($r['half_year_adjustment']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['cca_claimed']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['closing_ucc']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['recapture']) . '</td>';
            $html .= '<td class="amt">' . self::money($r['terminal_loss']) . '</td>';
            $html .= '</tr>';

            $totOpening = bcadd($totOpening, $r['opening_ucc'], 2);
            $totAdd     = bcadd($totAdd,     $r['cost_of_additions'], 2);
            $totDisp    = bcadd($totDisp,    $r['proceeds_of_disposition'], 2);
            $totUcc     = bcadd($totUcc,     $r['ucc_after_additions_dispositions'], 2);
            $totHalf    = bcadd($totHalf,    $r['half_year_adjustment'], 2);
            $totCca     = bcadd($totCca,     $r['cca_claimed'], 2);
            $totClose   = bcadd($totClose,   $r['closing_ucc'], 2);
            $totRecap   = bcadd($totRecap,   $r['recapture'], 2);
            $totTerm    = bcadd($totTerm,    $r['terminal_loss'], 2);
        }

        $html .= '<tr class="grand"><td colspan="2"><strong>TOTAL</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totOpening) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totAdd) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totDisp) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totUcc) . '</strong></td>';
        $html .= '<td></td>';
        $html .= '<td class="amt"><strong>' . self::money($totHalf) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totCca) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totClose) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totRecap) . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($totTerm) . '</strong></td>';
        $html .= '</tr>';
        $html .= '</tbody></table>';

        $html .= '<p style="margin-top:14px;font-size:8pt;color:#6b4900;">'
               . '⚠ AIIP adjustment is $0.00 (placeholder). Full AIIP phase-out '
               . 'and the proposed 2024 FES reinstatement rules are implemented '
               . 'in S-ACCT-CCA-2.</p>';

        self::emit($html, $title, 'A4', 'L');
    }

    /**
     * Render the Book vs Tax temporary-difference schedule (portrait A4).
     * Session: S-ACCT-CCA-2
     */
    public static function bookTaxDifferences(array $report): void
    {
        $title  = 'Book vs Tax — Temporary Differences';
        $period = 'Fiscal Year ' . $report['fiscal_year'];

        $html  = self::headerHtml($title, $period);

        $html .= '<div class="banner-amber">';
        $html .= '<strong>Method: ' . htmlspecialchars($report['method']) . '</strong> — '
               . htmlspecialchars($report['disclosure_note']);
        $html .= '</div>';

        $html .= '<table class="rpt"><thead><tr>';
        $html .= '<th>Item</th>';
        $html .= '<th style="text-align:right;">Book Amount</th>';
        $html .= '<th style="text-align:right;">Tax Amount</th>';
        $html .= '<th style="text-align:right;">Temp Diff</th>';
        $html .= '<th>Nature</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($report['items'] as $i) {
            $hl = bccomp($i['temp_diff'], '0', 2) !== 0 ? ' style="background:#f7f9fc;"' : '';
            $html .= '<tr' . $hl . '>';
            $html .= '<td>' . htmlspecialchars($i['item']) . '</td>';
            $html .= '<td class="amt">' . self::money($i['book_amount']) . '</td>';
            $html .= '<td class="amt">' . self::money($i['tax_amount']) . '</td>';
            $html .= '<td class="amt"><strong>' . self::money($i['temp_diff']) . '</strong></td>';
            $html .= '<td>' . htmlspecialchars($i['nature']) . '</td>';
            $html .= '</tr>';
            if (!empty($i['note'])) {
                $html .= '<tr><td colspan="5" style="background:#fafafa;font-size:8pt;color:#555;font-style:italic;padding:4px 12px;">'
                       . htmlspecialchars($i['note']) . '</td></tr>';
            }
        }

        $html .= '<tr class="grand"><td colspan="3"><strong>Total Temporary Difference</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['total_temp_diff']) . '</strong></td>';
        $html .= '<td></td></tr>';
        $html .= '</tbody></table>';

        self::emit($html, $title, 'A4', 'P');
    }

    /**
     * Render the GST34 13-line return (landscape A4).
     * Session: S-ACCT-GST34
     */
    public static function gst34(array $data): void
    {
        $p = $data['period'];
        $title  = 'GST34 Return';
        $period = "{$p['period_start']} to {$p['period_end']} ({$p['tax_type']})";

        $html  = self::headerHtml($title, $period);

        if ($data['quick_method']) {
            $html .= '<div class="banner-amber">';
            $html .= '<strong>Quick Method active:</strong> Line 103 = revenue × '
                   . htmlspecialchars((string) $data['quick_rate'])
                   . '%. ITCs limited to capital purchases only.';
            $html .= '</div>';
        }

        $restrictions = $data['lines']['L106']['restrictions_applied'] ?? [];
        if (!empty($restrictions)) {
            $sum = '0.00';
            foreach ($restrictions as $r) { $sum = bcadd($sum, $r['reduction'], 2); }
            $html .= '<div class="banner-amber">';
            $html .= '<strong>ITC restrictions applied:</strong> '
                   . count($restrictions) . ' restriction(s), '
                   . self::money($sum) . ' total ITC reduction.';
            $html .= '</div>';
        }

        $html .= '<table class="rpt"><thead><tr>';
        $html .= '<th style="width:60px;">Line</th>';
        $html .= '<th>Description</th>';
        $html .= '<th style="text-align:right;">Amount</th>';
        $html .= '</tr></thead><tbody>';

        $highlightRows = ['L105', 'L108', 'L109', 'L113'];
        foreach ($data['lines'] as $key => $line) {
            $num = preg_replace('/^L/', '', $key);
            $isHighlight = in_array($key, $highlightRows, true);
            $rowClass = $isHighlight ? ' class="subtotal"' : '';
            $html .= "<tr{$rowClass}>";
            $html .= '<td><strong>' . $num . '</strong></td>';
            $html .= '<td>' . htmlspecialchars($line['description']) . '</td>';
            $html .= '<td class="amt"><strong>' . self::money($line['amount']) . '</strong></td>';
            $html .= '</tr>';
        }

        $l113 = $data['lines']['L113'];
        $verdict = $l113['status'] === 'balance_due' ? 'BALANCE DUE TO CRA'
                 : ($l113['status'] === 'refund' ? 'REFUND DUE FROM CRA' : 'NIL FILING');
        $html .= '<tr class="grand"><td colspan="2"><strong>' . $verdict . '</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money(ltrim($l113['amount'], '-')) . '</strong></td></tr>';
        $html .= '</tbody></table>';

        if (!empty($data['warnings'])) {
            $html .= '<p style="margin-top:14px;font-size:7.5pt;color:#6b4900;">';
            foreach ($data['warnings'] as $w) {
                $html .= '⚠ ' . htmlspecialchars($w) . '<br>';
            }
            $html .= '</p>';
        }

        self::emit($html, $title, 'A4', 'L');
    }

    public static function balanceSheet(array $report): void
    {
        $title  = 'Balance Sheet';
        $period = 'As of ' . $report['as_of'];

        $html  = self::headerHtml($title, $period);
        if (!$report['is_balanced']) {
            $html .= '<div class="banner-red">Balance sheet unbalanced — drift '
                  . self::money($report['drift']) . '</div>';
        }
        $html .= '<table class="rpt">';

        $renderSection = static function (string $label, array $rows, string $total) {
            $h = '<tr class="group"><td colspan="2"><strong>' . htmlspecialchars($label) . '</strong></td></tr>';
            foreach ($rows as $r) {
                $h .= '<tr><td class="acct">' . htmlspecialchars($r['code'] . ' &mdash; ' . $r['name']) . '</td>';
                $h .= '<td class="amt">' . self::money($r['amount']) . '</td></tr>';
            }
            $h .= '<tr class="total"><td><strong>Total ' . htmlspecialchars($label) . '</strong></td>';
            $h .= '<td class="amt"><strong>' . self::money($total) . '</strong></td></tr>';
            return $h;
        };

        $html .= $renderSection('Current Assets', $report['current_assets'], $report['current_assets_total']);
        $html .= $renderSection('Long-Term Assets', $report['long_term_assets'], $report['long_term_assets_total']);
        $html .= '<tr class="grand"><td><strong>Total Assets</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['total_assets']) . '</strong></td></tr>';

        $html .= $renderSection('Current Liabilities', $report['current_liabilities'], $report['current_liabilities_total']);
        $html .= $renderSection('Long-Term Liabilities', $report['long_term_liabilities'], $report['long_term_liabilities_total']);
        $html .= '<tr class="grand"><td><strong>Total Liabilities</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['total_liabilities']) . '</strong></td></tr>';

        $html .= $renderSection('Equity', $report['equity'], $report['total_equity']);
        $html .= '<tr><td>Net Income (YTD, injected)</td><td class="amt">' . self::money($report['net_income_injected']) . '</td></tr>';
        $html .= '<tr class="grand"><td><strong>Total Liabilities + Equity</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['total_liabilities_and_equity']) . '</strong></td></tr>';
        $html .= '</table>';

        self::emit($html, $title, 'A4', 'P');
    }

    public static function cashFlow(array $report): void
    {
        $title  = 'Cash Flow Statement';
        $period = $report['period']['from'] . ' to ' . $report['period']['to'];

        $html  = self::headerHtml($title, $period);
        if (!$report['is_tied_out']) {
            $html .= '<div class="banner-amber">Cash tie-out difference '
                  . self::money($report['tie_diff']) . '</div>';
        }
        $html .= '<table class="rpt">';

        $html .= '<tr class="group"><td colspan="2"><strong>Operating Activities</strong></td></tr>';
        $html .= '<tr><td>Net Income</td><td class="amt">' . self::money($report['net_income']) . '</td></tr>';
        $html .= '<tr><td class="indent">+ Depreciation</td><td class="amt">' . self::money($report['non_cash']['depreciation']) . '</td></tr>';
        $html .= '<tr><td class="indent">+ Asset Disposals</td><td class="amt">' . self::money($report['non_cash']['asset_disposal']) . '</td></tr>';
        $html .= '<tr><td class="indent">+ Bad Debt Expense</td><td class="amt">' . self::money($report['non_cash']['bad_debt']) . '</td></tr>';
        $html .= '<tr><td class="indent">+ FX Revaluation</td><td class="amt">' . self::money($report['non_cash']['fx_revaluation']) . '</td></tr>';
        foreach ($report['working_capital'] as $wc) {
            $html .= '<tr><td class="indent">Δ ' . htmlspecialchars((string) $wc['label']) . '</td>';
            $html .= '<td class="amt">' . self::money($wc['cash_impact']) . '</td></tr>';
        }
        $html .= '<tr class="subtotal"><td><strong>Net Cash from Operating</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['operating_cash']) . '</strong></td></tr>';

        $html .= '<tr class="group"><td colspan="2"><strong>Investing Activities</strong></td></tr>';
        $html .= '<tr><td>Asset Acquisitions</td><td class="amt">(' . self::money($report['investing']['asset_acquisitions']) . ')</td></tr>';
        $html .= '<tr><td>Asset Disposal Proceeds</td><td class="amt">' . self::money($report['investing']['asset_disposal_proceeds']) . '</td></tr>';
        $html .= '<tr class="subtotal"><td><strong>Net Cash from Investing</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['investing']['net']) . '</strong></td></tr>';

        $html .= '<tr class="group"><td colspan="2"><strong>Financing Activities</strong></td></tr>';
        $html .= '<tr><td>Long-Term Debt (net)</td><td class="amt">' . self::money($report['financing']['long_term_debt_net']) . '</td></tr>';
        $html .= '<tr><td>Dividends / Owner Draws</td><td class="amt">(' . self::money($report['financing']['dividends']) . ')</td></tr>';
        $html .= '<tr class="subtotal"><td><strong>Net Cash from Financing</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['financing']['net']) . '</strong></td></tr>';

        $html .= '<tr class="grand"><td><strong>Net Change in Cash</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['net_change']) . '</strong></td></tr>';
        $html .= '<tr><td>Opening Cash</td><td class="amt">' . self::money($report['opening_cash']) . '</td></tr>';
        $html .= '<tr class="grand"><td><strong>Closing Cash (calc)</strong></td>';
        $html .= '<td class="amt"><strong>' . self::money($report['closing_cash_calc']) . '</strong></td></tr>';
        $html .= '<tr><td>Closing Cash (GL 1010)</td><td class="amt">' . self::money($report['closing_cash_gl']) . '</td></tr>';
        $html .= '</table>';

        self::emit($html, $title, 'A4', 'P');
    }

    public static function assetSchedule(array $report): void
    {
        $title  = 'Fixed Asset Schedule';
        $period = 'As of ' . $report['as_of'];

        $html  = self::headerHtml($title, $period);
        $html .= '<table class="rpt">';
        $html .= '<thead><tr>';
        $html .= '<th>Class</th><th>Opening Cost</th><th>Additions</th><th>Disposals</th><th>Closing Cost</th>';
        $html .= '<th>Opening A/D</th><th>Current Depr.</th><th>Closing A/D</th><th>NBV</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($report['classes'] as $cls) {
            $html .= '<tr><td>' . htmlspecialchars(str_replace('_', ' ', $cls['asset_class'])) . '</td>';
            $html .= '<td class="amt">' . self::money($cls['opening_cost']) . '</td>';
            $html .= '<td class="amt">' . self::money($cls['additions']) . '</td>';
            $html .= '<td class="amt">' . self::money($cls['disposals_cost']) . '</td>';
            $html .= '<td class="amt"><strong>' . self::money($cls['closing_cost']) . '</strong></td>';
            $html .= '<td class="amt">' . self::money($cls['opening_accum_dep']) . '</td>';
            $html .= '<td class="amt">' . self::money($cls['current_depr']) . '</td>';
            $html .= '<td class="amt"><strong>' . self::money($cls['closing_accum_dep']) . '</strong></td>';
            $html .= '<td class="amt"><strong>' . self::money($cls['nbv']) . '</strong></td></tr>';
        }
        $html .= '</tbody></table>';

        self::emit($html, $title, 'A4', 'L');
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Common header HTML — company name + report title + period.
     */
    private static function headerHtml(string $title, string $period): string
    {
        $company = \settings_get('company.name') ?: 'FleetForge';
        $now     = date('Y-m-d H:i');
        $css     = <<<'CSS'
<style>
body { font-family: 'dejavusans', sans-serif; font-size: 9pt; color: #1d1d1f; }
.hdr { border-bottom: 2px solid #1d1d1f; padding-bottom: 6px; margin-bottom: 10px; }
.hdr .co { font-size: 14pt; font-weight: 700; }
.hdr .ti { font-size: 11pt; margin-top: 2px; }
.hdr .pe { font-size: 9pt; color: #555; }
.hdr .gen { font-size: 7.5pt; color: #888; margin-top: 4px; }
.banner-red { background: #ffe6e6; border: 1px solid #cc0000; color: #990000;
              padding: 6px 10px; margin-bottom: 8px; font-size: 9pt; }
.banner-amber { background: #fff7d6; border: 1px solid #b8860b; color: #6b4900;
                padding: 6px 10px; margin-bottom: 8px; font-size: 9pt; }
table.rpt { width: 100%; border-collapse: collapse; }
table.rpt th { background: #f0f0f0; padding: 5px 7px; text-align: left; border-bottom: 1px solid #1d1d1f; font-size: 8.5pt; }
table.rpt td { padding: 3px 7px; border-bottom: 1px solid #eee; vertical-align: top; }
table.rpt td.amt { text-align: right; font-family: 'dejavusansmono', monospace; }
table.rpt td.acct { padding-left: 14px; }
table.rpt td.indent { padding-left: 22px; }
table.rpt tr.group td { background: #e8f0fe; font-weight: 600; padding-top: 6px; padding-bottom: 6px; }
table.rpt tr.total td { background: #f7f9fc; }
table.rpt tr.subtotal td { background: #f7f9fc; padding-top: 5px; padding-bottom: 5px; border-top: 1px solid #1d1d1f; }
table.rpt tr.grand td { background: #d8e6ff; padding-top: 6px; padding-bottom: 6px; border-top: 2px solid #1d1d1f; border-bottom: 2px solid #1d1d1f; }
</style>
CSS;
        return $css
            . '<div class="hdr">'
            . '<div class="co">' . htmlspecialchars((string) $company) . '</div>'
            . '<div class="ti">' . htmlspecialchars($title) . '</div>'
            . '<div class="pe">' . htmlspecialchars($period) . '</div>'
            . '<div class="gen">Generated ' . $now . '</div>'
            . '</div>';
    }

    /**
     * Render the ASPE Disclosure Note Pack (Notes 1-9). Each note becomes
     * a section with its title bar + plain-text content (whitespace
     * preserved via white-space:pre-wrap). Cover page lists engagement
     * type + practitioner contact info.
     *
     * Session: S-ACCT-DISC
     */
    public static function disclosureNotePack(array $data): void
    {
        $title       = 'Notes to Financial Statements';
        $fy          = (int) $data['fiscal_year'];
        $entity      = (string) ($data['entity_name'] ?? 'The Company');
        $engagement  = (string) ($data['engagement_type'] ?? 'compilation');
        $cpaFirm     = (string) ($data['cpa_firm'] ?? '');
        $cpaDesig    = (string) ($data['cpa_designation'] ?? '');
        $cpaCity     = (string) ($data['cpa_city'] ?? '');
        $notes       = $data['notes'] ?? [];

        $period = "For the year ended December 31, {$fy}";
        $html   = self::headerHtml($title, $period);

        $engagementLabel = $engagement === 'review' ? 'Review Engagement' : 'Compilation Engagement';

        $html .= '<div style="margin-bottom:14px;padding:8px 12px;background:#f7f9fc;border:1px solid #e1e4e8;font-size:9.5pt;">';
        $html .= '<strong>' . htmlspecialchars($entity) . '</strong><br>';
        $html .= htmlspecialchars($engagementLabel) . '<br>';
        if ($cpaFirm) {
            $cpaLine = $cpaFirm;
            if ($cpaDesig) $cpaLine .= ', ' . $cpaDesig;
            if ($cpaCity)  $cpaLine .= ' — ' . $cpaCity;
            $html .= '<span style="color:#555;">' . htmlspecialchars($cpaLine) . '</span>';
        } else {
            $html .= '<span style="color:#b8860b;">[Practitioner details pending — set in Settings → Engagement.]</span>';
        }
        $html .= '</div>';

        foreach ($notes as $note) {
            $html .= '<div style="page-break-inside:avoid;margin-bottom:14px;">';
            $html .= '<div style="background:#e8f0fe;border-left:3px solid #1d1d1f;padding:6px 10px;margin-bottom:6px;font-weight:600;font-size:10pt;">';
            $html .= 'Note ' . htmlspecialchars((string) $note['note_number'])
                  . ' — ' . htmlspecialchars((string) $note['note_title']);
            if ((int) ($note['is_auto_generated'] ?? 1) === 0) {
                $html .= ' <span style="font-weight:400;color:#888;font-size:8pt;">(edited)</span>';
            }
            $html .= '</div>';
            $html .= '<div style="white-space:pre-wrap;font-size:9pt;line-height:1.4;padding:0 4px;">';
            $html .= htmlspecialchars((string) $note['note_content']);
            $html .= '</div>';
            $html .= '</div>';
        }

        self::emit($html, $title, 'A4', 'P');
    }

    private static function emit(string $html, string $title, string $paper, string $orientation): void
    {
        $tmpDir = FF_ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode'         => 'utf-8',
            'format'       => $paper . '-' . $orientation,
            'margin_top'   => 12,
            'margin_bottom' => 12,
            'margin_left'  => 12,
            'margin_right' => 12,
            'default_font' => 'dejavusans',
            'tempDir'      => $tmpDir,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title) . '_' . date('Ymd') . '.pdf';
        $mpdf->Output($safeName, Destination::INLINE);
    }

    private static function money(string $val): string
    {
        $sign = bccomp($val, '0', 2) < 0 ? '-' : '';
        $abs  = ltrim($val, '-');
        $n    = number_format((float) $abs, 2, '.', ',');
        return $sign . '$' . $n;
    }
}
