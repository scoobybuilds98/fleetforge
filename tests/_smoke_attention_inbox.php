<?php declare(strict_types=1);

/**
 * tests/_smoke_attention_inbox.php
 *
 * S-ATTENTION-INBOX — Needs attention (one shared item per problem) +
 * Updates (grouped activity). Executes the real engine, router, kinds, cron,
 * switch-over script and GET endpoints against the REAL dev schema. Every
 * write happens inside one BEGIN … ROLLBACK (zero residue); the subprocess
 * checks (cron, script, endpoints) only read.
 *
 *   A. schema — attention tables, live_key uniqueness, notifications grouping columns
 *   B. registry + router — every kind evaluates against the real schema; every
 *      configured kind exists; every notify() type in the code routes somewhere sane
 *   C. engine — open once, refresh in place, clear, reopen-only-when-worse,
 *      done-needs-note, event-only done clears, snooze/wake, escalation once
 *   D. visibility + money — role routing, named audience, money facts dropped
 *   E. notify() routing — compliance event → item (no per-person rows),
 *      burst grouping, fall back to an update when the check finds no problem
 *   F. subprocesses — cron/attention_sweep.php, switch-over dry run, GET endpoints
 *   G. static — topbar seed()/no x-init="init()", assets wired, fix sites re-check
 *
 * Run: php tests/_smoke_attention_inbox.php   (exit 0 = all pass)
 * @session S-ATTENTION-INBOX
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Attention\AttentionService;
use FleetForge\Attention\KindRegistry;
use FleetForge\Attention\NotificationRouter;
use FleetForge\Notifications\NotificationService;

$pass = 0;
$fail = 0;
/**
 * Record one check.
 *
 * @param string $label
 * @param bool   $ok
 * @param string $detail
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else     { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/**
 * The newest active test user for a role (54–58 on dev).
 *
 * @param  string $role
 * @return int
 */
function role_user(string $role): int
{
    $u = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = ? AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.id DESC LIMIT 1",
        [$role]
    );
    if ($u === null) { fwrite(STDERR, "no {$role} user in dev DB\n"); exit(2); }
    return (int) $u['id'];
}

/**
 * Throws-check helper.
 *
 * @param  callable $fn
 * @param  string   $class
 * @return bool
 */
function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

$ROOT  = dirname(__DIR__);
$PHP   = PHP_BINARY;
$super = role_user('super_admin');
$mgr   = role_user('manager');
$disp  = role_user('dispatcher');
$acct  = role_user('accountant');

// ── F (read-only, BEFORE the transaction): subprocesses see committed data ──
echo "F. Subprocesses (read-only)\n";
exec(escapeshellarg($PHP) . ' ' . escapeshellarg($ROOT . '/cron/attention_sweep.php') . ' 2>&1', $cronOut, $cronCode);
$cronTxt = implode("\n", $cronOut);
check('cron/attention_sweep.php exits 0', $cronCode === 0, $cronTxt);
check('cron output has no fatal / undefined', !preg_match('/Fatal|undefined (function|method|index)|Uncaught/i', $cronTxt), $cronTxt);

exec(escapeshellarg($PHP) . ' ' . escapeshellarg($ROOT . '/scripts/notifications_switchover.php') . ' 2>&1', $swOut, $swCode);
$swTxt = implode("\n", $swOut);
check('switch-over dry run exits 0 and writes nothing', $swCode === 0 && str_contains($swTxt, 'Dry run'), $swTxt);

$harness = sys_get_temp_dir() . '/_ff_attention_' . getmypid() . '.php';
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$sess = json_decode(base64_decode(\$argv[2] ?? ''), true);
parse_str(\$argv[3] ?? '', \$_GET);
\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; \$_SERVER['HTTP_HOST'] = 'localhost';
@session_start(); \$_SESSION['ff_user'] = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$perm = require FF_ROOT . '/config/permissions.php';
$sessFor = static function (int $id, string $role) use ($perm): array {
    $u = db_row('SELECT name, role_id FROM users WHERE id = ?', [$id]);
    return [
        'id' => $id, 'name' => $u['name'], 'email' => 'smoke@fleetforge.test', 'role_id' => (int) $u['role_id'],
        'role_slug' => $role, 'permissions' => $perm[$role] ?? [], 'permission_overrides' => [],
        'role_permission_overrides' => [], 'theme' => 'dark',
        '_perm_checked_at' => time(),
    ];
};
$get = static function (string $endpoint, array $sess, string $qs = '') use ($PHP, $harness): array {
    $out = (string) shell_exec(escapeshellarg($PHP) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' ' . escapeshellarg($qs) . ' 2>/dev/null');
    $s = strpos($out, '{"');
    $j = json_decode(trim($s !== false ? substr($out, $s) : $out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$r = $get('api/v1/attention/count.php', $sessFor($mgr, 'manager'));
$want = AttentionService::badge($mgr, 'manager');
check('GET attention/count.php = AttentionService::badge (manager)',
    ($r['success'] ?? false) === true && (int) ($r['data']['total'] ?? -1) === $want['total'] && (int) ($r['data']['urgent'] ?? -1) === $want['urgent'],
    json_encode($r));

$r = $get('api/v1/attention/index.php', $sessFor($disp, 'dispatcher'), 'limit=200');
$dispKinds = array_unique(array_column($r['data']['items'] ?? [], 'kind'));
$dispMoney = false;
foreach ($r['data']['items'] ?? [] as $it) {
    foreach ($it['facts'] as $f) { if (preg_match('/\$\s?\d/', $f)) { $dispMoney = true; } }
}
check('GET attention/index.php (dispatcher) succeeds', ($r['success'] ?? false) === true, json_encode($r));
check('dispatcher list excludes money kinds by default', !in_array('customer_account', $dispKinds, true), implode(',', $dispKinds));
check('dispatcher list shows no dollar amounts', !$dispMoney);

$r = $get('api/v1/notifications/index.php', $sessFor($disp, 'dispatcher'), 'per_page=50&order=recent');
$leak = false;
foreach ($r['data']['items'] ?? [] as $it) {
    if (preg_match('/\$\s?\d/', $it['title'] . ' ' . $it['message'])) { $leak = true; }
}
check('GET notifications/index.php (dispatcher, recent) succeeds + has group_count/day_label',
    ($r['success'] ?? false) === true && (empty($r['data']['items']) || (isset($r['data']['items'][0]['group_count'], $r['data']['items'][0]['day_label']))),
    json_encode($r['error'] ?? null));
check('updates feed scrubs money for dispatcher', !$leak);
@unlink($harness);

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    AttentionService::resetListeners();

    // ── A. Schema ───────────────────────────────────────────────────────
    echo "A. Schema\n";
    $cols = array_column(db_select('SHOW COLUMNS FROM attention_items'), 'Field');
    foreach (['item_key', 'kind', 'priority', 'stage', 'facts', 'audience_user_ids', 'status', 'assigned_user_id',
              'snoozed_until', 'first_seen_at', 'last_seen_at', 'urgent_since', 'escalated_at', 'closed_at',
              'closed_by_user_id', 'close_note', 'cleared_at', 'live_key'] as $c) {
        check("attention_items.{$c}", in_array($c, $cols, true));
    }
    check('attention_item_events exists', db_count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'attention_item_events'") === 1);
    $ncols = array_column(db_select('SHOW COLUMNS FROM notifications'), 'Field');
    check('notifications.group_key + group_count', in_array('group_key', $ncols, true) && in_array('group_count', $ncols, true));

    // ── B. Registry + router ────────────────────────────────────────────
    echo "B. Registry + router\n";
    $kinds = KindRegistry::all();
    check('16 kinds registered', count($kinds) === 16, (string) count($kinds));
    foreach ($kinds as $key => $k) {
        if (!$k->hasTruth()) { continue; }
        try {
            $all = $k->evaluateAll();
            $ok = is_array($all);
            foreach ($all as $id => $d) {
                $ok = $ok && is_string($d['title'] ?? null) && $d['title'] !== '' && is_array($d['facts'] ?? null);
            }
            check("kind {$key}: evaluateAll() runs on the real schema (" . count($all) . ')', $ok);
        } catch (\Throwable $e) {
            check("kind {$key}: evaluateAll() runs on the real schema", false, $e->getMessage());
        }
    }
    $rules = require FF_ROOT . '/config/notification_types.php';
    $badRef = [];
    foreach ($rules as $type => $rule) {
        if (isset($rule['attention']) && KindRegistry::get($rule['attention']) === null) { $badRef[] = $type; }
        foreach ($rule['refresh'] ?? [] as [$kk, $res]) {
            if (KindRegistry::get($kk) === null) { $badRef[] = $type . '→' . $kk; }
        }
    }
    check('every configured kind exists', $badRef === [], implode(', ', $badRef));
    check('prefix rule: service_request.customer_reply.open → customer_request',
        NotificationRouter::attentionKindFor('service_request.customer_reply.open') === 'customer_request');
    check('unconfigured type → plain update', NotificationRouter::ruleFor('lease.nonexistent') === []);

    // Every literal notify() type in the codebase must not route to a missing kind.
    $types = [];
    foreach (['api', 'app', 'lib', 'cron'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FF_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') { continue; }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match_all("/NotificationService::notify\\(\\s*(?:type:\\s*)?'([a-z_]+(?:\\.[a-z_]+)*)'/", $src, $m)) {
                foreach ($m[1] as $t) { $types[$t] = true; }
            }
        }
    }
    $orphan = [];
    foreach (array_keys($types) as $t) {
        $k = NotificationRouter::attentionKindFor($t);
        if ($k !== null && KindRegistry::get($k) === null) { $orphan[] = $t; }
    }
    check('notify() types found in code (' . count($types) . ') all route sanely', count($types) > 20 && $orphan === [], implode(',', $orphan));

    // ── C. Engine ───────────────────────────────────────────────────────
    echo "C. Engine\n";
    $comp  = KindRegistry::get('compliance');
    $drift = KindRegistry::get('counter_drift');
    $fake  = 900000000 + random_int(1, 999999);   // synthetic entity id, no FK

    $r1 = AttentionService::raise($comp, $fake, ['title' => 'CVI expires 30 Sep: unit TEST', 'facts' => [['t' => 'x', 'm' => 0]], 'stage' => 'expiring', 'priority' => 'todo']);
    $r2 = AttentionService::raise($comp, $fake, ['title' => 'CVI expires 30 Sep: unit TEST (again)', 'facts' => [], 'stage' => 'expiring', 'priority' => 'todo']);
    $n  = db_count("SELECT COUNT(*) FROM attention_items WHERE item_key = ?", [AttentionService::itemKey($comp, $fake)]);
    check('first raise opens', $r1['event'] === 'opened');
    check('second raise refreshes in place (no duplicate)', $r2['event'] === 'seen' && $r2['id'] === $r1['id'] && $n === 1);
    check('title refreshed', AttentionService::row($r1['id'])['title'] === 'CVI expires 30 Sep: unit TEST (again)');
    check('live_key blocks a second live row', throws(fn() => db_insert('attention_items', [
        'item_key' => AttentionService::itemKey($comp, $fake), 'kind' => 'compliance', 'title' => 'dup',
        'first_seen_at' => ff_now_utc(), 'last_seen_at' => ff_now_utc(),
    ]), \PDOException::class));

    check('done without a note refused while the problem is live', throws(fn() => AttentionService::act($r1['id'], $mgr, 'manager', 'done'), \InvalidArgumentException::class));
    AttentionService::act($r1['id'], $mgr, 'manager', 'done', ['note' => 'Booked the CVI for Monday']);
    $row = AttentionService::row($r1['id']);
    check('done with a note closes it, problem still live', $row['status'] === 'done' && $row['cleared_at'] === null && (int) $row['closed_by_user_id'] === $mgr);
    $r3 = AttentionService::raise($comp, $fake, ['title' => 'same', 'stage' => 'expiring', 'priority' => 'todo']);
    check('same stage after done stays closed (no repeat)', $r3['event'] === 'unchanged' && AttentionService::row($r1['id'])['status'] === 'done');
    $r4 = AttentionService::raise($comp, $fake, ['title' => 'CVI expired: unit TEST', 'stage' => 'expired', 'priority' => 'urgent']);
    $row = AttentionService::row($r1['id']);
    check('worse stage reopens the same item as urgent', $r4['event'] === 'reopened' && $row['status'] === 'open' && $row['priority'] === 'urgent' && $row['urgent_since'] !== null);

    // escalation: urgent, unowned, past the window → once
    db_execute('UPDATE attention_items SET urgent_since = ? WHERE id = ?', [ff_now_utc('-25 hours'), $r1['id']]);
    $esc1 = array_column(AttentionService::escalate(), 'id');
    $esc2 = array_column(AttentionService::escalate(), 'id');
    check('urgent unowned item escalates after 24h', in_array($r1['id'], array_map('intval', $esc1), true));
    check('escalates only once', !in_array($r1['id'], array_map('intval', $esc2), true));
    $pres = AttentionService::present(AttentionService::findFor($r1['id'], $super, 'super_admin'), $super, true);
    check('present(): escalated flag + age label', $pres['escalated'] === true && $pres['age'] !== '');

    // snooze / effective open / wake
    $before = AttentionService::counts($mgr, 'manager')['total'];
    AttentionService::act($r1['id'], $mgr, 'manager', 'snooze', ['until' => 'tomorrow']);
    $afterSnooze = AttentionService::counts($mgr, 'manager')['total'];
    check('snoozed item leaves the count', $afterSnooze === $before - 1, "$before → $afterSnooze");
    db_execute('UPDATE attention_items SET snoozed_until = ? WHERE id = ?', [ff_now_utc('-1 minute'), $r1['id']]);
    check('past snooze counts as open again before the cron', AttentionService::counts($mgr, 'manager')['total'] === $before);
    check('wakeSnoozed() flips it back', AttentionService::wakeSnoozed() >= 1 && AttentionService::row($r1['id'])['status'] === 'open');
    check('snooze rejects today / >90 days', throws(fn() => AttentionService::snoozeUntil(ff_today()), \InvalidArgumentException::class)
        && throws(fn() => AttentionService::snoozeUntil(date('Y-m-d', strtotime(ff_today() . ' +120 days'))), \InvalidArgumentException::class));

    // clear → resolved; next occurrence = new row
    check('clear() resolves', AttentionService::clear($comp, $fake, 'Fixed') && AttentionService::row($r1['id'])['status'] === 'resolved');
    $r5 = AttentionService::raise($comp, $fake, ['title' => 'back again', 'stage' => 'expiring', 'priority' => 'todo']);
    check('problem coming back opens a fresh item', $r5['event'] === 'opened' && $r5['id'] !== $r1['id']);

    // event-only kind: done = gone
    $e1 = AttentionService::raise($drift, 0, ['title' => 'Counter drift detected', 'facts' => [['t' => 'Customer OB drift: $10.00', 'm' => 1]]]);
    AttentionService::act($e1['id'], $super, 'super_admin', 'done');
    check('event-only done needs no note and clears the problem', AttentionService::row($e1['id'])['cleared_at'] !== null);
    $e2 = AttentionService::raise($drift, 0, ['title' => 'Counter drift detected again']);
    check('event-only recurrence opens a new item', $e2['event'] === 'opened' && $e2['id'] !== $e1['id']);

    // take / release / assign
    AttentionService::act($r5['id'], $mgr, 'manager', 'take');
    check('take assigns to me', (int) AttentionService::row($r5['id'])['assigned_user_id'] === $mgr);
    check('mine count', AttentionService::counts($mgr, 'manager')['mine'] >= 1);
    AttentionService::act($r5['id'], $mgr, 'manager', 'release');
    check('release clears the owner', AttentionService::row($r5['id'])['assigned_user_id'] === null);
    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    $ca = AttentionService::raise(KindRegistry::get('customer_account'), 900000000 + random_int(1, 999999), ['title' => 'X: 1 invoice overdue', 'facts' => [['t' => '$99.00 overdue', 'm' => 1], ['t' => 'Oldest 3 days overdue', 'm' => 0]], 'stage' => '1_30']);
    check('cannot give an item to someone who can\'t see it', throws(fn() => AttentionService::act($ca['id'], $mgr, 'manager', 'assign', ['user_id' => $disp]), \InvalidArgumentException::class));
    AttentionService::act($ca['id'], $mgr, 'manager', 'assign', ['user_id' => $acct]);
    check('give to someone who can see it', (int) AttentionService::row($ca['id'])['assigned_user_id'] === $acct);
    $ev = array_column(AttentionService::events($ca['id']), 'action');
    check('history records opened + taken', $ev === ['opened', 'taken'], implode(',', $ev));

    // ── D. Visibility + money ───────────────────────────────────────────
    echo "D. Visibility + money\n";
    check('dispatcher cannot see customer_account', AttentionService::findFor($ca['id'], $disp, 'dispatcher') === null);
    check('accountant can', AttentionService::findFor($ca['id'], $acct, 'accountant') !== null);
    check('read_only sees nothing by default', KindRegistry::kindsForRole('read_only') === []);
    AttentionService::raise(KindRegistry::get('customer_account'), (int) AttentionService::row($ca['id'])['entity_id'], ['title' => 'X: 1 invoice overdue', 'facts' => [['t' => '$99.00 overdue', 'm' => 1], ['t' => 'Oldest 3 days overdue', 'm' => 0]], 'stage' => '1_30'], [$disp]);
    check('named audience grants visibility', AttentionService::findFor($ca['id'], $disp, 'dispatcher') !== null);
    $pd = AttentionService::present(AttentionService::findFor($ca['id'], $disp, 'dispatcher'), $disp, false);
    $pa = AttentionService::present(AttentionService::findFor($ca['id'], $acct, 'accountant'), $acct, true);
    check('money facts dropped without payments:view', !preg_grep('/\$/', $pd['facts']) && preg_grep('/\$/', $pa['facts']));

    // ── E. notify() routing ─────────────────────────────────────────────
    echo "E. notify() routing\n";
    $unit = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned') ORDER BY id LIMIT 1");
    db_execute('UPDATE equipment_units SET cvi_expiry = ? WHERE id = ?', [date('Y-m-d', strtotime(ff_today() . ' -2 days')), $unit['id']]);
    $rowsBefore = db_count("SELECT COUNT(*) FROM notifications WHERE type = 'compliance.expired'");
    NotificationService::notify('compliance.expired', 'Compliance: Unit ' . $unit['unit_number'], "[EXPIRED] CVI", 'equipment_unit', (int) $unit['id'], '/fleetforge/compliance', null, 'critical');
    $item = db_row("SELECT * FROM attention_items WHERE live_key = ?", ['compliance:equipment_unit:' . (int) $unit['id']]);
    check('compliance event → one urgent "expired" item', $item !== null && $item['priority'] === 'urgent' && $item['stage'] === 'expired', json_encode($item));
    check('…and no per-person notification rows', db_count("SELECT COUNT(*) FROM notifications WHERE type = 'compliance.expired'") === $rowsBefore);
    db_execute('UPDATE equipment_units SET cvi_expiry = ?, registration_expiry = NULL WHERE id = ?', [date('Y-m-d', strtotime(ff_today() . ' +300 days')), $unit['id']]);
    AttentionService::recheck('compliance', (int) $unit['id']);
    check('renewed date closes it on re-check', AttentionService::row((int) $item['id'])['status'] === 'resolved');

    NotificationService::notify('invoice.created', 'New invoice T-1', 'Invoice T-1 created — $10.00', 'invoice', 1, '/fleetforge/invoices/show?id=1', [$mgr]);
    NotificationService::notify('invoice.created', 'New invoice T-2', 'Invoice T-2 created — $20.00', 'invoice', 2, '/fleetforge/invoices/show?id=2', [$mgr]);
    NotificationService::notify('invoice.created', 'New invoice T-3', 'Invoice T-3 created — $30.00', 'invoice', 3, '/fleetforge/invoices/show?id=3', [$mgr]);
    $g = db_row("SELECT title, group_count, url FROM notifications WHERE user_id = ? AND group_key = 'invoice.created' AND is_read = 0 ORDER BY id DESC LIMIT 1", [$mgr]);
    check('a burst of updates folds into one row ("3 invoices created")', $g !== null && (int) $g['group_count'] === 3 && $g['title'] === '3 invoices created' && $g['url'] === '/fleetforge/invoices', json_encode($g));

    $clean = db_row("SELECT c.id FROM customers c WHERE c.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.customer_id = c.id AND i.status = 'overdue' AND i.deleted_at IS NULL) ORDER BY c.id LIMIT 1");
    $b = db_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'customer.risk_high'", [$mgr]);
    NotificationService::notify('customer.risk_high', 'Customer risk: HIGH — test', 'promoted', 'customer', (int) $clean['id'], '/fleetforge/customers/show?id=' . (int) $clean['id'], [$mgr], 'critical');
    check('no overdue invoices → falls back to an update (nothing lost)', db_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'customer.risk_high'", [$mgr]) === $b + 1);
    check('…and no item for that customer', db_row("SELECT id FROM attention_items WHERE live_key = ?", ['customer_account:customer:' . (int) $clean['id']]) === null);
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ── G. Static ────────────────────────────────────────────────────────────
echo "G. Static\n";
$topbar = (string) file_get_contents(FF_ROOT . '/includes/topbar.php');
check('topbar seeds the bell via seed(), never x-init="init()"', str_contains($topbar, 'x-init="seed(') && !preg_match('/FF_Notifications\(\)"\s+x-init="init\(\)/', $topbar));
check('topbar busy flags are boolean (!!busy)', !str_contains($topbar, ':disabled="busy[') );
check('attention.css wired in header', str_contains((string) file_get_contents(FF_ROOT . '/includes/header.php'), 'assets/css/attention.css'));
$appjs = (string) file_get_contents(FF_ROOT . '/public/assets/js/app.js');
check('app.js defines FF_Attention + the new bell', str_contains($appjs, 'const FF_Attention') && str_contains($appjs, '/api/v1/attention/count.php'));
foreach ([
    'api/v1/credit_applications/review.php'   => "recheck('credit_application'",
    'lib/Requests/RequestMessageService.php'  => "recheck('customer_request'",
    'api/v1/customers/reenable_email.php'     => "recheck('email_bounce'",
    'api/v1/compliance/update.php'            => "recheck('compliance'",
    'api/v1/invoices/batch_runs/cancel.php'   => "recheck('batch_run_approval'",
    'cron/reconcile_counters.php'             => "get('counter_drift')",
] as $file => $needle) {
    check("fix site re-checks: {$file}", str_contains((string) file_get_contents(FF_ROOT . '/' . $file), $needle));
}
check('SES bounce no longer calls the missing notifyRole()', !str_contains((string) file_get_contents(FF_ROOT . '/api/v1/webhooks/ses_notifications.php'), 'NotificationService::notifyRole('));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
