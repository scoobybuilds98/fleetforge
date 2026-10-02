<?php
declare(strict_types=1);

/**
 * scripts/repoint_stale_billing_anchor_2026_10_02.php
 *
 * S-CLOSE-ANCHOR-WALKBACK data repair. Before the fix, lease close voided month-end
 * drafts through adv_void_invoice() without walking leases.last_billed_date /
 * last_billed_invoice_id back, so completed leases kept pointing at a VOIDED invoice
 * (prod 2026-10-02: 151 leases; customer-portal "Billed through" + AI tools showed it).
 * This re-points them to the same walk-back value void.php / bulk_delete compute:
 * the latest live (deleted_at IS NULL, status <> 'void') invoice with a
 * billing_period_end. Display-only column — no money moves. Ran on Mainland prod
 * 2026-10-02 at the owner's request (16 + 135 leases, 0 left).
 *
 * Usage (prod: prefix with `sudo -u www-data`):
 *   php scripts/repoint_stale_billing_anchor_2026_10_02.php list
 *       read-only: every non-deleted lease whose anchor differs from the walk-back
 *       (any shape: void / deleted / dangling pointer, or a stale date)
 *   php scripts/repoint_stale_billing_anchor_2026_10_02.php dry|apply <lease ids, comma-separated>
 *       dry = full run inside one transaction, then ROLLBACK; apply = commit.
 *       Gated: each lease must be completed and anchored on a VOID invoice whose
 *       walk-back target is earlier — anything else aborts the whole run untouched.
 * Back up the lease rows first (row-only mysqldump: --no-create-info --skip-add-drop-table).
 */

require_once dirname(__DIR__) . '/config/app.php';

$mode = $argv[1] ?? '';

// ── list: the full invariant, read-only ──────────────────────────────────────
if ($mode === 'list') {
    $rows = db_select(
        "SELECT l.id, l.contract_number, l.status, l.last_billed_date, l.last_billed_invoice_id,
                c.max_end, c.inv_id
           FROM leases l
           LEFT JOIN LATERAL (
                SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
                  FROM invoices i2
                 WHERE i2.lease_id = l.id AND i2.deleted_at IS NULL AND i2.status <> 'void'
                   AND i2.billing_period_end IS NOT NULL
                 ORDER BY i2.billing_period_end DESC, i2.id DESC LIMIT 1) c ON TRUE
          WHERE l.deleted_at IS NULL
            AND (NOT (l.last_billed_date <=> c.max_end) OR NOT (l.last_billed_invoice_id <=> c.inv_id))
          ORDER BY l.id",
        []
    );
    foreach ($rows as $r) {
        printf("lease %-5d %-12s %-9s anchor %s / inv %s  ->  walk-back %s / inv %s\n", $r['id'], $r['contract_number'], $r['status'],
            $r['last_billed_date'] ?? 'NULL', $r['last_billed_invoice_id'] ?? 'NULL', $r['max_end'] ?? 'NULL', $r['inv_id'] ?? 'NULL');
    }
    echo count($rows) . " lease(s) differ from the walk-back. ids: " . implode(',', array_column($rows, 'id')) . "\n";
    exit(0);
}

$ids  = array_values(array_unique(array_filter(array_map('intval', explode(',', $argv[2] ?? '')))));
if (!in_array($mode, ['dry', 'apply'], true) || !$ids) { fwrite(STDERR, "usage: list | dry|apply <ids>\n"); exit(2); }

$WALK = "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
           FROM invoices i2
          WHERE i2.lease_id = ?
            AND i2.deleted_at IS NULL
            AND i2.status <> 'void'
            AND i2.billing_period_end IS NOT NULL
          ORDER BY i2.billing_period_end DESC, i2.id DESC
          LIMIT 1";

// ── Gates: every lease must be completed, anchored on a VOID invoice, and the
//    walk-back target must exist and be earlier than the current anchor. ───────
$pdo = db_pdo();
$pdo->beginTransaction();   // all-or-nothing; dry rolls back. Lease rows locked like InvoiceGenerator does.
$plan = []; $fail = [];
foreach ($ids as $id) {
    db_row("SELECT id FROM leases WHERE id = ? FOR UPDATE", [$id]);
    $l = db_row("SELECT l.id, l.contract_number, l.status, l.deleted_at, l.actual_return_date, l.last_billed_date, l.last_billed_invoice_id,
                        pi.status AS ptr_status, pi.invoice_number AS ptr_no
                   FROM leases l LEFT JOIN invoices pi ON pi.id = l.last_billed_invoice_id
                  WHERE l.id = ?", [$id]);
    $c = db_row($WALK, [$id]);
    if (!$l)                               { $fail[] = "$id: not found"; continue; }
    if ($l['status'] !== 'completed' || $l['deleted_at'] !== null) { $fail[] = "$id: status {$l['status']}"; continue; }
    if ($l['ptr_status'] !== 'void')       { $fail[] = "$id: anchor invoice is {$l['ptr_status']} (not void)"; continue; }
    if (!$c)                               { $fail[] = "$id: no live invoice to anchor on"; continue; }
    if (!($c['max_end'] < $l['last_billed_date'])) { $fail[] = "$id: target {$c['max_end']} not earlier than {$l['last_billed_date']}"; continue; }
    $plan[] = ['l' => $l, 'c' => $c];
}
echo "MODE=$mode  leases=" . count($ids) . "  gates: " . ($fail ? 'FAIL' : 'PASS') . "\n";
if ($fail) { $pdo->rollBack(); echo ' - ' . implode("\n - ", $fail) . "\n"; exit(1); }

try {
    foreach ($plan as $p) {
        $l = $p['l']; $c = $p['c'];
        $n = db_execute(
            "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW()
              WHERE id = ? AND status = 'completed' AND last_billed_invoice_id = ? AND last_billed_date = ?",
            [$c['max_end'], $c['inv_id'], $l['id'], $l['last_billed_invoice_id'], $l['last_billed_date']]
        );
        if ($n !== 1) throw new \RuntimeException("lease {$l['id']}: expected 1 row, got $n (changed concurrently?)");
        db_insert('audit_log', [
            'user_id'      => 1,
            'user_name'    => 'Avi',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => (int) $l['id'],
            'entity_label' => $l['contract_number'] ?: ('Lease #' . $l['id']),
            'notes'        => "Billing anchor re-pointed off voided invoice {$l['ptr_no']}: last_billed_date {$l['last_billed_date']} -> {$c['max_end']}, "
                            . "last_billed_invoice_id {$l['last_billed_invoice_id']} -> {$c['inv_id']} (same walk-back as invoice void/delete). "
                            . "[scripts/repoint_stale_billing_anchor_2026_10_02.php — S-CLOSE-ANCHOR-WALKBACK]",
            'old_values'   => json_encode(['last_billed_date' => $l['last_billed_date'], 'last_billed_invoice_id' => (int) $l['last_billed_invoice_id']]),
            'new_values'   => json_encode(['last_billed_date' => $c['max_end'], 'last_billed_invoice_id' => (int) $c['inv_id']]),
            'ip_address'   => '127.0.0.1',
        ]);
        printf("  lease %-4d ret %s  %s (%s void) -> %s (inv %d)\n", $l['id'], $l['actual_return_date'], $l['last_billed_date'], $l['ptr_no'], $c['max_end'], $c['inv_id']);
    }
    $in = implode(',', array_map(fn($p) => (int) $p['l']['id'], $plan));
    $left = db_row("SELECT COUNT(*) c FROM leases l JOIN invoices pi ON pi.id = l.last_billed_invoice_id WHERE l.id IN ($in) AND pi.status = 'void'", [])['c'];
    $mismatch = 0;
    foreach ($plan as $p) {
        $now = db_row("SELECT last_billed_date d, last_billed_invoice_id i FROM leases WHERE id = ?", [$p['l']['id']]);
        $w   = db_row($WALK, [$p['l']['id']]);
        if ($now['d'] !== $w['max_end'] || (int) $now['i'] !== (int) $w['inv_id']) $mismatch++;
    }
    echo "updated=" . count($plan) . "  still anchored on a void invoice: $left  mismatches vs walk-back: $mismatch\n";
    if ((int) $left !== 0 || $mismatch !== 0) throw new \RuntimeException('post-check failed');
    if ($mode === 'dry') { $pdo->rollBack(); echo "DRY RUN rolled back.\n"; }
    else { $pdo->commit(); echo "APPLIED.\n"; }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ABORTED, rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
