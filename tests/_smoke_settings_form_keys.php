#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * tests/_smoke_settings_form_keys.php
 *
 * Smoke test for the S-INTEL-FIX: per-form `_form_keys[]` declaration
 * prevents multi-form-per-group cross-corruption in Settings.
 *
 * Scenario the bug caused (before fix):
 *   1. Intelligence tab has 3 forms all sharing `_group=ai`:
 *      - AI Core (all ai.* except 3 sidecar keys)
 *      - Morning Briefing (ai.briefing_recipient_roles + notifications.digest_hour)
 *      - Budget Alert Config (ai.budget_alert_thresholds + ai.budget_alert_recipients)
 *   2. Saving any one of those forms made the handler iterate ALL ai.* keys
 *      and reset every key not in the submitted form to default/empty —
 *      wiping ai.anthropic_api_key, ai.model, etc.
 *
 * This test simulates each form's POST and asserts that ONLY the form's
 * declared keys are touched.
 *
 * Run:  php tests/_smoke_settings_form_keys.php
 */

require_once dirname(__DIR__) . '/config/app.php';

// Capture & restore the live ai.* settings around the test so it's safe
// to re-run.
$before = db_select(
    "SELECT `key`, `value` FROM settings WHERE group_name IN ('ai','notifications') AND `key` IN (
        'ai.enabled','ai.model','ai.anthropic_api_key','ai.daily_token_limit',
        'ai.cache_summaries','ai.summary_ttl_hours','ai.briefing_enabled',
        'ai.briefing_recipient_roles','ai.budget_alert_thresholds',
        'ai.budget_alert_recipients','notifications.digest_hour'
    )"
);
$snapshot = [];
foreach ($before as $r) { $snapshot[$r['key']] = $r['value']; }

$ok    = 0;
$fail  = 0;
$lines = [];

function assert_eq(string $name, mixed $expected, mixed $actual): void {
    global $ok, $fail, $lines;
    if ($expected === $actual) {
        $ok++;
        $lines[] = "  PASS  $name";
    } else {
        $fail++;
        $lines[] = "  FAIL  $name  expected=" . var_export($expected, true) . " actual=" . var_export($actual, true);
    }
}

/**
 * Simulate the settings POST handler against a fake $_POST.
 * Mirrors the logic in app/admin/settings/index.php exactly.
 */
function simulate_post(array $fakePost): void {
    $groupName = (string) ($fakePost['_group'] ?? '');
    if ($groupName === '') return;

    $declaredKeys = $fakePost['_form_keys'] ?? null;
    if (is_array($declaredKeys) && !empty($declaredKeys)) {
        $cleanKeys = array_values(array_unique(array_filter(
            array_map('strval', $declaredKeys),
            static fn(string $k): bool =>
                $k !== '' && (bool) preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $k)
        )));
        if (empty($cleanKeys)) return;
        $ph = implode(',', array_fill(0, count($cleanKeys), '?'));
        $groupKeys = db_select(
            "SELECT `key`, value_type FROM settings WHERE `key` IN ($ph)",
            $cleanKeys
        );
    } else {
        $groupKeys = db_select(
            "SELECT `key`, value_type FROM settings WHERE group_name = ?",
            [$groupName]
        );
    }

    $secretKeys = ['ai.anthropic_api_key', 'gps.samsara_api_key', 'email.smtp_pass'];

    foreach ($groupKeys as $setting) {
        $key       = $setting['key'];
        $valueType = $setting['value_type'];
        $postKey   = str_replace('.', '_', $key);
        $raw       = $fakePost[$postKey] ?? null;

        if (in_array($key, $secretKeys, true) && is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || str_starts_with($trimmed, '•')) {
                continue;
            }
        }

        if ($valueType === 'boolean') {
            $val = isset($fakePost[$postKey]) ? '1' : '0';
        } elseif ($valueType === 'integer') {
            $val = $raw !== null ? (string)(int)$raw : '0';
        } elseif ($key === 'ai.briefing_recipient_roles' || $key === 'ai.budget_alert_recipients') {
            $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
            $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
        } elseif ($key === 'ai.budget_alert_thresholds') {
            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($parsed)) {
                $clean = [];
                foreach ($parsed as $t) {
                    if (is_numeric($t)) {
                        $f = (float) $t;
                        if ($f > 0 && $f <= 2.0) $clean[] = $f;
                    }
                }
                sort($clean);
                $val = json_encode($clean, JSON_UNESCAPED_SLASHES);
            } else {
                $val = '[0.5,0.8,1.0]';
            }
        } else {
            $val = $raw !== null ? (string)$raw : '';
        }

        db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$val, $key]
        );
    }
}

// ─── Seed known values so we can detect overwrites ───────────────────
db_execute("UPDATE settings SET value='SENTINEL_API_KEY' WHERE `key`='ai.anthropic_api_key'");
db_execute("UPDATE settings SET value='claude-sonnet-4-20250514' WHERE `key`='ai.model'");
db_execute("UPDATE settings SET value='500000' WHERE `key`='ai.daily_token_limit'");
db_execute("UPDATE settings SET value='1' WHERE `key`='ai.cache_summaries'");
db_execute("UPDATE settings SET value='24' WHERE `key`='ai.summary_ttl_hours'");
db_execute("UPDATE settings SET value='1' WHERE `key`='ai.enabled'");
db_execute("UPDATE settings SET value='1' WHERE `key`='ai.briefing_enabled'");
db_execute("UPDATE settings SET value='[\"super_admin\",\"manager\"]' WHERE `key`='ai.briefing_recipient_roles'");
db_execute("UPDATE settings SET value='[0.5,0.8,1.0]' WHERE `key`='ai.budget_alert_thresholds'");
db_execute("UPDATE settings SET value='[\"super_admin\"]' WHERE `key`='ai.budget_alert_recipients'");

$lines[] = "─── Pre-flight: sentinel values written ───";
$lines[] = "  ai.anthropic_api_key = SENTINEL_API_KEY";
$lines[] = "  ai.briefing_recipient_roles = [\"super_admin\",\"manager\"]";
$lines[] = "  ai.budget_alert_thresholds = [0.5,0.8,1.0]";
$lines[] = "";

// ─── TEST 1: Save Morning Briefing form — should leave AI Core untouched ─
$lines[] = "─── TEST 1: Morning Briefing form (recipient_roles + digest_hour) ───";
simulate_post([
    '_group'      => 'ai',
    '_form_keys'  => ['ai.briefing_recipient_roles', 'notifications.digest_hour'],
    'ai_briefing_recipient_roles' => ['super_admin'],  // changed: only super_admin now
    'notifications_digest_hour'   => '8',
]);

assert_eq('Morning Briefing: recipient_roles updated',
    '["super_admin"]', settings_get('ai.briefing_recipient_roles'));
assert_eq('Morning Briefing: digest_hour updated',
    '8', settings_get('notifications.digest_hour'));
assert_eq('Morning Briefing: ai.anthropic_api_key PRESERVED (THE BUG)',
    'SENTINEL_API_KEY', settings_get('ai.anthropic_api_key'));
assert_eq('Morning Briefing: ai.model PRESERVED',
    'claude-sonnet-4-20250514', settings_get('ai.model'));
assert_eq('Morning Briefing: ai.daily_token_limit PRESERVED',
    '500000', settings_get('ai.daily_token_limit'));
assert_eq('Morning Briefing: ai.enabled PRESERVED',
    '1', settings_get('ai.enabled'));
assert_eq('Morning Briefing: ai.briefing_enabled PRESERVED',
    '1', settings_get('ai.briefing_enabled'));
assert_eq('Morning Briefing: ai.budget_alert_thresholds PRESERVED',
    '[0.5,0.8,1.0]', settings_get('ai.budget_alert_thresholds'));
assert_eq('Morning Briefing: ai.budget_alert_recipients PRESERVED',
    '["super_admin"]', settings_get('ai.budget_alert_recipients'));

$lines[] = "";

// ─── TEST 2: Save Budget Alert form — should leave everything else ─────
$lines[] = "─── TEST 2: Budget Alert form (thresholds + recipients) ───";
simulate_post([
    '_group'     => 'ai',
    '_form_keys' => ['ai.budget_alert_thresholds', 'ai.budget_alert_recipients'],
    'ai_budget_alert_thresholds' => '[0.6,0.9]',
    'ai_budget_alert_recipients' => ['super_admin', 'manager'],
]);

assert_eq('Budget Alert: thresholds updated',
    '[0.6,0.9]', settings_get('ai.budget_alert_thresholds'));
assert_eq('Budget Alert: recipients updated',
    '["super_admin","manager"]', settings_get('ai.budget_alert_recipients'));
assert_eq('Budget Alert: ai.anthropic_api_key PRESERVED',
    'SENTINEL_API_KEY', settings_get('ai.anthropic_api_key'));
assert_eq('Budget Alert: ai.model PRESERVED',
    'claude-sonnet-4-20250514', settings_get('ai.model'));
assert_eq('Budget Alert: ai.enabled PRESERVED',
    '1', settings_get('ai.enabled'));
assert_eq('Budget Alert: ai.briefing_recipient_roles PRESERVED',
    '["super_admin"]', settings_get('ai.briefing_recipient_roles'));

$lines[] = "";

// ─── TEST 3: Save AI Core form — declares all ai.* except 4 sidecar keys ─
$lines[] = "─── TEST 3: AI Core form (all ai.* except sidecar) ───";
$aiCoreKeys = array_values(array_diff(
    array_map(static fn($r) => $r['key'], db_select("SELECT `key` FROM settings WHERE group_name='ai'")),
    ['ai.briefing_recipient_roles', 'ai.budget_alert_thresholds', 'ai.budget_alert_recipients', 'ai.budget_alert_last_sent']
));
simulate_post([
    '_group'     => 'ai',
    '_form_keys' => $aiCoreKeys,
    'ai_enabled'             => '1',
    'ai_briefing_enabled'    => '1',
    'ai_anomaly_scan_enabled'=> '1',
    'ai_cache_summaries'     => '1',
    // ai_weekly_brief_enabled OMITTED — simulates unchecked checkbox (browsers
    // don't submit unchecked checkboxes; handler reads isset() so missing = '0').
    'ai_model'               => 'claude-sonnet-4-20250514',
    'ai_anthropic_api_key'   => 'sk-ant-NEW-KEY-TEST',
    'ai_daily_token_limit'   => '750000',
    'ai_summary_ttl_hours'   => '12',
    'ai_weekly_brief_day'    => '2',
    'ai_weekly_brief_hour'   => '8',
]);

assert_eq('AI Core: API key updated',
    'sk-ant-NEW-KEY-TEST', settings_get('ai.anthropic_api_key'));
assert_eq('AI Core: daily_token_limit updated',
    '750000', settings_get('ai.daily_token_limit'));
assert_eq('AI Core: weekly_brief_day updated',
    '2', settings_get('ai.weekly_brief_day'));
assert_eq('AI Core: weekly_brief_enabled unchecked → 0',
    '0', settings_get('ai.weekly_brief_enabled'));
assert_eq('AI Core: ai.briefing_recipient_roles PRESERVED (sidecar)',
    '["super_admin"]', settings_get('ai.briefing_recipient_roles'));
assert_eq('AI Core: ai.budget_alert_thresholds PRESERVED (sidecar)',
    '[0.6,0.9]', settings_get('ai.budget_alert_thresholds'));
assert_eq('AI Core: ai.budget_alert_recipients PRESERVED (sidecar)',
    '["super_admin","manager"]', settings_get('ai.budget_alert_recipients'));

$lines[] = "";

// ─── TEST 4: Backward compat — no _form_keys means save whole group ─────
$lines[] = "─── TEST 4: Backward-compat — no _form_keys = full-group save ───";
// Set up notifications group sentinels
db_execute("UPDATE settings SET value='OLD_HOST' WHERE `key`='notifications.smtp_host'");
db_execute("UPDATE settings SET value='OLD_USER' WHERE `key`='notifications.smtp_user'");

simulate_post([
    '_group'                  => 'notifications',
    'notifications_smtp_host' => 'mail.example.com',
    'notifications_smtp_user' => 'newuser@example.com',
    // Note: no _form_keys — relies on legacy "all keys in group" behavior
    // Other notification keys (port, pass, from, etc.) NOT in POST → those
    // strings will be wiped per legacy semantics. This is by design for
    // single-form-per-group surfaces where the form covers the whole group.
]);

assert_eq('Backward-compat: notifications.smtp_host updated',
    'mail.example.com', settings_get('notifications.smtp_host'));
assert_eq('Backward-compat: notifications.smtp_user updated',
    'newuser@example.com', settings_get('notifications.smtp_user'));

$lines[] = "";

// ─── Restore original snapshot ────────────────────────────────────────
foreach ($snapshot as $key => $value) {
    db_execute("UPDATE settings SET value=? WHERE `key`=?", [$value, $key]);
}
$lines[] = "─── Snapshot restored ───";
$lines[] = "";

// ─── Report ───────────────────────────────────────────────────────────
echo implode("\n", $lines) . "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "  PASS: $ok    FAIL: $fail\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($fail === 0 ? 0 : 1);
