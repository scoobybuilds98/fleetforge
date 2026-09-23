<?php
declare(strict_types=1);

/**
 * FleetForge — live checks for the month-end checklist (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopSignals.php
 * @description Next to each month-end step the SOP shows what the books say
 *              RIGHT NOW: "12 draft invoices dated on or before Aug 31",
 *              "Trial balance is balanced", "1 of 2 bank accounts reconciled".
 *              A tick is a person's word that a step is done; the signal is
 *              the system's. They are shown side by side on purpose — a
 *              ticked step with a red signal is the thing to look at.
 *
 *              Each signal is keyed by the checklist item key (see
 *              docs/sop/10-month-end-close.md) and is one of:
 *                ok     — nothing left to do for this step
 *                warn   — something is still open (the text says what)
 *                danger — the books disagree (trial balance out)
 *                info   — context, not a verdict (open claims, sync off)
 *                error  — the check itself failed; logged, never hidden
 *              Steps with no computable check (e.g. "payments recorded")
 *              simply have no signal.
 *
 *              Only COUNTS and yes/no verdicts are returned — never amounts —
 *              so the payload stays safe for every role that can see the
 *              checklist (SopChecklist::canView()).
 *
 *              Dates: DATE columns compared against the month's calendar
 *              bounds; "today" is ff_today() (business timezone), never
 *              CURDATE() — the DB session is UTC (project_local_day_vs_utc).
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

use FleetForge\Accounting\AccountingService;
use FleetForge\QboPushers\ItemAccountCheck;

final class SopSignals
{
    /**
     * All signals for one period.
     *
     * @return array<string, array{state:string, text:string}>
     */
    public static function forPeriod(string $period): array
    {
        [$start, $end] = SopChecklist::bounds($period);
        $endLabel = (new \DateTimeImmutable($end))->format('M j');
        $row      = self::periodRow($period);
        $qboOn    = (string) settings_get('quickbooks.sync_enabled', '0') === '1';

        $checks = [
            'leases_closed'       => fn () => self::leasesClosed($end, $endLabel),
            'invoices_sent'       => fn () => self::draftInvoices($end, $endLabel),
            'bills_approved'      => fn () => self::draftBills($end, $endLabel),
            'damage_claims'       => fn () => self::openClaims($end),
            'je_drafts'           => fn () => self::draftJournals($end, $endLabel),
            'recurring_current'   => fn () => self::recurringOverdue($end),
            'depreciation_posted' => fn () => self::depreciation($row),
            'bank_reconciled'     => fn () => self::bankReconciled($row),
            'ar_reconciled'       => fn () => self::subledger(AccountingService::arReconciliationCheck(), 'AR aging', '1030'),
            'ap_reconciled'       => fn () => self::subledger(AccountingService::apReconciliationCheck(), 'AP aging', '2010'),
            'trial_balance'       => fn () => self::trialBalance($end, $endLabel),
            'qbo_queue'           => fn () => $qboOn ? self::qboQueue() : self::qboOff(),
            'qbo_drift'           => fn () => $qboOn ? self::qboDrift() : self::qboOff(),
            'qbo_items'           => fn () => $qboOn ? self::qboItems() : self::qboOff(),
            'period_closed'       => fn () => self::periodClosed($row),
        ];

        $out = [];
        foreach ($checks as $key => $check) {
            try {
                $out[$key] = $check();
            } catch (\Throwable $e) {
                // A broken check must be SEEN, not read as "all clear": the
                // item shows "Couldn't check", and the cause goes to the log.
                error_log("[SOP signals] {$key} failed: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $out[$key] = ['state' => 'error', 'text' => "Couldn't check — see the error log"];
            }
        }
        return $out;
    }

    /** @return array{state:string,text:string} */
    private static function s(string $state, string $text): array
    {
        return ['state' => $state, 'text' => $text];
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    /** @return array{id:int,status:string}|null the accounting period row for the month */
    private static function periodRow(string $period): ?array
    {
        $row = db_row("SELECT id, status FROM acc_periods WHERE year = ? AND month = ?", [(int) substr($period, 0, 4), (int) substr($period, 5, 2)]);
        return $row ? ['id' => (int) $row['id'], 'status' => (string) $row['status']] : null;
    }

    // ── A. Documents ─────────────────────────────────────────────

    private static function leasesClosed(string $end, string $endLabel): array
    {
        $n = db_count(
            "SELECT COUNT(*) FROM leases
              WHERE status = 'active' AND deleted_at IS NULL
                AND end_date IS NOT NULL AND end_date <= ?",
            [$end]
        );
        return $n === 0
            ? self::s('ok', 'No active lease past its end date')
            : self::s('warn', self::plural($n, 'active lease') . " ended on or before {$endLabel}");
    }

    private static function draftInvoices(string $end, string $endLabel): array
    {
        $n = db_count(
            "SELECT COUNT(*) FROM invoices WHERE status = 'draft' AND deleted_at IS NULL AND invoice_date <= ?",
            [$end]
        );
        return $n === 0
            ? self::s('ok', "No drafts dated on or before {$endLabel}")
            : self::s('warn', self::plural($n, 'draft invoice') . " dated on or before {$endLabel}");
    }

    private static function draftBills(string $end, string $endLabel): array
    {
        $n = db_count("SELECT COUNT(*) FROM acc_bills WHERE status = 'draft' AND bill_date <= ?", [$end]);
        return $n === 0
            ? self::s('ok', 'No draft bills')
            : self::s('warn', self::plural($n, 'draft bill') . " dated on or before {$endLabel}");
    }

    private static function openClaims(string $end): array
    {
        $n = db_count(
            "SELECT COUNT(*) FROM damage_claims
              WHERE deleted_at IS NULL AND status NOT IN ('resolved','written_off')
                AND created_at < ?",
            // created_at is a UTC DATETIME: "on or before the month's last
            // day" = before the next local midnight, in UTC.
            [ff_local_day_start_utc((new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'))]
        );
        return self::s('info', $n === 0 ? 'No open damage claims' : self::plural($n, 'open damage claim') . ' to review');
    }

    // ── B. Ledger housekeeping ───────────────────────────────────

    private static function draftJournals(string $end, string $endLabel): array
    {
        $n = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE status = 'draft' AND entry_date <= ?", [$end]);
        return $n === 0
            ? self::s('ok', 'No draft journal entries')
            : self::s('warn', self::plural($n, 'draft journal entry', 'draft journal entries') . " dated on or before {$endLabel}");
    }

    private static function recurringOverdue(string $end): array
    {
        // Due = its next run date has passed by the month's end (or today,
        // for the month still running) and the template is still live.
        $cutoff = min($end, ff_today());
        $n = db_count(
            "SELECT COUNT(*) FROM acc_recurring_entries
              WHERE is_active = 1 AND next_post_date IS NOT NULL AND next_post_date <= ?
                AND (end_date IS NULL OR next_post_date <= end_date)",
            [$cutoff]
        );
        return $n === 0
            ? self::s('ok', 'No recurring entry is overdue')
            : self::s('warn', self::plural($n, 'recurring template') . ' overdue — use Catch up now');
    }

    private static function depreciation(?array $period): array
    {
        if ($period === null) {
            return self::s('info', 'No accounting period exists for this month');
        }
        $posted = db_count(
            "SELECT COUNT(*) FROM acc_depreciation_runs WHERE period_id = ? AND status = 'posted'",
            [$period['id']]
        );
        return $posted > 0
            ? self::s('ok', 'Depreciation posted for the month')
            : self::s('warn', 'No depreciation run posted for the month');
    }

    // ── C. Reconcile ─────────────────────────────────────────────

    private static function bankReconciled(?array $period): array
    {
        $accounts = db_count("SELECT COUNT(*) FROM acc_bank_accounts WHERE is_active = 1");
        if ($accounts === 0) {
            return self::s('info', 'No active bank accounts set up');
        }
        if ($period === null) {
            return self::s('info', 'No accounting period exists for this month');
        }
        $done = db_count(
            "SELECT COUNT(DISTINCT r.bank_account_id)
               FROM acc_bank_reconciliations r
               JOIN acc_bank_accounts b ON b.id = r.bank_account_id AND b.is_active = 1
              WHERE r.period_id = ? AND r.status IN ('completed','locked')",
            [$period['id']]
        );
        return $done >= $accounts
            ? self::s('ok', 'Every bank account reconciled')
            : self::s('warn', "{$done} of " . self::plural($accounts, 'bank account') . ' reconciled');
    }

    /** AR/AP aging vs the control account — as of now (the check has no date). */
    private static function subledger(array $check, string $what, string $account): array
    {
        return !empty($check['is_reconciled'])
            ? self::s('ok', "{$what} matches account {$account} (as of today)")
            : self::s('warn', "{$what} and account {$account} differ — open Check Reconciliation");
    }

    private static function trialBalance(string $end, string $endLabel): array
    {
        $row = db_row(
            "SELECT COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
               FROM acc_journal_entry_lines l
               JOIN acc_journal_entries je ON je.id = l.journal_entry_id
              WHERE je.status IN (" . AccountingService::LEDGER_STATUSES_SQL . ")
                AND je.entry_date <= ?",
            [$end]
        );
        return bccomp((string) ($row['dr'] ?? '0'), (string) ($row['cr'] ?? '0'), 2) === 0
            ? self::s('ok', "Balanced through {$endLabel}")
            : self::s('danger', "Debits and credits differ through {$endLabel}");
    }

    // ── D. QuickBooks ────────────────────────────────────────────

    private static function qboOff(): array
    {
        return self::s('info', 'QuickBooks sync is off — nothing to check yet');
    }

    private static function qboQueue(): array
    {
        $failed = db_count("SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE status = 'failed'");
        return $failed === 0
            ? self::s('ok', 'No failed items in the Sync Queue')
            : self::s('warn', self::plural($failed, 'failed item') . ' in the Sync Queue');
    }

    private static function qboDrift(): array
    {
        $open = db_count("SELECT COUNT(*) FROM acc_qbo_drift_events WHERE resolved_at IS NULL");
        return $open === 0
            ? self::s('ok', 'No open drift events')
            : self::s('warn', self::plural($open, 'open drift event') . ' to work');
    }

    private static function qboItems(): array
    {
        $different = (int) (ItemAccountCheck::checkAll()['counts']['different'] ?? 0);
        return $different === 0
            ? self::s('ok', 'No item posts to a different account')
            : self::s('warn', self::plural($different, 'item') . ' post to a different account (F85)');
    }

    // ── E. Close ─────────────────────────────────────────────────

    private static function periodClosed(?array $period): array
    {
        if ($period === null) {
            return self::s('info', 'No accounting period exists for this month');
        }
        return match ($period['status']) {
            'locked' => self::s('ok', 'Period closed and locked'),
            'closed' => self::s('ok', 'Period closed'),
            default  => self::s('warn', 'Period still open'),
        };
    }
}
