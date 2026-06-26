<?php
declare(strict_types=1);

/**
 * tests/_smoke_equipment_taxonomy.php
 *
 * S-EQTAX-3 — equipment-template create/update write paths for the two-level
 * taxonomy. Drives the REAL endpoints (subprocess, real db.php + schema + CSRF +
 * auth) and asserts:
 *   - create with category_id (+ subcategory_id) persists the FKs and writes the
 *     legacy `category` mirror = sub-category slug when chosen, else category slug;
 *   - back-compat: create with only the legacy `category` slug still resolves the
 *     category_id;
 *   - invalid category_id / mismatched subcategory_id → clean 422;
 *   - update reclassification recomputes the mirror, but an unrelated edit (name
 *     only) leaves category_id / mirror untouched.
 *
 * Real writes are cleaned up in the finally block (templates matched on a per-pid
 * token; the shared Combo sub-category is left intact).
 *
 * Run:  php tests/_smoke_equipment_taxonomy.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-EQTAX-3
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';

if (!isset($_SESSION)) $_SESSION = [];

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID   = getmypid();
$TOKEN = '_smoke_eqtax_' . $PID;

// ── POST harness (real endpoint subprocess) ──────────────────────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_eqtax_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfEqtaxInput {
    public \$context; private static string \$buf = ''; private int \$pos = 0;
    public static function set(string \$s): void { self::\$buf = \$s; }
    public function stream_open(\$p, \$m, \$o, &\$x): bool { \$this->pos = 0; return true; }
    public function stream_read(\$c) { \$ch = substr(self::\$buf, \$this->pos, \$c); \$this->pos += strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos >= strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o, \$w): bool { \$this->pos = \$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfEqtaxInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfEqtaxInput');
\$_SERVER['REQUEST_METHOD']    = 'POST';
\$_SERVER['CONTENT_TYPE']      = 'application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
\$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
\$_SERVER['HTTP_HOST']         = 'localhost';
\$_SERVER['HTTP_USER_AGENT']   = 'FleetForge-EQTAX-Smoke/1.0';
@session_start();
\$_SESSION['csrf_token'] = 'smoketoken';
\$_SESSION['ff_user']    = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession)))
        . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return null;
    $start = strpos($out, '{"success"');
    if ($start === false) $start = strpos($out, '{"');
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$cleanup = static function () use ($TOKEN): void {
    foreach (db_select("SELECT id FROM equipment_templates WHERE name LIKE ?", [$TOKEN . '%']) as $r) {
        $tid = (int) $r['id'];
        db_execute("DELETE FROM audit_log WHERE module='equipment' AND entity_type='equipment_template' AND entity_id=?", [$tid]);
        db_execute("DELETE FROM equipment_templates WHERE id=?", [$tid]);
    }
};

$tmplRow = static fn (int $id): ?array => db_row(
    "SELECT category, category_id, subcategory_id FROM equipment_templates WHERE id=?", [$id]
);

try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    $chassis = db_row("SELECT id, slug FROM equipment_categories WHERE slug='chassis' AND deleted_at IS NULL");
    $dryVan  = db_row("SELECT id, slug FROM equipment_categories WHERE slug='dry_van' AND deleted_at IS NULL");
    $reefer  = db_row("SELECT id, slug FROM equipment_categories WHERE slug='reefer' AND deleted_at IS NULL");
    if (!$chassis || !$dryVan || !$reefer) { echo "\033[31mSETUP FAIL\033[0m seeded categories missing (run the S-EQTAX-1 migration).\n"; exit(2); }
    $chassisId = (int) $chassis['id'];

    // Ensure a Combo sub-category under Chassis (idempotent).
    db_execute("INSERT INTO equipment_subcategories (category_id, slug, label) VALUES (?, 'combo', 'Combo')
                ON DUPLICATE KEY UPDATE label=VALUES(label)", [$chassisId]);
    $comboSubId = (int) db_row("SELECT id FROM equipment_subcategories WHERE category_id=? AND slug='combo'", [$chassisId])['id'];
    // A second chassis sub-category to reclassify into.
    db_execute("INSERT INTO equipment_subcategories (category_id, slug, label) VALUES (?, 'tridem_chassis', 'Tridem Chassis')
                ON DUPLICATE KEY UPDATE label=VALUES(label)", [$chassisId]);
    $tridemSubId = (int) db_row("SELECT id FROM equipment_subcategories WHERE category_id=? AND slug='tridem_chassis'", [$chassisId])['id'];

    $cleanup();
    echo str_repeat('─', 72) . "\nS-EQTAX-3 template write paths — token {$TOKEN}\n" . str_repeat('─', 72) . "\n";

    // 1. create with category_id + subcategory_id → mirror = subcat slug
    $r = $post('api/v1/equipment/templates/create.php',
        ['name' => $TOKEN . ' Combo Unit', 'category_id' => $chassisId, 'subcategory_id' => $comboSubId]);
    $id1 = (int) ($r['data']['id'] ?? 0);
    $row = $id1 ? $tmplRow($id1) : null;
    ($r['success'] ?? null) === true && $row
        && (int) $row['category_id'] === $chassisId
        && (int) $row['subcategory_id'] === $comboSubId
        && $row['category'] === 'combo'
        ? $pass("1 create chassis+combo → category_id={$chassisId}, subcategory_id={$comboSubId}, mirror='combo'")
        : $fail("1 create chassis+combo — got " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));

    // 2. create with category only (no subcategory) → mirror = category slug
    $r = $post('api/v1/equipment/templates/create.php',
        ['name' => $TOKEN . ' Plain Van', 'category_id' => (int) $dryVan['id']]);
    $id2 = (int) ($r['data']['id'] ?? 0);
    $row = $id2 ? $tmplRow($id2) : null;
    ($r['success'] ?? null) === true && $row
        && (int) $row['category_id'] === (int) $dryVan['id']
        && $row['subcategory_id'] === null
        && $row['category'] === 'dry_van'
        ? $pass("2 create dry_van (no sub) → mirror='dry_van', subcategory_id=NULL")
        : $fail("2 create dry_van — got " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));

    // 3. back-compat: legacy `category` slug only → resolves category_id
    $r = $post('api/v1/equipment/templates/create.php',
        ['name' => $TOKEN . ' Legacy Reefer', 'category' => 'reefer']);
    $id3 = (int) ($r['data']['id'] ?? 0);
    $row = $id3 ? $tmplRow($id3) : null;
    ($r['success'] ?? null) === true && $row
        && (int) $row['category_id'] === (int) $reefer['id']
        && $row['category'] === 'reefer'
        ? $pass("3 create back-compat (legacy slug 'reefer') → category_id resolved, mirror='reefer'")
        : $fail("3 create back-compat — got " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));

    // 4. invalid category_id → 422
    $r = $post('api/v1/equipment/templates/create.php',
        ['name' => $TOKEN . ' Bad Cat', 'category_id' => 99999999]);
    ($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        ? $pass("4 create invalid category_id → VALIDATION_ERROR (no row)")
        : $fail("4 create invalid category_id — expected 422, got " . json_encode($r));

    // 4b. subcategory not belonging to the category → 422
    $r = $post('api/v1/equipment/templates/create.php',
        ['name' => $TOKEN . ' Bad Sub', 'category_id' => (int) $dryVan['id'], 'subcategory_id' => $comboSubId]);
    ($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        ? $pass("4b create subcategory-not-in-category → VALIDATION_ERROR")
        : $fail("4b create mismatched subcategory — expected 422, got " . json_encode($r));

    // 5. update reclassification: id1 chassis/combo → chassis/tridem → mirror recompute
    if ($id1) {
        $ua = db_row("SELECT updated_at FROM equipment_templates WHERE id=?", [$id1])['updated_at'] ?? '';
        $r  = $post('api/v1/equipment/templates/update.php',
            ['id' => $id1, 'updated_at' => $ua, 'category_id' => $chassisId, 'subcategory_id' => $tridemSubId]);
        $row = $tmplRow($id1);
        ($r['success'] ?? null) === true && (int) $row['subcategory_id'] === $tridemSubId && $row['category'] === 'tridem_chassis'
            ? $pass("5 update reclassify sub → mirror recomputed to 'tridem_chassis'")
            : $fail("5 update reclassify — got " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));
    }

    // 6. unrelated edit (name only) leaves category_id + mirror untouched
    if ($id1) {
        $before = $tmplRow($id1);
        $ua = db_row("SELECT updated_at FROM equipment_templates WHERE id=?", [$id1])['updated_at'] ?? '';
        $r  = $post('api/v1/equipment/templates/update.php',
            ['id' => $id1, 'updated_at' => $ua, 'name' => $TOKEN . ' Combo Unit Renamed']);
        $after = $tmplRow($id1);
        ($r['success'] ?? null) === true
            && $after['category'] === $before['category']
            && (int) $after['category_id'] === (int) $before['category_id']
            && (int) $after['subcategory_id'] === (int) $before['subcategory_id']
            ? $pass("6 name-only edit → classification + mirror unchanged ({$after['category']})")
            : $fail("6 name-only edit — classification drifted: before=" . json_encode($before) . " after=" . json_encode($after));
    }

    // 7. update change category to dry_van (no sub) → mirror='dry_van', sub cleared
    if ($id1) {
        $ua = db_row("SELECT updated_at FROM equipment_templates WHERE id=?", [$id1])['updated_at'] ?? '';
        $r  = $post('api/v1/equipment/templates/update.php',
            ['id' => $id1, 'updated_at' => $ua, 'category_id' => (int) $dryVan['id'], 'subcategory_id' => '']);
        $row = $tmplRow($id1);
        ($r['success'] ?? null) === true
            && (int) $row['category_id'] === (int) $dryVan['id']
            && $row['subcategory_id'] === null
            && $row['category'] === 'dry_van'
            ? $pass("7 update change category → mirror='dry_van', subcategory cleared")
            : $fail("7 update change category — got " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));
    }

    // ── G: billing-gate resolution (the exact join InvoiceGenerator uses) ──────
    // Guards that the short-lease-minimum enforce flag resolves correctly via
    // category_id (preferred) with the slug-mirror fallback for unbackfilled rows.
    // Hermetic: chassis must enforce, reefer must not for these checks.
    db_execute("UPDATE equipment_categories SET enforce_minimum_billing_days = 1 WHERE slug = 'chassis' AND deleted_at IS NULL");
    db_execute("UPDATE equipment_categories SET enforce_minimum_billing_days = 0 WHERE slug = 'reefer'  AND deleted_at IS NULL");
    $gateEnforce = function (?int $catId, string $mirror) use ($TOKEN): int {
        // Insert a throwaway template (token-named → cleaned up) then run the gate join.
        $tid = db_insert('equipment_templates', [
            'name' => $TOKEN . ' Gate ' . bin2hex(substr($mirror, 0, 6)) . '_' . ($catId ?? 0),
            'slug' => '__smoke_eqtax_gate_' . ($catId ?? 0) . '_' . substr(md5($mirror . microtime()), 0, 8) . '__',
            'category' => $mirror, 'category_id' => $catId, 'default_mileage_rate' => '0.0000',
        ]);
        $row = db_row(
            "SELECT ec.enforce_minimum_billing_days AS e
               FROM equipment_templates et
               LEFT JOIN equipment_categories ec
                      ON ec.deleted_at IS NULL
                     AND (ec.id = et.category_id OR (et.category_id IS NULL AND ec.slug = et.category))
              WHERE et.id = ?",
            [$tid]
        );
        return (int) ($row['e'] ?? 0);
    };
    $chassisId = (int) $chassis['id'];
    $reeferId  = (int) $reefer['id'];
    $gateEnforce($chassisId, 'chassis') === 1
        ? $pass("G1 chassis-category template → gate enforces (1)")
        : $fail("G1 chassis-category template should enforce");
    $gateEnforce($reeferId, 'reefer') === 0
        ? $pass("G2 reefer-category template → gate does NOT enforce (0)")
        : $fail("G2 reefer-category template should not enforce");
    $gateEnforce($chassisId, 'combo') === 1
        ? $pass("G3 combo mirror + category_id=Chassis → INHERITS enforce (1) [the Jete's case]")
        : $fail("G3 combo-mirror-with-chassis-category should inherit enforce");
    $gateEnforce(null, 'chassis') === 1
        ? $pass("G4 unbackfilled (category_id NULL, mirror='chassis') → slug fallback enforces (1)")
        : $fail("G4 slug-mirror fallback should enforce for an unbackfilled chassis template");

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo str_repeat('─', 72) . "\n";
if ($failures) {
    echo "\033[31mRESULT: " . count($failures) . " FAIL / " . ($passes + count($failures)) . "\033[0m\n";
    exit(1);
}
echo "\033[32mRESULT: ALL {$passes} PASS\033[0m\n";
exit(0);
