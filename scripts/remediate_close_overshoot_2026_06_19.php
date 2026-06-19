<?php
/**
 * scripts/remediate_close_overshoot_2026_06_19.php
 *
 * One-time remediation for invoices already over-billed past their lease's
 * billable extent BEFORE the S-CLOSE-OVERSHOOT fix shipped (e.g. production
 * INV-2026-00126..00133 minus 00132 — leases MTTS62..MTTS69 returned mid-month
 * but billed to month-end).
 *
 * Reuses the EXACT live-fix logic (reconcile_overshoot_invoices) per affected
 * lease, inside a per-lease transaction:
 *   - draft overshoot  → void + regenerate shortened to the lease extent
 *   - sent/paid        → prorated credit_note for the unused tail
 *
 * IDEMPOTENT: re-running finds nothing (clamped invoices end at the extent).
 *
 * SAFETY:
 *   - DRY RUN by default — prints the plan, mutates nothing.
 *   - --apply           — execute the clamp.
 *   - --user-id=<id>    — actor recorded on audit/void/credit rows (REQUIRED with --apply).
 *   - --lease=<id|num>  — restrict to one lease (repeatable, comma-separated).
 *
 * NOTE on sent/paid invoices: issuing a credit_note does NOT by itself reduce
 * customers.outstanding_balance — the operator applies the note to the invoice
 * via the Credit Notes UI / api/v1/credit_notes/apply.php. Drafts (the prod set)
 * self-correct fully with no OB side effects.
 *
 * USAGE (operator, on the target host):
 *   php scripts/remediate_close_overshoot_2026_06_19.php                 # dry run, all
 *   php scripts/remediate_close_overshoot_2026_06_19.php --apply --user-id=22
 *   php scripts/remediate_close_overshoot_2026_06_19.php --apply --user-id=22 --lease=MTTS66
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
// auth.php defines current_user()/current_user_id() the shared close helpers call.
// config/app.php (the CLI bootstrap) does NOT load it; the web path gets it via
// api/bootstrap.php. Loading it here only DEFINES functions (session start is
// lazy inside _ff_session_start()), so it is CLI-safe.
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php';

// ── Args ───────────────────────────────────────────────────────────────────
$apply   = in_array('--apply', $argv, true);
$userId  = null;
$leaseFilter = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--user-id=')) $userId = (int) substr($a, 10);
    if (str_starts_with($a, '--lease='))   $leaseFilter = array_filter(array_map('trim', explode(',', substr($a, 8))));
}

if ($apply && !$userId) {
    fwrite(STDERR, "ERROR: --apply requires --user-id=<adminUserId> for audit attribution.\n");
    exit(2);
}

// Give the shared helpers a real actor (they call current_user()/current_user_id()).
if ($apply) {
    $u = db_row("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$u) { fwrite(STDERR, "ERROR: user #{$userId} not found.\n"); exit(2); }
    $_SESSION['ff_user'] = ['id' => (int) $u['id'], 'name' => $u['name'], 'role_slug' => 'super_admin', 'permissions' => []];
    echo "Actor: #{$u['id']} {$u['name']}\n";
}

echo ($apply ? "APPLY MODE — changes WILL be committed.\n" : "DRY RUN — no changes (pass --apply to execute).\n");
echo str_repeat('=', 90) . "\n";

// ── Affected leases (same predicate as the diagnostic / live fix) ───────────
$leases = db_select(
    "SELECT id, contract_number, customer_id, equipment_unit_id,
            company_name_snapshot, customer_name_snapshot,
            start_date, start_time, actual_return_date, actual_return_time,
            billing_days_removed
       FROM leases
      WHERE status = 'completed' AND actual_return_date IS NOT NULL AND deleted_at IS NULL
      ORDER BY id",
    []
);

$touched = 0; $invoicesFixed = 0;

foreach ($leases as $lease) {
    if ($leaseFilter && !in_array((string) $lease['id'], $leaseFilter, true)
        && !in_array((string) $lease['contract_number'], $leaseFilter, true)) {
        continue;
    }

    $extent = lease_billable_extent(
        (string) $lease['actual_return_date'], $lease['actual_return_time'] ?? null,
        $lease['start_time'] ?? null, (string) $lease['start_date'],
        (int) ($lease['billing_days_removed'] ?? 0)   // S-LEASE-CLOSE-REMOVE-DAYS: stay in lockstep with the live close path
    );

    $overshoot = db_select(
        "SELECT id, invoice_number, status, billing_type, billing_period_end
           FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL
            AND status NOT IN ('void', 'written_off')
            AND (generation_source IS NULL OR generation_source <> 'advance')
            AND billing_type NOT IN ('full_month', 'mileage_only', 'adjustment', 'credit_note')
            AND billing_period_end IS NOT NULL AND billing_period_end > ?
            AND NOT EXISTS (
                SELECT 1 FROM credit_notes cn
                 WHERE cn.source_invoice_id = invoices.id
                   AND cn.source = 'invoice_adjustment'
                   AND cn.status <> 'void' AND cn.deleted_at IS NULL
            )
          ORDER BY billing_period_start ASC, id ASC",
        [$lease['id'], $extent]
    );
    if (!$overshoot) continue;

    $touched++;
    echo "\nLease {$lease['contract_number']} (#{$lease['id']}) — extent {$extent}\n";
    foreach ($overshoot as $inv) {
        echo "  • {$inv['invoice_number']} [{$inv['status']}/{$inv['billing_type']}] billed→{$inv['billing_period_end']} "
           . ($inv['status'] === 'draft' ? "→ void + regenerate to {$extent}" : "→ credit note for unused tail") . "\n";
    }

    if (!$apply) { $invoicesFixed += count($overshoot); continue; }

    db_transaction(function () use ($lease, $extent, &$invoicesFixed) {
        // FOR UPDATE lock the unit (matches the live close path's concurrency guard).
        if (!empty($lease['equipment_unit_id'])) {
            db_row("SELECT id FROM equipment_units WHERE id = ? FOR UPDATE", [$lease['equipment_unit_id']]);
        }
        $actions = reconcile_overshoot_invoices(
            (int) $lease['id'], $lease, $extent,
            "S-CLOSE-OVERSHOOT remediation 2026-06-19 — billing period shortened to lease extent {$extent}."
        );
        $invoicesFixed += count($actions);
        foreach ($actions as $a) {
            echo "    ✓ {$a['invoice_number']}: {$a['action']}"
               . (isset($a['replacement_invoice_number']) ? " → {$a['replacement_invoice_number']}" : '')
               . (isset($a['credit_note_number']) ? " → {$a['credit_note_number']} (\${$a['amount']})" : '') . "\n";
        }
    });
}

echo "\n" . str_repeat('=', 90) . "\n";
printf("%s — %d lease(s), %d invoice(s) %s.\n",
    $apply ? 'DONE' : 'DRY RUN',
    $touched, $invoicesFixed, $apply ? 'reconciled' : 'would be reconciled');
if (!$apply && $touched) echo "Re-run with --apply --user-id=<adminId> to execute.\n";
