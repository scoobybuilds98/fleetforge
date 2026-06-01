<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_show_panels.php
 *
 * Smoke for F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN) — the shared QuickBooks
 * Sync rich panel on FF entity show pages. Guards against a show page silently
 * dropping the panel or mis-wiring its config (a real UX-regression class).
 *
 * Checks:
 *   C1 partial exists + lints clean
 *   C2 partial whitelist covers all 6 map tables (5 panel entities + invoice ref)
 *   C3-C7 each of the 5 show pages requires the partial with the correct
 *        entity_type + map_table config
 *   C8 partial renders without fatal for a sentinel "Not Synced" config
 *
 * @session F8
 */

require_once __DIR__ . '/../api/bootstrap.php';

$pass = 0; $total = 8; $failures = [];
$root = dirname(__DIR__);

// C1 — partial exists + lints.
$partial = $root . '/includes/partials/qbo-sync-panel.php';
$lint = is_file($partial) ? trim((string) shell_exec('php -l ' . escapeshellarg($partial) . ' 2>&1')) : 'missing';
if (is_file($partial) && strpos($lint, 'No syntax errors') !== false) { echo "PASS C1 partial exists + lints\n"; $pass++; }
else { echo "FAIL C1 {$lint}\n"; $failures[] = 'C1'; }

$partialSrc = is_file($partial) ? (string) file_get_contents($partial) : '';

// C2 — whitelist covers all map tables.
$c2 = [];
foreach (['acc_qbo_bill_map','acc_qbo_payment_map','acc_qbo_bill_payment_map','acc_qbo_journal_entry_map','acc_qbo_credit_memo_map','acc_qbo_invoice_map'] as $mt) {
    if (strpos($partialSrc, "'{$mt}'") === false) $c2[] = "whitelist missing {$mt}";
}
if (empty($c2)) { echo "PASS C2 whitelist covers 6 map tables\n"; $pass++; }
else { echo "FAIL C2 " . implode('; ', $c2) . "\n"; $failures[] = 'C2'; }

// C3-C7 — each show page wires the partial with the right config.
$pages = [
    'C3' => ['app/admin/accounting/bills/show.php',            'bill',          'acc_qbo_bill_map'],
    'C4' => ['app/admin/payments/show.php',                    'payment',       'acc_qbo_payment_map'],
    'C5' => ['app/admin/accounting/ap-payments/show.php',      'bill_payment',  'acc_qbo_bill_payment_map'],
    'C6' => ['app/admin/accounting/journal-entries/show.php',  'journal_entry', 'acc_qbo_journal_entry_map'],
    'C7' => ['app/admin/credit_notes/show.php',                'credit_memo',   'acc_qbo_credit_memo_map'],
];
foreach ($pages as $label => [$rel, $etype, $mtable]) {
    $src = is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';
    $ok = strpos($src, "includes/partials/qbo-sync-panel.php") !== false
       && strpos($src, "'entity_type' => '{$etype}'") !== false
       && strpos($src, "'map_table'   => '{$mtable}'") !== false;
    if ($ok) { echo "PASS {$label} " . basename(dirname($rel)) . "/show.php wires {$etype} panel\n"; $pass++; }
    else { echo "FAIL {$label} {$rel} missing/mis-wired {$etype}/{$mtable}\n"; $failures[] = $label; }
}

// C8 — render a sentinel config without fatal (Not Synced path; ff_id unlikely to map).
$c8ok = false;
try {
    $qboPanel = ['entity_type'=>'bill','map_table'=>'acc_qbo_bill_map','qbo_id_col'=>'qbo_bill_id','ff_fk'=>'ff_bill_id','ff_id'=>999999999,'deep_link'=>'bill','retry_url'=>base_url('api/v1/quickbooks/bills/retry')];
    ob_start();
    require $partial;
    $outLen = strlen((string) ob_get_clean());
    // Connected → renders the card; disconnected → empty. Either is non-fatal.
    $c8ok = true;
} catch (\Throwable $e) {
    @ob_end_clean();
    echo "  (render error: " . $e->getMessage() . ")\n";
}
if ($c8ok) { echo "PASS C8 partial renders sentinel config without fatal\n"; $pass++; }
else { echo "FAIL C8 partial fataled on render\n"; $failures[] = 'C8'; }

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_show_panels_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass === $total ? 0 : 1);
