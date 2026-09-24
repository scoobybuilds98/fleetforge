<?php declare(strict_types=1);

/**
 * tests/_smoke_attention_whatsapp.php
 *
 * S-ATTENTION-WHATSAPP — Needs attention on staff phones (Meta Cloud API).
 * Runs the real queue, dispatcher, summaries, update opt-ins and status
 * callbacks against the REAL dev schema with Meta replaced by a fake
 * transport (WhatsAppClient::setTransportForTesting) — nothing leaves the
 * machine. Every write is inside one BEGIN … ROLLBACK; subprocess checks
 * (cron, webhook) only read committed state.
 *
 *   A. helpers — phone normalisation, template-parameter cleaning, quiet hours
 *   B. alerts — urgent item → queued for people who can see it and chose
 *      "urgent"; to-do items wait for the summary; send-once
 *   C. dispatch — payload shape, sent + wamid, quiet-hours deferral, retry vs
 *      fail, skipped when the item was fixed before sending, switched off → skipped
 *   D. escalation — super admins only
 *   E. summary — counts, most urgent, yesterday; once per day
 *   F. updates — opted-in type queues (money scrubbed for non-financial roles);
 *      a grouped burst doesn't message again
 *   G. status callbacks — delivered/read, never backwards; webhook signature
 *   H. subprocesses + static — cron/whatsapp_dispatch.php, webhook fail-closed,
 *      secrets encrypted, settings group isolation
 *
 * Run: php tests/_smoke_attention_whatsapp.php   (exit 0 = all pass)
 * @session S-ATTENTION-WHATSAPP
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Attention\AttentionService;
use FleetForge\Attention\KindRegistry;
use FleetForge\Auth\MfaService;
use FleetForge\Notifications\NotificationService;
use FleetForge\Notifications\WhatsApp\WhatsAppClient;
use FleetForge\Notifications\WhatsApp\WhatsAppDeliveries;

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
 * Newest active test user for a role.
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
    if ($u === null) { fwrite(STDERR, "no {$role} user\n"); exit(2); }
    return (int) $u['id'];
}

/**
 * Upsert a setting inside the test transaction.
 *
 * @param string $k
 * @param string $v
 */
function set_setting(string $k, string $v): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name) VALUES (?, ?, 'string', 'whatsapp')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$k, $v]
    );
}

$ROOT = dirname(__DIR__);
$PHP  = PHP_BINARY;

// ── H (subprocesses, read-only, before the transaction) ─────────────────────
echo "H. Subprocesses\n";
exec(escapeshellarg($PHP) . ' ' . escapeshellarg($ROOT . '/cron/whatsapp_dispatch.php') . ' 2>&1', $o1, $c1);
check('cron/whatsapp_dispatch.php exits 0, no fatal', $c1 === 0 && !preg_match('/Fatal|Uncaught|undefined/i', implode("\n", $o1)), implode("\n", $o1));
$hook = sys_get_temp_dir() . '/_ff_wa_hook_' . getmypid() . '.php';
file_put_contents($hook, <<<PHP
<?php
error_reporting(E_ERROR | E_PARSE);
\$_SERVER['REQUEST_METHOD'] = \$argv[1]; \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
parse_str(\$argv[2] ?? '', \$_GET);
\$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = \$argv[3] ?? '';
register_shutdown_function(function () { echo "\\nHTTP:" . http_response_code(); });
require '{$ROOT}/api/v1/webhooks/whatsapp.php';
PHP);
$run = static fn(string $m, string $qs = '', string $sig = '') => (string) shell_exec(
    escapeshellarg($PHP) . ' ' . escapeshellarg($hook) . ' ' . escapeshellarg($m) . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg($sig) . ' 2>/dev/null'
);
$savedVerify = (string) settings_get('whatsapp.verify_token', '');
$g = $run('GET', 'hub_mode=subscribe&hub_verify_token=wrong-token-xyz&hub_challenge=123');
check('webhook GET with a wrong verify token → 403', str_contains($g, 'HTTP:403'), $g);
$p = $run('POST', '', 'sha256=deadbeef');
check('webhook POST with a bad signature → 403 (fail closed)', str_contains($p, 'HTTP:403'), $p);
@unlink($hook);

$pdo = db_pdo();
$pdo->beginTransaction();

$sent = [];   // captured fake-transport calls
try {
    AttentionService::resetListeners();

    // ── A. Helpers ───────────────────────────────────────────────────────
    echo "A. Helpers\n";
    check('10-digit number gets +1', WhatsAppClient::normalizePhone('(604) 555-0142') === '+16045550142');
    check('+44 kept, junk rejected', WhatsAppClient::normalizePhone('+44 7700 900123') === '+447700900123' && WhatsAppClient::normalizePhone('12') === null);
    check('toWaId strips +', WhatsAppClient::toWaId('+16045550142') === '16045550142');
    check('cleanParam: no newlines/tabs, no 4+ spaces, never empty',
        WhatsAppClient::cleanParam("a\nb\tc     d") === 'a · b · c   d' && WhatsAppClient::cleanParam('') === '—');
    $u0 = ['id' => 1, 'timezone' => 'America/Vancouver', 'whatsapp_quiet_start' => 21, 'whatsapp_quiet_end' => 7];
    $nightUtc = new DateTimeImmutable('2026-09-25 06:30:00', new DateTimeZone('UTC')); // 23:30 Pacific
    $dayUtc   = new DateTimeImmutable('2026-09-25 19:00:00', new DateTimeZone('UTC')); // 12:00 Pacific
    check('quiet hours: 23:30 local is quiet, waits until 07:00 local', WhatsAppDeliveries::quietUntil($u0, $nightUtc) === '2026-09-25 14:00:00',
        (string) WhatsAppDeliveries::quietUntil($u0, $nightUtc));
    check('quiet hours: noon is not quiet', WhatsAppDeliveries::quietUntil($u0, $dayUtc) === null);

    // ── Fixtures: connect WhatsApp (fake), three people ─────────────────
    set_setting('whatsapp.enabled', '1');
    set_setting('whatsapp.phone_number_id', '123456789012345');
    set_setting('whatsapp.access_token', MfaService::encryptSecret('TEST-TOKEN-abc'));
    set_setting('whatsapp.app_secret', MfaService::encryptSecret('app-secret-xyz'));
    set_setting('whatsapp.template_alert', 'fleetforge_alert');
    set_setting('whatsapp.template_summary', 'fleetforge_summary');
    check('secret round-trips through encryption', WhatsAppClient::secret('whatsapp.access_token') === 'TEST-TOKEN-abc');
    check('configured() once on + credentials', WhatsAppClient::configured() && WhatsAppClient::status() === 'on');

    $super = role_user('super_admin');
    $mgr   = role_user('manager');
    // A dispatcher WITHOUT a payments:view override (dev has PERM-TEST users
    // that were granted one — canSeeMoney() honours it, see the check below).
    $disp = 0;
    foreach (db_select("SELECT u.id, u.role_id, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
                         WHERE r.slug = 'dispatcher' AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.id") as $cand) {
        if (!WhatsAppDeliveries::canSeeMoney($cand)) { $disp = (int) $cand['id']; break; }
    }
    $granted = db_row("SELECT o.user_id AS id, u.role_id, 'dispatcher' AS role_slug FROM user_permission_overrides o JOIN users u ON u.id = o.user_id
                        JOIN user_roles r ON r.id = u.role_id WHERE r.slug = 'dispatcher' AND o.module = 'payments' AND o.action = 'view' AND o.granted = 1 LIMIT 1");
    if ($granted !== null) {
        check('a dispatcher granted payments:view by override CAN see money on WhatsApp', WhatsAppDeliveries::canSeeMoney($granted));
    }
    check('found a dispatcher without money access', $disp > 0);
    $acct  = role_user('accountant');
    // Everyone else off, so only our fixtures can receive.
    db_execute("UPDATE users SET whatsapp_mode = 'off'");
    $quiet0 = ['whatsapp_quiet_start' => 0, 'whatsapp_quiet_end' => 0]; // no quiet hours
    foreach ([[$super, '+16045550101', 'urgent'], [$mgr, '+16045550102', 'urgent'], [$disp, '+16045550103', 'urgent'], [$acct, '+16045550104', 'summary']] as [$id, $ph, $mode]) {
        db_update('users', ['phone_e164' => $ph, 'whatsapp_mode' => $mode, 'timezone' => 'America/Vancouver'] + $quiet0, 'id = ?', [$id]);
    }
    WhatsAppDeliveries::reset();
    WhatsAppClient::setTransportForTesting(function (string $url, array $headers, string $body) use (&$sent): array {
        $sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        return [200, json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TEST' . count($sent)]]])];
    });
    db_execute("DELETE FROM notification_deliveries");   // rolled back at the end

    // ── B. Alerts ────────────────────────────────────────────────────────
    echo "B. Alerts\n";
    $credit = KindRegistry::get('credit_application');   // urgent, "Right away", managers + accountants
    $fakeId = 900000000 + random_int(1, 999999);
    $item   = AttentionService::raise($credit, $fakeId, ['title' => 'Credit application to review: Smoke Co', 'facts' => [['t' => 'Signed by Test', 'm' => 0], ['t' => 'Limit $5,000.00', 'm' => 1]], 'url' => '/fleetforge/credit_applications/show?id=1']);
    $q = db_select("SELECT user_id, purpose FROM notification_deliveries WHERE attention_item_id = ? ORDER BY user_id", [$item['id']]);
    $to = array_map('intval', array_column($q, 'user_id'));
    check('urgent item → queued for super admin + manager (mode urgent, can see it)', in_array($super, $to, true) && in_array($mgr, $to, true), json_encode($to));
    check('not for the dispatcher (can\'t see credit applications)', !in_array($disp, $to, true));
    check('not for the accountant (chose summary only)', !in_array($acct, $to, true));
    AttentionService::raise($credit, $fakeId, ['title' => 'Credit application to review: Smoke Co (again)']);
    check('same item refreshed → nothing queued twice', db_count("SELECT COUNT(*) FROM notification_deliveries WHERE attention_item_id = ?", [$item['id']]) === count($q));
    $todoKind = KindRegistry::get('customer_account');     // dynamic, default summary
    $todo = AttentionService::raise($todoKind, $fakeId, ['title' => 'Smoke Co: 1 invoice overdue', 'stage' => '1_30', 'priority' => 'todo']);
    check('to-do item → nothing instant (waits for the summary)', db_count("SELECT COUNT(*) FROM notification_deliveries WHERE attention_item_id = ?", [$todo['id']]) === 0);

    // ── C. Dispatch ──────────────────────────────────────────────────────
    echo "C. Dispatch\n";
    $s = WhatsAppDeliveries::dispatchDue(50);
    check('dispatcher sends what\'s due', $s['sent'] === count($q), json_encode($s));
    $first = $sent[0]['body'] ?? [];
    check('payload: template + 4 body params + digits-only number',
        ($first['type'] ?? '') === 'template' && ($first['template']['name'] ?? '') === 'fleetforge_alert'
        && count($first['template']['components'][0]['parameters'] ?? []) === 4 && preg_match('/^\d+$/', (string) ($first['to'] ?? '')) === 1,
        json_encode($first));
    check('bearer token sent, graph URL uses the phone number ID',
        in_array('Authorization: Bearer TEST-TOKEN-abc', $sent[0]['headers'] ?? [], true) && str_contains($sent[0]['url'] ?? '', '/123456789012345/messages'));
    $row = db_row("SELECT status, provider_message_id, attempts FROM notification_deliveries WHERE attention_item_id = ? AND user_id = ?", [$item['id'], $mgr]);
    check('row marked sent with the wamid', $row['status'] === 'sent' && str_starts_with((string) $row['provider_message_id'], 'wamid.TEST') && (int) $row['attempts'] === 1);
    $mgrParams = [];
    foreach ($sent as $call) {
        if (($call['body']['to'] ?? '') === '16045550102') { $mgrParams = array_column($call['body']['template']['components'][0]['parameters'], 'text'); }
    }
    check('manager (can see money) gets the money fact', str_contains($mgrParams[2] ?? '', '$5,000.00'), json_encode($mgrParams));

    // quiet hours → deferred
    db_update('users', ['whatsapp_quiet_start' => 0, 'whatsapp_quiet_end' => 23], 'id = ?', [$mgr]);   // quiet all day
    WhatsAppDeliveries::reset();
    $it2 = AttentionService::raise($credit, $fakeId + 1, ['title' => 'Credit application to review: Quiet Co']);
    $n0 = count($sent);
    $s = WhatsAppDeliveries::dispatchDue(50);
    $mq = db_row("SELECT status, next_attempt_at FROM notification_deliveries WHERE attention_item_id = ? AND user_id = ?", [$it2['id'], $mgr]);
    check('quiet hours → deferred, not sent', $s['deferred'] >= 1 && $mq['status'] === 'queued' && $mq['next_attempt_at'] > ff_now_utc(), json_encode([$s, $mq]));
    db_update('users', $quiet0, 'id = ?', [$mgr]);
    WhatsAppDeliveries::reset();

    // fixed before it went out → skipped
    db_execute("UPDATE notification_deliveries SET next_attempt_at = UTC_TIMESTAMP() WHERE attention_item_id = ?", [$it2['id']]);
    AttentionService::act($it2['id'], $super, 'super_admin', 'done', ['note' => 'Reviewed on paper']);
    $s = WhatsAppDeliveries::dispatchDue(50);
    check('item dealt with before sending → skipped', db_row("SELECT status FROM notification_deliveries WHERE attention_item_id = ? AND user_id = ?", [$it2['id'], $mgr])['status'] === 'skipped', json_encode($s));

    // retryable vs permanent failures
    WhatsAppClient::setTransportForTesting(fn() => [429, json_encode(['error' => ['message' => 'Rate limit', 'code' => 130429]])]);
    $it3 = AttentionService::raise($credit, $fakeId + 2, ['title' => 'Credit application to review: Retry Co']);
    WhatsAppDeliveries::dispatchDue(50);
    $r3 = db_row("SELECT status, attempts, next_attempt_at, error FROM notification_deliveries WHERE attention_item_id = ? AND user_id = ?", [$it3['id'], $mgr]);
    check('rate limit → queued again with backoff', $r3['status'] === 'queued' && (int) $r3['attempts'] === 1 && $r3['next_attempt_at'] > ff_now_utc(), json_encode($r3));
    WhatsAppClient::setTransportForTesting(fn() => [400, json_encode(['error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001]])]);
    db_execute("UPDATE notification_deliveries SET next_attempt_at = UTC_TIMESTAMP() WHERE attention_item_id = ?", [$it3['id']]);
    WhatsAppDeliveries::dispatchDue(50);
    $r3 = db_row("SELECT status, error FROM notification_deliveries WHERE attention_item_id = ? AND user_id = ?", [$it3['id'], $mgr]);
    check('wrong template → failed with a plain reason', $r3['status'] === 'failed' && str_contains((string) $r3['error'], 'template'), json_encode($r3));

    // ── D. Escalation ────────────────────────────────────────────────────
    echo "D. Escalation\n";
    WhatsAppClient::setTransportForTesting(function (string $u, array $h, string $b) use (&$sent): array {
        $sent[] = ['url' => $u, 'headers' => $h, 'body' => json_decode($b, true)];
        return [200, json_encode(['messages' => [['id' => 'wamid.E' . count($sent)]]])];
    });
    $it4 = AttentionService::raise($credit, $fakeId + 3, ['title' => 'Credit application to review: Escalate Co']);
    db_execute("UPDATE attention_items SET urgent_since = ? WHERE id = ?", [ff_now_utc('-30 hours'), $it4['id']]);
    AttentionService::escalate();
    $esc = array_map('intval', array_column(db_select("SELECT user_id FROM notification_deliveries WHERE attention_item_id = ? AND purpose = 'escalation'", [$it4['id']]), 'user_id'));
    check('escalation → super admins only', $esc === [$super], json_encode($esc));

    // ── E. Summary ───────────────────────────────────────────────────────
    echo "E. Summary\n";
    $people = WhatsAppDeliveries::people(true);
    $sum = WhatsAppDeliveries::buildSummary($people[$acct], new DateTimeImmutable('now', new DateTimeZone('America/Vancouver')));
    check('summary has 5 params: urgent, to do, most urgent, yesterday, link',
        $sum !== null && count($sum['params']) === 5 && ctype_digit($sum['params'][0]) && str_contains($sum['params'][4], '/notifications'), json_encode($sum));
    $n1 = WhatsAppDeliveries::enqueueSummaries($acct);
    check('"send me today\'s summary" queues one', $n1 === 1);
    db_execute("UPDATE users SET whatsapp_summary_hour = ? WHERE id = ?", [(int) (new DateTimeImmutable('now', new DateTimeZone('America/Vancouver')))->format('G'), $acct]);
    WhatsAppDeliveries::reset();
    $a = WhatsAppDeliveries::enqueueSummaries();
    $b = WhatsAppDeliveries::enqueueSummaries();
    check('hourly run at their hour queues once; a second run the same day queues nothing', $a >= 1 && $b === 0, "$a / $b");

    // ── F. Updates ───────────────────────────────────────────────────────
    echo "F. Updates\n";
    db_update('users', ['whatsapp_updates' => json_encode(['payment.received'])], 'id = ?', [$disp]);
    WhatsAppDeliveries::reset();
    NotificationService::notify('payment.received', 'Payment received — $1,234.56', 'Payment of $1,234.56 from Smoke Co', 'payment', 999999999, '/fleetforge/payments', [$disp]);
    $up = db_row("SELECT params FROM notification_deliveries WHERE user_id = ? AND purpose = 'update' ORDER BY id DESC LIMIT 1", [$disp]);
    check('ticked update type → queued', $up !== null);
    check('money scrubbed for the dispatcher', $up !== null && !str_contains((string) $up['params'], '1,234.56'), (string) ($up['params'] ?? ''));
    $cnt = db_count("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND purpose = 'update'", [$disp]);
    NotificationService::notify('payment.received', 'Payment received — $5.00', 'Payment of $5.00', 'payment', 999999998, '/fleetforge/payments', [$disp]);
    check('a burst folding into the same row doesn\'t message again', db_count("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND purpose = 'update'", [$disp]) === $cnt);
    NotificationService::notify('lease.created', 'Lease X created', 'x', 'lease', 999999997, '/fleetforge/leases', [$disp]);
    check('unticked update type → nothing', db_count("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND purpose = 'update'", [$disp]) === $cnt);

    // ── G. Status callbacks ──────────────────────────────────────────────
    echo "G. Status callbacks\n";
    $wamid = (string) db_row("SELECT provider_message_id FROM notification_deliveries WHERE status = 'sent' AND provider_message_id IS NOT NULL LIMIT 1")['provider_message_id'];
    check('delivered applied', WhatsAppDeliveries::applyStatus($wamid, 'delivered'));
    check('read applied', WhatsAppDeliveries::applyStatus($wamid, 'read'));
    check('late "delivered" after read is ignored', !WhatsAppDeliveries::applyStatus($wamid, 'delivered') && db_row("SELECT status FROM notification_deliveries WHERE provider_message_id = ?", [$wamid])['status'] === 'read');
    $bodyRaw = '{"entry":[]}';
    check('webhook signature verifies with the app secret', WhatsAppClient::verifySignature($bodyRaw, 'sha256=' . hash_hmac('sha256', $bodyRaw, 'app-secret-xyz')));
    check('…and rejects anything else', !WhatsAppClient::verifySignature($bodyRaw, 'sha256=' . hash_hmac('sha256', $bodyRaw, 'wrong')));

    // switched off → queued messages dropped
    $it5 = AttentionService::raise($credit, $fakeId + 4, ['title' => 'Credit application to review: Off Co']);
    set_setting('whatsapp.enabled', '0');
    $s = WhatsAppDeliveries::dispatchDue(50);
    check('switched off → anything queued is skipped, not sent later', $s['skipped'] >= 1 && db_count("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'queued'") === 0, json_encode($s));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    WhatsAppClient::setTransportForTesting(null);
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ── Static ─────────────────────────────────────────────────────────────
echo "Static\n";
$settingsIndex = (string) file_get_contents(FF_ROOT . '/app/admin/settings/index.php');
check('attention/whatsapp settings stay out of the generic settings loop',
    !preg_match("/group_name IN \\([^)]*'(attention|whatsapp)'/", $settingsIndex));
check('WhatsApp secrets are encrypted on save', str_contains((string) file_get_contents(FF_ROOT . '/api/v1/attention/whatsapp_settings.php'), 'MfaService::encryptSecret('));
check('state after rollback: WhatsApp still as it was on dev', (string) settings_get('whatsapp.enabled', '0') === (string) db_row("SELECT value FROM settings WHERE `key` = 'whatsapp.enabled'")['value']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
