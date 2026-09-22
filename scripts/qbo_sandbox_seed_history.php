<?php
declare(strict_types=1);

/**
 * scripts/qbo_sandbox_seed_history.php
 *
 * Go-live REHEARSAL helper (S-QBO-GOLIVE-AUDIT). Recreates, inside an Intuit
 * SANDBOX company, the "year in QuickBooks" the real company will have at
 * go-live — built from the FF invoices in the (production-copy) dev DB, the
 * way the accountant would have typed them:
 *
 *   - a "Rentals" Class (the sandbox's sample party-business data plays the
 *     OTHER business sharing the company file);
 *   - a QuickBooks customer for every FF customer with an August invoice —
 *     most with the exact name, a few with the accountant's own spelling,
 *     so matching has something to confirm;
 *   - one QuickBooks invoice per FF August invoice (and a sample of July
 *     drafts), dated the same day or a day or two later, one line at FF's
 *     pre-tax amount on "GST/PST BC" (QuickBooks computes the tax), about a
 *     third carrying FF's invoice number and the rest the accountant's own
 *     numbering;
 *   - payments: most paid in full, some partly, one payment covering two
 *     invoices, one overpayment left unapplied.
 *
 * Writes ONLY to the sandbox (refuses unless quickbooks.environment='sandbox'
 * and connected; refuses while master sync is on so nothing is pushed back).
 * FF's map tables are NOT touched — linking them is the rehearsal.
 * Re-runnable: everything it creates carries PrivateNote "FF-REHEARSAL:…"
 * and is skipped when found.
 *
 *   php scripts/qbo_sandbox_seed_history.php [--july=30] [--dry-run]
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
require __DIR__ . '/../api/bootstrap.php';

use FleetForge\QuickBooksClient;

$opts   = getopt('', ['july::', 'dry-run']);
$july   = isset($opts['july']) ? max(0, (int) $opts['july']) : 30;
$dryRun = isset($opts['dry-run']);

if ((string) settings_get('quickbooks.environment', '') !== 'sandbox'
    || (string) settings_get('quickbooks.connection_status', '') !== 'connected') {
    fwrite(STDERR, "Refusing: needs a connected SANDBOX.\n");
    exit(2);
}
if ((string) settings_get('quickbooks.sync_enabled', '0') === '1') {
    fwrite(STDERR, "Refusing: master sync is ON — switch it off while seeding the sandbox's history.\n");
    exit(2);
}

$c = new QuickBooksClient();
$o = ['entity_type' => 'rehearsal_seed', 'operation' => 'rehearsal_seed'];
$q = static function (string $sql) use ($c, $o): array {
    $r = $c->query($sql, $o);
    $key = array_key_first(array_filter($r['QueryResponse'] ?? [], 'is_array')) ?? '';
    return $key === '' ? [] : ($r['QueryResponse'][$key] ?? []);
};
$esc = static fn(string $s): string => str_replace("'", "\\'", $s);
$say = static function (string $m): void { echo $m, "\n"; };
$created = ['class' => 0, 'item' => 0, 'customer' => 0, 'invoice' => 0, 'payment' => 0];

// ── Tax code + income account ──────────────────────────────────────────
$taxCode = $q("SELECT * FROM TaxCode WHERE Name = 'GST/PST BC'")[0]['Id'] ?? null;
if ($taxCode === null) {
    fwrite(STDERR, "The sandbox has no 'GST/PST BC' tax code — create it first.\n");
    exit(1);
}
// PST-exempt BC customers (~20% of invoices) are GST-only sales → "GST".
$gstRow  = $q("SELECT * FROM TaxCode WHERE Name = 'GST'")[0] ?? null;
$gstCode = $gstRow['Id'] ?? $taxCode;
$gstRate = $gstRow['SalesTaxRateList']['TaxRateDetail'][0]['TaxRateRef']['value'] ?? null;
$income = $q("SELECT * FROM Account WHERE AccountType = 'Income' AND Name = 'Sales'")[0]['Id']
    ?? $q("SELECT * FROM Account WHERE AccountType = 'Income' MAXRESULTS 1")[0]['Id'] ?? null;

// ── Class "Rentals" ────────────────────────────────────────────────────
$class = $q("SELECT * FROM Class WHERE Name = 'Rentals'")[0]['Id'] ?? null;
if ($class === null && !$dryRun) {
    $class = $c->createEntity('class', ['Name' => 'Rentals'], $o)['Class']['Id'];
    $created['class']++;
}

// ── Item the accountant bills rentals with ─────────────────────────────
$item = $q("SELECT * FROM Item WHERE Name = 'Equipment Rental'")[0]['Id'] ?? null;
if ($item === null && !$dryRun) {
    $item = $c->createEntity('item', [
        'Name' => 'Equipment Rental', 'Type' => 'Service',
        'IncomeAccountRef' => ['value' => (string) $income],
    ], $o)['Item']['Id'];
    $created['item']++;
}

// ── FF documents to mirror ─────────────────────────────────────────────
$aug = db_select(
    "SELECT i.id, i.invoice_number, i.customer_id, i.invoice_date, i.subtotal_after_discount, i.total_amount,
            i.tax_pst_amount, i.unit_number_invoice_snapshot AS unit, c.company_name
       FROM invoices i JOIN customers c ON c.id = i.customer_id
      WHERE i.status = 'overdue' AND i.deleted_at IS NULL
      ORDER BY i.customer_id, i.id"
);
$julyDocs = $july > 0 ? db_select(
    "SELECT i.id, i.invoice_number, i.customer_id, i.invoice_date, i.subtotal_after_discount, i.total_amount,
            i.tax_pst_amount, i.unit_number_invoice_snapshot AS unit, c.company_name
       FROM invoices i JOIN customers c ON c.id = i.customer_id
      WHERE i.status = 'draft' AND i.deleted_at IS NULL AND i.invoice_date BETWEEN '2026-07-01' AND '2026-07-31'
        AND i.customer_id IN (SELECT DISTINCT customer_id FROM invoices WHERE status = 'overdue')
      ORDER BY i.id LIMIT {$july}"
) : [];
$say(count($aug) . ' August invoices + ' . count($julyDocs) . ' July drafts to mirror' . ($dryRun ? ' (dry run)' : ''));

// ── Customers: exact names, except a few the accountant spelled their way ──
$custIds = array_values(array_unique(array_map(static fn($r) => (int) $r['customer_id'], $aug)));
$qboCust = [];
foreach ($custIds as $n => $ffCustId) {
    $name = (string) db_row("SELECT company_name FROM customers WHERE id = ?", [$ffCustId])['company_name'];
    // Every 5th customer: the accountant's variant (typo / "&" / trailing text)
    // so auto-match has suggestions to confirm, as in real life.
    $qboName = $name;
    if ($n % 5 === 1) {
        // Accountant added a qualifier → no automatic match; linked by hand.
        $qboName = str_contains($name, ' & ') ? str_replace(' & ', ' and ', $name) : $name . ' (Rentals)';
    } elseif ($n % 5 === 3) {
        // A typo (one letter dropped from the first word) → "similar name" suggestion.
        $words = explode(' ', $name);
        if (strlen($words[0]) > 4) {
            $words[0] = substr($words[0], 0, 2) . substr($words[0], 3);
            $qboName = implode(' ', $words);
        }
    }
    $found = $q("SELECT * FROM Customer WHERE DisplayName = '" . $esc($qboName) . "'")[0] ?? null;
    if ($found === null && !$dryRun) {
        $found = $c->createEntity('customer', [
            'DisplayName' => $qboName, 'CompanyName' => $qboName,
            'CurrencyRef' => ['value' => 'CAD'],
            'Notes'       => 'FF-REHEARSAL:customer:' . $ffCustId,
        ], $o)['Customer'];
        $created['customer']++;
        usleep(150000);
    }
    $qboCust[$ffCustId] = $found['Id'] ?? null;
    $say(sprintf('  customer %-40s → %s', $name, $qboName === $name ? 'same name' : "\"{$qboName}\""));
}

// ── Invoices ───────────────────────────────────────────────────────────
$mkInvoice = function (array $d, int $n, string $prefix) use ($c, $o, $q, $qboCust, $taxCode, $gstCode, $class, $item, $dryRun, &$created): ?array {
    $tag = 'FF-REHEARSAL:invoice:' . $d['id'];
    if (empty($qboCust[(int) $d['customer_id']])) {
        return null;   // customer not created (dry run)
    }
    $existing = $q("SELECT * FROM Invoice WHERE CustomerRef = '" . $qboCust[(int) $d['customer_id']] . "' AND TxnDate >= '" . $d['invoice_date'] . "' MAXRESULTS 1000");
    foreach ($existing as $e) {
        if (($e['PrivateNote'] ?? '') === $tag) {
            return $e;
        }
    }
    if ($dryRun || empty($qboCust[(int) $d['customer_id']])) {
        return null;
    }
    // A third keep FF's number (the accountant copied it); the rest use the
    // accountant's own series. Most on the same day, some a day or two later.
    $doc  = $n % 3 === 0 ? (string) $d['invoice_number'] : $prefix . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT);
    $date = $n % 4 === 3 ? date('Y-m-d', strtotime($d['invoice_date'] . ' +' . (1 + $n % 2) . ' day')) : (string) $d['invoice_date'];
    $inv = $c->createEntity('invoice', [
        'CustomerRef'  => ['value' => (string) $qboCust[(int) $d['customer_id']]],
        'TxnDate'      => $date,
        'DocNumber'    => $doc,
        'CurrencyRef'  => ['value' => 'CAD'],
        'ExchangeRate' => 1,
        'GlobalTaxCalculation' => 'TaxExcluded',
        'PrivateNote'  => $tag,
        'Line' => [[
            'DetailType'  => 'SalesItemLineDetail',
            'Amount'      => (float) $d['subtotal_after_discount'],
            'Description' => 'Equipment rental' . (!empty($d['unit']) ? ' — unit ' . $d['unit'] : '') . ' — ' . date('F Y', strtotime($d['invoice_date'])),
            'SalesItemLineDetail' => [
                'ItemRef'    => ['value' => (string) $item],
                'TaxCodeRef' => ['value' => (string) (bccomp((string) $d['tax_pst_amount'], '0', 2) > 0 ? $taxCode : $gstCode)],
                'ClassRef'   => ['value' => (string) $class],
            ],
        ]],
    ], $o)['Invoice'];
    $created['invoice']++;
    usleep(150000);
    return $inv;
};

$augQbo = [];
foreach ($aug as $n => $d) {
    $inv = $mkInvoice($d, $n, 'MR-');
    if ($inv) {
        $augQbo[] = ['ff' => $d, 'qbo' => $inv];
    }
}
$julyQbo = [];
foreach ($julyDocs as $n => $d) {
    $inv = $mkInvoice($d, $n, 'MR-7');
    if ($inv) {
        $julyQbo[] = ['ff' => $d, 'qbo' => $inv];
    }
}
$say(count($augQbo) . ' August + ' . count($julyQbo) . ' July QuickBooks invoices in place');

// ── Correct earlier runs: GST-only FF invoices put on GST/PST ─────────
// (first version of this script). Payments are trimmed first so no invoice
// ends up over-applied, then the invoice's line moves to the "GST" code and
// QuickBooks recomputes the tax.
$fixed = 0;
foreach (array_merge($augQbo, $julyQbo) as $k => $x) {
    $inv = $x['qbo'];
    if (bccomp((string) $x['ff']['tax_pst_amount'], '0', 2) > 0
        || (string) ($inv['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '') === (string) $gstCode
        || $dryRun) {
        continue;
    }
    $newTotal = bcadd((string) $x['ff']['total_amount'], '0', 2);
    foreach ($inv['LinkedTxn'] ?? [] as $lt) {
        if (($lt['TxnType'] ?? '') !== 'Payment') {
            continue;
        }
        $p = $c->getEntity('payment', (string) $lt['TxnId'], $o)['Payment'];
        $delta = '0.00';
        $lines = [];
        foreach ($p['Line'] ?? [] as $pl) {
            $amt = bcadd((string) $pl['Amount'], '0', 2);
            if ((string) ($pl['LinkedTxn'][0]['TxnId'] ?? '') === (string) $inv['Id'] && bccomp($amt, $newTotal, 2) > 0) {
                $delta = bcsub($amt, $newTotal, 2);
                $amt = $newTotal;
            }
            $lines[] = ['Amount' => (float) $amt, 'LinkedTxn' => $pl['LinkedTxn']];
        }
        if (bccomp($delta, '0', 2) > 0) {
            $c->updateEntity('payment', (string) $p['Id'], (string) $p['SyncToken'], [
                'CustomerRef' => $p['CustomerRef'],
                'TotalAmt'    => (float) bcsub((string) $p['TotalAmt'], $delta, 2),
                'Line'        => $lines,
            ], ['sparse' => true] + $o);
            usleep(150000);
        }
    }
    $cur = $c->getEntity('invoice', (string) $inv['Id'], $o)['Invoice'];
    $line = $cur['Line'][0];
    $line['SalesItemLineDetail']['TaxCodeRef'] = ['value' => (string) $gstCode];
    $gstAmt = bcsub($newTotal, bcadd((string) $x['ff']['subtotal_after_discount'], '0', 2), 2);
    $upd = $c->updateEntity('invoice', (string) $cur['Id'], (string) $cur['SyncToken'], [
        'CustomerRef' => $cur['CustomerRef'],
        'GlobalTaxCalculation' => 'TaxExcluded',
        // Replace the old GST+PST tax lines, or QuickBooks keeps the PST rate.
        'TxnTaxDetail' => ['TotalTax' => (float) $gstAmt, 'TaxLine' => [[
            'Amount' => (float) $gstAmt, 'DetailType' => 'TaxLineDetail',
            'TaxLineDetail' => ['TaxRateRef' => ['value' => (string) $gstRate], 'PercentBased' => true, 'TaxPercent' => 5,
                                'NetAmountTaxable' => (float) $x['ff']['subtotal_after_discount']],
        ]]],
        'Line' => [array_intersect_key($line, array_flip(['Id', 'LineNum', 'Description', 'Amount', 'DetailType', 'SalesItemLineDetail']))],
    ], ['sparse' => true] + $o)['Invoice'];
    $fixed++;
    usleep(150000);
    if ($k < count($augQbo)) {
        $augQbo[$k]['qbo'] = $upd;
    } else {
        $julyQbo[$k - count($augQbo)]['qbo'] = $upd;
    }
}
if ($fixed > 0) {
    $say("Corrected {$fixed} GST-only invoices to the GST code");
}

// ── Payments ───────────────────────────────────────────────────────────
$pay = function (string $custId, array $lines, string $total, string $date, string $tag) use ($c, $o, $q, $dryRun, &$created): void {
    if (bccomp($total, '0', 2) <= 0) {
        return;   // a $0 invoice has nothing to pay (QBO rejects a $0 payment)
    }
    foreach ($q("SELECT * FROM Payment WHERE CustomerRef = '{$custId}' MAXRESULTS 1000") as $p) {
        if (($p['PrivateNote'] ?? '') === $tag) {
            return;
        }
    }
    if ($dryRun) {
        return;
    }
    $c->createEntity('payment', [
        'CustomerRef' => ['value' => $custId], 'TotalAmt' => (float) $total, 'TxnDate' => $date,
        'CurrencyRef' => ['value' => 'CAD'], 'ExchangeRate' => 1,
        'PrivateNote' => $tag, 'Line' => $lines,
    ], $o);
    $created['payment']++;
    usleep(150000);
};
$line = static fn(array $inv, string $amt): array => ['Amount' => (float) $amt, 'LinkedTxn' => [['TxnId' => (string) $inv['Id'], 'TxnType' => 'Invoice']]];

// July history: all paid in full.
foreach ($julyQbo as $x) {
    $amt = bcadd((string) $x['qbo']['TotalAmt'], '0', 2);
    $pay((string) $x['qbo']['CustomerRef']['value'], [$line($x['qbo'], $amt)], $amt, '2026-07-20', 'FF-REHEARSAL:payment:' . $x['ff']['id']);
}
// August: ~55% full, ~10% partial, one two-invoice payment, one overpayment, rest open.
$skipNext = false;
foreach ($augQbo as $n => $x) {
    if ($skipNext) {
        $skipNext = false;
        continue;
    }
    $inv  = $x['qbo'];
    $amt  = bcadd((string) $inv['TotalAmt'], '0', 2);
    $cust = (string) $inv['CustomerRef']['value'];
    $date = date('Y-m-d', strtotime('2026-08-08 +' . ($n % 30) . ' days'));
    $tag  = 'FF-REHEARSAL:payment:' . $x['ff']['id'];
    $slot = $n % 20;
    if ($slot === 0 && isset($augQbo[$n + 1]) && $augQbo[$n + 1]['qbo']['CustomerRef']['value'] === $cust) {
        $amt2 = bcadd((string) $augQbo[$n + 1]['qbo']['TotalAmt'], '0', 2);
        $pay($cust, [$line($inv, $amt), $line($augQbo[$n + 1]['qbo'], $amt2)], bcadd($amt, $amt2, 2), $date, $tag);
        $skipNext = true;
    } elseif ($slot === 5) {
        $pay($cust, [$line($inv, $amt)], bcadd($amt, '50.00', 2), $date, $tag);          // $50 overpaid
    } elseif (in_array($slot, [7, 13], true)) {
        $part = bcdiv($amt, '2', 2);
        $pay($cust, [$line($inv, $part)], $part, $date, $tag);                             // half paid
    } elseif ($slot < 12) {
        $pay($cust, [$line($inv, $amt)], $amt, $date, $tag);                               // paid in full
    }
    // else: still open in QuickBooks too
}

$say('Created: ' . json_encode($created));
$say('Sandbox check: QuickBooks → Sales → Invoices (filter Class = Rentals).');
