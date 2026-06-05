<?php
declare(strict_types=1);

/**
 * app/admin/users/permissions.php
 *
 * PERM-1 — Per-user permission override matrix admin page.
 * S-PERM-EXPAND — extended action vocabulary + module group macros.
 *
 * super_admin only. Lists every (module, action) pair from
 * config/permissions.php in a toggle matrix for a single target user.
 *
 * The action vocabulary is per-module — modules that declare extended
 * verbs in config/permission_actions.php (e.g. journal_entries adds
 * 'post' + 'approve'; quickbooks has its own 7-verb set) render with
 * additional columns. Modules without extended actions render the
 * standard 5-column CRUD layout.
 *
 * Each cell shows:
 *   - the role baseline (true/false, displayed as a dim dot)
 *   - the current override (allow / deny / none)
 *   - the effective resolved value
 * Clicking a cell cycles through: none → allow → deny → none
 *
 * The Bulk Operations card (S-PERM-EXPAND) renders one row per group
 * from config/permission_groups.php (accounting, quickbooks, fleet_ops)
 * with 4 macro buttons: Grant View / Grant Read+Write / Deny All / Clear.
 * Each macro posts to api/v1/users/permissions/group_apply.php with a
 * required reason; on success we re-fetch index.php and re-render the
 * matrix from authoritative data (no local patch).
 *
 * Data flow:
 *   - Initial load: inline-rendered PHP pulls the full matrix once
 *     (same code path as api/v1/users/permissions/index.php).
 *   - Every cell click POSTs to api/v1/users/permissions/update.php
 *     and updates the local Alpine store on success.
 *   - "Reset all" POSTs to api/v1/users/permissions/reset.php.
 *   - Group macros POST to api/v1/users/permissions/group_apply.php
 *     and trigger a full refresh from index.php.
 *
 * Security:
 *   - Page requires is_super_admin() — other roles get the developer-only
 *     access wall.
 *   - Target user must not be super_admin (API also enforces, but
 *     the UI disables cells + hides the macro card in that case).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           api/v1/users/permissions/{index,update,reset,group_apply}.php
 * @session  PERM-1, S-PERM-EXPAND
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// S-PERM-USERS-ACCESS-WALL — Users module is super_admin only.
// Replaces the prior generic 403 fallback with the custom developer-only
// access wall, rendered inside the normal admin shell so non-super_admin
// viewers see why they can't enter. header.php already opens sidebar/
// topbar/<main class="page-content">; footer.php closes them.
if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <div class="access-wall-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5"
                 xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="access-wall-title">Developer Access Only</h2>
        <p class="access-wall-message">
            The Users &amp; Roles module is restricted to the Developer account.<br>
            Contact your system administrator for user management assistance.
        </p>
        <a href="<?= base_url('dashboard') ?>" class="btn btn-secondary">
            Back to Dashboard
        </a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// ── Resolve target user ─────────────────────────────────────
$targetId = clean_int($_GET['user_id'] ?? null);
if (!$targetId) {
    header('Location: ' . base_url('users'));
    exit;
}

$target = db_row(
    "SELECT u.id, u.name, u.email, u.role_id, u.notification_preferences,
            ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$targetId]
);

if (!$target) {
    header('Location: ' . base_url('users') . '?error=not_found');
    exit;
}

$isSuperAdminTarget = ($target['role_slug'] === 'super_admin');

// ── Build the matrix server-side (mirrors the API) ──────────
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$roleMatrix        = $permissionsConfig[$target['role_slug']] ?? [];

$overrideRows = db_select(
    "SELECT id, module, action, granted, reason, granted_by, updated_at,
            (SELECT name FROM users WHERE id = user_permission_overrides.granted_by) AS granted_by_name
     FROM user_permission_overrides
     WHERE user_id = ?
     ORDER BY module ASC, action ASC",
    [$targetId]
);

$overrideMap = [];
foreach ($overrideRows as $r) {
    $overrideMap[$r['module']][$r['action']] = (int) $r['granted'];
}

// Canonical module list from all roles
$allModules = [];
foreach ($permissionsConfig as $rolePerms) {
    foreach (array_keys($rolePerms) as $m) $allModules[$m] = true;
}
$moduleSlugs = array_keys($allModules);
sort($moduleSlugs);

$labels = [
    'customers'           => 'Customers',
    'equipment'           => 'Equipment',
    'leases'              => 'Leases',
    'reservations'        => 'Reservations',
    'invoices'            => 'Invoices',
    'payments'            => 'Payments',
    'rates'               => 'Rates',
    'maintenance'         => 'Maintenance',
    // S-PERM-CLEANUP: see api/v1/users/permissions/index.php for rationale.
    'inspections'         => 'Inspections',
    'compliance'          => 'Compliance',
    'reports'             => 'Reports',
    'analytics'           => 'Analytics',
    'users'               => 'Users',
    'settings'            => 'Settings',
    'audit'               => 'Audit Log',
    'ai'                  => 'AI Tools',
    'chart_of_accounts'   => 'Chart of Accounts',
    'journal_entries'     => 'Journal Entries',
    'accounts_payable'    => 'Accounts Payable',
    'bank_accounts'       => 'Bank Accounts',
    'fixed_assets'        => 'Fixed Assets',
    'tax_management'      => 'Tax Management',
    'financial_reports'   => 'Financial Reports',
    'budgets'             => 'Budgets',
    'period_management'   => 'Period Management',
    // S-PERM-CLEANUP: accounting_settings module added to config — see
    // api/v1/users/permissions/index.php for the full rationale block.
    'accounting_settings' => 'Accounting Settings',
    // S-PERM-EXPAND: QBO module reserved for Phase QBO.
    'quickbooks'          => 'QuickBooks',
];

// S-PERM-EXPAND: per-module action vocab + groups for the macro card.
// Loaded once here and serialized into the Alpine state JSON below.
$permActionsCfg     = _ff_load_permission_actions();
$actionDescriptions = $permActionsCfg['action_descriptions'] ?? [];
$groupsRaw          = get_permission_groups();
$groups             = [];
foreach ($groupsRaw as $key => $g) {
    $groups[] = [
        'key'         => $key,
        'label'       => $g['label'],
        'description' => $g['description'],
        'modules'     => $g['modules'],
    ];
}

// S-PERM-EXPAND: matrix row shape changed. Each row now declares its
// own `actions` (string array of valid verbs) plus a `permissions` map
// of action→{role, override, effective} state. The previous shape
// where `actions` held both was ambiguous once verbs varied per module.
$matrix = [];
foreach ($moduleSlugs as $slug) {
    $moduleActions = get_actions_for_module($slug);
    $row = [
        'slug'        => $slug,
        'label'       => $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug)),
        'actions'     => $moduleActions,
        'permissions' => [],
    ];
    foreach ($moduleActions as $action) {
        $roleVal = (bool) ($roleMatrix[$slug][$action] ?? false);
        $ovr     = $overrideMap[$slug][$action] ?? null;
        $eff     = $ovr === null ? $roleVal : (bool) $ovr;
        $row['permissions'][$action] = [
            'role'      => $roleVal,
            'override'  => $ovr,
            'effective' => $eff,
        ];
    }
    $matrix[] = $row;
}

// ── All roles for the role-picker ───────────────────────────
$allRoles = db_select(
    "SELECT id, name, slug, description FROM user_roles WHERE is_system = 1 ORDER BY id",
    []
);

// ── Module display groups (section headers in the redesigned UI) ──
$moduleGroupDefs = [
    'Fleet Operations'         => ['color' => '#22c55e', 'bulk_group' => 'fleet_ops',  'modules' => ['customers','equipment','leases','reservations','rates']],
    'Maintenance & Compliance' => ['color' => '#f59e0b', 'bulk_group' => null,         'modules' => ['maintenance','inspections','compliance']],
    'Financial'                => ['color' => '#3b82f6', 'bulk_group' => 'accounting', 'modules' => ['invoices','payments','chart_of_accounts','journal_entries','accounts_payable','bank_accounts','fixed_assets','tax_management','financial_reports','budgets','period_management','accounting_settings']],
    'Analytics & Reports'      => ['color' => '#8b5cf6', 'bulk_group' => null,         'modules' => ['reports','analytics','audit']],
    'System'                   => ['color' => '#64748b', 'bulk_group' => null,         'modules' => ['ai','users','settings','settings_general','settings_design','settings_users','settings_portal','settings_audit','settings_system','settings_integrations','settings_intelligence']],
    'Integrations'             => ['color' => '#14b8a6', 'bulk_group' => 'quickbooks', 'modules' => ['quickbooks']],
];
// Build slug → row lookup for grouped rendering
$matrixBySlug = [];
foreach ($matrix as $row) { $matrixBySlug[$row['slug']] = $row; }

// Build slug → group object lookup (for bulk macro buttons in section headers)
$groupByKey = [];
foreach ($groups as $g) { $groupByKey[$g['key']] = $g; }

$pageTitle = 'Permissions — ' . $target['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     Apple-style Permissions page — S-PERM-APPLE-REDESIGN
     ═══════════════════════════════════════════════════════════ -->

<!-- ── Page header ──────────────────────────────────────────── -->
<div class="perm-page-header">
    <a href="<?= base_url('users/show') ?>?id=<?= $targetId ?>" class="btn btn-secondary btn-sm perm-back-btn">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>Back
    </a>
    <div class="perm-user-identity">
        <div class="perm-user-avatar"><?= e(strtoupper(mb_substr($target['name'], 0, 2))) ?></div>
        <div class="perm-user-info">
            <h1 class="perm-user-name"><?= e($target['name']) ?></h1>
            <div class="perm-user-meta">
                <span class="badge badge-neutral badge-pill"><?= e($target['role_name']) ?></span>
            </div>
        </div>
    </div>
</div>

<?php if ($isSuperAdminTarget): ?>
<div class="perm-info-banner perm-info-banner--warning">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
    <div><strong>super_admin has unrestricted access</strong> — overrides cannot be applied. The matrix is shown for reference only.</div>
</div>
<?php endif; ?>

<!-- ── Role Assignment ─────────────────────────────────────── -->
<?php if (!$isSuperAdminTarget): ?>
<div class="card perm-roles-card" x-data="FF_RoleAssign()" x-init="init(<?= (int)$target['role_id'] ?>, '<?= addslashes($target['updated_at'] ?? '') ?>')">
    <div class="perm-apple-card-header">
        <div class="perm-apple-card-icon" style="background:rgba(139,92,246,0.15);color:#a78bfa;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
        </div>
        <div>
            <div class="perm-apple-card-title">Role Assignment</div>
            <div class="perm-apple-card-subtitle">Sets the permission baseline for this user. Overrides in the matrix below fine-tune it.</div>
        </div>
    </div>
    <div class="perm-role-cards-row">
        <?php
        $roleIconMap = [
            'manager'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z"/></svg>',
            'dispatcher' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
            'accountant' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>',
            'read_only'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        ];
        $roleColorMap = [
            'manager'    => ['bg' => 'rgba(59,130,246,0.15)',  'color' => '#60a5fa'],
            'dispatcher' => ['bg' => 'rgba(34,197,94,0.15)',   'color' => '#4ade80'],
            'accountant' => ['bg' => 'rgba(139,92,246,0.15)',  'color' => '#a78bfa'],
            'read_only'  => ['bg' => 'rgba(100,116,139,0.15)', 'color' => '#94a3b8'],
        ];
        foreach ($allRoles as $role):
            if ($role['slug'] === 'super_admin') continue;
            $isActive = ((int)$role['id'] === (int)$target['role_id']);
            $colors   = $roleColorMap[$role['slug']] ?? ['bg' => 'rgba(100,116,139,0.15)', 'color' => '#94a3b8'];
            $icon     = $roleIconMap[$role['slug']] ?? '';
        ?>
        <div class="perm-role-card <?= $isActive ? 'is-active' : '' ?>"
             @click="changeRole(<?= (int)$role['id'] ?>, '<?= addslashes(e($role['name'])) ?>')">
            <div class="perm-role-card-icon" style="background:<?= e($colors['bg']) ?>;color:<?= e($colors['color']) ?>;">
                <?= $icon ?>
            </div>
            <div class="perm-role-card-name"><?= e($role['name']) ?></div>
            <div class="perm-role-card-desc"><?= e($role['description']) ?></div>
            <?php if ($isActive): ?>
            <div class="perm-role-card-badge">Current role</div>
            <?php endif; ?>
            <a href="<?= base_url('users/role_permissions') ?>?role=<?= e($role['slug']) ?>"
               class="perm-role-defaults-link"
               @click.stop>
                Edit defaults
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:11px;height:11px;"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Role change confirmation modal -->
    <div x-show="roleModal.open" x-cloak class="modal-overlay"
         @click.self="roleModal.open = false"
         @keydown.escape.window="roleModal.open && (roleModal.open = false)"
         style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
        <div class="modal" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Change role?</h3>
                <button class="modal-close-btn" @click="roleModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 8px;font-size:0.875rem;">
                    Change <strong><?= e($target['name']) ?></strong> from
                    <strong><?= e($target['role_name']) ?></strong> to
                    <strong x-text="roleModal.roleName"></strong>?
                </p>
                <p style="margin:0;font-size:0.8125rem;color:var(--text-secondary);">
                    This changes the baseline permissions. Existing per-user overrides are kept.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="roleModal.open = false">Cancel</button>
                <button class="btn btn-primary" @click="confirmChange()" :disabled="updating">
                    <span x-show="!updating">Confirm</span>
                    <span x-show="updating">Updating…</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Main: permission matrix + sidebar ──────────────────── -->
<div x-data="permissionsMatrix()" x-init="init()">

<div class="perm-layout">

<!-- ── Left: Permission matrix ──────────────────────────────── -->
<div class="card perm-matrix-card">

    <div class="perm-matrix-card-header">
        <div>
            <div class="perm-apple-card-title" style="font-size:1rem;">Permission Overrides</div>
            <div class="perm-apple-card-subtitle">
                Green = effective access. Orange ring = override-allow. Red = override-deny.
            </div>
        </div>
        <div class="perm-matrix-header-actions">
            <span class="perm-override-pill" x-show="overrideCount > 0">
                <span x-text="overrideCount"></span>&nbsp;override<span x-show="overrideCount !== 1">s</span>
            </span>
            <?php if (!$isSuperAdminTarget): ?>
            <button class="btn btn-secondary btn-sm" @click="confirmReset()" :disabled="overrideCount === 0 || saving">
                Reset all
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($moduleGroupDefs as $sectionName => $sectionDef): ?>
    <?php
        $hasModules = false;
        foreach ($sectionDef['modules'] as $s) {
            if (isset($matrixBySlug[$s])) { $hasModules = true; break; }
        }
        if (!$hasModules) continue;
        $bulkGroup = ($sectionDef['bulk_group'] && isset($groupByKey[$sectionDef['bulk_group']]))
                   ? $groupByKey[$sectionDef['bulk_group']] : null;
    ?>
    <div class="perm-section">
        <div class="perm-section-header">
            <div class="perm-section-title">
                <span class="perm-section-dot" style="background:<?= e($sectionDef['color']) ?>"></span>
                <?= e($sectionName) ?>
            </div>
            <?php if ($bulkGroup && !$isSuperAdminTarget): ?>
            <div class="perm-section-bulk-actions">
                <button class="perm-bulk-btn" @click="openGroupMacro(<?= e(json_encode($bulkGroup)) ?>, 'grant_view')" :disabled="saving">View</button>
                <button class="perm-bulk-btn" @click="openGroupMacro(<?= e(json_encode($bulkGroup)) ?>, 'grant_read_write')" :disabled="saving">Read+Write</button>
                <button class="perm-bulk-btn perm-bulk-btn--danger" @click="openGroupMacro(<?= e(json_encode($bulkGroup)) ?>, 'deny_all')" :disabled="saving">Deny All</button>
                <button class="perm-bulk-btn perm-bulk-btn--muted" @click="openGroupMacro(<?= e(json_encode($bulkGroup)) ?>, 'clear')" :disabled="saving">Clear</button>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach ($sectionDef['modules'] as $slug): ?>
        <?php if (!isset($matrixBySlug[$slug])) continue; ?>
        <?php $row = $matrixBySlug[$slug]; ?>
        <div class="perm-matrix-row">
            <div class="perm-matrix-row-header">
                <span class="perm-matrix-row-label"><?= e($row['label']) ?></span>
            </div>
            <div class="perm-matrix-row-cells">
                <?php foreach ($row['actions'] as $action): ?>
                <div class="perm-matrix-cell-col">
                    <div class="perm-matrix-cell-header" title="<?= e($actionDescriptions[$action] ?? '') ?>"><?= e($action) ?></div>
                    <div class="perm-ios-toggle <?= $isSuperAdminTarget ? 'is-readonly' : '' ?>"
                         :class="toggleClass('<?= e($slug) ?>', '<?= e($action) ?>')"
                         <?php if (!$isSuperAdminTarget): ?>@click="cycleCell('<?= e($slug) ?>', '<?= e($action) ?>')"<?php endif; ?>
                         :title="cellTooltip('<?= e($slug) ?>', '<?= e($action) ?>')">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

</div><!-- /perm-matrix-card -->

<!-- ── Right sidebar ────────────────────────────────────────── -->
<div class="perm-sidebar-col">

    <!-- Active Overrides -->
    <div class="card perm-sidebar-card">
        <div class="perm-apple-card-header perm-apple-card-header--sm">
            <div class="perm-apple-card-icon perm-apple-card-icon--sm" style="background:rgba(249,115,22,0.14);color:#fb923c;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
            </div>
            <div class="perm-apple-card-title perm-apple-card-title--sm">Active Overrides</div>
            <span class="perm-badge-count" x-show="overrideCount > 0" x-text="overrideCount"></span>
        </div>
        <div style="padding:0;">
            <div x-show="overrideList.length === 0" class="perm-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width:28px;height:28px;opacity:.28;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <span>No overrides — using role defaults</span>
            </div>
            <ul x-show="overrideList.length > 0" class="perm-override-list">
                <template x-for="ovr in overrideList" :key="ovr.module + ':' + ovr.action">
                    <li class="perm-override-item">
                        <div class="perm-override-item-head">
                            <span class="perm-override-badge"
                                  :class="ovr.granted === 1 ? 'perm-override-badge--allow' : 'perm-override-badge--deny'"
                                  x-text="ovr.granted === 1 ? 'Allow' : 'Deny'"></span>
                            <span class="perm-override-target">
                                <span x-text="ovr.label"></span><span class="perm-override-action" x-text="' · ' + ovr.action"></span>
                            </span>
                            <?php if (!$isSuperAdminTarget): ?>
                            <button class="perm-override-clear-btn" @click="sendUpdate(ovr.module, ovr.action, null, null)" :disabled="saving" title="Clear this override">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:10px;height:10px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div x-show="ovr.reason" class="perm-override-reason" x-text="ovr.reason"></div>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    <!-- Notification Preferences -->
    <?php
    $notifCategories = [
        ['slug' => 'customers',    'label' => 'Customers',     'desc' => 'New customer, credit hold, suspended'],
        ['slug' => 'leases',       'label' => 'Leases',        'desc' => 'Lease created, activated, closed'],
        ['slug' => 'invoices',     'label' => 'Invoices',      'desc' => 'Overdue invoices, billing events'],
        ['slug' => 'payments',     'label' => 'Payments',      'desc' => 'Payments received, failed, reversed'],
        ['slug' => 'equipment',    'label' => 'Equipment',     'desc' => 'Unit status changes, availability'],
        ['slug' => 'compliance',   'label' => 'Compliance',    'desc' => 'Expiring docs, CVI/MVI/registration'],
        ['slug' => 'maintenance',  'label' => 'Maintenance',   'desc' => 'Work orders opened and closed'],
        ['slug' => 'damage',       'label' => 'Damage Claims', 'desc' => 'New claims, status changes'],
        ['slug' => 'reservations', 'label' => 'Reservations',  'desc' => 'New, confirmed, and cancelled'],
        ['slug' => 'samsara',      'label' => 'Samsara / GPS', 'desc' => 'Vehicle offline, sync alerts'],
        ['slug' => 'accounting',   'label' => 'Accounting',    'desc' => 'Reconciliation drift, period events'],
        ['slug' => 'quickbooks',   'label' => 'QuickBooks',    'desc' => 'Sync errors, QBO connection issues'],
        ['slug' => 'system',       'label' => 'System',        'desc' => 'Service requests, AI alerts, chat'],
    ];
    $currentOptedOut = json_decode($target['notification_preferences'] ?? 'null', true) ?? [];
    ?>
    <div class="card perm-sidebar-card" x-data="FF_NotifPrefs()" x-init="init(<?= e(json_encode($currentOptedOut)) ?>)">
        <div class="perm-apple-card-header perm-apple-card-header--sm">
            <div class="perm-apple-card-icon perm-apple-card-icon--sm" style="background:rgba(6,182,212,0.14);color:#22d3ee;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
            </div>
            <div class="perm-apple-card-title perm-apple-card-title--sm">Notifications</div>
            <button class="perm-notif-save-btn" :disabled="saving" @click="save()">
                <span x-show="!saving">Save</span><span x-show="saving">…</span>
            </button>
        </div>
        <div style="padding:6px 0 4px;">
            <div x-show="saveOk" class="perm-notif-saved" x-cloak>Saved</div>
            <div x-show="saveError" class="perm-notif-error" x-text="saveError" x-cloak></div>
            <?php foreach ($notifCategories as $cat): ?>
            <div class="perm-notif-row" @click="toggle('<?= e($cat['slug']) ?>', optedOut.includes('<?= e($cat['slug']) ?>'))">
                <div class="perm-notif-text">
                    <span class="perm-notif-label"><?= e($cat['label']) ?></span>
                    <span class="perm-notif-desc"><?= e($cat['desc']) ?></span>
                </div>
                <div class="perm-ios-toggle perm-ios-toggle--sm"
                     :class="!optedOut.includes('<?= e($cat['slug']) ?>') ? 'is-on' : 'is-off'">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- /perm-sidebar-col -->
</div><!-- /perm-layout -->

<!-- Reason modal -->
<div x-show="reasonModal.open" x-cloak class="modal-overlay"
     @click.self="cancelReason()" @keydown.escape.window="reasonModal.open && cancelReason()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title" x-text="reasonModal.intent === 1 ? 'Grant permission' : 'Revoke permission'"></h3>
            <button class="modal-close-btn" @click="cancelReason()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 14px;font-size:0.875rem;color:var(--text-secondary);">
                <span x-text="reasonModal.intent === 1 ? 'Explicitly grant' : 'Explicitly deny'"></span>
                <strong x-text="reasonModal.label"></strong> · <strong x-text="reasonModal.action"></strong>
                for <strong><?= e($target['name']) ?></strong>.
            </p>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3" x-model="reasonModal.reason" maxlength="1000"
                          placeholder="Why is this override needed? (e.g., 'Temporary access for Q1 close')"></textarea>
                <small class="form-text text-muted">Required for audit trail.</small>
            </div>
            <div x-show="reasonModal.error" x-text="reasonModal.error" style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" @click="cancelReason()">Cancel</button>
            <button class="btn btn-primary" :class="reasonModal.intent === 1 ? '' : 'btn-danger'"
                    @click="confirmReason()" :disabled="saving || !(reasonModal.reason || '').trim()">
                <span x-show="!saving" x-text="reasonModal.intent === 1 ? 'Grant' : 'Deny'"></span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </div>
</div>

<!-- Group macro modal -->
<div x-show="groupMacroModal.open" x-cloak class="modal-overlay"
     @click.self="cancelGroupMacro()" @keydown.escape.window="groupMacroModal.open && cancelGroupMacro()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">Bulk: <span x-text="groupMacroModal.macroLabel"></span> · <span x-text="groupMacroModal.groupLabel"></span></h3>
            <button class="modal-close-btn" @click="cancelGroupMacro()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:0.875rem;color:var(--text-secondary);" x-text="groupMacroModal.intro"></p>
            <div x-show="groupMacroModal.qboWarning" class="toast toast-warning" style="position:relative;margin-bottom:12px;animation:none;">
                <div class="toast-body">
                    <div class="toast-title">Extended QBO actions not included</div>
                    <div class="toast-message">Only <code>quickbooks.view</code> is granted. Extended actions must be set individually in the matrix.</div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3" x-model="groupMacroModal.reason" maxlength="1000"
                          placeholder="Why is this bulk change needed?"></textarea>
                <small class="form-text text-muted">Required. Stored on every override row + audit log.</small>
            </div>
            <div x-show="groupMacroModal.error" x-text="groupMacroModal.error" style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" @click="cancelGroupMacro()">Cancel</button>
            <button class="btn" :class="groupMacroModal.macro === 'deny_all' ? 'btn-danger' : 'btn-primary'"
                    @click="confirmGroupMacro()" :disabled="saving || !(groupMacroModal.reason || '').trim()">
                <span x-show="!saving" x-text="groupMacroModal.submitLabel"></span>
                <span x-show="saving">Applying…</span>
            </button>
        </div>
    </div>
</div>

<!-- Reset confirmation modal -->
<div x-show="resetModalOpen" x-cloak class="modal-overlay"
     @click.self="resetModalOpen = false" @keydown.escape.window="resetModalOpen && (resetModalOpen = false)"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">Reset all overrides?</h3>
            <button class="modal-close-btn" @click="resetModalOpen = false">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0;font-size:0.875rem;">
                This will remove all <strong x-text="overrideCount"></strong> override<span x-show="overrideCount !== 1">s</span>
                for <strong><?= e($target['name']) ?></strong> and revert to
                <strong><?= e($target['role_name']) ?></strong> defaults.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" @click="resetModalOpen = false">Cancel</button>
            <button class="btn btn-danger" @click="resetAll()" :disabled="saving">
                <span x-show="!saving">Reset all</span><span x-show="saving">Resetting…</span>
            </button>
        </div>
    </div>
</div>

</div><!-- /x-data permissionsMatrix -->

<style>
/* WHY: perm-* CSS lives in app.css (S-PERM-APPLE-REDESIGN).
   Only page-specific overrides belong here. */
</style>

<script>
function permissionsMatrix() {
    return {
        modules:            <?= json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        groups:             <?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        labels:             <?= json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        actionDescriptions: <?= json_encode($actionDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        saving: false,
        reasonModal: { open:false, module:null, action:null, label:'', intent:null, reason:'', error:null },
        groupMacroModal: { open:false, group:null, groupLabel:'', macro:null, macroLabel:'', submitLabel:'Apply', intro:'', qboWarning:false, reason:'', error:null },
        resetModalOpen: false,

        init() { /* matrix rendered server-side */ },

        toggleClass(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            if (cell.override === 1) return 'ovr-allow';
            if (cell.override === 0) return 'ovr-deny';
            return cell.role ? 'is-on' : 'is-off';
        },

        get overrideCount() {
            let n = 0;
            for (const row of this.modules)
                for (const a of row.actions)
                    if (row.permissions[a].override !== null) n++;
            return n;
        },

        get overrideList() {
            const out = [];
            for (const row of this.modules)
                for (const a of row.actions) {
                    const cell = row.permissions[a];
                    if (cell.override !== null)
                        out.push({ module: row.slug, label: row.label, action: a, granted: cell.override, reason: cell.reason || '' });
                }
            return out;
        },

        cellClass(moduleSlug, action) { return this.toggleClass(moduleSlug, action); },
        cellLabel(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            if (cell.override === 1) return '✓';
            if (cell.override === 0) return '✕';
            return cell.role ? '●' : '○';
        },
        cellTooltip(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            const roleTxt = cell.role ? 'grants' : 'denies';
            const desc = this.actionDescription(action);
            let head;
            if (cell.override === 1)      head = `Override: ALLOW (role ${roleTxt}) — click to clear`;
            else if (cell.override === 0) head = `Override: DENY (role ${roleTxt}) — click to clear`;
            else if (cell.effective)      head = `Role ${roleTxt} — click to override: DENY`;
            else                          head = `Role ${roleTxt} — click to override: ALLOW`;
            return desc ? `${head}\n${desc}` : head;
        },
        actionDescription(action) { return this.actionDescriptions[action] || ''; },
        _cell(moduleSlug, action) { return this.modules.find(r => r.slug === moduleSlug).permissions[action]; },

        cycleCell(moduleSlug, action) {
            if (this.saving) return;
            const cell = this._cell(moduleSlug, action);
            const row  = this.modules.find(r => r.slug === moduleSlug);
            // If an override is active, clear it immediately (no modal needed).
            if (cell.override !== null) {
                this.sendUpdate(moduleSlug, action, null, null);
                return;
            }
            // No override: intent is to flip the effective state.
            // Effective ON → deny it.  Effective OFF → allow it.
            const nextIntent = cell.effective ? 0 : 1;
            this.reasonModal = { open:true, module:moduleSlug, action:action, label:row.label, intent:nextIntent, reason:'', error:null };
        },

        cancelReason() { this.reasonModal.open = false; },
        async confirmReason() {
            const reason = (this.reasonModal.reason || '').trim();
            if (!reason) { this.reasonModal.error = 'A reason is required.'; return; }
            await this.sendUpdate(this.reasonModal.module, this.reasonModal.action, this.reasonModal.intent, reason);
        },

        async sendUpdate(moduleSlug, action, granted, reason) {
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/permissions/update.php'), {
                    user_id: <?= $targetId ?>, module: moduleSlug, action: action, granted: granted, reason: reason,
                });
                if (!res.success) {
                    const msg = (res.error && res.error.message) || 'Save failed.';
                    this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
                    return;
                }
                const row = this.modules.find(r => r.slug === moduleSlug);
                const cell = row.permissions[action];
                cell.override = granted; cell.reason = reason;
                cell.effective = granted === null ? cell.role : Boolean(granted);
                this.reasonModal.open = false;
                FF_Toast.success('Saved', granted === null ? 'Override cleared.' : (granted === 1 ? 'Permission granted.' : 'Permission denied.'));
            } catch (err) {
                const msg = 'Network error. Please try again.';
                this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
            } finally { this.saving = false; }
        },

        confirmReset() { if (this.overrideCount === 0) return; this.resetModalOpen = true; },
        async resetAll() {
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/permissions/reset.php'), { user_id: <?= $targetId ?> });
                if (!res.success) { FF_Toast.error('Error', (res.error && res.error.message) || 'Reset failed.'); return; }
                for (const row of this.modules)
                    for (const a of row.actions) {
                        row.permissions[a].override = null; row.permissions[a].reason = null;
                        row.permissions[a].effective = row.permissions[a].role;
                    }
                this.resetModalOpen = false;
                FF_Toast.success('Reset', `Cleared ${res.data.cleared_count} override${res.data.cleared_count === 1 ? '' : 's'}.`);
            } catch (err) { FF_Toast.error('Error', 'Network error. Please try again.'); }
            finally { this.saving = false; }
        },

        groupStatus(group) {
            let granted = 0, denied = 0;
            for (const m of group.modules) {
                const row = this.modules.find(r => r.slug === m);
                if (!row) continue;
                for (const a of row.actions) {
                    if (row.permissions[a].override === 1) granted++;
                    else if (row.permissions[a].override === 0) denied++;
                }
            }
            return { granted, denied, totalOverrides: granted + denied };
        },
        _macroLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear Overrides'}[macro] || macro; },
        _macroSubmitLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear'}[macro] || 'Apply'; },
        _macroIntro(group, macro) {
            const target = '<?= addslashes(e($target['name'])) ?>';
            const n = group.modules.length, noun = n === 1 ? 'module' : 'modules';
            switch (macro) {
                case 'grant_view':       return `Explicitly grant view on ${n} ${noun} in "${group.label}" for ${target}.`;
                case 'grant_read_write': return `Explicitly grant view + create + edit on ${n} ${noun} in "${group.label}" for ${target}. Delete and extended verbs excluded.`;
                case 'deny_all':         return `Explicitly deny every action on every module in "${group.label}" for ${target}.`;
                case 'clear':            return `Remove every override on ${n} ${noun} in "${group.label}" for ${target}. User reverts to role defaults.`;
                default: return '';
            }
        },
        openGroupMacro(group, macro) {
            if (this.saving) return;
            this.groupMacroModal = { open:true, group:group.key, groupLabel:group.label, macro:macro,
                macroLabel:this._macroLabel(macro), submitLabel:this._macroSubmitLabel(macro), intro:this._macroIntro(group, macro),
                qboWarning: group.key === 'quickbooks' && (macro === 'grant_view' || macro === 'grant_read_write'),
                reason:'', error:null };
        },
        cancelGroupMacro() { this.groupMacroModal.open = false; },
        async confirmGroupMacro() {
            const reason = (this.groupMacroModal.reason || '').trim();
            if (!reason) { this.groupMacroModal.error = 'A reason is required.'; return; }
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/permissions/group_apply.php'), {
                    user_id: <?= $targetId ?>, group: this.groupMacroModal.group, macro: this.groupMacroModal.macro, reason: reason,
                });
                if (!res.success) { this.groupMacroModal.error = (res.error && res.error.message) || 'Apply failed.'; return; }
                const refreshed = await FF_Api.get(FF_Api.url('/api/v1/users/permissions/index.php?user_id=<?= $targetId ?>'));
                if (refreshed.success && refreshed.data && refreshed.data.modules) this.modules = refreshed.data.modules;
                this.groupMacroModal.open = false;
                const applied = res.data.applied_count || 0, cleared = res.data.cleared_count || 0;
                FF_Toast.success('Bulk macro complete', this.groupMacroModal.macro === 'clear' || cleared > 0
                    ? `Cleared ${cleared} override${cleared===1?'':'s'}.` : `Applied ${applied} override${applied===1?'':'s'}.`);
                if (res.data.warnings && res.data.warnings.length > 0) res.data.warnings.forEach(w => FF_Toast.warning('Heads up', w));
            } catch (err) { this.groupMacroModal.error = 'Network error. Please try again.'; }
            finally { this.saving = false; }
        },
    };
}

function FF_RoleAssign() {
    return {
        currentRoleId: null,
        updatedAt: '',
        updating: false,
        roleModal: { open:false, roleId:null, roleName:'' },

        init(roleId, updatedAt) { this.currentRoleId = roleId; this.updatedAt = updatedAt; },

        changeRole(roleId, roleName) {
            if (roleId === this.currentRoleId) return;
            this.roleModal = { open:true, roleId, roleName };
        },

        async confirmChange() {
            this.updating = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/update.php'), {
                    id: <?= (int)$targetId ?>, role_id: this.roleModal.roleId, updated_at: this.updatedAt,
                });
                if (!res.success) { FF_Toast.error('Error', (res.error && res.error.message) || 'Role change failed.'); return; }
                FF_Toast.success('Role updated', `Changed to ${this.roleModal.roleName}. Reloading…`);
                setTimeout(() => window.location.reload(), 900);
            } catch (err) { FF_Toast.error('Error', 'Network error. Please try again.'); }
            finally { this.updating = false; this.roleModal.open = false; }
        },
    };
}

function FF_NotifPrefs() {
    return {
        optedOut: [], saving:false, saveError:null, saveOk:false,
        init(current) { this.optedOut = Array.isArray(current) ? current : []; },
        toggle(slug, isCurrentlyOptedOut) {
            if (isCurrentlyOptedOut) {
                this.optedOut = this.optedOut.filter(s => s !== slug);
            } else {
                if (!this.optedOut.includes(slug)) this.optedOut.push(slug);
            }
        },
        async save() {
            this.saving = true; this.saveError = null; this.saveOk = false;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/notification_preferences/update.php'), {
                    user_id: <?= (int)$targetId ?>, opted_out: this.optedOut,
                });
                if (res.success) { this.saveOk = true; setTimeout(() => { this.saveOk = false; }, 2500); }
                else this.saveError = (res.error && res.error.message) || 'Save failed.';
            } catch (_) { this.saveError = 'Network error. Please try again.'; }
            finally { this.saving = false; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
