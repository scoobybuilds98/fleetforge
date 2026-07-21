<?php
declare(strict_types=1);
/**
 * tests/_smoke_unit_brand.php — S-UNIT-BRAND
 *
 * Guards the move of `brand` from the equipment TEMPLATE onto the UNIT.
 *
 * The operator's problem: a template is a TYPE ("53' Dry Van") and one type is
 * built by many manufacturers, so a brand on the template forced one duplicate
 * template per brand. Brand is now `equipment_units.brand_id` → the
 * operator-managed `equipment_brands` lookup.
 *
 * This migration DROPPED a column that ~34 files read, so the failure mode is
 * not subtle-wrong values — it is a 500 or a silently blank column on pages all
 * over the app. The bulk of this file therefore EXECUTES the real queries and
 * the real endpoints rather than asserting on source text.
 *
 * Covers:
 *   SCHEMA  — table exists + seeded, FK shape, template column really gone.
 *   DATA    — the migration's backfill preserved every unit's brand.
 *   READERS — every rewired page/API query still parses and returns a `brand`
 *             column (a LEFT JOIN regression would drop unbranded units; a
 *             renamed alias would blank the column downstream).
 *   API     — brand CRUD (create/rename/deactivate/delete) incl. the in-use
 *             delete guard, and unit create/update accepting + clearing brand.
 *   TRAPS   — the use()-closure NULL trap, LEFT-not-INNER, inactive-brand
 *             rejection, and keeping a since-deactivated brand on edit.
 *
 * Run: php tests/_smoke_unit_brand.php   (exit 0 PASS / 1 FAIL)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';
if (!isset($_SESSION)) $_SESSION = [];

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void { global $pass; $pass++; echo "  PASS — {$m}\n"; }
function no(string $m): void { global $fail; $fail++; echo "  FAIL — {$m}\n"; }

$PID   = getmypid();
$TOKEN = 'SMOKEBRAND' . $PID;

// ── Endpoint harness (POST + GET), mirroring _smoke_days_on_rent ─────────
$harness = sys_get_temp_dir() . '/_ff_brand_harness_' . $PID . '.php';
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$method   = \$argv[2] ?? 'GET';
\$payload  = base64_decode(\$argv[3] ?? '');
\$sess     = json_decode(base64_decode(\$argv[4] ?? ''), true);
\$query    = json_decode(base64_decode(\$argv[5] ?? ''), true) ?: [];
class FfBrandInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfBrandInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfBrandInput');
\$_GET = \$query;
\$_SERVER['REQUEST_METHOD']    = \$method;
\$_SERVER['CONTENT_TYPE']      = 'application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
\$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
\$_SERVER['HTTP_HOST']         = 'localhost';
@session_start();
\$_SESSION['csrf_token'] = 'smoketoken';
\$_SESSION['ff_user']    = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$cfgPerms = require FF_ROOT . '/config/permissions.php';
$session  = ['id' => 1, 'name' => 'Brand Smoke', 'role_slug' => 'super_admin',
             'permissions' => $cfgPerms['super_admin'] ?? []];

$call = static function (string $endpoint, string $method = 'GET', array $payload = [], array $query = [])
        use ($harness, &$session): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($method)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($session)))
        . ' ' . escapeshellarg(base64_encode(json_encode($query))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"');
    if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => (string) $out];
};

/** The unit update endpoint requires the D19 optimistic-lock token that the real
 *  edit form submits; fetch it fresh so each call reflects the prior write. */
$updUnit = static function (int $unitId, array $payload) use (&$call): array {
    $tok = db_row("SELECT updated_at FROM equipment_units WHERE id = ?", [$unitId])['updated_at'] ?? '';
    return $call('api/v1/equipment/units/update.php', 'POST',
                 array_merge(['id' => $unitId, 'updated_at' => $tok], $payload));
};

$madeBrands = [];
$madeUnits  = [];

echo str_repeat('-', 74) . "\n";
echo "S-UNIT-BRAND — brand moved from template to unit (pid={$PID})\n";
echo str_repeat('-', 74) . "\n";

try {
    // ══ SCHEMA ══════════════════════════════════════════════════════════
    echo "\n── Schema ──\n";

    db_count("SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='equipment_brands'") === 1
        ? ok('S1 equipment_brands table exists')
        : no('S1 equipment_brands table missing');

    $tplBrand = db_count("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='equipment_templates' AND COLUMN_NAME='brand'");
    $tplBrand === 0
        ? ok('S2 equipment_templates.brand is GONE (cleanly removed)')
        : no('S2 equipment_templates.brand still present');

    $unitBrand = db_row("SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='equipment_units' AND COLUMN_NAME='brand_id'");
    ($unitBrand && $unitBrand['IS_NULLABLE'] === 'YES')
        ? ok('S3 equipment_units.brand_id exists and is NULLABLE (units may have no brand)')
        : no('S3 brand_id missing or NOT NULL: ' . json_encode($unitBrand));

    // ON DELETE SET NULL matters: retiring a brand must never cascade-delete assets.
    $fk = db_row("SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                   WHERE rc.CONSTRAINT_SCHEMA=DATABASE() AND rc.CONSTRAINT_NAME='fk_equnit_brand'");
    ($fk && $fk['DELETE_RULE'] === 'SET NULL')
        ? ok('S4 FK fk_equnit_brand is ON DELETE SET NULL (never cascade-deletes units)')
        : no('S4 FK rule wrong: ' . json_encode($fk));

    $seeded = db_count("SELECT COUNT(*) FROM equipment_brands WHERE deleted_at IS NULL");
    $seeded >= 27
        ? ok("S5 brand list seeded ({$seeded} brands)")
        : no("S5 expected >=27 seeded brands, got {$seeded}");

    // The operator's list had Fontaine twice — it must exist exactly once.
    db_count("SELECT COUNT(*) FROM equipment_brands WHERE LOWER(label)='fontaine'") === 1
        ? ok('S6 duplicate in the operator list (Fontaine) was deduped to one row')
        : no('S6 Fontaine is not exactly one row');

    $missing = [];
    foreach (['Max Atlas','CIMC','Jindo','Thaco','Sigimas','Fruehauf','Stoughton','Utility','Great Dane',
              'Wabash','Vanguard','Raja','Hyundai','Fontaine','Cheetah','Manac','Doepker','Wilson','Mac',
              'Trail King','Reitnouer','Lode King','Aspen'] as $lbl) {
        if (db_count("SELECT COUNT(*) FROM equipment_brands WHERE label = ?", [$lbl]) === 0) $missing[] = $lbl;
    }
    empty($missing) ? ok('S7 all 23 operator-supplied brands present')
                    : no('S7 missing brands: ' . implode(', ', $missing));

    // ══ DATA — the backfill must not have lost anything ═════════════════
    echo "\n── Backfill ──\n";

    $orphans = db_count(
        "SELECT COUNT(*) FROM equipment_units u
          LEFT JOIN equipment_brands b ON b.id = u.brand_id
         WHERE u.brand_id IS NOT NULL AND b.id IS NULL"
    );
    $orphans === 0 ? ok('B1 no unit points at a non-existent brand (no dangling FK)')
                   : no("B1 {$orphans} units have a dangling brand_id");

    $liveUnits   = db_count("SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL");
    $withBrand   = db_count("SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL AND brand_id IS NOT NULL");
    $withBrand > 0
        ? ok("B2 backfill populated brands ({$withBrand} of {$liveUnits} live units)")
        : no('B2 backfill left every unit without a brand');

    // ══ READERS — execute each rewired query shape ══════════════════════
    // A LEFT-vs-INNER regression silently DROPS unbranded units, so each probe
    // asserts the row count is unchanged by the brand join.
    echo "\n── Rewired readers (executed, not grepped) ──\n";

    $baseUnits = db_count("SELECT COUNT(*) FROM equipment_units u JOIN equipment_templates t ON t.id=u.template_id WHERE u.deleted_at IS NULL");
    $joined    = db_count("SELECT COUNT(*) FROM equipment_units u
                             JOIN equipment_templates t ON t.id=u.template_id
                             LEFT JOIN equipment_brands eb ON eb.id=u.brand_id
                            WHERE u.deleted_at IS NULL");
    $joined === $baseUnits
        ? ok("R1 LEFT JOIN to brands preserves every unit ({$joined} rows, no drop)")
        : no("R1 brand join changed the row count: {$baseUnits} -> {$joined} (INNER JOIN regression?)");

    // Prove the LEFT JOIN is load-bearing: a brand-less unit must still appear.
    $tplId = (int) (db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $nbId  = db_insert('equipment_units', [
        'template_id' => $tplId, 'unit_number' => $TOKEN . '-NOBRAND',
        'ownership_type' => 'owned', 'status' => 'available', 'brand_id' => null,
    ]);
    $madeUnits[] = $nbId;
    $seenNoBrand = db_row(
        "SELECT u.id, eb.label AS brand FROM equipment_units u
           JOIN equipment_templates t ON t.id=u.template_id
           LEFT JOIN equipment_brands eb ON eb.id=u.brand_id
          WHERE u.id = ?", [$nbId]
    );
    ($seenNoBrand && $seenNoBrand['brand'] === null)
        ? ok('R2 a unit with NO brand is still returned, with brand = NULL')
        : no('R2 brand-less unit vanished or mis-rendered: ' . json_encode($seenNoBrand));

    // Run the actual SELECT shape each rewired surface uses. Any 42S22 from the
    // dropped column, a bad alias, or a wrong ON clause fails here.
    $readerProbes = [
        'portal equipment list' =>
            "SELECT eu.unit_number, eb.label AS brand, et.model, et.category
               FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id
               LEFT JOIN equipment_brands eb ON eb.id=eu.brand_id LIMIT 5",
        'unit show page' =>
            "SELECT u.id, t.name AS template_name, eb.label AS template_brand, t.model AS template_model
               FROM equipment_units u JOIN equipment_templates t ON t.id=u.template_id
               LEFT JOIN equipment_brands eb ON eb.id=u.brand_id LIMIT 5",
        'per-unit P&L' =>
            "SELECT u.id, u.unit_number, u.year, eb.label AS template_brand, t.model AS template_model
               FROM equipment_units u JOIN equipment_templates t ON t.id=u.template_id
               LEFT JOIN equipment_brands eb ON eb.id=u.brand_id LIMIT 5",
        'global search (filters ON brand)' =>
            "SELECT eu.id, eb.label AS brand FROM equipment_units eu
               JOIN equipment_templates et ON et.id=eu.template_id
               LEFT JOIN equipment_brands eb ON eb.id=eu.brand_id
              WHERE eu.unit_number LIKE ? OR eb.label LIKE ? LIMIT 5",
        'chat attachment picker (CONCAT + filter)' =>
            "SELECT eu.id, CONCAT(eu.year,' ',COALESCE(eb.label,''),' ',et.model) AS subtitle
               FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id
               LEFT JOIN equipment_brands eb ON eb.id=eu.brand_id
              WHERE eu.unit_number LIKE ? OR eb.label LIKE ? OR et.model LIKE ? LIMIT 5",
        'vendor units (GROUP BY brand)' =>
            "SELECT eu.id, eu.unit_number, eb.label AS brand, et.model
               FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id
               LEFT JOIN equipment_brands eb ON eb.id=eu.brand_id
              GROUP BY eu.id, eu.unit_number, eb.label, et.model LIMIT 5",
    ];
    foreach ($readerProbes as $name => $sql) {
        $n = substr_count($sql, '?');
        try {
            db_select($sql, array_fill(0, $n, '%x%'));
            ok("R3 query shape executes: {$name}");
        } catch (\Throwable $e) {
            no("R3 {$name} → " . substr($e->getMessage(), 0, 120));
        }
    }

    // ══ BRAND CRUD API ══════════════════════════════════════════════════
    echo "\n── Brand CRUD API ──\n";

    $r = $call('api/v1/equipment/brands/index.php', 'GET');
    (($r['success'] ?? false) && is_array($r['data']['brands'] ?? null) && count($r['data']['brands']) >= 27)
        ? ok('A1 GET brands returns the seeded list with unit_count')
        : no('A1 brand list bad: ' . substr(json_encode($r), 0, 160));

    $r = $call('api/v1/equipment/brands/create.php', 'POST', ['label' => $TOKEN . ' Trailers']);
    $newBrandId = $r['data']['id'] ?? null;
    if ($newBrandId) $madeBrands[] = $newBrandId;
    (($r['success'] ?? false) && $newBrandId)
        ? ok('A2 create brand → 201 with generated slug ' . ($r['data']['slug'] ?? '?'))
        : no('A2 create failed: ' . substr(json_encode($r), 0, 160));

    $r = $call('api/v1/equipment/brands/create.php', 'POST', ['label' => $TOKEN . ' Trailers']);
    ((($r['success'] ?? true) === false) && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('A3 duplicate brand name rejected (422, not a 500 dup-key)')
        : no('A3 expected VALIDATION_ERROR, got ' . substr(json_encode($r), 0, 160));

    $r = $call('api/v1/equipment/brands/create.php', 'POST', ['label' => '']);
    ((($r['success'] ?? true) === false) && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('A4 blank brand name rejected')
        : no('A4 expected VALIDATION_ERROR, got ' . substr(json_encode($r), 0, 160));

    $r = $call('api/v1/equipment/brands/update.php', 'POST', ['id' => $newBrandId, 'label' => $TOKEN . ' Renamed']);
    (($r['success'] ?? false) && ($r['data']['label'] ?? '') === $TOKEN . ' Renamed')
        ? ok('A5 rename brand works')
        : no('A5 rename failed: ' . substr(json_encode($r), 0, 160));

    // Slug is the stable id — renaming must never move it.
    $slugAfter = db_row("SELECT slug FROM equipment_brands WHERE id = ?", [$newBrandId])['slug'] ?? '';
    str_contains($slugAfter, 'trailers')
        ? ok('A6 slug is immutable across rename (still ' . $slugAfter . ')')
        : no('A6 slug changed on rename → ' . $slugAfter);

    // ══ UNIT create/update with brand ═══════════════════════════════════
    echo "\n── Unit create / update ──\n";

    $custTpl = (int) (db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);
    $r = $call('api/v1/equipment/units/create.php', 'POST', [
        'template_id' => $custTpl, 'unit_number' => $TOKEN . '-U1',
        'ownership_type' => 'owned', 'brand_id' => $newBrandId,
    ]);
    $u1 = $r['data']['id'] ?? null;
    if ($u1) $madeUnits[] = $u1;
    // THE use()-CLOSURE TRAP: a variable in the insert array but missing from
    // the closure's use() list writes NULL. A 201 alone would not catch it —
    // this asserts the value actually landed in the row.
    $stored = $u1 ? db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u1]) : null;
    ($u1 && (int) ($stored['brand_id'] ?? 0) === (int) $newBrandId)
        ? ok('U1 create unit PERSISTS brand_id (use()-closure trap covered)')
        : no('U1 brand_id not persisted: ' . json_encode([$u1, $stored]));

    $r = $call('api/v1/equipment/units/create.php', 'POST', [
        'template_id' => $custTpl, 'unit_number' => $TOKEN . '-U2', 'ownership_type' => 'owned',
    ]);
    $u2 = $r['data']['id'] ?? null;
    if ($u2) $madeUnits[] = $u2;
    $stored2 = $u2 ? db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u2]) : null;
    ($u2 && $stored2['brand_id'] === null)
        ? ok('U2 brand is OPTIONAL on create (no brand → NULL, not an error)')
        : no('U2 expected NULL brand, got ' . json_encode($stored2));

    $r = $call('api/v1/equipment/units/create.php', 'POST', [
        'template_id' => $custTpl, 'unit_number' => $TOKEN . '-U3',
        'ownership_type' => 'owned', 'brand_id' => 2147483600,
    ]);
    if (($r['data']['id'] ?? null)) $madeUnits[] = $r['data']['id'];
    ((($r['success'] ?? true) === false) && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('U3 unknown brand_id rejected at create (422, not a dangling FK)')
        : no('U3 expected VALIDATION_ERROR, got ' . substr(json_encode($r), 0, 160));

    $otherBrand = (int) (db_row("SELECT id FROM equipment_brands WHERE label='Manac'")['id'] ?? 0);
    $r = $updUnit((int) $u1, ['brand_id' => $otherBrand]);
    $after = db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u1]);
    (($r['success'] ?? false) && (int) $after['brand_id'] === $otherBrand)
        ? ok('U4 update changes a unit\'s brand')
        : no('U4 update failed: ' . substr(json_encode($r), 0, 120) . ' stored=' . json_encode($after));

    $r = $updUnit((int) $u1, ['brand_id' => '']);
    $after = db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u1]);
    (($r['success'] ?? false) && $after['brand_id'] === null)
        ? ok('U5 empty brand_id CLEARS the brand (— No brand — is a valid choice)')
        : no('U5 expected NULL, got ' . json_encode($after) . ' resp=' . substr(json_encode($r), 0, 120));

    // ══ Deactivation semantics ══════════════════════════════════════════
    echo "\n── Deactivate / delete guards ──\n";

    db_execute("UPDATE equipment_units SET brand_id = ? WHERE id = ?", [$newBrandId, $u1]);
    $r = $call('api/v1/equipment/brands/delete.php', 'POST', ['id' => $newBrandId]);
    ((($r['success'] ?? true) === false) && ($r['error']['code'] ?? '') === 'IN_USE')
        ? ok('D1 deleting an in-use brand is BLOCKED (409 IN_USE)')
        : no('D1 expected IN_USE, got ' . substr(json_encode($r), 0, 160));

    $r = $call('api/v1/equipment/brands/update.php', 'POST', ['id' => $newBrandId, 'is_active' => 0]);
    $still = db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u1]);
    (($r['success'] ?? false) && (int) $still['brand_id'] === (int) $newBrandId)
        ? ok('D2 deactivating a brand KEEPS it on the units that carry it')
        : no('D2 deactivate broke the unit link: ' . json_encode($still));

    $r = $call('api/v1/equipment/brands/index.php', 'GET', [], ['active_only' => '1']);
    $ids = array_column($r['data']['brands'] ?? [], 'id');
    !in_array($newBrandId, $ids, false)
        ? ok('D3 a deactivated brand drops OUT of the picker list (active_only)')
        : no('D3 inactive brand still offered in the picker');

    $r = $call('api/v1/equipment/units/create.php', 'POST', [
        'template_id' => $custTpl, 'unit_number' => $TOKEN . '-U4',
        'ownership_type' => 'owned', 'brand_id' => $newBrandId,
    ]);
    if (($r['data']['id'] ?? null)) $madeUnits[] = $r['data']['id'];
    ((($r['success'] ?? true) === false) && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('D4 an INACTIVE brand cannot be assigned to a NEW unit')
        : no('D4 expected VALIDATION_ERROR, got ' . substr(json_encode($r), 0, 160));

    // The carve-out that stops an unrelated edit silently blanking the brand.
    $r = $updUnit((int) $u1, ['brand_id' => $newBrandId, 'yard_location' => null]);
    $keep = db_row("SELECT brand_id FROM equipment_units WHERE id = ?", [$u1]);
    (($r['success'] ?? false) && (int) $keep['brand_id'] === (int) $newBrandId)
        ? ok('D5 editing a unit whose brand was since deactivated KEEPS that brand')
        : no('D5 unchanged-but-inactive brand was rejected/blanked: ' . substr(json_encode($r), 0, 140));

    // Free it, then the delete must succeed.
    db_execute("UPDATE equipment_units SET brand_id = NULL WHERE brand_id = ?", [$newBrandId]);
    $r = $call('api/v1/equipment/brands/delete.php', 'POST', ['id' => $newBrandId]);
    (($r['success'] ?? false) === true)
        ? ok('D6 a brand with no units deletes cleanly')
        : no('D6 delete failed: ' . substr(json_encode($r), 0, 160));

    // ══ Template surface really is clean ════════════════════════════════
    echo "\n── Template surface ──\n";

    $r = $call('api/v1/equipment/templates/index.php', 'GET');
    $firstTpl = $r['data']['items'][0] ?? $r['data']['templates'][0] ?? null;
    (($r['success'] ?? false) && $firstTpl !== null && !array_key_exists('brand', $firstTpl))
        ? ok('T1 templates API no longer returns a brand field')
        : no('T1 templates API still exposes brand or failed: ' . substr(json_encode($r), 0, 160));

    $tplForms = 0;
    foreach (['app/admin/equipment/templates/create.php', 'app/admin/equipment/templates/edit.php'] as $f) {
        $src = file_get_contents(FF_ROOT . '/' . $f);
        if (preg_match('/x-model="form\.brand"|name="brand"/', $src)) $tplForms++;
    }
    $tplForms === 0
        ? ok('T2 brand input removed from BOTH template forms')
        : no("T2 {$tplForms} template form(s) still render a brand input");

} finally {
    if ($madeUnits) {
        db_execute("DELETE FROM equipment_units WHERE id IN (" . implode(',', array_map('intval', $madeUnits)) . ")");
    }
    db_execute("DELETE FROM equipment_units WHERE unit_number LIKE ?", [$TOKEN . '%']);
    if ($madeBrands) {
        db_execute("DELETE FROM equipment_brands WHERE id IN (" . implode(',', array_map('intval', $madeBrands)) . ")");
    }
    db_execute("DELETE FROM equipment_brands WHERE label LIKE ?", [$TOKEN . '%']);
    db_execute("DELETE FROM audit_log WHERE entity_type = 'equipment_brand' AND entity_label LIKE ?", [$TOKEN . '%']);
    @unlink($harness);
}

echo "\n" . str_repeat('-', 74) . "\n";
echo "UNIT-BRAND — {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
