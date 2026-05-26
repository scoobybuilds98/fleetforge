#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * tests/_smoke_settings_roundtrip.php
 *
 * Exhaustive roundtrip test for every setting key exposed by the
 * Settings UI. For each key we:
 *   1. snapshot the current value
 *   2. compute a NEW value safe for the value_type
 *   3. simulate the form submit (calls the same handler logic)
 *   4. read the value back via settings_get()
 *   5. assert it matches what we wrote
 *   6. restore the original
 *
 * Groups covered (matches the rendering in app/admin/settings/index.php):
 *   General:       company, invoices, leases, maintenance, alerts,
 *                  notifications, yards, currency
 *   Integrations:  gps, email, storage, aws, security
 *   Intelligence:  ai, slack, twilio
 *
 * Run:  php tests/_smoke_settings_roundtrip.php
 */

require_once dirname(__DIR__) . '/config/app.php';

$ok    = 0;
$fail  = 0;
$skip  = 0;
$lines = [];

function rec(string $tag, string $msg): void {
    global $ok, $fail, $skip, $lines;
    if     ($tag === 'PASS') { $ok++;   $lines[] = "  PASS  $msg"; }
    elseif ($tag === 'FAIL') { $fail++; $lines[] = "  FAIL  $msg"; }
    else                     { $skip++; $lines[] = "  SKIP  $msg"; }
}

/**
 * Same handler as app/admin/settings/index.php — kept in sync.
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

    $secretKeys = [
        'gps.samsara_api_key', 'gps.samsara_org_id', 'gps.geotab_password',
        'ai.anthropic_api_key', 'email.smtp_pass',
        'aws.access_key_id', 'aws.secret_access_key',
        'slack.webhook_url', 'twilio.account_sid', 'twilio.auth_token',
    ];

    foreach ($groupKeys as $setting) {
        $key       = $setting['key'];
        $valueType = $setting['value_type'];
        $postKey   = str_replace('.', '_', $key);
        $raw       = $fakePost[$postKey] ?? null;

        if (in_array($key, $secretKeys, true) && is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || str_starts_with($trimmed, '•')) continue;
        }

        if ($valueType === 'boolean') {
            $val = isset($fakePost[$postKey]) ? '1' : '0';
        } elseif ($valueType === 'integer') {
            $val = $raw !== null ? (string)(int)$raw : '0';
        } elseif ($valueType === 'decimal') {
            $val = $raw !== null ? (string) preg_replace('/[^0-9.\-]/', '', (string) $raw) : '0';
        } elseif ($key === 'security.mfa.required_roles'
               || $key === 'ai.briefing_recipient_roles'
               || $key === 'ai.budget_alert_recipients') {
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
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$val, $key]);
    }
}

/**
 * Build a sample POST value for a key based on its value_type. Returns
 * a tuple [postValue, expectedDbValue]. Returns null,null when the key
 * is a JSON/special field — those have their own tests.
 */
function sample_value(string $key, string $valueType): array {
    switch ($valueType) {
        case 'boolean': return ['1',          '1'];
        case 'integer': return ['42',         '42'];
        case 'decimal': return ['3.14',       '3.14'];
        case 'json':    return [null, null];          // special — skip in roundtrip
        case 'text':
        case 'string':
        default:        return ['test_' . substr(md5($key), 0, 8), 'test_' . substr(md5($key), 0, 8)];
    }
}

// ── 1. Snapshot ALL settings that the UI can touch ─────────────────────
// S-COUNTER-DRIFT-SECOND-PATH-AUDIT (2026-05-26): snapshot intentionally
// does NOT filter by `label IS NOT NULL`. simulate_post() iterates over
// ALL keys in the group when _form_keys is not provided (matching the
// real settings-form handler) — including label-NULL housekeeping keys
// like `invoice.next_number.YYYY` and `acc.next_je_number.YYYY` that
// would otherwise be silently overwritten to '0' (integer default) by
// simulate_post and never restored. The snapshot must cover everything
// simulate_post could mutate, so the restore at the bottom of the file
// returns the DB to its pre-test state.
$uiGroups = [
    'company','invoices','leases','maintenance','alerts','notifications','yards',
    'gps','email','storage','aws','security','ai','slack','twilio','currency',
];
$snapshotRows = db_select(
    "SELECT `key`, `value` FROM settings WHERE group_name IN (" .
    implode(',', array_fill(0, count($uiGroups), '?')) . ")",
    $uiGroups
);
$snapshot = [];
foreach ($snapshotRows as $r) { $snapshot[$r['key']] = $r['value']; }
$lines[] = "Snapshotted " . count($snapshot) . " settings keys.";
$lines[] = "";

// ── 2. Iterate each group, build a POST, verify roundtrip ──────────────
foreach ($uiGroups as $grp) {
    $lines[] = "─── Group: $grp ───";
    $keys = db_select(
        "SELECT `key`, value_type FROM settings WHERE group_name = ? AND label IS NOT NULL ORDER BY `key`",
        [$grp]
    );
    if (empty($keys)) { rec('SKIP', "$grp: no UI-visible keys"); $lines[] = ''; continue; }

    // Currency uses its own handler in the real code; skip the loop sim.
    if ($grp === 'currency') {
        $lines[] = "  (currency uses a dedicated handler — see _smoke_currency_form)";
        rec('SKIP', "currency: separate handler, exercised in dedicated test");
        $lines[] = '';
        continue;
    }

    $post = ['_group' => $grp];
    $expectations = [];
    foreach ($keys as $row) {
        $key      = $row['key'];
        $vtype    = $row['value_type'];
        $postKey  = str_replace('.', '_', $key);

        if ($vtype === 'json') {
            // Skip JSON-shaped fields here; they have dedicated tests.
            $expectations[$key] = ['skip', null];
            continue;
        }
        [$postVal, $dbVal] = sample_value($key, $vtype);
        if ($postVal === null) {
            $expectations[$key] = ['skip', null];
            continue;
        }
        $post[$postKey] = $postVal;
        $expectations[$key] = ['check', $dbVal];
    }

    simulate_post($post);

    foreach ($expectations as $key => [$mode, $expected]) {
        if ($mode === 'skip') continue;
        $actual = (string) settings_get($key);
        if ($actual === $expected) {
            rec('PASS', "$key = " . substr($actual, 0, 30));
        } else {
            rec('FAIL', "$key expected='$expected' actual='$actual'");
        }
    }
    $lines[] = '';
}

// ── 3. Specific JSON-key tests ─────────────────────────────────────────
$lines[] = "─── JSON-shaped keys ───";

// security.mfa.required_roles
simulate_post([
    '_group' => 'security',
    'security_mfa_required_roles' => ['super_admin', 'manager'],
]);
$got = (string) settings_get('security.mfa.required_roles');
rec($got === '["super_admin","manager"]' ? 'PASS' : 'FAIL',
    "security.mfa.required_roles got=$got");

// ai.briefing_recipient_roles via _form_keys (real form pattern)
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.briefing_recipient_roles'],
    'ai_briefing_recipient_roles' => ['super_admin', 'accountant'],
]);
$got = (string) settings_get('ai.briefing_recipient_roles');
rec($got === '["super_admin","accountant"]' ? 'PASS' : 'FAIL',
    "ai.briefing_recipient_roles got=$got");

// ai.budget_alert_thresholds (parse + sort + clamp)
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.budget_alert_thresholds'],
    'ai_budget_alert_thresholds' => '[1.0, 0.5, 0.8, 99, -1]',  // 99 and -1 must be dropped
]);
$got = (string) settings_get('ai.budget_alert_thresholds');
rec($got === '[0.5,0.8,1]' ? 'PASS' : 'FAIL',
    "ai.budget_alert_thresholds sanitized got=$got");

// ai.budget_alert_recipients
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.budget_alert_recipients'],
    'ai_budget_alert_recipients' => ['super_admin'],
]);
$got = (string) settings_get('ai.budget_alert_recipients');
rec($got === '["super_admin"]' ? 'PASS' : 'FAIL',
    "ai.budget_alert_recipients got=$got");

$lines[] = '';

// ── 4. Secret-field placeholder behavior ───────────────────────────────
$lines[] = "─── Secret-field placeholders (mask must NOT overwrite) ───";

db_execute("UPDATE settings SET value='real_secret_xyz' WHERE `key`='ai.anthropic_api_key'");
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.anthropic_api_key'],
    'ai_anthropic_api_key' => '•••••••• (masked)',  // placeholder
]);
$got = (string) settings_get('ai.anthropic_api_key');
rec($got === 'real_secret_xyz' ? 'PASS' : 'FAIL',
    "Masked placeholder did not overwrite secret  got=$got");

// Empty string also should not overwrite
db_execute("UPDATE settings SET value='another_secret' WHERE `key`='ai.anthropic_api_key'");
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.anthropic_api_key'],
    'ai_anthropic_api_key' => '   ',  // whitespace only
]);
$got = (string) settings_get('ai.anthropic_api_key');
rec($got === 'another_secret' ? 'PASS' : 'FAIL',
    "Empty/whitespace secret did not overwrite  got=$got");

// Real new value DOES overwrite
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.anthropic_api_key'],
    'ai_anthropic_api_key' => 'sk-ant-replacement-key',
]);
$got = (string) settings_get('ai.anthropic_api_key');
rec($got === 'sk-ant-replacement-key' ? 'PASS' : 'FAIL',
    "Real new secret DID overwrite  got=$got");

$lines[] = '';

// ── 5. Boolean unchecked = '0' ──────────────────────────────────────────
$lines[] = "─── Boolean unchecked semantics ───";

db_execute("UPDATE settings SET value='1' WHERE `key`='ai.enabled'");
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.enabled'],
    // ai_enabled OMITTED — simulates unchecked
]);
$got = (string) settings_get('ai.enabled');
rec($got === '0' ? 'PASS' : 'FAIL',
    "Unchecked boolean cleared to 0  got=$got");

db_execute("UPDATE settings SET value='0' WHERE `key`='ai.enabled'");
simulate_post([
    '_group' => 'ai',
    '_form_keys' => ['ai.enabled'],
    'ai_enabled' => '1',
]);
$got = (string) settings_get('ai.enabled');
rec($got === '1' ? 'PASS' : 'FAIL',
    "Checked boolean set to 1  got=$got");

$lines[] = '';

// ── 5b. Currency (dedicated handler — markup pct with strict validation) ─
$lines[] = "─── Currency form (dedicated handler) ───";

// Replicate the real handler's logic so we test it end-to-end.
function simulate_currency_post(string $rawMarkup): array {
    $error = null;
    $val   = null;

    if ($rawMarkup !== '' && ltrim($rawMarkup)[0] === '-') {
        return ['USD → CAD markup must be between 0% and 20%.', null];
    }
    $newMarkup = (string) preg_replace('/[^0-9.]/', '', $rawMarkup);
    if (!is_numeric($newMarkup) || $newMarkup === '') $newMarkup = '0.0000';
    $newMarkup = number_format((float) $newMarkup, 4, '.', '');

    if (bccomp($newMarkup, '0', 4) < 0 || bccomp($newMarkup, '20', 4) > 0) {
        return ['USD → CAD markup must be between 0% and 20%.', null];
    }
    db_execute(
        "UPDATE settings SET `value` = ? WHERE `key` = 'currency.usd_cad_markup_pct'",
        [$newMarkup]
    );
    return [null, $newMarkup];
}

// Happy-path: 2.5%
[$err, $val] = simulate_currency_post('2.5');
rec($err === null && settings_get('currency.usd_cad_markup_pct') === '2.5000' ? 'PASS' : 'FAIL',
    "Currency 2.5 → 2.5000  got=" . settings_get('currency.usd_cad_markup_pct'));

// Boundary: exactly 20
[$err, $val] = simulate_currency_post('20');
rec($err === null && settings_get('currency.usd_cad_markup_pct') === '20.0000' ? 'PASS' : 'FAIL',
    "Currency 20 accepted  got=" . settings_get('currency.usd_cad_markup_pct'));

// Out of range high
[$err, $val] = simulate_currency_post('20.01');
rec($err !== null ? 'PASS' : 'FAIL',
    "Currency 20.01 rejected  err=" . ($err ?? 'NONE'));

// Negative rejected before sanitizer can strip the sign
[$err, $val] = simulate_currency_post('-1.0');
rec($err !== null ? 'PASS' : 'FAIL',
    "Currency -1.0 rejected  err=" . ($err ?? 'NONE'));

// Zero accepted
[$err, $val] = simulate_currency_post('0');
rec($err === null && settings_get('currency.usd_cad_markup_pct') === '0.0000' ? 'PASS' : 'FAIL',
    "Currency 0 accepted  got=" . settings_get('currency.usd_cad_markup_pct'));

$lines[] = '';

// ── 6. Restore snapshot ────────────────────────────────────────────────
foreach ($snapshot as $key => $value) {
    db_execute("UPDATE settings SET value=? WHERE `key`=?", [$value, $key]);
}
$lines[] = "Snapshot restored (" . count($snapshot) . " keys).";
$lines[] = '';

echo implode("\n", $lines) . "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "  PASS: $ok    FAIL: $fail    SKIP: $skip\n";
echo "════════════════════════════════════════════════════════════════\n";
exit($fail === 0 ? 0 : 1);
