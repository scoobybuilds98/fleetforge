<?php
declare(strict_types=1);

/**
 * scripts/cleanup_orphan_test_jes_2026_05_03.php
 *
 * One-shot cleanup of orphan JE rows left by the S-FIX-2 regression test
 * (tests/_smoke_session4_regression.php).
 *
 * BACKGROUND
 * ----------
 * The regression test created payments and credit_notes during T14/T15, which
 * fired AutoEntryBridge and wrote JEs (JE-2026-00058..00061, JE-2026-00068..
 * 00070). The test cleanup deleted the source payments/credit_notes first, then
 * tried to delete their JEs via subquery — but the subquery returned empty
 * because the source rows were already gone. Seven JEs (+ 14 child lines) were
 * stranded with no parent entity.
 *
 * TARGET ROWS (verified 2026-05-03)
 * ----------------------------------
 *   id 65 | JE-2026-00058 | payment      | SMOKE-S4-20260501232248
 *   id 66 | JE-2026-00059 | credit_note  | SMOKE-S4-20260501232248
 *   id 67 | JE-2026-00060 | credit_note  | SMOKE-S4-20260501232248
 *   id 68 | JE-2026-00061 | credit_note  | SMOKE-S4-20260501232248
 *   id 75 | JE-2026-00068 | payment      | SMOKE-S4-20260501232507
 *   id 76 | JE-2026-00069 | credit_note  | SMOKE-S4-20260501232507
 *   id 77 | JE-2026-00070 | credit_note  | SMOKE-S4-20260501232507
 *
 * WHAT THIS SCRIPT DOES
 * ---------------------
 *   1. Identifies orphan JEs via description LIKE '%SMOKE-S4-%' AND
 *      entry_number LIKE 'JE-2026-%' (never matches production entries).
 *   2. Deletes child acc_journal_entry_lines rows first (FK order).
 *   3. Deletes the parent acc_journal_entries rows.
 *   4. Resyncs settings.accounting.je_next_number.2026 to
 *      MAX(remaining 2026 seq) + 1 (= 55 post-cleanup).
 *   5. Writes audit_log entries for every deleted JE + the counter resync.
 *
 * USAGE
 * -----
 *   php scripts/cleanup_orphan_test_jes_2026_05_03.php             (dry-run)
 *   php scripts/cleanup_orphan_test_jes_2026_05_03.php --dry-run   (dry-run)
 *   php scripts/cleanup_orphan_test_jes_2026_05_03.php --execute   (prompts for 'yes')
 *
 * SAFETY
 * ------
 *   - Default mode is --dry-run. No writes; exits 0.
 *   - --execute prints the full diff, then requires literal 'yes' on stdin.
 *   - Single db_transaction — full rollback on any error.
 *   - Idempotent: no orphan rows left means nothing to delete; counter update
 *     guard fires only when current value != target.
 *
 * AUTHOR:  S-FIX-2 regression cleanup
 * DATE:    2026-05-03
 */

require_once dirname(__DIR__) . '/config/app.php';

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$args   = array_slice($argv ?? [], 1);
$mode   = 'dry-run';
foreach ($args as $arg) {
    if ($arg === '--execute') {
        $mode = 'execute';
    } elseif ($arg === '--dry-run') {
        $mode = 'dry-run';
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/cleanup_orphan_test_jes_2026_05_03.php [--dry-run|--execute]\n");
        exit(2);
    }
}
$isDryRun = ($mode === 'dry-run');

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------
function out(string $line = ''): void {
    echo $line . "\n";
}

out(str_repeat('=', 78));
out('Orphan Test JE Cleanup — 2026-05-03');
out('Mode: ' . ($isDryRun ? 'DRY-RUN (no writes)' : 'EXECUTE (will write after confirmation)'));
out('Date: ' . date('Y-m-d H:i:s'));
out(str_repeat('=', 78));
out();

// ---------------------------------------------------------------------------
// PASS 1 — Identify orphan rows
// ---------------------------------------------------------------------------
$orphanJes = db_select(
    "SELECT id, entry_number, source_type, description
     FROM acc_journal_entries
     WHERE description LIKE '%SMOKE-S4-%'
       AND entry_number LIKE 'JE-2026-%'
     ORDER BY id"
);

$orphanJeIds = array_column($orphanJes, 'id');

if (empty($orphanJeIds)) {
    out('No orphan SMOKE-S4 JE rows found. Nothing to do.');
    out(str_repeat('=', 78));
    exit(0);
}

// Child lines for each orphan JE
$orphanLines = db_select(
    "SELECT id, journal_entry_id, account_id, debit, credit
     FROM acc_journal_entry_lines
     WHERE journal_entry_id IN (" . implode(',', array_fill(0, count($orphanJeIds), '?')) . ")
     ORDER BY journal_entry_id, id",
    $orphanJeIds
);

// Counter current value + post-cleanup target
$ctrRow      = db_row("SELECT value FROM settings WHERE `key` = 'accounting.je_next_number.2026'");
$ctrCurrent  = (int) ($ctrRow['value'] ?? 0);

// What MAX will be after we remove the orphan rows
$orphanIdsForSql = implode(',', $orphanJeIds);
$postCleanupMaxRow = db_row(
    "SELECT MAX(CAST(SUBSTRING(entry_number, 9) AS UNSIGNED)) AS m
     FROM acc_journal_entries
     WHERE id NOT IN ({$orphanIdsForSql})
       AND entry_number LIKE 'JE-2026-%'"
);
$postCleanupMax = (int) ($postCleanupMaxRow['m'] ?? 0);
$ctrTarget      = $postCleanupMax + 1;

// ---------------------------------------------------------------------------
// PASS 2 — Print the planned changes
// ---------------------------------------------------------------------------
out(sprintf('--- Step 1: Delete %d orphan acc_journal_entries rows ---', count($orphanJes)));
foreach ($orphanJes as $je) {
    out(sprintf('  DELETE id=%-4d | %s | %-14s | %s',
        $je['id'],
        $je['entry_number'],
        $je['source_type'],
        $je['description']
    ));
}
out();

out(sprintf('--- Step 2: Delete %d child acc_journal_entry_lines rows ---', count($orphanLines)));
// Group by JE for readability
$linesByJe = [];
foreach ($orphanLines as $l) {
    $linesByJe[$l['journal_entry_id']][] = $l;
}
foreach ($orphanJes as $je) {
    $lines = $linesByJe[$je['id']] ?? [];
    out(sprintf('  %s (%d lines):', $je['entry_number'], count($lines)));
    foreach ($lines as $l) {
        out(sprintf('    line id=%-4d | acct_id=%-3d | DR %8.2f  CR %8.2f',
            $l['id'], $l['account_id'], (float) $l['debit'], (float) $l['credit']));
    }
}
out();

out('--- Step 3: Resync accounting.je_next_number.2026 ---');
if ($ctrCurrent === $ctrTarget) {
    out(sprintf('  Already correct: %d (post-cleanup max is %d). No change needed.',
        $ctrCurrent, $postCleanupMax));
} else {
    out(sprintf('  Current counter: %d', $ctrCurrent));
    out(sprintf('  Post-cleanup MAX seq (JE-2026-*): %d', $postCleanupMax));
    out(sprintf('  Target counter: %d (MAX + 1)', $ctrTarget));
}
out();

// ---------------------------------------------------------------------------
// DRY-RUN MODE — exit here
// ---------------------------------------------------------------------------
if ($isDryRun) {
    out(str_repeat('=', 78));
    out('Dry-run complete. No changes written.');
    out('Re-run with --execute to apply (you will be prompted for confirmation first).');
    out(str_repeat('=', 78));
    exit(0);
}

// ---------------------------------------------------------------------------
// EXECUTE MODE — confirmation prompt
// ---------------------------------------------------------------------------
out(str_repeat('-', 78));
out(sprintf(
    "Will delete %d JEs (%d child lines) and resync counter %d → %d.",
    count($orphanJes), count($orphanLines), $ctrCurrent, $ctrTarget
));
out("Type 'yes' to confirm or anything else to abort.");
out(str_repeat('-', 78));
echo 'Confirmation: ';
$input   = fgets(STDIN);
$confirm = trim((string) $input);
if ($confirm !== 'yes') {
    out("Aborted (received '{$confirm}'). No changes written.");
    exit(1);
}

// ---------------------------------------------------------------------------
// PASS 3 — Apply the changes
// ---------------------------------------------------------------------------
$auditEntriesWritten = 0;

db_transaction(function () use (
    $orphanJes, $orphanJeIds, $orphanLines,
    $ctrCurrent, $ctrTarget, $postCleanupMax,
    &$auditEntriesWritten
): void {
    $now      = date('Y-m-d H:i:s');
    $userId   = null;
    $userName = 'system (cleanup_orphan_test_jes_2026_05_03.php)';

    // Step 1: Delete child lines first (FK constraint)
    if (!empty($orphanLines)) {
        $lineIds = array_column($orphanLines, 'id');
        db_execute(
            "DELETE FROM acc_journal_entry_lines WHERE id IN ("
            . implode(',', array_fill(0, count($lineIds), '?'))
            . ")",
            $lineIds
        );
    }

    // Step 2: Delete orphan JE rows + per-row audit_log
    foreach ($orphanJes as $je) {
        db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $je['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'delete',
            'module'       => 'accounting',
            'entity_type'  => 'journal_entry',
            'entity_id'    => (int) $je['id'],
            'entity_label' => $je['entry_number'],
            'notes'        => sprintf(
                'Orphan test JE cleanup (2026-05-03) — sourced from cascade-deleted payments/credit_notes during S-FIX-2 regression test (_smoke_session4_regression.php). entry_number=%s source_type=%s description="%s". Cleanup authorized 2026-05-03.',
                $je['entry_number'],
                $je['source_type'],
                $je['description']
            ),
            'old_values'   => json_encode([
                'id'           => (int) $je['id'],
                'entry_number' => $je['entry_number'],
                'source_type'  => $je['source_type'],
                'description'  => $je['description'],
            ]),
            'new_values'   => json_encode(['deleted' => true]),
            'ip_address'   => '127.0.0.1',
        ]);
        $auditEntriesWritten++;
    }

    // Step 3: Resync counter if needed
    if ($ctrCurrent !== $ctrTarget) {
        db_execute(
            "UPDATE settings SET `value` = ?, updated_at = ? WHERE `key` = 'accounting.je_next_number.2026'",
            [(string) $ctrTarget, $now]
        );

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'settings',
            'entity_type'  => 'setting',
            'entity_id'    => null,
            'entity_label' => 'accounting.je_next_number.2026',
            'notes'        => sprintf(
                'Counter resync after orphan JE cleanup (2026-05-03). Was %d (inflated by 7 deleted SMOKE-S4 test JEs). Reset to MAX(remaining JE-2026-* seq) + 1 = %d + 1 = %d.',
                $ctrCurrent,
                $postCleanupMax,
                $ctrTarget
            ),
            'old_values'   => json_encode(['value' => (string) $ctrCurrent]),
            'new_values'   => json_encode(['value' => (string) $ctrTarget]),
            'ip_address'   => '127.0.0.1',
        ]);
        $auditEntriesWritten++;
    }
});

// ---------------------------------------------------------------------------
// Post-execute verification
// ---------------------------------------------------------------------------
out();
$remainingSmoke = db_row(
    "SELECT COUNT(*) AS n FROM acc_journal_entries WHERE description LIKE '%SMOKE-S4-%' AND entry_number LIKE 'JE-2026-%'"
);
$newMaxRow = db_row(
    "SELECT MAX(CAST(SUBSTRING(entry_number, 9) AS UNSIGNED)) AS m FROM acc_journal_entries WHERE entry_number LIKE 'JE-2026-%'"
);
$newCtrRow = db_row("SELECT value FROM settings WHERE `key` = 'accounting.je_next_number.2026'");

out(str_repeat('=', 78));
out('Post-execute verification:');
out(sprintf('  Remaining SMOKE-S4 JE rows:         %d  (expect 0)', (int) ($remainingSmoke['n'] ?? 0)));
out(sprintf('  MAX JE-2026-* seq after cleanup:     %d  (expect %d)', (int) ($newMaxRow['m'] ?? 0), $postCleanupMax));
out(sprintf('  settings.je_next_number.2026 now:    %s  (expect %d)', $newCtrRow['value'] ?? 'NOT SET', $ctrTarget));
out();
out(sprintf('JEs deleted: %d  |  Child lines deleted: %d  |  Audit log entries written: %d',
    count($orphanJes), count($orphanLines), $auditEntriesWritten));
out(str_repeat('=', 78));
exit(0);
