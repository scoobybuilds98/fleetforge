<?php
declare(strict_types=1);

/**
 * tests/_smoke_recurring_entries_catchup.php
 *
 * Regression lock: recurring journal entries CATCH UP on missed occurrences.
 *
 * The bug: cron/accounting_recurring_entries.php only posted a template when
 * RecurringEntryService::isDueToday() matched today's day-of-month. A single
 * missed run (server down on the 1st, the job toggled off, a closed period)
 * lost that month permanently — dev had three monthly templates stuck at
 * next_post_date 2026-08-01 on 2026-09-16 with nothing ever able to post them,
 * and the existing "Post Now" posted only TODAY's month (dated today),
 * skipping August entirely.
 *
 * Asserts (all against the real engine + real JournalEntryService):
 *   - dueOccurrences(): monthly gap list; day-31 end-of-month clamp; quarterly
 *     cadence; end_date bound; start_date floor.
 *   - catchUp(): posts each missed month once, dated on its scheduled date,
 *     keyed [REC-id-YYYY-MM]; advances next_post_date / last_posted_date.
 *   - idempotent: a second catch-up posts nothing; a stale next_post_date
 *     re-walks already-posted months as skips and moves forward again.
 *   - forward-only: posting an older month by hand never pulls the schedule back.
 *   - stops at the first failure (closed period) with next_post_date parked on
 *     the failing occurrence and the earlier month still posted.
 *   - fetchDueTemplates() still returns a template whose end_date has passed
 *     but which has unposted occurrences before it.
 *
 * HERMETIC: one transaction, ROLLED BACK at the end (nested db_transaction()
 * calls inside the engine join it).
 *
 * Run:  php tests/_smoke_recurring_entries_catchup.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\RecurringEntryService;

$passes = 0;
$failures = [];
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$same = static function (string $label, mixed $got, mixed $want) use ($pass, $fail): void {
    $got === $want ? $pass($label) : $fail($label . ' — got ' . json_encode($got) . ', want ' . json_encode($want));
};

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    $expense = (int) (db_row("SELECT id FROM acc_accounts WHERE code = '6050' AND is_header = 0 AND is_active = 1")['id'] ?? 0);
    $cash    = (int) (db_row("SELECT id FROM acc_accounts WHERE code = '1010' AND is_header = 0 AND is_active = 1")['id'] ?? 0);
    $userId  = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$expense || !$cash || !$userId) { echo "SETUP FAIL: need accounts 6050 + 1010 and a user\n"; $pdo->rollBack(); exit(2); }

    $TODAY = '2026-09-16';

    /**
     * Seed a template + its two balanced lines inside the smoke transaction.
     */
    $mkTemplate = static function (array $over) use ($expense, $cash, $userId): array {
        $id = (int) db_insert('acc_recurring_entries', $over + [
            'name' => 'Catch-up smoke ' . getmypid(), 'frequency' => 'monthly', 'day_of_month' => 1,
            'start_date' => '2026-01-01', 'end_date' => null, 'next_post_date' => '2026-07-01',
            'last_posted_date' => null, 'is_active' => 1, 'auto_post' => 0, 'created_by' => $userId,
        ]);
        db_insert('acc_recurring_entry_lines', ['recurring_entry_id' => $id, 'account_id' => $expense, 'line_number' => 1, 'description' => 'smoke', 'debit' => '125.00', 'credit' => '0.00']);
        db_insert('acc_recurring_entry_lines', ['recurring_entry_id' => $id, 'account_id' => $cash, 'line_number' => 2, 'description' => 'smoke', 'debit' => '0.00', 'credit' => '125.00']);
        return db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [$id]);
    };
    $reload = static fn(array $t): array => db_row("SELECT * FROM acc_recurring_entries WHERE id = ?", [(int) $t['id']]);
    $jes = static fn(array $t): array => db_select(
        "SELECT entry_date, reference, status FROM acc_journal_entries
          WHERE source_type = 'recurring' AND source_id = ? ORDER BY entry_date", [(int) $t['id']]
    );

    echo str_repeat('─', 72) . "\ndueOccurrences()\n" . str_repeat('─', 72) . "\n";
    $same('monthly gap Jul→Sep on 2026-09-16',
        RecurringEntryService::dueOccurrences(['next_post_date' => '2026-07-01', 'start_date' => '2026-01-01', 'end_date' => null, 'frequency' => 'monthly', 'day_of_month' => 1], $TODAY),
        ['2026-07-01', '2026-08-01', '2026-09-01']);
    $same('day 31 clamps to month end (Jan 31, Feb 28, Mar 31)',
        RecurringEntryService::dueOccurrences(['next_post_date' => '2026-01-31', 'start_date' => '2026-01-01', 'end_date' => null, 'frequency' => 'monthly', 'day_of_month' => 31], '2026-04-15'),
        ['2026-01-31', '2026-02-28', '2026-03-31']);
    $same('quarterly cadence',
        RecurringEntryService::dueOccurrences(['next_post_date' => '2026-01-01', 'start_date' => '2026-01-01', 'end_date' => null, 'frequency' => 'quarterly', 'day_of_month' => 1], $TODAY),
        ['2026-01-01', '2026-04-01', '2026-07-01']);
    $same('end_date bounds the list',
        RecurringEntryService::dueOccurrences(['next_post_date' => '2026-07-01', 'start_date' => '2026-01-01', 'end_date' => '2026-08-15', 'frequency' => 'monthly', 'day_of_month' => 1], $TODAY),
        ['2026-07-01', '2026-08-01']);
    $same('nothing due when next_post_date is in the future',
        RecurringEntryService::dueOccurrences(['next_post_date' => '2026-10-01', 'start_date' => '2026-01-01', 'end_date' => null, 'frequency' => 'monthly', 'day_of_month' => 1], $TODAY),
        []);
    $same('pre-fix engine would post NOTHING today (isDueToday false on the 16th)',
        RecurringEntryService::isDueToday(['start_date' => '2026-01-01', 'end_date' => null, 'frequency' => 'monthly', 'day_of_month' => 1], $TODAY),
        false);

    echo str_repeat('─', 72) . "\ncatchUp()\n" . str_repeat('─', 72) . "\n";
    $t = $mkTemplate([]);
    $r = RecurringEntryService::catchUp($t, $TODAY, $userId);
    $same('posted 3 missed occurrences', count($r['posted']), 3);
    $same('no error', $r['error'], null);
    $rows = $jes($t);
    $same('each dated on its scheduled date', array_column($rows, 'entry_date'), ['2026-07-01', '2026-08-01', '2026-09-01']);
    $same('each keyed on its own month', array_column($rows, 'reference'),
        ["[REC-{$t['id']}-2026-07]", "[REC-{$t['id']}-2026-08]", "[REC-{$t['id']}-2026-09]"]);
    $same('auto_post=0 → drafts for review', array_unique(array_column($rows, 'status')), ['draft']);
    $t = $reload($t);
    $same('next_post_date advanced to 2026-10-01', $t['next_post_date'], '2026-10-01');
    $same('last_posted_date = 2026-09-01', $t['last_posted_date'], '2026-09-01');
    $same('remaining 0', $r['remaining'], 0);

    $r2 = RecurringEntryService::catchUp($t, $TODAY, $userId);
    $same('second catch-up posts nothing', [count($r2['posted']), count($r2['skipped'])], [0, 0]);

    db_execute("UPDATE acc_recurring_entries SET next_post_date = '2026-08-01' WHERE id = ?", [(int) $t['id']]);
    $r3 = RecurringEntryService::catchUp($reload($t), $TODAY, $userId);
    $same('stale pointer: already-posted months become skips, not duplicates', [count($r3['posted']), count($r3['skipped'])], [0, 2]);
    $same('…and the schedule moves forward again', $reload($t)['next_post_date'], '2026-10-01');
    $same('still exactly 3 JEs', count($jes($t)), 3);

    RecurringEntryService::postTemplate($reload($t), '2026-06-01', $userId);
    $t = $reload($t);
    $same('forward-only: posting June by hand keeps next_post_date', $t['next_post_date'], '2026-10-01');
    $same('forward-only: …and last_posted_date', $t['last_posted_date'], '2026-09-01');

    echo str_repeat('─', 72) . "\nfailure + fetchDueTemplates()\n" . str_repeat('─', 72) . "\n";
    // First CLOSED month whose previous month is OPEN, so the run posts one month then stops.
    $closed = db_row(
        "SELECT c.start_date AS closed_start, o.start_date AS open_start
           FROM acc_periods c
           JOIN acc_periods o ON o.end_date = DATE_SUB(c.start_date, INTERVAL 1 DAY)
          WHERE c.status IN ('closed','locked') AND o.status = 'open'
          ORDER BY c.start_date LIMIT 1"
    );
    if (!$closed) {
        $pass('(skipped closed-period stop: no open→closed month pair in this DB)');
    } else {
        $tf = $mkTemplate(['next_post_date' => $closed['open_start'], 'start_date' => $closed['open_start']]);
        $rf = RecurringEntryService::catchUp($tf, $TODAY, $userId);
        $same("posts the open month ({$closed['open_start']}) then stops", count($rf['posted']), 1);
        $same('stops on the closed month', $rf['failed_date'], $closed['closed_start']);
        $rf['error'] !== null && str_contains($rf['error'], 'cannot post recurring JE')
            ? $pass('error explains the closed period: ' . $rf['error'])
            : $fail('unexpected error: ' . json_encode($rf['error']));
        $same('next_post_date parked on the failing occurrence', $reload($tf)['next_post_date'], $closed['closed_start']);
        $rf['remaining'] > 0 ? $pass("still overdue ({$rf['remaining']} remaining)") : $fail('remaining should be > 0');
    }

    $te = $mkTemplate(['end_date' => '2026-08-15']);
    $dueIds = array_map(static fn($x) => (int) $x['id'], RecurringEntryService::fetchDueTemplates($TODAY));
    in_array((int) $te['id'], $dueIds, true)
        ? $pass('fetchDueTemplates() keeps an ended template with unposted months')
        : $fail('fetchDueTemplates() dropped the ended-but-overdue template');
    $re = RecurringEntryService::catchUp($te, $TODAY, $userId);
    $same('ended template posts only up to end_date', array_column($re['posted'], 'date'), ['2026-07-01', '2026-08-01']);
} catch (\Throwable $e) {
    $fail('exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n  (rolled back — no rows persisted)\n";
}

echo str_repeat('─', 72) . "\n";
echo 'RECURRING CATCH-UP — ' . $passes . ' passed, ' . count($failures) . " failed\n";
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
