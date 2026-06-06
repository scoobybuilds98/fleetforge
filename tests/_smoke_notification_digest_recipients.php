<?php
declare(strict_types=1);

/**
 * tests/_smoke_notification_digest_recipients.php
 *
 * S-AI-DIGEST-PARAM-FIX — behavioral smoke for digest_select_recipients()
 * in cron/notification_digest.php.
 *
 * WHY THIS EXISTS: the morning digest had NEVER sent in production. The
 * recipient SELECT bound `$sqlParams = $roles` FIRST and appended the hour
 * params, but the SQL places the hour-gate placeholders BEFORE
 * `ur.slug IN (...)`. So the role string bound to the hour-gate and the
 * integer hour bound to the IN-clause → `ur.slug IN (7)` → ZERO recipients
 * on every NON-forced run. The only prior coverage exercised the FORCE path
 * (hourGate='1=1', no placeholders) — which binds roles correctly and so
 * never tripped the bug. This smoke EXECUTES the real query on the NON-force
 * path against seeded data, so the class of bug cannot ship silently again.
 *
 * All seeding happens inside a BEGIN/ROLLBACK transaction; assertions test
 * MEMBERSHIP by seeded user id (never total counts), so they are robust
 * against whatever real users already exist in the DB.
 *
 * @session S-AI-DIGEST-PARAM-FIX
 * @decision D-DIGEST-PARAM-FIX (param order == SQL placeholder order)
 */

require_once __DIR__ . '/../config/app.php';

// Load notification_digest's helpers WITHOUT running the cron body
// (FF_NOTIFICATION_DIGEST_INCLUDE early-return guard at the top of the cron).
define('FF_NOTIFICATION_DIGEST_INCLUDE', true);
require_once FF_ROOT . '/cron/notification_digest.php';

$failures = [];
$pass     = 0;
$total    = 6;

/** @return int[] seeded-or-any user ids present in a recipient result set */
$ids = static fn(array $rows): array => array_map(static fn($r) => (int) $r['id'], $rows);
$has = static fn(array $rows, int $id): bool => in_array($id, $ids($rows), true);

// Resolve role_id by slug; skip the whole smoke gracefully if the canonical
// roles aren't seeded in this DB.
$roleId = static function (string $slug): ?int {
    $r = db_row("SELECT id FROM user_roles WHERE slug = ?", [$slug]);
    return $r ? (int) $r['id'] : null;
};

$need = ['super_admin', 'manager', 'accountant', 'dispatcher'];
$rids = [];
foreach ($need as $s) {
    $rids[$s] = $roleId($s);
    if ($rids[$s] === null) {
        echo "SKIP — user_roles missing slug '{$s}' (cannot seed); smoke not run.\n";
        exit(0);
    }
}

if (!function_exists('digest_select_recipients')) {
    echo "FAIL — digest_select_recipients() not defined (notification_digest not included)\n";
    exit(1);
}

// Unique email helper so seeded rows never collide with real users.
$seedEmail = static fn(string $tag): string => "sdr_smoke_{$tag}_" . getmypid() . "@fleetforge.test";

db_execute('BEGIN');
try {
    // ── Seed users ────────────────────────────────────────────────
    $mk = static function (string $tag, int $roleId, $hour, int $optIn, ?string $snooze = null) use ($seedEmail): int {
        return db_insert('users', [
            'name'                    => "SDR Smoke {$tag}",
            'email'                   => $seedEmail($tag),
            'role_id'                 => $roleId,
            'status'                  => 'active',
            'morning_briefing_opt_in' => $optIn,
            'briefing_hour'           => $hour,          // null → use global digest_hour
            'briefing_snoozed_until'  => $snooze,
        ]);
    };

    $uSuper      = $mk('super',     $rids['super_admin'], null, 1);       // NULL hour, opted in
    $uManager    = $mk('manager',   $rids['manager'],     null, 1);
    $uAccountant = $mk('acct',      $rids['accountant'],  null, 1);
    $uDispatcher = $mk('dispatch',  $rids['dispatcher'],  null, 1);       // role NOT in any test allow-list
    $uHour9      = $mk('hour9',     $rids['super_admin'], 9,    1);       // explicit per-user hour
    $uOptOut     = $mk('optout',    $rids['super_admin'], null, 0);       // opted out
    $uSnoozed    = $mk('snoozed',   $rids['super_admin'], null, 1, date('Y-m-d H:i:s', strtotime('+2 days')));

    // ── C1: NON-force, single role, hour match — THE regression guard.
    // Under the old buggy param order this returned 0 (uSuper absent).
    $err = [];
    $r = digest_select_recipients(['super_admin'], false, 7, 7);
    if (!$has($r, $uSuper))       $err[] = 'super_admin NULL-hour user NOT returned at localHour==digest_hour (the original bug)';
    if ($has($r, $uManager))      $err[] = 'manager returned but role not in allow-list';
    if ($has($r, $uOptOut))       $err[] = 'opted-out user returned';
    if ($has($r, $uSnoozed))      $err[] = 'snoozed user returned';
    if ($has($r, $uHour9))        $err[] = 'briefing_hour=9 user returned at localHour=7';
    if (empty($err)) { echo "PASS C1 non-force single-role hour-match returns the right user (regression guard)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $err) . "\n"; $failures[] = 'C1'; }

    // ── C2: NON-force, hour MISMATCH — NULL-hour user excluded.
    $err = [];
    $r = digest_select_recipients(['super_admin'], false, 3, 7);   // localHour 3 != globalHour 7
    if ($has($r, $uSuper)) $err[] = 'NULL-hour user returned at non-matching hour (hour gate broken)';
    if (empty($err)) { echo "PASS C2 non-force hour-mismatch excludes NULL-hour user\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $err) . "\n"; $failures[] = 'C2'; }

    // ── C3: NON-force, explicit per-user briefing_hour.
    $err = [];
    $r = digest_select_recipients(['super_admin'], false, 9, 7);   // localHour 9 matches uHour9
    if (!$has($r, $uHour9)) $err[] = 'briefing_hour=9 user NOT returned at localHour=9';
    if ($has($r, $uSuper))  $err[] = 'NULL-hour user returned at localHour=9 (should map to global 7)';
    if (empty($err)) { echo "PASS C3 non-force honors explicit per-user briefing_hour\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $err) . "\n"; $failures[] = 'C3'; }

    // ── C4: MULTI-ROLE — all three IN values must bind (latent bug).
    // If only one placeholder value bound, manager/accountant would be missing.
    $err = [];
    $r = digest_select_recipients(['super_admin', 'manager', 'accountant'], false, 7, 7);
    if (!$has($r, $uSuper))       $err[] = 'super_admin missing from 3-role result';
    if (!$has($r, $uManager))     $err[] = 'manager missing (2nd IN value did not bind)';
    if (!$has($r, $uAccountant))  $err[] = 'accountant missing (3rd IN value did not bind)';
    if ($has($r, $uDispatcher))   $err[] = 'dispatcher returned but role not in allow-list';
    if (empty($err)) { echo "PASS C4 multi-role allow-list binds all N IN values\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $err) . "\n"; $failures[] = 'C4'; }

    // ── C5: FORCE path still works (no regression) — hour bypassed, but
    // role + opt-in + snooze gates still apply.
    $err = [];
    $r = digest_select_recipients(['super_admin'], true, 99, 99);  // absurd hour; force bypasses
    if (!$has($r, $uSuper))   $err[] = 'force path dropped NULL-hour super_admin';
    if (!$has($r, $uHour9))   $err[] = 'force path dropped briefing_hour=9 user (hour should be bypassed)';
    if ($has($r, $uManager))  $err[] = 'force path leaked a role not in allow-list';
    if ($has($r, $uOptOut))   $err[] = 'force path leaked an opted-out user';
    if ($has($r, $uSnoozed))  $err[] = 'force path leaked a snoozed user';
    if (empty($err)) { echo "PASS C5 force path bypasses hour gate but keeps role/opt-in/snooze gates\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $err) . "\n"; $failures[] = 'C5'; }

    // ── C6: empty roles → empty result (defensive guard).
    $err = [];
    $r = digest_select_recipients([], false, 7, 7);
    if (!empty($r)) $err[] = 'empty roles allow-list returned ' . count($r) . ' rows (expected 0)';
    if (empty($err)) { echo "PASS C6 empty roles allow-list returns no recipients\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $err) . "\n"; $failures[] = 'C6'; }

} catch (\Throwable $e) {
    echo "CRASH: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures[] = 'crash';
} finally {
    db_execute('ROLLBACK');
}

if (!empty($failures)) {
    echo "\nnotification_digest_recipients_smoke: {$pass}/{$total} PASS — failures: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\nnotification_digest_recipients_smoke: {$pass}/{$total} PASS\n";
exit(0);
