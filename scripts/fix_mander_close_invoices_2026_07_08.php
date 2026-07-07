<?php
declare(strict_types=1);

/**
 * scripts/fix_mander_close_invoices_2026_07_08.php
 *
 * One-time prod remediation (operator-granted prod write, 2026-07-08 — F42
 * precedent): regenerate the two Mander Bros close-time draft invoices so
 * they carry the S-CLOSE-NO-ESTIMATE presentation (no stub-period estimate;
 * one self-explanatory true-up line). MUST run on code >= 0075a00 — the
 * pre-fix engine cannot see the $40.01 overflow credit note and would
 * double-credit it. The script refuses to run if the fix isn't present.
 *
 * Targets (drafts only, no money moved):
 *   - INV-2026-01444 (id 1426, lease 358 MTTS403): $0.00 draft with a $14.00
 *     July stub estimate + a capped $52.67 credit. Regenerates to base rental
 *     + one mileage_credit; existing CN-CR-2026-00011 ($40.01) is counted as
 *     already-credited by the fixed engine → NO duplicate credit note.
 *   - INV-2026-01454 (id 1436, lease 355 MTTS406): $152.86 draft with a
 *     $40.00 stub estimate + $28.24 adjustment. Regenerates to base rental +
 *     one $68.24 adjustment — SAME total (presentation-only change).
 *
 * Per draft: soft-delete replicating api/v1/invoices/delete.php's Path-B
 * side effects for a DRAFT (total_invoiced -= total; OB untouched;
 * last_billed_date walked back; audit row), then InvoiceGenerator::
 * createFromLease with the exact params close.php passes for the final
 * partial_end (invoice_type='final' + cumulative_actual_km).
 *
 * DRY-RUN by default — pass --apply to write. Each lease runs in one
 * transaction; a failed assertion rolls that lease back.
 *
 * Run: php scripts/fix_mander_close_invoices_2026_07_08.php [--apply]
 *
 * @session S-CLOSE-NO-ESTIMATE (prod data half)
 */

require_once dirname(__DIR__) . '/config/app.php';

$apply = in_array('--apply', $argv, true);

// ── Refuse to run on pre-fix code ───────────────────────────────────────────
$engineSrc = file_get_contents(dirname(__DIR__) . '/lib/Billing/InvoiceGenerator.php') ?: '';
if (!str_contains($engineSrc, 'suppressEstimate') || !str_contains($engineSrc, "source = 'mileage_overpayment'")) {
    fwrite(STDERR, "REFUSED: InvoiceGenerator does not carry S-CLOSE-NO-ESTIMATE (0075a00). Deploy first.\n");
    exit(1);
}

// invoice_id => [lease_id, period_start, period_end, cumulative_actual_km, expected_total|null]
$targets = [
    1426 => ['lease' => 358, 'start' => '2026-07-01', 'end' => '2026-07-02', 'cum_km' => '7133',  'label' => 'MTTS403'],
    1436 => ['lease' => 355, 'start' => '2026-07-01', 'end' => '2026-07-04', 'cum_km' => '16206', 'label' => 'MTTS406'],
];

echo ($apply ? "APPLY" : "DRY-RUN") . " — regenerate Mander close drafts (S-CLOSE-NO-ESTIMATE presentation)\n";
echo str_repeat('=', 78) . "\n";

$dumpLines = function (int $invId): void {
    foreach (db_select(
        "SELECT item_type, description, amount, is_credit FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order",
        [$invId]
    ) as $li) {
        printf("      %-26s %s%9s  %s\n", $li['item_type'], $li['is_credit'] ? '-' : ' ', $li['amount'], mb_substr((string) $li['description'], 0, 90));
    }
};

foreach ($targets as $invId => $t) {
    $inv = db_row(
        "SELECT id, invoice_number, lease_id, customer_id, status, total_amount, balance_due
           FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$invId]
    );
    echo "\n{$t['label']} — invoice id {$invId}:\n";
    if (!$inv) {
        echo "  SKIP: not found or already deleted (idempotent re-run, or already remediated).\n";
        continue;
    }
    if ($inv['status'] !== 'draft') {
        echo "  SKIP: status='{$inv['status']}' — only drafts are safe to regenerate. Leave as-is.\n";
        continue;
    }
    if ((int) $inv['lease_id'] !== $t['lease']) {
        echo "  SKIP: lease_id mismatch (" . $inv['lease_id'] . " != {$t['lease']}) — refusing.\n";
        continue;
    }

    $cnBefore = (int) db_row(
        "SELECT COUNT(*) c FROM credit_notes WHERE lease_id = ? AND deleted_at IS NULL AND voided_at IS NULL",
        [$t['lease']]
    )['c'];
    printf("  current: %s  status=%s  total=%s  (lease CNs: %d)\n",
        $inv['invoice_number'], $inv['status'], $inv['total_amount'], $cnBefore);
    $dumpLines($invId);

    if (!$apply) {
        echo "  would: soft-delete draft, regenerate partial_end {$t['start']}..{$t['end']} (final, cum_actual={$t['cum_km']} km)\n";
        continue;
    }

    db_transaction(function () use ($inv, $invId, $t, $cnBefore, $dumpLines) {
        // 1. Soft-delete, replicating delete.php's DRAFT branch verbatim:
        //    total_invoiced -= total_amount; OB delta 0; revenue delta 0 (draft
        //    revenue is booked at send, not create); walk last_billed_date back.
        db_update('invoices', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$invId]);
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ?, updated_at = NOW() WHERE id = ?",
            [(string) $inv['total_amount'], $t['lease']]
        );
        $cov = db_row(
            "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
               FROM invoices i2
              WHERE i2.lease_id = ? AND i2.deleted_at IS NULL AND i2.status <> 'void'
                AND i2.billing_period_end IS NOT NULL
              ORDER BY i2.billing_period_end DESC, i2.id DESC LIMIT 1",
            [$t['lease']]
        );
        db_execute(
            "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
            [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $t['lease']]
        );
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'delete',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $invId,
            'entity_label' => $inv['invoice_number'],
            'notes'        => "Invoice {$inv['invoice_number']} soft-deleted (was draft) for S-CLOSE-NO-ESTIMATE regeneration (fix_mander_close_invoices_2026_07_08). Counter delta: total_invoiced -= {$inv['total_amount']}, outstanding_balance -= 0.00 (Path B).",
            'old_values'   => json_encode(['status' => 'draft', 'balance_due' => (string) $inv['balance_due']]),
            'new_values'   => json_encode(['deleted_at' => date('Y-m-d H:i:s')]),
            'ip_address'   => '127.0.0.1',
        ]);

        // 2. Regenerate with the exact close.php final-invoice params.
        $gen = new \FleetForge\Billing\InvoiceGenerator();
        $new = $gen->createFromLease([
            'lease_id'             => $t['lease'],
            'period_start'         => $t['start'],
            'period_end'           => $t['end'],
            'billing_type'         => 'partial_end',
            'invoice_type'         => 'final',
            'notes'                => "Regenerated from {$inv['invoice_number']} (S-CLOSE-NO-ESTIMATE presentation fix).",
            'created_by'           => null,
            'auto_generated'       => 1,
            'generation_source'    => 'lease_close',
            'cumulative_actual_km' => $t['cum_km'],
        ]);
        $newId  = (int) $new['invoice_id'];
        $newRow = db_row("SELECT invoice_number, subtotal, total_amount FROM invoices WHERE id = ?", [$newId]);

        // 3. Assertions — throw (→ rollback) on any surprise.
        $est = db_row("SELECT id FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_estimate'", [$newId]);
        if ($est !== null) {
            throw new RuntimeException("assertion failed: regenerated invoice {$newRow['invoice_number']} carries a mileage_estimate line");
        }
        $cnAfter = (int) db_row(
            "SELECT COUNT(*) c FROM credit_notes WHERE lease_id = ? AND deleted_at IS NULL AND voided_at IS NULL",
            [$t['lease']]
        )['c'];
        if ($cnAfter !== $cnBefore) {
            throw new RuntimeException("assertion failed: credit_note count changed {$cnBefore} -> {$cnAfter} (double-credit guard)");
        }

        printf("  APPLIED: %s -> %s  total=%s  (lease CNs: %d, unchanged)\n",
            $inv['invoice_number'], $newRow['invoice_number'], $newRow['total_amount'], $cnAfter);
        $dumpLines($newId);
    });
}

if ($apply && function_exists('invalidate_dashboard_cache')) {
    invalidate_dashboard_cache();
}

echo "\n" . str_repeat('=', 78) . "\n";
echo $apply ? "DONE.\n" : "DRY-RUN complete — re-run with --apply.\n";
exit(0);
