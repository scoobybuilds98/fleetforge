<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_name_clash.php
 *
 * S-QBO-NAME-CLASH — QuickBooks "name already taken" (6240) on customer /
 * vendor CREATE.
 *
 * Operator decision (2026-09-23): add " (Vendor)" / " (Customer)"
 * automatically — but only when the name belongs to a DIFFERENT kind of
 * record (a dual-role company's other side, an employee). A record of the
 * same kind holding the name means "link, don't duplicate".
 *
 * QuickBooks answered by the fixture layer: QboFixture::injectError queues
 * the 6240 faults, cannedGet('query:Vendor' | 'query:Customer') answers the
 * same-kind lookup. HERMETIC: one outer transaction rolled back. Sentinels
 * 999900–999905.
 *
 *   C1 suffixed(): " (Vendor)" / " (Customer)", long names trimmed to fit;
 *      query escaping
 *   C2 vendor clash with a non-vendor → created as "X (Vendor)"; company +
 *      cheque name stay "X"; map notes the rename; audit row
 *   C3 an (inactive) vendor already has the name → refused with the link
 *      message, no second create
 *   C4 both "X" and "X (Vendor)" taken → refused, clear message
 *   C5 customer clash → " (Customer)"
 *   C6 lookup of who holds the name fails → refused, original create not
 *      retried blindly
 *   C7 any other create error is untouched (status qbo_error, no retry)
 *
 * @session S-QBO-NAME-CLASH
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboFixture;
use FleetForge\QboPushers\DisplayNameClash;
use FleetForge\QboPushers\VendorPusher;
use FleetForge\QboPushers\CustomerPusher;

$pass     = 0;
$total    = 7;
$failures = [];

function ff_nc_check(string $id, string $label, array $errs): void
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

function ff_nc_set(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
         VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    settings_cache_flush();
}

/** QuickBooks' 6240 fault body. */
function ff_nc_6240(): array
{
    return ['Fault' => ['Error' => [['Message' => 'Duplicate Name Exists Error', 'Detail' => 'The name supplied already exists.', 'code' => '6240']], 'type' => 'ValidationFault']];
}

/** Create bodies FF POSTed to $endpoint since $sinceLogId. */
function ff_nc_posts(string $endpoint, int $sinceLogId): array
{
    $out = [];
    foreach (db_select("SELECT request_payload FROM acc_qbo_sync_log WHERE id > ? AND http_method = 'POST' AND endpoint = ? ORDER BY id", [$sinceLogId, $endpoint]) as $r) {
        $out[] = json_decode((string) $r['request_payload'], true)['body'] ?? null;
    }
    return $out;
}

function ff_nc_log_max(): int
{
    return (int) (db_row("SELECT COALESCE(MAX(id), 0) AS m FROM acc_qbo_sync_log")['m'] ?? 0);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-NAME-CLASH smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();
QboFixture::reset();

try {
    // ══ C1 — naming helpers ══════════════════════════════════════════
    $e = [];
    if (DisplayNameClash::suffixed('ACME Transport', 'Vendor') !== 'ACME Transport (Vendor)') { $e[] = 'vendor suffix'; }
    if (DisplayNameClash::suffixed('ACME Transport', 'Customer') !== 'ACME Transport (Customer)') { $e[] = 'customer suffix'; }
    $long = DisplayNameClash::suffixed(str_repeat('Z', 120), 'Vendor');
    if (mb_strlen($long) !== DisplayNameClash::MAX_LEN || !str_ends_with($long, ' (Vendor)')) { $e[] = 'long name ' . mb_strlen($long); }
    if (DisplayNameClash::escape("O'Brien \\ Sons") !== "O\\'Brien \\\\ Sons") { $e[] = 'escape ' . DisplayNameClash::escape("O'Brien \\ Sons"); }
    ff_nc_check('C1', 'suffix per kind, long names trimmed to fit, query escaping', $e);

    // ── Fixture QuickBooks ────────────────────────────────────────────
    ff_nc_set('quickbooks.environment', 'sandbox');
    ff_nc_set('quickbooks.realm_id', QboFixture::REALM_SENTINEL);
    ff_nc_set('quickbooks.realm_mismatch', '0');
    ff_nc_set('quickbooks.fixture_mode', '1');
    ff_nc_set('quickbooks.cutover_at', '');
    ff_nc_set('quickbooks.sync_mode.vendor', 'sync');
    ff_nc_set('quickbooks.sync_mode.customer', 'sync');
    foreach ([999900 => 'Smoke Clash Hauling', 999901 => 'Smoke Clash Taken', 999902 => 'Smoke Clash Twice', 999904 => 'Smoke Clash Lookup', 999905 => 'Smoke Clash Other'] as $id => $name) {
        db_execute("INSERT INTO vendors (id, name, vendor_type, created_at) VALUES (?, ?, 'other', NOW())", [$id, $name]);
    }
    db_execute("INSERT INTO customers (id, company_name, currency, created_at) VALUES (999903, 'Smoke Clash Customer', 'CAD', NOW())");
    QboFixture::cannedGet('query:Vendor', ['QueryResponse' => []]);
    QboFixture::cannedGet('query:Customer', ['QueryResponse' => []]);

    // ══ C2 — vendor, name held by a non-vendor ═══════════════════════
    $e = [];
    QboFixture::injectError('vendor:create', 400, ff_nc_6240());
    $log0 = ff_nc_log_max();
    $r = VendorPusher::pushCreate(999900);
    $posts = ff_nc_posts('vendor', $log0);
    if (empty($r['success']) || ($r['renamed_to'] ?? '') !== 'Smoke Clash Hauling (Vendor)') { $e[] = 'result ' . json_encode($r); }
    $second = $posts[1] ?? [];
    if (count($posts) !== 2 || ($posts[0]['DisplayName'] ?? '') !== 'Smoke Clash Hauling' || ($second['DisplayName'] ?? '') !== 'Smoke Clash Hauling (Vendor)') { $e[] = 'creates ' . json_encode(array_column($posts, 'DisplayName')); }
    if (($second['CompanyName'] ?? '') !== 'Smoke Clash Hauling' || ($second['PrintOnCheckName'] ?? '') !== 'Smoke Clash Hauling') { $e[] = 'company/cheque name ' . json_encode([$second['CompanyName'] ?? null, $second['PrintOnCheckName'] ?? null]); }
    $map = db_row("SELECT mapping_status, qbo_display_name, match_notes FROM acc_qbo_vendor_map WHERE ff_vendor_id = 999900");
    if (($map['mapping_status'] ?? '') !== 'mapped' || ($map['qbo_display_name'] ?? '') !== 'Smoke Clash Hauling (Vendor)' || !str_contains((string) ($map['match_notes'] ?? ''), 'S-QBO-NAME-CLASH')) { $e[] = 'map ' . json_encode($map); }
    if (!db_row("SELECT id FROM audit_log WHERE entity_type = 'vendor' AND entity_id = 999900 AND notes LIKE '%S-QBO-NAME-CLASH%'")) { $e[] = 'no audit row'; }
    if ((db_row("SELECT name FROM vendors WHERE id = 999900")['name'] ?? '') !== 'Smoke Clash Hauling') { $e[] = 'FF vendor renamed'; }
    ff_nc_check('C2', 'vendor name held by a customer/employee → created as "X (Vendor)"; company + cheque name kept; rename noted', $e);

    // ══ C3 — an inactive vendor already has the name ═════════════════
    $e = [];
    QboFixture::cannedGet('query:Vendor', ['QueryResponse' => ['Vendor' => [['Id' => '77', 'DisplayName' => 'smoke clash taken', 'Active' => false]]]]);
    QboFixture::injectError('vendor:create', 400, ff_nc_6240());
    $log0 = ff_nc_log_max();
    $r = VendorPusher::pushCreate(999901);
    if (!empty($r['success']) || ($r['status'] ?? '') !== 'duplicate_name' || !str_contains((string) ($r['error'] ?? ''), 'INACTIVE vendor') || !str_contains((string) ($r['error'] ?? ''), 'Link this FleetForge vendor')) { $e[] = 'result ' . json_encode($r); }
    if (count(ff_nc_posts('vendor', $log0)) !== 1) { $e[] = 'retried a same-kind clash'; }
    if ((db_row("SELECT push_status FROM acc_qbo_vendor_map WHERE ff_vendor_id = 999901")['push_status'] ?? '') !== 'failed') { $e[] = 'map not failed'; }
    QboFixture::cannedGet('query:Vendor', ['QueryResponse' => []]);
    ff_nc_check('C3', 'a vendor (even inactive) already holds the name → refused with "link it", never a duplicate', $e);

    // ══ C4 — both names taken ════════════════════════════════════════
    $e = [];
    QboFixture::injectError('vendor:create', 400, ff_nc_6240());
    QboFixture::injectError('vendor:create', 400, ff_nc_6240());
    $r = VendorPusher::pushCreate(999902);
    if (($r['status'] ?? '') !== 'duplicate_name' || !str_contains((string) ($r['error'] ?? ''), 'Both "Smoke Clash Twice" and "Smoke Clash Twice (Vendor)" are taken')) { $e[] = 'result ' . json_encode($r); }
    ff_nc_check('C4', 'both "X" and "X (Vendor)" taken → refused with a clear message', $e);

    // ══ C5 — customer ════════════════════════════════════════════════
    $e = [];
    QboFixture::injectError('customer:create', 400, ff_nc_6240());
    $log0 = ff_nc_log_max();
    $r = CustomerPusher::pushCreate(999903);
    $posts = ff_nc_posts('customer', $log0);
    if (empty($r['success']) || ($r['renamed_to'] ?? '') !== 'Smoke Clash Customer (Customer)') { $e[] = 'result ' . json_encode($r); }
    if (($posts[1]['DisplayName'] ?? '') !== 'Smoke Clash Customer (Customer)' || ($posts[1]['CompanyName'] ?? '') !== 'Smoke Clash Customer') { $e[] = 'creates ' . json_encode(array_column($posts, 'DisplayName')); }
    if (!str_contains((string) (db_row("SELECT match_notes FROM acc_qbo_customer_map WHERE ff_customer_id = 999903")['match_notes'] ?? ''), 'a vendor or employee')) { $e[] = 'customer note'; }
    ff_nc_check('C5', 'customer name held by a vendor/employee → created as "X (Customer)"', $e);

    // ══ C6 — lookup fails ════════════════════════════════════════════
    $e = [];
    QboFixture::injectError('vendor:create', 400, ff_nc_6240());
    // A 400 (not retried by the client — a 5xx would be retried and succeed).
    QboFixture::injectError('vendor:name_clash_lookup', 400, ['Fault' => ['Error' => [['Message' => 'Invalid query', 'code' => '4000']], 'type' => 'ValidationFault']]);
    $log0 = ff_nc_log_max();
    $r = VendorPusher::pushCreate(999904);
    if (!empty($r['success']) || !str_contains((string) ($r['error'] ?? ''), 'could not check')) { $e[] = 'result ' . json_encode($r); }
    if (count(ff_nc_posts('vendor', $log0)) !== 1) { $e[] = 'created blindly after a failed lookup'; }
    ff_nc_check('C6', 'who-holds-the-name lookup fails → refused, no blind rename', $e);

    // ══ C7 — other errors untouched ══════════════════════════════════
    $e = [];
    QboFixture::injectError('vendor:create', 400, ['Fault' => ['Error' => [['Message' => 'Invalid address', 'code' => '6000']], 'type' => 'ValidationFault']]);
    $log0 = ff_nc_log_max();
    $r = VendorPusher::pushCreate(999905);
    if (!empty($r['success']) || ($r['status'] ?? '') !== 'qbo_error' || isset($r['renamed_to'])) { $e[] = 'result ' . json_encode($r); }
    if (count(ff_nc_posts('vendor', $log0)) !== 1) { $e[] = 'retried a non-6240 error'; }
    ff_nc_check('C7', 'any other create error is reported as before, no retry', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    QboFixture::reset();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_name_clash_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
