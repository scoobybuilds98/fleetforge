<?php
declare(strict_types=1);

/**
 * lib/QboPushers/AccountValidator.php
 *
 * Bridge-account validator (D-QBO-8-2 + D-QBO-VALIDATOR-1/2/3/4/5).
 * Identifies which FF acc_accounts rows are "critical" — required to
 * be mapped to a QBO account before downstream Pusher sessions can
 * function. Each critical account carries a `critical_category` tag
 * so the validator can gate per-Pusher session rather than requiring
 * ALL critical accounts mapped before ANY push proceeds.
 *
 * Critical-account heuristic (verified against live FF acc_accounts
 * during S-QBO-8 pre-flight — 93 rows, 7 candidates):
 *
 *   FF code → critical_category
 *   1030 (AR is_system=1)        → 'ar_clearing'      (S-QBO-11 invoice, S-QBO-13/14 payment)
 *   2010 (AP is_system=1)        → 'ap_clearing'      (S-QBO-18/19 bills + bill payments)
 *   1050 (GST/HST Receivable)    → 'tax_receivable'   (S-QBO-21 JE for tax remit)
 *   1060 (PST Receivable)        → 'tax_receivable'   (S-QBO-21)
 *   2030 (GST/HST Payable)       → 'tax_payable'      (S-QBO-21)
 *   2040 (PST Payable)           → 'tax_payable'      (S-QBO-21)
 *   4xxx is_system=1 revenue     → 'sales_revenue'    (S-QBO-11 invoice line emission)
 *
 * 'undeposited_funds' is defined as a category but has no FF account
 * in the current chart — QBO Undeposited Funds is a QBO-side built-in
 * with no FF analog. assertReadyForPaymentPush() throws an actionable
 * error if requested but no FF account is tagged (D-QBO-VALIDATOR-3
 * + operator AskUserQuestion resolution at session pre-flight).
 *
 * The heuristic is hardcoded for v1 — FF deployment is single-tenant
 * Canadian-context (per D-QBO-CORE-1 + D-QBO-6-5 Country='CA' default)
 * so the chart codes are stable. Future multi-tenant deployments
 * would lift this to a settings.quickbooks.critical_accounts JSON
 * setting (deferred — out of scope v1).
 *
 * Per-session assert methods (D-QBO-VALIDATOR-3):
 *   assertReadyForInvoicePush()         → requires ['ar_clearing', 'sales_revenue']
 *   assertReadyForPaymentPush()         → requires ['ar_clearing', 'undeposited_funds']
 *   assertReadyForBillPush()            → requires ['ap_clearing']
 *   assertReadyForBillPaymentPush()     → requires ['ap_clearing', 'undeposited_funds']
 *   assertReadyForJournalEntryPush()    → requires ['tax_receivable', 'tax_payable']
 *   assertReadyForFullCompliance()      → requires ALL 6 categories
 *
 * The validator does NOT manage QBO-side accounts — Puller-only per
 * D-QBO-8-1. It operates entirely on the FF acc_accounts table +
 * the acc_qbo_account_map mapping rows.
 *
 * K-22 (S-QBO-VALIDATOR-SCOPE-SPLIT pre-flight): the original session
 * prompt's PART B/C heuristic used FF.acct_subtype='AccountsReceivable'
 * etc. — PascalCase as if FF used QBO naming. FF.acct_subtype is
 * lowercase snake_case (current_asset, current_liability, ...) per
 * the live acc_accounts ENUM. Resolved via AskUserQuestion per
 * [[feedback_trust_file_over_prompt]] + [[project_qbo_subtype_taxonomy]];
 * heuristic uses FF code patterns instead (same as
 * identifyCriticalFfAccounts() since S-QBO-8).
 *
 * @session  S-QBO-8 (original), S-QBO-VALIDATOR-SCOPE-SPLIT (per-session split)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher pre-flight gates)
 * @decision D-QBO-8-2 (bridge-account validator + is_critical column),
 *           D-QBO-VALIDATOR-1 (per-session gates by Pusher, not abstract category),
 *           D-QBO-VALIDATOR-2 (critical_category column + 6 baseline categories),
 *           D-QBO-VALIDATOR-3 (per-session assert methods + category requirements),
 *           D-QBO-VALIDATOR-4 (assertReadyForInvoicePush semantics narrow to
 *                              ar_clearing + sales_revenue; legacy all-7 check
 *                              moved to assertReadyForFullCompliance),
 *           D-QBO-VALIDATOR-5 (exception message names blocking categories +
 *                              the FF accounts contributing to those categories)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class AccountValidator
{
    /**
     * Category list used by per-session assert methods. Operator/CPA
     * can extend (e.g. add 'refund_clearing' if a refund-pusher
     * session lands later); the assert methods only check for the
     * subset they require.
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'ar_clearing',
        'ap_clearing',
        'undeposited_funds',
        'tax_receivable',
        'tax_payable',
        'sales_revenue',
    ];

    /**
     * Per-session category requirements (D-QBO-VALIDATOR-3). Each
     * future Pusher's pre-flight gate consults this map.
     *
     * @var array<string, list<string>>
     */
    public const SESSION_REQUIREMENTS = [
        'invoice_push'       => ['ar_clearing', 'sales_revenue'],
        'payment_push'       => ['ar_clearing', 'undeposited_funds'],
        'bill_push'          => ['ap_clearing'],
        'bill_payment_push'  => ['ap_clearing', 'undeposited_funds'],
        'journal_entry_push' => ['tax_receivable', 'tax_payable'],
        'full_compliance'    => ['ar_clearing', 'ap_clearing', 'undeposited_funds', 'tax_receivable', 'tax_payable', 'sales_revenue'],
    ];

    /**
     * Mark critical accounts in acc_qbo_account_map (idempotent — safe
     * to run after each Pull). Sets is_critical=1 + critical_reason +
     * critical_category on rows whose ff_account_id matches the
     * bridge-account heuristic.
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
            // sets the is_critical flag + critical_category.
            db_execute(
                "INSERT INTO acc_qbo_account_map
                    (ff_account_id, mapping_status, is_critical, critical_reason, critical_category)
                 VALUES (?, 'ff_only', 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    is_critical       = 1,
                    critical_reason   = VALUES(critical_reason),
                    critical_category = VALUES(critical_category)",
                [(int) $ff['id'], (string) $ff['critical_reason'], (string) $ff['critical_category']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Returns list of critical FF accounts that are NOT yet linked to
     * a QBO account. Each entry:
     *   [ff_account_id, code, name, account_type, account_subtype,
     *    critical_reason, critical_category]
     *
     * Used by:
     *   - UI banner on /quickbooks/accounts
     *   - api/v1/quickbooks/accounts/list.php KPI 'critical_unmapped'
     *   - assertReadyForFullCompliance() — throws on non-empty
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unmappedCritical(): array
    {
        return db_select(
            "SELECT m.ff_account_id, a.code, a.name, a.account_type,
                    a.account_subtype, m.critical_reason, m.critical_category
               FROM acc_qbo_account_map m
               JOIN acc_accounts a ON a.id = m.ff_account_id
              WHERE m.is_critical = 1
                AND (m.mapping_status != 'mapped' OR m.qbo_account_id IS NULL)
                AND a.is_active = 1
              ORDER BY a.code"
        );
    }

    /**
     * Returns unmapped critical accounts grouped by category. Used by
     * the per-session assert methods to format helpful error messages.
     *
     * Only categories the caller asks about are returned; categories
     * with no unmapped accounts are absent from the returned array
     * (caller treats absence as "category satisfied").
     *
     * @param  list<string> $categories  Categories to inspect
     * @return array<string, list<array<string, mixed>>>  category → list of FF rows
     */
    public static function unmappedByCategory(array $categories): array
    {
        if (empty($categories)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $rows = db_select(
            "SELECT m.ff_account_id, a.code, a.name, a.account_type,
                    a.account_subtype, m.critical_reason, m.critical_category
               FROM acc_qbo_account_map m
               JOIN acc_accounts a ON a.id = m.ff_account_id
              WHERE m.is_critical = 1
                AND m.critical_category IN ({$placeholders})
                AND (m.mapping_status != 'mapped' OR m.qbo_account_id IS NULL)
                AND a.is_active = 1
              ORDER BY m.critical_category, a.code",
            $categories
        );

        $byCategory = [];
        foreach ($rows as $r) {
            $cat = (string) $r['critical_category'];
            $byCategory[$cat][] = $r;
        }
        return $byCategory;
    }

    /**
     * Returns categories from $required that have NO mapped FF account
     * tagged with them. Used by assert methods to identify which
     * categories block the gate.
     *
     * A category with ZERO is_critical FF accounts in the chart is
     * treated as blocking — operator must add + tag an FF account
     * before the gate passes. (Applies to 'undeposited_funds' in the
     * current chart per pre-flight resolution.)
     *
     * @param  list<string> $required
     * @return list<string> Categories blocking (subset of $required)
     */
    public static function blockingCategories(array $required): array
    {
        if (empty($required)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        // For each required category, count how many is_critical FF
        // accounts are currently mapped under that category. If 0 → blocking.
        $rows = db_select(
            "SELECT cat.category, COALESCE(cnt.mapped_count, 0) AS mapped_count
               FROM (
                 -- Synthesize a single-column table from the required list.
                 -- MySQL doesn't support VALUES (...) ROW constructor at
                 -- the FROM position pre-8.0.31; SELECT … UNION ALL is
                 -- the portable shim. Build via PHP.
                 " . self::categorySynthesisSql($required) . "
               ) AS cat
               LEFT JOIN (
                 SELECT m.critical_category AS category,
                        COUNT(*) AS mapped_count
                   FROM acc_qbo_account_map m
                   JOIN acc_accounts a ON a.id = m.ff_account_id
                  WHERE m.is_critical = 1
                    AND m.mapping_status = 'mapped'
                    AND m.qbo_account_id IS NOT NULL
                    AND m.critical_category IN ({$placeholders})
                    AND a.is_active = 1
                  GROUP BY m.critical_category
               ) AS cnt ON cnt.category = cat.category",
            $required
        );
        $blocking = [];
        foreach ($rows as $r) {
            if ((int) $r['mapped_count'] === 0) {
                $blocking[] = (string) $r['category'];
            }
        }
        return $blocking;
    }

    /**
     * Build a SELECT-UNION-ALL clause that synthesizes the required
     * categories as a single-column table named 'category'. Safe for
     * portable MySQL; values are inlined from the const CATEGORIES
     * whitelist (never user input) so SQL injection is N/A.
     *
     * @param  list<string> $required
     * @return string
     */
    private static function categorySynthesisSql(array $required): string
    {
        $whitelist = self::CATEGORIES;
        $parts = [];
        foreach ($required as $cat) {
            if (!in_array($cat, $whitelist, true)) {
                continue; // ignore unknown categories
            }
            // Single-quote escape is safe; whitelist gate above is
            // the actual defense (categories are hardcoded constants).
            $safe = str_replace("'", "''", $cat);
            $parts[] = "SELECT '{$safe}' AS category";
        }
        if (empty($parts)) {
            return "SELECT NULL AS category WHERE 1=0";
        }
        return implode(' UNION ALL ', $parts);
    }

    /**
     * Pre-flight gate for S-QBO-11 invoice push. Throws
     * ChartOfAccountsIncompleteException if either ar_clearing or
     * sales_revenue category has no mapped FF account (D-QBO-VALIDATOR-4
     * — semantics narrowed from S-QBO-8 all-7-critical to invoice-push
     * minimum).
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForInvoicePush(): void
    {
        self::assertCategoriesReady('invoice_push');
    }

    /**
     * Pre-flight gate for S-QBO-13/14 payment push. Requires
     * ar_clearing + undeposited_funds. Undeposited funds may have no
     * FF account in v1 chart — operator tags one before payment push
     * (D-QBO-VALIDATOR-3 + S-QBO-VALIDATOR-SCOPE-SPLIT operator
     * AskUserQuestion resolution).
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForPaymentPush(): void
    {
        self::assertCategoriesReady('payment_push');
    }

    /**
     * Pre-flight gate for S-QBO-18 bill push. Requires ap_clearing.
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForBillPush(): void
    {
        self::assertCategoriesReady('bill_push');
    }

    /**
     * Pre-flight gate for S-QBO-19 bill payment push. Requires
     * ap_clearing + undeposited_funds.
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForBillPaymentPush(): void
    {
        self::assertCategoriesReady('bill_payment_push');
    }

    /**
     * Pre-flight gate for S-QBO-21 journal entry push. Requires
     * tax_receivable + tax_payable (the GST/HST/PST clearing accounts
     * used for end-of-period tax remit JEs).
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForJournalEntryPush(): void
    {
        self::assertCategoriesReady('journal_entry_push');
    }

    /**
     * Pre-flight gate for pre-cutover full-compliance check. Requires
     * ALL 6 categories to have at least one mapped FF account. This
     * preserves the original S-QBO-8 all-7-critical semantics for
     * cutover gating; per-session gates above are the new default for
     * individual Pusher sessions.
     *
     * @throws ChartOfAccountsIncompleteException
     */
    public static function assertReadyForFullCompliance(): void
    {
        self::assertCategoriesReady('full_compliance');
    }

    /**
     * Shared implementation for the per-session assert methods.
     * Looks up the session's required categories, identifies blocking
     * ones, and throws a descriptive exception listing both the
     * blocking categories and the FF accounts that contribute to
     * those categories (D-QBO-VALIDATOR-5).
     *
     * @param  string $sessionKey  Key in SESSION_REQUIREMENTS
     * @throws ChartOfAccountsIncompleteException
     */
    private static function assertCategoriesReady(string $sessionKey): void
    {
        $required = self::SESSION_REQUIREMENTS[$sessionKey] ?? [];
        if (empty($required)) {
            return;
        }
        $blocking = self::blockingCategories($required);
        if (empty($blocking)) {
            return;
        }

        // Build the actionable error message. For each blocking
        // category, list the FF accounts known to contribute (so the
        // operator can find them in /quickbooks/accounts and map). If
        // a category has NO contributing FF accounts (e.g.
        // undeposited_funds in v1 chart), the message names the gap.
        $unmappedByCat = self::unmappedByCategory($blocking);
        $allCriticalByCat = self::criticalAccountsByCategory($blocking);

        $parts = [];
        foreach ($blocking as $cat) {
            $rows = $unmappedByCat[$cat] ?? [];
            $allRows = $allCriticalByCat[$cat] ?? [];
            if (empty($allRows)) {
                // S-QBO-BILL-GOTCHAS-PAYDOWN: extended hint to mention Pull
                // first (recurring operator pain point — the QBO sandbox AP
                // account wasn't pulled in initial S-QBO-LIVE-VERIFY-RERUN,
                // requiring manual mapping during S-QBO-18 live test).
                $parts[] = "{$cat} (no FF account tagged with this category — if you don't see a matching QBO account in /quickbooks/accounts, click Pull to refresh from QBO first, then Auto-Match or Save Mapping; tag via critical_category column)";
                continue;
            }
            $names = array_map(
                static fn($r) => "{$r['code']} {$r['name']}",
                !empty($rows) ? $rows : $allRows
            );
            $parts[] = "{$cat}: " . implode(', ', $names);
        }
        $sessionLabel = str_replace('_', ' ', $sessionKey);
        $countLabel = count($blocking) === 1 ? 'category' : 'categories';
        $msg = "{$sessionLabel} blocked: " . count($blocking) . " required {$countLabel} unmapped — "
             . implode(' | ', $parts)
             . ". Map via /quickbooks/accounts before push.";

        // Pass the unmapped list (whatever we could find) so the worker
        // can route through the standard drift-event path.
        $flatUnmapped = [];
        foreach ($unmappedByCat as $rows) {
            foreach ($rows as $r) {
                $flatUnmapped[] = $r;
            }
        }
        throw new ChartOfAccountsIncompleteException($msg, $flatUnmapped);
    }

    /**
     * Returns all critical FF accounts (mapped + unmapped) grouped by
     * category. Used for error messages — even mapped accounts are
     * useful to surface so the operator can see the full critical
     * chart at once.
     *
     * @param  list<string> $categories
     * @return array<string, list<array<string, mixed>>>
     */
    private static function criticalAccountsByCategory(array $categories): array
    {
        if (empty($categories)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $rows = db_select(
            "SELECT m.ff_account_id, a.code, a.name, m.critical_category,
                    m.critical_reason, m.mapping_status
               FROM acc_qbo_account_map m
               JOIN acc_accounts a ON a.id = m.ff_account_id
              WHERE m.is_critical = 1
                AND m.critical_category IN ({$placeholders})
                AND a.is_active = 1
              ORDER BY m.critical_category, a.code",
            $categories
        );
        $byCategory = [];
        foreach ($rows as $r) {
            $cat = (string) $r['critical_category'];
            $byCategory[$cat][] = $r;
        }
        return $byCategory;
    }

    /**
     * Heuristic for identifying critical FF accounts. Returns list of
     * [id, code, name, critical_reason, critical_category] from live
     * acc_accounts.
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
        // own reason string AND category tag (D-QBO-VALIDATOR-2).
        $sql = "
            -- Accounts Receivable
            SELECT id, code, name,
                   'Accounts Receivable (AR clearing)' AS critical_reason,
                   'ar_clearing'                       AS critical_category
              FROM acc_accounts
             WHERE code = '1030' AND is_active = 1
            UNION ALL
            -- Accounts Payable
            SELECT id, code, name,
                   'Accounts Payable (AP clearing)' AS critical_reason,
                   'ap_clearing'                    AS critical_category
              FROM acc_accounts
             WHERE code = '2010' AND is_active = 1
            UNION ALL
            -- Tax Receivable (Input Tax Credits)
            SELECT id, code, name,
                   'GST/HST Receivable (Input Tax Credits)' AS critical_reason,
                   'tax_receivable'                         AS critical_category
              FROM acc_accounts
             WHERE code = '1050' AND is_active = 1
            UNION ALL
            SELECT id, code, name,
                   'PST Receivable'  AS critical_reason,
                   'tax_receivable'  AS critical_category
              FROM acc_accounts
             WHERE code = '1060' AND is_active = 1
            UNION ALL
            -- Tax Payable
            SELECT id, code, name,
                   'GST/HST Payable' AS critical_reason,
                   'tax_payable'     AS critical_category
              FROM acc_accounts
             WHERE code = '2030' AND is_active = 1
            UNION ALL
            SELECT id, code, name,
                   'PST Payable'  AS critical_reason,
                   'tax_payable'  AS critical_category
              FROM acc_accounts
             WHERE code = '2040' AND is_active = 1
            UNION ALL
            -- Sales Revenue (is_system=1 AND code in 4xxx)
            SELECT id, code, name,
                   CONCAT('Sales Revenue (', name, ')') AS critical_reason,
                   'sales_revenue'                      AS critical_category
              FROM acc_accounts
             WHERE code LIKE '4%'
               AND is_system = 1
               AND is_active = 1
               AND account_type = 'revenue'
        ";
        return db_select($sql);
    }
}
