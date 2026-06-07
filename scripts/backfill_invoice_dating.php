<?php
declare(strict_types=1);

/**
 * scripts/backfill_invoice_dating.php
 *
 * S-INVOICE-DATING-FIX backfill. Pre-fix, every generated invoice was stamped
 * invoice_date = generation day and due_date = generation day + net terms,
 * regardless of its billing period. This rewrites issue/due to derive from each
 * invoice's OWN billing period:
 *
 *   invoice_date = billing_period_start
 *   due_date     = billing_period_start + settings(invoice.due_days_default)
 *
 * SCOPE (safe): only DRAFT, non-deleted invoices with a billing_period_start —
 * SENT / paid / void invoices are immutable (D12/D14) and their GL JEs already
 * posted, so they are NEVER touched. Drafts have no posted JE yet, so the GL
 * date follows automatically (D-GL-REVREC-1) when they are sent. Idempotent:
 * re-running changes nothing once dates already match.
 *
 * Usage:
 *   php scripts/backfill_invoice_dating.php            # DRY RUN (default) — prints plan
 *   php scripts/backfill_invoice_dating.php --apply    # apply the updates
 *
 * @session S-INVOICE-DATING-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

$apply  = in_array('--apply', $argv, true);
$dueDays = (int) (settings_get('invoice.due_days_default', '30') ?? 30);

echo "S-INVOICE-DATING-FIX backfill — " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
echo "APP_ENV=" . APP_ENV . "  net terms=+{$dueDays}d\n";
echo str_repeat('-', 78) . "\n";

$rows = db_select(
    "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
            invoice_date, due_date
       FROM invoices
      WHERE deleted_at IS NULL
        AND status = 'draft'
        AND billing_period_start IS NOT NULL
        AND invoice_date <> billing_period_start
      ORDER BY id"
);

$changed = 0;
foreach ($rows as $r) {
    $newIssue = (string) $r['billing_period_start'];
    $newDue   = (new DateTimeImmutable($newIssue))->modify("+{$dueDays} days")->format('Y-m-d');

    printf(
        "%-16s [%s..%s]  issue %s -> %s   due %s -> %s\n",
        $r['invoice_number'],
        $r['billing_period_start'], $r['billing_period_end'],
        $r['invoice_date'], $newIssue,
        $r['due_date'] ?? '—', $newDue
    );

    if ($apply) {
        db_execute(
            "UPDATE invoices SET invoice_date = ?, due_date = ?, updated_at = NOW() WHERE id = ?",
            [$newIssue, $newDue, (int) $r['id']]
        );
    }
    $changed++;
}

echo str_repeat('-', 78) . "\n";
echo ($apply ? "Updated" : "Would update") . " {$changed} draft invoice(s).\n";
if (!$apply && $changed > 0) {
    echo "Re-run with --apply to write the changes.\n";
}
