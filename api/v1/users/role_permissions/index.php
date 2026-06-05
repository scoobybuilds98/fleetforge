<?php
declare(strict_types=1);

/**
 * api/v1/users/role_permissions/index.php
 *
 * S-ROLE-PERM-OVERRIDE — Full permission matrix for a role.
 *
 * Returns the config/permissions.php baseline for the role PLUS any
 * admin-applied overrides from role_permission_overrides.
 *
 * @method  GET
 * @query   role_id (required, positive int)
 * @auth    super_admin only
 * @returns 200 {
 *   role: { id, name, slug, description },
 *   modules: [
 *     { slug, label, actions: ['view','create',...],
 *       permissions: {
 *         view:   { config, override, effective },
 *         create: { config, override, effective },
 *         ...
 *       }
 *     }
 *   ]
 * }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage role permissions.', 403);
}

$roleId = clean_int($_GET['role_id'] ?? null);
if (!$roleId || $roleId <= 0) {
    json_validation_error(['role_id' => 'A valid role_id is required.']);
}

// ── Resolve role ────────────────────────────────────────────
$role = db_row(
    "SELECT id, name, slug, description FROM user_roles WHERE id = ?",
    [$roleId]
);
if (!$role) {
    json_error('NOT_FOUND', 'Role not found.', 404);
}

// ── Config baseline for this role ───────────────────────────
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$roleSlug          = (string) $role['slug'];
$configMatrix      = $permissionsConfig[$roleSlug] ?? [];

// ── Existing role-level overrides ───────────────────────────
$overrideRows = db_select(
    "SELECT module, action, granted, reason, updated_by, updated_at,
            (SELECT name FROM users WHERE id = rpo.updated_by) AS updated_by_name
     FROM role_permission_overrides rpo
     WHERE role_id = ?",
    [$roleId]
);
$overrideMap = [];
foreach ($overrideRows as $r) {
    $overrideMap[$r['module']][$r['action']] = [
        'granted'          => (int) $r['granted'],
        'reason'           => $r['reason'],
        'updated_by'       => (int) $r['updated_by'],
        'updated_by_name'  => $r['updated_by_name'],
        'updated_at'       => $r['updated_at'],
    ];
}

// ── Build module list (same helper as user permissions index) ─
$permActionsCfg     = _ff_load_permission_actions();
$actionDescriptions = $permActionsCfg['action_descriptions'] ?? [];

// Collect all modules across all roles
$allModules = [];
foreach ($permissionsConfig as $rPerms) {
    foreach (array_keys($rPerms) as $m) $allModules[$m] = true;
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

$modules = [];
foreach ($moduleSlugs as $slug) {
    $moduleActions = get_actions_for_module($slug);
    $row = [
        'slug'        => $slug,
        'label'       => $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug)),
        'actions'     => $moduleActions,
        'permissions' => [],
    ];
    foreach ($moduleActions as $action) {
        $configVal  = (bool) ($configMatrix[$slug][$action] ?? false);
        $ovr        = $overrideMap[$slug][$action] ?? null;
        $effective  = $ovr !== null ? (bool) $ovr['granted'] : $configVal;
        $row['permissions'][$action] = [
            'config'    => $configVal,
            'override'  => $ovr !== null ? (int) $ovr['granted'] : null,
            'reason'    => $ovr['reason'] ?? null,
            'updated_by_name' => $ovr['updated_by_name'] ?? null,
            'updated_at'      => $ovr['updated_at'] ?? null,
            'effective' => $effective,
        ];
    }
    $modules[] = $row;
}

json_success([
    'role'    => [
        'id'          => (int) $role['id'],
        'name'        => $role['name'],
        'slug'        => $role['slug'],
        'description' => $role['description'],
    ],
    'modules'              => $modules,
    'action_descriptions'  => $actionDescriptions,
    'labels'               => $labels,
]);
