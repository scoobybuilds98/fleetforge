<?php
declare(strict_types=1);

/**
 * app/admin/users/role_permissions.php
 *
 * S-ROLE-PERM-OVERRIDE — Role-level permission editor.
 *
 * Lets a super_admin view and override the default permissions for each
 * system role (Manager, Dispatcher, Accountant, Read Only). Changes are
 * stored in role_permission_overrides and take effect for every user of
 * that role on their next authenticated request (no re-login needed).
 *
 * Resolution order after an override is applied:
 *   1. super_admin short-circuit (always true)
 *   2. Per-user overrides (user_permission_overrides)
 *   3. Role-level overrides (role_permission_overrides — THIS PAGE)
 *   4. Config factory defaults (config/permissions.php)
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           api/v1/users/role_permissions/{index,update,reset}.php
 * @session  S-ROLE-PERM-OVERRIDE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <div class="access-wall-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="access-wall-title">Developer Access Only</h2>
        <p class="access-wall-message">The Role Permissions module is restricted to the Developer account.</p>
        <a href="<?= base_url('dashboard') ?>" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// ── Load all non-super_admin roles ───────────────────────────
$allRoles = db_select(
    "SELECT id, name, slug, description FROM user_roles WHERE is_system = 1 AND slug != 'super_admin' ORDER BY id",
    []
);

if (empty($allRoles)) {
    header('Location: ' . base_url('users'));
    exit;
}

// Default selected role
$selectedRoleSlug = clean_string($_GET['role'] ?? null, 50) ?: $allRoles[0]['slug'];
$selectedRole     = null;
foreach ($allRoles as $r) {
    if ($r['slug'] === $selectedRoleSlug) { $selectedRole = $r; break; }
}
if (!$selectedRole) $selectedRole = $allRoles[0];

// ── Build permission matrix for selected role ────────────────
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$permActionsCfg    = _ff_load_permission_actions();
$actionDescriptions = $permActionsCfg['action_descriptions'] ?? [];
$groupsRaw         = get_permission_groups();
$groups            = [];
foreach ($groupsRaw as $key => $g) {
    $groups[] = ['key' => $key, 'label' => $g['label'], 'description' => $g['description'], 'modules' => $g['modules']];
}

$roleSlug    = (string) $selectedRole['slug'];
$configMatrix = $permissionsConfig[$roleSlug] ?? [];

$overrideRows = db_select(
    "SELECT rpo.module, rpo.action, rpo.granted, rpo.reason, rpo.updated_at,
            (SELECT name FROM users WHERE id = rpo.updated_by) AS updated_by_name
     FROM role_permission_overrides rpo
     WHERE rpo.role_id = ?
     ORDER BY rpo.module, rpo.action",
    [(int) $selectedRole['id']]
);
$overrideMap = [];
foreach ($overrideRows as $r) {
    $overrideMap[$r['module']][$r['action']] = [
        'granted'         => (int) $r['granted'],
        'reason'          => $r['reason'],
        'updated_by_name' => $r['updated_by_name'],
        'updated_at'      => $r['updated_at'],
    ];
}

$labels = [
    'customers'           => 'Customers',   'equipment'           => 'Equipment',
    'leases'              => 'Leases',       'reservations'        => 'Reservations',
    'invoices'            => 'Invoices',     'payments'            => 'Payments',
    'rates'               => 'Rates',        'maintenance'         => 'Maintenance',
    'inspections'         => 'Inspections',  'compliance'          => 'Compliance',
    'reports'             => 'Reports',      'analytics'           => 'Analytics',
    'users'               => 'Users',        'settings'            => 'Settings',
    'audit'               => 'Audit Log',    'ai'                  => 'AI Tools',
    'chart_of_accounts'   => 'Chart of Accounts',
    'journal_entries'     => 'Journal Entries',
    'accounts_payable'    => 'Accounts Payable',
    'bank_accounts'       => 'Bank Accounts',
    'fixed_assets'        => 'Fixed Assets',
    'tax_management'      => 'Tax Management',
    'financial_reports'   => 'Financial Reports',
    'budgets'             => 'Budgets',
    'period_management'   => 'Period Management',
    'accounting_settings' => 'Accounting Settings',
    'quickbooks'          => 'QuickBooks',
    'settings_general'    => 'General Settings',
    'settings_design'     => 'Design Settings',
    'settings_users'      => 'User Settings',
    'settings_portal'     => 'Portal Settings',
    'settings_audit'      => 'Audit Settings',
    'settings_system'     => 'System Settings',
    'settings_integrations' => 'Integration Settings',
    'settings_intelligence' => 'AI/Intelligence Settings',
];

// Build matrix
$allModules = [];
foreach ($permissionsConfig as $rPerms) {
    foreach (array_keys($rPerms) as $m) $allModules[$m] = true;
}
$moduleSlugs = array_keys($allModules);
sort($moduleSlugs);

$matrix = [];
foreach ($moduleSlugs as $slug) {
    $moduleActions = get_actions_for_module($slug);
    $row = ['slug' => $slug, 'label' => $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug)), 'actions' => $moduleActions, 'permissions' => []];
    foreach ($moduleActions as $action) {
        $cfgVal  = (bool) ($configMatrix[$slug][$action] ?? false);
        $ovr     = $overrideMap[$slug][$action] ?? null;
        $row['permissions'][$action] = [
            'config'          => $cfgVal,
            'override'        => $ovr !== null ? (int) $ovr['granted'] : null,
            'reason'          => $ovr['reason'] ?? null,
            'updated_by_name' => $ovr['updated_by_name'] ?? null,
            'updated_at'      => $ovr['updated_at'] ?? null,
            'effective'       => $ovr !== null ? (bool) $ovr['granted'] : $cfgVal,
        ];
    }
    $matrix[] = $row;
}
$matrixBySlug = [];
foreach ($matrix as $row) { $matrixBySlug[$row['slug']] = $row; }

// Module display groups (same as permissions.php)
$moduleGroupDefs = [
    'Fleet Operations'         => ['color' => '#22c55e', 'bulk_group' => 'fleet_ops',  'modules' => ['customers','equipment','leases','reservations','rates']],
    'Maintenance & Compliance' => ['color' => '#f59e0b', 'bulk_group' => null,         'modules' => ['maintenance','inspections','compliance']],
    'Financial'                => ['color' => '#3b82f6', 'bulk_group' => 'accounting', 'modules' => ['invoices','payments','chart_of_accounts','journal_entries','accounts_payable','bank_accounts','fixed_assets','tax_management','financial_reports','budgets','period_management','accounting_settings']],
    'Analytics & Reports'      => ['color' => '#8b5cf6', 'bulk_group' => null,         'modules' => ['reports','analytics','audit']],
    'System'                   => ['color' => '#64748b', 'bulk_group' => null,         'modules' => ['ai','users','settings','settings_general','settings_design','settings_users','settings_portal','settings_audit','settings_system','settings_integrations','settings_intelligence']],
    'Integrations'             => ['color' => '#14b8a6', 'bulk_group' => 'quickbooks', 'modules' => ['quickbooks']],
];
$groupByKey = [];
foreach ($groups as $g) { $groupByKey[$g['key']] = $g; }

// Count users per role for role tabs
$userCounts = [];
foreach ($allRoles as $r) {
    $cnt = db_count("SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL", [(int) $r['id']]);
    $userCounts[$r['slug']] = $cnt;
}

$pageTitle = 'Role Permissions';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ════════════════════════════════════════════════════════════
     Role Permissions Page — S-ROLE-PERM-OVERRIDE
     ════════════════════════════════════════════════════════════ -->

<!-- Page header -->
<div class="perm-page-header">
    <a href="<?= base_url('users') ?>" class="btn btn-secondary btn-sm perm-back-btn">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>Back
    </a>
    <div class="perm-user-identity">
        <div class="perm-user-avatar" style="background:rgba(139,92,246,0.15);color:#a78bfa;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:22px;height:22px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
        </div>
        <div class="perm-user-info">
            <h1 class="perm-user-name">Role Permissions</h1>
            <div class="perm-user-meta">
                <span class="text-secondary" style="font-size:0.8125rem;">Edit default permissions per role — affects all users with that role</span>
            </div>
        </div>
    </div>
</div>

<!-- Role selector tabs -->
<div class="rp-role-tabs card" style="margin-bottom:18px;">
    <div class="rp-role-tabs-inner">
        <?php
        $roleIconMap = [
            'manager'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z"/></svg>',
            'dispatcher' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
            'accountant' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>',
            'read_only'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        ];
        $roleColorMap = [
            'manager'    => '#60a5fa',
            'dispatcher' => '#4ade80',
            'accountant' => '#a78bfa',
            'read_only'  => '#94a3b8',
        ];
        foreach ($allRoles as $r):
            $isActive = ($r['slug'] === $selectedRole['slug']);
            $color    = $roleColorMap[$r['slug']] ?? '#94a3b8';
            $icon     = $roleIconMap[$r['slug']] ?? '';
            $count    = $userCounts[$r['slug']] ?? 0;
        ?>
        <a href="<?= base_url('users/role_permissions') ?>?role=<?= e($r['slug']) ?>"
           class="rp-role-tab <?= $isActive ? 'is-active' : '' ?>">
            <span class="rp-role-tab-icon" style="color:<?= e($color) ?>"><?= $icon ?></span>
            <span class="rp-role-tab-name"><?= e($r['name']) ?></span>
            <span class="rp-role-tab-count"><?= $count ?> user<?= $count !== 1 ? 's' : '' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Main Alpine component -->
<div x-data="rolePermissionsMatrix()" x-init="init()">

<div class="rp-info-banner">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;flex-shrink:0;color:#60a5fa;"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
    <span>Changes to <strong><?= e($selectedRole['name']) ?></strong> role affect
    <strong><?= $userCounts[$selectedRole['slug']] ?? 0 ?></strong>
    user<?= ($userCounts[$selectedRole['slug']] ?? 0) !== 1 ? 's' : '' ?> immediately on their next page load.
    Per-user overrides take precedence over these role defaults.</span>
</div>

<div class="perm-layout">

<!-- ── Left: Permission matrix ──────────────────────────────── -->
<div class="card perm-matrix-card">
    <div class="perm-matrix-card-header">
        <div>
            <div class="perm-apple-card-title" style="font-size:1rem;"><?= e($selectedRole['name']) ?> — Permission Matrix</div>
            <div class="perm-apple-card-subtitle">
                Green = config grants. Orange ring = role override allow. Red = role override deny.
            </div>
        </div>
        <div class="perm-matrix-header-actions">
            <span class="perm-override-pill" x-show="overrideCount > 0">
                <span x-text="overrideCount"></span>&nbsp;override<span x-show="overrideCount !== 1">s</span>
            </span>
            <button class="btn btn-secondary btn-sm" @click="confirmReset()" :disabled="overrideCount === 0 || saving">Reset all</button>
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
            <?php if ($bulkGroup): ?>
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
                <span class="rp-config-badge" title="Config default">default</span>
            </div>
            <div class="perm-matrix-row-cells">
                <?php foreach ($row['actions'] as $action): ?>
                <?php
                    $cell       = $row['permissions'][$action];
                    $cfgLabel   = $cell['config'] ? 'on' : 'off';
                    $ovrInfo    = $cell['override'] !== null
                        ? ('Override by ' . e($cell['updated_by_name'] ?? 'admin') . ' — ' . e($cell['reason'] ?? ''))
                        : ('Config default: ' . ($cell['config'] ? 'ALLOW' : 'DENY'));
                ?>
                <div class="perm-matrix-cell-col">
                    <div class="perm-matrix-cell-header" title="<?= e($actionDescriptions[$action] ?? '') ?>"><?= e($action) ?></div>
                    <div class="perm-ios-toggle"
                         :class="toggleClass('<?= e($slug) ?>', '<?= e($action) ?>')"
                         @click="cycleCell('<?= e($slug) ?>', '<?= e($action) ?>')"
                         title="<?= $ovrInfo ?>">
                    </div>
                    <!-- Small config indicator below toggle -->
                    <div class="rp-config-dot rp-config-dot--<?= $cfgLabel ?>" title="Config default: <?= $cfgLabel ?>"></div>
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

    <!-- Role info card -->
    <div class="card perm-sidebar-card">
        <div class="perm-apple-card-header perm-apple-card-header--sm">
            <div class="perm-apple-card-icon perm-apple-card-icon--sm" style="background:rgba(139,92,246,0.14);color:#a78bfa;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            </div>
            <div class="perm-apple-card-title perm-apple-card-title--sm"><?= e($selectedRole['name']) ?></div>
        </div>
        <div style="padding:12px 16px 14px;">
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 10px;line-height:1.5;"><?= e($selectedRole['description']) ?></p>
            <div class="rp-legend-grid">
                <div class="rp-legend-item">
                    <div class="perm-ios-toggle perm-ios-toggle--sm is-on" style="pointer-events:none;"></div>
                    <span>Config grants</span>
                </div>
                <div class="rp-legend-item">
                    <div class="perm-ios-toggle perm-ios-toggle--sm is-off" style="pointer-events:none;"></div>
                    <span>Config denies</span>
                </div>
                <div class="rp-legend-item">
                    <div class="perm-ios-toggle perm-ios-toggle--sm ovr-allow" style="pointer-events:none;"></div>
                    <span>Override: allow</span>
                </div>
                <div class="rp-legend-item">
                    <div class="perm-ios-toggle perm-ios-toggle--sm ovr-deny" style="pointer-events:none;"></div>
                    <span>Override: deny</span>
                </div>
            </div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-color);">
                <div class="rp-legend-item" style="margin-bottom:4px;">
                    <div class="rp-config-dot rp-config-dot--on"></div>
                    <span style="font-size:0.75rem;">Small dot = config default (below toggle)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Role Overrides -->
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
                <span>Using config defaults for all modules</span>
            </div>
            <ul x-show="overrideList.length > 0" class="perm-override-list">
                <template x-for="ovr in overrideList" :key="ovr.module + ':' + ovr.action">
                    <li class="perm-override-item">
                        <div class="perm-override-item-head">
                            <span class="perm-override-badge"
                                  :class="ovr.override === 1 ? 'perm-override-badge--allow' : 'perm-override-badge--deny'"
                                  x-text="ovr.override === 1 ? 'Allow' : 'Deny'"></span>
                            <span class="perm-override-target">
                                <span x-text="ovr.label"></span><span class="perm-override-action" x-text="' · ' + ovr.action"></span>
                            </span>
                            <button class="perm-override-clear-btn" @click="sendUpdate(ovr.module, ovr.action, null, null)" :disabled="saving" title="Clear">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:10px;height:10px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div x-show="ovr.reason" class="perm-override-reason" x-text="ovr.reason"></div>
                        <div x-show="ovr.updated_by_name" class="perm-override-reason" x-text="'by ' + ovr.updated_by_name + (ovr.updated_at ? ' · ' + ovr.updated_at.slice(0,10) : '')"></div>
                    </li>
                </template>
            </ul>
        </div>
    </div>

</div><!-- /sidebar -->
</div><!-- /perm-layout -->

<!-- Reason modal -->
<div x-show="reasonModal.open" x-cloak class="modal-overlay"
     @click.self="cancelReason()" @keydown.escape.window="reasonModal.open && cancelReason()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title" x-text="reasonModal.intent === 1 ? 'Grant to role' : 'Deny for role'"></h3>
            <button class="modal-close-btn" @click="cancelReason()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 6px;font-size:0.875rem;color:var(--text-secondary);">
                <span x-text="reasonModal.intent === 1 ? 'Grant' : 'Deny'"></span>
                <strong x-text="reasonModal.label"></strong> · <strong x-text="reasonModal.action"></strong>
                for <strong>all <?= e($selectedRole['name']) ?> users</strong>.
            </p>
            <p style="margin:0 0 14px;font-size:0.8125rem;color:var(--color-warning,#eab308);">
                ⚠ This affects all <?= (int)($userCounts[$selectedRole['slug']] ?? 0) ?> user<?= ($userCounts[$selectedRole['slug']] ?? 0) !== 1 ? 's' : '' ?> with this role.
            </p>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3" x-model="reasonModal.reason" maxlength="1000"
                          placeholder="Why is this role-level override needed?"></textarea>
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
            <p style="margin:0 0 6px;font-size:0.875rem;color:var(--text-secondary);" x-text="groupMacroModal.intro"></p>
            <p style="margin:0 0 14px;font-size:0.8125rem;color:var(--color-warning,#eab308);">
                ⚠ Affects all <?= (int)($userCounts[$selectedRole['slug']] ?? 0) ?> <?= e($selectedRole['name']) ?> users.
            </p>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3" x-model="groupMacroModal.reason" maxlength="1000"
                          placeholder="Why is this bulk role change needed?"></textarea>
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

<!-- Reset modal -->
<div x-show="resetModalOpen" x-cloak class="modal-overlay"
     @click.self="resetModalOpen = false" @keydown.escape.window="resetModalOpen && (resetModalOpen = false)"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">Reset all role overrides?</h3>
            <button class="modal-close-btn" @click="resetModalOpen = false">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 8px;font-size:0.875rem;">
                Remove all <strong x-text="overrideCount"></strong> override<span x-show="overrideCount !== 1">s</span>
                for <strong><?= e($selectedRole['name']) ?></strong>. The role reverts to config/permissions.php defaults.
            </p>
            <p style="margin:0;font-size:0.8125rem;color:var(--color-warning,#eab308);">
                ⚠ Affects all <?= (int)($userCounts[$selectedRole['slug']] ?? 0) ?> users with this role.
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

</div><!-- /x-data -->

<style>
/* ══ Role Permissions page extras ══════════════════════════════ */
.rp-role-tabs { border-radius:16px; overflow:hidden; }
.rp-role-tabs-inner {
    display: flex;
    overflow-x: auto;
    padding: 12px 16px;
    gap: 8px;
    scrollbar-width: none;
}
.rp-role-tabs-inner::-webkit-scrollbar { display: none; }

.rp-role-tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 12px 20px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,.06);
    background: var(--bg-surface-2);
    text-decoration: none;
    color: var(--text-secondary);
    min-width: 110px;
    transition: all 160ms ease;
    flex-shrink: 0;
}
.rp-role-tab:hover:not(.is-active) {
    border-color: rgba(255,255,255,.13);
    color: var(--text-primary);
    background: var(--bg-surface-hover);
}
.rp-role-tab.is-active {
    border-color: var(--color-primary);
    background: rgba(249,115,22,.07);
    color: var(--text-primary);
    box-shadow: 0 0 0 1px var(--color-primary);
}
[data-theme="light"] .rp-role-tab { background: #f8fafc; border-color: rgba(0,0,0,.07); }
[data-theme="light"] .rp-role-tab.is-active { background: rgba(234,111,0,.05); }
.rp-role-tab-icon { width:22px; height:22px; display:flex; align-items:center; justify-content:center; }
.rp-role-tab-icon svg { width:20px; height:20px; }
.rp-role-tab-name { font-weight:600; font-size:0.875rem; white-space:nowrap; }
.rp-role-tab-count { font-size:0.6875rem; color:var(--text-tertiary); }

.rp-info-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.20);
    border-radius: 10px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    margin-bottom: 16px;
    line-height: 1.5;
}

.rp-config-badge {
    display: inline-block;
    font-size: 0.5625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-tertiary);
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    padding: 1px 5px;
    border-radius: 4px;
    margin-top: 2px;
}

/* Small dot below toggle showing config default */
.rp-config-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    margin-top: 1px;
}
.rp-config-dot--on  { background: rgba(34,197,94,.55); }
.rp-config-dot--off { background: rgba(100,116,139,.4); }

/* Legend grid */
.rp-legend-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.rp-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: var(--text-secondary);
}
</style>

<script>
function rolePermissionsMatrix() {
    return {
        modules:            <?= json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        groups:             <?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        labels:             <?= json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        actionDescriptions: <?= json_encode($actionDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        roleId:             <?= (int) $selectedRole['id'] ?>,
        saving: false,
        reasonModal: { open:false, module:null, action:null, label:'', intent:null, reason:'', error:null },
        groupMacroModal: { open:false, group:null, groupLabel:'', macro:null, macroLabel:'', submitLabel:'Apply', intro:'', reason:'', error:null },
        resetModalOpen: false,

        init() { /* matrix rendered server-side; tabs switch via page reload */ },

        toggleClass(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            if (cell.override === 1) return 'ovr-allow';
            if (cell.override === 0) return 'ovr-deny';
            return cell.config ? 'is-on' : 'is-off';
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
                        out.push({ module:row.slug, label:row.label, action:a, override:cell.override,
                            reason:cell.reason||'', updated_by_name:cell.updated_by_name||'', updated_at:cell.updated_at||'' });
                }
            return out;
        },

        cellTooltip(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            const cfgTxt = cell.config ? 'grants' : 'denies';
            const desc   = this.actionDescriptions[action] || '';
            const head   = cell.override === 1 ? `Role override: ALLOW (config ${cfgTxt})`
                         : cell.override === 0 ? `Role override: DENY (config ${cfgTxt})`
                         : `Config ${cfgTxt} by default`;
            return desc ? `${head}\n${desc}` : head;
        },

        _cell(moduleSlug, action) {
            return this.modules.find(r => r.slug === moduleSlug).permissions[action];
        },

        cycleCell(moduleSlug, action) {
            if (this.saving) return;
            const cell = this._cell(moduleSlug, action);
            const row  = this.modules.find(r => r.slug === moduleSlug);
            let nextIntent = cell.override === null ? 1 : cell.override === 1 ? 0 : null;
            if (nextIntent === null) { this.sendUpdate(moduleSlug, action, null, null); return; }
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
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/update.php'), {
                    role_id: this.roleId, module: moduleSlug, action: action, granted: granted, reason: reason,
                });
                if (!res.success) {
                    const msg = (res.error && res.error.message) || 'Save failed.';
                    this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
                    return;
                }
                const row  = this.modules.find(r => r.slug === moduleSlug);
                const cell = row.permissions[action];
                cell.override = granted;
                cell.reason   = reason;
                cell.effective = granted === null ? cell.config : Boolean(granted);
                this.reasonModal.open = false;
                FF_Toast.success('Saved', granted === null ? 'Override cleared.' : (granted === 1 ? 'Permission granted for role.' : 'Permission denied for role.'));
            } catch (err) {
                const msg = 'Network error. Please try again.';
                this.reasonModal.open ? (this.reasonModal.error = msg) : FF_Toast.error('Error', msg);
            } finally { this.saving = false; }
        },

        confirmReset() { if (this.overrideCount === 0) return; this.resetModalOpen = true; },
        async resetAll() {
            this.saving = true;
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/reset.php'), { role_id: this.roleId });
                if (!res.success) { FF_Toast.error('Error', (res.error && res.error.message) || 'Reset failed.'); return; }
                for (const row of this.modules)
                    for (const a of row.actions) {
                        row.permissions[a].override  = null;
                        row.permissions[a].reason    = null;
                        row.permissions[a].effective = row.permissions[a].config;
                    }
                this.resetModalOpen = false;
                FF_Toast.success('Reset', `Cleared ${res.data.cleared_count} override${res.data.cleared_count === 1 ? '' : 's'}.`);
            } catch (err) { FF_Toast.error('Error', 'Network error. Please try again.'); }
            finally { this.saving = false; }
        },

        _macroLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear Overrides'}[macro]||macro; },
        _macroSubmitLabel(macro) { return {grant_view:'Grant View',grant_read_write:'Grant Read+Write',deny_all:'Deny All',clear:'Clear'}[macro]||'Apply'; },
        _macroIntro(group, macro) {
            const role = '<?= addslashes(e($selectedRole['name'])) ?>';
            const n = group.modules.length, noun = n===1?'module':'modules';
            switch(macro) {
                case 'grant_view':       return `Grant view on ${n} ${noun} in "${group.label}" for all ${role} users.`;
                case 'grant_read_write': return `Grant view+create+edit on ${n} ${noun} in "${group.label}" for all ${role} users.`;
                case 'deny_all':         return `Deny all actions on every module in "${group.label}" for all ${role} users.`;
                case 'clear':            return `Clear all overrides on ${n} ${noun} in "${group.label}" — reverts to config defaults.`;
                default: return '';
            }
        },
        openGroupMacro(group, macro) {
            if (this.saving) return;
            this.groupMacroModal = { open:true, group:group.key, groupLabel:group.label, macro:macro,
                macroLabel:this._macroLabel(macro), submitLabel:this._macroSubmitLabel(macro),
                intro:this._macroIntro(group, macro), reason:'', error:null };
        },
        cancelGroupMacro() { this.groupMacroModal.open = false; },
        async confirmGroupMacro() {
            const reason = (this.groupMacroModal.reason || '').trim();
            if (!reason) { this.groupMacroModal.error = 'A reason is required.'; return; }
            this.saving = true;
            try {
                // Apply macro by iterating modules in the group
                const group = this.groups.find(g => g.key === this.groupMacroModal.group);
                if (!group) { this.groupMacroModal.error = 'Group not found.'; return; }

                const macro   = this.groupMacroModal.macro;
                const updates = [];
                for (const moduleName of group.modules) {
                    const row = this.modules.find(r => r.slug === moduleName);
                    if (!row) continue;
                    const actions = macro === 'grant_view'       ? ['view']
                                  : macro === 'grant_read_write' ? ['view','create','edit']
                                  : macro === 'deny_all'         ? row.actions
                                  : /* clear */                    row.actions;
                    const granted = macro === 'clear' ? null : (macro === 'deny_all' ? 0 : 1);
                    for (const a of actions) {
                        updates.push({ module: moduleName, action: a, granted, reason });
                    }
                }

                let applied = 0, cleared = 0;
                for (const u of updates) {
                    const res = await FF_Api.post(FF_Api.url('/api/v1/users/role_permissions/update.php'), {
                        role_id: this.roleId, module: u.module, action: u.action, granted: u.granted, reason: u.reason,
                    });
                    if (res.success) {
                        const row  = this.modules.find(r => r.slug === u.module);
                        const cell = row.permissions[u.action];
                        cell.override  = u.granted;
                        cell.reason    = reason;
                        cell.effective = u.granted === null ? cell.config : Boolean(u.granted);
                        if (u.granted === null) cleared++; else applied++;
                    }
                }

                this.groupMacroModal.open = false;
                FF_Toast.success('Bulk complete', macro === 'clear' ? `Cleared ${cleared} override${cleared===1?'':'s'}.` : `Applied ${applied} override${applied===1?'':'s'}.`);
            } catch (err) { this.groupMacroModal.error = 'Network error. Please try again.'; }
            finally { this.saving = false; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
