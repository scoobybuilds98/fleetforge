<?php
declare(strict_types=1);

/**
 * tests/_smoke_mileage_estimate_rate_from_card.php
 *
 * S-MILEAGE-EST-DAILY — PROVE the estimated-daily-mileage rate is sourced from
 * the CUSTOMER RATE CARD, end-to-end:
 *
 *   rate_card_items.mileage_rate
 *        → api/v1/leases/lookup_rates.php   (the "grab": customer card > global > template)
 *        → the create form applies d.mileage_rate + d.mileage_unit to the payload
 *          (JS, app/admin/leases/create.php:1558/1561 — verified by inspection;
 *          this test feeds lookup_rates' EXACT output into the create endpoint,
 *          exactly as the form does)
 *        → api/v1/leases/create.php derives leases.mileage_rate_km
 *        → InvoiceGenerator emits the `mileage_estimate` line at that rate.
 *
 * Scenarios (real endpoints via CLI harness; committed fixtures cleaned up):
 *   G1  Customer-specific card ($0.60/km) → lookup returns 0.60, source=customer.
 *   G2  A GLOBAL card ($0.30/km) also exists → the CUSTOMER card still wins (0.60).
 *   C1  Create an estimate lease (50 km/day) submitting the looked-up rate →
 *       leases.mileage_rate_km == 0.6000 (the card rate reached the lease).
 *   C2  Its first invoice's `mileage_estimate` line prices at $0.60/km
 *       (31 days × 50 km/day × $0.60 = $930.00) — the card rate reached billing.
 *   M1  Per-MILE customer card ($1.00/mile) → lookup returns 1.00 unit=miles →
 *       create derives mileage_rate_km = 1.00 × 0.621371 = $0.6214/km
 *       (a rate converts with the INVERSE distance factor; the miles column is exact).
 *
 * Run: php tests/_smoke_mileage_estimate_rate_from_card.php
 *
 * @session S-MILEAGE-EST-DAILY
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-MILEAGE-EST-DAILY — estimate rate sourced from CUSTOMER RATE CARD\n" . str_repeat('=', 72) . "\n";

$ROOT = FF_ROOT; $PID = getmypid();

// One CLI harness that runs a real endpoint (GET or POST) with a mocked session.
$harness = sys_get_temp_dir() . "/_ff_rfc_{$PID}.php";
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$method = \$argv[1] ?? 'GET';
\$target = \$argv[2] ?? '';
\$payload = base64_decode(\$argv[3] ?? '');           // POST body (JSON)
\$query   = json_decode(base64_decode(\$argv[4] ?? ''), true) ?: [];  // GET params
\$sess    = json_decode(base64_decode(\$argv[5] ?? ''), true);
class FfRfcIn { public \$context; private static string \$b=''; private int \$p=0;
  public static function set(\$s){ self::\$b=\$s; }
  public function stream_open(\$a,\$b,\$c,&\$d){ \$this->p=0; return true; }
  public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
  public function stream_eof(){ return \$this->p>=strlen(self::\$b); }
  public function stream_stat(){ return []; } public function stream_seek(\$o,\$w){ \$this->p=\$o; return true; }
  public function stream_tell(){ return \$this->p; } }
FfRfcIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfRfcIn');
\$_SERVER['REQUEST_METHOD']=\$method; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-RFC/1.0';
\$_GET = \$query;
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$target;
PHP);

$admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
$sess  = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

$call = function (string $method, string $target, array $body = [], array $query = []) use ($harness, $sess) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
         . escapeshellarg($method) . ' ' . escapeshellarg($target) . ' '
         . escapeshellarg(base64_encode(json_encode($body))) . ' '
         . escapeshellarg(base64_encode(json_encode($query))) . ' '
         . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null';
    $out = shell_exec($cmd); $s = is_string($out) ? strpos($out, '{"') : false;
    if ($s !== false) $out = substr($out, $s);
    return json_decode(trim((string) $out), true) ?: ['_raw' => $out];
};

$cleanup = ['leases' => [], 'rate_cards' => [], 'customers' => [], 'units' => []];
try {
    $user = (int) $admin['id'];
    $tmpl = db_row("SELECT id, category FROM equipment_templates WHERE deleted_at IS NULL AND category IS NOT NULL ORDER BY id LIMIT 1");
    $tmplId = (int) $tmpl['id']; $cat = (string) $tmpl['category'];

    $freeUnits = array_column(db_select(
        "SELECT id FROM equipment_units WHERE deleted_at IS NULL AND status='available' AND id NOT IN (
            SELECT equipment_unit_id FROM leases WHERE status IN ('active','pending') AND deleted_at IS NULL AND equipment_unit_id IS NOT NULL
         ) ORDER BY id LIMIT 2", []), 'id');
    if (count($freeUnits) < 2) throw new RuntimeException('need 2 free equipment units, found ' . count($freeUnits));

    // reserve invoice numbers
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 60)]);

    // ── Customer + a customer-specific KM card @ $0.60/km ──────────────────
    $custKm = db_insert('customers', [
        'contact_name' => 'RFC KM ' . $PID, 'company_name' => 'RFC-KM-' . $PID,
        'email' => "rfc-km-{$PID}@example.test", 'currency' => 'CAD',
    ]);
    $cleanup['customers'][] = $custKm;
    $cardCust = db_insert('rate_cards', [
        'name' => 'RFC Customer Card ' . $PID, 'customer_id' => $custKm,
        'is_default' => 0, 'effective_from' => '2020-01-01', 'created_by' => $user,
    ]);
    $cleanup['rate_cards'][] = $cardCust;
    db_insert('rate_card_items', [
        'rate_card_id' => $cardCust, 'equipment_type' => $cat, 'equipment_template_id' => null,
        'daily_rate' => '80.00', 'weekly_rate' => '480.00', 'monthly_rate' => '1800.00',
        'mileage_rate' => '0.6000', 'mileage_unit' => 'km', 'currency' => 'CAD',
    ]);

    // ── G1: lookup grabs the customer card rate ────────────────────────────
    $lk = $call('GET', 'api/v1/leases/lookup_rates.php', [], [
        'customer_id' => $custKm, 'equipment_template_id' => $tmplId,
    ]);
    $lkData = $lk['data'] ?? $lk;
    ck('G1', ($lkData['source'] ?? '') === 'customer'
            && bccomp((string) ($lkData['mileage_rate'] ?? '0'), '0.6000', 4) === 0
            && ($lkData['mileage_unit'] ?? '') === 'km',
        "lookup_rates → mileage_rate=" . ($lkData['mileage_rate'] ?? 'none') . " unit=" . ($lkData['mileage_unit'] ?? '?') . " source=" . ($lkData['source'] ?? '?') . " (expect 0.6000/km/customer)");

    // ── G2: add a GLOBAL card @ $0.30/km — the customer card must still win ─
    $cardGlobal = db_insert('rate_cards', [
        'name' => 'RFC Global Card ' . $PID, 'customer_id' => null,
        'is_default' => 1, 'effective_from' => '2020-01-01', 'created_by' => $user,
    ]);
    $cleanup['rate_cards'][] = $cardGlobal;
    db_insert('rate_card_items', [
        'rate_card_id' => $cardGlobal, 'equipment_type' => $cat, 'equipment_template_id' => null,
        'daily_rate' => '50.00', 'mileage_rate' => '0.3000', 'mileage_unit' => 'km', 'currency' => 'CAD',
    ]);
    $lk2 = $call('GET', 'api/v1/leases/lookup_rates.php', [], [
        'customer_id' => $custKm, 'equipment_template_id' => $tmplId,
    ]);
    $lk2Data = $lk2['data'] ?? $lk2;
    ck('G2', bccomp((string) ($lk2Data['mileage_rate'] ?? '0'), '0.6000', 4) === 0
            && ($lk2Data['source'] ?? '') === 'customer',
        "with a \$0.30 global card present, lookup still returns the customer \$" . ($lk2Data['mileage_rate'] ?? 'none') . " (customer > global)");

    // ── C1/C2: create an estimate lease with the looked-up rate → billing ──
    $base = [
        'customer_id' => $custKm, 'equipment_unit_id' => (int) $freeUnits[0],
        'equipment_template_id' => $tmplId,
        'start_date' => '2026-05-01', 'start_time' => '09:00',
        'billing_cycle' => 'monthly', 'currency' => 'CAD', 'mileage_tracking_mode' => 'manual',
        // exactly what the form submits after _lookupRates():
        'daily_rate' => $lkData['daily_rate'], 'weekly_rate' => $lkData['weekly_rate'],
        'monthly_rate' => $lkData['monthly_rate'],
        'mileage_unit' => $lkData['mileage_unit'], 'mileage_rate' => $lkData['mileage_rate'],
        'estimated_mileage_per_day' => '50',
    ];
    $cr = $call('POST', 'api/v1/leases/create.php', $base);
    $lid = (int) ($cr['data']['id'] ?? 0);
    if ($lid) { $cleanup['leases'][] = $lid; $cleanup['units'][] = (int) $freeUnits[0]; }
    $leaseRow = $lid ? db_row("SELECT mileage_rate_km, estimated_mileage_per_day_km, status FROM leases WHERE id=?", [$lid]) : null;
    ck('C1', $leaseRow !== null && bccomp((string) $leaseRow['mileage_rate_km'], '0.6000', 4) === 0
            && bccomp((string) $leaseRow['estimated_mileage_per_day_km'], '50.0000', 4) === 0,
        "created lease.mileage_rate_km=" . ($leaseRow['mileage_rate_km'] ?? 'none') . " per_day_km=" . ($leaseRow['estimated_mileage_per_day_km'] ?? '?') . " (card \$0.60 reached the lease). resp=" . json_encode($cr['error'] ?? ($cr['success'] ?? null)));

    if ($lid) {
        // Activate so billing has a normal active lease, then bill one month.
        db_execute("UPDATE leases SET status='active' WHERE id=?", [$lid]);
        db_execute("BEGIN");
        $iv = (new InvoiceGenerator())->createFromLease([
            'lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        ]);
        $est = db_row("SELECT amount, unit_price, unit, quantity FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_estimate'", [$iv['invoice_id']]);
        db_execute("ROLLBACK");
        // 31 days × 50 km/day × $0.60 = $930.00, priced at the card's $0.60/km
        ck('C2', $est !== null
                && bccomp((string) $est['unit_price'], '0.6000', 4) === 0
                && bccomp((string) $est['amount'], '930.00', 2) === 0,
            "mileage_estimate priced at \$" . ($est['unit_price'] ?? 'none') . "/km → 31×50×0.60 = \$" . ($est['amount'] ?? '?') . " (expect 0.6000 / 930.00)");
    }

    // ── M1: per-MILE customer card → create derives the km column correctly ─
    $custMi = db_insert('customers', [
        'contact_name' => 'RFC MI ' . $PID, 'company_name' => 'RFC-MI-' . $PID,
        'email' => "rfc-mi-{$PID}@example.test", 'currency' => 'CAD',
    ]);
    $cleanup['customers'][] = $custMi;
    $cardMi = db_insert('rate_cards', [
        'name' => 'RFC Miles Card ' . $PID, 'customer_id' => $custMi,
        'is_default' => 0, 'effective_from' => '2020-01-01', 'created_by' => $user,
    ]);
    $cleanup['rate_cards'][] = $cardMi;
    db_insert('rate_card_items', [
        'rate_card_id' => $cardMi, 'equipment_type' => $cat, 'equipment_template_id' => null,
        'daily_rate' => '80.00', 'monthly_rate' => '1800.00',
        'mileage_rate' => '1.0000', 'mileage_unit' => 'miles', 'currency' => 'CAD',
    ]);
    $lkMi = $call('GET', 'api/v1/leases/lookup_rates.php', [], ['customer_id' => $custMi, 'equipment_template_id' => $tmplId]);
    $lkMiData = $lkMi['data'] ?? $lkMi;
    $baseMi = [
        'customer_id' => $custMi, 'equipment_unit_id' => (int) $freeUnits[1],
        'equipment_template_id' => $tmplId,
        'start_date' => '2026-05-01', 'start_time' => '09:00',
        'billing_cycle' => 'monthly', 'currency' => 'CAD', 'mileage_tracking_mode' => 'manual',
        'daily_rate' => $lkMiData['daily_rate'], 'monthly_rate' => $lkMiData['monthly_rate'],
        // mirror the form's D-D pre-fill: weekly = monthly/4.33 when the card has none.
        'weekly_rate' => $lkMiData['weekly_rate'] ?? number_format(((float) $lkMiData['monthly_rate']) / 4.33, 2, '.', ''),
        'mileage_unit' => $lkMiData['mileage_unit'], 'mileage_rate' => $lkMiData['mileage_rate'],
        'estimated_mileage_per_day' => '30',
    ];
    $crMi = $call('POST', 'api/v1/leases/create.php', $baseMi);
    $lidMi = (int) ($crMi['data']['id'] ?? 0);
    if ($lidMi) { $cleanup['leases'][] = $lidMi; $cleanup['units'][] = (int) $freeUnits[1]; }
    $rowMi = $lidMi ? db_row("SELECT mileage_rate, mileage_rate_km FROM leases WHERE id=?", [$lidMi]) : null;
    // $/km = $/mile × 0.621371 (inverse distance factor)
    ck('M1', $lkMiData['mileage_unit'] === 'miles'
            && bccomp((string) ($lkMiData['mileage_rate'] ?? '0'), '1.0000', 4) === 0
            && $rowMi !== null
            && bccomp((string) $rowMi['mileage_rate'], '1.0000', 4) === 0
            && bccomp((string) $rowMi['mileage_rate_km'], '0.6214', 4) === 0,
        "per-mile card \$1.00/mi → lease mileage_rate=" . ($rowMi['mileage_rate'] ?? '?') . " mileage_rate_km=" . ($rowMi['mileage_rate_km'] ?? 'none') . " (expect 1.0000 & 0.6214)");

} catch (\Throwable $e) {
    ck('FATAL', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($cleanup['leases'] as $id) {
        db_execute("DELETE FROM invoice_line_items WHERE invoice_id IN (SELECT id FROM invoices WHERE lease_id=?)", [$id]);
        db_execute("DELETE FROM invoices WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM lease_status_log WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$id]);
        db_execute("DELETE FROM leases WHERE id=?", [$id]);
    }
    foreach ($cleanup['rate_cards'] as $id) {
        db_execute("DELETE FROM rate_card_items WHERE rate_card_id=?", [$id]);
        db_execute("DELETE FROM rate_cards WHERE id=?", [$id]);
    }
    foreach (array_unique($cleanup['units']) as $uid) db_execute("UPDATE equipment_units SET status='available' WHERE id=?", [$uid]);
    foreach ($cleanup['customers'] as $id) db_execute("DELETE FROM customers WHERE id=?", [$id]);
    @unlink($harness);
}

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — estimate rate is sourced from the customer rate card\033[0m\n";
exit(0);
