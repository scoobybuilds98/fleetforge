<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_customer_terms_addr.php
 *
 * S-QBO-CUSTOMER-TERMS-ADDR — payment terms + billing address on the
 * QuickBooks customer, sent only for customers FleetForge creates.
 *
 * Operator decision (2026-09-23): FF sends terms + billing address when IT
 * creates the QuickBooks customer (and keeps the address in sync for
 * those); a customer linked to the accountant's record keeps the
 * accountant's terms and address. Pushes run through the fixture layer
 * (QuickBooks answered in-process); the payload FF sent is read back from
 * acc_qbo_sync_log.request_payload.
 *
 * HERMETIC: one outer transaction, rolled back in `finally`. Sentinel
 * customer ids 999930–999934.
 *
 *   C1 dueDays: Net 30 / net30 / 30 days / N15 / Due on receipt / COD; unknown → null
 *   C2 match: same name wins; else the ONE same-days standard term; ambiguous
 *      or date-driven → no term
 *   C3 builder: billing_address lines → BillAddr (≤5, overflow folded) +
 *      main address → ShipAddr; no billing_address → main address as before
 *   C4 create: SalesTermRef from the QuickBooks term list + billing BillAddr
 *      sent; map ff_created_in_qbo = 1
 *   C5 update of a LINKED customer: no BillAddr / ShipAddr / SalesTermRef /
 *      DisplayName — the accountant's record keeps its address + terms
 *   C6 update of an FF-created customer: address sent; flag stays 1
 *   C7 no confident term match → created without SalesTermRef (push still OK)
 *
 * @session S-QBO-CUSTOMER-TERMS-ADDR
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboFixture;
use FleetForge\QboPushers\CustomerPusher;
use FleetForge\QboPushers\TermResolver;

$pass     = 0;
$total    = 7;
$failures = [];

function ff_cta_check(string $id, string $label, array $errs): void
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

function ff_cta_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    settings_cache_flush();
}

/**
 * The JSON body FF last sent for a customer push (fixture sync log). Outside
 * the worker the log row carries no entity_id, so match the sentinel's
 * CompanyName (sent on create AND update).
 */
function ff_cta_last_payload(int $customerId, string $operation): ?array
{
    $row = db_row(
        "SELECT request_payload FROM acc_qbo_sync_log
          WHERE entity_type = 'customer' AND operation = ? AND http_method = 'POST' AND request_payload LIKE ?
          ORDER BY id DESC LIMIT 1",
        [$operation, '%Smoke Terms Customer ' . $customerId . '%']
    );
    if (!$row) {
        return null;
    }
    $p = json_decode((string) $row['request_payload'], true);
    return is_array($p['body'] ?? null) ? $p['body'] : null;
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-CUSTOMER-TERMS-ADDR smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();
QboFixture::reset();
TermResolver::resetCache();

try {
    // ══ C1 — days parser ═════════════════════════════════════════════
    $e = [];
    foreach (['Net 30' => 30, 'net30' => 30, '30 days' => 30, 'N15' => 15, 'Due on receipt' => 0, 'due upon receipt' => 0, 'COD' => 0, '45' => 45,
              '2% 10 Net 30' => null, 'Monthly' => null, '' => null] as $in => $want) {
        $got = TermResolver::dueDays((string) $in);
        if ($got !== $want) { $e[] = "'{$in}' → " . var_export($got, true) . ' (want ' . var_export($want, true) . ')'; }
    }
    ff_cta_check('C1', 'payment-terms text → days (unknown formats are not guessed)', $e);

    // ══ C2 — matcher ═════════════════════════════════════════════════
    $terms = [
        ['id' => '1', 'name' => 'Due on receipt', 'days' => 0],
        ['id' => '2', 'name' => 'Net 15', 'days' => 15],
        ['id' => '3', 'name' => '30 days', 'days' => 30],
        ['id' => '4', 'name' => 'Net 60', 'days' => 60],
        ['id' => '5', 'name' => 'Sixty', 'days' => 60],
        ['id' => '6', 'name' => '15th of next month', 'days' => null],
    ];
    $e = [];
    if (TermResolver::match('net 15', $terms) !== '2') { $e[] = 'name match'; }
    if (TermResolver::match('Net 30', $terms) !== '3') { $e[] = 'days match (Net 30 ↔ 30 days)'; }
    if (TermResolver::match('COD', $terms) !== '1') { $e[] = 'COD ↔ Due on receipt'; }
    if (TermResolver::match('Net 60', $terms) !== '4') { $e[] = 'name must win over two 60-day terms'; }
    if (TermResolver::match('60 days', $terms) !== null) { $e[] = 'ambiguous 60-day match guessed'; }
    if (TermResolver::match('Monthly', $terms) !== null) { $e[] = 'unknown text matched'; }
    ff_cta_check('C2', 'term match: name first, else the ONE same-days term; ambiguous → none', $e);

    // ══ C3 — payload builder ═════════════════════════════════════════
    ff_cta_set('quickbooks.multi_currency_enabled', '0');
    $base = ['id' => 1, 'company_name' => 'Smoke Terms Co', 'currency' => 'CAD', 'address' => '12 Yard Rd', 'city' => 'Surrey',
             'province' => 'BC', 'postal_code' => 'V3S 1A1', 'country' => 'CA'];
    $e = [];
    $p = CustomerPusher::buildQboPayload($base + ['billing_address' => "Accounts Payable\nSmoke Terms Co\n400 Office St, Suite 9\nVancouver BC V6B 1A1"]);
    if (($p['BillAddr']['Line1'] ?? '') !== 'Accounts Payable' || ($p['BillAddr']['Line4'] ?? '') !== 'Vancouver BC V6B 1A1' || isset($p['BillAddr']['City'])) { $e[] = 'BillAddr ' . json_encode($p['BillAddr'] ?? null); }
    if (($p['ShipAddr']['Line1'] ?? '') !== '12 Yard Rd' || ($p['ShipAddr']['City'] ?? '') !== 'Surrey') { $e[] = 'ShipAddr ' . json_encode($p['ShipAddr'] ?? null); }
    $p = CustomerPusher::buildQboPayload($base + ['billing_address' => "a\nb\nc\nd\ne\nf\ng"]);
    if (($p['BillAddr']['Line5'] ?? '') !== 'e, f, g' || isset($p['BillAddr']['Line6'])) { $e[] = 'overflow ' . json_encode($p['BillAddr'] ?? null); }
    $p = CustomerPusher::buildQboPayload($base + ['billing_address' => "  \n "]);
    if (($p['BillAddr']['Line1'] ?? '') !== '12 Yard Rd' || isset($p['ShipAddr'])) { $e[] = 'blank billing address should fall back to main ' . json_encode($p); }
    ff_cta_check('C3', 'billing address → BillAddr lines (+ main address → ShipAddr); none → main address as before', $e);

    // ── Fixture QuickBooks for the pushes ─────────────────────────────
    ff_cta_set('quickbooks.environment', 'sandbox');
    ff_cta_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
    ff_cta_set('quickbooks.realm_mismatch', '0');
    ff_cta_set('quickbooks.fixture_mode', '1');
    ff_cta_set('quickbooks.sync_mode.customer', 'sync');
    ff_cta_set('quickbooks.cutover_at', '');        // no pre-go-live create block for the sentinels
    QboFixture::cannedGet('query:Term', ['QueryResponse' => ['Term' => [
        ['Id' => '7', 'Name' => 'Net 30', 'Type' => 'STANDARD', 'DueDays' => 30, 'Active' => true],
        ['Id' => '8', 'Name' => 'Net 15', 'Type' => 'STANDARD', 'DueDays' => 15, 'Active' => true],
    ]]]);
    foreach ([[999930, 'Net 30', "Smoke AP Dept\n1 Bill St"], [999931, 'Net 30', null], [999932, 'Monthly', null]] as [$cid, $pt, $ba]) {
        db_execute(
            "INSERT INTO customers (id, company_name, currency, address, city, province, postal_code, payment_terms, billing_address, created_at)
             VALUES (?, ?, 'CAD', '12 Yard Rd', 'Surrey', 'BC', 'V3S 1A1', ?, ?, NOW())",
            [$cid, "Smoke Terms Customer {$cid}", $pt, $ba]
        );
    }

    // ══ C4 — create ══════════════════════════════════════════════════
    $e = [];
    $r = CustomerPusher::pushCreate(999930);
    if (empty($r['success'])) { $e[] = 'push ' . json_encode($r); }
    $sent = ff_cta_last_payload(999930, 'create');
    if (($sent['SalesTermRef']['value'] ?? '') !== '7') { $e[] = 'SalesTermRef ' . json_encode($sent['SalesTermRef'] ?? null); }
    if (($sent['BillAddr']['Line1'] ?? '') !== 'Smoke AP Dept' || ($sent['ShipAddr']['Line1'] ?? '') !== '12 Yard Rd') { $e[] = 'addresses ' . json_encode([$sent['BillAddr'] ?? null, $sent['ShipAddr'] ?? null]); }
    $flag = db_row("SELECT ff_created_in_qbo FROM acc_qbo_customer_map WHERE ff_customer_id = 999930")['ff_created_in_qbo'] ?? null;
    if ((int) $flag !== 1) { $e[] = 'ff_created_in_qbo=' . var_export($flag, true); }
    ff_cta_check('C4', 'create: payment terms (QuickBooks term by name) + billing address sent; map marks FF-created', $e);

    // ══ C5 — update of a LINKED customer ═════════════════════════════
    $e = [];
    db_execute("INSERT INTO acc_qbo_customer_map (ff_customer_id, qbo_customer_id, qbo_sync_token, mapping_status, match_confidence, ff_created_in_qbo)
                VALUES (999931, 'ACCT-CUST-1', '3', 'mapped', 'exact', 0)");
    $r = CustomerPusher::pushUpdate(999931);
    if (empty($r['success'])) { $e[] = 'push ' . json_encode($r); }
    $sent = ff_cta_last_payload(999931, 'update');
    foreach (['BillAddr', 'ShipAddr', 'SalesTermRef', 'DisplayName'] as $k) {
        if (isset($sent[$k])) { $e[] = "{$k} sent to the accountant's record"; }
    }
    if (($sent['CompanyName'] ?? '') === '') { $e[] = 'update payload lost CompanyName ' . json_encode($sent); }
    ff_cta_check('C5', "update of a linked customer: name/email/phone only — accountant's address + terms untouched", $e);

    // ══ C6 — update of an FF-created customer ════════════════════════
    $e = [];
    db_execute("UPDATE customers SET billing_address = 'Smoke AP Dept\n99 New Bill Ave' WHERE id = 999930");
    $r = CustomerPusher::pushUpdate(999930);
    if (empty($r['success'])) { $e[] = 'push ' . json_encode($r); }
    $sent = ff_cta_last_payload(999930, 'update');
    if (($sent['BillAddr']['Line2'] ?? '') !== '99 New Bill Ave') { $e[] = 'BillAddr ' . json_encode($sent['BillAddr'] ?? null); }
    if (isset($sent['SalesTermRef'])) { $e[] = 'terms re-sent on update'; }
    if ((int) (db_row("SELECT ff_created_in_qbo FROM acc_qbo_customer_map WHERE ff_customer_id = 999930")['ff_created_in_qbo'] ?? 0) !== 1) { $e[] = 'flag cleared by update'; }
    ff_cta_check('C6', 'update of an FF-created customer: address kept in sync (terms not re-sent); flag stays', $e);

    // ══ C7 — no confident term ═══════════════════════════════════════
    $e = [];
    $r = CustomerPusher::pushCreate(999932);
    if (empty($r['success'])) { $e[] = 'push ' . json_encode($r); }
    $sent = ff_cta_last_payload(999932, 'create');
    if ($sent === null || isset($sent['SalesTermRef'])) { $e[] = 'payload ' . json_encode($sent); }
    ff_cta_check('C7', "terms QuickBooks doesn't have → created without SalesTermRef (QuickBooks default), push OK", $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    QboFixture::reset();
    TermResolver::resetCache();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_customer_terms_addr_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
