<?php
declare(strict_types=1);

/**
 * tests/_smoke_equipment_taxonomy_e2e.php
 *
 * S-EQTAX-10 — END-TO-END proof that rate cards AND the 3-day minimum work for a
 * Combo-style equipment type, including across the new "convert category →
 * sub-category" action (S-EQTAX-9). The real Jete's Lumber scenario:
 *
 *   1. A top-level "combo" category with a template + a global rate card keyed on
 *      its slug. lookup_rates returns that card.                          (rates)
 *   2. Convert that category into a SUB-category under Chassis (real endpoint).
 *   3. The template is re-pointed (category_id=Chassis, mirror slug preserved)
 *      and the source category is retired.                              (convert)
 *   4. lookup_rates STILL returns the same card — rate matching survived.  (rates)
 *   5. A 1-day lease on that unit closes and bills 3 days × the rate-card daily
 *      rate: the minimum BINDS (inherited from Chassis) at the correct rate.
 *                                                              (rates + minimum)
 *
 * Drives the REAL lookup_rates (GET, self child-mode) + convert endpoint (POST
 * harness) + InvoiceGenerator::createFromLease (the real close path). Committed
 * data (subprocesses can't see a transaction) cleaned up in finally by token.
 *
 * Run:  php tests/_smoke_equipment_taxonomy_e2e.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-EQTAX-10
 */

// ── CHILD MODE: run lookup_rates with a super_admin session ──────────────────
if (($argv[1] ?? '') === 'lookup' && ctype_digit($argv[2] ?? '') && ctype_digit($argv[3] ?? '')) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET = ['customer_id' => (int) $argv[2], 'equipment_template_id' => (int) $argv[3]];
    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = ['id' => 1, 'name' => 'Smoke', 'email' => 's@f.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark'];
    $_SESSION['ff_last_activity'] = time();
    require FF_ROOT . '/api/v1/leases/lookup_rates.php';
    exit(0);
}

// ── PARENT MODE ──────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\Fixtures;

$ROOT = dirname(__DIR__);
$PID  = getmypid();
$TOKEN = '__e2e_eqtax_' . $PID . '__';
$SLUG  = 'e2e_combo_' . $PID;            // unique → no collision with seed rate cards
$self  = __FILE__;

$passes = 0; $failures = [];
$pass = function (string $m) use (&$passes) { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = function (string $m) use (&$failures) { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

// lookup_rates via self child-mode → decoded JSON data.
$lookup = function (int $custId, int $tmplId) use ($self): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($self) . ' lookup ' . $custId . ' ' . $tmplId . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"success"') : false;
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim((string) $out), true);
    return is_array($j['data'] ?? null) ? $j['data'] : [];
};

// convert endpoint via a POST harness (real CSRF + session).
$harnessFile = sys_get_temp_dir() . '/_ff_e2e_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$payload = base64_decode(\$argv[1] ?? '');
\$sess    = json_decode(base64_decode(\$argv[2] ?? ''), true);
class FfE2EInput {
    public \$context; private static string \$b=''; private int \$p=0;
    public static function set(string \$s):void{ self::\$b=\$s; }
    public function stream_open(\$a,\$b,\$c,&\$d):bool{ \$this->p=0; return true; }
    public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
    public function stream_eof():bool{ return \$this->p>=strlen(self::\$b); }
    public function stream_stat():array{ return []; }
    public function stream_seek(\$o,\$w):bool{ \$this->p=\$o; return true; }
    public function stream_tell():int{ return \$this->p; }
}
FfE2EInput::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfE2EInput');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-E2E/1.0';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/api/v1/equipment/categories/convert_to_subcategory.php';
PHP);
$adminSession = null;
$convert = function (int $srcId, int $parentId) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg(base64_encode(json_encode(['id' => $srcId, 'parent_category_id' => $parentId])))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"') : false;
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$catId = $tmplId = $unitId = $custId = $cardId = $leaseId = 0;
$cleanup = function () use (&$catId, &$tmplId, &$unitId, &$custId, &$cardId, &$leaseId, $SLUG) {
    if ($leaseId) {
        $invs = array_column(db_select("SELECT id FROM invoices WHERE lease_id = ?", [$leaseId]), 'id');
        foreach ($invs as $iv) {
            db_execute("DELETE FROM invoice_line_items WHERE invoice_id = ?", [(int) $iv]);
            db_execute("DELETE FROM invoices WHERE id = ?", [(int) $iv]);
        }
        db_execute("DELETE FROM leases WHERE id = ?", [$leaseId]);
    }
    if ($cardId)  { db_execute("DELETE FROM rate_card_items WHERE rate_card_id = ?", [$cardId]); db_execute("DELETE FROM rate_cards WHERE id = ?", [$cardId]); }
    if ($unitId)  { db_execute("DELETE FROM equipment_units WHERE id = ?", [$unitId]); }
    if ($tmplId)  { db_execute("DELETE FROM equipment_templates WHERE id = ?", [$tmplId]); }
    if ($custId)  { db_execute("DELETE FROM leases WHERE customer_id = ?", [$custId]); db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    db_execute("DELETE FROM equipment_subcategories WHERE slug = ?", [$SLUG]);
    db_execute("DELETE FROM equipment_categories WHERE slug = ?", [$SLUG]);
    db_execute("DELETE FROM audit_log WHERE module='equipment' AND entity_type IN ('equipment_category','equipment_subcategory','equipment_template') AND entity_label LIKE ?", ['%' . $SLUG . '%']);
};

try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    $chassis = db_row("SELECT id FROM equipment_categories WHERE slug='chassis' AND deleted_at IS NULL");
    if (!$chassis) { echo "\033[31mSETUP FAIL\033[0m chassis category missing (run S-EQTAX-1 migration).\n"; exit(2); }
    $chassisId = (int) $chassis['id'];
    db_execute("UPDATE equipment_categories SET enforce_minimum_billing_days = 1 WHERE id = ?", [$chassisId]);

    $cleanup(); // clear leftovers from a prior aborted run

    echo str_repeat('─', 72) . "\nS-EQTAX-10 rate-card + 3-day-min E2E — token {$SLUG}\n" . str_repeat('─', 72) . "\n";

    // ── Setup: top-level "combo" category + template + unit + global rate card ──
    $catId  = db_insert('equipment_categories', ['slug' => $SLUG, 'label' => $TOKEN . ' Combo', 'enforce_minimum_billing_days' => 1, 'sort_order' => 999]);
    $tmplId = db_insert('equipment_templates', [
        'name' => $TOKEN . ' Combo Type', 'slug' => $SLUG . '_tpl', 'category' => $SLUG,
        'category_id' => $catId, 'default_daily_rate' => '80.00', 'default_weekly_rate' => '500.00',
        'default_monthly_rate' => '1800.00', 'default_mileage_rate' => '0.0000',
    ]);
    $unitId = db_insert('equipment_units', ['template_id' => $tmplId, 'unit_number' => $TOKEN . '-U', 'ownership_type' => 'owned']);
    $custId = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $cardId = db_insert('rate_cards', ['name' => $TOKEN . ' Card', 'is_default' => 0, 'effective_from' => '2026-01-01']);
    db_insert('rate_card_items', [
        'rate_card_id' => $cardId, 'equipment_type' => $SLUG, 'daily_rate' => '100.00',
        'weekly_rate' => '600.00', 'monthly_rate' => '2000.00', 'mileage_rate' => '0.0000',
        'mileage_unit' => 'km', 'currency' => 'CAD', 'minimum_days' => 3,
    ]);

    // ── 1. lookup_rates (pre-convert) → the combo card matches ──
    $r = $lookup($custId, $tmplId);
    ($r['source'] ?? '') === 'rate_card' && (string) ($r['daily_rate'] ?? '') === '100.00' && (int) ($r['minimum_days'] ?? 0) === 3
        ? $pass("1 pre-convert lookup → rate card matches (daily=100.00, minimum_days=3)")
        : $fail("1 pre-convert lookup — got " . json_encode($r));

    // ── 2. CONVERT the combo category into a sub-category under Chassis ──
    $cv = $convert($catId, $chassisId);
    $tpl = db_row("SELECT category_id, subcategory_id, category FROM equipment_templates WHERE id=?", [$tmplId]);
    $srcDeleted = db_row("SELECT deleted_at FROM equipment_categories WHERE id=?", [$catId])['deleted_at'] ?? null;
    $subSlug = db_row("SELECT slug FROM equipment_subcategories WHERE id=?", [(int) ($tpl['subcategory_id'] ?? 0)])['slug'] ?? null;
    ($cv['success'] ?? null) === true
        && (int) $tpl['category_id'] === $chassisId
        && $tpl['subcategory_id'] !== null
        && $tpl['category'] === $SLUG          // mirror preserved
        && $subSlug === $SLUG                   // new sub keeps the slug
        && $srcDeleted !== null                 // source category retired
        ? $pass("2 convert → template re-pointed to Chassis + sub '{$SLUG}', mirror preserved, source retired")
        : $fail("2 convert — resp=" . json_encode($cv['error'] ?? $cv) . " tpl=" . json_encode($tpl) . " srcDeleted=" . json_encode($srcDeleted));

    // ── 3. lookup_rates (post-convert) → SAME card still matches ──
    $r = $lookup($custId, $tmplId);
    ($r['source'] ?? '') === 'rate_card' && (string) ($r['daily_rate'] ?? '') === '100.00'
        ? $pass("3 post-convert lookup → rate card STILL matches (daily=100.00) — survives the convert")
        : $fail("3 post-convert lookup — got " . json_encode($r));

    // ── 4. Billing: 1-day lease on the combo unit closes → bills 3 × $100 ──
    $leaseId = Fixtures::createLease($custId, [
        'engine_version' => 'holistic', 'billing_cycle' => 'monthly',
        'equipment_unit_id' => $unitId, 'start_date' => '2026-06-01', 'status' => 'active',
        'daily_rate' => '100.00', 'weekly_rate' => '600.00', 'monthly_rate' => '2000.00',
        'minimum_billing_days' => 3, 'gps_opt_in' => 0, 'insurance_opt_in' => 0, 'warranty_opt_in' => 0,
        'tax_exempt' => 1, 'gst_exempt' => 1, 'pst_exempt' => 1, 'mileage_tracking_mode' => 'off',
    ]);
    db_execute("UPDATE leases SET actual_return_date = '2026-06-01' WHERE id = ?", [$leaseId]);
    $gen = new InvoiceGenerator();
    $res = $gen->createFromLease([
        'lease_id' => $leaseId, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => 1,
    ]);
    $invId = (int) ($res['invoice_id'] ?? 0);
    $net = db_row(
        "SELECT COALESCE(SUM(CASE WHEN is_credit=1 THEN -amount ELSE amount END),'0.00') s
           FROM invoice_line_items WHERE invoice_id=? AND item_type IN ('base_rental','base_rental_reconciliation_credit')",
        [$invId]
    )['s'] ?? '0.00';
    $desc = db_row("SELECT description FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental' LIMIT 1", [$invId])['description'] ?? '';
    bccomp((string) $net, '300.00', 2) === 0 && stripos($desc, 'minimum') !== false
        ? $pass("4 billing → 1-day combo lease bills 3 × \$100 = \$300 (minimum inherited from Chassis) · {$desc}")
        : $fail("4 billing — net={$net} (expect 300.00) desc=[{$desc}]");

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo str_repeat('─', 72) . "\n";
if ($failures) { echo "\033[31mRESULT: " . count($failures) . " FAIL / " . ($passes + count($failures)) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$passes} PASS — rate cards + 3-day minimum work end-to-end (incl. convert)\033[0m\n";
exit(0);
