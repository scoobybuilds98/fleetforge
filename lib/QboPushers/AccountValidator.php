<?php
declare(strict_types=1);

/**
 * lib/QboPushers/AccountValidator.php
 *
 * Bridge-account validator (D-QBO-8-2). Identifies which FF
 * acc_accounts rows are "critical" — required to be mapped to a QBO
 * account before downstream Pusher sessions (invoice push S-QBO-11,
 * journal-entry push S-QBO-21) can function. Future Pushers call
 * `assertReadyForInvoicePush()` at dispatch top to fail fast with
 * ChartOfAccountsIncompleteException if any critical account is
 * unmapped.
 *
 * Critical-account heuristic (verified against live FF acc_accounts
 * during S-QBO-8 pre-flight — 93 rows, 7 candidates):
 *
 *   1. Accounts Receivable (code = '1030', is_system=1)
 *   2. Accounts Payable (code = '2010', is_system=1)
 *   3. GST/HST Receivable / ITC (code = '1050')
 *   4. PST Receivable (code = '1060')
 *   5. GST/HST Payable (code = '2030')
 *   6. PST Payable (code = '2040')
 *   7. Sales Revenue accounts (code LIKE '4%' AND is_system=1)
 *
 * The heuristic is hardcoded for v1 — FF deployment is single-tenant
 * Canadian-context (per D-QBO-CORE-1 + D-QBO-6-5 Country='CA' default)
 * so the chart codes are stable. Future multi-tenant deployments
 * would lift this to a settings.quickbooks.critical_accounts JSON
 * setting (deferred — out of scope v1).
 *
 * The validator does NOT manage QBO-side accounts — Puller-only per
 * D-QBO-8-1. It operates entirely on the FF acc_accounts table +
 * the acc_qbo_account_map mapping rows.
 *
 * @session  S-QBO-8
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher pre-flight gates)
 * @decision D-QBO-8-2 (bridge-account validator with is_critical
 *                       column independent of mapping_status; the
 *                       assertReadyForInvoicePush gate fires from
 *                       S-QBO-11 invoice push)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class AccountValidator
{
    /**
     * Mark critical accounts in acc_qbo_account_map (idempotent — safe
     * to run after each Pull). Sets is_critical=1 + critical_reason
     * on rows whose ff_account_id matches the bridge-account heuristic.
     *
     * Pre-condition: every FF account must have at least an ff_only
     * row in acc_qbo_account_map. The pull endpoint typically only
     * inserts qbo_only rows; ff_only rows get created by Auto-Match
     * OR by this method on first run (we INSERT IGNORE the ff_only
     * baseline for any critical FF account not yet represented).
     *
     * @return int Number of rows flagged is_critical=1 in this run
     *             (delta from prior state).
     */
    public static function markCriticalAccounts(): int
    {
        $criticalFf = self::identifyCriticalFfAccounts();

        $flagged = 0;
        foreach ($criticalFf as $ff) {
            // Ensure an acc_qbo_account_map row exists for this FF
            // account. INSERT IGNORE handles the case where Auto-Match
            // already created an ff_only row OR a mapped row.
            // We do NOT touch mapping_status here — the validator only
            // sets the is_critical flag.
            db_execute(
                "INSERT INTO acc_qbo_account_map
                    (ff_account_id, mapping_status, is_critical, critical_reason)
                 VALUES (?, 'ff_only', 1, ?)
                 ON DUPLICATE KEY UPDATE
                    is_critical     = 1,
                    critical_reason = VALUES(critical_reason)",
                [(int) $ff['id'], (string) $ff['critical_reason']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Returns list of critical FF accounts that are NOT yet linked to
     * a QBO account. Each entry:
     *   [ff_account_id, code, name, account_type, account_subtype, critical_reason]
     *
     * Used by:
     *   - UI banner on /quickbooks/accounts
     *   - api/v1/quickbooks/accounts/list.php KPI 'critical_unmapped'
     *   - assertReadyForInvoicePush() — throws on non-empty
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unmappedCritical(): array
    {
        return db_select(
            "SELECT m.ff_account_id, a.code, a.name, a.account_type,
                    a.account_subtype, m.critical_reason
               FROM acc_qbo_account_map m
               JOIN acc_accounts a ON a.id = m.ff_account_id
              WHERE m.is_critical = 1
                AND (m.mapping_status != 'mapped' OR m.qbo_account_id IS NULL)
                AND a.is_active = 1
              ORDER BY a.code"
        );
    }

    /**
     * Pre-flight gate for invoice + JE Pusher sessions (S-QBO-11+).
     * Throws ChartOfAccountsIncompleteException with the unmapped
     * accounts list if any bridge account is unmapped. Passes
     * silently otherwise.
     *
     * Future Pushers wire this in at the top of `pushImpl`:
     *
     *   try {
     *       AccountValidator::assertReadyForInvoicePush();
     *   } catch (ChartOfAccountsIncompleteException $e) {
     *       return ['success'=>false, 'status'=>'chart_incomplete',
     *               'error'=>$e->getMessage()];
     *   }
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForInvoicePush(): void
    {
        $unmapped = self::unmappedCritical();
        if (empty($unmapped)) {
            return;
        }
        $summary = implode(
            ', ',
            array_map(
                static fn($a) => "{$a['code']} {$a['name']}",
                $unmapped
            )
        );
        $count = count($unmapped);
        throw new ChartOfAccountsIncompleteException(
            "{$count} critical FF account(s) unmapped to QBO. Map via /quickbooks/accounts before invoice push: {$summary}",
            $unmapped
        );
    }

    /**
     * Heuristic for identifying critical FF accounts. Returns list of
     * [id, code, name, critical_reason] from live acc_accounts.
     *
     * Order matters for `critical_reason` precedence — if an account
     * matches multiple criteria (unlikely with the current chart), the
     * earlier rule wins.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function identifyCriticalFfAccounts(): array
    {
        // Single query with UNION to surface critical accounts via
        // separate WHERE clauses for clarity. Each branch carries its
        // own reason string.
        $sql = "
            -- Accounts Receivable
            SELECT id, code, name, 'Accounts Receivable (AR clearing)' AS critical_reason
              FROM acc_accounts
             WHERE code = '1030' AND is_active = 1
            UNION ALL
            -- Accounts Payable
            SELECT id, code, name, 'Accounts Payable (AP clearing)' AS critical_reason
              FROM acc_accounts
             WHERE code = '2010' AND is_active = 1
            UNION ALL
            -- Tax Receivable (Input Tax Credits)
            SELECT id, code, name, 'GST/HST Receivable (Input Tax Credits)' AS critical_reason
              FROM acc_accounts
             WHERE code = '1050' AND is_active = 1
            UNION ALL
            SELECT id, code, name, 'PST Receivable' AS critical_reason
              FROM acc_accounts
             WHERE code = '1060' AND is_active = 1
            UNION ALL
            -- Tax Payable
            SELECT id, code, name, 'GST/HST Payable' AS critical_reason
              FROM acc_accounts
             WHERE code = '2030' AND is_active = 1
            UNION ALL
            SELECT id, code, name, 'PST Payable' AS critical_reason
              FROM acc_accounts
             WHERE code = '2040' AND is_active = 1
            UNION ALL
            -- Sales Revenue (is_system=1 AND code in 4xxx)
            SELECT id, code, name, CONCAT('Sales Revenue (', name, ')') AS critical_reason
              FROM acc_accounts
             WHERE code LIKE '4%'
               AND is_system = 1
               AND is_active = 1
               AND account_type = 'revenue'
        ";
        return db_select($sql);
    }
}
