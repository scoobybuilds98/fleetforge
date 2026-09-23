<?php
declare(strict_types=1);

/**
 * scripts/sop_known_issues_backfill.php
 *
 * One-time deploy helper for S-SOP-KNOWN-ISSUES (operator follow-up F89).
 * The fixes change how NEW activity posts; this reports the OLD data they
 * cannot correct by themselves, so the accountant can decide:
 *
 *   A. Bank accounts with an opening balance but no opening journal entry
 *      (SOP I8 — the field used to post nothing). --apply posts the missing
 *      entries (DR bank / CR 3050), ONLY for the ids passed in --banks=…,
 *      because the accountant may already have entered them by hand (the old
 *      SOP told them to).
 *   B. GST/HST periods remitted before the fix (SOP I4): their input tax
 *      credits are still in 1050 and the same amount still credits 2030.
 *      Report only — one clearing entry DR 2030 / CR 1050 for the total,
 *      unless the accountant already posted clearing entries by hand.
 *   C. Vendor credits posted DR AP / CR AP ("Auto (from AP)", SOP I3).
 *      Report only — each needs a correcting entry to the right expense.
 *   D. Revenue since go-live that landed in 4110 Other Revenue for line types
 *      the map now sends elsewhere (SOP I1). Report only — a reclass is the
 *      accountant's call (F85).
 *
 * Usage (production: run as www-data):
 *   php scripts/sop_known_issues_backfill.php                 # report only
 *   php scripts/sop_known_issues_backfill.php --apply --banks=3,5
 *
 * @session S-SOP-KNOWN-ISSUES
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\BankService;

$apply = in_array('--apply', $argv, true);
$banks = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--banks=')) {
        $banks = array_values(array_filter(array_map('intval', explode(',', substr($a, 8)))));
    }
}

echo "S-SOP-KNOWN-ISSUES backfill report" . ($apply ? ' (APPLY)' : ' (report only)') . "\n\n";

// ── A. Bank opening balances ─────────────────────────────────────
echo "A. Bank accounts with an opening balance and no opening entry\n";
$rows = db_select(
    "SELECT b.id, b.name, b.currency, b.opening_balance, b.opening_balance_date
       FROM acc_bank_accounts b
      WHERE b.opening_balance <> 0
        AND NOT EXISTS (SELECT 1 FROM acc_journal_entries j
                         WHERE j.source_type = 'bank_opening_balance' AND j.source_id = b.id
                           AND j.status = 'posted' AND j.reversed_by_id IS NULL)
      ORDER BY b.id",
    []
);
if (!$rows) echo "   none\n";
foreach ($rows as $r) {
    echo sprintf("   #%d %s — %s %s on %s\n", $r['id'], $r['name'], $r['currency'], $r['opening_balance'], $r['opening_balance_date'] ?? '(no date)');
    if ($apply && in_array((int) $r['id'], $banks, true)) {
        try {
            $je = db_transaction(fn () => BankService::syncOpeningBalanceEntry((int) $r['id'], null));
            echo "      → posted opening entry JE id {$je}\n";
        } catch (\Throwable $e) {
            echo "      → NOT posted: {$e->getMessage()}\n";
        }
    }
}
if ($rows && !$apply) {
    echo "   Ask the accountant whether each was already entered by hand. For those that were NOT:\n";
    echo "   php scripts/sop_known_issues_backfill.php --apply --banks=<ids>\n";
}

// ── B. GST/HST periods remitted before the fix ──────────────────
echo "\nB. GST/HST periods remitted without clearing their input tax credits\n";
$rows = db_select(
    "SELECT p.id, p.period_start, p.period_end, p.total_itc
       FROM acc_tax_filing_periods p
       JOIN acc_tax_remittances r ON r.filing_period_id = p.id
      WHERE p.tax_type = 'gst_hst' AND p.status = 'remitted' AND r.itc_cleared IS NULL
      ORDER BY p.period_start",
    []
);
$total = '0.00';
foreach ($rows as $r) {
    echo sprintf("   period #%d %s → %s: ITC %s\n", $r['id'], $r['period_start'], $r['period_end'], $r['total_itc']);
    $total = bcadd($total, (string) $r['total_itc'], 2);
}
echo $rows
    ? "   Total ITC still in 1050 from these periods: {$total}. Unless already cleared by hand, the accountant posts one\n   Manual journal entry: DR 2030 GST/HST Payable {$total} / CR 1050 GST Receivable {$total}.\n"
    : "   none\n";

// ── C. Vendor credits that posted AP against AP ─────────────────
echo "\nC. Vendor credits whose entry debits and credits Accounts Payable only\n";
$ap = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.ap_account_id'")['value'] ?? 0);
$rows = db_select(
    "SELECT vc.id, vc.credit_number, vc.amount, vc.credit_date
       FROM acc_vendor_credits vc
       JOIN acc_journal_entry_lines l ON l.journal_entry_id = vc.journal_entry_id
      GROUP BY vc.id, vc.credit_number, vc.amount, vc.credit_date
     HAVING COUNT(DISTINCT l.account_id) = 1 AND MIN(l.account_id) = ?",
    [$ap]
);
if (!$rows) echo "   none\n";
foreach ($rows as $r) {
    echo sprintf("   %s (%s) %s — correcting entry: DR 2010 AP %s / CR the expense the credit reverses\n", $r['credit_number'], $r['credit_date'], $r['amount'], $r['amount']);
}

// ── D. Revenue in 4110 the map now sends elsewhere ──────────────
echo "\nD. Sent-invoice revenue by line type, and the map key it posts to from now on\n";
$rows = db_select(
    "SELECT li.item_type, COUNT(*) AS n, SUM(CASE WHEN li.is_credit = 1 THEN -li.amount ELSE li.amount END) AS amount
       FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
      WHERE i.status NOT IN ('draft','void') AND i.deleted_at IS NULL
        AND li.item_type NOT IN ('gps','account_credit_applied')
      GROUP BY li.item_type ORDER BY amount DESC",
    []
);
foreach ($rows as $r) {
    $d = \FleetForge\Accounting\AutoEntryBridge::resolveRevenueAccountDetail((string) $r['item_type']);
    $now = in_array($r['item_type'], ['base_rental', 'base_rental_reconciliation_credit', 'early_return_credit'], true)
        ? 'base_rental_<unit category>' : ($d['key'] ?? '(unmapped)');
    echo sprintf("   %-34s %6d lines  %14s  now → %s\n", $r['item_type'], $r['n'], number_format((float) $r['amount'], 2), $now);
}
echo "   Past entries stay in 4110; a reclassifying journal entry is the accountant's call (F85).\n";
