<?php
declare(strict_types=1);

/**
 * scripts/billing_rate_fix_2026_05_06.php
 *
 * S-BILLING-RATE-FIX Commit 1 — One-shot remediation of the rate-tier hole bug.
 *
 * Closes the data side of an upstream defect that allowed leases to be created
 * with weekly_rate=0 while daily_rate and monthly_rate were populated. The
 * downstream symptom was 5 draft invoices (INV-2026-00026, 00054, 00083,
 * 00085, 00086) carrying base_rental=$0 because ProRateCalculator's 8-29 day
 * weekly path silently computed 0 from a 0 weekly rate. No invoices were
 * sent — entire blast radius is internal drafts. Code-side fixes follow in
 * Commits 2 (strict validation) and 3 (defensive ProRateCalculator).
 *
 * SCOPE (signed off as D-A through D-H, 2026-05-06):
 *
 *   D-A — Backfill equipment_templates.default_weekly_rate where NULL/0 with
 *         monthly/4.33 (calendar-accurate divisor: 52 weeks ÷ 12 months).
 *         Pre-work scan found 1 template hole: id=1 "53ft Dry Van".
 *
 *   D-B — Backfill leases.weekly_rate=0 with monthly/4.33 for active or
 *         completed leases. Skip soft-deleted leases (lease 30). Skip
 *         already-corrected leases (38, 41 — silent SQL patched at 02:07:17).
 *         Affected: lease 21 (508.08), lease 33 (230.95).
 *
 *   D-C — Void the 5 affected drafts with structured void_reason. INV-00054
 *         is also already soft-deleted; void adds the explicit billing-intent
 *         signal on top of the soft-delete flag. No regeneration — user
 *         decides per invoice. Counter movements all $0 (drafts).
 *
 *   D-H — Retroactive audit_log entries for the silent SQL patch on
 *         leases 38 AND 41 (both share updated_at='2026-05-06 02:07:17').
 *         The original patch had no audit trail — these rows close the gap.
 *
 * SAFETY:
 *   - Default mode is --dry-run. Writes nothing, exits 0.
 *   - --execute prints the proposed diff first, then prompts for the
 *     literal string 'yes'.
 *   - Single db_transaction wraps all writes — full rollback on any error.
 *   - Idempotent: re-running with --execute on already-corrected state
 *     writes zero rows (each step has a `needs work?` guard).
 *
 * USAGE:
 *   php scripts/billing_rate_fix_2026_05_06.php --dry-run   (default)
 *   php scripts/billing_rate_fix_2026_05_06.php --execute   (prompts 'yes')
 *
 * SPEC:    S-BILLING-RATE-FIX, audit dated 2026-05-06
 * AUTHOR:  S-BILLING-RATE-FIX session
 */

require_once dirname(__DIR__) . '/config/app.php';

// ────────────────────────────────────────────────────────────────────────────
// Argument parsing
// ────────────────────────────────────────────────────────────────────────────
$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/billing_rate_fix_2026_05_06.php [--dry-run|--execute]\n");
        exit(2);
    }
}

// Calendar-accurate weekly-from-monthly divisor: 52 weeks ÷ 12 months
$DIVISOR = '4.33';

// Helper — compute weekly = ROUND(monthly / 4.33, 2)
$derive_weekly = function (string $monthly) use ($DIVISOR): string {
    return bcround(bcdiv($monthly, $DIVISOR, 6), 2);
};

// ────────────────────────────────────────────────────────────────────────────
// Pre-flight read of the state we plan to change. Drives both the dry-run
// printout and the execute-time guard so we don't write something stale.
// ────────────────────────────────────────────────────────────────────────────

// D-A — equipment_templates with weekly hole + siblings populated
$templateHoles = db_select(
    "SELECT id, name, default_daily_rate, default_weekly_rate, default_monthly_rate
     FROM equipment_templates
     WHERE (default_weekly_rate IS NULL OR default_weekly_rate = 0)
       AND default_daily_rate > 0
       AND default_monthly_rate > 0
     ORDER BY id"
);

// D-B — leases with weekly hole + siblings populated, NOT soft-deleted
$leaseHoles = db_select(
    "SELECT id, contract_number, daily_rate, weekly_rate, monthly_rate, status
     FROM leases
     WHERE deleted_at IS NULL
       AND weekly_rate = 0
       AND daily_rate > 0
       AND monthly_rate > 0
     ORDER BY id"
);

// D-C — the 5 affected drafts (idempotent: only those still in draft state)
$voidTargets = db_select(
    "SELECT id, invoice_number, status, subtotal, total_amount, balance_due,
            customer_id, lease_id, deleted_at
     FROM invoices
     WHERE invoice_number IN (?, ?, ?, ?, ?)
       AND status = 'draft'
     ORDER BY id",
    ['INV-2026-00026','INV-2026-00054','INV-2026-00083','INV-2026-00085','INV-2026-00086']
);

// D-H — retroactive audit_log entries; only insert if no row already exists
// for the 02:07:17 patch on each lease. Match on a sentinel marker in notes
// so re-runs are no-ops.
$dhMarker  = 'S-BILLING-RATE-FIX D-H';
$dhNeeded  = [];
foreach ([38, 41] as $leaseId) {
    $existing = db_count(
        "SELECT COUNT(*) FROM audit_log
         WHERE entity_type = 'lease' AND entity_id = ?
           AND notes LIKE ?",
        [$leaseId, "%{$dhMarker}%"]
    );
    if ($existing === 0) {
        $lease = db_row(
            "SELECT id, contract_number, weekly_rate, monthly_rate FROM leases WHERE id = ?",
            [$leaseId]
        );
        if ($lease && bccomp((string)$lease['weekly_rate'], '0', 2) > 0) {
            $dhNeeded[] = $lease;
        }
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Plan printout — same shape for dry-run and execute confirm-prompt.
// ────────────────────────────────────────────────────────────────────────────

echo str_repeat('═', 78), "\n";
echo "S-BILLING-RATE-FIX Commit 1 — mode: {$mode}\n";
echo str_repeat('═', 78), "\n\n";

// D-A
echo "D-A — equipment_templates backfill\n";
echo str_repeat('─', 78), "\n";
if (!$templateHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($templateHoles as $t) {
        $newWeekly = $derive_weekly((string)$t['default_monthly_rate']);
        echo sprintf(
            "  template id=%d  %-20s  daily=%s  weekly=%s → %s  monthly=%s\n",
            $t['id'], $t['name'],
            (string)$t['default_daily_rate'],
            $t['default_weekly_rate'] === null ? 'NULL' : (string)$t['default_weekly_rate'],
            $newWeekly,
            (string)$t['default_monthly_rate']
        );
    }
}
echo "\n";

// D-B
echo "D-B — leases backfill\n";
echo str_repeat('─', 78), "\n";
if (!$leaseHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($leaseHoles as $l) {
        $newWeekly = $derive_weekly((string)$l['monthly_rate']);
        echo sprintf(
            "  lease id=%d  %-30s  status=%-9s  daily=%s  weekly=%s → %s  monthly=%s\n",
            $l['id'], $l['contract_number'], $l['status'],
            (string)$l['daily_rate'], (string)$l['weekly_rate'], $newWeekly,
            (string)$l['monthly_rate']
        );
    }
}
echo "\n";

// D-C
echo "D-C — invoice voids (5 drafts)\n";
echo str_repeat('─', 78), "\n";
if (!$voidTargets) {
    echo "  (no remaining drafts — already voided or unexpected state)\n";
} else {
    foreach ($voidTargets as $i) {
        echo sprintf(
            "  invoice id=%d  %-16s  lease=%-3d  total=%s  balance=%s%s\n",
            $i['id'], $i['invoice_number'], $i['lease_id'],
            (string)$i['total_amount'], (string)$i['balance_due'],
            $i['deleted_at'] ? '  (also soft-deleted)' : ''
        );
    }
}
echo "\n";

// D-H
echo "D-H — retroactive audit_log entries (silent SQL patch at 02:07:17)\n";
echo str_repeat('─', 78), "\n";
if (!$dhNeeded) {
    echo "  (no entries needed — already present or rate not 508.08)\n";
} else {
    foreach ($dhNeeded as $l) {
        echo sprintf(
            "  audit_log entry  lease id=%d  %-30s  weekly=0.00 → %s\n",
            $l['id'], $l['contract_number'], (string)$l['weekly_rate']
        );
    }
}
echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// Dry-run exit
// ────────────────────────────────────────────────────────────────────────────
if ($mode === 'dry-run') {
    echo "DRY-RUN — no changes written. Re-run with --execute to apply.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// Execute confirmation
// ────────────────────────────────────────────────────────────────────────────
echo "About to apply the changes above in a single transaction.\n";
echo "Type 'yes' (lowercase, no quotes) to proceed, anything else to cancel: ";
$line = trim((string)fgets(STDIN));
if ($line !== 'yes') {
    echo "Cancelled.\n";
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────────
// Apply
// ────────────────────────────────────────────────────────────────────────────
$writes = [
    'template_backfills' => 0,
    'lease_backfills'    => 0,
    'invoice_voids'      => 0,
    'dh_audit_entries'   => 0,
    'audit_log_entries'  => 0,
];

db_transaction(function () use (
    $templateHoles, $leaseHoles, $voidTargets, $dhNeeded,
    $derive_weekly, $dhMarker, &$writes
) {
    $now      = date('Y-m-d H:i:s');
    $userId   = 1;                 // Avi
    $userName = 'Avi (S-BILLING-RATE-FIX)';
    $ip       = '127.0.0.1';

    // ── D-A: equipment_templates ───────────────────────────────────────────
    foreach ($templateHoles as $t) {
        $oldWeekly = $t['default_weekly_rate'];     // null or '0.00'
        $newWeekly = $derive_weekly((string)$t['default_monthly_rate']);

        db_update('equipment_templates', [
            'default_weekly_rate' => $newWeekly,
            'updated_at'          => $now,
        ], 'id = ?', [$t['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'equipment_templates',
            'entity_type'  => 'equipment_template',
            'entity_id'    => $t['id'],
            'entity_label' => $t['name'],
            'notes'        => sprintf(
                'S-BILLING-RATE-FIX D-A backfill: default_weekly_rate set from monthly/%s derivation (%s/4.33=%s).',
                '4.33', (string)$t['default_monthly_rate'], $newWeekly
            ),
            'old_values'   => json_encode(['default_weekly_rate' => $oldWeekly]),
            'new_values'   => json_encode(['default_weekly_rate' => $newWeekly]),
            'ip_address'   => $ip,
        ]);

        $writes['template_backfills']++;
        $writes['audit_log_entries']++;
    }

    // ── D-B: leases ───────────────────────────────────────────────────────
    foreach ($leaseHoles as $l) {
        $newWeekly = $derive_weekly((string)$l['monthly_rate']);

        db_update('leases', [
            'weekly_rate' => $newWeekly,
            'updated_at'  => $now,
            'updated_by'  => $userId,
        ], 'id = ?', [$l['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => $l['id'],
            'entity_label' => $l['contract_number'],
            'notes'        => sprintf(
                'S-BILLING-RATE-FIX D-B backfill: weekly_rate set from monthly/4.33 derivation per equipment_templates default (%s/4.33=%s).',
                (string)$l['monthly_rate'], $newWeekly
            ),
            'old_values'   => json_encode(['weekly_rate' => (string)$l['weekly_rate']]),
            'new_values'   => json_encode(['weekly_rate' => $newWeekly]),
            'ip_address'   => $ip,
        ]);

        $writes['lease_backfills']++;
        $writes['audit_log_entries']++;
    }

    // ── D-C: invoice voids ────────────────────────────────────────────────
    $voidReason = 'S-BILLING-RATE-FIX: original generation produced $0 base_rental from upstream zero-rate bug. Lease has been corrected; user may regenerate this invoice if still needed.';

    foreach ($voidTargets as $i) {
        $preStatus   = $i['status'];
        $balanceDue  = (string) $i['balance_due'];
        $totalAmount = (string) $i['total_amount'];

        // Path B (matches api/v1/invoices/void.php): draft→void → decOb=0
        $decOb = ($preStatus === 'draft') ? '0.00' : $balanceDue;

        // Mirror the void.php update set so a regenerated invoice on the same
        // lease behaves identically afterwards.
        db_update('invoices', [
            'status'      => 'void',
            'balance_due' => '0.00',
            'voided_date' => date('Y-m-d'),
            'void_reason' => $voidReason,
            'voided_by'   => $userId,
            'updated_by'  => $userId,
        ], 'id = ?', [$i['id']]);

        // Counter reversal — for our 5 drafts, total=0 and decOb=0, so these
        // are arithmetic no-ops but kept for symmetry with void.php.
        if ($i['lease_id']) {
            db_execute(
                "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $decOb, $i['lease_id']]
            );
        }
        if ($i['customer_id']) {
            db_execute(
                "UPDATE customers SET outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
                [$decOb, $i['customer_id']]
            );
        }

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'invoice_voided',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $i['id'],
            'entity_label' => $i['invoice_number'],
            'notes'        => "Invoice {$i['invoice_number']} voided (was {$preStatus}): {$voidReason} Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
            'old_values'   => json_encode(['status' => $preStatus, 'balance_due' => $balanceDue]),
            'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
            'ip_address'   => $ip,
        ]);

        $writes['invoice_voids']++;
        $writes['audit_log_entries']++;
    }

    // ── D-H: retroactive audit_log for the silent SQL patch ──────────────
    // created_at frozen at the original patch timestamp so the audit row
    // sits where the change actually happened on the timeline.
    $patchAt = '2026-05-06 02:07:17';
    foreach ($dhNeeded as $l) {
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => 'Avi (manual TablePlus patch)',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => $l['id'],
            'entity_label' => $l['contract_number'],
            'notes'        => sprintf(
                'Manual SQL patch at 02:07:17 corrected weekly_rate from 0.00 to %s. Audit row added retroactively per %s. Original patch bypassed audit; this row preserves audit completeness.',
                (string)$l['weekly_rate'], $dhMarker
            ),
            'old_values'   => json_encode(['weekly_rate' => '0.00']),
            'new_values'   => json_encode(['weekly_rate' => (string)$l['weekly_rate']]),
            'ip_address'   => $ip,
            'created_at'   => $patchAt,
        ]);

        $writes['dh_audit_entries']++;
        $writes['audit_log_entries']++;
    }
});

// ────────────────────────────────────────────────────────────────────────────
// Verification — three independent checks per spec triple-check enforcement
// ────────────────────────────────────────────────────────────────────────────
$check1Leases = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL AND weekly_rate = 0 AND daily_rate > 0 AND monthly_rate > 0"
);
$check1Templates = db_count(
    "SELECT COUNT(*) FROM equipment_templates
     WHERE (default_weekly_rate IS NULL OR default_weekly_rate = 0)
       AND default_daily_rate > 0 AND default_monthly_rate > 0"
);
$check2Voids = db_count(
    "SELECT COUNT(*) FROM invoices
     WHERE invoice_number IN ('INV-2026-00026','INV-2026-00054','INV-2026-00083','INV-2026-00085','INV-2026-00086')
       AND status = 'void'"
);
$check3DhCount = db_count(
    "SELECT COUNT(*) FROM audit_log
     WHERE entity_type='lease' AND entity_id IN (38, 41)
       AND notes LIKE '%S-BILLING-RATE-FIX D-H%'"
);

echo "\n";
echo str_repeat('═', 78), "\n";
echo "Writes applied:\n";
foreach ($writes as $k => $v) {
    echo sprintf("  %-22s %d\n", $k, $v);
}
echo "\n";
echo "Post-write verification:\n";
echo sprintf("  active leases with rate-tier hole:    %s   (expect 0)\n", $check1Leases);
echo sprintf("  templates with rate-tier hole:        %s   (expect 0)\n", $check1Templates);
echo sprintf("  affected invoices in 'void' status:   %s   (expect 5)\n", $check2Voids);
echo sprintf("  D-H retroactive audit_log entries:    %s   (expect 2)\n", $check3DhCount);

$ok = ($check1Leases === 0) && ($check1Templates === 0)
    && ($check2Voids === 5)  && ($check3DhCount === 2);

echo "\n", ($ok ? 'OK — all post-conditions met.' : 'FAIL — see counts above.'), "\n";
exit($ok ? 0 : 3);
