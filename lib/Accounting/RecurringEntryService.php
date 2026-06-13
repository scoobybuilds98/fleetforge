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

            // ── Update template metadata
            \db_update(
                'acc_recurring_entries',
                [
                    'last_posted_date' => $today,
                    'next_post_date'   => self::computeNextPostDate($template, $today),
                ],
                'id = ?',
                [$templateId]
            );

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
