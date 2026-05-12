<?php
declare(strict_types=1);

/**
 * scripts/model_c_retirement_2026_05_12.php
 *
 * S-MILEAGE-2B C4 — Model C plumbing retirement precondition script.
 *
 * Runs BEFORE the DROP COLUMN migration (202605120907_S-MILEAGE-2B_model_c_retirement.sql):
 *
 *   (1) CREATE TABLE IF NOT EXISTS invoices_model_c_backup_S_MILEAGE_2B
 *       (DDL — auto-commits; idempotent via IF NOT EXISTS).
 *
 *   (2) INSERT INTO backup the snapshot rows with non-null Model C
 *       columns (per D107 capture-all discipline; idempotent via empty
 *       check + columns-still-exist guard).
 *
 *   (3) VOID INV-2026-00087 (draft, mrs=pending, $1280.71 excess_charge_amount
 *       under Model C). Path B: customer.outstanding_balance unaffected
 *       (draft never counted per D45). Lease 2 (CN-441250-2026)
 *       total_invoiced -= total_amount.
 *
 *   (4) REGEN a fresh invoice for lease 2 over the same billing period
 *       under the post-C3 Model B Lite engine. Expected output:
 *         - mileage_usage line at 7614.02 km × $0.18 = $1370.52
 *         - HST 13% on the taxable subtotal (matches BC HST per lease 2's
 *           customer 1 province)
 *       Per operator confirmation 2026-05-12: regen amount delta
 *         $1280.71 (broken Model C draft) → ~$1370.52 (correct Model B Lite
 *         under D135). Customer never saw the original draft (status was
 *         draft, not sent), so no D14 financial-immutability conflict.
 *
 * Idempotent: re-running on already-voided + regenerated state writes 0
 * rows; the backup INSERT checks for existing rows before re-snapshotting.
 *
 * USAGE:
 *   php scripts/model_c_retirement_2026_05_12.php --dry-run   (default)
 *   php scripts/model_c_retirement_2026_05_12.php --execute   (prompts 'yes')
 *
 * @session   S-MILEAGE-2B C4 (2026-05-12)
 * @decision  D-G (i) wholesale DROP + backup
 * @pattern   D107 backup-table-before-DROP; D45 Path B counters
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';

$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(2); }
}

echo "S-MILEAGE-2B C4 — Model C plumbing retirement precondition\n";
echo str_repeat('═', 78) . "\n";
echo "Mode: {$mode}\n\n";

// ── Step 1: CREATE TABLE IF NOT EXISTS (DDL, idempotent, runs in
// both dry-run + execute since CREATE TABLE IF NOT EXISTS is safe
// to run repeatedly — Step 2's SELECT needs the table to exist) ──
echo "[1/4] Creating backup table invoices_model_c_backup_S_MILEAGE_2B if missing...\n";
db_execute("
    CREATE TABLE IF NOT EXISTS invoices_model_c_backup_S_MILEAGE_2B (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT UNSIGNED NOT NULL,
        invoice_number VARCHAR(50) NOT NULL,
        invoice_status ENUM('draft','sent','partially_paid','paid','overdue','void','written_off') NOT NULL,
        excess_distance_km DECIMAL(10,2) DEFAULT NULL,
        excess_charge_amount DECIMAL(12,2) DEFAULT NULL,
        mileage_review_status ENUM('not_required','pending','approved','overridden') DEFAULT NULL,
        mileage_override_amount DECIMAL(12,2) DEFAULT NULL,
        mileage_reviewed_at DATETIME DEFAULT NULL,
        mileage_reviewed_by_user_id INT UNSIGNED DEFAULT NULL,
        mileage_review_notes TEXT DEFAULT NULL,
        snapshot_taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_invoice_id (invoice_id),
        KEY idx_invoice_number (invoice_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='S-MILEAGE-2B C4: forensic snapshot of Model C columns on invoices before DROP. See D107 pattern.'
", []);
echo "  ✓ backup table ready\n\n";

// ── Step 2: INSERT snapshot rows (idempotent — empty-check) ───────
echo "[2/4] Snapshotting rows with non-null Model C columns...\n";
$existing = db_row("SELECT COUNT(*) AS c FROM invoices_model_c_backup_S_MILEAGE_2B", []);
$existingCount = (int) ($existing['c'] ?? 0);
echo "  existing backup rows: {$existingCount}\n";

$candidates = db_select("
    SELECT id, invoice_number, status, excess_distance_km, excess_charge_amount,
           mileage_review_status, mileage_override_amount, mileage_reviewed_at,
           mileage_reviewed_by_user_id, mileage_review_notes
      FROM invoices
     WHERE deleted_at IS NULL
       AND (
            excess_distance_km IS NOT NULL
         OR excess_charge_amount IS NOT NULL
         OR (mileage_review_status IS NOT NULL AND mileage_review_status NOT IN ('', 'not_required'))
         OR mileage_override_amount IS NOT NULL
         OR mileage_reviewed_at IS NOT NULL
         OR mileage_reviewed_by_user_id IS NOT NULL
         OR mileage_review_notes IS NOT NULL
       )
     ORDER BY id
", []);
echo "  candidate rows: " . count($candidates) . "\n";
foreach ($candidates as $c) {
    echo sprintf("    - id=%d %s status=%s edk=%s eca=%s mrs=%s\n",
        $c['id'], $c['invoice_number'], $c['status'],
        $c['excess_distance_km'] ?? 'NULL',
        $c['excess_charge_amount'] ?? 'NULL',
        $c['mileage_review_status'] ?? 'NULL');
}

if ($existingCount === 0 && count($candidates) > 0) {
    if ($mode === 'execute') {
        foreach ($candidates as $c) {
            db_insert('invoices_model_c_backup_S_MILEAGE_2B', [
                'invoice_id'                  => $c['id'],
                'invoice_number'              => $c['invoice_number'],
                'invoice_status'              => $c['status'],
                'excess_distance_km'          => $c['excess_distance_km'],
                'excess_charge_amount'        => $c['excess_charge_amount'],
                'mileage_review_status'       => $c['mileage_review_status'],
                'mileage_override_amount'     => $c['mileage_override_amount'],
                'mileage_reviewed_at'         => $c['mileage_reviewed_at'],
                'mileage_reviewed_by_user_id' => $c['mileage_reviewed_by_user_id'],
                'mileage_review_notes'        => $c['mileage_review_notes'],
            ]);
        }
        echo "  ✓ snapshotted " . count($candidates) . " rows\n";
    } else {
        echo "  [dry-run] would snapshot " . count($candidates) . " rows\n";
    }
} else {
    echo "  ✓ skipped (already snapshotted)\n";
}
echo "\n";

// ── Step 3: VOID INV-87 (idempotent — only voids if still in draft+pending) ──
echo "[3/4] Voiding INV-2026-00087 (Model C pending draft)...\n";
$inv87 = db_row("SELECT id, invoice_number, status, mileage_review_status, lease_id, customer_id, total_amount, billing_period_start, billing_period_end, billing_type, invoice_type, period_distance_km, odometer_at_period_start_km, odometer_at_period_end_km, odometer_source, odometer_fetched_at, po_number, notes, internal_notes, auto_generated, generation_source, created_by FROM invoices WHERE invoice_number='INV-2026-00087'");
if (!$inv87) {
    echo "  ✗ INV-2026-00087 not found — bailing.\n";
    exit(2);
}
echo "  current status: {$inv87['status']}, mrs: " . ($inv87['mileage_review_status'] ?? 'NULL') . ", lease_id: {$inv87['lease_id']}\n";

$needsVoid = ($inv87['status'] === 'draft' && $inv87['mileage_review_status'] === 'pending');
if ($needsVoid) {
    if ($mode === 'execute') {
        db_transaction(function () use ($inv87) {
            db_execute("UPDATE invoices SET status='void', notes=CONCAT(COALESCE(notes,''), CHAR(10), '[VOIDED 2026-05-12 by S-MILEAGE-2B C4 model_c_retirement script. Original Model C draft with mrs=pending + excess_charge_amount=\$1280.71 — regenerated under Model B Lite per D-G + D135.]'), updated_at=NOW() WHERE id=?", [$inv87['id']]);
            db_insert('audit_log', [
                'user_id' => $inv87['created_by'],
                'user_name' => 'system',
                'action' => 'invoice_voided',
                'module' => 'invoices',
                'entity_type' => 'invoice',
                'entity_id' => $inv87['id'],
                'entity_label' => $inv87['invoice_number'],
                'notes' => 'S-MILEAGE-2B C4: voided Model C pending-review draft as precondition to DROP COLUMN migration. Regen scheduled in Step 4.',
                'old_values' => json_encode(['status' => 'draft', 'mileage_review_status' => 'pending']),
                'new_values' => json_encode(['status' => 'void']),
                'ip_address' => '127.0.0.1',
            ]);
            // D45 Path B: draft → void does NOT touch customer.outstanding_balance
            // (drafts never counted). Lease total_invoiced was incremented at
            // draft creation (Path A); reverse it here.
            $row = db_row("SELECT total_amount FROM invoices WHERE id=?", [$inv87['id']]);
            db_execute("UPDATE leases SET total_invoiced = GREATEST(0, total_invoiced - ?), updated_at=NOW() WHERE id=?", [$row['total_amount'], $inv87['lease_id']]);
        });
        echo "  ✓ INV-2026-00087 voided\n";
    } else {
        echo "  [dry-run] would void INV-2026-00087\n";
    }
} else {
    echo "  ✓ skipped (already voided or different status)\n";
}
echo "\n";

// ── Step 4: REGEN under Model B Lite via InvoiceGenerator ─────────
echo "[4/4] Regenerating Model B Lite invoice for lease " . $inv87['lease_id'] . "...\n";
// Look for an existing post-void regen on the same lease + period to
// prevent duplicates on re-run.
$existingRegen = db_row("
    SELECT id, invoice_number FROM invoices
     WHERE lease_id = ?
       AND billing_period_start = ?
       AND billing_period_end = ?
       AND status = 'draft'
       AND deleted_at IS NULL
       AND id != ?
", [$inv87['lease_id'], $inv87['billing_period_start'], $inv87['billing_period_end'], $inv87['id']]);

if ($existingRegen) {
    echo "  ✓ regen invoice already exists: {$existingRegen['invoice_number']} (id={$existingRegen['id']}); skipping\n";
} elseif ($mode === 'execute') {
    $gen = new \FleetForge\Billing\InvoiceGenerator();
    try {
        $result = $gen->createFromLease([
            'lease_id'                    => $inv87['lease_id'],
            'period_start'                => $inv87['billing_period_start'],
            'period_end'                  => $inv87['billing_period_end'],
            'billing_type'                => $inv87['billing_type'],
            'invoice_type'                => $inv87['invoice_type'],
            'odometer_at_period_start_km' => $inv87['odometer_at_period_start_km'],
            'odometer_at_period_end_km'   => $inv87['odometer_at_period_end_km'],
            'odometer_source'             => $inv87['odometer_source'],
            'odometer_fetched_at'         => $inv87['odometer_fetched_at'],
            'po_number'                   => $inv87['po_number'],
            'notes'                       => '[REGEN 2026-05-12 — replaces voided INV-2026-00087 under S-MILEAGE-2B C4 / D135 Model B Lite.]',
            'auto_generated'              => $inv87['auto_generated'] ?? 0,
            'generation_source'           => $inv87['generation_source'] ?? 'manual',
            'created_by'                  => $inv87['created_by'],
        ]);
        echo "  ✓ regen invoice created: id={$result['invoice_id']} number={$result['invoice_number']}\n";
        $usageLine = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$result['invoice_id']]);
        if ($usageLine) {
            echo "  mileage_usage line amount: \${$usageLine['amount']} (expected ~\$1370.52 per 7614.02 km × \$0.18)\n";
        }
    } catch (\Throwable $e) {
        echo "  ✗ regen failed: " . $e->getMessage() . "\n";
        echo "    " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(2);
    }
} else {
    echo "  [dry-run] would regen invoice for lease " . $inv87['lease_id'] . " over " . $inv87['billing_period_start'] . " to " . $inv87['billing_period_end'] . "\n";
}
echo "\n";

// ── Final state report ────────────────────────────────────────────
echo "Final state:\n";
$pending = db_row("SELECT COUNT(*) AS c FROM invoices WHERE mileage_review_status='pending' AND deleted_at IS NULL", []);
echo "  invoices with mrs='pending': " . (int) $pending['c'] . " (expect 0 post-execute)\n";
$backupCount = db_row("SELECT COUNT(*) AS c FROM invoices_model_c_backup_S_MILEAGE_2B", []);
echo "  backup rows: " . (int) $backupCount['c'] . "\n";

echo "\nDone. Run the DROP COLUMN migration next:\n";
echo "  php bin/migrate.php --apply\n";
echo "(202605120907_S-MILEAGE-2B_model_c_retirement.sql)\n";
