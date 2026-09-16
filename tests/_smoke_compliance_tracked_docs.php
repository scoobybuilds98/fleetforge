<?php
declare(strict_types=1);

/**
 * tests/_smoke_compliance_tracked_docs.php
 *
 * Stop-condition smoke: compliance counts/alerts track CVI + Registration ONLY.
 *
 * Bug: MVI and Insurance were removed from every per-unit compliance UI
 * (S-UNIT-COMPLIANCE-HIDE-MVI-INS — grid, editor, upload selector) but the
 * sidebar badge, dashboard KPI, equipment-list badge, nightly compliance_alerts
 * cron (staff + customer branches), morning briefing, AI fleet brief, anomaly
 * detector, AI tools and health-score penalty still evaluated mvi_expiry /
 * insurance_expiry, and the Documents library + compliance/update API could
 * still WRITE equipment_units.insurance_expiry. A unit whose only "problem" was
 * an invisible insurance date therefore lit the badge and raised alerts.
 *
 * Hermetic: every DB write happens inside BEGIN … ROLLBACK. Functions under test
 * are EXTRACTED from the shipped files (sidebar_badge_count, compute_unit_health_score)
 * or invoked via reflection (AnomalyDetector / FleetForgeTools private statics),
 * so the smoke exercises the literal code against the real schema.
 *
 * Tests:
 *  T1  Static: no mvi_expiry / insurance_expiry left in the count/alert sources
 *  T2  Static: documents/upload + compliance/update no longer accept 'insurance'
 *  T3  sidebar_badge_count('compliance_alerts'): MVI/Insurance-only expiry does NOT
 *      count; a CVI expiry inside 30 days DOES (company-local date window)
 *  T4  compute_unit_health_score: MVI/Insurance expired → no penalty; CVI expired → -30
 *  T5  AnomalyDetector::detectComplianceRisks ignores MVI/Insurance-only units
 *  T6  FleetForgeTools::getExpiringDocuments returns no MVI/Insurance rows
 *
 * Run: php tests/_smoke_compliance_tracked_docs.php
 */

require_once __DIR__ . '/../config/app.php';

$pass = 0; $fail = 0;
/** Record a passing assertion. @param string $t label @return void */
function ok(string $t): void { global $pass; $pass++; echo "  \033[32m✓\033[0m {$t}\n"; }
/** Record a failing assertion. @param string $t label @param string $d detail @return void */
function bad(string $t, string $d = ''): void { global $fail; $fail++; echo "  \033[31m✗\033[0m {$t}" . ($d ? " — {$d}" : '') . "\n"; }
/** Assert helper. @param bool $c condition @param string $t label @param string $d detail @return void */
function check(bool $c, string $t, string $d = ''): void { $c ? ok($t) : bad($t, $d); }

$ROOT = dirname(__DIR__);

/**
 * Pull one top-level PHP function's source out of a file so it can be eval'd
 * without executing the rest of the file (sidebar markup, cron body).
 *
 * @param  string $file absolute path
 * @param  string $name function name
 * @return string function source ('' when not found)
 */
function extract_function(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, "function {$name}(");
    if ($start === false) return '';
    $open = strpos($src, '{', strpos($src, ')', $start));
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        if ($src[$i] === '}' && --$depth === 0) return substr($src, $start, $i - $start + 1);
    }
    return '';
}

echo "\nS-COMPLIANCE-TRACKED-DOCS — counts/alerts track CVI + Registration only\n";
echo str_repeat('═', 72) . "\n";

// ── T1: static — alert/count sources ────────────────────────────────────────
echo "\nT1: no MVI/Insurance in count + alert sources\n";
$alertSources = [
    'includes/sidebar.php',
    'api/v1/dashboard/kpis.php',
    'cron/compliance_alerts.php',
    'cron/health_scores.php',
    'cron/ai_fleet_brief.php',
    'lib/Notifications/MorningBriefingRenderer.php',
    'lib/AI/AnomalyDetector.php',
    'app/portal/equipment/index.php',
];
foreach ($alertSources as $rel) {
    $s = (string) file_get_contents("{$ROOT}/{$rel}");
    check(!preg_match('/\b(mvi_expiry|insurance_expiry)\b/', $s), "T1: {$rel} evaluates neither mvi_expiry nor insurance_expiry");
}
$eqIndex = (string) file_get_contents("{$ROOT}/app/admin/equipment/index.php");
check(str_contains($eqIndex, "const fields = ['cvi_expiry','registration_expiry'];"), 'T1: equipment list hasComplianceIssue() checks CVI + Registration only');

// ── T2: static — write paths ────────────────────────────────────────────────
echo "\nT2: insurance write paths closed\n";
$upload = (string) file_get_contents("{$ROOT}/api/v1/documents/upload.php");
check(str_contains($upload, "'equipment_unit' => ['cvi', 'registration', 'other']"), "T2: documents/upload.php equipment_unit allowlist excludes 'insurance'");
check(str_contains($upload, "in_array(\$docType, ['cvi', 'registration'], true)"), 'T2: documents/upload.php legacy expiry sync limited to cvi/registration');
$cupd = (string) file_get_contents("{$ROOT}/api/v1/compliance/update.php");
check(str_contains($cupd, "\$allowedDocTypes = ['cvi', 'registration'];"), "T2: compliance/update.php doc_type allowlist excludes 'insurance'");

// ── Load real functions ─────────────────────────────────────────────────────
$fnSidebar = extract_function("{$ROOT}/includes/sidebar.php", 'sidebar_badge_count');
$fnHealth  = extract_function("{$ROOT}/cron/health_scores.php", 'compute_unit_health_score');
if ($fnSidebar === '' || $fnHealth === '') {
    bad('extract functions', 'sidebar_badge_count or compute_unit_health_score not found');
} else {
    eval($fnSidebar);
    eval($fnHealth);
}

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $unit = db_row(
        "SELECT id, unit_number FROM equipment_units
          WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
          ORDER BY id LIMIT 1"
    );
    if (!$unit) throw new RuntimeException('no active equipment unit in dev DB');
    $uid       = (int) $unit['id'];
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $in10      = date('Y-m-d', strtotime('+10 days'));
    $far       = date('Y-m-d', strtotime('+400 days'));

    // Baseline: this unit clean on every date.
    db_execute("UPDATE equipment_units SET cvi_expiry = ?, registration_expiry = ?, mvi_expiry = NULL, insurance_expiry = NULL WHERE id = ?", [$far, $far, $uid]);

    // ── T3: sidebar badge ───────────────────────────────────────────────────
    echo "\nT3: sidebar compliance badge\n";
    if (function_exists('sidebar_badge_count')) {
        $base = sidebar_badge_count('compliance_alerts');
        db_execute("UPDATE equipment_units SET mvi_expiry = ?, insurance_expiry = ? WHERE id = ?", [$yesterday, $yesterday, $uid]);
        $afterHidden = sidebar_badge_count('compliance_alerts');
        check($afterHidden === $base, 'T3: expired MVI + Insurance alone do not raise the badge', "base={$base} after={$afterHidden}");
        db_execute("UPDATE equipment_units SET cvi_expiry = ? WHERE id = ?", [$in10, $uid]);
        $afterCvi = sidebar_badge_count('compliance_alerts');
        check($afterCvi === $base + 1, 'T3: a CVI expiring in 10 days raises the badge by 1', "base={$base} after={$afterCvi}");
        db_execute("UPDATE equipment_units SET cvi_expiry = ? WHERE id = ?", [$far, $uid]);
    }

    // ── T4: health score ────────────────────────────────────────────────────
    echo "\nT4: health score compliance penalty\n";
    if (function_exists('compute_unit_health_score')) {
        $args = [$today, date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime('+30 days')),
                 date('Y-m-d', strtotime('+60 days')), date('Y-m-d H:i:s', strtotime('-90 days')), []];
        $hidden = ['cvi_expiry' => $far, 'registration_expiry' => $far, 'mvi_expiry' => $yesterday,
                   'insurance_expiry' => $yesterday, 'status' => 'available', 'template_id' => 0,
                   'samsara_odometer_km' => null];
        $cviExp = ['cvi_expiry' => $yesterday] + $hidden;
        $sHidden = compute_unit_health_score($hidden, ...$args);
        $sCvi    = compute_unit_health_score($cviExp, ...$args);
        check($sHidden - $sCvi === 30, 'T4: expired CVI costs 30 points; expired MVI/Insurance cost nothing', "hidden={$sHidden} cvi={$sCvi}");
    }

    // ── T5: anomaly detector ────────────────────────────────────────────────
    echo "\nT5: AnomalyDetector::detectComplianceRisks\n";
    db_execute("UPDATE equipment_units SET mvi_expiry = ?, insurance_expiry = ? WHERE id = ?", [$yesterday, $yesterday, $uid]);
    $m = new ReflectionMethod(\FleetForge\AI\AnomalyDetector::class, 'detectComplianceRisks');
    $m->setAccessible(true);
    $flagged = array_filter($m->invoke(null), fn($a) => (int) $a['entity_id'] === $uid);
    check(count($flagged) === 0, 'T5: unit with only MVI/Insurance expired is not flagged');
    db_execute("UPDATE equipment_units SET cvi_expiry = ? WHERE id = ?", [$yesterday, $uid]);
    $flagged = array_filter($m->invoke(null), fn($a) => (int) $a['entity_id'] === $uid);
    check(count($flagged) === 1, 'T5: same unit with an expired CVI is flagged');
    db_execute("UPDATE equipment_units SET cvi_expiry = ? WHERE id = ?", [$far, $uid]);

    // ── T6: AI expiring-documents tool ──────────────────────────────────────
    echo "\nT6: FleetForgeTools::getExpiringDocuments\n";
    db_execute("UPDATE equipment_units SET mvi_expiry = ?, insurance_expiry = ? WHERE id = ?", [$in10, $in10, $uid]);
    $t = new ReflectionMethod(\FleetForge\AI\Tools\FleetForgeTools::class, 'getExpiringDocuments');
    $t->setAccessible(true);
    $rows  = $t->invoke(null, ['days_ahead' => 30]);
    $types = array_unique(array_map(fn($r) => $r['document_type'], $rows));
    check(!in_array('MVI', $types, true) && !in_array('Insurance', $types, true), 'T6: no MVI/Insurance rows returned', implode(',', $types));
} catch (\Throwable $e) {
    bad('unexpected exception', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "\n" . str_repeat('─', 72) . "\nPASS {$pass}  FAIL {$fail}\n";
exit($fail > 0 ? 1 : 0);
