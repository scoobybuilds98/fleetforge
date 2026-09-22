<?php
declare(strict_types=1);

/**
 * scripts/qbo_sandbox_verify.php
 *
 * Go-live rehearsal against an Intuit SANDBOX company (S-QBO-GOLIVE-AUDIT).
 * Exercises the paths the offline smokes cannot: real CompanyInfo /
 * Preferences, real Canadian tax codes, a real invoice push (tax + total
 * check), the pay-online link, a QuickBooks payment flowing back into FF,
 * a void flowing back, and the go-live linker's QuickBooks queries.
 *
 * Refuses to run unless quickbooks.environment='sandbox' and connected — it
 * never touches a production company.
 *
 * Usage (from the repo root, on the DEV install connected to the sandbox):
 *   php scripts/qbo_sandbox_verify.php                 # read-only readiness report
 *   php scripts/qbo_sandbox_verify.php --invoice=123   # + push FF invoice 123, check
 *                                                      #   totals + tax, fetch pay link
 *   php scripts/qbo_sandbox_verify.php --invoice=123 --pay
 *                                                      # + record a QBO payment for it,
 *                                                      #   mirror into FF, then void it
 *                                                      #   in QBO and mirror the void
 *   php scripts/qbo_sandbox_verify.php --linker        # + go-live linker match report
 *
 * Writes (only with --invoice / --pay): the sandbox company, and the DEV
 * database's map rows / FF payment rows for that invoice — the dev dataset is
 * disposable (see DEMO_SEED_MANIFEST.md). Nothing is rolled back so the
 * results can be inspected in both apps afterwards.
 *
 * Exit code 0 = every check passed; 1 = at least one ✗.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require __DIR__ . '/../api/bootstrap.php';

use FleetForge\QuickBooksClient;
use FleetForge\QboPushers\AccountValidator;
use FleetForge\QboPushers\CompanyInfoSync;
use FleetForge\QboPushers\InvoiceLinker;
use FleetForge\QboPushers\InvoicePusher;
use FleetForge\QboPushers\InvoiceTaxPerRate;
use FleetForge\QboPushers\PaymentWebhookHandler;

$opts      = getopt('', ['invoice::', 'pay', 'linker']);
$invoiceId = isset($opts['invoice']) ? (int) $opts['invoice'] : 0;
$failed    = 0;

function sv_head(string $t): void { echo "\n── {$t} " . str_repeat('─', max(0, 60 - strlen($t))) . "\n"; }
function sv_ok(bool $ok, string $msg, string $detail = ''): void
{
    global $failed;
    echo '  ' . ($ok ? '✓' : '✗') . ' ' . $msg . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    if (!$ok) {
        $failed++;
    }
}
function sv_note(string $msg): void { echo "  · {$msg}\n"; }

// ── Guard: sandbox only ─────────────────────────────────────────────────
$env   = (string) settings_get('quickbooks.environment', '');
$conn  = (string) settings_get('quickbooks.connection_status', '');
$realm = (string) settings_get('quickbooks.realm_id', '');
if ($env !== 'sandbox' || $conn !== 'connected' || $realm === '') {
    fwrite(STDERR, "Refusing: needs a CONNECTED SANDBOX (environment='{$env}', status='{$conn}', realm='{$realm}').\n");
    exit(2);
}
if ((string) settings_get('quickbooks.fixture_mode', '0') === '1') {
    fwrite(STDERR, "Refusing: quickbooks.fixture_mode=1 — turn it off to talk to the real sandbox.\n");
    exit(2);
}
$client = new QuickBooksClient();

// ── 1. Company + preferences ────────────────────────────────────────────
sv_head('1. Company');
try {
    $info = CompanyInfoSync::syncFromQbo($client);
    sv_ok(true, 'CompanyInfo read', json_encode($info, JSON_UNESCAPED_SLASHES));
} catch (\Throwable $e) {
    sv_ok(false, 'CompanyInfo read', $e->getMessage());
}
settings_cache_flush();
$home = (string) settings_get('quickbooks.home_currency', '');
sv_ok($home === 'CAD', "Home currency is CAD (got '{$home}') — FF bills in CAD");
sv_note('Multi-currency: ' . (settings_get('quickbooks.multi_currency_enabled', '0') === '1' ? 'ON' : 'off'));
sv_note('Class tracking: ' . (settings_get('quickbooks.pref.class_tracking', '') ?: '?')
    . ' · Locations: ' . (settings_get('quickbooks.pref.track_locations', '') ?: '?')
    . ' · Books closed through: ' . (settings_get('quickbooks.pref.book_close_date', '') ?: 'not set'));
sv_ok((string) settings_get('quickbooks.pref.custom_txn_numbers', '') === '1',
    'Custom transaction numbers ON (keeps FleetForge invoice numbers)',
    'QuickBooks → Account and settings → Sales → Custom transaction numbers');

// ── 2. Mapping readiness ───────────────────────────────────────────────
sv_head('2. Mappings');
foreach ([
    'customers mapped' => "SELECT COUNT(*) FROM acc_qbo_customer_map WHERE mapping_status = 'mapped'",
    'vendors mapped'   => "SELECT COUNT(*) FROM acc_qbo_vendor_map WHERE mapping_status = 'mapped'",
    'items mapped'     => "SELECT COUNT(*) FROM acc_qbo_item_map WHERE mapping_status = 'mapped'",
    'accounts mapped'  => "SELECT COUNT(*) FROM acc_qbo_account_map WHERE mapping_status = 'mapped'",
    'tax codes pulled' => "SELECT COUNT(*) FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id IS NOT NULL",
] as $label => $sql) {
    sv_note(str_pad($label, 18) . db_count($sql));
}
try {
    AccountValidator::assertReadyForInvoicePush();
    sv_ok(true, 'Chart of accounts ready for invoice push');
} catch (\Throwable $e) {
    sv_ok(false, 'Chart of accounts ready for invoice push', $e->getMessage());
}
$pushFrom = InvoiceLinker::pushFromDay();
sv_note('Push transactions dated from: ' . ($pushFrom ?? 'no limit (sync never enabled)')
    . ($pushFrom !== null ? ' — older FF documents are held back as "already in QuickBooks". The demo dataset is historical: on this DEV rehearsal set Settings → Business tagging → "Push transactions dated from" to e.g. 2020-01-01.' : ''));
$mode = (string) settings_get('quickbooks.invoice.tax_mode', 'override');
sv_note("Invoice tax mode: {$mode}");
if ($mode === 'per_rate') {
    foreach (db_select(
        "SELECT t.id, t.name, m.qbo_tax_code_id, m.qbo_name FROM tax_rates t
           LEFT JOIN acc_qbo_tax_code_map m ON m.ff_tax_rate_id = t.id AND m.mapping_status = 'mapped'
          WHERE t.is_active = 1 ORDER BY t.province"
    ) as $t) {
        sv_ok($t['qbo_tax_code_id'] !== null, "FF tax rate '{$t['name']}' → QuickBooks code", $t['qbo_name'] ?? 'unmapped');
    }
} else {
    sv_note('Canadian company: switch to per_rate on QuickBooks → Tax Codes → Invoice tax, then re-run.');
}
sv_head('   QuickBooks tax codes (sales rates)');
foreach (db_select("SELECT qbo_tax_code_id, qbo_name, qbo_sales_rate_refs FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id IS NOT NULL ORDER BY qbo_name") as $c) {
    $names = array_map(static fn($r) => $r['TaxRateRef']['name'] ?? '?', json_decode((string) $c['qbo_sales_rate_refs'], true) ?: []);
    sv_note("#{$c['qbo_tax_code_id']} {$c['qbo_name']}" . ($names ? ' — ' . implode(', ', $names) : ''));
}

// ── 3. Invoice push ────────────────────────────────────────────────────
$qboInvoiceId = null;
if ($invoiceId > 0) {
    sv_head("3. Push FF invoice #{$invoiceId}");
    $inv = db_row("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
    if (!$inv) {
        sv_ok(false, 'Invoice exists');
    } else {
        $existing = db_row("SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?", [$invoiceId]);
        $res = $existing && $existing['qbo_invoice_id']
            ? ['success' => true, 'status' => 'already_mapped', 'qbo_id' => $existing['qbo_invoice_id']]
            : InvoicePusher::pushCreate($invoiceId);
        sv_ok(!empty($res['success']), 'Push result: ' . ($res['status'] ?? '?'), (string) ($res['error'] ?? ''));
        $qboInvoiceId = $res['qbo_id'] ?? null;
        if ($qboInvoiceId) {
            $q = $client->getEntity('invoice', (string) $qboInvoiceId, ['entity_type' => 'invoice', 'operation' => 'sandbox_verify'])['Invoice'] ?? [];
            $ffTotal  = bcadd((string) $inv['total_amount'], '0', 2);
            $qboTotal = bcadd((string) ($q['TotalAmt'] ?? '0'), '0', 2);
            sv_ok(bccomp($ffTotal, $qboTotal, 2) === 0, "QuickBooks total {$qboTotal} = FleetForge total {$ffTotal}");
            $qTax = bcadd((string) ($q['TxnTaxDetail']['TotalTax'] ?? '0'), '0', 2);
            $fTax = bcadd((string) ($inv['tax_total'] ?? '0'), '0', 2);
            sv_ok(bccomp($qTax, $fTax, 2) === 0, "QuickBooks tax {$qTax} = FleetForge tax {$fTax}");
            foreach ($q['TxnTaxDetail']['TaxLine'] ?? [] as $tl) {
                sv_note('Tax line: ' . ($tl['TaxLineDetail']['TaxRateRef']['value'] ?? '?') . ' = ' . ($tl['Amount'] ?? '?'));
            }
            sv_ok(($q['DocNumber'] ?? '') === (string) $inv['invoice_number'] || (string) settings_get('quickbooks.pref.custom_txn_numbers', '') === '0',
                'DocNumber is the FleetForge number (' . ($q['DocNumber'] ?? '—') . ')');
            if ((string) settings_get('quickbooks.payments_enabled', '0') === '1') {
                try {
                    $link = $client->generatePaymentsHostedUrl((string) $qboInvoiceId, base_url('pay'), base_url('pay'));
                    sv_ok(!empty($link['url']), 'Pay-online link issued', (string) ($link['url'] ?? ''));
                } catch (\Throwable $e) {
                    sv_ok(false, 'Pay-online link issued', $e->getMessage() . ' — the sandbox needs QuickBooks Payments enabled + a BillEmail');
                }
            } else {
                sv_note('Pay-online link skipped (quickbooks.payments_enabled is off).');
            }
        }
    }
}

// ── 4. Payment round trip ──────────────────────────────────────────────
if ($invoiceId > 0 && $qboInvoiceId && isset($opts['pay'])) {
    sv_head('4. QuickBooks payment → FleetForge');
    $inv = db_row("SELECT balance_due, status, customer_id FROM invoices WHERE id = ?", [$invoiceId]);
    $cust = db_row("SELECT qbo_customer_id FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [(int) $inv['customer_id']]);
    $amount = bcadd((string) $inv['balance_due'], '0', 2);
    if (bccomp($amount, '0', 2) <= 0 || !in_array($inv['status'], InvoiceLinker::PAYABLE_INVOICE_STATUSES, true)) {
        sv_ok(false, 'Invoice is open and payable in FF', "status={$inv['status']} balance={$amount}");
    } else {
        try {
            $pay = $client->createEntity('payment', [
                'CustomerRef' => ['value' => (string) $cust['qbo_customer_id']],
                'TotalAmt'    => (float) $amount,
                'TxnDate'     => ff_today(),
                'Line'        => [['Amount' => (float) $amount, 'LinkedTxn' => [['TxnId' => (string) $qboInvoiceId, 'TxnType' => 'Invoice']]]],
            ], ['entity_type' => 'payment', 'operation' => 'sandbox_verify'])['Payment'] ?? [];
            $pid = (string) ($pay['Id'] ?? '');
            sv_ok($pid !== '', "Payment {$pid} created in QuickBooks for {$amount}");
            $r = PaymentWebhookHandler::handle($pid, 'Create', $realm, 'sandbox-verify');
            sv_ok(($r['result'] ?? '') === 'payment_created', 'Mirrored into FleetForge: ' . ($r['result'] ?? '?'), (string) ($r['detail'] ?? ''));
            $after = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$invoiceId]);
            sv_ok($after['status'] === 'paid', "FF invoice now '{$after['status']}' (balance {$after['balance_due']})");
            $je = db_count("SELECT COUNT(*) FROM acc_journal_entries WHERE source_type = 'payment' AND source_id = ?", [(int) ($r['ff_payment_id'] ?? 0)]);
            sv_ok($je > 0, "FF payment journal entry posted ({$je})");

            $again = PaymentWebhookHandler::handle($pid, 'Create', $realm, 'sandbox-verify');
            sv_ok(($again['result'] ?? '') === 'already_mapped', 'Replayed webhook is a no-op: ' . ($again['result'] ?? '?'));

            $client->voidEntity('payment', $pid, (string) ($pay['SyncToken'] ?? '0'));
            $v = PaymentWebhookHandler::handle($pid, 'Void', $realm, 'sandbox-verify');
            sv_ok(($v['result'] ?? '') === 'payment_voided', 'Void in QuickBooks voided the FF payment: ' . ($v['result'] ?? '?'), (string) ($v['detail'] ?? ''));
            $back = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$invoiceId]);
            sv_ok(bccomp((string) $back['balance_due'], $amount, 2) === 0, "FF invoice reopened (balance {$back['balance_due']}, status {$back['status']})");
        } catch (\Throwable $e) {
            sv_ok(false, 'Payment round trip', $e->getMessage());
        }
    }
}

// ── 5. Go-live linker ──────────────────────────────────────────────────
if (isset($opts['linker'])) {
    sv_head('5. Go-live linker (read-only)');
    foreach (['invoice', 'credit_memo', 'bill'] as $kind) {
        try {
            $c = InvoiceLinker::candidates($kind, ['limit' => 50]);
            sv_ok(true, "{$kind}: " . count($c['rows']) . ' unlinked', json_encode($c['summary']));
        } catch (\Throwable $e) {
            sv_ok(false, "{$kind} candidates", $e->getMessage());
        }
    }
}

echo "\n" . ($failed === 0 ? 'ALL CHECKS PASSED' : "{$failed} CHECK(S) FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
