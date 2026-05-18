<?php
declare(strict_types=1);

/**
 * lib/Accounting/YearEndService.php
 *
 * Year-end close workflow:
 *   1. preflightCheck — read-only health audit (periods, AR/AP drift,
 *      draft JEs, checklist completeness, prior-closure idempotency).
 *   2. close — under advisory lock, post the closing JE that zeros every
 *      revenue / expense account into Retained Earnings, lock all 12
 *      periods of the fiscal year, seed the next year's 12 periods,
 *      generate the year-end PDF package + manifest.json + ZIP, and
 *      record the closure row.
 *   3. generatePackage — assemble the scoped report bundle defined in
 *      D-S037-YE-2 (8 PDFs + manifest with placeholders for pending
 *      Phase C reports).
 *   4. reverse — super-admin-only. Reverses the closing JE via
 *      JournalEntryService::reverse(), unlocks the periods (status
 *      back to 'closed' — not 'open' — so they remain non-editable),
 *      and flips the closure row to 'reversed'.
 *
 * Schema reality: acc_periods and acc_year_end_checklist use a `year`
 * smallint column; the new acc_year_end_closures table uses `fiscal_year`
 * for API clarity. Bridge in the queries.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.2
 * Session:  S037-YE
 */

namespace FleetForge\Accounting;

use FleetForge\Storage\StorageClient;

class YearEndService
{
    private const AR_DRIFT_TOLERANCE = '1.00';
    private const AP_DRIFT_TOLERANCE = '1.00';

    // ==================================================================
    // PREFLIGHT
    // ==================================================================

    /**
     * Read-only health audit. Returns per-check status. Never throws.
     *
     * @param int  $fiscalYear
     * @param bool $isSuperAdmin  Affects whether AR drift is soft (warning)
     *                            or hard (blocking).
     */
    public static function preflightCheck(int $fiscalYear, bool $isSuperAdmin = false): array
    {
        $yearStart = sprintf('%04d-01-01', $fiscalYear);
        $yearEnd   = sprintf('%04d-12-31', $fiscalYear);

        // ── 1. periods_complete: 12 periods for the fiscal year, none in 'pending' state
        // The acc_periods.status ENUM is open|closed|locked — no 'pending'. We
        // require all 12 monthly periods to exist; missing ones are a fail.
        $periodRows = \db_select(
            "SELECT id, name, status FROM acc_periods
              WHERE `year` = ?
              ORDER BY `month`",
            [$fiscalYear]
        );
        $periodCount = count($periodRows);
        $periodNamesNonCompliant = array_values(array_filter(
            array_map(fn($r) => !in_array($r['status'], ['open', 'closed', 'locked'], true) ? $r['name'] : null, $periodRows),
            fn($n) => $n !== null
        ));
        $periodsCheck = [
            'pass'   => $periodCount === 12 && empty($periodNamesNonCompliant),
            'detail' => $periodCount === 12
                ? (empty($periodNamesNonCompliant)
                    ? 'All 12 periods present.'
                    : 'Non-compliant period statuses: ' . implode(', ', $periodNamesNonCompliant))
                : "Only {$periodCount}/12 periods found.",
            'period_count' => $periodCount,
        ];

        // ── 2. ar_drift — soft gate for super_admin (D-S037-YE-1)
        $arRecon = AccountingService::arReconciliationCheck();
        $arDriftAbs = bccomp($arRecon['difference'], '0', 2) < 0
            ? bcmul($arRecon['difference'], '-1', 2)
            : (string) $arRecon['difference'];
        $arOverTolerance = bccomp($arDriftAbs, self::AR_DRIFT_TOLERANCE, 2) > 0;
        $arCheck = [
            'pass'         => !$arOverTolerance,
            'drift_amount' => $arRecon['difference'],
            'gl_balance'   => $arRecon['gl_balance'],
            'subledger_balance' => $arRecon['subledger_balance'],
            'is_blocking'  => $arOverTolerance && !$isSuperAdmin,
            'is_warning'   => $arOverTolerance && $isSuperAdmin,
        ];

        // ── 3. ap_drift — hard gate (always blocking when over tolerance)
        $apRecon = AccountingService::apReconciliationCheck();
        $apDriftAbs = bccomp($apRecon['difference'], '0', 2) < 0
            ? bcmul($apRecon['difference'], '-1', 2)
            : (string) $apRecon['difference'];
        $apOverTolerance = bccomp($apDriftAbs, self::AP_DRIFT_TOLERANCE, 2) > 0;
        $apCheck = [
            'pass'         => !$apOverTolerance,
            'drift_amount' => $apRecon['difference'],
            'gl_balance'   => $apRecon['gl_balance'],
            'subledger_balance' => $apRecon['subledger_balance'],
            'is_blocking'  => $apOverTolerance,
        ];

        // ── 4. unposted_jes
        $unpostedRow = \db_row(
            "SELECT COUNT(*) AS n FROM acc_journal_entries
              WHERE status = 'draft'
                AND entry_date BETWEEN ? AND ?",
            [$yearStart, $yearEnd]
        );
        $unpostedCount = (int) ($unpostedRow['n'] ?? 0);
        $jeCheck = [
            'pass'        => $unpostedCount === 0,
            'count'       => $unpostedCount,
            'is_blocking' => $unpostedCount > 0,
        ];

        // ── 5. checklist_complete
        $clRow = \db_row(
            "SELECT
                COALESCE(SUM(CASE WHEN is_complete = 0 THEN 1 ELSE 0 END), 0) AS incomplete,
                COUNT(*) AS total
               FROM acc_year_end_checklist
              WHERE `year` = ?",
            [$fiscalYear]
        );
        $incompleteCount = (int) ($clRow['incomplete'] ?? 0);
        $clCheck = [
            'pass'             => $incompleteCount === 0,
            'incomplete_count' => $incompleteCount,
            'total_count'      => (int) ($clRow['total'] ?? 0),
            'is_blocking'      => $incompleteCount > 0,
        ];

        // ── 6. already_closed (idempotency)
        $closure = \db_row(
            "SELECT id, status FROM acc_year_end_closures
              WHERE fiscal_year = ? AND status = 'closed' LIMIT 1",
            [$fiscalYear]
        );
        $alreadyClosedCheck = [
            'pass'          => !$closure,
            'closure_id'    => $closure ? (int) $closure['id'] : null,
            'is_blocking'   => (bool) $closure,
        ];

        $canProceed = $periodsCheck['pass']
            && !$arCheck['is_blocking']
            && !$apCheck['is_blocking']
            && $jeCheck['pass']
            && $clCheck['pass']
            && $alreadyClosedCheck['pass'];

        return [
            'fiscal_year' => $fiscalYear,
            'checks' => [
                'periods_complete'    => $periodsCheck,
                'ar_drift'            => $arCheck,
                'ap_drift'            => $apCheck,
                'unposted_jes'        => $jeCheck,
                'checklist_complete'  => $clCheck,
                'already_closed'      => $alreadyClosedCheck,
            ],
            'can_proceed' => $canProceed,
            'requires_super_admin_override' => $arCheck['is_warning'],
        ];
    }

    // ==================================================================
    // CLOSE
    // ==================================================================

    /**
     * Run the year-end close.
     *
     * @param int  $fiscalYear
     * @param int  $userId
     * @param bool $isSuperAdmin
     * @param bool $arDriftOverride  Required when AR drift > tolerance and
     *                               the user is super_admin (soft gate per
     *                               D-S037-YE-1).
     */
    public static function close(int $fiscalYear, int $userId, bool $isSuperAdmin = false, bool $arDriftOverride = false): array
    {
        $preflight = self::preflightCheck($fiscalYear, $isSuperAdmin);
        if (!$preflight['can_proceed']) {
            throw new \RuntimeException('Pre-flight checks failed. Resolve the failing checks before closing.');
        }
        if ($preflight['requires_super_admin_override'] && !$arDriftOverride) {
            throw new \RuntimeException('AR drift exceeds tolerance. Super-admin override flag is required.');
        }
        if (!$preflight['checks']['already_closed']['pass']) {
            throw new \RuntimeException("Fiscal year {$fiscalYear} is already closed.");
        }

        $yearEnd = sprintf('%04d-12-31', $fiscalYear);

        // Resolve retained earnings account.
        $reAccountId = (int) AccountingService::setting('accounting.retained_earnings_account_id', 0);
        if ($reAccountId <= 0) {
            $row = \db_row(
                "SELECT id FROM acc_accounts
                  WHERE (code LIKE '3500%%' OR code LIKE '3020%%' OR LOWER(name) LIKE '%%retained%%')
                    AND account_type = 'equity'
                    AND is_active = 1
                  ORDER BY code ASC LIMIT 1"
            );
            if (!$row) {
                throw new \RuntimeException(
                    'Retained earnings account not configured. Set accounting.retained_earnings_account_id in Accounting Settings.'
                );
            }
            $reAccountId = (int) $row['id'];
        }

        return \db_transaction(function () use ($fiscalYear, $userId, $arDriftOverride, $yearEnd, $reAccountId) {
            $lockRow = \db_row("SELECT GET_LOCK(?, 30) AS got", ["year_end_close_{$fiscalYear}"]);
            if (!$lockRow || (int) $lockRow['got'] !== 1) {
                throw new \RuntimeException('Could not acquire year-end lock.');
            }

            try {
                // Re-check idempotency under the lock
                $existing = \db_row(
                    "SELECT id FROM acc_year_end_closures
                      WHERE fiscal_year = ? AND status = 'closed' LIMIT 1",
                    [$fiscalYear]
                );
                if ($existing) {
                    throw new \RuntimeException("Fiscal year {$fiscalYear} is already closed.");
                }

                // ── Compute revenue + expense balances at year-end
                $revenueAccts = \db_select(
                    "SELECT id, code, name, account_type, normal_balance
                       FROM acc_accounts
                      WHERE account_type IN ('revenue','other_income')
                        AND is_active = 1
                        AND is_header = 0
                      ORDER BY sort_order, code",
                    []
                );
                $expenseAccts = \db_select(
                    "SELECT id, code, name, account_type, normal_balance
                       FROM acc_accounts
                      WHERE account_type IN ('cost_of_revenue','operating_expense','other_expense')
                        AND is_active = 1
                        AND is_header = 0
                      ORDER BY sort_order, code",
                    []
                );

                $lines = [];
                $totalRevenue = '0.00';
                $totalExpense = '0.00';

                // Revenue (credit-normal) → DR account, sum to revenue total
                foreach ($revenueAccts as $a) {
                    $bal = AccountingService::accountBalance((int) $a['id'], $yearEnd);
                    if (bccomp($bal, '0', 2) === 0) continue;
                    // accountBalance returns positive when account has its normal balance.
                    // Closing entry: debit the account by its balance.
                    $lines[] = [
                        'account_id'  => (int) $a['id'],
                        'debit'       => $bal,
                        'credit'      => '0.00',
                        'description' => "Close {$a['code']} {$a['name']}",
                    ];
                    $totalRevenue = bcadd($totalRevenue, $bal, 2);
                }

                // Expense (debit-normal) → CR account, sum to expense total
                foreach ($expenseAccts as $a) {
                    $bal = AccountingService::accountBalance((int) $a['id'], $yearEnd);
                    if (bccomp($bal, '0', 2) === 0) continue;
                    $lines[] = [
                        'account_id'  => (int) $a['id'],
                        'debit'       => '0.00',
                        'credit'      => $bal,
                        'description' => "Close {$a['code']} {$a['name']}",
                    ];
                    $totalExpense = bcadd($totalExpense, $bal, 2);
                }

                $netIncome = bcsub($totalRevenue, $totalExpense, 2);

                // Retained earnings balancing line
                if (bccomp($netIncome, '0', 2) > 0) {
                    // Net income positive: CR retained earnings (credit-normal, balance grows)
                    $lines[] = [
                        'account_id'  => $reAccountId,
                        'debit'       => '0.00',
                        'credit'      => $netIncome,
                        'description' => "Net income to Retained Earnings for FY {$yearEnd}",
                    ];
                } elseif (bccomp($netIncome, '0', 2) < 0) {
                    $abs = bcmul($netIncome, '-1', 2);
                    $lines[] = [
                        'account_id'  => $reAccountId,
                        'debit'       => $abs,
                        'credit'      => '0.00',
                        'description' => "Net loss to Retained Earnings for FY {$yearEnd}",
                    ];
                }

                $closingJeId = null;
                $closingJe   = null;
                if (count($lines) >= 2) {
                    // Validate balance
                    $sumDr = '0.00'; $sumCr = '0.00';
                    foreach ($lines as $l) {
                        $sumDr = bcadd($sumDr, $l['debit'], 2);
                        $sumCr = bcadd($sumCr, $l['credit'], 2);
                    }
                    if (bccomp($sumDr, $sumCr, 2) !== 0) {
                        throw new \RuntimeException(
                            "Closing JE unbalanced: debits ({$sumDr}) ≠ credits ({$sumCr}). "
                            . "This indicates a chart-of-accounts configuration issue."
                        );
                    }

                    $closingJe = JournalEntryService::create([
                        'entry_date'       => $yearEnd,
                        'description'      => "Year-End Close — Fiscal Year {$fiscalYear}",
                        'entry_type'       => 'year_end',
                        'reference'        => "YE-{$fiscalYear}",
                        'source_type'      => 'year_end',
                        'currency'         => 'CAD',
                        'post_immediately' => true,
                    ], $lines, $userId);

                    $closingJeId = (int) $closingJe['id'];
                }

                // ── Lock all 12 periods of the fiscal year
                \db_execute(
                    "UPDATE acc_periods
                        SET status = 'locked', locked_by = ?, locked_at = NOW()
                      WHERE `year` = ?",
                    [$userId, $fiscalYear]
                );

                // ── Seed the next fiscal year's 12 periods (idempotent — ON DUP KEY)
                $nextYear = $fiscalYear + 1;
                $monthsCreated = 0;
                for ($m = 1; $m <= 12; $m++) {
                    $start = sprintf('%04d-%02d-01', $nextYear, $m);
                    $end   = date('Y-m-t', strtotime($start));
                    $name  = date('F Y', strtotime($start));
                    $existing = \db_row("SELECT id FROM acc_periods WHERE `year` = ? AND `month` = ?", [$nextYear, $m]);
                    if (!$existing) {
                        \db_insert('acc_periods', [
                            'year'       => $nextYear,
                            'month'      => $m,
                            'name'       => $name,
                            'start_date' => $start,
                            'end_date'   => $end,
                            'status'     => 'open',
                            'is_year_end' => $m === 12 ? 1 : 0,
                        ]);
                        $monthsCreated++;
                    }
                }

                // ── Insert closure row (without package_path/hash yet — generated below)
                $closureId = \db_insert('acc_year_end_closures', [
                    'fiscal_year'   => $fiscalYear,
                    'closed_at'     => date('Y-m-d H:i:s'),
                    'closed_by'     => $userId,
                    'closing_je_id' => $closingJeId,
                    'package_path'  => null,
                    'package_hash'  => null,
                    'status'        => 'closed',
                    'notes'         => $arDriftOverride
                        ? 'AR drift override applied (super_admin).'
                        : null,
                ]);

                \db_execute("SELECT RELEASE_LOCK(?)", ["year_end_close_{$fiscalYear}"]);

                // ── Generate package outside the transaction lock release path
                // (package gen is mostly I/O; if it fails we still want the
                // close to be committed so the operator can regenerate).
                $packageResult = null;
                try {
                    $packageResult = self::generatePackage($fiscalYear, $closureId, $userId);
                    \db_update(
                        'acc_year_end_closures',
                        [
                            'package_path' => $packageResult['package_path'],
                            'package_hash' => $packageResult['package_hash'],
                        ],
                        'id = ?',
                        [$closureId]
                    );
                } catch (\Throwable $e) {
                    error_log('YearEnd package generation failed: ' . $e->getMessage());
                    \db_update(
                        'acc_year_end_closures',
                        ['notes' => 'Close committed; package generation failed: ' . $e->getMessage()],
                        'id = ?',
                        [$closureId]
                    );
                }

                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'user_name'   => (function_exists('current_user') ? (\current_user()['name'] ?? 'system') : 'system'),
                    'action'      => 'status_change',
                    'module'      => 'accounting',
                    'entity_type' => 'year_end_closure',
                    'entity_id'   => $closureId,
                    'entity_label' => "FY {$fiscalYear}",
                    'notes'       => sprintf(
                        'Year-end close completed for FY%d. JE=%s. Revenue closed: $%s. Expenses closed: $%s. Net income: $%s. AR override: %s.',
                        $fiscalYear,
                        $closingJe ? $closingJe['entry_number'] : '(none — zero balances)',
                        $totalRevenue,
                        $totalExpense,
                        $netIncome,
                        $arDriftOverride ? 'YES' : 'no'
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                return [
                    'closure'             => \db_row("SELECT * FROM acc_year_end_closures WHERE id = ?", [$closureId]),
                    'closing_je'          => $closingJe,
                    'new_periods_created' => $monthsCreated,
                    'net_income'          => $netIncome,
                    'total_revenue'       => $totalRevenue,
                    'total_expense'       => $totalExpense,
                    'package'             => $packageResult,
                ];
            } catch (\Throwable $e) {
                \db_execute("SELECT RELEASE_LOCK(?)", ["year_end_close_{$fiscalYear}"]);
                throw $e;
            }
        });
    }

    // ==================================================================
    // PACKAGE GENERATION
    // ==================================================================

    /**
     * Build the scoped year-end ZIP package (D-S037-YE-2).
     */
    public static function generatePackage(int $fiscalYear, int $closureId, ?int $userId = null): array
    {
        $yearStart = sprintf('%04d-01-01', $fiscalYear);
        $yearEnd   = sprintf('%04d-12-31', $fiscalYear);

        $storageRoot = defined('FF_ROOT') ? FF_ROOT . '/storage/year_end_packages' : __DIR__ . '/../../storage/year_end_packages';
        $dir = $storageRoot . '/' . $fiscalYear;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Could not create package directory: {$dir}");
        }

        $manifest = [
            'fiscal_year'   => $fiscalYear,
            'closure_id'    => $closureId,
            'generated_at'  => date('Y-m-d H:i:s'),
            'generated_by_user_id' => $userId,
            'files'         => [],
        ];

        $writeReport = static function (string $filename, callable $renderHtml, string $orientation = 'P') use ($dir, &$manifest): void {
            $path = $dir . '/' . $filename;
            try {
                $tmpDir = defined('FF_ROOT') ? FF_ROOT . '/storage/tmp' : sys_get_temp_dir();
                if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
                $mpdf = new \Mpdf\Mpdf([
                    'mode'         => 'utf-8',
                    'format'       => 'A4-' . $orientation,
                    'margin_top'   => 12,
                    'margin_bottom'=> 12,
                    'margin_left'  => 12,
                    'margin_right' => 12,
                    'default_font' => 'dejavusans',
                    'tempDir'      => $tmpDir,
                ]);
                $mpdf->WriteHTML($renderHtml());
                $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
                $manifest['files'][] = [
                    'file'   => $filename,
                    'hash'   => hash_file('sha256', $path),
                    'status' => 'complete',
                ];
            } catch (\Throwable $e) {
                $errPath = $dir . '/' . $filename . '.error.txt';
                file_put_contents($errPath, $e->getMessage());
                $manifest['files'][] = [
                    'file'   => $filename,
                    'hash'   => null,
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ];
            }
        };

        // 01 — Trial Balance
        $writeReport('01_trial_balance.pdf', fn() => self::renderTrialBalanceHtml($yearEnd, $fiscalYear), 'P');
        // 02 — P&L
        $writeReport('02_profit_loss.pdf', function () use ($yearStart, $yearEnd, $fiscalYear) {
            $r = ReportingService::profitAndLoss($yearStart, $yearEnd);
            return self::wrapHtml("Profit & Loss — FY {$fiscalYear}", $yearStart . ' to ' . $yearEnd, self::renderPLBody($r));
        }, 'L');
        // 03 — Balance Sheet
        $writeReport('03_balance_sheet.pdf', function () use ($yearEnd, $fiscalYear) {
            $r = ReportingService::balanceSheet($yearEnd);
            return self::wrapHtml("Balance Sheet — FY {$fiscalYear}", 'As of ' . $yearEnd, self::renderBSBody($r));
        }, 'P');
        // 04 — Cash Flow
        $writeReport('04_cash_flow.pdf', function () use ($yearStart, $yearEnd, $fiscalYear) {
            $r = ReportingService::cashFlow($yearStart, $yearEnd);
            return self::wrapHtml("Cash Flow Statement — FY {$fiscalYear}", $yearStart . ' to ' . $yearEnd, self::renderCFBody($r));
        }, 'P');
        // 05 — Asset Schedule
        $writeReport('05_asset_schedule.pdf', function () use ($yearEnd, $fiscalYear) {
            $r = ReportingService::assetSchedule($yearEnd);
            return self::wrapHtml("Fixed Asset Schedule — FY {$fiscalYear}", 'As of ' . $yearEnd, self::renderASBody($r));
        }, 'L');
        // 06 — AR Aging
        $writeReport('06_ar_aging.pdf', fn() => self::renderArAgingHtml($yearEnd, $fiscalYear), 'P');
        // 07 — AP Aging
        $writeReport('07_ap_aging.pdf', fn() => self::renderApAgingHtml($yearEnd, $fiscalYear), 'P');
        // 08 — FX Revaluation Summary
        $writeReport('08_fx_revaluation_summary.pdf', fn() => self::renderFxSummaryHtml($fiscalYear), 'P');

        // Placeholders for pending Phase C reports
        foreach ([
            '09_lead_schedules.pdf'      => 'pending_phase_c',
            '10_cca_schedule_8.pdf'      => 'pending_phase_c',
            '11_disclosure_notes.pdf'    => 'pending_phase_c',
            '12_lease_amortization.pdf'  => 'pending_phase_c',
        ] as $filename => $status) {
            $manifest['files'][] = [
                'file'   => $filename,
                'hash'   => null,
                'status' => $status,
            ];
        }

        // manifest.json
        $manifestPath = $dir . '/manifest.json';
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($manifestPath, $manifestJson);

        // ZIP
        $zipPath = $dir . "/year_end_package_{$fiscalYear}.zip";
        @unlink($zipPath); // rebuild on regeneration
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('ZipArchive PHP extension not available — see PREDEPLOY D-YE-2.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create zip: {$zipPath}");
        }
        foreach (glob($dir . '/*') as $f) {
            if (basename($f) === basename($zipPath)) continue; // don't include the zip in itself
            $zip->addFile($f, basename($f));
        }
        $zip->close();

        $hash = hash_file('sha256', $zipPath);
        $relativePath = 'year_end_packages/' . $fiscalYear . '/year_end_package_' . $fiscalYear . '.zip';

        return [
            'package_path' => $relativePath,
            'package_hash' => $hash,
            'file_count'   => count($manifest['files']),
            'absolute_path' => $zipPath,
        ];
    }

    // ── PDF rendering helpers ────────────────────────────────────────────

    private static function wrapHtml(string $title, string $subtitle, string $body): string
    {
        $company = function_exists('settings_get') ? (\settings_get('company.name') ?: 'FleetForge') : 'FleetForge';
        $now     = date('Y-m-d H:i');
        return <<<HTML
<style>
body { font-family: 'dejavusans', sans-serif; font-size: 9pt; color: #1d1d1f; }
.hdr { border-bottom: 2px solid #1d1d1f; padding-bottom: 6px; margin-bottom: 10px; }
.hdr .co { font-size: 14pt; font-weight: 700; }
.hdr .ti { font-size: 11pt; margin-top: 2px; }
.hdr .pe { font-size: 9pt; color: #555; }
table.rpt { width: 100%; border-collapse: collapse; }
table.rpt th { background: #f0f0f0; padding: 5px 7px; text-align: left; border-bottom: 1px solid #1d1d1f; font-size: 8.5pt; }
table.rpt td { padding: 3px 7px; border-bottom: 1px solid #eee; vertical-align: top; }
table.rpt td.amt { text-align: right; font-family: 'dejavusansmono', monospace; }
table.rpt tr.total td { background: #f7f9fc; font-weight: 600; }
table.rpt tr.group td { background: #e8f0fe; font-weight: 600; }
</style>
<div class="hdr">
    <div class="co">{$company}</div>
    <div class="ti">{$title}</div>
    <div class="pe">{$subtitle}</div>
    <div class="pe" style="font-size:7.5pt;color:#888;">Generated {$now}</div>
</div>
{$body}
HTML;
    }

    private static function money(string $val): string
    {
        $sign = bccomp($val, '0', 2) < 0 ? '-' : '';
        $abs  = ltrim($val, '-');
        return $sign . '$' . number_format((float) $abs, 2, '.', ',');
    }

    private static function renderTrialBalanceHtml(string $asOf, int $fiscalYear): string
    {
        $balances = AccountingService::allAccountBalances($asOf);
        $accounts = \db_select(
            "SELECT id, code, name, account_type, normal_balance
               FROM acc_accounts
              WHERE is_active = 1 AND is_header = 0
              ORDER BY sort_order, code"
        );
        $html = '<table class="rpt"><thead><tr>';
        $html .= '<th>Account</th><th class="amt">Debit</th><th class="amt">Credit</th>';
        $html .= '</tr></thead><tbody>';
        $totalDr = '0.00'; $totalCr = '0.00';
        foreach ($accounts as $a) {
            $aid = (int) $a['id'];
            $dr  = (string) ($balances[$aid]['debit_total']  ?? '0.00');
            $cr  = (string) ($balances[$aid]['credit_total'] ?? '0.00');
            if (bccomp($dr, '0', 2) === 0 && bccomp($cr, '0', 2) === 0) continue;
            $net = bcsub($dr, $cr, 2);
            $tDr = '0.00'; $tCr = '0.00';
            if ($a['normal_balance'] === 'debit') {
                if (bccomp($net, '0', 2) >= 0) $tDr = $net;
                else $tCr = bcmul($net, '-1', 2);
            } else {
                $nc = bcsub($cr, $dr, 2);
                if (bccomp($nc, '0', 2) >= 0) $tCr = $nc;
                else $tDr = bcmul($nc, '-1', 2);
            }
            $totalDr = bcadd($totalDr, $tDr, 2);
            $totalCr = bcadd($totalCr, $tCr, 2);
            $html .= '<tr><td>' . htmlspecialchars($a['code'] . ' — ' . $a['name']) . '</td>';
            $html .= '<td class="amt">' . (bccomp($tDr, '0', 2) > 0 ? self::money($tDr) : '') . '</td>';
            $html .= '<td class="amt">' . (bccomp($tCr, '0', 2) > 0 ? self::money($tCr) : '') . '</td></tr>';
        }
        $html .= '<tr class="total"><td>Totals</td>';
        $html .= '<td class="amt">' . self::money($totalDr) . '</td>';
        $html .= '<td class="amt">' . self::money($totalCr) . '</td></tr>';
        $html .= '</tbody></table>';
        return self::wrapHtml("Trial Balance — FY {$fiscalYear}", "As of {$asOf}", $html);
    }

    private static function renderPLBody(array $r): string
    {
        $h = '<table class="rpt">';
        $group = static function (string $label, array $rows, string $total) {
            $o  = '<tr class="group"><td colspan="2">' . htmlspecialchars($label) . '</td></tr>';
            foreach ($rows as $row) {
                $o .= '<tr><td>' . htmlspecialchars($row['code'] . ' — ' . $row['name']) . '</td>';
                $o .= '<td class="amt">' . self::money($row['amount']) . '</td></tr>';
            }
            $o .= '<tr class="total"><td>Total ' . htmlspecialchars($label) . '</td>';
            $o .= '<td class="amt">' . self::money($total) . '</td></tr>';
            return $o;
        };
        $h .= $group('Revenue', $r['revenue'], $r['revenue_total']);
        $h .= $group('Cost of Revenue', $r['direct_costs'], $r['direct_costs_total']);
        $h .= '<tr class="total"><td>Gross Profit</td><td class="amt">' . self::money($r['gross_profit']) . '</td></tr>';
        $h .= $group('Operating Expenses', $r['operating_expenses'], $r['opex_total']);
        $h .= '<tr class="total"><td>Operating Income</td><td class="amt">' . self::money($r['operating_income']) . '</td></tr>';
        $h .= $group('Other Income / Expense', $r['other'], $r['other_total']);
        $h .= '<tr class="total"><td>Net Income Before Tax</td><td class="amt">' . self::money($r['net_income_before_tax']) . '</td></tr>';
        $h .= '<tr><td>Tax Provision</td><td class="amt">' . self::money($r['tax_provision']) . '</td></tr>';
        $h .= '<tr class="total"><td>Net Income</td><td class="amt">' . self::money($r['net_income']) . '</td></tr>';
        $h .= '</table>';
        return $h;
    }

    private static function renderBSBody(array $r): string
    {
        $h = '<table class="rpt">';
        $section = static function (string $label, array $rows, string $total) {
            $o = '<tr class="group"><td colspan="2">' . htmlspecialchars($label) . '</td></tr>';
            foreach ($rows as $row) {
                $o .= '<tr><td>' . htmlspecialchars($row['code'] . ' — ' . $row['name']) . '</td>';
                $o .= '<td class="amt">' . self::money($row['amount']) . '</td></tr>';
            }
            $o .= '<tr class="total"><td>Total ' . htmlspecialchars($label) . '</td>';
            $o .= '<td class="amt">' . self::money($total) . '</td></tr>';
            return $o;
        };
        $h .= $section('Current Assets', $r['current_assets'], $r['current_assets_total']);
        $h .= $section('Long-Term Assets', $r['long_term_assets'], $r['long_term_assets_total']);
        $h .= '<tr class="total"><td>Total Assets</td><td class="amt">' . self::money($r['total_assets']) . '</td></tr>';
        $h .= $section('Current Liabilities', $r['current_liabilities'], $r['current_liabilities_total']);
        $h .= $section('Long-Term Liabilities', $r['long_term_liabilities'], $r['long_term_liabilities_total']);
        $h .= '<tr class="total"><td>Total Liabilities</td><td class="amt">' . self::money($r['total_liabilities']) . '</td></tr>';
        $h .= $section('Equity', $r['equity'], $r['total_equity']);
        $h .= '<tr><td>Net Income (YTD, injected)</td><td class="amt">' . self::money($r['net_income_injected']) . '</td></tr>';
        $h .= '<tr class="total"><td>Total Liabilities + Equity</td><td class="amt">' . self::money($r['total_liabilities_and_equity']) . '</td></tr>';
        if (!$r['is_balanced']) {
            $h .= '<tr><td colspan="2" style="color:#990000;">⚠ Balance check: drift ' . self::money($r['drift']) . '</td></tr>';
        }
        $h .= '</table>';
        return $h;
    }

    private static function renderCFBody(array $r): string
    {
        $h = '<table class="rpt">';
        $h .= '<tr class="group"><td colspan="2">Operating Activities</td></tr>';
        $h .= '<tr><td>Net Income</td><td class="amt">' . self::money($r['net_income']) . '</td></tr>';
        foreach (['depreciation' => 'Depreciation', 'asset_disposal' => 'Asset Disposals', 'bad_debt' => 'Bad Debt', 'fx_revaluation' => 'FX Revaluation'] as $k => $label) {
            $h .= '<tr><td>+ ' . $label . '</td><td class="amt">' . self::money($r['non_cash'][$k]) . '</td></tr>';
        }
        foreach ($r['working_capital'] as $wc) {
            $h .= '<tr><td>Δ ' . htmlspecialchars((string) $wc['label']) . '</td><td class="amt">' . self::money($wc['cash_impact']) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>Net Cash from Operating</td><td class="amt">' . self::money($r['operating_cash']) . '</td></tr>';
        $h .= '<tr class="group"><td colspan="2">Investing Activities</td></tr>';
        $h .= '<tr><td>Asset Acquisitions</td><td class="amt">(' . self::money($r['investing']['asset_acquisitions']) . ')</td></tr>';
        $h .= '<tr><td>Asset Disposal Proceeds</td><td class="amt">' . self::money($r['investing']['asset_disposal_proceeds']) . '</td></tr>';
        $h .= '<tr class="total"><td>Net Cash from Investing</td><td class="amt">' . self::money($r['investing']['net']) . '</td></tr>';
        $h .= '<tr class="group"><td colspan="2">Financing Activities</td></tr>';
        $h .= '<tr><td>Long-Term Debt (net)</td><td class="amt">' . self::money($r['financing']['long_term_debt_net']) . '</td></tr>';
        $h .= '<tr><td>Dividends</td><td class="amt">(' . self::money($r['financing']['dividends']) . ')</td></tr>';
        $h .= '<tr class="total"><td>Net Cash from Financing</td><td class="amt">' . self::money($r['financing']['net']) . '</td></tr>';
        $h .= '<tr class="total"><td>Net Change in Cash</td><td class="amt">' . self::money($r['net_change']) . '</td></tr>';
        $h .= '<tr><td>Opening Cash</td><td class="amt">' . self::money($r['opening_cash']) . '</td></tr>';
        $h .= '<tr class="total"><td>Closing Cash (calculated)</td><td class="amt">' . self::money($r['closing_cash_calc']) . '</td></tr>';
        $h .= '<tr><td>Closing Cash (GL 1010)</td><td class="amt">' . self::money($r['closing_cash_gl']) . '</td></tr>';
        $h .= '</table>';
        return $h;
    }

    private static function renderASBody(array $r): string
    {
        $h = '<table class="rpt"><thead><tr>';
        $h .= '<th>Class</th><th class="amt">Opening Cost</th><th class="amt">Additions</th><th class="amt">Disposals</th><th class="amt">Closing Cost</th>';
        $h .= '<th class="amt">Opening A/D</th><th class="amt">Current Depr</th><th class="amt">Closing A/D</th><th class="amt">NBV</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($r['classes'] as $c) {
            $h .= '<tr><td>' . htmlspecialchars(str_replace('_', ' ', $c['asset_class'])) . '</td>';
            $h .= '<td class="amt">' . self::money($c['opening_cost']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['additions']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['disposals_cost']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['closing_cost']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['opening_accum_dep']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['current_depr']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['closing_accum_dep']) . '</td>';
            $h .= '<td class="amt">' . self::money($c['nbv']) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    private static function renderArAgingHtml(string $asOf, int $fiscalYear): string
    {
        $rows = \db_select(
            "SELECT c.name AS customer_name, i.invoice_number, i.invoice_date, i.due_date, i.balance_due,
                    DATEDIFF(?, i.due_date) AS days_overdue
               FROM invoices i
               JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
              WHERE i.status NOT IN ('paid','void','written_off')
                AND i.deleted_at IS NULL
                AND i.balance_due > 0
                AND i.invoice_date <= ?
              ORDER BY i.due_date ASC",
            [$asOf, $asOf]
        );
        $h = '<table class="rpt"><thead><tr>';
        $h .= '<th>Customer</th><th>Invoice #</th><th>Due Date</th><th class="amt">Balance</th><th class="amt">Days Overdue</th>';
        $h .= '</tr></thead><tbody>';
        $total = '0.00';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . htmlspecialchars($r['customer_name']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['invoice_number']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['due_date']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['balance_due']) . '</td>';
            $h .= '<td class="amt">' . (int) $r['days_overdue'] . '</td></tr>';
            $total = bcadd($total, (string) $r['balance_due'], 2);
        }
        $h .= '<tr class="total"><td colspan="3">Total Outstanding AR</td><td class="amt">' . self::money($total) . '</td><td></td></tr>';
        $h .= '</tbody></table>';
        return self::wrapHtml("AR Aging — FY {$fiscalYear}", "As of {$asOf}", $h);
    }

    private static function renderApAgingHtml(string $asOf, int $fiscalYear): string
    {
        $rows = \db_select(
            "SELECT v.name AS vendor_name, b.bill_number, b.bill_date, b.due_date, b.balance_due,
                    DATEDIFF(?, b.due_date) AS days_overdue
               FROM acc_bills b
               JOIN vendors v ON v.id = b.vendor_id
              WHERE b.status NOT IN ('paid','void')
                AND b.balance_due > 0
                AND b.bill_date <= ?
              ORDER BY b.due_date ASC",
            [$asOf, $asOf]
        );
        $h = '<table class="rpt"><thead><tr>';
        $h .= '<th>Vendor</th><th>Bill #</th><th>Due Date</th><th class="amt">Balance</th><th class="amt">Days Overdue</th>';
        $h .= '</tr></thead><tbody>';
        $total = '0.00';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . htmlspecialchars($r['vendor_name']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['bill_number']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['due_date']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['balance_due']) . '</td>';
            $h .= '<td class="amt">' . (int) $r['days_overdue'] . '</td></tr>';
            $total = bcadd($total, (string) $r['balance_due'], 2);
        }
        $h .= '<tr class="total"><td colspan="3">Total Outstanding AP</td><td class="amt">' . self::money($total) . '</td><td></td></tr>';
        $h .= '</tbody></table>';
        return self::wrapHtml("AP Aging — FY {$fiscalYear}", "As of {$asOf}", $h);
    }

    private static function renderFxSummaryHtml(int $fiscalYear): string
    {
        $rows = \db_select(
            "SELECT r.revaluation_date, p.name AS period_name, r.exchange_rate_used,
                    r.total_ar_usd, r.total_ar_cad_book, r.total_ar_cad_revalued,
                    r.unrealized_gain_loss, r.status
               FROM acc_fx_revaluations r
               JOIN acc_periods p ON p.id = r.period_id
              WHERE YEAR(r.revaluation_date) = ?
              ORDER BY r.revaluation_date",
            [$fiscalYear]
        );
        $h = '<table class="rpt"><thead><tr>';
        $h .= '<th>Period</th><th>Date</th><th class="amt">Rate</th><th class="amt">USD Total</th><th class="amt">CAD Book</th><th class="amt">Revalued</th><th class="amt">Gain/Loss</th><th>Status</th>';
        $h .= '</tr></thead><tbody>';
        $total = '0.00';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . htmlspecialchars($r['period_name']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['revaluation_date']) . '</td>';
            $h .= '<td class="amt">' . htmlspecialchars($r['exchange_rate_used']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['total_ar_usd']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['total_ar_cad_book']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['total_ar_cad_revalued']) . '</td>';
            $h .= '<td class="amt">' . self::money((string) $r['unrealized_gain_loss']) . '</td>';
            $h .= '<td>' . htmlspecialchars($r['status']) . '</td></tr>';
            if ($r['status'] === 'posted') {
                $total = bcadd($total, (string) $r['unrealized_gain_loss'], 2);
            }
        }
        if (empty($rows)) {
            $h .= '<tr><td colspan="8" style="text-align:center;color:#666;">No FX revaluations posted for fiscal year ' . $fiscalYear . '.</td></tr>';
        }
        $h .= '<tr class="total"><td colspan="6">Net Posted Gain / (Loss)</td><td class="amt">' . self::money($total) . '</td><td></td></tr>';
        $h .= '</tbody></table>';
        return self::wrapHtml("FX Revaluation Summary — FY {$fiscalYear}", '', $h);
    }

    // ==================================================================
    // REVERSE
    // ==================================================================

    /**
     * Super-admin reverse of a posted year-end closure. Reverses the
     * closing JE, drops the periods back to 'closed' (NOT 'open' —
     * they remain non-editable until the operator decides), and flips
     * the closure row to 'reversed'.
     *
     * @param int    $fiscalYear
     * @param int    $userId
     * @param string $reason  audit-log narrative
     */
    public static function reverse(int $fiscalYear, int $userId, string $reason): array
    {
        $row = \db_row(
            "SELECT * FROM acc_year_end_closures WHERE fiscal_year = ? AND status = 'closed' LIMIT 1",
            [$fiscalYear]
        );
        if (!$row) {
            throw new \RuntimeException("No active closed year-end found for FY {$fiscalYear}.");
        }
        if (trim($reason) === '') {
            throw new \RuntimeException('A reason is required for year-end reversal.');
        }

        return \db_transaction(function () use ($row, $userId, $reason, $fiscalYear) {
            $reversalJe = null;
            if (!empty($row['closing_je_id'])) {
                $reversalJe = JournalEntryService::reverse(
                    (int) $row['closing_je_id'],
                    date('Y-m-d'),
                    $userId
                );
            }

            // Unlock periods back to 'closed' (not 'open' — they should not be editable
            // until the operator deliberately re-opens them).
            \db_execute(
                "UPDATE acc_periods
                    SET status = 'closed', locked_by = NULL, locked_at = NULL
                  WHERE `year` = ? AND status = 'locked'",
                [$fiscalYear]
            );

            \db_update(
                'acc_year_end_closures',
                [
                    'status' => 'reversed',
                    'notes'  => trim(($row['notes'] ?? '') . "\nReversed " . date('Y-m-d H:i:s') . ": " . $reason),
                ],
                'id = ?',
                [(int) $row['id']]
            );

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'user_name'   => (function_exists('current_user') ? (\current_user()['name'] ?? 'system') : 'system'),
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'year_end_closure',
                'entity_id'   => (int) $row['id'],
                'entity_label'=> "FY {$fiscalYear}",
                'notes'       => "Year-end reversal: {$reason}. Periods unlocked back to 'closed'. Reversal JE: "
                                . ($reversalJe ? $reversalJe['entry_number'] : '(none)'),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return [
                'closure'     => \db_row("SELECT * FROM acc_year_end_closures WHERE id = ?", [(int) $row['id']]),
                'reversal_je' => $reversalJe,
            ];
        });
    }
}
