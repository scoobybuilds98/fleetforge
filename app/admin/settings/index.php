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
            // Fetch all keys belonging to this group
            $groupKeys = db_select(
                "SELECT `key`, value_type FROM settings WHERE group_name = ?",
                [$groupName]
            );

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
                    } elseif ($key === 'ai.briefing_recipient_roles') {
                        // S-INTEL-TAB: multi-checkbox UI for Intelligence tab.
                        // JSON array of user_roles.slug values per D-INTEL-2;
                        // empty selection => '[]' which means no role passes
                        // the gate (effectively disables the briefing without
                        // touching ai.briefing_enabled — useful for staging
                        // the rollout).
                        $rolesArr = is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
                        $val = json_encode($rolesArr, JSON_UNESCAPED_SLASHES);
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
            });

            $saveFlash = 'Settings saved successfully.';
        }
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
     WHERE group_name IN ('company','invoices','leases','maintenance','alerts','notifications','gps','ai','yards','email','storage','aws','currency','security')
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
$sensitiveGroups   = ['gps', 'email', 'storage', 'aws', 'security'];
$intelligenceGroups = ['ai'];

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
        Portal Users <span class="tab-badge" style="font-size:0.7rem;"><?= e((string)$portalUserCount) ?></span>
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
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="text-align:center;padding:48px 32px;">
            <div style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:8px;">
                Portal user management has moved
            </div>
            <h2 style="font-size:1.125rem;font-weight:600;margin:0 0 8px;">Manage portal users in the Users module</h2>
            <p style="font-size:0.875rem;color:var(--text-tertiary);max-width:560px;margin:0 auto 24px;">
                Portal users (customer-side logins) are now managed alongside admin users in the
                dedicated Users module. The new Portal Users tab offers a richer surface — sortable
                list with company column, status / Email Off badges, per-user detail page with
                login history, and a Re-enable Email action for SES-bounce recoveries.
            </p>
            <?php /* S-PERM-USERS-SUPERADMIN-ONLY — gate hardcoded /users link to super_admin only. */ ?>
            <?php if (can('users', 'view')): ?>
            <a href="<?= base_url('users') ?>?tab=portal" class="btn btn-primary">
                Go to Users &rarr; Portal Users &rarr;
            </a>
            <?php endif; ?>
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

<?php if (empty($grouped['gps']) && empty($grouped['ai'])): ?>
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
// role for super_admin's row-level management.
$intelRecipients = db_select(
    "SELECT u.id, u.name, u.email, u.morning_briefing_opt_in, ur.slug AS role_slug
       FROM users u
       JOIN user_roles ur ON ur.id = u.role_id
      WHERE u.deleted_at IS NULL AND u.status = 'active'
      ORDER BY ur.id, u.name"
);

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
                // section below.
                if ($key === 'ai.briefing_recipient_roles') continue;
            ?>
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

        <!-- Settings form: digest_hour + recipient_roles multi-checkbox -->
        <form method="POST" action="" style="margin-bottom:18px;">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="ai">

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
                            Hour (0–23) when notification_digest cron dispatches the morning email. Reads <code>company.timezone</code> for the local clock.
                            (Note: changing this requires saving the Notifications group, NOT AI — separate form below.)
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div style="padding-top:14px;margin-top:14px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">Save Recipient Roles</button>
            </div>
            <?php endif; ?>
        </form>

        <!-- Test send + history -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;padding-top:16px;border-top:1px solid var(--border-default);">

            <div>
                <h3 class="h6" style="margin:0 0 8px;">Test send</h3>
                <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
                    Sends today's cached briefing to your email (<?= e((string) (current_user()['email'] ?? '?')) ?>).
                    Does NOT call Claude — uses the brief already in <code>report_cache</code>
                    (D-INTEL-3). If no cache exists yet, you'll get a clear error.
                </p>
                <button class="btn btn-secondary btn-sm" @click="sendTest()" :disabled="testSending || !history.cache_active">
                    <span x-show="!testSending">Send me a test briefing now</span>
                    <span x-show="testSending" x-cloak>Sending&hellip;</span>
                </button>
                <div x-show="testFlash.message" x-cloak
                     :class="testFlash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
                     style="margin-top:10px;font-size:0.8125rem;"
                     x-text="testFlash.message"></div>
            </div>

            <div>
                <h3 class="h6" style="margin:0 0 8px;">Recent runs (last 10)</h3>
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
                                <tr>
                                    <td x-text="r.run_date"></td>
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
                        <th align="left">Email</th>
                        <th align="left">Role</th>
                        <th align="center" style="width:120px;">Opt-in</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($intelRecipients as $u):
                        $inAllowList = isset($intelRolesSet[$u['role_slug']]);
                    ?>
                    <tr :class="(<?= (int) $u['morning_briefing_opt_in'] ?> === 1 && <?= $inAllowList ? '1' : '0' ?> === 1) ? '' : ''">
                        <td><?= e((string) $u['name']) ?></td>
                        <td class="text-muted" style="font-size:0.78rem;"><?= e((string) $u['email']) ?></td>
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
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div x-show="optFlash.message" x-cloak
             :class="optFlash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
             style="margin-top:10px;font-size:0.8125rem;"
             x-text="optFlash.message"></div>
    </div>
</div>
<?php endif; ?>

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
        testFlash: { message: '', type: '' },

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

        formatTs(ts) {
            if (!ts) return '—';
            try { return new Date(String(ts).replace(' ', 'T')).toLocaleString(); } catch { return ts; }
        },
    };
}

function FF_RecipientManager() {
    return {
        optFlash: { message: '', type: '' },

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
    };
}
</script>

</div><!-- /intelligence tab -->

</div><!-- /x-data root -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
