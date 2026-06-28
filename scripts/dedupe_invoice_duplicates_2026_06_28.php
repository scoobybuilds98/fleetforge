<?php
declare(strict_types=1);

/**
 * scripts/dedupe_invoice_duplicates_2026_06_28.php
 *
 * ONE-OFF REMEDIATION (F43) — remove the DUPLICATE fold-appended closeout/mileage
 * lines that a reopen→reclose left on a DRAFT invoice, then recompute totals.
 *
 * Root cause + code fix: S-CLOSE-RECLOSE-IDEMPOTENT (the close fold now
 * delete-and-replaces instead of appending). This script cleans the ONE invoice
 * that was already corrupted before that fix deployed.
 *
 * Target (prod, verified read-only 2026-06-28):
 *   inv 639  INV-2026-00657  lease 321  MTTS206  — fuel $1787.50 ×2 + mileage $5.76 ×2
 *
 * METHOD: among the FOLD-OWNED line types (mileage / sweep / wash / fuel), any
 * exact-duplicate group (same item_type + description + amount + is_credit, >1 row)
 * is collapsed to a single row (keep the lowest id, delete the rest). Engine lines
 * (base_rental, gps, hourly_usage, mileage_usage, etc.) are NEVER touched. Then
 * InvoiceRecalc::recalc rebuilds subtotal/tax/total authoritatively.
 *
 * SAFETY: DRY-RUN by default (--apply to write). Strict preconditions: exact
 * (id, invoice_number, lease_id, contract) tuple + status='draft'. Idempotent
 * (re-run after success finds no duplicate groups → no-op). One transaction.
 *
 * Run:  php scripts/dedupe_invoice_duplicates_2026_06_28.php            (dry-run)
 *       php scripts/dedupe_invoice_duplicates_2026_06_28.php --apply    (write)
 *
 * @session S-CLOSE-RECLOSE-IDEMPOTENT (F43 data half)
 */

$appRoot = is_file(dirname(__DIR__) . '/config/app.php')
    ? dirname(__DIR__)
    : (getenv('FF_APP_ROOT') ?: '/var/www/fleetforge');
require_once $appRoot . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceRecalc;

$APPLY = in_array('--apply', $argv, true);

// Exact production target(s). Each fully asserted before any write.
$TARGETS = [
    ['inv_id' => 639, 'inv_no' => 'INV-2026-00657', 'lease_id' => 321, 'contract' => 'MTTS206'],
];
// Only these (fold-owned) types are eligible for dedup — engine lines are off-limits.
$FOLD_TYPES = ['mileage', 'sweep', 'wash', 'fuel'];

$actor = db_row(
    "SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
);
$actorId   = (int) ($actor['id'] ?? 0);
$actorName = (string) ($actor['name'] ?? 'system');
if (!$actorId) { fwrite(STDERR, "FATAL: no super_admin user found.\n"); exit(2); }

echo str_repeat('=', 78) . "\n";
echo "DEDUPE DUPLICATE CLOSEOUT/MILEAGE LINES — " . ($APPLY ? "\033[31mAPPLY (writing)\033[0m" : "DRY-RUN (no writes)") . "\n";
echo "Actor: {$actorName} (#{$actorId})\n" . str_repeat('=', 78) . "\n\n";

$done = 0; $skipped = 0; $errors = 0;

foreach ($TARGETS as $t) {
    $invId = $t['inv_id'];
    echo "── {$t['inv_no']} (inv #{$invId}, lease {$t['contract']}) ───────────────────\n";
    try {
        $inv   = db_row("SELECT id, invoice_number, lease_id, status, total_amount FROM invoices WHERE id=? AND deleted_at IS NULL", [$invId]);
        $lease = db_row("SELECT id, contract_number FROM leases WHERE id=? AND deleted_at IS NULL", [$t['lease_id']]);

        $why = null;
        if (!$inv)                                           $why = "invoice not found";
        elseif ($inv['invoice_number'] !== $t['inv_no'])     $why = "invoice_number mismatch ({$inv['invoice_number']})";
        elseif ((int) $inv['lease_id'] !== $t['lease_id'])   $why = "lease_id mismatch ({$inv['lease_id']})";
        elseif ($inv['status'] !== 'draft')                  $why = "invoice is '{$inv['status']}' not draft";
        elseif (!$lease || $lease['contract_number'] !== $t['contract']) $why = "lease/contract mismatch";
        if ($why) { echo "  SKIP — {$why}\n\n"; $skipped++; continue; }

        // Identify exact-duplicate groups among fold-owned types.
        $ph = implode(',', array_fill(0, count($FOLD_TYPES), '?'));
        $rows = db_select(
            "SELECT id, item_type, description, amount, is_credit
               FROM invoice_line_items
              WHERE invoice_id = ? AND item_type IN ({$ph})
              ORDER BY id",
            array_merge([$invId], $FOLD_TYPES)
        );
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['item_type'] . '|' . $r['description'] . '|' . $r['amount'] . '|' . (int) $r['is_credit'];
            $groups[$key][] = (int) $r['id'];
        }
        $toDelete = [];
        foreach ($groups as $key => $ids) {
            if (count($ids) > 1) {
                sort($ids);
                $keep = array_shift($ids);          // keep lowest id
                foreach ($ids as $dupId) $toDelete[] = $dupId;
                echo "  dup group [{$key}] ×" . (count($ids) + 1) . " → keep #{$keep}, delete #" . implode(',#', $ids) . "\n";
            }
        }
        if (!$toDelete) { echo "  SKIP — no duplicate fold-owned lines (already clean).\n\n"; $skipped++; continue; }

        echo "  current total: \${$inv['total_amount']}\n";

        if (!$APPLY) {
            db_execute("BEGIN");
            foreach ($toDelete as $d) db_execute("DELETE FROM invoice_line_items WHERE id=?", [$d]);
            $proj = InvoiceRecalc::recalc($invId);
            db_execute("ROLLBACK");
            echo "  → would delete " . count($toDelete) . " line(s); projected total: \${$proj['total_amount']}  (subtotal \${$proj['subtotal']}, tax \${$proj['tax_total']})\n";
            echo "  (dry-run — nothing written)\n\n";
            $done++;
            continue;
        }

        $result = db_transaction(function () use ($invId, $inv, $toDelete, $actorId, $actorName) {
            foreach ($toDelete as $d) db_execute("DELETE FROM invoice_line_items WHERE id=?", [$d]);
            db_update('invoices', ['updated_by' => $actorId], 'id = ?', [$invId]);
            $totals = InvoiceRecalc::recalc($invId);
            db_insert('audit_log', [
                'user_id' => $actorId, 'user_name' => $actorName, 'action' => 'update',
                'module' => 'invoices', 'entity_type' => 'invoice', 'entity_id' => $invId,
                'entity_label' => $inv['invoice_number'],
                'notes' => "F43 remediation: removed " . count($toDelete) . " duplicate closeout/mileage line(s) left by a reopen→reclose double-append (S-CLOSE-RECLOSE-IDEMPOTENT). Total {$inv['total_amount']} → {$totals['total_amount']}.",
                'old_values' => json_encode(['total_amount' => $inv['total_amount']]),
                'new_values' => json_encode(['total_amount' => $totals['total_amount']]),
                'ip_address' => '127.0.0.1',
            ]);
            return $totals;
        });
        echo "  \033[32m✓ APPLIED\033[0m — deleted " . count($toDelete) . " line(s); new total: \${$result['total_amount']}  (subtotal \${$result['subtotal']}, tax \${$result['tax_total']})\n\n";
        $done++;
    } catch (\Throwable $e) {
        echo "  \033[31mERROR\033[0m — {$e->getMessage()}\n\n";
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";
printf("%s — processed %d, skipped %d, errors %d\n", $APPLY ? 'APPLY COMPLETE' : 'DRY-RUN COMPLETE', $done, $skipped, $errors);
if (!$APPLY) echo "Re-run with --apply to write.\n";
exit($errors > 0 ? 1 : 0);
