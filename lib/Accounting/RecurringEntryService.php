<?php
declare(strict_types=1);

/**
 * lib/Accounting/RecurringEntryService.php
 *
 * Recurring journal-entry template engine. Pure logic — the daily cron
 * (cron/accounting_recurring_entries.php) and the manual "Post Now"
 * endpoint (api/v1/accounting/recurring/post_now.php) both consume it.
 *
 * Schema-on-disk: acc_recurring_entries has `is_active` (no separate
 * `status` column); acc_recurring_entry_lines has plain `description`
 * (no `description_template`). Engine treats the line description as
 * literal text — the per-month / per-period substitution happens only
 * on the JE header description.
 *
 * Scheduling is driven by `next_post_date` (catch-up), not by "is today the
 * day": every occurrence from next_post_date up to today is posted once, in
 * order, each dated on its OWN scheduled date and keyed on its own month.
 * The original engine only fired when today's day-of-month matched, so a
 * single missed run (server down, cron toggled off that day, a closed period)
 * silently lost that month forever — and the next run jumped next_post_date
 * past the gap. See dueOccurrences() / catchUp().
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.3
 * Session:  S037-REC
 */

namespace FleetForge\Accounting;

class RecurringEntryService
{
    /**
     * Is this template due for posting on $today?
     *
     * Day-of-month rule: literal match, with the special case that
     * day_of_month 29/30/31 clamps to the last day of the current
     * month (so a "post on the 31st" template still fires on Feb 28).
     *
     * Frequency rule:
     *   monthly   — fires every month on day_of_month
     *   quarterly — fires on day_of_month every 3rd month from start_date's month
     *   annually  — fires on day_of_month + month-of(start_date)
     *
     * Bounded by start_date (inclusive) and end_date (inclusive,
     * NULL = open-ended).
     */
    public static function isDueToday(array $template, string $today): bool
    {
        $todayTs = strtotime($today);
        if ($todayTs === false) return false;

        $start = (string) $template['start_date'];
        if ($today < $start) return false;
        $end = $template['end_date'] ?? null;
        if ($end !== null && $today > $end) return false;

        // Day-of-month with end-of-month clamping
        $todayDay  = (int) date('j', $todayTs);
        $monthDays = (int) date('t', $todayTs);
        $targetDay = min((int) $template['day_of_month'], $monthDays);
        if ($todayDay !== $targetDay) return false;

        $todayMonth = (int) date('n', $todayTs);
        $startMonth = (int) date('n', strtotime($start));

        return match ($template['frequency']) {
            'monthly'   => true,
            'quarterly' => (($todayMonth - $startMonth) % 3 + 3) % 3 === 0,
            'annually'  => $todayMonth === $startMonth,
            default     => false,
        };
    }

    /**
     * Idempotency key for a posted recurring JE. Stamped on
     * acc_journal_entries.reference; checked before every post.
     */
    public static function buildJeReference(int $templateId, string $today): string
    {
        return sprintf('[REC-%d-%s]', $templateId, date('Y-m', strtotime($today)));
    }

    /**
     * Compute the next due date after $postedDate.
     *
     * @param array  $template acc_recurring_entries row (frequency, day_of_month)
     * @param string $postedDate YYYY-MM-DD of the occurrence just posted
     * @return string YYYY-MM-DD of the following occurrence
     */
    public static function computeNextPostDate(array $template, string $postedDate): string
    {
        $postedTs = strtotime($postedDate);
        $targetDay = (int) $template['day_of_month'];

        $monthsForward = match ($template['frequency']) {
            'monthly'   => 1,
            'quarterly' => 3,
            'annually'  => 12,
            default     => 1,
        };

        // Move to the target month by stepping forward one month at a time
        // from the 1st of the posted month (avoids the classic "Jan 31 + 1
        // month = Mar 3" PHP date pitfall).
        $cursor = strtotime(date('Y-m-01', $postedTs));
        for ($i = 0; $i < $monthsForward; $i++) {
            $cursor = strtotime('+1 month', $cursor);
        }
        $monthYear = date('Y-m', $cursor);
        $monthDays = (int) date('t', $cursor);
        $day = min($targetDay, $monthDays);
        return sprintf('%s-%02d', $monthYear, $day);
    }

    /**
     * Post a template. Idempotent: if a JE already exists with the
     * same reference key, return it unchanged.
     *
     * @return array { je: <row>, created: bool, skipped_reason?: string }
     */
    public static function postTemplate(array $template, string $today, int $userId): array
    {
        $templateId = (int) $template['id'];
        $reference  = self::buildJeReference($templateId, $today);

        return \db_transaction(function () use ($template, $today, $userId, $templateId, $reference) {
            // ── Idempotency: same reference key for this template?
            $existing = \db_row(
                "SELECT * FROM acc_journal_entries
                  WHERE source_type = 'recurring'
                    AND source_id = ?
                    AND reference = ?
                  LIMIT 1",
                [$templateId, $reference]
            );
            if ($existing) {
                // Still move the schedule past this occurrence — otherwise an
                // occurrence posted earlier (e.g. via Post Now) would pin
                // next_post_date and stall catch-up on it forever.
                self::advanceSchedule($template, $today);
                return ['je' => $existing, 'created' => false, 'skipped_reason' => 'already_posted'];
            }

            // ── Lines must exist (STOP condition: never post a zero-line JE)
            $lines = \db_select(
                "SELECT account_id, line_number, description, debit, credit
                   FROM acc_recurring_entry_lines
                  WHERE recurring_entry_id = ?
                  ORDER BY line_number, id",
                [$templateId]
            );
            if (count($lines) === 0) {
                throw new \RuntimeException("Template #{$templateId} has no lines — cannot post.");
            }

            // ── Period must exist + be open (STOP condition: skip if no open period)
            $period = AccountingService::periodForDate($today);
            if (!$period) {
                throw new \RuntimeException("No accounting period found for {$today}.");
            }
            if ($period['status'] !== 'open') {
                throw new \RuntimeException("Period '{$period['name']}' is {$period['status']} — cannot post recurring JE.");
            }

            // ── Build JE lines (bcmath strings)
            $jeLines = [];
            foreach ($lines as $l) {
                $jeLines[] = [
                    'account_id'  => (int) $l['account_id'],
                    // D16 bcmath: normalize to 2dp WITHOUT a float round-trip
                    // (number_format((float)…) reintroduces binary-float error on money).
                    'debit'       => bcadd((string) $l['debit'], '0', 2),
                    'credit'      => bcadd((string) $l['credit'], '0', 2),
                    'description' => $l['description'] ?? null,
                ];
            }

            // ── JE header
            $headerDescription = (string) $template['name']
                . ' — ' . date('M Y', strtotime($today));
            $autoPost = ((int) ($template['auto_post'] ?? 0)) === 1;

            $je = JournalEntryService::create([
                'entry_date'       => $today,
                'description'      => $headerDescription,
                'entry_type'       => 'recurring',
                'reference'        => $reference,
                'source_type'      => 'recurring',
                'source_id'        => $templateId,
                'currency'         => 'CAD',
                'post_immediately' => $autoPost,
            ], $jeLines, $userId);

            // ── Update template metadata (forward-only — see advanceSchedule())
            self::advanceSchedule($template, $today);

            // ── Audit log
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => function_exists('current_user') ? (\current_user()['name'] ?? 'system') : 'system',
                'action'       => 'create',
                'module'       => 'accounting',
                'entity_type'  => 'recurring_entry',
                'entity_id'    => $templateId,
                'entity_label' => (string) $template['name'],
                'notes'        => sprintf(
                    'Recurring JE %s — ref %s — JE %s (%s)',
                    $autoPost ? 'posted' : 'drafted',
                    $reference,
                    $je['entry_number'],
                    $autoPost ? 'auto-post=1' : 'auto-post=0; draft for review'
                ),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return ['je' => $je, 'created' => true];
        });
    }

    /**
     * Move a template's schedule past an occurrence. FORWARD-ONLY: posting an
     * older month by hand (Post Now with an explicit date) must never pull
     * next_post_date / last_posted_date backwards and re-open months that are
     * already done.
     *
     * @param array  $template   acc_recurring_entries row
     * @param string $occurrence YYYY-MM-DD just posted (or found already posted)
     * @return void
     */
    private static function advanceSchedule(array $template, string $occurrence): void
    {
        $next = self::computeNextPostDate($template, $occurrence);
        \db_execute(
            "UPDATE acc_recurring_entries
                SET next_post_date   = GREATEST(next_post_date, ?),
                    last_posted_date = GREATEST(COALESCE(last_posted_date, ?), ?)
              WHERE id = ?",
            [$next, $occurrence, $occurrence, (int) $template['id']]
        );
    }

    /**
     * Every scheduled occurrence that is due on or before $today and not yet
     * posted, oldest first: next_post_date, then each following cycle, bounded
     * by start_date (an occurrence before it is skipped), end_date (inclusive)
     * and $limit.
     *
     * Pure — reads only the template row passed in.
     *
     * @param array  $template acc_recurring_entries row
     * @param string $today    YYYY-MM-DD (business-local)
     * @param int    $limit    safety cap on occurrences returned
     * @return string[] YYYY-MM-DD dates
     */
    public static function dueOccurrences(array $template, string $today, int $limit = 60): array
    {
        $cursor = (string) ($template['next_post_date'] ?? '');
        if ($cursor === '' || strtotime($cursor) === false) {
            return [];
        }
        $start = (string) $template['start_date'];
        $end   = $template['end_date'] ?? null;

        $out   = [];
        $guard = 0;
        // Plain YYYY-MM-DD strings compare correctly as strings.
        while ($cursor <= $today && ($end === null || $cursor <= $end) && count($out) < $limit) {
            if ($cursor >= $start) {
                $out[] = $cursor;
            }
            $next = self::computeNextPostDate($template, $cursor);
            if ($next <= $cursor || ++$guard > 1200) {
                break; // defensive: a non-advancing schedule must not loop
            }
            $cursor = $next;
        }
        return $out;
    }

    /**
     * Post every overdue occurrence of a template, oldest first — each dated
     * on its scheduled date and idempotent per month via the reference key, so
     * re-running (or a cron + a manual catch-up racing) never double-posts.
     *
     * Stops at the FIRST failure (closed period, missing lines, header
     * account…) and rethrows nothing: next_post_date stays on the failing
     * occurrence, so the template keeps showing as overdue with the reason,
     * and later months are not posted around the gap.
     *
     * Each occurrence commits in its own transaction (postTemplate), so the
     * months posted before a failure stay posted.
     *
     * @param array  $template acc_recurring_entries row
     * @param string $today    YYYY-MM-DD (business-local)
     * @param int    $userId   posting user
     * @param int    $limit    max occurrences this call
     * @return array{posted: array<int,array>, skipped: array<int,array>, error: ?string, failed_date: ?string, remaining: int, next_post_date: ?string}
     */
    public static function catchUp(array $template, string $today, int $userId, int $limit = 60): array
    {
        $posted  = [];
        $skipped = [];
        $error   = null;
        $failedDate = null;
        $templateId = (int) $template['id'];

        // Per-template advisory lock: the nightly cron and an operator's
        // "Post Now" can run at the same moment. The reference-key check in
        // postTemplate() is read-then-insert, so without serialising the two
        // callers both could see "not posted yet" and post the month twice.
        $lockName = 'ff_acct_recurring_tpl_' . $templateId;
        $lock = \db_row("SELECT GET_LOCK(?, 10) AS ok", [$lockName]);
        if (!$lock || (int) $lock['ok'] !== 1) {
            return [
                'posted' => [], 'skipped' => [], 'failed_date' => null,
                'error' => 'Another posting run for this template is in progress — try again in a moment.',
                'remaining' => count(self::dueOccurrences($template, $today, 1000)),
                'next_post_date' => $template['next_post_date'] ?? null,
            ];
        }

        // Re-read under the lock: the other runner may have just advanced it.
        $template = \db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$templateId]) ?: $template;

        try {
            foreach (self::dueOccurrences($template, $today, $limit) as $date) {
                try {
                    $r = self::postTemplate($template, $date, $userId);
                } catch (\Throwable $e) {
                    $error      = $e->getMessage();
                    $failedDate = $date;
                    break;
                }
                $row = [
                    'date'         => $date,
                    'je_id'        => (int) ($r['je']['id'] ?? 0),
                    'entry_number' => $r['je']['entry_number'] ?? null,
                    'status'       => $r['je']['status'] ?? null,
                ];
                if ($r['created']) {
                    $posted[] = $row;
                } else {
                    $skipped[] = $row + ['reason' => $r['skipped_reason'] ?? 'already_posted'];
                }
            }
        } finally {
            \db_row("SELECT RELEASE_LOCK(?) AS released", [$lockName]);
        }

        $fresh = \db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$templateId]) ?: $template;
        return [
            'posted'         => $posted,
            'skipped'        => $skipped,
            'error'          => $error,
            'failed_date'    => $failedDate,
            'remaining'      => count(self::dueOccurrences($fresh, $today, 1000)),
            'next_post_date' => $fresh['next_post_date'] ?? null,
        ];
    }

    /**
     * Active templates with at least one occurrence due on or before $today
     * (next_post_date <= today). Unlike fetchActiveTemplates() this still
     * returns a template whose end_date has passed but which has unposted
     * occurrences from before it ended.
     *
     * @param string $today YYYY-MM-DD
     * @return array<int,array> acc_recurring_entries rows
     */
    public static function fetchDueTemplates(string $today): array
    {
        return \db_select(
            "SELECT * FROM acc_recurring_entries
              WHERE is_active = 1
                AND next_post_date <= ?
                AND (end_date IS NULL OR next_post_date <= end_date)
              ORDER BY next_post_date, id",
            [$today]
        );
    }

    /**
     * Fetch all templates currently in-window for posting on $today
     * (active + within start/end dates).
     */
    public static function fetchActiveTemplates(string $today): array
    {
        return \db_select(
            "SELECT * FROM acc_recurring_entries
              WHERE is_active = 1
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)
              ORDER BY id",
            [$today, $today]
        );
    }
}
