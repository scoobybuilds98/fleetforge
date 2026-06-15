<?php
declare(strict_types=1);

/**
 * lib/Accounting/BudgetService.php
 *
 * CRUD + variance helpers for the budget module. Schema-aligned to
 * acc_budgets and acc_budget_lines as defined in FLEETFORGE_DATABASE_MASTER.sql:
 *   - acc_budgets has columns: name, year, version (base|conservative|optimistic),
 *     status (draft|active|archived), is_active, notes — NOT fiscal_year /
 *     basis / approved. The session prompt described a different shape; this
 *     service follows the schema-on-disk per the K-22 trust-file principle.
 *   - acc_budget_lines uses 12 month columns (jan..dec) where `dec` is a SQL
 *     reserved word and must always be backtick-quoted.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §11 (Budget Module)
 * Session:  S036
 */

namespace FleetForge\Accounting;

class BudgetService
{
    private const VALID_VERSIONS = ['base', 'conservative', 'optimistic'];
    private const VALID_STATUSES = ['draft', 'active', 'archived'];

    /**
     * Create a new budget header (and optionally clone lines from a prior-year budget).
     *
     * @param array $data   { name, year, version?, notes?, copy_prior_year? }
     * @param int   $userId
     * @return array        New budget row (header)
     */
    public static function create(array $data, int $userId): array
    {
        $name    = trim((string) ($data['name'] ?? ''));
        $year    = (int) ($data['year'] ?? 0);
        $version = (string) ($data['version'] ?? 'base');
        $notes   = isset($data['notes']) ? (string) $data['notes'] : null;
        $copyPrior = !empty($data['copy_prior_year']);

        if ($name === '') {
            throw new \InvalidArgumentException('Budget name is required.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Year must be a 4-digit calendar year.');
        }
        if (!in_array($version, self::VALID_VERSIONS, true)) {
            throw new \InvalidArgumentException('Invalid version. Must be base, conservative, or optimistic.');
        }

        return \db_transaction(function () use ($name, $year, $version, $notes, $copyPrior, $userId) {
            $budgetId = \db_insert('acc_budgets', [
                'name'       => $name,
                'year'       => $year,
                'version'    => $version,
                'status'     => 'draft',
                'is_active'  => 0,
                'notes'      => $notes,
                'created_by' => $userId,
            ]);

            if ($copyPrior) {
                self::copyLinesFromPriorYear($budgetId, $year - 1);
            }

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => \current_user()['name'] ?? 'system',
                'action'       => 'create',
                'module'       => 'accounting',
                'entity_type'  => 'acc_budget',
                'entity_id'    => $budgetId,
                'entity_label' => $name,
                'notes'        => "Budget '{$name}' created for {$year} ({$version})",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return self::loadHeader($budgetId);
        });
    }

    /**
     * Find the most recent budget for the prior year + same version (fallback
     * to any version) and clone every line into $newBudgetId with identical
     * monthly amounts.
     */
    private static function copyLinesFromPriorYear(int $newBudgetId, int $priorYear): int
    {
        $prior = \db_row(
            "SELECT id FROM acc_budgets
              WHERE year = ?
              ORDER BY created_at DESC
              LIMIT 1",
            [$priorYear]
        );
        if (!$prior) return 0;

        $lines = \db_select(
            "SELECT account_id, `jan`,`feb`,`mar`,`apr`,`may`,`jun`,`jul`,`aug`,`sep`,`oct`,`nov`,`dec`, notes
               FROM acc_budget_lines WHERE budget_id = ?",
            [(int) $prior['id']]
        );
        $copied = 0;
        foreach ($lines as $l) {
            \db_insert('acc_budget_lines', [
                'budget_id'  => $newBudgetId,
                'account_id' => (int) $l['account_id'],
                'jan' => $l['jan'], 'feb' => $l['feb'], 'mar' => $l['mar'],
                'apr' => $l['apr'], 'may' => $l['may'], 'jun' => $l['jun'],
                'jul' => $l['jul'], 'aug' => $l['aug'], 'sep' => $l['sep'],
                'oct' => $l['oct'], 'nov' => $l['nov'], 'dec' => $l['dec'],
                'notes' => $l['notes'] ?? null,
            ]);
            $copied++;
        }
        return $copied;
    }

    /**
     * Update budget header fields and/or individual line monthly amounts.
     * D19 optimistic lock: caller passes `updated_at` of the version they read.
     *
     * @param array $data { updated_at (required), name?, version?, status?,
     *                      is_active?, notes?, lines? [{ account_id, jan..dec, notes }] }
     */
    public static function update(int $id, array $data, int $userId): array
    {
        return \db_transaction(function () use ($id, $data, $userId) {
            $current = \db_row(
                "SELECT id, name, status, is_active, version, updated_at
                   FROM acc_budgets WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$current) {
                throw new \RuntimeException('Budget not found.');
            }

            // Schema's `status` ENUM is draft|active|archived; treat 'archived'
            // as the lock state. Once archived, only super-admin can edit and
            // that is enforced at the API permission layer, not here.
            if (($current['status'] ?? '') === 'archived') {
                throw new \RuntimeException('Archived budgets cannot be edited.');
            }

            // D19 via the shared gate so the app-wide FF_OPTIMISTIC_LOCKING flag
            // governs budgets too (locking disabled by default — last-write-wins).
            $providedUpdatedAt = (string) ($data['updated_at'] ?? '');
            if ($providedUpdatedAt && !optimistic_lock_matches($providedUpdatedAt, (string) $current['updated_at'])) {
                throw new \RuntimeException('STALE_DATA: this budget was modified by someone else.');
            }

            $header = [];
            if (isset($data['name']))      $header['name'] = (string) $data['name'];
            if (isset($data['version']))   {
                if (!in_array($data['version'], self::VALID_VERSIONS, true)) {
                    throw new \InvalidArgumentException('Invalid version.');
                }
                $header['version'] = (string) $data['version'];
            }
            if (isset($data['status']))    {
                if (!in_array($data['status'], self::VALID_STATUSES, true)) {
                    throw new \InvalidArgumentException('Invalid status.');
                }
                $header['status'] = (string) $data['status'];
            }
            if (array_key_exists('is_active', $data)) {
                $header['is_active'] = (int) (bool) $data['is_active'];
            }
            if (array_key_exists('notes', $data)) {
                $header['notes'] = $data['notes'] !== null ? (string) $data['notes'] : null;
            }

            if (!empty($header)) {
                \db_update('acc_budgets', $header, 'id = ?', [$id]);
            }

            $lines = $data['lines'] ?? [];
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    self::upsertLine($id, $line);
                }
            }

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => \current_user()['name'] ?? 'system',
                'action'       => 'update',
                'module'       => 'accounting',
                'entity_type'  => 'acc_budget',
                'entity_id'    => $id,
                'entity_label' => (string) $current['name'],
                'notes'        => "Budget #{$id} updated (header fields: " . count($header) . ", lines: " . count($lines) . ")",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return self::loadHeader($id);
        });
    }

    /**
     * Upsert a single line — keyed on (budget_id, account_id) per the
     * uq_budget_account unique index.
     */
    private static function upsertLine(int $budgetId, array $line): void
    {
        $accountId = (int) ($line['account_id'] ?? 0);
        if ($accountId <= 0) return;

        $existing = \db_row(
            "SELECT id FROM acc_budget_lines WHERE budget_id = ? AND account_id = ?",
            [$budgetId, $accountId]
        );

        $months = [
            'jan','feb','mar','apr','may','jun',
            'jul','aug','sep','oct','nov','dec',
        ];
        $payload = ['notes' => $line['notes'] ?? null];
        foreach ($months as $m) {
            $val = $line[$m] ?? '0.00';
            $payload[$m] = is_numeric($val) ? number_format((float) $val, 2, '.', '') : '0.00';
        }

        if ($existing) {
            \db_update(
                'acc_budget_lines',
                $payload,
                'id = ?',
                [(int) $existing['id']]
            );
        } else {
            $payload['budget_id']  = $budgetId;
            $payload['account_id'] = $accountId;
            \db_insert('acc_budget_lines', $payload);
        }
    }

    /**
     * Delete a budget (hard delete — acc_budgets has no deleted_at column
     * per schema). Cascades acc_budget_lines via FK ON DELETE CASCADE.
     */
    public static function delete(int $id, int $userId): void
    {
        \db_transaction(function () use ($id, $userId) {
            $row = \db_row("SELECT id, name, status FROM acc_budgets WHERE id = ? FOR UPDATE", [$id]);
            if (!$row) throw new \RuntimeException('Budget not found.');

            \db_execute("DELETE FROM acc_budget_lines WHERE budget_id = ?", [$id]);
            \db_execute("DELETE FROM acc_budgets WHERE id = ?", [$id]);

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => \current_user()['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'accounting',
                'entity_type'  => 'acc_budget',
                'entity_id'    => $id,
                'entity_label' => (string) $row['name'],
                'notes'        => "Budget #{$id} '{$row['name']}' deleted.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        });
    }

    /**
     * Clone a budget header + all lines into a new year. Name gets "(Copy)"
     * suffix to disambiguate.
     */
    public static function copy(int $sourceId, int $newYear, int $userId): array
    {
        if ($newYear < 2000 || $newYear > 2100) {
            throw new \InvalidArgumentException('Invalid year.');
        }
        return \db_transaction(function () use ($sourceId, $newYear, $userId) {
            $src = \db_row("SELECT * FROM acc_budgets WHERE id = ?", [$sourceId]);
            if (!$src) throw new \RuntimeException('Source budget not found.');

            $newId = \db_insert('acc_budgets', [
                'name'       => $src['name'] . ' (Copy)',
                'year'       => $newYear,
                'version'    => $src['version'],
                'status'     => 'draft',
                'is_active'  => 0,
                'notes'      => $src['notes'],
                'created_by' => $userId,
            ]);

            $lines = \db_select(
                "SELECT account_id, `jan`,`feb`,`mar`,`apr`,`may`,`jun`,`jul`,`aug`,`sep`,`oct`,`nov`,`dec`, notes
                   FROM acc_budget_lines WHERE budget_id = ?",
                [$sourceId]
            );
            foreach ($lines as $l) {
                \db_insert('acc_budget_lines', [
                    'budget_id'  => $newId,
                    'account_id' => (int) $l['account_id'],
                    'jan' => $l['jan'], 'feb' => $l['feb'], 'mar' => $l['mar'],
                    'apr' => $l['apr'], 'may' => $l['may'], 'jun' => $l['jun'],
                    'jul' => $l['jul'], 'aug' => $l['aug'], 'sep' => $l['sep'],
                    'oct' => $l['oct'], 'nov' => $l['nov'], 'dec' => $l['dec'],
                    'notes' => $l['notes'] ?? null,
                ]);
            }

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => \current_user()['name'] ?? 'system',
                'action'       => 'create',
                'module'       => 'accounting',
                'entity_type'  => 'acc_budget',
                'entity_id'    => $newId,
                'entity_label' => $src['name'] . ' (Copy)',
                'notes'        => "Budget #{$newId} created as copy of #{$sourceId} for year {$newYear} (" . count($lines) . " lines cloned)",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return self::loadHeader($newId);
        });
    }

    /**
     * Variance report — budgeted vs actual per account, with favorable/
     * unfavorable classification based on account normal-balance side.
     */
    public static function variance(int $budgetId, string $from, string $to): array
    {
        $budget = \db_row("SELECT * FROM acc_budgets WHERE id = ?", [$budgetId]);
        if (!$budget) {
            throw new \RuntimeException('Budget not found.');
        }

        $lines = \db_select(
            "SELECT bl.account_id, a.code, a.name, a.account_type, a.normal_balance,
                    bl.`jan`, bl.`feb`, bl.`mar`, bl.`apr`, bl.`may`, bl.`jun`,
                    bl.`jul`, bl.`aug`, bl.`sep`, bl.`oct`, bl.`nov`, bl.`dec`
               FROM acc_budget_lines bl
               JOIN acc_accounts a ON a.id = bl.account_id
              WHERE bl.budget_id = ?
              ORDER BY a.sort_order ASC, a.code ASC",
            [$budgetId]
        );

        $thresholdSetting = AccountingService::setting('accounting.variance_warning_pct', '10.00');
        $threshold = is_numeric($thresholdSetting) ? (string) $thresholdSetting : '10.00';

        $rows = [];
        $totals = [
            'budgeted_revenue' => '0.00', 'actual_revenue' => '0.00',
            'budgeted_expense' => '0.00', 'actual_expense' => '0.00',
        ];

        foreach ($lines as $l) {
            $accountId = (int) $l['account_id'];

            // Pro-rate budget to the date range using the same month-day fraction
            // logic as ReportingService::prorateBudgetLine.
            $budgeted = self::prorateLineToRange($l, $from, $to);

            // Actual from posted JE lines for this account in the same range.
            $actualRow = \db_row(
                "SELECT COALESCE(SUM(jel.debit), 0) AS dr,
                        COALESCE(SUM(jel.credit), 0) AS cr
                   FROM acc_journal_entry_lines jel
                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                  WHERE jel.account_id = ?
                    AND je.status = 'posted'
                    AND je.entry_date BETWEEN ? AND ?",
                [$accountId, $from, $to]
            );
            $dr = (string) ($actualRow['dr'] ?? '0.00');
            $cr = (string) ($actualRow['cr'] ?? '0.00');
            $actual = $l['normal_balance'] === 'credit'
                ? bcsub($cr, $dr, 2)
                : bcsub($dr, $cr, 2);

            $varAmt = bcsub($actual, $budgeted, 2);
            $varPct = self::variancePct($actual, $budgeted);

            // Favorable / unfavorable convention:
            //   revenue + other_income → actual > budget = favorable
            //   expenses (cost_of_revenue / operating_expense / other_expense)
            //     → actual > budget = unfavorable
            $isRevenue = in_array($l['account_type'], ['revenue', 'other_income'], true);
            $status = 'neutral';
            if (bccomp($varAmt, '0', 2) !== 0) {
                if ($isRevenue) {
                    $status = bccomp($varAmt, '0', 2) > 0 ? 'favorable' : 'unfavorable';
                } else {
                    $status = bccomp($varAmt, '0', 2) > 0 ? 'unfavorable' : 'favorable';
                }
            }
            $crossesThreshold = bccomp(bcdiv(bcmul($varPct, $varPct === '0.00' ? '1' : '1', 4), '1', 2), $threshold, 2) > 0
                              || bccomp($varPct, '-' . $threshold, 2) < 0;

            $rows[] = [
                'account_id'   => $accountId,
                'code'         => $l['code'],
                'name'         => $l['name'],
                'account_type' => $l['account_type'],
                'budgeted'     => $budgeted,
                'actual'       => $actual,
                'var_amt'      => $varAmt,
                'var_pct'      => $varPct,
                'status'       => $status,
                'crosses_threshold' => $crossesThreshold,
            ];

            if ($isRevenue) {
                $totals['budgeted_revenue'] = bcadd($totals['budgeted_revenue'], $budgeted, 2);
                $totals['actual_revenue']   = bcadd($totals['actual_revenue'], $actual, 2);
            } else {
                $totals['budgeted_expense'] = bcadd($totals['budgeted_expense'], $budgeted, 2);
                $totals['actual_expense']   = bcadd($totals['actual_expense'], $actual, 2);
            }
        }

        $totals['budgeted_net'] = bcsub($totals['budgeted_revenue'], $totals['budgeted_expense'], 2);
        $totals['actual_net']   = bcsub($totals['actual_revenue'], $totals['actual_expense'], 2);

        return [
            'budget' => [
                'id'      => (int) $budget['id'],
                'name'    => $budget['name'],
                'year'    => (int) $budget['year'],
                'version' => $budget['version'],
                'status'  => $budget['status'],
            ],
            'period'    => ['from' => $from, 'to' => $to],
            'threshold_pct' => $threshold,
            'rows'      => $rows,
            'totals'    => $totals,
        ];
    }

    private static function prorateLineToRange(array $line, string $from, string $to): string
    {
        $cols = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        $year = (int) substr($from, 0, 4);
        $total = '0.00';

        for ($m = 1; $m <= 12; $m++) {
            $monthStart = sprintf('%04d-%02d-01', $year, $m);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));

            $overlapStart = max(strtotime($from), strtotime($monthStart));
            $overlapEnd   = min(strtotime($to), strtotime($monthEnd));
            if ($overlapEnd < $overlapStart) continue;

            $monthDays   = (int) date('t', strtotime($monthStart));
            $overlapDays = (int) round(($overlapEnd - $overlapStart) / 86400) + 1;
            $share       = bcdiv((string) $overlapDays, (string) $monthDays, 6);
            $colVal      = (string) ($line[$cols[$m - 1]] ?? '0.00');
            $portion     = bcmul($colVal, $share, 6);
            $total       = bcadd($total, $portion, 6);
        }
        return bcadd($total, '0', 2);
    }

    private static function variancePct(string $actual, string $base): string
    {
        if (bccomp($base, '0', 2) === 0) return '0.00';
        $diff = bcsub($actual, $base, 4);
        $pct  = bcmul(bcdiv($diff, $base, 6), '100', 4);
        return bcadd($pct, '0', 2);
    }

    /**
     * Load a budget header row with the creator's name joined in.
     */
    public static function loadHeader(int $id): array
    {
        $row = \db_row(
            "SELECT b.*, u.name AS created_by_name
               FROM acc_budgets b
          LEFT JOIN users u ON u.id = b.created_by
              WHERE b.id = ?",
            [$id]
        );
        if (!$row) throw new \RuntimeException('Budget not found.');
        return $row;
    }

    /**
     * Load lines for a budget with account info joined.
     */
    public static function loadLines(int $budgetId): array
    {
        return \db_select(
            "SELECT bl.id, bl.account_id, a.code, a.name AS account_name,
                    a.account_type, a.normal_balance,
                    bl.`jan`, bl.`feb`, bl.`mar`, bl.`apr`, bl.`may`, bl.`jun`,
                    bl.`jul`, bl.`aug`, bl.`sep`, bl.`oct`, bl.`nov`, bl.`dec`,
                    bl.annual_total, bl.notes
               FROM acc_budget_lines bl
               JOIN acc_accounts a ON a.id = bl.account_id
              WHERE bl.budget_id = ?
              ORDER BY a.sort_order ASC, a.code ASC",
            [$budgetId]
        );
    }
}
