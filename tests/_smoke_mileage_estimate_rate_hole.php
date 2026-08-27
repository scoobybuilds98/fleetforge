<?php
declare(strict_types=1);

/**
 * tests/_smoke_mileage_estimate_rate_hole.php
 *
 * S-MILEAGE-EST-RATE-HOLE — PROVE the D133 "mileage intent ⇒ mileage rate > 0"
 * invariant now holds on EVERY write path, not just lease create, and that a
 * lease already in the holed state fails LEGIBLY instead of as a 500.
 *
 * The hole (Sentry FLEETFORGE-1E, lease #528): create.php enforced D133, but
 * api/v1/leases/update.php let an operator add estimated_mileage_per_day to a
 * rate-0 lease (rate columns are blocked there and route to amend_rate.php),
 * and amend_rate.php let an amendment zero the mileage rate out from under a
 * lease that still had an estimate. Either way InvoiceGenerator's D-B guard
 * then refused to bill the lease AT ALL — every manual invoice, close invoice
 * and cron run threw BillingRateException. On the manual path that surfaced as
 * a 500 + one Sentry issue per attempt behind "An unexpected error occurred".
 *
 * Scenarios (real endpoints via CLI harness; committed fixtures cleaned up):
 *   A1  create with a per-day estimate and no rate → 422 (pre-existing D133).
 *   B1  update: adding a per-day estimate to a rate-0 lease → 422, field-keyed.
 *   B2  update: an UNRELATED edit on an already-holed lease still saves (200) —
 *       the gate must not trap an operator inside a lease it can't fix.
 *   B3  update: clearing the estimate back to 0 on a holed lease saves (200) —
 *       one of the two documented ways out.
 *   C1  update: the same per-day estimate on a lease that HAS a rate → 200
 *       (no false positive).
 *   C2  update: estimated_engine_hours_per_day alone on a lease with an
 *       hourly_rate → 200. The hours gate read $existing['hourly_rate'] while
 *       the SELECT never fetched it, so it rejected EVERY hours-only edit.
 *   D1  amend_rate: zeroing mileage_rate_km under a live estimate → 422.
 *   D2  amend_rate: a daily-rate-only amendment on an ALREADY-holed lease still
 *       succeeds (200) — the gate is scoped to amendments touching that rate.
 *   E1  invoices/create on a holed lease (planted by direct SQL, exactly how
 *       #528 exists) → 422 BILLING_RATE_INCOMPLETE, not a 500, and no invoice.
 *   F1  leases/close on that same lease → 422 BILLING_RATE_INCOMPLETE and the
 *       lease still ACTIVE. The close-time final invoice runs the same engine,
 *       so a holed lease could be neither invoiced NOR closed — #528 hit both.
 *
 * Run: php tests/_smoke_mileage_estimate_rate_hole.php
 *
 * @session S-MILEAGE-EST-RATE-HOLE
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-MILEAGE-EST-RATE-HOLE — D133 holds on update / amend / bill\n" . str_repeat('=', 72) . "\n";

$ROOT = FF_ROOT; $PID = getmypid();

// One CLI harness that runs a real endpoint with a mocked super_admin session.
// (Same shape as tests/_smoke_mileage_estimate_rate_from_card.php.)
$harness = sys_get_temp_dir() . "/_ff_hole_{$PID}.php";
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$method = \$argv[1] ?? 'GET';
\$target = \$argv[2] ?? '';
\$payload = base64_decode(\$argv[3] ?? '');
\$sess    = json_decode(base64_decode(\$argv[4] ?? ''), true);
class FfHoleIn { public \$context; private static string \$b=''; private int \$p=0;
  public static function set(\$s){ self::\$b=\$s; }
  public function stream_open(\$a,\$b,\$c,&\$d){ \$this->p=0; return true; }
  public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
  public function stream_eof(){ return \$this->p>=strlen(self::\$b); }
  public function stream_stat(){ return []; } public function stream_seek(\$o,\$w){ \$this->p=\$o; return true; }
  public function stream_tell(){ return \$this->p; } }
FfHoleIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfHoleIn');
\$_SERVER['REQUEST_METHOD']=\$method; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-HOLE/1.0';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$target;
PHP);

$admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
$sess  = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

$call = function (string $target, array $body = []) use ($harness, $sess) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
         . escapeshellarg('POST') . ' ' . escapeshellarg($target) . ' '
         . escapeshellarg(base64_encode(json_encode($body))) . ' '
         . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null';
    $out = shell_exec($cmd); $s = is_string($out) ? strpos($out, '{"') : false;
    if ($s !== false) $out = substr($out, $s);
    return json_decode(trim((string) $out), true) ?: ['_raw' => $out];
};

// Optimistic-lock token for update.php / amend_rate.php.
$stamp = fn(int $lid): string => (string) db_row("SELECT updated_at FROM leases WHERE id=?", [$lid])['updated_at'];

$cleanup = ['leases' => [], 'customers' => [], 'units' => []];
try {
    $user = (int) $admin['id'];
    $tmpl = db_row("SELECT id, category FROM equipment_templates WHERE deleted_at IS NULL AND category IS NOT NULL ORDER BY id LIMIT 1");
    $tmplId = (int) $tmpl['id'];

    $freeUnits = array_column(db_select(
        "SELECT id FROM equipment_units WHERE deleted_at IS NULL AND status='available' AND id NOT IN (
            SELECT equipment_unit_id FROM leases WHERE status IN ('active','pending') AND deleted_at IS NULL AND equipment_unit_id IS NOT NULL
         ) ORDER BY id LIMIT 5", []), 'id');
    if (count($freeUnits) < 5) throw new RuntimeException('need 5 free equipment units, found ' . count($freeUnits));

    // Reserve a block of invoice numbers so E1's attempt can't collide.
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 60)]);

    $cust = db_insert('customers', [
        'contact_name' => 'Hole ' . $PID, 'company_name' => 'HOLE-' . $PID,
        'email' => "hole-{$PID}@example.test", 'currency' => 'CAD',
    ]);
    $cleanup['customers'][] = $cust;

    // Rental tiers are always complete (D132); only the MILEAGE rate varies.
    // $freeUnits BY REFERENCE — each lease must consume a different unit.
    $mkLease = function (array $extra) use ($call, $cust, $tmplId, &$freeUnits, &$cleanup) {
        $unit = (int) array_shift($freeUnits);
        $r = $call('api/v1/leases/create.php', $extra + [
            'customer_id' => $cust, 'equipment_unit_id' => $unit,
            'equipment_template_id' => $tmplId,
            'start_date' => '2026-05-01', 'start_time' => '09:00',
            'billing_cycle' => 'monthly', 'currency' => 'CAD',
            'mileage_tracking_mode' => 'manual',
            'daily_rate' => '80.00', 'weekly_rate' => '480.00', 'monthly_rate' => '1800.00',
            'mileage_unit' => 'miles', 'mileage_rate' => '0',
        ]);
        $lid = (int) ($r['data']['id'] ?? 0);
        if ($lid) { $cleanup['leases'][] = $lid; $cleanup['units'][] = $unit; }
        return [$lid, $r];
    };

    // ── A1: create-time D133 (regression anchor for the primary gate) ───────
    [$lidA, $rA] = $mkLease(['estimated_mileage_per_day' => '100']);
    ck('A1', $lidA === 0 && ($rA['error']['code'] ?? '') === 'VALIDATION_ERROR'
            && isset($rA['error']['fields']['mileage_rate']),
        'create with 100 mi/day + no rate → ' . ($rA['error']['code'] ?? 'CREATED') . ' (expect VALIDATION_ERROR on mileage_rate)');

    // ── B: the edit path — a rate-0 lease with NO estimate is legitimate ────
    [$lidB, $rB] = $mkLease([]);
    if (!$lidB) throw new RuntimeException('B fixture lease not created: ' . json_encode($rB));

    $b1 = $call('api/v1/leases/update.php', [
        'id' => $lidB, 'updated_at' => $stamp($lidB), 'estimated_mileage_per_day' => '100',
    ]);
    $b1Row = db_row("SELECT estimated_mileage_per_day FROM leases WHERE id=?", [$lidB]);
    ck('B1', ($b1['error']['code'] ?? '') === 'VALIDATION_ERROR'
            && isset($b1['error']['fields']['estimated_mileage_per_day'])
            && bccomp((string) $b1Row['estimated_mileage_per_day'], '0', 2) === 0,
        'update adding 100 mi/day to a rate-0 lease → ' . ($b1['error']['code'] ?? 'SAVED')
        . ', stored per_day=' . $b1Row['estimated_mileage_per_day'] . ' (expect VALIDATION_ERROR + unchanged 0.00)');

    // Plant the holed state the old code allowed (this is lease #528's shape).
    db_execute("UPDATE leases SET estimated_mileage_per_day='100.00',
                    estimated_mileage_per_day_miles='100.0000',
                    estimated_mileage_per_day_km='160.9344', updated_at=NOW() WHERE id=?", [$lidB]);

    $b2 = $call('api/v1/leases/update.php', [
        'id' => $lidB, 'updated_at' => $stamp($lidB), 'notes' => 'unrelated edit ' . $PID,
    ]);
    ck('B2', ($b2['success'] ?? false) === true,
        'unrelated edit on an already-holed lease → ' . (($b2['success'] ?? false) ? 'saved' : ('BLOCKED: ' . json_encode($b2['error'] ?? $b2))));

    $b3 = $call('api/v1/leases/update.php', [
        'id' => $lidB, 'updated_at' => $stamp($lidB), 'estimated_mileage_per_day' => '0',
    ]);
    $b3Row = db_row("SELECT estimated_mileage_per_day FROM leases WHERE id=?", [$lidB]);
    ck('B3', ($b3['success'] ?? false) === true
            && bccomp((string) $b3Row['estimated_mileage_per_day'], '0', 2) === 0,
        'clearing the estimate on a holed lease → ' . (($b3['success'] ?? false) ? 'saved, per_day=' . $b3Row['estimated_mileage_per_day'] : ('BLOCKED: ' . json_encode($b3['error'] ?? $b3))));

    // ── C: no false positives on leases that DO carry the matching rate ─────
    [$lidC, $rC] = $mkLease(['mileage_rate' => '1.00', 'hourly_rate' => '25.00']);
    if (!$lidC) throw new RuntimeException('C fixture lease not created: ' . json_encode($rC));

    $c1 = $call('api/v1/leases/update.php', [
        'id' => $lidC, 'updated_at' => $stamp($lidC), 'estimated_mileage_per_day' => '100',
    ]);
    $c1Row = db_row("SELECT estimated_mileage_per_day_km FROM leases WHERE id=?", [$lidC]);
    ck('C1', ($c1['success'] ?? false) === true
            && bccomp((string) $c1Row['estimated_mileage_per_day_km'], '160.9344', 4) === 0,
        '100 mi/day on a $1.00/mi lease → ' . (($c1['success'] ?? false) ? 'saved, per_day_km=' . $c1Row['estimated_mileage_per_day_km'] : ('BLOCKED: ' . json_encode($c1['error'] ?? $c1))));

    $c2 = $call('api/v1/leases/update.php', [
        'id' => $lidC, 'updated_at' => $stamp($lidC), 'estimated_engine_hours_per_day' => '8',
    ]);
    $c2Row = db_row("SELECT estimated_engine_hours_per_day FROM leases WHERE id=?", [$lidC]);
    ck('C2', ($c2['success'] ?? false) === true
            && bccomp((string) $c2Row['estimated_engine_hours_per_day'], '8', 2) === 0,
        'hours-only edit on a lease with hourly_rate=25.00 → ' . (($c2['success'] ?? false) ? 'saved, hrs/day=' . $c2Row['estimated_engine_hours_per_day'] : ('BLOCKED: ' . json_encode($c2['error'] ?? $c2))));

    // ── D: the rate side — an amendment must not zero the rate out ──────────
    $d1 = $call('api/v1/leases/amend_rate.php', [
        'lease_id' => $lidC, 'updated_at' => $stamp($lidC),
        'new_mileage_rate_miles' => '0', 'reason' => 'smoke: zero the rate under a live estimate',
    ]);
    $d1Row = db_row("SELECT mileage_rate_km FROM leases WHERE id=?", [$lidC]);
    ck('D1', ($d1['error']['code'] ?? '') === 'RATE_TIER_INCOMPLETE'
            && bccomp((string) $d1Row['mileage_rate_km'], '0', 4) > 0,
        'amend mileage rate → 0 under a 100 mi/day estimate → ' . ($d1['error']['code'] ?? 'APPLIED')
        . ', rate_km still ' . $d1Row['mileage_rate_km'] . ' (expect RATE_TIER_INCOMPLETE + untouched)');

    $d2 = $call('api/v1/leases/amend_rate.php', [
        'lease_id' => $lidB, 'updated_at' => $stamp($lidB),
        'new_daily_rate' => '90.00', 'reason' => 'smoke: unrelated tier amendment',
    ]);
    ck('D2', ($d2['success'] ?? false) === true,
        'daily-rate amendment on the rate-0 lease → ' . (($d2['success'] ?? false) ? 'applied' : ('BLOCKED: ' . json_encode($d2['error'] ?? $d2))));

    // ── E1: billing a lease already in the holed state (lease #528) ─────────
    [$lidE, $rE] = $mkLease([]);
    if (!$lidE) throw new RuntimeException('E fixture lease not created: ' . json_encode($rE));
    db_execute("UPDATE leases SET status='active', estimated_mileage_per_day='100.00',
                    estimated_mileage_per_day_miles='100.0000',
                    estimated_mileage_per_day_km='160.9344', updated_at=NOW() WHERE id=?", [$lidE]);

    $e1 = $call('api/v1/invoices/create.php', [
        'lease_id' => $lidE, 'period_start' => '2026-08-01', 'period_end' => '2026-08-25',
        'billing_type' => 'partial_start', 'invoice_type' => 'regular',
    ]);
    $e1Count = (int) db_row("SELECT COUNT(*) n FROM invoices WHERE lease_id=?", [$lidE])['n'];
    ck('E1', ($e1['error']['code'] ?? '') === 'BILLING_RATE_INCOMPLETE' && $e1Count === 0,
        'invoicing a holed lease → ' . ($e1['error']['code'] ?? 'CREATED') . ", invoices={$e1Count} "
        . '(expect BILLING_RATE_INCOMPLETE 422, 0 invoices — was INTERNAL_ERROR 500 + a Sentry issue)');

    // ── F1: and it can't be CLOSED either — same engine, same guard ────────
    $f1 = $call('api/v1/leases/close.php', [
        'id' => $lidE, 'actual_return_date' => '2026-08-25',
    ]);
    $f1Status = (string) db_row("SELECT status FROM leases WHERE id=?", [$lidE])['status'];
    ck('F1', ($f1['error']['code'] ?? '') === 'BILLING_RATE_INCOMPLETE' && $f1Status === 'active',
        'closing a holed lease → ' . ($f1['error']['code'] ?? 'CLOSED') . ", status={$f1Status} "
        . '(expect BILLING_RATE_INCOMPLETE 422, lease untouched — was INTERNAL_ERROR 500)');

} catch (\Throwable $e) {
    ck('FATAL', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($cleanup['leases'] as $id) {
        db_execute("DELETE FROM invoice_line_items WHERE invoice_id IN (SELECT id FROM invoices WHERE lease_id=?)", [$id]);
        db_execute("DELETE FROM invoices WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM lease_billing_periods WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM lease_amendments WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM lease_status_log WHERE lease_id=?", [$id]);
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$id]);
        db_execute("DELETE FROM leases WHERE id=?", [$id]);
    }
    foreach (array_unique($cleanup['units']) as $uid) db_execute("UPDATE equipment_units SET status='available' WHERE id=?", [$uid]);
    foreach ($cleanup['customers'] as $id) db_execute("DELETE FROM customers WHERE id=?", [$id]);
    @unlink($harness);
}

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — D133 holds on create, update, amend, and billing\033[0m\n";
exit(0);
