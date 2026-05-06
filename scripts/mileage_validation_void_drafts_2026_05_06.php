<?php
declare(strict_types=1);

/**
 * scripts/mileage_validation_void_drafts_2026_05_06.php
 *
 * S-MILEAGE-RATE-VALIDATION C3 — One-shot void of INV-2026-00027 +
 * INV-2026-00084. Completes deferred void work from S-MILEAGE-RATE-ZERO-FIX
 * C1's silent scope contraction; runs alongside the I4+I5 smoke gate
 * extension that surfaced the gap in pre-work.
 *
 * BACKGROUND:
 *   Both invoices are drafts on lease 21 (CN-7B5Z5B-2026, customer 3
 *   Lepore Enterprise) which is `completed` (closed 2026-04-09) with
 *   mileage_rate_km=0 AND estimated_mileage_km=0. The OLD InvoiceGenerator
 *   silently skipped the mileage block and emitted only base_rental — but
 *   period_distance_km was recorded on the invoice header (593.62 km on
 *   INV-27 from lease_close, 148.18 km on INV-84 from manual generation).
 *   I5 (D133, this session) catches this exact pattern: positive distance
 *   against zero-rate lease.
 *
 *   S-MILEAGE-RATE-ZERO-FIX C1's backfill scope was active+pending leases
 *   only (lease 21 excluded as completed). Per Avi's call: do NOT touch
 *   lease 21's rate (deferred to S-LEASE-21-CLEANUP per KNOWN ISSUES A4).
 *   The voids alone close the I5 hole without posthumous rate change on a
 *   closed lease.
 *
 * SCOPE:
 *   - Void INV-2026-00027 (draft, lease 21, total=$250.00, balance=$250.00)
 *   - Void INV-2026-00084 (draft, lease 21, total=$4840.00, balance=$4840.00)
 *
 * COUNTER SEMANTICS (Path B truth table at top of InvoiceGenerator.php):
 *   draft -> void: customers.OB unchanged (decOb=0), leases.total_invoiced
 *   -= total_amount, leases.outstanding_balance -= decOb (no-op since 0).
 *
 * SAFETY:
 *   --dry-run  print the proposed voids, write nothing (default)
 *   --execute  apply inside single db_transaction; prompts for 'yes'
 *   Idempotent: re-running on already-void state writes zero rows.
 *
 * SPEC: S-MILEAGE-RATE-VALIDATION C3, 2026-05-06
 */

require_once dirname(__DIR__) . '/config/app.php';

$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$voidNumbers = ['INV-2026-00027', 'INV-2026-00084'];
$voidReason  = 'Completing deferred void work from S-MILEAGE-RATE-ZERO-FIX C1 scope contraction. '
             . 'Lease 21 has multiple data anomalies (KNOWN ISSUES A2: INV-84 period outside lease '
             . 'span; A4: lease 21 same-day create→close suggests seed/test data) — replacement '
             . 'invoicing deferred to S-LEASE-21-CLEANUP. I5 surfaces this draft as a rate-zero '
             . 'positive-distance invoice; voiding cleans the gap without posthumous rate change '
             . 'on a closed lease.';

$candidates = db_select(
    "SELECT id, invoice_number, status, balance_due, total_amount, lease_id, customer_id, deleted_at
     FROM invoices
     WHERE invoice_number IN (?, ?)
       AND status = 'draft'
     ORDER BY id",
    $voidNumbers
);

echo str_repeat('═', 78), "\n";
echo "S-MILEAGE-RATE-VALIDATION C3 void — mode: {$mode}\n";
echo str_repeat('═', 78), "\n\n";

if (!$candidates) {
    echo "(no drafts in target list — already voided or unexpected state — re-run is a no-op)\n";
    if ($mode === 'dry-run') exit(0);
}

foreach ($candidates as $i) {
    echo sprintf(
        "  invoice id=%d  %-16s  lease=%-3d  customer=%-3d  total=%s  balance=%s%s\n",
        $i['id'], $i['invoice_number'], (int)$i['lease_id'], (int)$i['customer_id'],
        (string)$i['total_amount'], (string)$i['balance_due'],
        $i['deleted_at'] ? '  (also soft-deleted)' : ''
    );
}

if ($mode === 'dry-run') {
    echo "\nDRY-RUN — no changes written. Re-run with --execute to apply.\n";
    exit(0);
}

if (!$candidates) exit(0);

echo "\nAbout to void the invoice(s) above in a single transaction.\n";
echo "Type 'yes' (lowercase, no quotes) to proceed: ";
$line = trim((string)fgets(STDIN));
if ($line !== 'yes') {
    echo "Cancelled.\n";
    exit(1);
}

$writes = ['voids' => 0, 'audit_log_entries' => 0];

db_transaction(function () use ($candidates, $voidReason, &$writes) {
    $userId   = 1; // Avi
    $userName = 'Avi (S-MILEAGE-RATE-VALIDATION)';
    $ip       = '127.0.0.1';

    foreach ($candidates as $i) {
        $preStatus   = $i['status'];
        $totalAmount = (string) $i['total_amount'];
        $balanceDue  = (string) $i['balance_due'];

        // Path B: draft -> void => decOb = 0, total_invoiced -= total_amount
        $decOb = ($preStatus === 'draft') ? '0.00' : $balanceDue;

        db_update('invoices', [
            'status'      => 'void',
            'balance_due' => '0.00',
            'voided_date' => date('Y-m-d'),
            'void_reason' => $voidReason,
            'voided_by'   => $userId,
            'updated_by'  => $userId,
        ], 'id = ?', [$i['id']]);

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

        $writes['voids']++;
        $writes['audit_log_entries']++;
    }
});

// Verification
$check1 = db_count(
    "SELECT COUNT(*) FROM invoices WHERE invoice_number IN (?, ?) AND status = 'void'",
    $voidNumbers
);

echo "\n";
echo str_repeat('═', 78), "\n";
echo "Writes applied:\n";
foreach ($writes as $k => $v) echo sprintf("  %-22s %d\n", $k, $v);
echo sprintf("\n  voids confirmed in 'void' status: %d  (expect 2)\n", $check1);
echo "\n", ($check1 === 2 ? 'OK — both targets voided.' : 'FAIL — see counts above.'), "\n";
exit($check1 === 2 ? 0 : 3);
