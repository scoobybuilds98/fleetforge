<?php
declare(strict_types=1);

/**
 * tests/_smoke_overflow_cn_lifecycle.php
 *
 * S-ORPHAN-OVERFLOW-CN — an invoice and its auto-created overflow credit note
 * live and die together.
 *
 * The mileage true-up subtracts LIVE 'mileage_overpayment' credit_notes from
 * "mileage billed to date" (idempotency across reclose/regenerate). Before
 * this session, deleting/voiding/regenerating the SOURCE invoice left its
 * overflow CN active — an orphan that poisoned every later settlement's
 * true-up (prod: deleted INV-2026-01444 left CN-CR-2026-00011 $40.01 active,
 * halving INV-2026-01760's mileage credit from $80.68 to $40.67).
 *
 * Scenarios:
 *   L1  Settlement overflow creates the CN (baseline, mirrors est-daily C1)
 *       + its issue JE posts when accounting is enabled.
 *   L2  OverflowCreditNotes::voidForInvoice voids the CN (status/remaining/
 *       voided_at), writes the audit row, and reverses the issue JE.
 *   L3  UN-POISONED REGENERATION: after the CN is voided and the settlement
 *       invoice removed, a fresh settlement re-credits the FULL overflow
 *       (the orphan no longer halves it) — the prod INV-2026-01760 failure
 *       mode, exercised end-to-end.
 *   L4  A partially-applied overflow CN is a BLOCKER: findBlockers reports
 *       it and voidForInvoice throws (operation must refuse, not orphan).
 *   L5  Idempotency: voidForInvoice on an invoice with no live overflow CNs
 *       returns [] without error.
 *
 * Run: php tests/_smoke_overflow_cn_lifecycle.php
 *
 * @session S-ORPHAN-OVERFLOW-CN
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\OverflowCreditNotes;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-ORPHAN-OVERFLOW-CN — overflow credit-note lifecycle\n" . str_repeat('=', 72) . "\n";

db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) throw new RuntimeException("missing seed (cust=$cust unit=$unit user=$user)");

    $accountingOn = (bool) db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.enabled'")['value'] ?? false;

    // Reserve invoice numbers so createFromLease never collides (est-daily pattern).
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 300)]);

    $lease = db_insert('leases', [
        'contract_number' => 'SMOKE-OCN-' . getmypid(),
        'customer_id' => $cust, 'equipment_unit_id' => $unit,
        'start_date' => '2026-05-01', 'status' => 'active',
        'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
        'currency' => 'CAD', 'billing_cycle' => 'monthly',
        'mileage_unit' => 'km', 'mileage_rate' => '0.5000', 'mileage_rate_km' => '0.5000',
        'mileage_rate_miles' => '0.8047',
        'mileage_tracking_mode' => 'manual', 'precharge_enabled' => 0,
        'estimated_mileage' => '0', 'estimated_mileage_km' => '0.000',
        'estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000',
        'km_to_miles_conversion' => '0.621371', 'miles_to_km_conversion' => '1.609344',
        'created_by' => $user, 'updated_by' => $user,
    ]);
    $gen = new InvoiceGenerator();

    // May: estimate $620 billed (31 × 40 km × $0.50).
    $gen->createFromLease([
        'lease_id' => $lease, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);

    // ── L1 — settlement overflow creates the CN (+ issue JE) ──────────────
    // Actual 1000 km → target $500 vs $620 billed → $120 credit, all overflow
    // on a carrier invoice with no other charges.
    $iv1 = $gen->createFromLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1000',
    ]);
    $cn1 = db_row(
        "SELECT id, credit_note_number, status, amount, amount_remaining FROM credit_notes
          WHERE lease_id = ? AND source_invoice_id = ? AND source = 'mileage_overpayment'",
        [$lease, $iv1['invoice_id']]
    );
    ck('L1a', $cn1 !== null && $cn1['status'] === 'active' && bccomp((string) $cn1['amount'], '120.00', 2) === 0,
        "settlement overflow → active \$" . ($cn1['amount'] ?? '?') . " CN " . ($cn1['credit_note_number'] ?? '?') . " (expect 120.00)");
    $je1 = $cn1 ? db_row(
        "SELECT id, status, is_reversal, reversed_by_id FROM acc_journal_entries
          WHERE source_type='credit_note' AND source_id=? AND is_reversal=0 ORDER BY id ASC LIMIT 1",
        [$cn1['id']]
    ) : null;
    ck('L1b', !$accountingOn || ($je1 !== null && $je1['status'] === 'posted' && $je1['reversed_by_id'] === null),
        $accountingOn
            ? "issue JE posted, unreversed (je=" . ($je1['id'] ?? 'none') . ")"
            : "accounting disabled in this DB — JE assertions skipped");

    // ── L1c — S-REFUND-ON-INVOICE display expansion ────────────────────────
    // The stored capped line reads "$0.00 credit"; the display expander must
    // restore the original amount and append a balancing account-credit row
    // naming the CN, with the signed sum still equal to the stored subtotal.
    $rawLines = db_select("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order", [(int) $iv1['invoice_id']]);
    $disp = ff_expand_capped_invoice_lines($rawLines, (int) $iv1['invoice_id']);
    $dispByType = [];
    foreach ($disp as $dl) $dispByType[$dl['item_type']] = $dl;
    $sumSigned = '0.00';
    foreach ($disp as $dl) {
        $sumSigned = !empty($dl['is_credit']) ? bcsub($sumSigned, (string) $dl['amount'], 2) : bcadd($sumSigned, (string) $dl['amount'], 2);
    }
    $storedSub = (string) db_row("SELECT subtotal FROM invoices WHERE id = ?", [(int) $iv1['invoice_id']])['subtotal'];
    ck('L1c', isset($dispByType['mileage_credit']) && bccomp((string) $dispByType['mileage_credit']['amount'], '120.00', 2) === 0
            && isset($dispByType['account_credit_issued']) && !empty($dispByType['account_credit_issued']['_synthetic'])
            && bccomp((string) $dispByType['account_credit_issued']['amount'], '120.00', 2) === 0
            && str_contains((string) $dispByType['account_credit_issued']['description'], (string) $cn1['credit_note_number'])
            && bccomp($sumSigned, $storedSub, 2) === 0,
        "display expansion: credit shows \$" . ($dispByType['mileage_credit']['amount'] ?? '?')
        . ", synthetic account-credit row \$" . ($dispByType['account_credit_issued']['amount'] ?? '?')
        . " names {$cn1['credit_note_number']}, signed sum {$sumSigned} == stored subtotal {$storedSub}");

    // ── L2 — voidForInvoice voids the CN + reverses the JE ────────────────
    $voided = OverflowCreditNotes::voidForInvoice((int) $iv1['invoice_id'], $user, 'Smoke', 'source invoice deleted (smoke)');
    $cn1b = db_row("SELECT status, amount_remaining, voided_at FROM credit_notes WHERE id = ?", [$cn1['id']]);
    ck('L2a', count($voided) === 1 && (int) $voided[0]['id'] === (int) $cn1['id']
            && $cn1b['status'] === 'void' && bccomp((string) $cn1b['amount_remaining'], '0.00', 2) === 0
            && $cn1b['voided_at'] !== null,
        "voidForInvoice → CN {$cn1['credit_note_number']} status=" . $cn1b['status'] . " remaining=\$" . $cn1b['amount_remaining']);
    $audit = db_row(
        "SELECT id FROM audit_log WHERE entity_type='credit_note' AND entity_id=? AND notes LIKE '%auto-voided%' ORDER BY id DESC LIMIT 1",
        [$cn1['id']]
    );
    ck('L2b', $audit !== null, "audit_log row written for the auto-void");
    $je1b = $accountingOn && $je1 ? db_row("SELECT reversed_by_id FROM acc_journal_entries WHERE id = ?", [$je1['id']]) : null;
    ck('L2c', !$accountingOn || ($je1b !== null && $je1b['reversed_by_id'] !== null),
        $accountingOn ? "issue JE reversed (reversed_by_id=" . ($je1b['reversed_by_id'] ?? 'NULL') . ")" : "accounting disabled — skipped");

    // ── L3 — un-poisoned regeneration (the INV-2026-01760 failure mode) ───
    // Remove the settlement invoice (mirror the delete/regenerate flows'
    // in-transaction removal), then re-settle: with the CN voided, the fresh
    // true-up must credit the FULL $120 again — not $120 − $120 = $0.
    db_update('invoices', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $iv1['invoice_id']]);
    $iv2 = $gen->createFromLease([
        'lease_id' => $lease, 'period_start' => '2026-06-02', 'period_end' => '2026-06-02',
        'billing_type' => 'mileage_only', 'invoice_type' => 'final', 'created_by' => $user,
        'cumulative_actual_km' => '1000',
    ]);
    $cn2 = db_row(
        "SELECT id, amount, status FROM credit_notes
          WHERE lease_id = ? AND source_invoice_id = ? AND source = 'mileage_overpayment'",
        [$lease, $iv2['invoice_id']]
    );
    ck('L3', $cn2 !== null && bccomp((string) $cn2['amount'], '120.00', 2) === 0,
        "regenerated settlement re-credits the FULL overflow: \$" . ($cn2['amount'] ?? 'none')
        . " (expect 120.00 — an orphaned CN would have poisoned this to \$0.00)");

    // ── L4 — an applied CN blocks the operation ────────────────────────────
    // Simulate a partial apply on the fresh CN, then assert blocker semantics.
    db_update('credit_notes', ['amount_remaining' => '50.00', 'status' => 'partially_used'], 'id = ?', [(int) $cn2['id']]);
    $blockers = OverflowCreditNotes::findBlockers((int) $iv2['invoice_id']);
    $threw = false;
    try { OverflowCreditNotes::voidForInvoice((int) $iv2['invoice_id'], $user, 'Smoke', 'should throw'); }
    catch (\RuntimeException $e) { $threw = true; }
    $cn2b = db_row("SELECT status FROM credit_notes WHERE id = ?", [(int) $cn2['id']]);
    ck('L4', count($blockers) === 1 && $threw && $cn2b['status'] === 'partially_used',
        "partially-applied CN → findBlockers=" . count($blockers) . ", voidForInvoice threw=" . ($threw ? 'yes' : 'no')
        . ", CN untouched (" . $cn2b['status'] . ")");

    // ── L5 — no live overflow CNs → no-op ──────────────────────────────────
    db_update('credit_notes', ['status' => 'void', 'voided_at' => date('Y-m-d H:i:s'), 'amount_remaining' => '0.00'], 'id = ?', [(int) $cn2['id']]);
    $noop = OverflowCreditNotes::voidForInvoice((int) $iv2['invoice_id'], $user, 'Smoke', 'no-op');
    ck('L5', $noop === [], "voidForInvoice with nothing live returns [] (idempotent)");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    ck('FATAL', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — overflow CNs live and die with their source invoice\033[0m\n";
exit(0);
