<?php
declare(strict_types=1);

/**
 * app/admin/settings/index.php
 *
 * Comprehensive settings hub — tabbed layout with 6 sections:
 *   1. General     — company, invoices, alerts, notifications settings
 *   2. Users       — admin user management (invite, status, roles)
 *   3. Portal Users — customer portal user management (super_admin only)
 *   4. Audit Log   — filterable audit trail viewer
 *   5. System      — system info, cron status, database stats
 *   6. Integrations — GPS, AI, sensitive config (collapsed)
 *
 * Permission: settings/view to see, settings/edit to modify (super_admin only).
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @decisions D7/D30/D32
 * @session  S017, S025
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

$canEdit     = can('settings', 'edit');
$isSuperAdmin = can('settings', 'delete'); // WHY: only super_admin has delete on settings

// ── Flash message from previous save ────────────────────────────────────────
$saveFlash = $_SESSION['settings_flash'] ?? null;
$saveError = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_flash'], $_SESSION['settings_error']);

// ── Handle POST (save a group) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {

    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $saveError = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $groupName = clean_string($_POST['_group'] ?? null);

        if ($groupName === 'currency') {
            // CURRENCY-MARKUP-1: step=0.0001 precision, range 0–20, old/new audit log
            $oldMarkup = (string) (settings_get('currency.usd_cad_markup_pct', '0.0000') ?? '0.0000');
            $rawMarkup = $_POST['currency_usd_cad_markup_pct'] ?? null;
            // Reject before stripping: a leading '-' would be silently dropped by
            // the sanitizer, turning -1.0 into 1.0 and bypassing the range check.
            if ($rawMarkup !== null && ltrim((string) $rawMarkup)[0] === '-') {
                $saveError = 'USD → CAD markup must be between 0% and 20%.';
            }
            if (empty($saveError)) {
                $newMarkup = $rawMarkup !== null
                    ? (string) preg_replace('/[^0-9.]/', '', (string) $rawMarkup)
                    : '0.0000';
                if (!is_numeric($newMarkup) || $newMarkup === '') {
                    $newMarkup = '0.0000';
                }
                $newMarkup = number_format((float) $newMarkup, 4, '.', '');
            }

            if (empty($saveError) && (bccomp($newMarkup, '0', 4) < 0 || bccomp($newMarkup, '20', 4) > 0)) {
                $saveError = 'USD → CAD markup must be between 0% and 20%.';
            }
            if (empty($saveError)) {
                db_execute(
                    "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = 'currency.usd_cad_markup_pct'",
                    [$newMarkup, current_user_id()]
                );
                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'] ?? 'system',
                    'action'       => 'update',
                    'module'       => 'settings',
                    'entity_type'  => 'settings_group',
                    'entity_label' => 'currency',
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'notes'        => "USD → CAD markup updated: {$oldMarkup}% → {$newMarkup}%",
                ]);
                $saveFlash = 'Currency settings saved.';
            }

        } elseif ($groupName) {
            // S-INTEL-FIX: per-form opt-in via _form_keys[]
            //
            // The Intelligence tab has 3 distinct forms that all carry
            // _group=ai (AI Core, Morning Briefing, Budget Alert Config).
            // The original handler reloaded ALL ai.* keys and treated
            // "field missing from POST" as "user wants to clear it",
            // so saving any one of those forms WIPED the fields owned
            // by the other two — including ai.anthropic_api_key,
            // ai.model, ai.briefing_recipient_roles, etc.
            //
            // Fix: each form may declare its keys via hidden
            // <input name="_form_keys[]" value="ai.foo"> markers. When
            // present, we restrict the iteration to those keys. When
            // absent, we fall back to all-keys-in-group for backward
            // compatibility with the General / Integrations / Notifications
            // forms where one form covers its entire group.
            $declaredKeys = $_POST['_form_keys'] ?? null;
            if (is_array($declaredKeys) && !empty($declaredKeys)) {
                // Sanitize: keep only well-formed setting keys (group.subkey).
                $cleanKeys = array_values(array_unique(array_filter(
                    array_map('strval', $declaredKeys),
                    static fn(string $k): bool =>
                        $k !== '' &&
                        (bool) preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $k)
                )));
                if (!empty($cleanKeys)) {
                    $ph = implode(',', array_fill(0, count($cleanKeys), '?'));
                    $groupKeys = db_select(
                        "SELECT `key`, value_type FROM settings WHERE `key` IN ($ph)",
                        $cleanKeys
                    );
                } else {
                    $groupKeys = [];
                }
            } else {
                // Backward-compat path: all keys in the submitted group.
                //
                // S-SECURITY-SAVE-SCOPE: restrict to label IS NOT NULL so the
                // save scope MATCHES the render scope. The form only renders
                // keys with a non-NULL label (see the $allSettings query
                // below, which filters the same way), so any NULL-label key in
                // the group is never present in POST. Without this filter the
                // loop below treats "absent from POST" as "reset to type
                // default", silently clobbering hidden rows: the 13
                // security.rate_limit.* keys (NULL label, never rendered) were
                // forced to 0 every time the Security/MFA card was saved —
                // which disabled IP + MFA rate limiting (window/threshold 0).
                // Only persist what the operator could actually see and edit.
                $groupKeys = db_select(
                    "SELECT `key`, value_type FROM settings WHERE group_name = ? AND label IS NOT NULL",
                    [$groupName]
                );
            }

            // INT-1: list of keys whose values are secret. The form
            // renders these as masked password fields and submits the
            // mask placeholder when the user does not edit them — we
            // detect that placeholder and SKIP the update so the real
            // stored secret is preserved.
            $secretKeys = [
                'gps.samsara_api_key',
                'gps.samsara_org_id',
                'gps.geotab_password',
                'ai.anthropic_api_key',
                'email.smtp_pass',
                'aws.access_key_id',
                'aws.secret_access_key',
            ];

            db_transaction(function () use ($groupKeys, $groupName, $secretKeys) {
                // S-MFA-SETTINGS-RECONCILE: captured below when the
                // security.mfa.required_roles key is processed. Drives the
                // users.mfa_required reconcile that runs after the settings
                // loop. Stays null when that key is not part of this save, so
                // non-security group saves never touch user rows.
                $reconcileRoles = null;

                foreach ($groupKeys as $setting) {
                    $key       = $setting['key'];
                    $valueType = $setting['value_type'];
                    // WHY: PHP converts dots in POST field names to underscores.
                    // <input name="invoice.prefix"> becomes $_POST['invoice_prefix'].
                    // We must look up the underscore version to find the submitted value.
                    $postKey   = str_replace('.', '_', $key);
                    $raw       = $_POST[$postKey] ?? null;

                    // INT-1: secret fields submit a placeholder when unchanged.
                    // Trim and bail out so we never overwrite a real key with
                    // dots. The placeholder always starts with U+2022 (•).
                    if (in_array($key, $secretKeys, true) && is_string($raw)) {
                        $trimmed = trim($raw);
                        if ($trimmed === '' || str_starts_with($trimmed, '•')) {
                            continue; // keep existing value
                        }
                    }

                    // Normalize value by type
                    if ($valueType === 'boolean') {
                        $val = isset($_POST[$postKey]) ? '1' : '0';
                    } elseif ($valueType === 'integer') {
                        $val = $raw !== null ? (string)(int)$raw : '0';
                    } elseif ($valueType === 'decimal') {
                        $val = $raw !== null ? (string)preg_replace('/[^0-9.\-]/', '', $raw) : '0';
                    } elseif ($key === 'security.mfa.required_roles') {
                        // S-SETTINGS-CLEANUP: multi-checkbox UI submits an
                        // array of role slugs. Encode as a JSON array to match
                        // MfaService::requiredRolesList() expectations
                        // (json_decode'd as string[] of role slugs). Empty
                        // selection yields '[]' which disables the MFA
                        // requirement entirely.
                        $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                        $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
                        // S-MFA-SETTINGS-RECONCILE: remember the freshly-saved
                        // role list so the post-loop block can propagate it to
                        // users.mfa_required. An empty array (operator unchecked
                        // every role) reconciles every user to mfa_required = 0.
                        $reconcileRoles = $rolesArr;
                    } elseif ($key === 'ai.briefing_recipient_roles') {
                        // S-INTEL-TAB: multi-checkbox UI for Intelligence tab.
                        // JSON array of user_roles.slug values per D-INTEL-2;
                        // empty selection => '[]' which means no role passes
                        // the gate (effectively disables the briefing without
                        // touching ai.briefing_enabled — useful for staging
                        // the rollout).
                        $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                        $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
                    } elseif ($key === 'ai.budget_alert_recipients') {
                        // S-INTEL-V2 F12: same shape as briefing recipient roles.
                        $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                        $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
                    } elseif ($key === 'ai.budget_alert_thresholds') {
                        // S-INTEL-V2 F12: JSON array of fractions. Parse the
                        // text input, validate each entry is numeric 0..1,
                        // re-encode canonically. Bad input falls back to the
                        // default to avoid silently saving an unparseable
                        // value that would break the cron.
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
                    } elseif (preg_match('/^portal_requests\.routing\.[a-z_]+\.role_slugs$/', $key)) {
                        // S-PORTAL-REQUEST-ROUTING: multi-checkbox over user_roles.
                        // JSON array of role slugs; empty selection => '[]' which
                        // means no role applies and the default fallback bucket
                        // kicks in (D-PORTAL-REQUEST-ROUTING-4).
                        $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                        $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
                    } elseif (preg_match('/^portal_requests\.routing\.[a-z_]+\.user_ids$/', $key)) {
                        // S-PORTAL-REQUEST-ROUTING: multi-checkbox over active
                        // admin users. JSON array of ints (additive to role-
                        // derived users at dispatch time).
                        $idsArr = is_array($raw) ? array_values(array_filter(array_map('intval', $raw))) : [];
                        $val = json_encode($idsArr, JSON_UNESCAPED_SLASHES);
                    } else {
                        $val = $raw !== null ? (string)$raw : '';
                    }

                    db_execute(
                        "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = ?",
                        [$val, current_user_id(), $key]
                    );
                }

                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'] ?? 'system',
                    'action'       => 'update',
                    'module'       => 'settings',
                    'entity_type'  => 'settings_group',
                    'entity_label' => $groupName,
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'notes'        => "Settings group '{$groupName}' updated",
                ]);

                // ── S-MFA-SETTINGS-RECONCILE ───────────────────────────────
                // Propagate security.mfa.required_roles → users.mfa_required.
                //
                // WHY: app/auth/login.php gates the login challenge on the
                // per-user columns users.mfa_required / users.mfa_enabled, NOT
                // on this setting. Historically the column was only written at
                // user-create (api/v1/users/create.php), role-change
                // (api/v1/users/update.php), and via the manual CLI
                // (scripts/reconcile_mfa_required.php) — so editing the role
                // list here had ZERO effect at login. An operator who emptied
                // the list to "turn off MFA" still saw super_admins/managers
                // prompted because their mfa_required column kept its stale
                // value. Reconciling in the SAME transaction makes the setting
                // take effect immediately and atomically.
                //
                // SCOPE (D-E): only mfa_required is reconciled here. mfa_enabled
                // + mfa_secret are deliberately never touched on a settings
                // save — a user who personally enrolled an authenticator
                // (voluntary setup from My Profile) must keep their second
                // factor even when their role stops requiring it. To strip MFA
                // from a specific enrolled account, an admin uses the per-user
                // disable action (api/v1/users/disable_mfa.php) or the CLI
                // (scripts/admin_override_mfa.php).
                if ($reconcileRoles !== null) {
                    $usersForMfa = db_select(
                        "SELECT u.id, u.email, u.mfa_required, r.slug AS role_slug
                           FROM users u
                           JOIN user_roles r ON r.id = u.role_id
                          WHERE u.deleted_at IS NULL"
                    );
                    $mfaChanged = 0;
                    foreach ($usersForMfa as $uRow) {
                        $should = in_array($uRow['role_slug'], $reconcileRoles, true) ? 1 : 0;
                        if ((int) $uRow['mfa_required'] !== $should) {
                            db_execute(
                                "UPDATE users SET mfa_required = ? WHERE id = ?",
                                [$should, (int) $uRow['id']]
                            );
                            $mfaChanged++;
                        }
                    }
                    if ($mfaChanged > 0) {
                        $rolesLabel = empty($reconcileRoles) ? '(none)' : implode(', ', $reconcileRoles);
                        db_insert('audit_log', [
                            'user_id'      => current_user_id(),
                            'user_name'    => current_user()['name'] ?? 'system',
                            'action'       => 'update',
                            'module'       => 'settings',
                            'entity_type'  => 'settings_group',
                            'entity_label' => 'security',
                            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                            'notes'        => "MFA required-roles saved → reconciled mfa_required for {$mfaChanged} user(s). Required roles now: {$rolesLabel}.",
                        ]);
                    }
                }
            });

            $saveFlash = 'Settings saved successfully.';
        }
    }

    // ── POST-redirect-GET (PRG) — preserve the active tab. ─────────
    //
    // Without a redirect, the form POSTs to /settings (Alpine tab
    // state is client-only — the URL bar has no tab marker), and the
    // page reloads on the default "general" tab even when the
    // operator was editing Integrations / Intelligence. Operator
    // reported this as confusing — submitting from any non-General
    // tab snaps back to General.
    //
    // Fix: after the save handler runs, map the submitted
    // $_POST['_group'] back to the tab that hosts it, then 303-redirect
    // with ?tab=<tab>. The browser GETs the new URL, $defaultTab
    // honors it, Alpine starts on the right tab. Flash messages move
    // into $_SESSION so they survive the redirect. Standard PRG.
    //
    // Errors before form processing (CSRF fail, no _group) still fall
    // through to the page render so the operator sees the message.
    $postedGroup = isset($_POST['_group']) ? (string) $_POST['_group'] : '';
    if ($postedGroup !== '') {
        $groupToTab = [
            // General tab (primary groups + currency)
            'company'       => 'general',
            'invoices'      => 'general',
            'leases'        => 'general',
            'maintenance'   => 'general',
            'alerts'        => 'general',
            'notifications' => 'general',
            'yards'         => 'general',
            'currency'      => 'general',
            // Integrations tab (sensitive credential groups)
            'gps'           => 'integrations',
            'email'         => 'integrations',
            'storage'       => 'integrations',
            'aws'           => 'integrations',
            'security'      => 'integrations',
            // Intelligence tab (S-INTEL-V2 + delivery infra)
            'ai'            => 'intelligence',
            'slack'         => 'intelligence',
            'twilio'        => 'intelligence',
        ];
        $redirectTab = $groupToTab[$postedGroup] ?? 'general';
        if (!empty($saveFlash)) {
            $_SESSION['settings_flash'] = $saveFlash;
        }
        if (!empty($saveError)) {
            $_SESSION['settings_error'] = $saveError;
        }
        header('Location: ' . base_url('settings?tab=' . urlencode($redirectTab)), true, 303);
        exit;
    }
}

// ── Load all settings, grouped ───────────────────────────────────────────────
// INT-1: include email/storage/aws so the integrations tab can manage
// SMTP, S3, and SES credentials from the UI instead of editing .env.
// S-SETTINGS-CLEANUP: include 'security' so the 3 mfa.* rows (labels
// backfilled by migration 19) appear in the Integrations tab. The 13
// rate_limit.* rows still have NULL labels and stay hidden via the
// `label IS NOT NULL` filter (D-C — deferred to a future surface).
$allSettings = db_select(
    "SELECT `key`, `value`, value_type, group_name, label, description
     FROM settings
     WHERE group_name IN ('company','invoices','leases','maintenance','alerts','notifications','gps','ai','yards','email','storage','aws','currency','security','slack','twilio')
       AND label IS NOT NULL
     ORDER BY group_name ASC, `key` ASC"
);

$grouped = [];
foreach ($allSettings as $s) {
    $grouped[$s['group_name']][] = $s;
}

$primaryGroups   = ['company', 'invoices', 'leases', 'maintenance', 'alerts', 'notifications', 'yards'];
// S-SETTINGS-CLEANUP: 'security' added so the MFA card renders alongside
// gps/ai/email/storage/aws in the Integrations tab via the existing render loop.
// S-INTEL-TAB: 'ai' removed from Integrations — it now renders in the
// dedicated Intelligence tab. Integrations keeps the credential-management
// surfaces (GPS, SMTP, S3/SES, MFA).
// S-INTEL-V2 follow-up: 'slack' + 'twilio' added to $intelligenceGroups
// because they're delivery infrastructure for the briefing's multi-
// channel fan-out (D-INTEL-V2-6). Rendered in the Intelligence tab's
// new "Delivery Channels" card.
$sensitiveGroups    = ['gps', 'email', 'storage', 'aws', 'security'];
$intelligenceGroups = ['ai', 'slack', 'twilio'];

// INT-1: secret keys are rendered as masked password fields.
// Save handler skips writes when the masked placeholder comes back.
$secretKeys = [
    'gps.samsara_api_key',
    'gps.samsara_org_id',
    'gps.geotab_password',
    'ai.anthropic_api_key',
    'email.smtp_pass',
    'aws.access_key_id',
    'aws.secret_access_key',
    // S-INTEL-V2 Phase D: webhook URLs + Twilio creds rendered masked.
    'slack.webhook_url',
    'twilio.account_sid',
    'twilio.auth_token',
];

$groupLabels = [
    'company'       => 'Company',
    'invoices'      => 'Invoices & Billing',
    'leases'        => 'Leases & Contracts',
    'maintenance'   => 'Maintenance & Claims',
    'alerts'        => 'Alerts & Compliance',
    'notifications' => 'Notifications & Email',
    'gps'           => 'GPS Integration',
    'ai'            => 'AI / Machine Learning',
    'currency'      => 'Currency Conversion',
    'yards'         => 'Yards',
    'email'         => 'Email (SMTP / SES)',
    'storage'       => 'Storage Driver',
    'aws'           => 'AWS Credentials (S3 + SES)',
    'security'      => 'Security / MFA',
    'slack'         => 'Slack Delivery',
    'twilio'        => 'Twilio SMS Delivery',
];

// INT-1: helper to mask a secret value, showing only the last 4 chars.
// Returns '' if there is no stored value (so the field renders empty).
$maskSecret = static function (string $value): string {
    if ($value === '') return '';
    $tail = substr($value, -4);
    return str_repeat('•', 16) . $tail;
};

// S-SETTINGS-CLEANUP: roles list for the Security card's required_roles
// multi-checkbox. Loaded once here so the render loop doesn't re-query
// per render iteration. Slugs match the JSON values stored at
// settings.security.mfa.required_roles (e.g. ["super_admin","manager"]).
$mfaRolesList    = db_select("SELECT slug, name FROM user_roles ORDER BY id ASC");
$mfaRequiredJson = settings_get('security.mfa.required_roles', '[]');
$mfaRequiredSet  = [];
$decoded         = json_decode((string) $mfaRequiredJson, true);
if (is_array($decoded)) {
    foreach ($decoded as $slug) {
        if (is_string($slug)) $mfaRequiredSet[$slug] = true;
    }
}

// ── Stats for tab badges ──────────────────────────────────────────────────────
$userCount = db_count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
$portalUserCount = db_count("SELECT COUNT(*) FROM portal_users");
$recentAuditCount = db_count("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");

$pageTitle = 'Settings';
$helpModuleSlug = 'settings';
require_once FF_ROOT . '/includes/header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// WHY: default tab can come from URL param (e.g. after redirect from sub-page)
$defaultTab = clean_string($_GET['tab'] ?? 'general');
// S-DESIGN-SETTINGS-FOOTER-LOGIN: 'design' (super_admin only) added between
// general and users. Validation list includes it regardless so a deep-link
// like ?tab=design from a non-super-admin still resolves to general gracefully.
$validTabs = ['general', 'design', 'users', 'portal_users', 'audit', 'system', 'integrations', 'intelligence'];
if (!in_array($defaultTab, $validTabs, true)) $defaultTab = 'general';
if ($defaultTab === 'design' && !$isSuperAdmin) $defaultTab = 'general';
?>

<div x-data="{ activeTab: '<?= e($defaultTab) ?>' }">

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-header-title">Settings</h1>
        <p style="margin:4px 0 0;font-size:0.8125rem;color:var(--text-muted);">System configuration, user management, and administration</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('settings') ?>
        <?php /* EMAIL-1: link to standalone email templates manager */ ?>
        <a href="<?= base_url('settings/email_templates') ?>" class="btn btn-secondary btn-sm">
            <?= heroicon('envelope', 'btn-icon') ?>
            Email Templates
        </a>
        <a href="<?= base_url('email/bulk') ?>" class="btn btn-secondary btn-sm">
            <?= heroicon('paper-airplane', 'btn-icon') ?>
            Bulk Email
        </a>
        <?php if (!$canEdit): ?>
        <span class="badge badge-neutral">View Only</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($saveFlash): ?>
<div class="toast toast-success" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></span>
    <div class="toast-body"><div class="toast-message"><?= e($saveFlash) ?></div></div>
</div>
<?php endif; ?>

<?php if ($saveError): ?>
<div class="toast toast-danger" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/></svg></span>
    <div class="toast-body"><div class="toast-message"><?= e($saveError) ?></div></div>
</div>
<?php endif; ?>

<!-- ── Tab Navigation ─────────────────────────────────────────────────────── -->
<div class="tab-bar" role="tablist" style="margin-bottom:24px;">
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'general' }"
            @click="activeTab = 'general'" role="tab">
        General
    </button>
    <?php if ($isSuperAdmin): /* S-DESIGN-SETTINGS-FOOTER-LOGIN: super_admin-only Design tab */ ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'design' }"
            @click="activeTab = 'design'" role="tab">
        Design
    </button>
    <?php endif; ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'users' }"
            @click="activeTab = 'users'" role="tab">
        Users <span class="tab-badge" style="font-size:0.7rem;"><?= e((string)$userCount) ?></span>
    </button>
    <?php if ($isSuperAdmin): ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'portal_users' }"
            @click="activeTab = 'portal_users'" role="tab">
        Portal &amp; Requests <span class="tab-badge" style="font-size:0.7rem;"><?= e((string)$portalUserCount) ?></span>
    </button>
    <?php endif; ?>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'audit' }"
            @click="activeTab = 'audit'" role="tab">
        Audit Log <span class="tab-badge" style="font-size:0.7rem;"><?= e((string)$recentAuditCount) ?></span>
    </button>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'system' }"
            @click="activeTab = 'system'" role="tab">
        System
    </button>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'integrations' }"
            @click="activeTab = 'integrations'" role="tab">
        Integrations
    </button>
    <button class="tab-btn" :class="{ 'is-active': activeTab === 'intelligence' }"
            @click="activeTab = 'intelligence'" role="tab">
        Intelligence
    </button>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: GENERAL SETTINGS                                                 -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'general'" x-transition:enter class="ff-tab-enter">

<?php foreach ($primaryGroups as $grp): ?>
<?php if (empty($grouped[$grp])) continue; ?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;"><?= e($groupLabels[$grp] ?? ucfirst($grp)) ?></div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="<?= e($grp) ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px 24px;">
            <?php foreach ($grouped[$grp] as $setting): ?>
            <?php
            $key   = $setting['key'];
            $val   = $setting['value'] ?? '';
            $vtype = $setting['value_type'];
            $label = $setting['label'] ?? $key;
            $desc  = $setting['description'] ?? null;
            ?>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>

                <?php if ($vtype === 'boolean'): ?>
                <div class="form-check">
                    <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                           <?= $val === '1' ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>>
                    <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                </div>
                <?php elseif ($vtype === 'text'): ?>
                <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control" rows="3"
                          <?= !$canEdit ? 'readonly' : '' ?>><?= e($val) ?></textarea>
                <?php elseif (in_array($vtype, ['integer', 'decimal'], true)): ?>
                <input type="number" min="0" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" step="<?= $vtype === 'decimal' ? '0.01' : '1' ?>"
                       min="0" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php else: ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" maxlength="500" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php endif; ?>

                <?php if ($desc): ?>
                <p class="text-muted" style="font-size:0.75rem;margin:4px 0 0;"><?= e($desc) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:20px;margin-top:20px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?> Settings</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- CURRENCY-MARKUP-1: custom card — step=0.0001, max=20, % suffix, old/new audit -->
<?php
$_curMarkup = '0.0000';
if (!empty($grouped['currency'])) {
    foreach ($grouped['currency'] as $_cs) {
        if ($_cs['key'] === 'currency.usd_cad_markup_pct') {
            $_curMarkup = $_cs['value'] ?? '0.0000';
            break;
        }
    }
}
?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Currency Conversion</div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="currency">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px 24px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="currency_usd_cad_markup_pct">USD → CAD Markup</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" id="currency_usd_cad_markup_pct" name="currency_usd_cad_markup_pct"
                               class="form-control" style="max-width:160px;"
                               value="<?= e($_curMarkup) ?>"
                               step="0.0001" min="0" max="20"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                        <span style="font-size:0.875rem;color:var(--text-secondary);">%</span>
                    </div>
                    <p class="text-muted" style="font-size:0.75rem;margin:4px 0 0;">
                        Markup % applied on top of the bank exchange rate when generating USD invoices.
                        Frozen per invoice at creation. Visible on invoice PDF and customer portal. 0 = no markup.
                    </p>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:20px;margin-top:20px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save Currency Settings</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

</div><!-- /general tab -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1.5: DESIGN — brand color, logo, defaults, regional, PDF, UI (super_admin only) -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if ($isSuperAdmin): ?>
<div x-show="activeTab === 'design'" x-transition:enter class="ff-tab-enter">
    <?php require_once __DIR__ . '/design.php'; ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: ADMIN USERS — link to sidebar Users module                       -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php /* S-SETTINGS-CLEANUP D-B: tab content swapped from settings/users.php
         (a simplified duplicate) to a link card pointing at the sidebar Users
         module, which is the superset (richer KPIs, inline profile edit,
         password reset, set-password, MFA disable, permissions matrix).
         The tab nav entry stays so existing bookmarks to ?tab=users still
         resolve. The settings/users.php file remains on disk pending the
         future S-USERS-CONSOLIDATE decommission. */ ?>
<div x-show="activeTab === 'users'" x-transition:enter class="ff-tab-enter">
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="text-align:center;padding:48px 32px;">
            <div style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:8px;">
                User management has moved
            </div>
            <h2 style="font-size:1.125rem;font-weight:600;margin:0 0 8px;">Manage admin users in the Users module</h2>
            <p style="font-size:0.875rem;color:var(--text-tertiary);max-width:520px;margin:0 auto 24px;">
                The dedicated Users module offers richer features than the old Settings tab — inline profile editing,
                password reset emails, set-password (super_admin), per-user permission overrides, MFA disable,
                and login history. Portal users continue to be managed under the Portal Users tab here.
            </p>
            <?php /* S-PERM-USERS-SUPERADMIN-ONLY — gate hardcoded /users link to super_admin only. */ ?>
            <?php if (can('users', 'view')): ?>
            <a href="<?= base_url('users') ?>" class="btn btn-primary">
                Go to Users module &rarr;
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: PORTAL USERS (super_admin only)                                  -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if ($isSuperAdmin): ?>
<?php /* S-USERS-CONSOLIDATE D-B: tab content swapped from settings/portal_users.php
         (the prior full management surface) to a link card pointing at the
         unified Users module's Portal Users tab. Matches the S-SETTINGS-CLEANUP
         pattern used for admin Users. The tab nav entry stays so existing
         bookmarks to ?tab=portal_users still resolve. The settings/portal_users.php
         file remains on disk with a deprecation comment per D-D. */ ?>
<div x-show="activeTab === 'portal_users'" x-transition:enter class="ff-tab-enter">

    <!-- ── Portal user management redirect notice ─────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:24px;padding:16px 24px;">
            <div>
                <div style="font-weight:600;margin-bottom:2px;">Portal user management</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">
                    Portal users (customer-side logins) are managed in the Users module.
                </div>
            </div>
            <?php if (can('users', 'view')): ?>
            <a href="<?= base_url('users') ?>?tab=portal" class="btn btn-secondary btn-sm" style="white-space:nowrap;">
                Go to Users &rarr; Portal &rarr;
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Portal Service Request Routing (S-PORTAL-REQUEST-ROUTING) ──────── -->
    <?php
    // Build the lookup data for the routing matrix.
    $portalReqRoles = db_select("SELECT slug, name FROM user_roles ORDER BY id ASC");
    $portalReqUsers = db_select(
        "SELECT u.id, u.name, u.email, ur.slug AS role_slug
           FROM users u
           JOIN user_roles ur ON ur.id = u.role_id
          WHERE u.deleted_at IS NULL AND u.status = 'active'
          ORDER BY ur.id, u.name"
    );

    $portalReqTypes = [
        'lease_extension'   => 'Lease Extension Request',
        'early_return'      => 'Early Return Notice',
        'damage_report'     => 'Damage Report',
        'billing_inquiry'   => 'Billing Inquiry',
        'document_request'  => 'Document Request',
        'new_lease_inquiry' => 'New Lease Inquiry',
        'general'           => 'General Question',
        'default'           => 'Default fallback (used when type-specific routing empty)',
    ];

    // Pre-decode current settings values per type.
    $portalReqCurrent = [];
    foreach (array_keys($portalReqTypes) as $type) {
        $rolesJson = (string) settings_get("portal_requests.routing.{$type}.role_slugs", '[]');
        $usersJson = (string) settings_get("portal_requests.routing.{$type}.user_ids",   '[]');
        $rolesArr  = json_decode($rolesJson, true);
        $usersArr  = json_decode($usersJson, true);
        $portalReqCurrent[$type] = [
            'role_slugs' => is_array($rolesArr) ? array_flip(array_map('strval', $rolesArr)) : [],
            'user_ids'   => is_array($usersArr) ? array_flip(array_map('intval', $usersArr)) : [],
        ];
    }
    $portalReqAlwaysSuper = (string) settings_get('portal_requests.routing.always_include_super_admin', '1') === '1';
    ?>

    <div id="service-request-routing" class="card" style="margin-bottom:20px;scroll-margin-top:80px;">
        <div class="card-header" style="font-weight:600;">Service Request Notification Routing</div>
        <div class="card-body">
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
                Customers submit service requests via the portal (lease extension, damage report, billing
                inquiry, etc.). Configure which roles or specific users receive each request type below.
                Routing is union (roles + users); when a request type has empty routing, the
                <strong>Default fallback</strong> bucket applies.
                When <code>Always include super_admin</code> is on, super_admins are notified for every
                request regardless of per-type config (safety net to prevent silent-drops if routing is
                misconfigured).
            </p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="_group"     value="portal_requests">
                <input type="hidden" name="_form_keys[]" value="portal_requests.routing.always_include_super_admin">
                <?php foreach (array_keys($portalReqTypes) as $type): ?>
                    <input type="hidden" name="_form_keys[]" value="portal_requests.routing.<?= e($type) ?>.role_slugs">
                    <input type="hidden" name="_form_keys[]" value="portal_requests.routing.<?= e($type) ?>.user_ids">
                <?php endforeach; ?>

                <!-- ── Safety net toggle ────────────────────────────────── -->
                <div style="padding:12px 14px;background:var(--bg-secondary);border-radius:6px;margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;">
                        <input type="checkbox"
                               name="portal_requests.routing.always_include_super_admin"
                               value="1"
                               <?= $portalReqAlwaysSuper ? 'checked' : '' ?>
                               <?= !$canEdit ? 'disabled' : '' ?>>
                        <span>Always include super_admin users <span style="font-weight:400;color:var(--text-muted);font-size:0.8125rem;">(recommended — safety net)</span></span>
                    </label>
                </div>

                <!-- ── Per-request-type routing matrix ─────────────────── -->
                <?php foreach ($portalReqTypes as $type => $label):
                    $isDefault = $type === 'default';
                    $rolesSel = $portalReqCurrent[$type]['role_slugs'];
                    $usersSel = $portalReqCurrent[$type]['user_ids'];
                ?>
                <div style="border:1px solid var(--border-color);border-radius:6px;padding:16px;margin-bottom:14px;<?= $isDefault ? 'background:var(--bg-secondary);' : '' ?>">
                    <div style="font-weight:600;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
                        <span>
                            <?= e($label) ?>
                            <?php if (!$isDefault): ?>
                                <code style="margin-left:8px;font-size:0.75rem;color:var(--text-muted);"><?= e($type) ?></code>
                            <?php endif; ?>
                        </span>
                        <?php if ($isDefault): ?>
                            <span class="badge badge-info" style="font-size:0.7rem;">Fallback</span>
                        <?php endif; ?>
                    </div>

                    <!-- Roles -->
                    <div style="margin-bottom:12px;">
                        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;">Roles</div>
                        <div style="display:flex;flex-wrap:wrap;gap:14px;">
                            <?php foreach ($portalReqRoles as $role):
                                $checked = isset($rolesSel[$role['slug']]);
                            ?>
                            <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:0.875rem;">
                                <input type="checkbox"
                                       name="portal_requests.routing.<?= e($type) ?>.role_slugs[]"
                                       value="<?= e($role['slug']) ?>"
                                       <?= $checked ? 'checked' : '' ?>
                                       <?= !$canEdit ? 'disabled' : '' ?>>
                                <span><?= e($role['name']) ?> <code style="font-size:0.7rem;color:var(--text-muted);"><?= e($role['slug']) ?></code></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Specific users (collapsed by default; expand on click) -->
                    <details>
                        <summary style="cursor:pointer;font-size:0.8125rem;color:var(--text-secondary);user-select:none;">
                            Specific users (in addition to roles)
                            <?php $userCount = count($usersSel); if ($userCount > 0): ?>
                                <span class="badge badge-secondary" style="margin-left:6px;font-size:0.7rem;"><?= $userCount ?> selected</span>
                            <?php endif; ?>
                        </summary>
                        <div style="display:flex;flex-wrap:wrap;gap:10px 18px;margin-top:10px;">
                            <?php foreach ($portalReqUsers as $u):
                                $checked = isset($usersSel[(int) $u['id']]);
                            ?>
                            <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:0.8125rem;">
                                <input type="checkbox"
                                       name="portal_requests.routing.<?= e($type) ?>.user_ids[]"
                                       value="<?= (int) $u['id'] ?>"
                                       <?= $checked ? 'checked' : '' ?>
                                       <?= !$canEdit ? 'disabled' : '' ?>>
                                <span><?= e($u['name']) ?> <span style="color:var(--text-muted);font-size:0.75rem;">(<?= e($u['role_slug']) ?>)</span></span>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($portalReqUsers)): ?>
                                <span style="color:var(--text-muted);font-size:0.8125rem;">No active admin users found.</span>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
                <?php endforeach; ?>

                <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary btn-md" style="margin-top:8px;">Save Routing Configuration</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4: AUDIT LOG                                                        -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'audit'" x-transition:enter class="ff-tab-enter">
    <?php require_once __DIR__ . '/audit_log.php'; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 5: SYSTEM                                                           -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'system'" x-transition:enter class="ff-tab-enter">
    <?php require_once __DIR__ . '/system.php'; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 6: INTEGRATIONS                                                     -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'integrations'" x-transition:enter class="ff-tab-enter">

<?php foreach ($sensitiveGroups as $grp): ?>
<?php if (empty($grouped[$grp])) continue; ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?>
        <span class="badge badge-warning" style="font-size:0.7rem;">Sensitive</span>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="<?= e($grp) ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px 24px;">
            <?php foreach ($grouped[$grp] as $setting): ?>
            <?php
            $key      = $setting['key'];
            $val      = $setting['value'] ?? '';
            $vtype    = $setting['value_type'];
            $label    = $setting['label'] ?? $key;
            $desc     = $setting['description'] ?? null;
            // INT-1: secret keys render masked. The masked placeholder
            // is what comes back on submit when the user does not edit
            // the field — the save handler detects it and skips writes.
            $isSecret = in_array($key, $secretKeys, true);
            $display  = $isSecret ? $maskSecret((string) $val) : (string) $val;
            ?>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <?php if ($key === 'security.mfa.required_roles'): ?>
                <?php /* S-SETTINGS-CLEANUP D-A: multi-checkbox over user_roles
                         instead of raw JSON text. Submits an array under
                         security_mfa_required_roles[]; save handler JSON-encodes. */ ?>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ($mfaRolesList as $_role): ?>
                    <label class="form-check" style="margin:0;">
                        <input type="checkbox"
                               name="<?= e($key) ?>[]"
                               value="<?= e($_role['slug']) ?>"
                               <?= isset($mfaRequiredSet[$_role['slug']]) ? 'checked' : '' ?>
                               <?= !$canEdit ? 'disabled' : '' ?>>
                        <span style="margin-left:6px;font-size:0.875rem;"><?= e($_role['name']) ?>
                            <span class="text-muted" style="font-size:0.75rem;">(<?= e($_role['slug']) ?>)</span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($vtype === 'boolean'): ?>
                <div class="form-check">
                    <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                           <?= $val === '1' ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>>
                    <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                </div>
                <?php elseif (in_array($vtype, ['integer', 'decimal'], true)): ?>
                <input type="number" min="0" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" step="<?= $vtype === 'decimal' ? '0.01' : '1' ?>"
                       min="0" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php elseif ($isSecret): ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control font-mono"
                       value="<?= e($display) ?>" maxlength="500"
                       autocomplete="off" spellcheck="false"
                       placeholder="Paste new key to replace stored value"
                       <?= !$canEdit ? 'readonly' : '' ?>
                       onfocus="if(this.value.startsWith('\u2022')) this.select();">
                <?php else: ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" maxlength="500" autocomplete="off" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php endif; ?>
                <?php if ($desc): ?>
                <p class="text-muted" style="font-size:0.75rem;margin:4px 0 0;"><?= e($desc) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:20px;margin-top:20px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?> Settings</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php
// S-INTEL-V2 follow-up: empty-state check now spans the actual
// $sensitiveGroups list (AI moved to Intelligence tab; slack/twilio
// also live there as delivery infrastructure). The empty-state card
// shows only when ALL configured Integrations groups are empty.
$intHasAny = false;
foreach ($sensitiveGroups as $_ig) {
    if (!empty($grouped[$_ig] ?? [])) { $intHasAny = true; break; }
}
?>
<?php if (!$intHasAny): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted);">
        No integration settings configured.
    </div>
</div>
<?php endif; ?>

<!-- ── Samsara Connection Status ──────────────────────────────────────── -->
<?php if ($isSuperAdmin): ?>
<div class="card" style="margin-bottom:20px;" x-data="FF_SamsaraTest()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Samsara Connection
        <span class="badge badge-info" style="font-size:0.7rem;">GPS</span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">
            <div>
                <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 12px;">
                    Test your Samsara API connection to verify credentials and access. The test makes a
                    single API call to list vehicles and confirms authentication is working.
                </p>

                <!-- Connection status display -->
                <template x-if="tested">
                    <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"
                         :style="connected
                             ? 'background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);'
                             : 'background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);'">
                        <span x-show="connected" style="color:var(--color-success);font-size:1.1rem;">&#10003;</span>
                        <span x-show="!connected" style="color:var(--color-danger);font-size:1.1rem;">&#10007;</span>
                        <span x-text="message" :style="connected ? 'color:var(--color-success);' : 'color:var(--color-danger);'"></span>
                    </div>
                </template>

                <!-- Details (when connected) -->
                <template x-if="tested && connected && details.vehicles_found">
                    <div style="margin-top:8px;font-size:0.8125rem;color:var(--text-muted);">
                        Vehicles found: <span class="font-mono" x-text="details.vehicles_found"></span>
                        <template x-if="details.org_id">
                            &nbsp;·&nbsp;Org ID: <span class="font-mono" x-text="details.org_id"></span>
                        </template>
                    </div>
                </template>
            </div>

            <button class="btn btn-secondary btn-sm" @click="test()" :disabled="testing" style="white-space:nowrap;">
                <span x-show="!testing">Test Connection</span>
                <span x-show="testing" x-cloak>Testing…</span>
            </button>
        </div>

        <!-- GPS Fleet Overview -->
        <?php
        $gpsUnitCount = db_count(
            "SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL AND tracking_provider = 'samsara' AND gps_device_id IS NOT NULL AND gps_device_id != ''"
        );
        $totalUnits = db_count("SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL");
        $lastGpsSync = db_row(
            "SELECT created_at FROM audit_log WHERE module = 'cron' AND entity_label LIKE '%gps_sync%' ORDER BY created_at DESC LIMIT 1"
        );
        ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-default);display:flex;gap:20px;flex-wrap:wrap;">
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">GPS-Equipped Units</div>
                <div class="font-mono" style="font-size:1rem;font-weight:600;">
                    <?= e((string)$gpsUnitCount) ?> <span style="font-weight:400;color:var(--text-muted);font-size:0.8125rem;">/ <?= e((string)$totalUnits) ?></span>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">Last GPS Sync (Cron)</div>
                <div style="font-size:0.875rem;">
                    <?= $lastGpsSync ? e(format_datetime($lastGpsSync['created_at'])) : '<span style="color:var(--text-muted);">Never</span>' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">Tracking Page</div>
                <a href="<?= base_url('tracking') ?>" style="font-size:0.875rem;">Open Fleet Map →</a>
            </div>
        </div>
    </div>
</div>

<script>
function FF_SamsaraTest() {
    return {
        testing:   false,
        tested:    false,
        connected: false,
        message:   '',
        details:   {},

        async test() {
            this.testing = true;
            this.tested = false;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/gps/test-connection') ?>');
                if (r.success) {
                    this.connected = r.data.connected;
                    this.message   = r.data.message;
                    this.details   = r.data.details || {};
                } else {
                    this.connected = false;
                    this.message   = r.error?.message || 'Test request failed.';
                }
            } catch(e) {
                this.connected = false;
                this.message   = 'Network error. Could not reach the server.';
            }
            this.tested = true;
            this.testing = false;
        },
    };
}
</script>

<?php endif; ?>

<!-- ── SES Email Connection Test ──────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_SesTest()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Email Delivery (AWS SES)
        <span class="badge badge-info" style="font-size:0.7rem;">Diagnostics</span>
    </div>
    <div class="card-body">

        <!-- Status strip (loaded on mount) -->
        <div x-show="diag !== null" style="margin-bottom:16px;">
            <!-- Mode indicator -->
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;font-size:0.875rem;margin-bottom:12px;"
                 :style="diag?.mode === 'ses'
                     ? 'background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);'
                     : 'background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.3);'">
                <span x-show="diag?.mode === 'ses'" style="color:var(--color-success);font-size:1.1rem;">&#10003;</span>
                <span x-show="diag?.mode !== 'ses'" style="color:#b45309;font-size:1.1rem;">&#9888;</span>
                <div>
                    <strong x-text="diag?.mode === 'ses' ? 'SES mode active — emails will be delivered' : 'Log-only mode — emails are NOT delivered'"></strong>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px;" x-text="diag?.mode_reason"></div>
                </div>
            </div>

            <!-- Credential summary -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;font-size:0.8125rem;margin-bottom:12px;">
                <div style="padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">
                    <div style="color:var(--text-muted);font-size:0.72rem;margin-bottom:2px;">ACCESS KEY</div>
                    <div x-text="diag?.key_present ? (diag?.key_preview || '✓ Set') : '✗ Not configured'" :style="diag?.key_present ? 'color:var(--color-success);' : 'color:var(--color-danger);'" class="font-mono" style="font-size:0.8rem;"></div>
                    <div style="color:var(--text-muted);font-size:0.7rem;" x-text="diag?.key_source ? 'Source: '+diag.key_source : ''"></div>
                </div>
                <div style="padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">
                    <div style="color:var(--text-muted);font-size:0.72rem;margin-bottom:2px;">SECRET KEY</div>
                    <div :style="diag?.secret_present ? 'color:var(--color-success);' : 'color:var(--color-danger);'" x-text="diag?.secret_present ? '✓ Set' : '✗ Not configured'"></div>
                </div>
                <div style="padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">
                    <div style="color:var(--text-muted);font-size:0.72rem;margin-bottom:2px;">FROM ADDRESS</div>
                    <div x-text="diag?.from_email || '(not set)'" class="font-mono" style="font-size:0.78rem;" :style="diag?.from_is_placeholder ? 'color:var(--color-danger);' : ''"></div>
                    <div x-show="diag?.from_is_placeholder" style="color:var(--color-danger);font-size:0.7rem;">⚠ Placeholder — must be SES-verified</div>
                </div>
                <div style="padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">
                    <div style="color:var(--text-muted);font-size:0.72rem;margin-bottom:2px;">REGION</div>
                    <div class="font-mono" style="font-size:0.8rem;" x-text="diag?.region"></div>
                </div>
            </div>

            <!-- Warnings -->
            <template x-if="diag?.warnings?.length">
                <div style="background:rgba(220,38,38,0.05);border:1px solid rgba(220,38,38,0.2);border-radius:6px;padding:10px 14px;margin-bottom:12px;">
                    <div style="font-weight:600;font-size:0.8rem;color:var(--color-danger);margin-bottom:6px;">Action Required</div>
                    <template x-for="w in diag.warnings" :key="w">
                        <div style="font-size:0.8125rem;color:var(--color-danger);margin-bottom:4px;" x-text="'• ' + w"></div>
                    </template>
                </div>
            </template>
        </div>

        <!-- Send test result -->
        <template x-if="sendResult">
            <div style="padding:10px 14px;border-radius:6px;font-size:0.875rem;margin-bottom:12px;"
                 :style="sendResult === 'sent'
                     ? 'background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);color:var(--color-success);'
                     : 'background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);color:var(--color-danger);'">
                <template x-if="sendResult === 'sent'">
                    <span>&#10003; Test email sent to <strong x-text="sentTo"></strong>. Check your inbox.</span>
                </template>
                <template x-if="sendResult !== 'sent'">
                    <div>
                        <strong>Send failed:</strong> <span x-text="sendError"></span>
                    </div>
                </template>
            </div>
        </template>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button class="btn btn-secondary btn-sm" @click="check()" :disabled="checking">
                <span x-show="!checking">Refresh Diagnostics</span>
                <span x-show="checking" x-cloak>Checking…</span>
            </button>
            <button class="btn btn-primary btn-sm" @click="sendTest()" :disabled="sending || checking">
                <span x-show="!sending">Send Test Email to Me</span>
                <span x-show="sending" x-cloak>Sending…</span>
            </button>
            <span style="font-size:0.75rem;color:var(--text-muted);">Sends a real email to your account address via SES</span>
        </div>

    </div>
</div>

<script>
function FF_SesTest() {
    return {
        diag:       null,
        checking:   false,
        sending:    false,
        sendResult: null,
        sendError:  null,
        sentTo:     null,

        async init() { await this.check(); },

        async check() {
            this.checking = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/admin/integrations/test_email') ?>', { send: 0 });
                if (r.success) this.diag = r.data;
                else this.diag = { mode: 'error', mode_reason: r.error?.message || 'Diagnostics failed', warnings: [] };
            } catch(e) {
                this.diag = { mode: 'error', mode_reason: 'Network error', warnings: [] };
            }
            this.checking = false;
        },

        async sendTest() {
            this.sending    = true;
            this.sendResult = null;
            this.sendError  = null;
            this.sentTo     = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/admin/integrations/test_email') ?>', { send: 1 });
                if (r.success) {
                    this.sendResult = r.data.send_result;
                    this.sendError  = r.data.send_error;
                    this.sentTo     = r.data.to_email;
                    // Refresh diag strip too
                    this.diag = r.data;
                } else {
                    this.sendResult = 'failed';
                    this.sendError  = r.error?.message || 'Request failed';
                }
            } catch(e) {
                this.sendResult = 'failed';
                this.sendError  = 'Network error';
            }
            this.sending = false;
        },
    };
}
</script>

</div><!-- /integrations tab -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 7: INTELLIGENCE (S-INTEL-TAB)                                       -->
<!-- AI core + Morning Briefing config + Anomaly Scan + recipient management -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div x-show="activeTab === 'intelligence'" x-transition:enter class="ff-tab-enter">

<?php
// ── Pre-compute Intelligence-tab data: roles, current recipients, history ──
$intelRoles = db_select("SELECT slug, name FROM user_roles ORDER BY id ASC");
$intelRolesJson = (string) settings_get('ai.briefing_recipient_roles', '["super_admin","manager","accountant"]');
$intelRolesDecoded = json_decode($intelRolesJson, true);
$intelRolesSet = [];
if (is_array($intelRolesDecoded)) {
    foreach ($intelRolesDecoded as $slug) {
        if (is_string($slug)) $intelRolesSet[$slug] = true;
    }
}

// Recipients table — every active user, with their current opt_in state +
// snooze state (S-INTEL-V2 F8) + role for super_admin's row-level management.
$intelRecipients = db_select(
    "SELECT u.id, u.name, u.email, u.morning_briefing_opt_in, u.briefing_snoozed_until,
            u.briefing_hour, u.briefing_sections, ur.slug AS role_slug
       FROM users u
       JOIN user_roles ur ON ur.id = u.role_id
      WHERE u.deleted_at IS NULL AND u.status = 'active'
      ORDER BY ur.id, u.name"
);
$intelGlobalDigestHour = (int) settings_get('notifications.digest_hour', 7);

// Last anomaly scan timestamp.
$lastAnomalyScan = settings_get('ai.last_anomaly_scan', null);
?>

<!-- ── 1. AI Core ──────────────────────────────────────────────────── -->
<?php if (!empty($grouped['ai'])): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        AI Core
        <span class="badge badge-info" style="font-size:0.7rem;">Claude</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            Master AI configuration shared by morning briefing, anomaly scan, AI chat,
            and any future AI-powered feature. The <code>ai.enabled</code> toggle is the
            kill switch — set to 0 to disable ALL AI features at once.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="ai">
            <?php
            // S-INTEL-FIX: keys owned by the OTHER two ai.* forms. AI Core
            // must NOT touch these or it'll wipe what those forms set.
            $aiSidecarKeys = [
                'ai.briefing_recipient_roles',
                'ai.budget_alert_thresholds',
                'ai.budget_alert_recipients',
                'ai.budget_alert_last_sent',  // system-managed, never edited via UI
            ];
            ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px 24px;">
            <?php foreach ($grouped['ai'] as $setting):
                $key      = $setting['key'];
                $val      = $setting['value'] ?? '';
                $vtype    = $setting['value_type'];
                $label    = $setting['label'] ?? $key;
                $desc     = $setting['description'] ?? null;
                $isSecret = in_array($key, $secretKeys, true);
                $display  = $isSecret ? $maskSecret((string) $val) : (string) $val;

                // S-INTEL-TAB: ai.briefing_recipient_roles renders as a
                // multi-checkbox over user_roles (mirrors the MFA pattern).
                // Render it OUTSIDE this generic loop — see "Morning Briefing"
                // section below. Budget alert keys live in their own card.
                if (in_array($key, $aiSidecarKeys, true)) continue;
            ?>
            <input type="hidden" name="_form_keys[]" value="<?= e($key) ?>">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <?php if ($vtype === 'boolean'): ?>
                <div class="form-check">
                    <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                           <?= $val === '1' ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>>
                    <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                </div>
                <?php elseif (in_array($vtype, ['integer', 'decimal'], true)): ?>
                <input type="number" min="0" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" step="<?= $vtype === 'decimal' ? '0.01' : '1' ?>"
                       <?= !$canEdit ? 'readonly' : '' ?>>
                <?php elseif ($isSecret): ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control font-mono"
                       value="<?= e($display) ?>" maxlength="500"
                       autocomplete="off" spellcheck="false"
                       placeholder="Paste new key to replace stored value"
                       <?= !$canEdit ? 'readonly' : '' ?>
                       onfocus="if(this.value.startsWith('•')) this.select();">
                <?php else: ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" maxlength="500" autocomplete="off" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php endif; ?>
                <?php if ($desc): ?>
                <p class="text-muted" style="font-size:0.75rem;margin:4px 0 0;"><?= e($desc) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:20px;margin-top:20px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save AI Settings</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── 2. Anthropic Connection + Usage (moved from Integrations) ──── -->
<?php if ($isSuperAdmin): ?>
<div class="card" style="margin-bottom:20px;" x-data="FF_AiTest()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Anthropic Connection
        <span class="badge badge-info" style="font-size:0.7rem;">Claude API</span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">
            <div>
                <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 12px;">
                    Test the API connection to verify credentials are working. Sends a minimal request to Claude.
                </p>
                <template x-if="tested">
                    <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"
                         :style="connected
                             ? 'background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);'
                             : 'background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);'">
                        <span x-show="connected" style="color:var(--color-success);font-size:1.1rem;">&#10003;</span>
                        <span x-show="!connected" style="color:var(--color-danger);font-size:1.1rem;">&#10007;</span>
                        <span x-text="message" :style="connected ? 'color:var(--color-success);' : 'color:var(--color-danger);'"></span>
                    </div>
                </template>
                <template x-if="tested && connected && details.model">
                    <div style="margin-top:8px;font-size:0.8125rem;color:var(--text-muted);">
                        Model: <span class="font-mono" x-text="details.model"></span>
                        <template x-if="details.tokens">
                            &nbsp;&middot;&nbsp;Test tokens: <span class="font-mono" x-text="details.tokens"></span>
                        </template>
                    </div>
                </template>
            </div>
            <button class="btn btn-secondary btn-sm" @click="test()" :disabled="testing" style="white-space:nowrap;">
                <span x-show="!testing">Test Connection</span>
                <span x-show="testing" x-cloak>Testing&hellip;</span>
            </button>
        </div>

        <?php
        $aiUsageToday = db_row(
            "SELECT COALESCE(SUM(total_tokens), 0) AS tokens, COUNT(*) AS requests, COALESCE(SUM(cost_usd), 0) AS cost
             FROM ai_query_log WHERE DATE(created_at) = CURDATE()"
        );
        $aiDailyLimit = (int) settings_get('ai.daily_token_limit', 500000);
        $aiModel = settings_get('ai.model', 'claude-sonnet-4-20250514');
        ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-default);display:flex;gap:20px;flex-wrap:wrap;">
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">Today's Token Usage</div>
                <div class="font-mono" style="font-size:1rem;font-weight:600;">
                    <?= e(number_format((int) ($aiUsageToday['tokens'] ?? 0))) ?>
                    <span style="font-weight:400;color:var(--text-muted);font-size:0.8125rem;">/ <?= e(number_format($aiDailyLimit)) ?></span>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">Requests Today</div>
                <div class="font-mono" style="font-size:1rem;font-weight:600;"><?= e((string) ($aiUsageToday['requests'] ?? 0)) ?></div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">Model</div>
                <div style="font-size:0.875rem;" class="font-mono"><?= e((string) $aiModel) ?></div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">AI Chat</div>
                <a href="<?= base_url('ai') ?>" style="font-size:0.875rem;">Open AI Assistant &rarr;</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── 3. Morning Briefing ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_BriefingControl()" x-init="loadHistory()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Morning Briefing
        <span class="badge badge-info" style="font-size:0.7rem;">Cron 04:00 → email at digest_hour</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            AI-generated daily fleet briefing emailed to recipients matching all three gates (D-INTEL-2):
            briefing master toggle, role-level allow list, and per-user opt-in.
        </p>

        <!-- Status mini-strip -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px 24px;margin-bottom:18px;padding:14px;background:var(--bg-subtle,#f7f7f6);border-radius:6px;">
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Last brief generated</div>
                <div style="font-size:0.85rem;margin-top:2px;" x-text="history.last_brief_generated_at ? formatTs(history.last_brief_generated_at) : '—'"></div>
            </div>
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Cache active</div>
                <div style="font-size:0.85rem;margin-top:2px;" x-text="history.cache_active ? 'YES' : 'NO'"
                     :style="history.cache_active ? 'color:#16a34a;' : 'color:#dc2626;'"></div>
            </div>
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Current recipients</div>
                <div style="font-size:0.85rem;margin-top:2px;" x-text="history.current_recipient_count + ' user(s)'"></div>
            </div>
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Briefing enabled</div>
                <div style="font-size:0.85rem;margin-top:2px;" x-text="history.briefing_enabled ? 'YES' : 'NO'"
                     :style="history.briefing_enabled ? 'color:#16a34a;' : 'color:#dc2626;'"></div>
            </div>
        </div>

        <!-- Settings form: recipient_roles + digest_hour.
             S-INTEL-FIX: declares _form_keys[] so the handler only touches
             these two keys — the AI Core form above and the Budget Alert
             form below are no longer wiped when this form submits. -->
        <form method="POST" action="" style="margin-bottom:18px;">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="ai">
            <input type="hidden" name="_form_keys[]" value="ai.briefing_recipient_roles">
            <input type="hidden" name="_form_keys[]" value="notifications.digest_hour">

            <div style="display:grid;grid-template-columns:minmax(220px,1fr) 2fr;gap:24px;align-items:start;">

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Recipient roles (D-INTEL-2 gate 2)</label>
                    <div style="display:flex;flex-direction:column;gap:6px;padding:10px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <?php foreach ($intelRoles as $role): ?>
                        <label class="form-check" style="margin:0;">
                            <input type="checkbox"
                                   name="ai.briefing_recipient_roles[]"
                                   value="<?= e($role['slug']) ?>"
                                   <?= isset($intelRolesSet[$role['slug']]) ? 'checked' : '' ?>
                                   <?= !$canEdit ? 'disabled' : '' ?>>
                            <span style="margin-left:6px;font-size:0.875rem;"><?= e($role['name']) ?>
                                <span class="text-muted" style="font-size:0.75rem;">(<?= e($role['slug']) ?>)</span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0;">
                        Users whose role is in this list AND who have opt_in=1 receive the daily briefing.
                    </p>
                </div>

                <div>
                    <?php
                    $digestHourSetting = db_row("SELECT `key`, `value`, value_type, label, description FROM settings WHERE `key` = 'notifications.digest_hour'");
                    if ($digestHourSetting):
                    ?>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label" for="notifications.digest_hour">Digest send hour (local timezone)</label>
                        <input type="number" id="notifications.digest_hour" name="notifications.digest_hour" class="form-control"
                               value="<?= e((string) $digestHourSetting['value']) ?>" min="0" max="23" style="max-width:120px;"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                        <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0;">
                            Hour (0–23) when notification_digest cron dispatches the morning email. Reads <code>company.timezone</code> for the local clock. Saved by this form alongside the recipient roles.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:14px;margin-top:14px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save Recipient Roles &amp; Send Hour</button>
            </div>
            <?php endif; ?>
        </form>

        <!-- Test send + history -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;padding-top:16px;border-top:1px solid var(--border-default);">

            <div>
                <h3 class="h6" style="margin:0 0 8px;">Test send + manual generation</h3>
                <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
                    <strong>Test send</strong> uses today's cached brief (free). <strong>Generate now</strong> calls Claude (~3000 tokens) and refreshes the cache.
                </p>
                <!-- S-INTEL-UX-1: surface the master-kill-switch dependency
                     up-front. ai.enabled is a separate gate from ai.briefing_enabled
                     — without this banner, clicking Generate while ai.enabled=0
                     returns an AI_DISABLED error that scrolls below the fold and
                     the user reads it as "still broken". -->
                <div x-show="!history.ai_enabled" x-cloak class="alert alert-warning"
                     style="margin:0 0 10px;font-size:0.8125rem;">
                    <strong>AI Features Enabled is off.</strong> Turn on the master switch in AI Core above
                    (and click <em>Save AI Settings</em>) before you can generate or test briefs.
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-secondary btn-sm" @click="sendTest()"
                            :disabled="testSending || !history.cache_active || !history.ai_enabled || !history.briefing_enabled"
                            :title="!history.ai_enabled ? 'AI Features Enabled is off — turn it on in AI Core above' : (!history.briefing_enabled ? 'Morning Briefing is disabled' : (!history.cache_active ? 'No cached brief to send — generate one first' : ''))">
                        <span x-show="!testSending">Test send (cached)</span>
                        <span x-show="testSending" x-cloak>Sending&hellip;</span>
                    </button>
                    <button class="btn btn-primary btn-sm" @click="generateNow()"
                            :disabled="generating || !history.ai_enabled"
                            :title="!history.ai_enabled ? 'AI Features Enabled is off — turn it on in AI Core above' : 'Calls Claude API now — costs ~3000 tokens'">
                        <span x-show="!generating">Generate brief now</span>
                        <span x-show="generating" x-cloak>Generating (calling Claude)&hellip;</span>
                    </button>
                </div>
                <!-- S-INTEL-UX-1: x-effect scrolls the alert into view the
                     moment a flash message appears, so error responses don't
                     get missed when the user scrolled past the buttons. -->
                <div x-show="testFlash.message" x-cloak
                     x-ref="testFlashEl"
                     x-effect="if (testFlash.message && $refs.testFlashEl) $refs.testFlashEl.scrollIntoView({behavior:'smooth', block:'center'})"
                     :class="testFlash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
                     style="margin-top:10px;font-size:0.8125rem;"
                     x-text="testFlash.message"></div>
                <div x-show="genFlash.message" x-cloak
                     x-ref="genFlashEl"
                     x-effect="if (genFlash.message && $refs.genFlashEl) $refs.genFlashEl.scrollIntoView({behavior:'smooth', block:'center'})"
                     :class="genFlash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
                     style="margin-top:10px;font-size:0.8125rem;"
                     x-html="genFlash.message"></div>
            </div>

            <div>
                <h3 class="h6" style="margin:0 0 8px;">Recent runs (last 10)</h3>
                <p style="font-size:0.72rem;color:var(--text-muted);margin:0 0 6px;">Click a row to see what was sent.</p>
                <template x-if="history.runs && history.runs.length === 0">
                    <p style="font-size:0.8125rem;color:var(--text-muted);margin:0;">No dispatches yet.</p>
                </template>
                <template x-if="history.runs && history.runs.length > 0">
                    <table class="table table-sm" style="font-size:0.8125rem;margin:0;">
                        <thead>
                            <tr><th align="left">Date</th><th align="right">Sent</th><th align="right">Failed</th><th align="right">Total</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="r in history.runs" :key="r.run_date">
                                <tr style="cursor:pointer;" @click="openContentModal(r.run_date)">
                                    <td x-text="r.run_date" style="color:#7c3aed;text-decoration:underline;"></td>
                                    <td align="right" x-text="r.sent"></td>
                                    <td align="right" :style="r.failed > 0 ? 'color:#dc2626;' : ''" x-text="r.failed"></td>
                                    <td align="right" x-text="r.total"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>

        <!-- Content viewer modal (F3) -->
        <div x-show="contentModal.open" x-cloak class="modal-overlay"
             style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
             @click.self="contentModal.open = false">
            <div class="modal" style="max-width:720px;width:90%;">
                <div class="modal-header">
                    <h3 class="h5" style="margin:0;">Brief content — <span x-text="contentModal.date"></span></h3>
                    <button class="modal-close-btn" @click="contentModal.open = false" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body" style="max-height:60vh;overflow-y:auto;">
                    <template x-if="contentModal.loading">
                        <p style="font-size:0.8125rem;color:var(--text-muted);">Loading&hellip;</p>
                    </template>
                    <template x-if="!contentModal.loading && contentModal.error">
                        <div class="alert alert-danger" x-text="contentModal.error"></div>
                    </template>
                    <template x-if="!contentModal.loading && contentModal.data">
                        <div>
                            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:8px;">
                                Generated: <span x-text="contentModal.data.generated_at"></span>
                                <template x-if="contentModal.data.manual">
                                    <span class="badge badge-warning" style="margin-left:6px;">manual</span>
                                </template>
                                <template x-if="contentModal.data.model">
                                    <span style="margin-left:8px;">Model: <code x-text="contentModal.data.model"></code></span>
                                </template>
                            </div>
                            <h4 class="h6" style="margin:0 0 6px;">AI brief text</h4>
                            <div style="background:#f7f7f6;padding:14px;border-radius:6px;font-size:0.8125rem;line-height:1.6;white-space:pre-wrap;" x-text="contentModal.data.brief"></div>

                            <h4 class="h6" style="margin:14px 0 6px;">Recipients (<span x-text="contentModal.data.recipient_count"></span>)</h4>
                            <template x-if="contentModal.data.recipients.length === 0">
                                <p style="font-size:0.78rem;color:var(--text-muted);">No dispatch log on this date.</p>
                            </template>
                            <template x-if="contentModal.data.recipients.length > 0">
                                <table class="table table-sm" style="font-size:0.78rem;margin:0;">
                                    <thead><tr><th align="left">Email</th><th align="left">Status</th><th align="left">Sent at</th></tr></thead>
                                    <tbody>
                                        <template x-for="rcp in contentModal.data.recipients" :key="rcp.recipient + (rcp.sent_at||'')">
                                            <tr>
                                                <td x-text="rcp.recipient"></td>
                                                <td :style="rcp.status === 'sent' ? 'color:#16a34a;' : 'color:#dc2626;'" x-text="rcp.status"></td>
                                                <td x-text="rcp.sent_at || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="modal-footer" style="display:flex;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                    <button class="btn btn-outline" @click="contentModal.open = false">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── 4. Recipient Management ─────────────────────────────────────── -->
<?php if ($canEdit): ?>
<div class="card" style="margin-bottom:20px;" x-data="FF_RecipientManager()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Recipient Management
        <span class="badge badge-warning" style="font-size:0.7rem;">D-INTEL-2 gate 3</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
            Per-user opt-in for the morning briefing. A user must (a) have a role in the allow list above
            AND (b) have opt_in=1 below to receive a daily email. Filter shows only users whose role currently
            qualifies; flip the switch above to broaden visibility.
        </p>

        <div class="table-wrapper">
            <table class="table table-sm" style="margin:0;">
                <thead>
                    <tr>
                        <th align="left">User</th>
                        <th align="left">Role</th>
                        <th align="center" style="width:70px;">Opt-in</th>
                        <th align="center" style="width:110px;">Hour</th>
                        <th align="center" style="width:140px;">Sections</th>
                        <th align="left" style="width:220px;">Snooze</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($intelRecipients as $u):
                        $inAllowList   = isset($intelRolesSet[$u['role_slug']]);
                        $snoozeRaw     = $u['briefing_snoozed_until'];
                        $snoozeActive  = $snoozeRaw !== null && strtotime((string) $snoozeRaw) > time();
                        $userHour      = $u['briefing_hour'];
                        $sectionsRaw   = $u['briefing_sections'];
                        $sectionsArr   = $sectionsRaw !== null ? (json_decode((string) $sectionsRaw, true) ?: []) : null;
                        $sectionsLabel = $sectionsArr === null
                            ? 'all'
                            : (count($sectionsArr) === 0 ? '(none)' : count($sectionsArr) . ' of 6');
                    ?>
                    <tr>
                        <td>
                            <?= e((string) $u['name']) ?>
                            <div class="text-muted" style="font-size:0.72rem;"><?= e((string) $u['email']) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= $inAllowList ? 'badge-info' : 'badge-secondary' ?>" style="font-size:0.7rem;">
                                <?= e((string) $u['role_slug']) ?>
                            </span>
                        </td>
                        <td align="center">
                            <label class="form-check" style="margin:0;display:inline-flex;align-items:center;">
                                <input type="checkbox"
                                       <?= (int) $u['morning_briefing_opt_in'] === 1 ? 'checked' : '' ?>
                                       @change="toggleOptIn(<?= (int) $u['id'] ?>, $event.target.checked ? 1 : 0)">
                            </label>
                        </td>
                        <td align="center">
                            <input type="number" min="0" max="23" placeholder="<?= $intelGlobalDigestHour ?>"
                                   value="<?= $userHour !== null ? e((string) (int) $userHour) : '' ?>"
                                   style="width:60px;font-size:0.78rem;text-align:center;"
                                   class="form-control form-control-sm"
                                   @change="setHour(<?= (int) $u['id'] ?>, $event.target.value)"
                                   title="0..23 local, or blank to use global (<?= $intelGlobalDigestHour ?>)">
                        </td>
                        <td align="center">
                            <button class="btn btn-sm btn-outline" style="padding:2px 6px;font-size:0.72rem;"
                                    @click="openSections(<?= (int) $u['id'] ?>, <?= htmlspecialchars(json_encode($sectionsArr), ENT_QUOTES) ?>)">
                                <?= e($sectionsLabel) ?>
                            </button>
                        </td>
                        <td>
                            <?php if ($snoozeActive): ?>
                                <span class="badge badge-warning" style="font-size:0.7rem;">until <?= e((string) $snoozeRaw) ?></span>
                                <button class="btn btn-sm btn-outline" style="margin-left:4px;padding:2px 6px;font-size:0.7rem;" @click="setSnooze(<?= (int) $u['id'] ?>, null)">Clear</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline" style="padding:2px 6px;font-size:0.7rem;" @click="setSnooze(<?= (int) $u['id'] ?>, '1d')">1d</button>
                                <button class="btn btn-sm btn-outline" style="padding:2px 6px;font-size:0.7rem;margin-left:2px;" @click="setSnooze(<?= (int) $u['id'] ?>, '1w')">1w</button>
                                <button class="btn btn-sm btn-outline" style="padding:2px 6px;font-size:0.7rem;margin-left:2px;" @click="setSnoozeCustom(<?= (int) $u['id'] ?>)">…</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Sections-picker modal (F5) -->
        <div x-show="sectionsModal.open" x-cloak class="modal-overlay"
             style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
             @click.self="sectionsModal.open = false">
            <div class="modal" style="max-width:480px;width:90%;">
                <div class="modal-header">
                    <h3 class="h5" style="margin:0;">Briefing sections for this user</h3>
                    <button class="modal-close-btn" @click="sectionsModal.open = false" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
                        Pick which sections appear in this user's daily email. Default (all checked → save with all selected, or none = "use all").
                    </p>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <template x-for="key in ['overdue','compliance','damage','risk_high','health_drops','brief']" :key="key">
                            <label class="form-check" style="margin:0;">
                                <input type="checkbox" :value="key" x-model="sectionsModal.selected">
                                <span style="margin-left:6px;font-size:0.875rem;" x-text="sectionLabel(key)"></span>
                            </label>
                        </template>
                    </div>
                </div>
                <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                    <button class="btn btn-outline" @click="resetSections()">Reset to all</button>
                    <button class="btn btn-primary" @click="saveSections()">Save</button>
                </div>
            </div>
        </div>

        <div x-show="optFlash.message" x-cloak
             :class="optFlash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
             style="margin-top:10px;font-size:0.8125rem;"
             x-text="optFlash.message"></div>
    </div>
</div>
<?php endif; ?>

<!-- ── 4b. Delivery Channels (S-INTEL-V2 Phase D follow-up) ────────── -->
<?php
// Render slack + twilio settings via the same typed render loop as
// AI Core. Each group becomes its own card. Operator can wire up
// Slack webhook + Twilio creds here; users opt into channels via the
// Recipient Management table below.
$deliveryGroups = ['slack', 'twilio'];
foreach ($deliveryGroups as $delGrp):
    if (empty($grouped[$delGrp] ?? [])) continue;
?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        <?= e($groupLabels[$delGrp] ?? ucfirst($delGrp)) ?>
        <span class="badge badge-warning" style="font-size:0.7rem;">Sensitive</span>
        <span class="badge badge-info" style="font-size:0.7rem;">Multi-channel briefing</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            <?= $delGrp === 'slack'
                ? 'Configure Slack incoming webhook for briefing delivery. When enabled, users with <code>slack</code> in their channel list receive a digest summary in your workspace.'
                : 'Configure Twilio for SMS briefing delivery. Each user needs a valid E.164 phone (set via the recipient table) and <code>sms</code> in their channel list.' ?>
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="<?= e($delGrp) ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px 24px;">
            <?php foreach ($grouped[$delGrp] as $setting):
                $key      = $setting['key'];
                $val      = $setting['value'] ?? '';
                $vtype    = $setting['value_type'];
                $label    = $setting['label'] ?? $key;
                $desc     = $setting['description'] ?? null;
                $isSecret = in_array($key, $secretKeys, true);
                $display  = $isSecret ? $maskSecret((string) $val) : (string) $val;
            ?>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <?php if ($vtype === 'boolean'): ?>
                <div class="form-check">
                    <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                           <?= $val === '1' ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>>
                    <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                </div>
                <?php elseif ($isSecret): ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control font-mono"
                       value="<?= e($display) ?>" maxlength="500"
                       autocomplete="off" spellcheck="false"
                       placeholder="Paste new value to replace stored"
                       <?= !$canEdit ? 'readonly' : '' ?>
                       onfocus="if(this.value.startsWith('•')) this.select();">
                <?php else: ?>
                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" class="form-control"
                       value="<?= e($val) ?>" maxlength="500" autocomplete="off" <?= !$canEdit ? 'readonly' : '' ?>>
                <?php endif; ?>
                <?php if ($desc): ?>
                <p class="text-muted" style="font-size:0.75rem;margin:4px 0 0;"><?= e($desc) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:20px;margin-top:20px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save <?= e($groupLabels[$delGrp] ?? ucfirst($delGrp)) ?> Settings</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ── 5. Anomaly Scan ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Anomaly Scan
        <span class="badge badge-info" style="font-size:0.7rem;">Cron 02:00</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            Nightly statistical scan for unusual patterns in invoices, equipment, and lease data.
            SQL-based; AI enrichment optional. Toggled via <code>ai.anomaly_scan_enabled</code> above in AI Core.
        </p>
        <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:0.8125rem;">
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Status</div>
                <div style="margin-top:2px;" :style="<?= (string) settings_get('ai.anomaly_scan_enabled', '1') === '1' ? "'color:#16a34a;'" : "'color:#dc2626;'" ?>">
                    <?= (string) settings_get('ai.anomaly_scan_enabled', '1') === '1' ? 'Enabled' : 'Disabled' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Last scan</div>
                <div style="margin-top:2px;"><?= $lastAnomalyScan ? e((string) $lastAnomalyScan) : '<span class="text-muted">never</span>' ?></div>
            </div>
            <div>
                <a href="<?= base_url('ai/anomalies') ?>" style="font-size:0.875rem;">View anomalies dashboard &rarr;</a>
            </div>
        </div>
    </div>
</div>

<script>
function FF_AiTest() {
    return {
        testing: false, tested: false, connected: false, message: '', details: {},
        async test() {
            this.testing = true; this.tested = false;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/test-connection') ?>');
                this.connected = r.success === true;
                this.message = r.message || (r.success ? 'Connected.' : 'Test failed.');
                this.details = r.details || {};
            } catch(e) {
                this.connected = false; this.message = 'Network error.';
            }
            this.tested = true; this.testing = false;
        },
    };
}

function FF_BriefingControl() {
    return {
        history: { runs: [], current_recipient_count: 0, briefing_enabled: false, cache_active: false, last_brief_generated_at: null },
        testSending: false,
        generating: false,
        testFlash: { message: '', type: '' },
        genFlash: { message: '', type: '' },
        contentModal: { open: false, loading: false, date: '', data: null, error: '' },

        async loadHistory() {
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/admin/intelligence/briefing_history.php'));
                if (j.success) {
                    this.history = j.data;
                }
            } catch (e) {
                console.error('Failed to load briefing history', e);
            }
        },

        async sendTest() {
            this.testSending = true;
            this.testFlash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/test_briefing.php'), {});
                if (j.success) {
                    this.testFlash = { message: 'Test briefing sent to ' + j.data.recipient + '. Check your inbox.', type: 'success' };
                    await this.loadHistory();
                } else {
                    this.testFlash = { message: (j.error && j.error.message) || 'Test send failed', type: 'error' };
                }
            } catch (e) {
                this.testFlash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.testSending = false;
            }
        },

        async generateNow() {
            if (!confirm('Generate a fresh brief now? This calls Claude (~3000 tokens, ~$0.05). The new brief replaces today\'s cache.')) return;
            this.generating = true;
            this.genFlash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/generate_brief_now.php'), { force: 1 });
                if (j.success) {
                    const d = j.data;
                    if (d.cache_hit) {
                        this.genFlash = { message: 'Cache hit (used recent brief). ' + d.message, type: 'success' };
                    } else {
                        this.genFlash = {
                            message: '<strong>Brief generated</strong> — tokens=' + d.tokens_used.toLocaleString() + ', cost=$' + d.cost_usd.toFixed(4) + ', latency=' + d.latency_ms + 'ms.<br><div style="background:#f7f7f6;padding:10px;border-radius:4px;margin-top:6px;font-size:0.78rem;white-space:pre-wrap;">' + this.escapeHtml(d.brief_preview) + '&hellip;</div>',
                            type: 'success'
                        };
                    }
                    await this.loadHistory();
                } else {
                    this.genFlash = { message: (j.error && j.error.message) || 'Generation failed', type: 'error' };
                }
            } catch (e) {
                this.genFlash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.generating = false;
            }
        },

        async openContentModal(runDate) {
            this.contentModal = { open: true, loading: true, date: runDate, data: null, error: '' };
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/admin/intelligence/brief_content.php?date=' + encodeURIComponent(runDate)));
                if (j.success) {
                    this.contentModal.data = j.data;
                } else {
                    this.contentModal.error = (j.error && j.error.message) || 'Failed to load';
                }
            } catch (e) {
                this.contentModal.error = e.message || 'Network error';
            } finally {
                this.contentModal.loading = false;
            }
        },

        escapeHtml(s) { return String(s||'').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); },

        formatTs(ts) {
            if (!ts) return '—';
            try { return new Date(String(ts).replace(' ', 'T')).toLocaleString(); } catch { return ts; }
        },
    };
}

function FF_RecipientManager() {
    return {
        optFlash: { message: '', type: '' },
        sectionsModal: { open: false, userId: null, selected: [] },

        sectionLabel(key) {
            return ({
                overdue: 'Overdue Invoices',
                compliance: 'Compliance Expiring',
                damage: 'Open Damage Claims',
                risk_high: 'Customer Risk Changes',
                health_drops: 'Equipment Health Drops',
                brief: 'AI Fleet Brief',
            })[key] || key;
        },

        openSections(userId, currentArrayOrNull) {
            this.sectionsModal = {
                open: true,
                userId: userId,
                // null on server = all; we represent as "all checked" in the UI
                selected: currentArrayOrNull === null
                    ? ['overdue','compliance','damage','risk_high','health_drops','brief']
                    : currentArrayOrNull.slice(),
            };
        },

        resetSections() {
            this.sectionsModal.selected = ['overdue','compliance','damage','risk_high','health_drops','brief'];
        },

        async saveSections() {
            const all = ['overdue','compliance','damage','risk_high','health_drops','brief'];
            const selected = this.sectionsModal.selected;
            // If user kept all 6 checked → save null (use all default).
            const value = (selected.length === all.length && all.every(k => selected.includes(k))) ? null : selected;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/set_user_preferences.php'), {
                    user_id: this.sectionsModal.userId,
                    briefing_sections: value,
                });
                if (j.success) {
                    this.optFlash = { message: 'Sections updated. Reloading…', type: 'success' };
                    this.sectionsModal.open = false;
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    this.optFlash = { message: (j.error && j.error.message) || 'Save failed', type: 'error' };
                }
            } catch (e) {
                this.optFlash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        async setHour(userId, hourStr) {
            const hour = hourStr === '' || hourStr === null ? null : parseInt(hourStr, 10);
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/set_user_preferences.php'), {
                    user_id: userId,
                    briefing_hour: hour,
                });
                if (j.success) {
                    this.optFlash = { message: 'Hour updated.', type: 'success' };
                } else {
                    this.optFlash = { message: (j.error && j.error.message) || 'Save failed', type: 'error' };
                }
            } catch (e) {
                this.optFlash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        async toggleOptIn(userId, optIn) {
            this.optFlash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/set_opt_in.php'), { user_id: userId, opt_in: optIn });
                if (j.success) {
                    this.optFlash = { message: 'Updated.', type: 'success' };
                } else {
                    this.optFlash = { message: (j.error && j.error.message) || 'Toggle failed', type: 'error' };
                }
            } catch (e) {
                this.optFlash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        async setSnooze(userId, snoozeValue) {
            this.optFlash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/admin/intelligence/set_snooze.php'), { user_id: userId, snoozed_until: snoozeValue });
                if (j.success) {
                    this.optFlash = { message: j.data.cleared ? 'Snooze cleared. Reloading…' : 'Snoozed until ' + j.data.snoozed_until + '. Reloading…', type: 'success' };
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.optFlash = { message: (j.error && j.error.message) || 'Snooze failed', type: 'error' };
                }
            } catch (e) {
                this.optFlash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        async setSnoozeCustom(userId) {
            const input = prompt('Snooze until (YYYY-MM-DD, or "until_monday", or "1d"/"1w"):');
            if (!input) return;
            await this.setSnooze(userId, input);
        },
    };
}
</script>

<!-- ── 6. Token Analytics (S-INTEL-V2 F1) ──────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_TokenAnalytics()" x-init="load()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Token Analytics
        <span class="badge badge-info" style="font-size:0.7rem;">Cost &amp; usage</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            Real-time Claude API token consumption + cost. Budget threshold alerts (configured below)
            email super_admins when usage crosses configured fractions of <code>ai.daily_token_limit</code>.
        </p>

        <!-- Today's usage bar -->
        <div style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;font-size:0.8125rem;margin-bottom:4px;">
                <span><strong>Today's usage</strong></span>
                <span class="font-mono">
                    <span x-text="(snap.today.tokens || 0).toLocaleString()"></span>
                    <span style="color:var(--text-muted);"> / <span x-text="(snap.limit_tokens || 0).toLocaleString()"></span> tokens</span>
                    <span style="margin-left:8px;font-weight:600;"
                          :style="(snap.percent_used_today * 100) >= 100 ? 'color:#dc2626;' : (snap.percent_used_today * 100) >= 80 ? 'color:#d97706;' : 'color:#16a34a;'"
                          x-text="(snap.percent_used_today * 100).toFixed(1) + '%'"></span>
                </span>
            </div>
            <div style="height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                <div :style="'width:' + Math.min(100, snap.percent_used_today * 100) + '%;height:100%;background:' + ((snap.percent_used_today*100)>=100?'#dc2626':(snap.percent_used_today*100)>=80?'#d97706':'#16a34a') + ';transition:width 0.3s;'"></div>
            </div>
        </div>

        <!-- 4-tile rollups -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:18px;">
            <template x-for="row in [
                {label:'Today',     d:snap.today},
                {label:'Month',     d:snap.mtd},
                {label:'Last 7d',   d:snap.last_7d},
                {label:'Last 30d',  d:snap.last_30d}
            ]" :key="row.label">
                <div style="padding:12px;border:1px solid var(--border-default);border-radius:6px;">
                    <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;" x-text="row.label"></div>
                    <div class="font-mono" style="font-size:1.1rem;font-weight:600;margin:4px 0;" x-text="(row.d.tokens||0).toLocaleString()"></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);">
                        <span x-text="row.d.requests + ' req'"></span> · <span x-text="'$' + (row.d.cost_usd||0).toFixed(4)"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Per-feature today -->
        <template x-if="snap.by_feature_today && snap.by_feature_today.length > 0">
            <div style="margin-bottom:18px;">
                <h3 class="h6" style="margin:0 0 8px;">Today by feature</h3>
                <table class="table table-sm" style="font-size:0.8125rem;margin:0;">
                    <thead><tr><th align="left">Feature</th><th align="right">Tokens</th><th align="right">Requests</th></tr></thead>
                    <tbody>
                        <template x-for="f in snap.by_feature_today" :key="f.query_type">
                            <tr>
                                <td><code x-text="f.query_type"></code></td>
                                <td align="right" class="font-mono" x-text="f.tokens.toLocaleString()"></td>
                                <td align="right" class="font-mono" x-text="f.requests"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
        <template x-if="snap.by_feature_today && snap.by_feature_today.length === 0">
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">No AI activity today yet.</p>
        </template>

        <!-- 7d chart (CSS bar chart, no external library) -->
        <template x-if="snap.daily_7d_chart && snap.daily_7d_chart.length > 0">
            <div>
                <h3 class="h6" style="margin:0 0 8px;">Last 7 days</h3>
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;align-items:end;height:120px;">
                    <template x-for="d in snap.daily_7d_chart" :key="d.date">
                        <div style="display:flex;flex-direction:column;align-items:center;justify-content:end;">
                            <div :title="d.date + ': ' + d.tokens.toLocaleString() + ' tokens'"
                                 :style="'width:100%;background:#7c3aed;border-radius:4px 4px 0 0;height:' + (maxDailyTokens > 0 ? Math.max(2, (d.tokens / maxDailyTokens) * 100) : 0) + '%;'"></div>
                            <div style="font-size:0.65rem;color:var(--text-muted);margin-top:4px;text-align:center;" x-text="d.date.slice(5)"></div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>

<!-- ── 7. AI Request Log (S-INTEL-V2 F10) ──────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_RequestLog()" x-init="load()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        AI Request Log
        <span class="badge badge-info" style="font-size:0.7rem;">ai_query_log</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
            Every Claude API call FleetForge made. Filter by feature or user; useful for debugging
            cost spikes or slow responses.
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:12px;">
            <div>
                <label class="form-label">Query type</label>
                <select class="form-select form-select-sm" x-model="filters.query_type" @change="page=1; load()">
                    <option value="">All</option>
                    <template x-for="t in queryTypes" :key="t"><option :value="t" x-text="t"></option></template>
                </select>
            </div>
            <div>
                <label class="form-label">Since</label>
                <input type="date" class="form-control form-control-sm" x-model="filters.since" @change="page=1; load()">
            </div>
            <div style="display:flex;align-items:flex-end;">
                <button class="btn btn-secondary btn-sm" @click="filters = {query_type:'', since:''}; page=1; load()">Reset</button>
            </div>
        </div>

        <template x-if="rows.length === 0">
            <p style="font-size:0.8125rem;color:var(--text-muted);">No requests match the filter.</p>
        </template>

        <template x-if="rows.length > 0">
            <div class="table-wrapper">
                <table class="table table-sm" style="font-size:0.8125rem;margin:0;">
                    <thead><tr>
                        <th align="left">When</th>
                        <th align="left">Type</th>
                        <th align="left">User</th>
                        <th align="right">Prompt</th>
                        <th align="right">Completion</th>
                        <th align="right">Total</th>
                        <th align="right">Cost</th>
                        <th align="right">Latency</th>
                        <th align="center">Cached</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.id">
                            <tr>
                                <td x-text="formatTs(r.created_at)"></td>
                                <td><code x-text="r.query_type"></code></td>
                                <td x-text="r.user_name || (r.user_id ? '#' + r.user_id : 'system')"></td>
                                <td align="right" class="font-mono" x-text="(r.prompt_tokens||0).toLocaleString()"></td>
                                <td align="right" class="font-mono" x-text="(r.completion_tokens||0).toLocaleString()"></td>
                                <td align="right" class="font-mono" x-text="(r.total_tokens||0).toLocaleString()"></td>
                                <td align="right" class="font-mono" x-text="'$' + parseFloat(r.cost_usd || 0).toFixed(4)"></td>
                                <td align="right" class="font-mono" x-text="(r.latency_ms||0) + 'ms'"></td>
                                <td align="center" x-text="r.was_cached == 1 ? '✓' : ''"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <template x-if="totalPages > 1">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:0.8125rem;">
                <button class="btn btn-sm btn-outline" :disabled="page <= 1" @click="page--; load()">Prev</button>
                <span>Page <span x-text="page"></span> of <span x-text="totalPages"></span> · <span x-text="total + ' rows'"></span></span>
                <button class="btn btn-sm btn-outline" :disabled="page >= totalPages" @click="page++; load()">Next</button>
            </div>
        </template>
    </div>
</div>

<!-- ── 8. Recent Activity (S-INTEL-V2 F11) ─────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_AuditFeed()" x-init="load()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Recent Intelligence Activity
        <span class="badge badge-info" style="font-size:0.7rem;">audit_log</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
            Recent settings changes, test sends, opt-in toggles, and cron run summaries scoped to AI / briefing infrastructure.
        </p>
        <template x-if="rows.length === 0">
            <p style="font-size:0.8125rem;color:var(--text-muted);">No recent activity.</p>
        </template>
        <template x-if="rows.length > 0">
            <div class="table-wrapper">
                <table class="table table-sm" style="font-size:0.8125rem;margin:0;">
                    <thead><tr>
                        <th align="left">When</th>
                        <th align="left">User</th>
                        <th align="left">Action</th>
                        <th align="left">Entity</th>
                        <th align="left">Notes</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in rows" :key="r.id">
                            <tr>
                                <td x-text="formatTs(r.created_at)"></td>
                                <td x-text="r.user_name"></td>
                                <td><code x-text="r.action"></code></td>
                                <td><span class="text-muted" x-text="r.entity_type"></span> · <span x-text="r.entity_label"></span></td>
                                <td style="color:var(--text-muted);" x-text="r.notes ? (r.notes.length > 80 ? r.notes.slice(0,77)+'…' : r.notes) : ''"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</div>

<!-- ── 9. Budget Alert Settings (S-INTEL-V2 F12) ───────────────────── -->
<?php if ($canEdit): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
        Budget Alert Configuration
        <span class="badge badge-warning" style="font-size:0.7rem;">Email alerts</span>
    </div>
    <div class="card-body">
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px;">
            Configure when the system emails super_admins about daily token consumption. Defaults to alert at 50%, 80%, and 100% of <code>ai.daily_token_limit</code>.
            Alerts dedup per threshold per day.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="ai">
            <!-- S-INTEL-FIX: declare ownership so this form only touches its
                 two keys — never wipes ai.anthropic_api_key, ai.model, etc. -->
            <input type="hidden" name="_form_keys[]" value="ai.budget_alert_thresholds">
            <input type="hidden" name="_form_keys[]" value="ai.budget_alert_recipients">

            <?php
            $thresholdsJson = (string) settings_get('ai.budget_alert_thresholds', '[0.5,0.8,1.0]');
            $alertRolesJson = (string) settings_get('ai.budget_alert_recipients', '["super_admin"]');
            $alertRolesDecoded = json_decode($alertRolesJson, true);
            $alertRolesSet = is_array($alertRolesDecoded) ? array_flip($alertRolesDecoded) : [];
            ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="ai.budget_alert_thresholds">Alert thresholds (JSON array of 0..1 fractions)</label>
                    <input type="text" id="ai.budget_alert_thresholds" name="ai.budget_alert_thresholds" class="form-control font-mono"
                           value="<?= e($thresholdsJson) ?>" <?= !$canEdit ? 'readonly' : '' ?>>
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0;">
                        e.g. <code>[0.5,0.8,1.0]</code> alerts at 50%, 80%, 100%. <code>[]</code> disables alerts entirely.
                    </p>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Alert recipient roles</label>
                    <div style="display:flex;flex-direction:column;gap:6px;padding:10px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <?php foreach ($intelRoles as $role): ?>
                        <label class="form-check" style="margin:0;">
                            <input type="checkbox"
                                   name="ai.budget_alert_recipients[]"
                                   value="<?= e($role['slug']) ?>"
                                   <?= isset($alertRolesSet[$role['slug']]) ? 'checked' : '' ?>
                                   <?= !$canEdit ? 'disabled' : '' ?>>
                            <span style="margin-left:6px;font-size:0.875rem;"><?= e($role['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:14px;margin-top:14px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save Alert Config</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function FF_TokenAnalytics() {
    return {
        snap: { today:{tokens:0,requests:0,cost_usd:0}, mtd:{tokens:0,requests:0,cost_usd:0}, last_7d:{tokens:0,requests:0,cost_usd:0}, last_30d:{tokens:0,requests:0,cost_usd:0}, by_feature_today:[], daily_7d_chart:[], limit_tokens:0, percent_used_today:0 },
        get maxDailyTokens() { return Math.max(1, ...(this.snap.daily_7d_chart || []).map(d => d.tokens)); },
        async load() {
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/admin/intelligence/token_analytics.php'));
                if (j.success) this.snap = j.data;
            } catch (e) { console.error('Token analytics load failed', e); }
        },
    };
}
function FF_RequestLog() {
    return {
        rows: [], queryTypes: [], total: 0, totalPages: 1, page: 1, pageSize: 25,
        filters: { query_type: '', since: '' },
        async load() {
            try {
                const params = new URLSearchParams({ page: String(this.page), page_size: String(this.pageSize) });
                if (this.filters.query_type) params.set('query_type', this.filters.query_type);
                if (this.filters.since)       params.set('since', this.filters.since);
                const j = await FF_Api.get(FF_Api.url('/api/v1/admin/intelligence/ai_request_log.php?' + params.toString()));
                if (j.success) {
                    this.rows = j.data.rows;
                    this.queryTypes = j.data.query_types;
                    this.total = j.data.pagination.total;
                    this.totalPages = j.data.pagination.total_pages;
                }
            } catch (e) { console.error('Request log load failed', e); }
        },
        formatTs(s) { if (!s) return '—'; try { return new Date(String(s).replace(' ','T')).toLocaleString(); } catch { return s; } },
    };
}
function FF_AuditFeed() {
    return {
        rows: [],
        async load() {
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/admin/intelligence/briefing_audit_log.php?limit=25'));
                if (j.success) this.rows = j.data.rows;
            } catch (e) { console.error('Audit feed load failed', e); }
        },
        formatTs(s) { if (!s) return '—'; try { return new Date(String(s).replace(' ','T')).toLocaleString(); } catch { return s; } },
    };
}
</script>

</div><!-- /intelligence tab -->

</div><!-- /x-data root -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
