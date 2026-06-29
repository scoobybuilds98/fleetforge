<?php
declare(strict_types=1);

/**
 * tests/_smoke_mileage_unit_billing.php
 *
 * S-MILEAGE-RATE-CONVERT-FIX — per-mile leases.
 *
 * TWO bugs fixed:
 *   A. CHARGE: lease create derived mileage_rate_km = mileage_rate × milesToKm
 *      (1.609) — the INVERSE of the correct direction. A rate scales opposite to a
 *      distance, so $/km = $/mile × kmToMiles (0.621). The modern `mileage_usage`
 *      path (km_distance × mileage_rate_km) therefore OVER-charged miles leases by
 *      milesToKm² ≈ 2.59×. Now mileage_rate_km = mileage_rate × kmToMiles.
 *   B. DISPLAY: every mileage line hardcoded "km × $…/km". Now rendered in the
 *      lease's unit via ff_mileage_line_display() → "{miles} miles × ${…}/mile".
 *
 *   T1  MILES lease, modern path: 291 km driven, rate_km 0.0559 → bills $16.27
 *       (= 180.82 mi × $0.09 = 291 km × $0.0559), NOT the 2.59× ($42.14). unit=miles,
 *       description reads "… miles × $0.0900/mile".
 *   T2  KM lease, modern path: 100 km × $0.10 → $10.00, unit=km, "… km × $0.1000/km".
 *   T3  (real create endpoint) MILES lease rate $0.09/mi → stored mileage_rate_km
 *       = 0.0559 (NOT 0.1448); KM lease $0.10/km → mileage_rate_miles = 0.1609.
 *
 * T1/T2 hermetic (BEGIN/ROLLBACK). T3 drives the real create endpoint (committed
 * fixture cleaned up). Run: php tests/_smoke_mileage_unit_billing.php
 *
 * @session S-MILEAGE-RATE-CONVERT-FIX
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

echo str_repeat('=', 72) . "\nS-MILEAGE-RATE-CONVERT-FIX — per-mile billing + label\n" . str_repeat('=', 72) . "\n";

// ── T1 / T2 — engine billing (hermetic) ──────────────────────────────────
db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) throw new RuntimeException("missing seed (cust=$cust unit=$unit user=$user)");

    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 80)]);

    $mlSeq = 0;
    $makeLease = function (string $munit, string $rate, string $rateKm) use ($cust, $unit, $user, &$mlSeq): int {
        $mlSeq++;
        return db_insert('leases', [
            'contract_number' => 'SMOKE-MIU-' . $munit . '-' . getmypid() . '-' . $mlSeq,
            'customer_id' => $cust, 'equipment_unit_id' => $unit,
            'start_date' => '2026-05-01', 'status' => 'active',
            'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
            'currency' => 'CAD', 'billing_cycle' => 'monthly',
            'mileage_unit' => $munit, 'mileage_rate' => $rate, 'mileage_rate_km' => $rateKm,
            'mileage_tracking_mode' => 'samsara', 'precharge_enabled' => 0,
            'estimated_mileage_km' => '0.000', 'estimated_mileage' => '0',
            'km_to_miles_conversion' => '0.621371', 'miles_to_km_conversion' => '1.609344',
            'created_by' => $user, 'updated_by' => $user,
        ]);
    };
    $gen = new InvoiceGenerator();
    $mLine = fn (int $iv) => db_row("SELECT amount, quantity, unit, unit_price, description FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$iv]);
    $gen2 = function (int $lid, string $odoStart, string $odoEnd) use ($gen, $user) {
        return $gen->createFromLease([
            'lease_id' => $lid, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
            'odometer_at_period_start_km' => $odoStart, 'odometer_at_period_end_km' => $odoEnd,
        ]);
    };

    // T1 — MILES: rate 0.09/mile, correct rate_km 0.0559, 291 km driven.
    $l1 = $makeLease('miles', '0.0900', '0.0559');
    $iv1 = $gen2($l1, '1000.00', '1291.00'); // 291 km
    $ln1 = $mLine($iv1['invoice_id']);
    ck('T1a', $ln1 !== null && $ln1['amount'] === '16.27',
        "miles charge = 291km×\$0.0559 = \$" . ($ln1['amount'] ?? 'none') . " (correct \$16.27; the bug billed ~\$42.14)");
    ck('T1b', $ln1 !== null && $ln1['unit'] === 'miles' && bccomp((string) $ln1['unit_price'], '0.0900', 4) === 0,
        "line unit='" . ($ln1['unit'] ?? '?') . "', unit_price=" . ($ln1['unit_price'] ?? '?') . " (expect miles / 0.09)");
    ck('T1c', $ln1 !== null && str_contains((string) $ln1['description'], 'miles ×') && str_contains((string) $ln1['description'], '/mile'),
        "description: " . ($ln1['description'] ?? 'none'));
    ck('T1d', $ln1 !== null && bccomp(bcround(bcmul((string) $ln1['quantity'], (string) $ln1['unit_price'], 6), 2), (string) $ln1['amount'], 2) === 0,
        "qty×price == amount (QBO line invariant): " . $ln1['quantity'] . "×" . $ln1['unit_price'] . " vs " . $ln1['amount']);

    // T1e — the reviewer's divergence case: $1.00/mile, 1609.344 km (= 1000 miles).
    // Pre-fix amount (km×rate_km) would be $1000.05 while qty×price=$1000.00 (QBO
    // invariant broken); now amount is the per-mile-canonical $1000.00 == qty×price.
    $l1e = $makeLease('miles', '1.0000', bcround(bcmul('1.0000', '0.621371', 8), 4)); // rate_km 0.6214
    $iv1e = $gen2($l1e, '0.000', '1609.344');
    $ln1e = $mLine($iv1e['invoice_id']);
    ck('T1e', $ln1e !== null
            && bccomp((string) $ln1e['amount'], '1000.00', 2) === 0
            && bccomp(bcround(bcmul((string) $ln1e['quantity'], (string) $ln1e['unit_price'], 6), 2), (string) $ln1e['amount'], 2) === 0,
        "1000mi×\$1.00 → amount=" . ($ln1e['amount'] ?? 'none') . " (expect 1000.00, qty×price consistent; pre-fix was 1000.05≠1000.00)");

    // T2 — KM: rate 0.10/km, 100 km.
    $l2 = $makeLease('km', '0.1000', '0.1000');
    $iv2 = $gen2($l2, '0.00', '100.00');
    $ln2 = $mLine($iv2['invoice_id']);
    ck('T2a', $ln2 !== null && $ln2['amount'] === '10.00', "km charge = 100×\$0.10 = \$" . ($ln2['amount'] ?? 'none'));
    ck('T2b', $ln2 !== null && $ln2['unit'] === 'km' && str_contains((string) $ln2['description'], 'km ×') && str_contains((string) $ln2['description'], '/km'),
        "km line unit/desc: " . ($ln2['description'] ?? 'none'));

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    ck('T1/T2', false, 'FATAL ' . $e->getMessage());
}

// ── T3 — create.php derivation via the REAL endpoint ──────────────────────
$ROOT = dirname(__DIR__); $PID = getmypid();
$harness = sys_get_temp_dir() . "/_ff_miu_{$PID}.php";
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$payload = base64_decode(\$argv[1] ?? ''); \$sess = json_decode(base64_decode(\$argv[2] ?? ''), true);
class FfMiuIn { public \$context; private static string \$b=''; private int \$p=0;
  public static function set(\$s){ self::\$b=\$s; }
  public function stream_open(\$a,\$b,\$c,&\$d){ \$this->p=0; return true; }
  public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
  public function stream_eof(){ return \$this->p>=strlen(self::\$b); }
  public function stream_stat(){ return []; } public function stream_seek(\$o,\$w){ \$this->p=\$o; return true; }
  public function stream_tell(){ return \$this->p; } }
FfMiuIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfMiuIn');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-MIU/1.0';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/api/v1/leases/create.php';
PHP);

$createdLeaseIds = [];
try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    $sess = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];
    $cust = (int) db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'];
    // Two distinct units with NO active/pending lease (create rejects an occupied unit).
    $freeUnits = array_column(db_select(
        "SELECT id FROM equipment_units WHERE deleted_at IS NULL AND status='available' AND id NOT IN (
            SELECT equipment_unit_id FROM leases WHERE status IN ('active','pending') AND deleted_at IS NULL AND equipment_unit_id IS NOT NULL
         ) ORDER BY id LIMIT 2", []), 'id');
    if (count($freeUnits) < 2) throw new RuntimeException('need 2 free equipment units, found ' . count($freeUnits));
    $post = function (array $p) use ($harness, $sess) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
             . escapeshellarg(base64_encode(json_encode($p))) . ' '
             . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null';
        $out = shell_exec($cmd); $s = is_string($out) ? strpos($out, '{"') : false;
        if ($s !== false) $out = substr($out, $s);
        return json_decode(trim((string) $out), true) ?: ['_raw' => $out];
    };
    $base = [
        'customer_id' => $cust,
        'start_date' => '2026-05-01', 'start_time' => '09:00',
        'daily_rate' => '50.00', 'weekly_rate' => '300.00', 'monthly_rate' => '1000.00',
        'billing_cycle' => 'monthly', 'currency' => 'CAD', 'mileage_tracking_mode' => 'samsara',
    ];
    // miles lease
    $r = $post($base + ['equipment_unit_id' => (int) $freeUnits[0], 'mileage_unit' => 'miles', 'mileage_rate' => '0.0900']);
    $lid = (int) ($r['data']['id'] ?? $r['data']['lease_id'] ?? 0);
    if ($lid) $createdLeaseIds[] = $lid;
    $rk = $lid ? (string) db_row("SELECT mileage_rate_km FROM leases WHERE id=?", [$lid])['mileage_rate_km'] : 'none';
    ck('T3a', $lid && bccomp($rk, '0.0559', 4) === 0,
        "create MILES \$0.09/mi → mileage_rate_km=$rk (expect 0.0559, NOT the inverted 0.1448). resp=" . json_encode($r['error'] ?? ($r['success'] ?? $r)));
    // km lease (second free unit)
    $r2 = $post($base + ['equipment_unit_id' => (int) $freeUnits[1], 'mileage_unit' => 'km', 'mileage_rate' => '0.1000']);
    $lid2 = (int) ($r2['data']['id'] ?? $r2['data']['lease_id'] ?? 0);
    if ($lid2) $createdLeaseIds[] = $lid2;
    $rm = $lid2 ? (string) db_row("SELECT mileage_rate_miles FROM leases WHERE id=?", [$lid2])['mileage_rate_miles'] : 'none';
    ck('T3b', $lid2 && bccomp($rm, '0.1609', 4) === 0,
        "create KM \$0.10/km → mileage_rate_miles=$rm (expect 0.1609). resp=" . json_encode($r2['error'] ?? ($r2['success'] ?? $r2)));
} catch (\Throwable $e) {
    ck('T3', false, 'FATAL ' . $e->getMessage());
} finally {
    foreach ($createdLeaseIds as $lid) db_execute("DELETE FROM leases WHERE id=?", [$lid]);
    // Reset the unit status the create endpoint flipped to on_lease (the lease-row
    // delete above doesn't), so re-runs find the units 'available' again.
    if (!empty($freeUnits)) {
        $ph = implode(',', array_fill(0, count($freeUnits), '?'));
        db_execute("UPDATE equipment_units SET status='available' WHERE id IN ({$ph})", $freeUnits);
    }
    @unlink($harness);
}

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — per-mile leases bill miles×\$/mile + label correctly\033[0m\n";
exit(0);
