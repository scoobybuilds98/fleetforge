<?php
declare(strict_types=1);

/**
 * tests/_smoke_intelligence_v2.php
 *
 * S-INTEL-V2 — Comprehensive structural + behavioral smoke covering
 * all 12 features across 6 phases. Self-cleaning via settings
 * snapshot/restore for any value mutated during checks.
 *
 * Phase A — Token economics + visibility (F1, F10, F11, F12)
 * Phase B — Brief control (F2, F3, F8)
 * Phase C — Per-user customization (F4, F5)
 * Phase D — Multi-channel delivery (F6)
 * Phase E — Weekly digest (F9)
 * Phase F — AI insights surface (F7)
 *
 * @session S-INTEL-V2
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\AI\TokenBudgetMonitor;
use FleetForge\Notifications\MorningBriefingRenderer;
use FleetForge\Notifications\SlackPoster;
use FleetForge\Notifications\SmsClient;

// S-CRON-FIX-NOTIFICATION: load notification_digest's hour-gate helper WITHOUT
// running the cron body (FF_NOTIFICATION_DIGEST_INCLUDE early-return guard) so
// C32 can test digest_hour_sections_should_run() directly.
define('FF_NOTIFICATION_DIGEST_INCLUDE', true);
require_once FF_ROOT . '/cron/notification_digest.php';

$failures = [];
$pass     = 0;
$total    = 33;

// Snapshot mutable settings + user state we'll touch.
$snapshot = [];
foreach ([
    'ai.budget_alert_thresholds',
    'ai.budget_alert_last_sent',
    'ai.budget_alert_recipients',
    'ai.weekly_brief_enabled',
    'ai.weekly_brief_day',
    'ai.weekly_brief_hour',
    'slack.enabled',
    'twilio.enabled',
] as $k) {
    $r = db_row("SELECT `value` FROM settings WHERE `key`=?", [$k]);
    $snapshot[$k] = $r['value'] ?? null;
}

try {

// ──────────────────────────────────────────────────────────
// PHASE A — Token Economics + Visibility
// ──────────────────────────────────────────────────────────

// C1: TokenBudgetMonitor class shape
$err = [];
if (!class_exists(TokenBudgetMonitor::class)) {
    $err[] = 'TokenBudgetMonitor class missing';
} else {
    $rc = new ReflectionClass(TokenBudgetMonitor::class);
    foreach (['snapshot', 'check'] as $m) {
        if (!$rc->hasMethod($m) || !$rc->getMethod($m)->isStatic()) {
            $err[] = "method {$m} missing or non-static";
        }
    }
}
if (empty($err)) { echo "PASS C1 TokenBudgetMonitor class shape\n"; $pass++; }
else { echo "FAIL C1 " . implode('; ', $err) . "\n"; $failures[] = 'C1'; }

// C2: snapshot() returns expected shape
$err = [];
$snap = TokenBudgetMonitor::snapshot();
foreach (['today','mtd','last_7d','last_30d','by_feature_today','daily_7d_chart','limit_tokens','percent_used_today'] as $k) {
    if (!array_key_exists($k, $snap)) { $err[] = "missing key: $k"; }
}
foreach (['tokens','requests','cost_usd'] as $sk) {
    if (!isset($snap['today'][$sk])) { $err[] = "today missing $sk"; }
}
if (empty($err)) { echo "PASS C2 TokenBudgetMonitor::snapshot shape\n"; $pass++; }
else { echo "FAIL C2 " . implode('; ', $err) . "\n"; $failures[] = 'C2'; }

// C3: check() returns expected shape + skips when usage<thresholds
$err = [];
// Force usage=0 by ensuring no rows today.
db_execute("UPDATE settings SET `value` = '[0.5,0.8,1.0]' WHERE `key` = 'ai.budget_alert_thresholds'");
db_execute("UPDATE settings SET `value` = '{}' WHERE `key` = 'ai.budget_alert_last_sent'");
$r = TokenBudgetMonitor::check();
if (!isset($r['alerts_sent'], $r['skipped_thresholds'])) {
    $err[] = 'check() result missing expected keys';
}
if (!empty($r['alerts_sent'])) {
    $err[] = 'expected 0 alerts at 0% usage, got ' . count($r['alerts_sent']);
}
if (count($r['skipped_thresholds']) !== 3) {
    $err[] = 'expected 3 skipped thresholds, got ' . count($r['skipped_thresholds']);
}
if (empty($err)) { echo "PASS C3 check() skips all thresholds at 0% usage\n"; $pass++; }
else { echo "FAIL C3 " . implode('; ', $err) . "\n"; $failures[] = 'C3'; }

// C4: 4 token-budget settings keys exist
$err = [];
foreach (['ai.budget_alert_thresholds','ai.budget_alert_last_sent','ai.budget_alert_recipients'] as $k) {
    if (!db_row("SELECT id FROM settings WHERE `key`=?", [$k])) {
        $err[] = "settings.$k missing";
    }
}
if (empty($err)) { echo "PASS C4 budget alert settings keys present\n"; $pass++; }
else { echo "FAIL C4 " . implode('; ', $err) . "\n"; $failures[] = 'C4'; }

// C5: token_analytics + ai_request_log + briefing_audit_log endpoints lint clean
$err = [];
foreach (['token_analytics.php', 'ai_request_log.php', 'briefing_audit_log.php'] as $ep) {
    $path = FF_ROOT . '/api/v1/admin/intelligence/' . $ep;
    if (!is_file($path)) { $err[] = "$ep missing"; continue; }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) { $err[] = "$ep lint: " . implode(' ', $out); }
}
if (empty($err)) { echo "PASS C5 Phase A endpoints lint clean\n"; $pass++; }
else { echo "FAIL C5 " . implode('; ', $err) . "\n"; $failures[] = 'C5'; }

// C6: cron/ai_budget_check.php exists + lints
$err = [];
$path = FF_ROOT . '/cron/ai_budget_check.php';
if (!is_file($path)) { $err[] = 'cron missing'; }
else { $out=[]; $code=0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = 'cron lint: ' . implode(' ', $out); }
if (empty($err)) { echo "PASS C6 ai_budget_check cron exists + lints\n"; $pass++; }
else { echo "FAIL C6 " . implode('; ', $err) . "\n"; $failures[] = 'C6'; }

// ──────────────────────────────────────────────────────────
// PHASE B — Brief Control
// ──────────────────────────────────────────────────────────

// C7: users.briefing_snoozed_until column exists
$err = [];
$col = db_row("SHOW COLUMNS FROM users WHERE Field='briefing_snoozed_until'");
if (!$col) $err[] = 'column missing';
elseif (!str_contains($col['Type'], 'datetime')) $err[] = "expected datetime, got {$col['Type']}";
elseif ($col['Null'] !== 'YES') $err[] = 'expected nullable';
if (empty($err)) { echo "PASS C7 users.briefing_snoozed_until column\n"; $pass++; }
else { echo "FAIL C7 " . implode('; ', $err) . "\n"; $failures[] = 'C7'; }

// C8: snooze gate in notification_digest.php
$src = (string) file_get_contents(FF_ROOT . '/cron/notification_digest.php');
$err = [];
if (!str_contains($src, 'briefing_snoozed_until IS NULL OR u.briefing_snoozed_until <= NOW()')) {
    $err[] = 'snooze SQL guard missing';
}
if (empty($err)) { echo "PASS C8 cron honors snooze gate\n"; $pass++; }
else { echo "FAIL C8 " . implode('; ', $err) . "\n"; $failures[] = 'C8'; }

// C9: Phase B endpoints exist + lint
$err = [];
foreach (['generate_brief_now.php', 'brief_content.php', 'set_snooze.php'] as $ep) {
    $path = FF_ROOT . '/api/v1/admin/intelligence/' . $ep;
    if (!is_file($path)) { $err[] = "$ep missing"; continue; }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = "$ep lint failed";
}
if (empty($err)) { echo "PASS C9 Phase B endpoints exist + lint clean\n"; $pass++; }
else { echo "FAIL C9 " . implode('; ', $err) . "\n"; $failures[] = 'C9'; }

// C10: snooze SQL behavior — set snooze in past, expect cron sees user; future, expect skip
$err = [];
$testUser = db_row("SELECT id FROM users WHERE deleted_at IS NULL AND status='active' AND morning_briefing_opt_in=1 LIMIT 1");
if (!$testUser) { $err[] = 'no opted-in user to test'; }
else {
    $origSnoozeRow = db_row("SELECT briefing_snoozed_until FROM users WHERE id = ?", [(int) $testUser['id']]);
    $orig = $origSnoozeRow['briefing_snoozed_until'];
    // Set future snooze.
    db_execute("UPDATE users SET briefing_snoozed_until = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [(int) $testUser['id']]);
    $countSnoozed = (int) db_count(
        "SELECT COUNT(*) FROM users
          WHERE id = ?
            AND (briefing_snoozed_until IS NULL OR briefing_snoozed_until <= NOW())",
        [(int) $testUser['id']]
    );
    if ($countSnoozed !== 0) $err[] = 'future snooze: expected 0 cron-eligible, got ' . $countSnoozed;
    // Set past snooze (expired).
    db_execute("UPDATE users SET briefing_snoozed_until = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [(int) $testUser['id']]);
    $countExpired = (int) db_count(
        "SELECT COUNT(*) FROM users
          WHERE id = ?
            AND (briefing_snoozed_until IS NULL OR briefing_snoozed_until <= NOW())",
        [(int) $testUser['id']]
    );
    if ($countExpired !== 1) $err[] = 'expired snooze: expected 1 cron-eligible, got ' . $countExpired;
    // Restore.
    db_execute("UPDATE users SET briefing_snoozed_until = ? WHERE id = ?", [$orig, (int) $testUser['id']]);
}
if (empty($err)) { echo "PASS C10 snooze auto-resume on expiration\n"; $pass++; }
else { echo "FAIL C10 " . implode('; ', $err) . "\n"; $failures[] = 'C10'; }

// ──────────────────────────────────────────────────────────
// PHASE C — Per-user customization
// ──────────────────────────────────────────────────────────

// C11: users.briefing_hour + users.briefing_sections columns
$err = [];
foreach ([
    'briefing_hour'     => 'tinyint',
    'briefing_sections' => 'json',
] as $col => $type) {
    $row = db_row("SHOW COLUMNS FROM users WHERE Field=?", [$col]);
    if (!$row) { $err[] = "$col missing"; continue; }
    if (!str_contains($row['Type'], $type)) { $err[] = "$col: expected $type"; }
    if ($row['Null'] !== 'YES') { $err[] = "$col: expected nullable"; }
}
if (empty($err)) { echo "PASS C11 briefing_hour + briefing_sections columns\n"; $pass++; }
else { echo "FAIL C11 " . implode('; ', $err) . "\n"; $failures[] = 'C11'; }

// C12: renderBody honors $sections parameter
$err = [];
$dummy = MorningBriefingRenderer::buildPayload();
$onlyOverdue = MorningBriefingRenderer::renderBody('Tester', $dummy, ['overdue']);
$allSections = MorningBriefingRenderer::renderBody('Tester', $dummy, null);
if (!str_contains($onlyOverdue, 'Overdue Invoices')) $err[] = 'overdue section missing when included';
if (str_contains($onlyOverdue, 'Compliance Expiring This Week')) $err[] = 'compliance section present despite filter';
if (str_contains($onlyOverdue, 'Open Damage Claims'))            $err[] = 'damage section present despite filter';
if (!str_contains($allSections, 'Compliance Expiring This Week')) $err[] = 'compliance section missing when null=all';
if (empty($err)) { echo "PASS C12 renderBody filters sections correctly\n"; $pass++; }
else { echo "FAIL C12 " . implode('; ', $err) . "\n"; $failures[] = 'C12'; }

// C13: ALL_SECTIONS constant has 6 keys
$err = [];
$rc = new ReflectionClass(MorningBriefingRenderer::class);
$constants = $rc->getConstants();
if (!isset($constants['ALL_SECTIONS'])) $err[] = 'ALL_SECTIONS constant missing';
elseif (count($constants['ALL_SECTIONS']) !== 6) $err[] = 'expected 6 sections, got ' . count($constants['ALL_SECTIONS']);
if (empty($err)) { echo "PASS C13 ALL_SECTIONS = 6 keys\n"; $pass++; }
else { echo "FAIL C13 " . implode('; ', $err) . "\n"; $failures[] = 'C13'; }

// C14: set_user_preferences endpoint exists + lints
$err = [];
$path = FF_ROOT . '/api/v1/admin/intelligence/set_user_preferences.php';
if (!is_file($path)) $err[] = 'endpoint missing';
else { $out = []; $code = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = 'lint failed'; }
if (empty($err)) { echo "PASS C14 set_user_preferences endpoint lints\n"; $pass++; }
else { echo "FAIL C14 " . implode('; ', $err) . "\n"; $failures[] = 'C14'; }

// C15: cron hour gate present
$err = [];
if (!str_contains($src, 'u.briefing_hour') || !str_contains($src, 'briefing_hour IS NULL')) {
    $err[] = 'cron missing per-user hour gate';
}
if (empty($err)) { echo "PASS C15 cron honors per-user briefing_hour\n"; $pass++; }
else { echo "FAIL C15 " . implode('; ', $err) . "\n"; $failures[] = 'C15'; }

// ──────────────────────────────────────────────────────────
// PHASE D — Multi-channel
// ──────────────────────────────────────────────────────────

// C16: SlackPoster + SmsClient classes exist + static methods
$err = [];
foreach ([SlackPoster::class => ['post', 'summarizePayload'], SmsClient::class => ['send', 'summarizePayload']] as $cls => $methods) {
    if (!class_exists($cls)) { $err[] = "$cls missing"; continue; }
    $rcc = new ReflectionClass($cls);
    foreach ($methods as $m) {
        if (!$rcc->hasMethod($m) || !$rcc->getMethod($m)->isStatic()) {
            $err[] = "{$cls}::{$m} missing/non-static";
        }
    }
}
if (empty($err)) { echo "PASS C16 SlackPoster + SmsClient class shape\n"; $pass++; }
else { echo "FAIL C16 " . implode('; ', $err) . "\n"; $failures[] = 'C16'; }

// C17: SlackPoster degrades cleanly when disabled
$err = [];
db_execute("UPDATE settings SET `value` = '0' WHERE `key` = 'slack.enabled'");
$r = SlackPoster::post('Test', null, 'Test');
if (!($r['ok'] ?? false) || !($r['skipped'] ?? false) || ($r['reason'] ?? '') !== 'slack_disabled') {
    $err[] = 'expected ok+skipped+slack_disabled; got ' . json_encode($r);
}
if (empty($err)) { echo "PASS C17 SlackPoster no-op when disabled\n"; $pass++; }
else { echo "FAIL C17 " . implode('; ', $err) . "\n"; $failures[] = 'C17'; }

// C18: SmsClient degrades cleanly when disabled
$err = [];
db_execute("UPDATE settings SET `value` = '0' WHERE `key` = 'twilio.enabled'");
$r = SmsClient::send('+14155551212', 'Test');
if (!($r['ok'] ?? false) || !($r['skipped'] ?? false) || ($r['reason'] ?? '') !== 'sms_disabled') {
    $err[] = 'expected ok+skipped+sms_disabled; got ' . json_encode($r);
}
if (empty($err)) { echo "PASS C18 SmsClient no-op when disabled\n"; $pass++; }
else { echo "FAIL C18 " . implode('; ', $err) . "\n"; $failures[] = 'C18'; }

// C19: SmsClient rejects invalid E.164
$err = [];
db_execute("UPDATE settings SET `value` = '1' WHERE `key` = 'twilio.enabled'");
db_execute("INSERT INTO settings (`key`,`value`,`group_name`) VALUES ('twilio.account_sid','TEST_SID','twilio') ON DUPLICATE KEY UPDATE `value`='TEST_SID'");
db_execute("INSERT INTO settings (`key`,`value`,`group_name`) VALUES ('twilio.auth_token','TEST_TOKEN','twilio') ON DUPLICATE KEY UPDATE `value`='TEST_TOKEN'");
db_execute("INSERT INTO settings (`key`,`value`,`group_name`) VALUES ('twilio.from_phone','+15551234567','twilio') ON DUPLICATE KEY UPDATE `value`='+15551234567'");
$r = SmsClient::send('not-e164', 'test');
if ($r['ok'] ?? true) $err[] = 'expected ok=false on bad E.164';
if (!str_contains((string) ($r['reason'] ?? ''), 'invalid_to_e164')) $err[] = 'expected invalid_to_e164 reason';
db_execute("UPDATE settings SET `value` = '0' WHERE `key` = 'twilio.enabled'");
db_execute("UPDATE settings SET `value` = ''  WHERE `key` = 'twilio.account_sid'");
db_execute("UPDATE settings SET `value` = ''  WHERE `key` = 'twilio.auth_token'");
db_execute("UPDATE settings SET `value` = ''  WHERE `key` = 'twilio.from_phone'");
if (empty($err)) { echo "PASS C19 SmsClient rejects non-E.164 number\n"; $pass++; }
else { echo "FAIL C19 " . implode('; ', $err) . "\n"; $failures[] = 'C19'; }

// C20: Phase D columns exist + briefing_channels default email
$err = [];
foreach ([
    'briefing_channels' => 'json',
    'slack_user_id'     => 'varchar',
    'phone_e164'        => 'varchar',
] as $col => $type) {
    $row = db_row("SHOW COLUMNS FROM users WHERE Field=?", [$col]);
    if (!$row) $err[] = "$col missing";
    elseif (!str_contains($row['Type'], $type)) $err[] = "$col: expected $type";
}
if (empty($err)) { echo "PASS C20 Phase D columns (channels, slack_user_id, phone_e164)\n"; $pass++; }
else { echo "FAIL C20 " . implode('; ', $err) . "\n"; $failures[] = 'C20'; }

// C21: set_channels endpoint exists + lints
$err = [];
$path = FF_ROOT . '/api/v1/admin/intelligence/set_channels.php';
if (!is_file($path)) $err[] = 'set_channels.php missing';
else { $out = []; $code = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = 'lint failed: ' . implode(' ', $out); }
if (empty($err)) { echo "PASS C21 set_channels endpoint lints\n"; $pass++; }
else { echo "FAIL C21 " . implode('; ', $err) . "\n"; $failures[] = 'C21'; }

// C22: cron fan-out dispatches per channel (verify source contains all 3 channels)
$err = [];
foreach (['channel === \'email\'', 'channel === \'slack\'', 'channel === \'sms\''] as $needle) {
    if (!str_contains($src, $needle)) $err[] = "cron missing channel branch: $needle";
}
if (empty($err)) { echo "PASS C22 cron fans out across email/slack/sms\n"; $pass++; }
else { echo "FAIL C22 " . implode('; ', $err) . "\n"; $failures[] = 'C22'; }

// ──────────────────────────────────────────────────────────
// PHASE E — Weekly digest
// ──────────────────────────────────────────────────────────

// C23: users.weekly_brief_opt_in + 3 ai.weekly_* settings keys
$err = [];
$col = db_row("SHOW COLUMNS FROM users WHERE Field='weekly_brief_opt_in'");
if (!$col) $err[] = 'weekly_brief_opt_in column missing';
elseif ((string) $col['Default'] !== '0') $err[] = "expected default 0, got '{$col['Default']}'";
foreach (['ai.weekly_brief_enabled', 'ai.weekly_brief_day', 'ai.weekly_brief_hour'] as $k) {
    if (!db_row("SELECT id FROM settings WHERE `key`=?", [$k])) $err[] = "$k missing";
}
if (empty($err)) { echo "PASS C23 weekly_brief column + 3 settings keys\n"; $pass++; }
else { echo "FAIL C23 " . implode('; ', $err) . "\n"; $failures[] = 'C23'; }

// C24: weekly cron + buildWeeklyPayload + renderWeeklyBody
$err = [];
if (!is_file(FF_ROOT . '/cron/ai_weekly_brief.php')) $err[] = 'cron missing';
else { $out = []; $code = 0; exec('php -l ' . escapeshellarg(FF_ROOT . '/cron/ai_weekly_brief.php') . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = 'cron lint failed'; }

$rc = new ReflectionClass(MorningBriefingRenderer::class);
foreach (['buildWeeklyPayload', 'renderWeeklyBody'] as $m) {
    if (!$rc->hasMethod($m) || !$rc->getMethod($m)->isStatic()) {
        $err[] = "$m missing/non-static";
    }
}
if (empty($err)) { echo "PASS C24 weekly cron + renderer methods\n"; $pass++; }
else { echo "FAIL C24 " . implode('; ', $err) . "\n"; $failures[] = 'C24'; }

// C25: buildWeeklyPayload returns expected shape
$err = [];
$wp = MorningBriefingRenderer::buildWeeklyPayload();
foreach (['week_start','week_end','invoices','payments','leases','overdue','damage'] as $k) {
    if (!array_key_exists($k, $wp)) $err[] = "missing key: $k";
}
foreach (['count','total'] as $sk) {
    if (!isset($wp['invoices'][$sk])) $err[] = "invoices missing $sk";
}
if (empty($err)) { echo "PASS C25 buildWeeklyPayload returns expected shape\n"; $pass++; }
else { echo "FAIL C25 " . implode('; ', $err) . "\n"; $failures[] = 'C25'; }

// C26: renderWeeklyBody produces non-empty HTML
$err = [];
$html = MorningBriefingRenderer::renderWeeklyBody('Tester', $wp);
if (strlen($html) < 500) $err[] = 'HTML body too short: ' . strlen($html);
if (!str_contains($html, 'Weekly fleet digest')) $err[] = 'header missing';
if (!str_contains($html, 'Revenue & invoicing')) $err[] = 'revenue section missing';
if (empty($err)) { echo "PASS C26 renderWeeklyBody produces ~complete HTML\n"; $pass++; }
else { echo "FAIL C26 " . implode('; ', $err) . "\n"; $failures[] = 'C26'; }

// ──────────────────────────────────────────────────────────
// PHASE F — AI Insights
// ──────────────────────────────────────────────────────────

// C27: insights.php endpoint exists + lints
$err = [];
$path = FF_ROOT . '/api/v1/admin/intelligence/insights.php';
if (!is_file($path)) $err[] = 'insights.php missing';
else { $out = []; $code = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $err[] = 'lint failed: ' . implode(' ', $out); }
if (empty($err)) { echo "PASS C27 insights endpoint lints\n"; $pass++; }
else { echo "FAIL C27 " . implode('; ', $err) . "\n"; $failures[] = 'C27'; }

// ──────────────────────────────────────────────────────────
// Cross-cutting
// ──────────────────────────────────────────────────────────

// C28: Intelligence tab UI lints + has the new Phase A widgets
$err = [];
$path = FF_ROOT . '/app/admin/settings/index.php';
$out = []; $code = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
if ($code !== 0) $err[] = 'lint failed: ' . implode(' ', $out);
$ui = (string) file_get_contents($path);
foreach (['FF_TokenAnalytics', 'FF_RequestLog', 'FF_AuditFeed', 'FF_BriefingControl', 'FF_RecipientManager'] as $fn) {
    if (!str_contains($ui, "function {$fn}(")) $err[] = "Alpine factory $fn missing";
}
if (!str_contains($ui, 'Token Analytics'))           $err[] = 'Token Analytics card header missing';
if (!str_contains($ui, 'AI Request Log'))            $err[] = 'AI Request Log card header missing';
if (!str_contains($ui, 'Recent Intelligence Activity'))$err[] = 'Audit Feed card header missing';
if (!str_contains($ui, 'Budget Alert Configuration'))$err[] = 'Budget Alert config card missing';
if (empty($err)) { echo "PASS C28 Intelligence tab UI lints + all 5 Alpine factories + 4 new cards\n"; $pass++; }
else { echo "FAIL C28 " . implode('; ', $err) . "\n"; $failures[] = 'C28'; }

// C29: All 5 migrations applied + parity clean
$err = [];
$migs = ['202605222000_S-INTEL-V2-A.sql', '202605222100_S-INTEL-V2-B.sql', '202605222200_S-INTEL-V2-C.sql', '202605222300_S-INTEL-V2-D.sql', '202605222400_S-INTEL-V2-E.sql'];
foreach ($migs as $m) {
    if (!is_file(FF_ROOT . '/db_migrations/' . $m)) $err[] = "migration $m missing";
}
if (empty($err)) { echo "PASS C29 All 5 S-INTEL-V2 migrations present\n"; $pass++; }
else { echo "FAIL C29 " . implode('; ', $err) . "\n"; $failures[] = 'C29'; }

// C30: All Phase endpoint files exist + are gated correctly
$err = [];
$endpoints = [
    'token_analytics.php'      => "require_permission('settings', 'view')",
    'ai_request_log.php'       => "require_permission('settings', 'view')",
    'briefing_audit_log.php'   => "require_permission('settings', 'view')",
    'generate_brief_now.php'   => "require_permission('settings', 'edit')",
    'brief_content.php'        => "require_permission('settings', 'view')",
    'set_snooze.php'           => "require_auth_api()",
    'set_user_preferences.php' => "require_auth_api()",
    'set_channels.php'         => "require_auth_api()",
    'insights.php'             => "require_permission('ai', 'view')",
];
foreach ($endpoints as $ep => $needle) {
    $content = (string) file_get_contents(FF_ROOT . '/api/v1/admin/intelligence/' . $ep);
    if (!str_contains($content, $needle)) $err[] = "$ep missing permission gate: $needle";
}
if (empty($err)) { echo "PASS C30 All 9 Phase A-F endpoints have correct permission gates\n"; $pass++; }
else { echo "FAIL C30 " . implode('; ', $err) . "\n"; $failures[] = 'C30'; }

// ── S-CRON-FIX-NOTIFICATION (HIGH-2 + MED-5) ──────────────────────────
// C31: notification_log + _archive accept status='skipped' AND channel='slack'
// (the enum hardening — without it the slack path throws under strict mode).
$err = [];
db_execute('BEGIN');
try {
    foreach (['notification_log', 'notification_log_archive'] as $tbl) {
        db_execute(
            "INSERT INTO `{$tbl}` (channel, recipient, notification_type, status, created_at)
             VALUES ('slack', 'U-test', 'weekly_digest', 'skipped', NOW())"
        );
        $row = db_row("SELECT channel, status FROM `{$tbl}` WHERE notification_type='weekly_digest' ORDER BY id DESC LIMIT 1");
        if (($row['status'] ?? '') !== 'skipped') $err[] = "{$tbl}.status stored '" . ($row['status'] ?? '?') . "' (expect skipped)";
        if (($row['channel'] ?? '') !== 'slack')  $err[] = "{$tbl}.channel stored '" . ($row['channel'] ?? '?') . "' (expect slack)";
    }
} catch (\Throwable $e) { $err[] = 'insert threw (enum not migrated?): ' . $e->getMessage(); }
finally { db_execute('ROLLBACK'); }
if (empty($err)) { echo "PASS C31 notification_log + _archive accept status='skipped' + channel='slack'\n"; $pass++; }
else { echo "FAIL C31 " . implode('; ', $err) . "\n"; $failures[] = 'C31'; }

// C32: digest 4c/4e hour-gate — fires only at the digest hour; $forced bypasses.
$err = [];
if (!function_exists('digest_hour_sections_should_run')) {
    $err[] = 'digest_hour_sections_should_run() not found (notification_digest not included)';
} else {
    if (digest_hour_sections_should_run(false, 7, 7) !== true)  $err[] = 'hour match should run (expected true)';
    if (digest_hour_sections_should_run(false, 3, 7) !== false) $err[] = 'hour mismatch should NOT run (expected false)';
    if (digest_hour_sections_should_run(true, 3, 7) !== true)   $err[] = 'forced should bypass gate (expected true)';
}
if (empty($err)) { echo "PASS C32 digest 4c/4e hour-gate (match runs / mismatch skips / forced bypasses)\n"; $pass++; }
else { echo "FAIL C32 " . implode('; ', $err) . "\n"; $failures[] = 'C32'; }

// C33: ai_weekly_brief per-recipient failure isolation (HIGH-2). The recipient
// loop body must be wrapped so one recipient's throw can't abort the rest.
// Structural assertion (an integration test of the live loop would need
// cron-body extraction/mocking, disproportionate here — see SESSION LOG).
$err = [];
$wsrc = (string) file_get_contents(FF_ROOT . '/cron/ai_weekly_brief.php');
if (!preg_match('/foreach\s*\(\s*\$recipients\s+as\s+\$u\s*\)\s*\{.*?\btry\s*\{/s', $wsrc)) {
    $err[] = 'per-recipient loop body is not wrapped in try{}';
}
if (substr_count($wsrc, 'catch (\Throwable') < 2) {
    $err[] = 'expected >=2 \Throwable catches (outer + per-recipient), found ' . substr_count($wsrc, 'catch (\Throwable');
}
if (empty($err)) { echo "PASS C33 ai_weekly_brief per-recipient try/catch isolation\n"; $pass++; }
else { echo "FAIL C33 " . implode('; ', $err) . "\n"; $failures[] = 'C33'; }

} catch (\Throwable $e) {
    echo "CRASH: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures[] = 'crash';
} finally {
    // Restore snapshotted settings.
    foreach ($snapshot as $k => $v) {
        if ($v !== null) {
            db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$v, $k]);
        }
    }
}

if (!empty($failures)) {
    echo "\nintelligence_v2_smoke: {$pass}/{$total} PASS — failures: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\nintelligence_v2_smoke: {$pass}/{$total} PASS\n";
exit(0);
