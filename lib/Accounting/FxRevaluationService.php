<?php
declare(strict_types=1);

/**
 * lib/Accounting/FxRevaluationService.php
 *
 * FX revaluation engine — period-end mark-to-market for USD-denominated
 * monetary GL accounts under ASPE 1651 temporal method. The CAD-book
 * balance (sum of debit − credit on the account) is compared against
 * the USD balance × current closing rate; the delta is posted as an
 * unrealized gain or loss via a system JE with auto_reverse=1 on the
 * first day of the next month (so the reversal lands cleanly the next
 * period and the engine can re-mark to a fresh rate).
 *
 * Account-level filter: WHERE is_fx_monetary = 1 AND currency = 'USD'.
 * Engine treats `foreign_amount` on each acc_journal_entry_lines row
 * as the USD value, and debit/credit as the CAD-book equivalent.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.1 (FX revaluation engine).
 * Session:  S037-FX
 */

namespace FleetForge\Accounting;

class FxRevaluationService
{
    /**
     * Bank of Canada Valet API endpoint pattern. CAD/USD daily noon rate.
     * Returns observations[].FXUSDCAD.v as a decimal string.
     */
    private const BOC_URL_TEMPLATE = 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?start_date=%s&end_date=%s';
    private const BOC_LOOKBACK_DAYS = 5;
    private const BOC_TIMEOUT_SECS  = 8;

    // ==================================================================
    // RATE SOURCE
    // ==================================================================

    /**
     * Fetch the CAD/USD closing rate for a given date.
     *
     * Honors the `accounting.fx_rate_source` setting:
     *   'manual'         → return accounting.fx_manual_rate (throws if blank)
     *   'bank_of_canada' → hit Valet API, walk back up to 5 days for gaps
     *                      (weekends, holidays)
     *
     * @param string $date  YYYY-MM-DD
     * @return string       bcmath-safe rate string, e.g. '1.365000'
     * @throws \RuntimeException
     */
    public static function fetchBankOfCanadaRate(string $date): string
    {
        $source = (string) AccountingService::setting('accounting.fx_rate_source', 'bank_of_canada');

        if ($source === 'manual') {
            $manual = (string) AccountingService::setting('accounting.fx_manual_rate', '');
            if ($manual === '' || !is_numeric($manual)) {
                throw new \RuntimeException('Manual FX rate not configured. Set accounting.fx_manual_rate in Settings.');
            }
            return $manual;
        }

        // Bank of Canada: try $date, then walk back up to 5 days.
        $tries = 0;
        $cursor = $date;
        $lastError = '';
        while ($tries <= self::BOC_LOOKBACK_DAYS) {
            try {
                $rate = self::callValetApi($cursor);
                if ($rate !== null) {
                    return $rate;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                error_log('Bank of Canada Valet API: ' . $e->getMessage());
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
            $tries++;
        }
        throw new \RuntimeException(
            "Bank of Canada rate unavailable for {$date} and prior "
            . self::BOC_LOOKBACK_DAYS . " days. Use manual rate entry or retry tomorrow."
            . ($lastError ? " Last error: {$lastError}" : '')
        );
    }

    /**
     * Single Valet API call for one date. Returns null if no observation
     * (date may be weekend/holiday with no posted rate); throws on
     * network/parsing error.
     */
    private static function callValetApi(string $date): ?string
    {
        $url = sprintf(self::BOC_URL_TEMPLATE, $date, $date);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::BOC_TIMEOUT_SECS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'FleetForge/S037-FX',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err) {
            throw new \RuntimeException('curl: ' . ($err ?: 'unknown'));
        }
        if ($code !== 200) {
            throw new \RuntimeException("HTTP {$code}");
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['observations']) || !is_array($json['observations'])) {
            throw new \RuntimeException('Malformed Valet API response');
        }
        if (empty($json['observations'])) {
            return null; // no observation for this date (weekend/holiday)
        }
        $first = $json['observations'][0];
        if (!isset($first['FXUSDCAD']['v'])) {
            return null;
        }
        $rate = (string) $first['FXUSDCAD']['v'];
        if (!is_numeric($rate)) {
            throw new \RuntimeException("Non-numeric rate in observation: {$rate}");
        }
        return $rate;
    }

    // ==================================================================
    // PREVIEW
    // ==================================================================

    /**
     * Compute the revaluation snapshot without posting anything.
     *
     * @param int         $periodId
     * @param string|null $manualRate  Override the rate-source lookup
     * @return array
     */
    public static function preview(int $periodId, ?string $manualRate = null): array
    {
        if ((string) AccountingService::setting('accounting.fx_revaluation_enabled', '0') !== '1') {
            throw new \RuntimeException('FX revaluation is disabled. Enable accounting.fx_revaluation_enabled in Settings.');
        }

        $period = \db_row("SELECT id, name, start_date, end_date, status FROM acc_periods WHERE id = ?", [$periodId]);
        if (!$period) {
            throw new \RuntimeException("Period #{$periodId} not found.");
        }
        $periodEnd = (string) $period['end_date'];

        // Rate resolution
        $rateSource = (string) AccountingService::setting('accounting.fx_rate_source', 'bank_of_canada');
        if ($manualRate !== null && $manualRate !== '') {
            if (!is_numeric($manualRate)) {
                throw new \RuntimeException('Invalid manual rate.');
            }
            $rate     = $manualRate;
            $rateDate = $periodEnd;
            $rateSrc  = 'manual_override';
        } else {
            $rate     = self::fetchBankOfCanadaRate($periodEnd);
            $rateDate = $periodEnd; // BoC fetcher walks back internally; we report the requested date
            $rateSrc  = $rateSource;
        }

        // Accounts to revalue
        $accts = \db_select(
            "SELECT id, code, name, account_type, normal_balance, currency
               FROM acc_accounts
              WHERE is_fx_monetary = 1
                AND currency = 'USD'
                AND is_active = 1
                AND is_header = 0
              ORDER BY sort_order ASC, code ASC",
            []
        );

        $rows         = [];
        $totalUsd     = '0.00';
        $totalCadBook = '0.00';
        $totalCadRev  = '0.00';
        $totalDelta   = '0.00';

        foreach ($accts as $a) {
            $accountId = (int) $a['id'];

            // USD balance: sum of foreign_amount, signed by debit/credit
            // and the account's normal-balance side. acc_journal_entry_lines.foreign_amount
            // is stored as the USD value of each debit or credit line.
            $usdRow = \db_row(
                "SELECT
                    COALESCE(SUM(CASE WHEN jel.debit  > 0 THEN COALESCE(jel.foreign_amount, jel.debit)  ELSE 0 END), 0) AS dr,
                    COALESCE(SUM(CASE WHEN jel.credit > 0 THEN COALESCE(jel.foreign_amount, jel.credit) ELSE 0 END), 0) AS cr
                   FROM acc_journal_entry_lines jel
                   JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                  WHERE jel.account_id = ?
                    AND je.status = 'posted'
                    AND je.entry_date <= ?",
                [$accountId, $periodEnd]
            );
            $usdBalance = $a['normal_balance'] === 'credit'
                ? bcsub((string) $usdRow['cr'], (string) $usdRow['dr'], 2)
                : bcsub((string) $usdRow['dr'], (string) $usdRow['cr'], 2);

            $cadBook = AccountingService::accountBalance($accountId, $periodEnd);

            $cadRevalued = bcmul($usdBalance, $rate, 2);
            $delta       = bcsub($cadRevalued, $cadBook, 2);

            $rows[] = [
                'account_id'   => $accountId,
                'code'         => $a['code'],
                'name'         => $a['name'],
                'account_type' => $a['account_type'],
                'usd_balance'  => $usdBalance,
                'cad_book'     => $cadBook,
                'cad_revalued' => $cadRevalued,
                'delta'        => $delta,
            ];
            $totalUsd     = bcadd($totalUsd, $usdBalance, 2);
            $totalCadBook = bcadd($totalCadBook, $cadBook, 2);
            $totalCadRev  = bcadd($totalCadRev, $cadRevalued, 2);
            $totalDelta   = bcadd($totalDelta, $delta, 2);
        }

        $existing = \db_row(
            "SELECT id, status FROM acc_fx_revaluations
              WHERE period_id = ? AND status = 'posted' LIMIT 1",
            [$periodId]
        );

        return [
            'period_id'                  => $periodId,
            'period_name'                => $period['name'],
            'period_end'                 => $periodEnd,
            'rate_used'                  => $rate,
            'rate_date'                  => $rateDate,
            'rate_source'                => $rateSrc,
            'accounts'                   => $rows,
            'total_usd'                  => $totalUsd,
            'total_cad_book'             => $totalCadBook,
            'total_cad_revalued'         => $totalCadRev,
            'total_unrealized_gain_loss' => $totalDelta,
            'existing_revaluation_id'    => $existing ? (int) $existing['id'] : null,
        ];
    }

    // ==================================================================
    // POST
    // ==================================================================

    /**
     * Post a revaluation: write the JE + the acc_fx_revaluations row.
     * Idempotency: refuses if a 'posted' row already exists for the
     * period. Caller must reverse it first if a re-run is intended.
     *
     * @return array { revaluation, journal_entry }
     */
    public static function post(int $periodId, string $rate, int $userId): array
    {
        if (!is_numeric($rate)) {
            throw new \RuntimeException('Invalid rate.');
        }

        // Compute via preview so the same logic produces the JE shape.
        $snapshot = self::preview($periodId, $rate);

        if ($snapshot['existing_revaluation_id'] !== null) {
            throw new \RuntimeException(
                'Revaluation already posted for this period. Reverse it first.'
            );
        }

        // Resolve FX gain/loss accounts. The seed COA carries separate
        // accounts for gain (7030 Foreign Exchange Gain, normal_balance=credit)
        // and loss (7040 Foreign Exchange Loss, normal_balance=debit). Honor
        // accounting.fx_gain_account_id + accounting.fx_loss_account_id if set;
        // fall back to the unified accounting.fx_revaluation_account_id setting;
        // and finally to a code-based lookup so a fresh install just works.
        $fxGainAccountId = (int) AccountingService::setting('accounting.fx_gain_account_id', 0);
        $fxLossAccountId = (int) AccountingService::setting('accounting.fx_loss_account_id', 0);
        $unified         = (int) AccountingService::setting('accounting.fx_revaluation_account_id', 0);
        if ($fxGainAccountId <= 0) $fxGainAccountId = $unified;
        if ($fxLossAccountId <= 0) $fxLossAccountId = $unified;

        if ($fxGainAccountId <= 0) {
            $row = \db_row("SELECT id FROM acc_accounts WHERE code IN ('7030','7200') AND is_active = 1 ORDER BY code DESC LIMIT 1");
            if (!$row) {
                throw new \RuntimeException('FX revaluation GL account not configured and no fallback (7030/7200) found in COA.');
            }
            $fxGainAccountId = (int) $row['id'];
        }
        if ($fxLossAccountId <= 0) {
            $row = \db_row("SELECT id FROM acc_accounts WHERE code IN ('7040','7200') AND is_active = 1 ORDER BY code DESC LIMIT 1");
            if (!$row) {
                $fxLossAccountId = $fxGainAccountId; // use same account if no separate loss account exists
            } else {
                $fxLossAccountId = (int) $row['id'];
            }
        }

        return \db_transaction(function () use ($snapshot, $rate, $userId, $fxGainAccountId, $fxLossAccountId, $periodId) {
            // Advisory lock for the period so two concurrent posts can't collide
            $lockRow = \db_row("SELECT GET_LOCK(?, 10) AS got", ["fx_rev_{$periodId}"]);
            if (!$lockRow || (int) $lockRow['got'] !== 1) {
                throw new \RuntimeException('Could not acquire fx_rev lock.');
            }

            try {
                // Build JE lines from per-account deltas
                $lines = [];
                $totalDelta = $snapshot['total_unrealized_gain_loss'];

                foreach ($snapshot['accounts'] as $a) {
                    $delta = (string) $a['delta'];
                    if (bccomp($delta, '0', 2) === 0) continue;

                    // Positive delta = gain on this account (asset increased / liability decreased)
                    // Negative delta = loss (asset decreased / liability increased)
                    if (bccomp($delta, '0', 2) > 0) {
                        // Gain: DR account / CR FX Gain account
                        $lines[] = [
                            'account_id'        => $a['account_id'],
                            'debit'             => $delta,
                            'credit'            => '0.00',
                            'description'       => "FX revaluation — {$a['code']} {$a['name']}",
                            'foreign_amount'    => $a['usd_balance'],
                            'foreign_currency'  => 'USD',
                            'exchange_rate'     => $rate,
                        ];
                        $lines[] = [
                            'account_id'  => $fxGainAccountId,
                            'debit'       => '0.00',
                            'credit'      => $delta,
                            'description' => "Unrealized FX gain — {$a['code']} {$a['name']}",
                        ];
                    } else {
                        $abs = bcmul($delta, '-1', 2);
                        // Loss: DR FX Loss account / CR account
                        $lines[] = [
                            'account_id'  => $fxLossAccountId,
                            'debit'       => $abs,
                            'credit'      => '0.00',
                            'description' => "Unrealized FX loss — {$a['code']} {$a['name']}",
                        ];
                        $lines[] = [
                            'account_id'        => $a['account_id'],
                            'debit'             => '0.00',
                            'credit'            => $abs,
                            'description'       => "FX revaluation — {$a['code']} {$a['name']}",
                            'foreign_amount'    => $a['usd_balance'],
                            'foreign_currency'  => 'USD',
                            'exchange_rate'     => $rate,
                        ];
                    }
                }

                // Auto-reverse on the 1st of the next month after the period end
                $periodEnd = (string) $snapshot['period_end'];
                $autoReverseDate = date('Y-m-d', strtotime('first day of next month', strtotime($periodEnd)));

                $description = sprintf(
                    'FX Revaluation — %s — Rate %s CAD/USD',
                    $snapshot['period_name'],
                    $rate
                );

                // INSERT placeholder acc_fx_revaluations FIRST so we can attach source_id
                $revId = \db_insert('acc_fx_revaluations', [
                    'revaluation_date'        => $periodEnd,
                    'period_id'               => $periodId,
                    'exchange_rate_used'      => $rate,
                    'total_ar_usd'            => $snapshot['total_usd'],
                    'total_ar_cad_book'       => $snapshot['total_cad_book'],
                    'total_ar_cad_revalued'   => $snapshot['total_cad_revalued'],
                    'unrealized_gain_loss'    => $totalDelta,
                    'status'                  => 'posted',
                    'run_at'                  => date('Y-m-d H:i:s'),
                    'journal_entry_id'        => null, // set after JE creates
                    'created_by'              => $userId,
                ]);

                if (count($lines) > 0) {
                    $je = JournalEntryService::create([
                        'entry_date'        => $periodEnd,
                        'description'       => $description,
                        'entry_type'        => 'system',
                        'reference'         => 'FX-REV-' . $periodId,
                        'source_type'       => 'fx_revaluation',
                        'source_id'         => $revId,
                        'currency'          => 'CAD',
                        'auto_reverse'      => 1,
                        'auto_reverse_date' => $autoReverseDate,
                        'post_immediately'  => true,
                    ], $lines, $userId);

                    \db_update(
                        'acc_fx_revaluations',
                        ['journal_entry_id' => (int) $je['id']],
                        'id = ?',
                        [$revId]
                    );
                } else {
                    // Zero-delta revaluation: record the run but no JE needed.
                    // (Still a valid period evaluation — proves we looked.)
                    $je = null;
                }

                \db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => (function_exists('current_user') ? (\current_user()['name'] ?? 'system') : 'system'),
                    'action'       => 'create',
                    'module'       => 'accounting',
                    'entity_type'  => 'fx_revaluation',
                    'entity_id'    => $revId,
                    'entity_label' => $description,
                    'notes'        => sprintf(
                        'FX revaluation posted for %s. Rate=%s. Gain/loss=%s',
                        $snapshot['period_name'], $rate, $totalDelta
                    ),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                \db_execute("SELECT RELEASE_LOCK(?)", ["fx_rev_{$periodId}"]);

                return [
                    'revaluation'    => \db_row("SELECT * FROM acc_fx_revaluations WHERE id = ?", [$revId]),
                    'journal_entry'  => $je,
                ];
            } catch (\Throwable $e) {
                \db_execute("SELECT RELEASE_LOCK(?)", ["fx_rev_{$periodId}"]);
                throw $e;
            }
        });
    }

    // ==================================================================
    // REVERSE
    // ==================================================================

    /**
     * Reverse a posted revaluation. Calls JournalEntryService::reverse()
     * on the backing JE and flips status to 'reversed'.
     */
    public static function reverse(int $revaluationId, int $userId): array
    {
        $row = \db_row("SELECT * FROM acc_fx_revaluations WHERE id = ?", [$revaluationId]);
        if (!$row) {
            throw new \RuntimeException("Revaluation #{$revaluationId} not found.");
        }
        if (($row['status'] ?? '') !== 'posted') {
            throw new \RuntimeException("Revaluation #{$revaluationId} is not in 'posted' status (current: {$row['status']}).");
        }

        return \db_transaction(function () use ($row, $userId, $revaluationId) {
            $reversalJe = null;
            if (!empty($row['journal_entry_id'])) {
                $reversalJe = JournalEntryService::reverse(
                    (int) $row['journal_entry_id'],
                    date('Y-m-d'),
                    $userId
                );
            }

            \db_update(
                'acc_fx_revaluations',
                ['status' => 'reversed'],
                'id = ?',
                [$revaluationId]
            );

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => (function_exists('current_user') ? (\current_user()['name'] ?? 'system') : 'system'),
                'action'       => 'status_change',
                'module'       => 'accounting',
                'entity_type'  => 'fx_revaluation',
                'entity_id'    => $revaluationId,
                'entity_label' => 'FX revaluation reversed',
                'notes'        => sprintf(
                    'FX revaluation #%d reversed. Original gain/loss=%s reversed via JE %s.',
                    $revaluationId,
                    $row['unrealized_gain_loss'],
                    $reversalJe ? $reversalJe['entry_number'] : '(no JE — zero-delta original)'
                ),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return [
                'revaluation'  => \db_row("SELECT * FROM acc_fx_revaluations WHERE id = ?", [$revaluationId]),
                'reversal_je'  => $reversalJe,
            ];
        });
    }
}
