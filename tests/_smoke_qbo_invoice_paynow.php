<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_invoice_paynow.php
 *
 * S-QBO-INVOICE-PAYNOW — the QuickBooks invoice carries the customer's
 * email, terms, bill-to and PO; the due date follows the customer's terms;
 * a "Pay now" button reaches the customer in every invoice email, reminder,
 * dunning letter and PDF; and QuickBooks payments come back to FleetForge
 * even when the webhook is missed.
 *
 *   C1  PaymentTerms: "Net 15"/"n30"/"30 days"/"Due on receipt" parse; blank
 *       and odd terms fall back to the company default
 *   C2  generation: a Net 15 customer's invoice is due 15 days after its date,
 *       a blank-terms customer's after the default, "Due on receipt" same day
 *   C3  TermResolver::matchDays: customer's named term first, else the one
 *       term with those days, ambiguous / none → null
 *   C4  buildQboPayload: BillEmail even with Payments OFF; SalesTermRef from
 *       the resolved term; BillAddr from the Bill-To snapshot; PO custom field
 *       only when QuickBooks has one; AllowOnline* only with Payments ON
 *   C5  CompanyInfoSync::poCustomField reads Preferences custom-field slots
 *   C6  PayLink: off → nothing; payable rules; button + text carry link/amount
 *   C7  EmailService::withPayNow: appended once for a payable invoice; not for
 *       other entities, drafts, Payments off, or a body that already has it
 *   C8  reminders: due-soon / overdue "Pay now" goes straight to the pay link
 *   C9  PDF pay box: button + QR while payable; nothing for paid / void / off
 *   C10 dunning letter: a Pay-now link per overdue invoice (only when on)
 *   C11 PaymentBackstop (fixture QuickBooks): a missed payment on an FF
 *       invoice is brought in and the invoice paid; others ignored; re-run is
 *       a no-op; window advances
 *   C12 wiring: worker runs the backstop before the queue claim; compose
 *       modal notice; invoice "Copy pay link"; template variable; RealmGuard
 *
 * All DB writes inside transactions that are rolled back.
 *
 * @session S-QBO-INVOICE-PAYNOW
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Billing\PaymentTerms;
use FleetForge\Email\EmailService;
use FleetForge\QboFixture;
use FleetForge\QboPushers\CompanyInfoSync;
use FleetForge\QboPushers\InvoicePusher;
use FleetForge\QboPushers\PayLink;
use FleetForge\QboPushers\PaymentBackstop;
use FleetForge\QboPushers\TermResolver;
use FleetForge\Tests\Fixtures;

require_once FF_ROOT . '/lib/Email/templates/customer_reminders.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

$pass     = 0;
$total    = 12;
$failures = [];

function ff_pn_check(string $id, string $label, array $errs): void
{
    global $pass, $failures;
    if ($errs === []) {
        echo "PASS {$id} {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$id} {$label} — " . implode('; ', $errs) . "\n";
        $failures[] = $id;
    }
}

/** Settings write inside the rolled-back transaction. */
function ff_pn_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    settings_cache_flush();
}

/** A customer + sent invoice mapped to QuickBooks (rolled back). */
function ff_pn_invoice(int $id, string $status = 'sent', string $balance = '250.00', array $inv = []): int
{
    db_execute("INSERT INTO customers (id, company_name, email, invoice_email, currency, payment_terms, outstanding_balance)
                VALUES (?, ?, ?, 'billing@paynow.test', 'CAD', 'Net 15', ?)", [$id, 'Smoke PayNow Co ' . $id, "main{$id}@paynow.test", $balance]);
    db_execute(
        "INSERT INTO invoices (id, invoice_number, customer_id, invoice_date, due_date, status,
                               billing_period_start, billing_period_end, billing_period_days, billing_type,
                               subtotal, tax_total, total_amount, amount_paid, balance_due, currency, po_number, billing_address_snapshot)
         VALUES (?, ?, ?, '2026-09-01', '2026-09-16', ?, '2026-09-01', '2026-09-30', 30, 'full_month',
                 ?, '0.00', ?, '0.00', ?, 'CAD', ?, ?)",
        [$id, 'SMK-PN-' . $id, $id, $status, $balance, $balance, $balance, $inv['po_number'] ?? 'PO-7781', $inv['bill_to'] ?? "Smoke PayNow Co\n12 Dock Rd\nDelta BC V4G 1A1"]
    );
    db_execute("INSERT INTO acc_qbo_invoice_map (ff_invoice_id, qbo_invoice_id, qbo_sync_token, push_status, pushed_at)
                VALUES (?, ?, '0', 'pushed', NOW())", [$id, 'SMK-QINV-' . $id]);
    return $id;
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-INVOICE-PAYNOW smoke ({$total} sub-checks; rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();

// ══ C1 ════════════════════════════════════════════════════════════
$e = [];
foreach ([['Net 15', 15], ['net15', 15], ['N30', 30], ['30 days', 30], ['Due on receipt', 0], ['COD', 0], ['', null], [null, null], ['2% 10 Net 30', null], ['15th of next month', null]] as [$in, $want]) {
    if (PaymentTerms::parseDays($in) !== $want) { $e[] = var_export($in, true) . ' → ' . var_export(PaymentTerms::parseDays($in), true); }
}
$def = PaymentTerms::defaultDays();
if (PaymentTerms::dueDays('whenever') !== $def || PaymentTerms::dueDays(null) !== $def) { $e[] = 'unreadable terms do not fall back to the default'; }
if (PaymentTerms::dueDate('2026-09-01', 'Net 15') !== '2026-09-16' || PaymentTerms::dueDate('2026-02-20', 'Net 10') !== '2026-03-02') { $e[] = 'dueDate arithmetic'; }
if (TermResolver::dueDays('Net 45') !== 45) { $e[] = 'TermResolver no longer shares the parser'; }
ff_pn_check('C1', 'payment terms parse; odd/blank terms use the default', $e);

// ══ C2 ════════════════════════════════════════════════════════════
$e = [];
$pdo->beginTransaction();
try {
    $gen = new InvoiceGenerator();
    foreach ([['Net 15', 15], [null, $def], ['Due on receipt', 0]] as [$terms, $days]) {
        $cust = Fixtures::createCustomer(['province' => 'BC', 'payment_terms' => $terms]);
        $lease = Fixtures::createLease($cust, [
            'engine_version' => 'holistic', 'billing_cycle' => 'monthly', 'status' => 'active',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00', 'gps_opt_in' => 0,
            'start_date' => '2027-05-01', 'end_date' => '2027-05-31',
        ]);
        $batch = $gen->generateForLease(['lease_id' => $lease, 'period_start' => '2027-05-01', 'period_end' => '2027-05-31',
                                         'billing_type' => 'full_month', 'created_by' => null, 'generation_source' => 'manual']);
        $row = db_row("SELECT invoice_date, due_date FROM invoices WHERE id = ?", [(int) $batch['invoices'][0]['invoice_id']]);
        $want = (new DateTimeImmutable($row['invoice_date']))->modify("+{$days} days")->format('Y-m-d');
        if ($row['due_date'] !== $want) { $e[] = var_export($terms, true) . ": due {$row['due_date']}, want {$want}"; }
    }
    $src = (string) file_get_contents(FF_ROOT . '/lib/Billing/InvoiceGenerator.php');
    if (substr_count($src, 'PaymentTerms::dueDate(') !== 2 || str_contains($src, "settings_get('invoice.due_days_default'")) { $e[] = 'a generator path still ignores the customer terms'; }
} catch (\Throwable $ex) {
    $e[] = get_class($ex) . ': ' . $ex->getMessage();
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
ff_pn_check('C2', 'generated due dates follow terms (Net 15 → +15, blank → default, on receipt → same day)', $e);

// ══ C3 ════════════════════════════════════════════════════════════
$e = [];
$terms = [
    ['id' => '1', 'name' => 'Net 15', 'days' => 15],
    ['id' => '2', 'name' => 'Net 30', 'days' => 30],
    ['id' => '3', 'name' => '30 days (legacy)', 'days' => 30],
    ['id' => '4', 'name' => 'Due on receipt', 'days' => 0],
    ['id' => '5', 'name' => '15th of next month', 'days' => null],
];
$cases = [[15, 'Net 15', '1'], [15, null, '1'], [30, 'Net 30', '2'], [30, '30 days (legacy)', '3'], [30, 'Net 15', null], [0, 'Due on receipt', '4'], [45, 'Net 45', null]];
foreach ($cases as [$days, $cust, $want]) {
    $got = TermResolver::matchDays($days, $cust, $terms);
    if ($got !== $want) { $e[] = "{$days}d/" . var_export($cust, true) . ' → ' . var_export($got, true) . ' want ' . var_export($want, true); }
}
ff_pn_check('C3', 'invoice term: named term first, unique same-days term next, never a guess', $e);

// ══ C4 — C10 (rolled back) ════════════════════════════════════════
$pdo->beginTransaction();
try {
    ff_pn_invoice(999911);
    ff_pn_invoice(999912, 'paid', '0.00');
    ff_pn_invoice(999913, 'draft', '120.00');
    $inv  = db_row("SELECT * FROM invoices WHERE id = 999911");
    $cust = db_row("SELECT * FROM customers WHERE id = 999911");
    $line = [['item_type' => 'base_rental', 'description' => 'Rental', 'amount' => '250.00', 'unit_price' => '250.00', 'quantity' => 1, 'sort_order' => 1]];

    // ── C4 ──
    $e = [];
    ff_pn_set('quickbooks.payments_enabled', '0');
    ff_pn_set('quickbooks.pref.po_custom_field_id', '');
    $pl = InvoicePusher::buildQboPayload($inv, $cust, $line, ['qbo_customer_id' => 'SMK-QC'], '2');
    if (($pl['BillEmail']['Address'] ?? '') !== 'billing@paynow.test') { $e[] = 'BillEmail missing with Payments off: ' . json_encode($pl['BillEmail'] ?? null); }
    if (isset($pl['AllowOnlineCreditCardPayment'])) { $e[] = 'AllowOnline* sent while Payments off'; }
    if (($pl['SalesTermRef']['value'] ?? '') !== '2') { $e[] = 'SalesTermRef not the resolved term'; }
    if (($pl['BillAddr']['Line2'] ?? '') !== '12 Dock Rd' || isset($pl['BillAddr']['Line4'])) { $e[] = 'BillAddr from snapshot: ' . json_encode($pl['BillAddr'] ?? null); }
    if (isset($pl['CustomField'])) { $e[] = 'CustomField sent with no QuickBooks PO field'; }
    if (($pl['DueDate'] ?? '') !== '2026-09-16') { $e[] = 'DueDate'; }
    ff_pn_set('quickbooks.payments_enabled', '1');
    ff_pn_set('quickbooks.pref.po_custom_field_id', '2');
    ff_pn_set('quickbooks.pref.po_custom_field_name', 'P.O. Number');
    $pl = InvoicePusher::buildQboPayload($inv, $cust, $line, ['qbo_customer_id' => 'SMK-QC'], null);
    if (empty($pl['AllowOnlineCreditCardPayment']) || empty($pl['AllowOnlineACHPayment'])) { $e[] = 'AllowOnline* missing with Payments on'; }
    if (isset($pl['SalesTermRef'])) { $e[] = 'SalesTermRef sent without a resolved term'; }
    $cf = $pl['CustomField'][0] ?? [];
    if (($cf['DefinitionId'] ?? '') !== '2' || ($cf['StringValue'] ?? '') !== 'PO-7781' || ($cf['Type'] ?? '') !== 'StringType') { $e[] = 'PO custom field: ' . json_encode($cf); }
    $noSnap = $inv; $noSnap['billing_address_snapshot'] = '';
    if (isset(InvoicePusher::buildQboPayload($noSnap, $cust, $line, ['qbo_customer_id' => 'SMK-QC'])['BillAddr'])) { $e[] = 'BillAddr sent from an empty snapshot'; }
    if (InvoicePusher::termDays(['invoice_date' => '2026-09-01', 'due_date' => '2026-08-01']) !== null) { $e[] = 'reversed dates gave term days'; }
    ff_pn_check('C4', 'QuickBooks invoice: email always, term, bill-to, PO field, online-pay flags', $e);

    // ── C5 ──
    $e = [];
    $prefs = ['CustomField' => [
        ['CustomField' => [
            ['Name' => 'SalesFormsPrefs.UseSalesCustom1', 'Type' => 'BooleanType', 'BooleanValue' => true],
            ['Name' => 'SalesFormsPrefs.UseSalesCustom2', 'Type' => 'BooleanType', 'BooleanValue' => true],
            ['Name' => 'SalesFormsPrefs.UseSalesCustom3', 'Type' => 'BooleanType', 'BooleanValue' => false],
        ]],
        ['CustomField' => [
            ['Name' => 'SalesFormsPrefs.SalesCustomName1', 'Type' => 'StringType', 'StringValue' => 'Crew #'],
            ['Name' => 'SalesFormsPrefs.SalesCustomName2', 'Type' => 'StringType', 'StringValue' => 'P.O. Number'],
            ['Name' => 'SalesFormsPrefs.SalesCustomName3', 'Type' => 'StringType', 'StringValue' => 'PO'],
        ]],
    ]];
    if (CompanyInfoSync::poCustomField($prefs) !== ['id' => '2', 'name' => 'P.O. Number']) { $e[] = 'slot 2 not found: ' . json_encode(CompanyInfoSync::poCustomField($prefs)); }
    $prefs['CustomField'][0]['CustomField'][1]['BooleanValue'] = false;   // slot 2 off; slot 3 "PO" is off too
    if (CompanyInfoSync::poCustomField($prefs) !== null) { $e[] = 'a switched-off slot was used'; }
    foreach (['PO #', 'Purchase Order', 'po number', 'P.O.'] as $label) {
        $p = ['CustomField' => [['CustomField' => [['Name' => 'SalesFormsPrefs.UseSalesCustom1', 'BooleanValue' => 'true'], ['Name' => 'SalesFormsPrefs.SalesCustomName1', 'StringValue' => $label]]]]];
        if ((CompanyInfoSync::poCustomField($p)['id'] ?? null) !== '1') { $e[] = "label '{$label}' not recognised"; }
    }
    ff_pn_check('C5', 'QuickBooks PO custom field found from Preferences (on + PO-like label only)', $e);

    // ── C6 ──
    $e = [];
    ff_pn_set('quickbooks.payments_enabled', '0');
    if (PayLink::url(999911) !== '' || PayLink::buttonHtml(999911) !== '') { $e[] = 'link/button while Payments off'; }
    ff_pn_set('quickbooks.payments_enabled', '1');
    $url = PayLink::payableUrl(999911);
    if ($url === '' || !str_contains($url, '/pay?i=999911&t=')) { $e[] = "payableUrl '{$url}'"; }
    if (PayLink::payableUrl(999912) !== '' || PayLink::payableUrl(999913) !== '') { $e[] = 'paid / draft invoice offered a pay link'; }
    $btn = PayLink::buttonHtml(999911);
    foreach (['data-ff-pay-now', htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'Pay now', '250.00', 'SMK-PN-999911'] as $needle) {
        if (!str_contains($btn, $needle)) { $e[] = "button lacks {$needle}"; }
    }
    if (!str_contains(PayLink::buttonText(999911), $url)) { $e[] = 'text line lacks the link'; }
    ff_pn_check('C6', 'pay link only when on + payable; button carries link, amount, invoice', $e);

    // ── C7 ──
    $e = [];
    [$h, $t] = EmailService::withPayNow('<p>Hi</p>', 'Hi', 'invoice', 999911);
    if (substr_count($h, 'data-ff-pay-now') !== 1 || !str_contains($t, $url)) { $e[] = 'not appended for a payable invoice'; }
    [$h2] = EmailService::withPayNow($h, $t, 'invoice', 999911);
    if (substr_count($h2, 'data-ff-pay-now') !== 1) { $e[] = 'appended twice'; }
    [$h3] = EmailService::withPayNow('<p>Pay: ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>', '', 'invoice', 999911);
    if (str_contains($h3, 'data-ff-pay-now')) { $e[] = 'added although the template placed the link'; }
    foreach ([['lease', 999911], ['invoice', 999913], ['invoice', 999912], [null, null]] as [$type, $id]) {
        [$hx] = EmailService::withPayNow('<p>x</p>', 'x', $type, $id);
        if ($hx !== '<p>x</p>') { $e[] = "changed body for {$type}#{$id}"; }
    }
    $vars = EmailService::resolveEntityVariables('invoice', 999911);
    if (!str_contains((string) ($vars['pay_now_button'] ?? ''), 'data-ff-pay-now')) { $e[] = '{pay_now_button} variable empty'; }
    ff_pn_check('C7', 'emails: Pay now appended once for payable invoices only', $e);

    // ── C8 ──
    $e = [];
    $withPay = render_customer_invoice_overdue(['customer_name' => 'X', 'company_name' => 'Y', 'invoice_number' => 'I', 'due_date' => 'd', 'amount' => '$1', 'days_overdue' => 3,
                                                'portal_url' => 'https://portal.test/p', 'pay_url' => $url]);
    if (!str_contains($withPay, 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"') || !str_contains($withPay, 'Pay now') || str_contains($withPay, 'portal.test/p')) { $e[] = 'overdue reminder not straight to the pay link'; }
    $noPay = render_customer_invoice_due_soon(['customer_name' => 'X', 'company_name' => 'Y', 'invoice_number' => 'I', 'due_date' => 'd', 'amount' => '$1',
                                               'portal_url' => 'https://portal.test/p', 'pay_url' => '']);
    if (!str_contains($noPay, 'portal.test/p') || !str_contains($noPay, 'View &amp; Pay Invoice')) { $e[] = 'no pay link → portal fallback lost'; }
    $cron = (string) file_get_contents(FF_ROOT . '/cron/customer_reminders.php');
    if (substr_count($cron, "PayLink::payableUrl(\$invId)") !== 2) { $e[] = 'reminder cron does not pass pay_url for both invoice reminders'; }
    ff_pn_check('C8', 'due-soon / overdue reminders pay in one click (portal fallback kept)', $e);

    // ── C9 ──
    $e = [];
    $box = InvoicePdfGenerator::payNowBlock($inv);
    if (!str_contains($box, 'Pay now') || !str_contains($box, 'data:image/svg+xml;base64,') || !str_contains($box, htmlspecialchars($url, ENT_QUOTES, 'UTF-8'))) { $e[] = 'PDF pay box lacks button / QR / link'; }
    $draft = db_row("SELECT * FROM invoices WHERE id = 999913");
    if (InvoicePdfGenerator::payNowBlock($draft) === '') { $e[] = 'draft PDF (rendered before send) has no pay box'; }
    if (InvoicePdfGenerator::payNowBlock(db_row("SELECT * FROM invoices WHERE id = 999912")) !== '') { $e[] = 'paid invoice got a pay box'; }
    ff_pn_set('quickbooks.payments_enabled', '0');
    if (InvoicePdfGenerator::payNowBlock($inv) !== '') { $e[] = 'pay box while Payments off'; }
    ff_pn_set('quickbooks.payments_enabled', '1');
    ff_pn_check('C9', 'invoice PDF: Pay now button + QR code only while it can be paid', $e);

    // ── C10 ──
    $e = [];
    $render = new ReflectionMethod(\FleetForge\Accounting\DunningLetterGenerator::class, 'renderHtml');
    $render->setAccessible(true);
    $content = ['heading' => 'H', 'body' => 'B', 'closing' => 'C', 'subject' => 'S'];
    $overdue = [['id' => 999911, 'invoice_number' => 'SMK-PN-999911', 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-16', 'balance_due' => '250.00']];
    $html = (string) $render->invoke(null, $cust, $overdue, $content, '250.00');
    if (!str_contains($html, 'Pay online') || !str_contains($html, htmlspecialchars($url, ENT_QUOTES, 'UTF-8'))) { $e[] = 'dunning letter lacks the pay link'; }
    ff_pn_set('quickbooks.payments_enabled', '0');
    $html = (string) $render->invoke(null, $cust, $overdue, $content, '250.00');
    if (str_contains($html, 'Pay online')) { $e[] = 'pay column while Payments off'; }
    ff_pn_check('C10', 'dunning letter: Pay now per overdue invoice, only while on', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL-C4-10';
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    settings_cache_flush();
}

// ══ C11 — payment backstop against fixture QuickBooks ═════════════
$e = [];
$pdo->beginTransaction();
try {
    ff_pn_invoice(999921, 'sent', '300.00');
    ff_pn_set('quickbooks.environment', 'sandbox');
    ff_pn_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
    ff_pn_set('quickbooks.realm_mismatch', '0');
    ff_pn_set('quickbooks.fixture_mode', '1');
    ff_pn_set('quickbooks.payments.backstop_since', '');
    ff_pn_set('quickbooks.payments.backstop_last_run', '');
    QboFixture::reset();

    $mine = ['Id' => 'SMK-PAY-1', 'SyncToken' => '0', 'TotalAmt' => 300.00, 'TxnDate' => ff_today(), 'CurrencyRef' => ['value' => 'CAD'],
             'CustomerRef' => ['value' => 'SMK-QC'], 'PaymentRefNum' => 'QB-CARD-1',
             'Line' => [['Amount' => 300.00, 'LinkedTxn' => [['TxnId' => 'SMK-QINV-999921', 'TxnType' => 'Invoice']]]]];
    $other = ['Id' => 'SMK-PAY-2', 'SyncToken' => '0', 'TotalAmt' => 80.00, 'TxnDate' => ff_today(),
              'Line' => [['Amount' => 80.00, 'LinkedTxn' => [['TxnId' => 'OTHER-BUSINESS-INV', 'TxnType' => 'Invoice']]]]];
    $gone  = ['Id' => 'SMK-PAY-3', 'status' => 'Deleted'];
    QboFixture::cannedGet('cdc', ['CDCResponse' => [['QueryResponse' => [['Payment' => [$mine, $other, $gone]]]]]]);
    QboFixture::cannedGet('payment/SMK-PAY-1', ['Payment' => $mine]);

    if (PaymentBackstop::operationFor($mine) !== 'Create' || PaymentBackstop::operationFor($other) !== null || PaymentBackstop::operationFor($gone) !== null) { $e[] = 'operationFor rules'; }

    $r1 = PaymentBackstop::run();
    settings_cache_flush();
    $after = db_row("SELECT status, balance_due FROM invoices WHERE id = 999921");
    $map   = db_row("SELECT origin, ff_payment_id FROM acc_qbo_payment_map WHERE qbo_payment_id = 'SMK-PAY-1'");
    if ($r1['checked'] !== 3 || $r1['relevant'] !== 1) { $e[] = 'run counts ' . json_encode($r1); }
    if (($after['status'] ?? '') !== 'paid' || bccomp((string) ($after['balance_due'] ?? '1'), '0', 2) !== 0) { $e[] = 'FF invoice not paid: ' . json_encode($after) . ' results ' . json_encode($r1['results']); }
    if (!$map || $map['origin'] !== 'qbo_other' || empty($map['ff_payment_id'])) { $e[] = 'payment map row ' . json_encode($map); }
    if (db_row("SELECT 1 FROM acc_qbo_payment_map WHERE qbo_payment_id = 'SMK-PAY-2'")) { $e[] = "another business's payment was imported"; }
    if ((string) settings_get('quickbooks.payments.backstop_since', '') === '') { $e[] = 'window not advanced'; }

    // Re-run straight away: not due (10-minute throttle) → null.
    if (PaymentBackstop::runIfDue() !== null) { $e[] = 'ran again inside the interval'; }
    // Forced re-run: the same payment is now mapped with the same SyncToken → nothing to do.
    $r2 = PaymentBackstop::run();
    if ($r2['relevant'] !== 0) { $e[] = 'mirrored payment processed twice: ' . json_encode($r2); }
    $count = db_row("SELECT COUNT(*) n FROM payment_allocations a JOIN payments p ON p.id = a.payment_id WHERE a.invoice_id = 999921 AND p.deleted_at IS NULL");
    if ((int) ($count['n'] ?? 0) !== 1) { $e[] = 'payment count ' . json_encode($count); }
} catch (\Throwable $ex) {
    $e[] = get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine();
} finally {
    QboFixture::reset();
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    settings_cache_flush();
}
ff_pn_check('C11', 'missed QuickBooks payment brought in, invoice paid; others ignored; no double import', $e);

// ══ C12 ═══════════════════════════════════════════════════════════
$e = [];
$src = static fn (string $f): string => (string) file_get_contents(FF_ROOT . '/' . $f);
$worker = $src('cron/qbo_sync_worker.php');
$bsAt = strpos($worker, 'PaymentBackstop::runIfDue()');
$claimAt = strpos($worker, '0 due items; exiting');
if ($bsAt === false || $claimAt === false || $bsAt > $claimAt || !str_contains($worker, 'if (!$dryRun) {')) { $e[] = 'worker does not run the backstop before the empty-queue exit / outside dry-run'; }
if (!str_contains($src('api/v1/email/attachments/available.php'), "'pay_now'")) { $e[] = 'compose endpoint lacks pay_now'; }
if (!str_contains($src('includes/partials/email-compose-modal.php'), 'x-show="payNow"')) { $e[] = 'compose modal lacks the notice'; }
if (!str_contains($src('app/admin/invoices/show.php'), 'Copy pay link')) { $e[] = 'invoice page lacks Copy pay link'; }
if (!str_contains($src('app/admin/settings/email_templates.php'), "'pay_now_button'")) { $e[] = 'template variable list lacks pay_now_button'; }
$rg = $src('lib/QboPushers/RealmGuard.php');
foreach (['pref.po_custom_field_id', 'payments.backstop_since'] as $k) {
    if (!str_contains($rg, "'{$k}'")) { $e[] = "RealmGuard does not reset {$k}"; }
}
if (!str_contains($src('lib/QboPushers/InvoicePusher.php'), 'qboTermIdForInvoice(')) { $e[] = 'push path does not resolve the term'; }
ff_pn_check('C12', 'wired: worker backstop, compose notice, Copy pay link, template var, RealmGuard, term lookup', $e);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_invoice_paynow_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
