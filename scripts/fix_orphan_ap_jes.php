<?php
declare(strict_types=1);

/**
 * scripts/fix_orphan_ap_jes.php
 *
 * S-ACCT-FIX-AP Phase 2 — One-shot orphan AP-payment JE remediation.
 *
 * Reverses 6 orphan rows in acc_journal_entries where source_type='ap_payment'
 * and source_id IN (1,2,3,4,6,7). All 6 verdicts in Phase 1 diagnostic:
 * DEMO-RESIDUE — created by scripts/demo_accounting.php on 2026-04-07 at
 * 22:19:46 (single batch); demo emitted ap_payment JEs at line 451 but
 * never inserted into acc_ap_payments or acc_ap_payment_allocations,
 * leaving the AP subledger drill-down impossible for these 6 JEs.
 *
 * Reverses via FleetForge\Accounting\JournalEntryService::reverse() which
 * already writes a `status_change` audit_log row per reversal (Branch A
 * resolution from Avi's approval — no second marker row needed). After
 * each reverse() succeeds, the reversal JE description is updated to
 * append the idempotency tag [FIX-AP-source_id-N] for human findability.
 *
 * IDEMPOTENCY:
 *   Two mechanisms (D2 in approval):
 *   (a) Append [FIX-AP-source_id-N] to reversal JE description; appender
 *       checks for existing tag before writing — re-running is a no-op.
 *   (b) JournalEntryService::reverse() throws if reversed_by_id IS NOT NULL.
 *       The script catches that exception and continues to the next JE.
 *
 * USAGE:
 *   php scripts/fix_orphan_ap_jes.php --dry-run   (default — no writes)
 *   php scripts/fix_orphan_ap_jes.php --execute   (writes)
 *
 * SAFETY:
 *   - Default mode is --dry-run.
 *   - --execute wraps EACH per-JE operation in its own db_transaction
 *     (D-ACCT-A1-4: one corrective action per orphan, no batch writes).
 *     A failure on JE N does not roll back JE N-1.
 *   - Holds advisory lock `ff_fix_ap_payments` for the whole batch so
 *     two concurrent runs cannot collide (D-ACCT-A1-7).
 *   - Re-running on already-reversed JEs is a no-op + emits skip message.
 *
 * SCOPE LOCK (Avi approval 2026-05-18):
 *   Approved source_ids: 1, 2, 3, 4, 6, 7 — all DEMO-RESIDUE → reverse.
 *   No STOP-CONDITION JEs. No deferred JEs.
 *
 * AUTHOR:  S-ACCT-FIX-AP
 * DATE:    2026-05-18
 * SPEC:    docs/FLEETFORGE_ACCOUNTING_SPEC.md §20.2
 * AUDIT:   docs/FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md §4
 */

require_once realpath(__DIR__ . '/../config/app.php');

use FleetForge\Accounting\JournalEntryService;

$dryRun = !in_array('--execute', $argv, true);
$systemUserId = 1; // Avi (super_admin) — confirmed via SELECT u.id, r.slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = 1

$approvedSourceIds = [1, 2, 3, 4, 6, 7];

echo "═══ S-ACCT-FIX-AP Phase 2 — orphan ap_payment JE reversal ═══\n";
echo "Mode: " . ($dryRun ? 'DRY-RUN (no writes)' : 'EXECUTE (writes)') . "\n";
echo "Approved source_ids: " . implode(',', $approvedSourceIds) . " (all DEMO-RESIDUE → reverse)\n";
echo "System user id: {$systemUserId} (super_admin)\n\n";

// ── Acquire advisory lock for the whole batch (D-ACCT-A1-7) ──────────────────
if (!$dryRun) {
    $lockRow = db_row("SELECT GET_LOCK('ff_fix_ap_payments', 10) AS got");
    if (!$lockRow || (int) $lockRow['got'] !== 1) {
        fwrite(STDERR, "ERROR: could not acquire advisory lock ff_fix_ap_payments — another fix run may be in progress.\n");
        exit(1);
    }
}

// ── Pre-fetch the 6 target JEs ───────────────────────────────────────────────
$placeholders = implode(',', array_fill(0, count($approvedSourceIds), '?'));
$targets = db_select(
    "SELECT id, entry_number, entry_date, source_id, source_type, status,
            description, reversed_by_id, reversal_of_id, is_reversal
       FROM acc_journal_entries
      WHERE source_type = 'ap_payment'
        AND source_id IN ({$placeholders})
        AND is_reversal = 0
      ORDER BY source_id",
    $approvedSourceIds
);

echo "PRE-RUN target JE summary:\n";
foreach ($targets as $t) {
    $reversed = (int) $t['reversed_by_id'] > 0 ? ' (ALREADY REVERSED)' : '';
    printf("  source_id=%d  je_id=%-3d  %s  status=%-8s%s\n",
        (int) $t['source_id'], (int) $t['id'], $t['entry_number'], $t['status'], $reversed);
}
echo "\n";

if (count($targets) === 0) {
    echo "✓ No matching orphan JEs found — nothing to do. Exiting.\n";
    if (!$dryRun) db_execute("SELECT RELEASE_LOCK('ff_fix_ap_payments')");
    exit(0);
}

$alreadyReversedCount = 0;
foreach ($targets as $t) {
    if ((int) $t['reversed_by_id'] > 0) $alreadyReversedCount++;
}
if ($alreadyReversedCount === count($targets)) {
    echo "✓ All target JEs already reversed (idempotent re-run). Nothing to do.\n";
    if (!$dryRun) db_execute("SELECT RELEASE_LOCK('ff_fix_ap_payments')");
    exit(0);
}

if ($dryRun) {
    echo "Dry-run complete. Re-run with --execute to apply reversals.\n";
    echo "Action: " . (count($targets) - $alreadyReversedCount) . " reversal(s) will be created;\n";
    echo "        {$alreadyReversedCount} already-reversed JE(s) will be skipped.\n";
    exit(0);
}

// ── Execute reversals (one db_transaction per orphan — D-ACCT-A1-4) ──────────
$successCount = 0;
$skipCount = 0;
$failCount = 0;

foreach ($targets as $t) {
    $jeId     = (int) $t['id'];
    $sourceId = (int) $t['source_id'];
    $tag      = "[FIX-AP-source_id-{$sourceId}]";
    $jeNumber = $t['entry_number'];

    if ((int) $t['reversed_by_id'] > 0) {
        echo "  SKIP  je_id={$jeId}  {$jeNumber}  source_id={$sourceId} — already reversed (reversed_by_id={$t['reversed_by_id']}).\n";
        $skipCount++;
        continue;
    }

    try {
        $result = db_transaction(function () use ($jeId, $sourceId, $tag, $systemUserId) {
            // JournalEntryService::reverse() handles its own FOR UPDATE + idempotency
            // guards (status='posted', reversed_by_id IS NULL) and writes a
            // status_change audit_log row on the original JE.
            $reversalJe = JournalEntryService::reverse($jeId, date('Y-m-d'), $systemUserId);

            $reversalId = (int) $reversalJe['id'];

            // Idempotency tag: append to the reversal JE description if not already present.
            // varchar(500) on acc_journal_entries.description — plenty of headroom.
            $existing = db_row(
                "SELECT description FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$reversalId]
            );
            if ($existing && strpos((string) $existing['description'], $tag) === false) {
                $newDescription = trim($existing['description']) . ' ' . $tag;
                if (strlen($newDescription) > 500) {
                    $newDescription = substr($newDescription, 0, 500);
                }
                db_update(
                    'acc_journal_entries',
                    ['description' => $newDescription],
                    'id = ?',
                    [$reversalId]
                );
            }

            return [
                'reversal_id'     => $reversalId,
                'reversal_number' => $reversalJe['entry_number'],
            ];
        });

        echo "  REVERSED  je_id={$jeId}  {$jeNumber}  →  reversal_id={$result['reversal_id']}  {$result['reversal_number']}  tag={$tag}\n";
        $successCount++;

    } catch (Throwable $e) {
        fwrite(STDERR, "  FAIL  je_id={$jeId}  {$jeNumber}  source_id={$sourceId} — " . $e->getMessage() . "\n");
        $failCount++;
        // Continue to next JE per STOP CONDITION: "halt that JE, report the
        // exception, continue with remaining approved JEs only".
    }
}

// ── Release advisory lock ────────────────────────────────────────────────────
db_execute("SELECT RELEASE_LOCK('ff_fix_ap_payments')");

echo "\n";
echo "═══ SUMMARY ═══\n";
echo "  Reversed:        {$successCount}\n";
echo "  Already reversed (skipped): {$skipCount}\n";
echo "  Failed:          {$failCount}\n";

// ── Verify post-state ────────────────────────────────────────────────────────
$postCheck = db_select(
    "SELECT source_id, id, entry_number, status, reversed_by_id
       FROM acc_journal_entries
      WHERE source_type = 'ap_payment'
        AND source_id IN ({$placeholders})
        AND is_reversal = 0
      ORDER BY source_id",
    $approvedSourceIds
);

$stillPosted = 0;
foreach ($postCheck as $row) {
    if ($row['status'] === 'posted' && (int) $row['reversed_by_id'] === 0) {
        $stillPosted++;
    }
}

echo "\nPOST-RUN: ";
if ($stillPosted === 0) {
    echo "✓ All 6 originals now status='reversed' with reversed_by_id set.\n";
    exit($failCount > 0 ? 1 : 0);
} else {
    echo "⚠ {$stillPosted} original JE(s) still status='posted' — investigation needed.\n";
    exit(1);
}
