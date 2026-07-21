<?php
declare(strict_types=1);
/**
 * tests/_smoke_days_on_rent.php — S-DAYS-ON-RENT
 *
 * Guards api/v1/equipment/units/days-on-rent.php, which backs the "Days on
 * Rent" panel on the unit command center's Lease History tab.
 *
 * The occupancy math has four ways to be quietly wrong, and every one of them
 * produces a plausible-looking number rather than an error:
 *
 *   1. OFF-BY-ONE — days must be INCLUSIVE of both endpoints (a one-day lease
 *      is 1 day, not 0). Matches HolisticLeaseEngine::inclusiveDays().
 *   2. END-DATE LADDER — actual_return_date → end_date → today. Completed
 *      leases with a NULL actual_return_date exist in real data; under the
 *      older api/v1/reports/fleet.php ladder (which omits end_date) they read
 *      as still-on-rent through the window end, inflating the total.
 *   3. OVERLAP — nothing validates a new lease's dates against the unit's
 *      other leases (api/v1/leases/create.php gates on the unit's CURRENT
 *      status, which is date-blind), so overlapping leases are real. Summing
 *      per-lease spans double-counts and can exceed 100% utilization; the
 *      endpoint merges intervals instead.
 *   4. WINDOW CEILING — "this year" must stop at today, not Dec 31, or the
 *      utilization denominator includes unelapsed days.
 *
 * Per feedback_schema_real_smoke_coverage this EXECUTES the real endpoint in a
 * subprocess against the real schema — php -l would not catch an undefined
 * helper or a column that does not exist. Fixtures are committed (the
 * subprocess cannot see an uncommitted transaction) and removed in a finally.
 *
 * Run: php tests/_smoke_days_on_rent.php   (exit 0 PASS / 1 FAIL)
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

$PID      = getmypid();
$TOKEN    = 'SMOKEDOR' . $PID;
$ENDPOINT = 'api/v1/equipment/units/days-on-rent.php';

$today    = date('Y-m-d');
$thisYear = (int) date('Y');
$lastYear = $thisYear - 1;

/** Inclusive day count — independent re-implementation, so a bug in
 *  HolisticLeaseEngine::inclusiveDays() cannot make the test agree with the
 *  endpoint for the wrong reason. */
$days = static function (string $a, string $b): int {
    return (int) ((new DateTimeImmutable($b))->diff(new DateTimeImmutable($a))->days) + 1;
};

// ── GET harness ──────────────────────────────────────────────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_dor_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$_GET     = json_decode(base64_decode(\$argv[2] ?? ''), true) ?: [];
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
\$_SERVER['REQUEST_METHOD'] = \$argv[4] ?? 'GET';
\$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
\$_SERVER['HTTP_HOST']      = 'localhost';
@session_start();
\$_SESSION['ff_user'] = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

/** Builds a session the way auth.php:253-262 does at login. can() resolves the
 *  factory default from $user['permissions'] — a map baked into the SESSION at
 *  login, NOT re-read from config/permissions.php per call — so a session that
 *  omits it reads as "no permissions" and every role would 403 for the wrong
 *  reason. */
$mkSession = static function (string $roleSlug): array {
    $cfg = require FF_ROOT . '/config/permissions.php';
    return [
        'id'          => 1,
        'name'        => 'DOR Smoke (' . $roleSlug . ')',
        'role_slug'   => $roleSlug,
        'permissions' => $cfg[$roleSlug] ?? [],
    ];
};

$session = $mkSession('super_admin');
// Sentinel, not null: `?? $session` would silently promote the
// unauthenticated case back to an admin session and pass T23 vacuously.
const DOR_NO_SESSION = '__none__';
$get = static function (array $params, string $method = 'GET', mixed $sessOverride = null)
       use ($harnessFile, $ENDPOINT, &$session): array {
    $sess = $sessOverride === null ? $session : ($sessOverride === DOR_NO_SESSION ? null : $sessOverride);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($ENDPOINT)
        . ' ' . escapeshellarg(base64_encode(json_encode($params)))
        . ' ' . escapeshellarg(base64_encode(json_encode($sess)))
        . ' ' . escapeshellarg($method) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"');
    if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => (string) $out];
};

$unitIds  = [];
$leaseIds = [];

/** Creates a throwaway unit whose created_at is pinned, so the "since_added"
 *  anchor is deterministic instead of "whenever this test ran". */
$mkUnit = static function (string $createdAt) use (&$unitIds, $TOKEN): int {
    static $n = 0;
    $n++;
    $tpl = (int) (db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $id = db_insert('equipment_units', [
        'template_id'    => $tpl,
        'unit_number'    => $TOKEN . '-U' . $n,
        'ownership_type' => 'owned',
        'status'         => 'available',
    ]);
    db_execute("UPDATE equipment_units SET created_at = ? WHERE id = ?", [$createdAt . ' 08:00:00', $id]);
    $unitIds[] = $id;
    return $id;
};

$mkLease = static function (int $unitId, string $start, ?string $end, ?string $actualReturn,
                            string $status = 'completed', ?string $deletedAt = null)
           use (&$leaseIds, $TOKEN): int {
    static $n = 0;
    $n++;
    $id = db_insert('leases', [
        'contract_number'    => $TOKEN . '-L' . $n,
        'equipment_unit_id'  => $unitId,
        'start_date'         => $start,
        'end_date'           => $end,
        'actual_return_date' => $actualReturn,
        'status'             => $status,
        'deleted_at'         => $deletedAt,
    ]);
    $leaseIds[] = $id;
    return $id;
};

echo str_repeat('-', 72) . "\n";
echo "DAYS ON RENT — endpoint smoke (pid={$PID}, today={$today})\n";
echo str_repeat('-', 72) . "\n";

try {
    // ── T1: inclusive day math — a one-day lease is 1 day ────────────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-06-10', $lastYear . '-06-10', $lastYear . '-06-10');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    ($r['data']['days_on_rent'] ?? null) === 1
        ? ok('T1 one-day lease counts as 1 day (inclusive of both endpoints)')
        : no('T1 expected days_on_rent=1, got ' . json_encode($r['data']['days_on_rent'] ?? $r));

    // ── T2: back-to-back leases merge into one continuous spell ──────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-01-01', $lastYear . '-01-31', $lastYear . '-01-31');
    $mkLease($u, $lastYear . '-02-01', $lastYear . '-02-28', $lastYear . '-02-28');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === 59 && count($d['periods'] ?? []) === 1)
        ? ok('T2 back-to-back Jan+Feb = 59 days in ONE merged period')
        : no('T2 expected 59 days / 1 period, got ' . json_encode([$d['days_on_rent'] ?? null, count($d['periods'] ?? [])]));

    // ── T3: OVERLAPPING leases are merged, not summed ────────────────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-03-01', $lastYear . '-03-20', $lastYear . '-03-20');  // 20d
    $mkLease($u, $lastYear . '-03-15', $lastYear . '-03-31', $lastYear . '-03-31');  // 17d, overlaps 6d
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === 31 && ($d['raw_lease_day_sum'] ?? null) === 37
        && ($d['overlap_adjusted'] ?? null) === true && count($d['periods'] ?? []) === 1)
        ? ok('T3 overlapping leases merge to 31 days (raw sum 37, overlap_adjusted=true)')
        : no('T3 expected 31/37/true/1period, got ' . json_encode([
            $d['days_on_rent'] ?? null, $d['raw_lease_day_sum'] ?? null,
            $d['overlap_adjusted'] ?? null, count($d['periods'] ?? [])]));

    // ── T4: lease straddling the window on BOTH sides is clipped ─────────
    $u = $mkUnit(($lastYear - 1) . '-01-01');
    $mkLease($u, ($lastYear - 1) . '-06-01', ($thisYear + 1) . '-06-01', null, 'active');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === ($d['window_days'] ?? -1)
        && ($d['window_days'] ?? null) === $days($lastYear . '-01-01', $lastYear . '-12-31'))
        ? ok('T4 straddling lease clipped to the window exactly (' . ($d['window_days'] ?? '?') . 'd)')
        : no('T4 expected days==window_days==365/366, got ' . json_encode([$d['days_on_rent'] ?? null, $d['window_days'] ?? null]));

    // ── T5: LADDER — completed lease, NULL actual_return_date → end_date ─
    //   The regression this exists for: omitting end_date from the COALESCE
    //   makes this lease run to the window end instead of stopping in June.
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-06-01', $lastYear . '-06-30', null, 'completed');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    ($r['data']['days_on_rent'] ?? null) === 30
        ? ok('T5 completed lease w/ NULL actual_return_date falls back to end_date (30d, not window-end)')
        : no('T5 expected 30, got ' . json_encode($r['data']['days_on_rent'] ?? $r));

    // ── T6: LADDER — actual_return_date WINS over a later end_date ───────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-06-01', $lastYear . '-06-30', $lastYear . '-06-10');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    ($r['data']['days_on_rent'] ?? null) === 10
        ? ok('T6 actual_return_date (Jun 10) beats end_date (Jun 30) → 10d')
        : no('T6 expected 10, got ' . json_encode($r['data']['days_on_rent'] ?? $r));

    // ── T7: open-ended active lease runs to TODAY, never past it ─────────
    $u = $mkUnit($thisYear . '-01-01');
    $mkLease($u, $thisYear . '-01-01', null, null, 'active');
    $r = $get(['unit_id' => $u, 'preset' => 'this_year']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === $days($thisYear . '-01-01', $today) && ($d['date_to'] ?? null) === $today)
        ? ok('T7 open-ended active lease runs to today (' . ($d['days_on_rent'] ?? '?') . 'd)')
        : no('T7 expected ' . $days($thisYear . '-01-01', $today) . ' through ' . $today
             . ', got ' . json_encode([$d['days_on_rent'] ?? null, $d['date_to'] ?? null]));

    // ── T8: soft-deleted leases are excluded ─────────────────────────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-05-01', $lastYear . '-05-31', $lastYear . '-05-31', 'completed', $lastYear . '-06-01 10:00:00');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === 0 && ($d['lease_count'] ?? null) === 0)
        ? ok('T8 soft-deleted lease excluded')
        : no('T8 expected 0/0, got ' . json_encode([$d['days_on_rent'] ?? null, $d['lease_count'] ?? null]));

    // ── T9: pending + cancelled excluded (payoff.php counts pending) ─────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-07-01', $lastYear . '-07-31', null, 'pending');
    $mkLease($u, $lastYear . '-08-01', $lastYear . '-08-31', null, 'cancelled');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    ($r['data']['days_on_rent'] ?? null) === 0
        ? ok('T9 pending + cancelled leases excluded')
        : no('T9 expected 0, got ' . json_encode($r['data']['days_on_rent'] ?? $r));

    // ── T10: "this year" window stops at today, not Dec 31 ───────────────
    $u = $mkUnit($thisYear . '-01-01');
    $r = $get(['unit_id' => $u, 'preset' => 'this_year']);
    $d = $r['data'] ?? [];
    (($d['date_from'] ?? null) === $thisYear . '-01-01' && ($d['date_to'] ?? null) === $today)
        ? ok('T10 this_year clamps date_to to today (not Dec 31)')
        : no('T10 expected ' . $thisYear . '-01-01 → ' . $today . ', got ' . json_encode([$d['date_from'] ?? null, $d['date_to'] ?? null]));

    // ── T11: "last year" is the full prior calendar year ─────────────────
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    (($d['date_from'] ?? null) === $lastYear . '-01-01' && ($d['date_to'] ?? null) === $lastYear . '-12-31')
        ? ok('T11 last_year = ' . $lastYear . '-01-01 → ' . $lastYear . '-12-31')
        : no('T11 got ' . json_encode([$d['date_from'] ?? null, $d['date_to'] ?? null]));

    // ── T12: "since added" anchors on the unit's created_at date ─────────
    $u = $mkUnit(($lastYear - 2) . '-04-15');
    $r = $get(['unit_id' => $u, 'preset' => 'since_added']);
    $d = $r['data'] ?? [];
    (($d['date_from'] ?? null) === ($lastYear - 2) . '-04-15'
        && ($d['anchor_source'] ?? null) === 'created_at' && ($d['date_to'] ?? null) === $today)
        ? ok('T12 since_added anchors on DATE(created_at)')
        : no('T12 expected ' . ($lastYear - 2) . '-04-15 → ' . $today . ', got ' . json_encode($d));

    // ── T13: utilization + idle days are internally consistent ───────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-01-01', $lastYear . '-03-31', $lastYear . '-03-31');
    $r = $get(['unit_id' => $u, 'preset' => 'last_year']);
    $d = $r['data'] ?? [];
    $w = (int) ($d['window_days'] ?? 0);
    $expPct = $w > 0 ? round(($d['days_on_rent'] ?? 0) / $w * 100, 1) : 0.0;
    (($d['idle_days'] ?? null) === $w - ($d['days_on_rent'] ?? 0) && abs(($d['utilization_pct'] ?? -1) - $expPct) < 0.05)
        ? ok('T13 idle_days + utilization_pct consistent with days/window (' . ($d['utilization_pct'] ?? '?') . '%)')
        : no('T13 inconsistent: ' . json_encode($d));

    // ── T14: INVARIANT — days_on_rent never exceeds the window ───────────
    //   The property merging buys us structurally; payoff.php:299 has to
    //   clamp with min() because it sums instead.
    $u = $mkUnit($lastYear . '-01-01');
    for ($i = 0; $i < 6; $i++) {   // six mutually overlapping full-year leases
        $mkLease($u, $lastYear . '-01-01', $lastYear . '-12-31', $lastYear . '-12-31');
    }
    $bad = [];
    foreach (['since_added', 'last_year', 'this_year'] as $p) {
        $d = $get(['unit_id' => $u, 'preset' => $p])['data'] ?? [];
        if (($d['days_on_rent'] ?? 0) > ($d['window_days'] ?? 0) || ($d['days_on_rent'] ?? -1) < 0
            || ($d['utilization_pct'] ?? 0) > 100) {
            $bad[] = $p . ':' . json_encode([$d['days_on_rent'] ?? null, $d['window_days'] ?? null, $d['utilization_pct'] ?? null]);
        }
    }
    empty($bad)
        ? ok('T14 invariant 0 <= days_on_rent <= window_days holds for every preset (6 overlapping leases)')
        : no('T14 invariant violated: ' . implode(' | ', $bad));

    // ── T15: custom range clips to the requested window ──────────────────
    $u = $mkUnit($lastYear . '-01-01');
    $mkLease($u, $lastYear . '-01-01', $lastYear . '-12-31', $lastYear . '-12-31');
    $r = $get(['unit_id' => $u, 'preset' => 'custom', 'date_from' => $lastYear . '-03-01', 'date_to' => $lastYear . '-03-31']);
    $d = $r['data'] ?? [];
    (($d['days_on_rent'] ?? null) === 31 && ($d['window_days'] ?? null) === 31)
        ? ok('T15 custom range clips a full-year lease to 31 days')
        : no('T15 expected 31/31, got ' . json_encode([$d['days_on_rent'] ?? null, $d['window_days'] ?? null]));

    // ── T16: custom range with date_to in the future is clamped to today ─
    $r = $get(['unit_id' => $u, 'preset' => 'custom', 'date_from' => $thisYear . '-01-01', 'date_to' => ($thisYear + 5) . '-12-31']);
    ($r['data']['date_to'] ?? null) === $today
        ? ok('T16 future date_to clamped to today')
        : no('T16 expected date_to=' . $today . ', got ' . json_encode($r['data']['date_to'] ?? $r));

    // ── T17: malformed custom dates → 422, not a silent rollover ─────────
    $r = $get(['unit_id' => $u, 'preset' => 'custom', 'date_from' => '2026-13-45', 'date_to' => $today]);
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('T17 invalid date_from → 422 VALIDATION_ERROR')
        : no('T17 expected VALIDATION_ERROR, got ' . json_encode($r));

    // ── T18: inverted custom range → 422 ─────────────────────────────────
    $r = $get(['unit_id' => $u, 'preset' => 'custom', 'date_from' => $lastYear . '-12-01', 'date_to' => $lastYear . '-01-01']);
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('T18 date_from after date_to → 422 VALIDATION_ERROR')
        : no('T18 expected VALIDATION_ERROR, got ' . json_encode($r));

    // ── T19: unknown preset falls back to since_added, never 500s ────────
    $r = $get(['unit_id' => $u, 'preset' => 'nonsense_preset']);
    ($r['data']['preset'] ?? null) === 'since_added'
        ? ok('T19 unknown preset falls back to since_added')
        : no('T19 expected since_added, got ' . json_encode($r['data']['preset'] ?? $r));

    // ── T20: missing unit_id → 422 ───────────────────────────────────────
    $r = $get(['preset' => 'this_year']);
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'VALIDATION_ERROR')
        ? ok('T20 missing unit_id → 422 VALIDATION_ERROR')
        : no('T20 expected VALIDATION_ERROR, got ' . json_encode($r));

    // ── T21: unknown / soft-deleted unit → 404 ───────────────────────────
    $r = $get(['unit_id' => 2147483600, 'preset' => 'this_year']);
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'NOT_FOUND')
        ? ok('T21 unknown unit_id → 404 NOT_FOUND')
        : no('T21 expected NOT_FOUND, got ' . json_encode($r));

    // ── T22: POST is rejected ────────────────────────────────────────────
    $r = $get(['unit_id' => $u], 'POST');
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED')
        ? ok('T22 POST → 405 METHOD_NOT_ALLOWED')
        : no('T22 expected METHOD_NOT_ALLOWED, got ' . json_encode($r));

    // ── T23: no session → 401 (fail-closed) ──────────────────────────────
    $r = $get(['unit_id' => $u], 'GET', DOR_NO_SESSION);
    (($r['success'] ?? true) === false && ($r['error']['code'] ?? '') === 'UNAUTHORIZED')
        ? ok('T23 unauthenticated → 401 UNAUTHORIZED')
        : no('T23 expected UNAUTHORIZED, got ' . json_encode($r));

    // ── T24: dispatcher can read it — this is equipment:view, not a money
    //         gate. Day counts carry no financial data, so the panel must
    //         not silently 403 for the operators who use it most.
    $r = $get(['unit_id' => $u], 'GET', $mkSession('dispatcher'));
    ($r['success'] ?? false) === true
        ? ok('T24 dispatcher session → 200 (equipment:view, not a financial gate)')
        : no('T24 dispatcher expected 200, got ' . json_encode($r));

} finally {
    if ($leaseIds) {
        db_execute("DELETE FROM leases WHERE id IN (" . implode(',', array_map('intval', $leaseIds)) . ")");
    }
    if ($unitIds) {
        db_execute("DELETE FROM equipment_units WHERE id IN (" . implode(',', array_map('intval', $unitIds)) . ")");
    }
    @unlink($harnessFile);
}

echo str_repeat('-', 72) . "\n";
echo "DAYS-ON-RENT — {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
