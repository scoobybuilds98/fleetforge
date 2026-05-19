<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Permission Group Macros
//
// S-PERM-EXPAND — module groupings for bulk override operations.
//
// A "group macro" is syntactic sugar over the per-(user, module,
// action) override API: applying a macro loops the group's module
// list × module_actions and writes individual override rows via the
// same code path that update.php uses. Groups do not introduce a
// new override scope, table, or resolution layer — the runtime
// can() check is unchanged.
//
// Every module listed below must exist in config/permissions.php.
// We do not invent groups not backed by existing module names —
// accounts_receivable is intentionally absent because AR is served
// today by the 'invoices' and 'payments' modules, not a separate
// AR module (cross-checked against spec §1.3 + grep require_permission
// call sites).
//
// @decisions
//   D-PERM-EXPAND-1: Groups are static config data. The admin UI
//     fetches them via api/v1/users/permissions/index.php.
//   D-PERM-EXPAND-3: group_apply.php loops over modules × actions
//     and writes one override row + one audit_log row per cell. No
//     summary audit row.
// ============================================================

return [

    // ----------------------------------------------------------
    // accounting — 9 submodules per FLEETFORGE_ACCOUNTING_SPEC §1.3
    // ----------------------------------------------------------
    'accounting' => [
        'label'       => 'Accounting',
        'description' => 'All accounting submodules — Chart of Accounts, '
                       . 'Journal Entries, AP, Bank Accounts, Fixed Assets, '
                       . 'Tax, Financial Reports, Budgets, Periods.',
        'modules'     => [
            'chart_of_accounts',
            'journal_entries',
            'accounts_payable',
            'bank_accounts',
            'fixed_assets',
            'tax_management',
            'financial_reports',
            'budgets',
            'period_management',
        ],
    ],

    // ----------------------------------------------------------
    // quickbooks — reserved for Phase QBO. Single module.
    //
    // The grant_view and grant_read_write macros only grant
    // 'quickbooks.view'; extended actions (force_resync, clear_queue,
    // disconnect, etc.) are too sensitive for bulk operations and
    // must be granted per-action per-user in the matrix.
    // ----------------------------------------------------------
    'quickbooks' => [
        'label'       => 'QuickBooks Integration',
        'description' => 'QBO sync, drift monitoring, manual sync controls. '
                       . 'Extended actions must be granted individually.',
        'modules'     => ['quickbooks'],
    ],

    // ----------------------------------------------------------
    // fleet_ops — operational modules touching the fleet itself.
    // ----------------------------------------------------------
    'fleet_ops' => [
        'label'       => 'Fleet Operations',
        'description' => 'Equipment, leases, reservations, maintenance, '
                       . 'inspections, compliance, rates.',
        'modules'     => [
            'equipment',
            'leases',
            'reservations',
            'maintenance',
            'inspections',
            'compliance',
            'rates',
        ],
    ],
];
