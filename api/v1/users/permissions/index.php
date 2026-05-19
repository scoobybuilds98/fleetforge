<?php
declare(strict_types=1);

/**
 * api/v1/users/permissions/index.php
 *
 * PERM-1 — Per-user permission override matrix.
 *
 * Returns the full effective permission matrix for a single user:
 *   - For every (module, action) pair from config/permissions.php
 *   - Indicates the role baseline (true/false)
 *   - Indicates the per-user override (1 = allow, 0 = deny, null = none)
 *   - Indicates the effective resolved value (override beats role)
 *
 * The admin permissions page consumes this directly to render the matrix.
 *
 * @method  GET
 * @query   user_id (required, positive int)
 * @auth    super_admin only
 * @returns 200 {
 *            user: { id, name, email, role_slug, role_name },
 *            modules: [
 *              { slug, label, actions: {
 *                  view:   { role, override, effective },
 *                  create: { role, override, effective },
 *                  ...
 *              }}
 *            ],
 *            actions: ['view','create','edit','delete','export']
 *          }
 *
 * @decisions
 *   - super_admin TARGETS are listed but the matrix is shown read-only
 *     in the UI because can() short-circuits to true for them. The API
 *     also blocks override writes against a super_admin target.
 *   - All 23 modules from config/permissions.php are surfaced, even
 *     ones not seeded into the user_permissions table — overrides are
 *     stored independently of the seed table.
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// Only super_admin may view or edit per-user overrides.
if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage permission overrides.', 403);
}

// ── Resolve target user ─────────────────────────────────────
$userId = require_id($_GET['user_id'] ?? null);

$user = db_row(
    "SELECT u.id, u.name, u.email, u.role_id,
            ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);

if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// ── Load role baseline from config ──────────────────────────
// WHY: config/permissions.php is the live source-of-truth for what
// any role grants by default. We rebuild the matrix from it on every
// request so that adding a new module to the config immediately shows
// up in the override UI without a re-seed.
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$roleSlug          = (string) $user['role_slug'];
$roleMatrix        = $permissionsConfig[$roleSlug] ?? [];

// ── Load existing overrides for this user ───────────────────
$overrideRows = db_select(
    "SELECT module, action, granted, reason, granted_by, updated_at
     FROM user_permission_overrides
     WHERE user_id = ?",
    [$userId]
);

$overrideMap = [];
foreach ($overrideRows as $r) {
    $overrideMap[$r['module']][$r['action']] = (int) $r['granted'];
}

// ── Module list & labels ────────────────────────────────────
// WHY: derive the canonical module list from the union of all roles
// in the config so we never miss a module a particular role doesn't
// happen to define. Sort alphabetically for stable UI ordering.
$allModules = [];
foreach ($permissionsConfig as $rolePerms) {
    foreach (array_keys($rolePerms) as $modSlug) {
        $allModules[$modSlug] = true;
    }
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
    // S-PERM-CLEANUP (D-PERM-EXPAND-4 cosmetic item): 'inspections' was in
    // role-default matrix + 22+ require_permission() call sites but missing
    // from $labels — used to render via ucwords fallback as "Inspections".
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
    // S-PERM-CLEANUP: accounting_settings module added to config + this
    // labels map. Real key used by 5 require_permission() call sites in
    // api/v1/accounting/settings/*; was missing from config entirely
    // pre-cleanup (silent 403 for non-super_admin per D-PERM-EXPAND-4 drift).
    'accounting_settings' => 'Accounting Settings',
    // S-PERM-EXPAND: QBO module reserved for Phase QBO.
    'quickbooks'          => 'QuickBooks',
];

// ── Build the matrix ────────────────────────────────────────
// S-PERM-EXPAND: each module declares its own action vocabulary
// in config/permission_actions.php (default = 5-action CRUD).
// `actions` is now a per-module string list; `permissions` is the
// per-action state map keyed by action name.
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
        $roleVal = (bool) ($roleMatrix[$slug][$action] ?? false);
        $ovr     = $overrideMap[$slug][$action] ?? null; // null|0|1
        $eff     = $ovr === null ? $roleVal : (bool) $ovr;

        $row['permissions'][$action] = [
            'role'      => $roleVal,
            'override'  => $ovr,    // null = no override
            'effective' => $eff,
        ];
    }

    $modules[] = $row;
}

// ── Override audit metadata (for the "Override Summary" panel) ─
$overrideSummary = [];
foreach ($overrideRows as $r) {
    $overrideSummary[] = [
        'module'     => $r['module'],
        'action'     => $r['action'],
        'granted'    => (int) $r['granted'],
        'reason'     => $r['reason'],
        'granted_by' => (int) $r['granted_by'],
        'updated_at' => $r['updated_at'],
    ];
}

// S-PERM-EXPAND: include group definitions + action descriptions
// so the admin UI can render the bulk-macro card and tooltips
// without a second API round-trip.
$permActionsCfg = _ff_load_permission_actions();
$groupsRaw      = get_permission_groups();
$groups         = [];
foreach ($groupsRaw as $key => $g) {
    $groups[] = [
        'key'         => $key,
        'label'       => $g['label'],
        'description' => $g['description'],
        'modules'     => $g['modules'],
    ];
}

json_success([
    'user' => [
        'id'        => (int) $user['id'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'role_id'   => (int) $user['role_id'],
        'role_name' => $user['role_name'],
        'role_slug' => $user['role_slug'],
    ],
    'modules'             => $modules,
    'groups'              => $groups,
    'action_descriptions' => $permActionsCfg['action_descriptions'] ?? [],
    'overrides_count'     => count($overrideSummary),
    'overrides'           => $overrideSummary,
]);
