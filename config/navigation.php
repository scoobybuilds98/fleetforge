<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Sidebar Navigation
//
// Single source of truth for the admin sidebar.
// Consumed by includes/sidebar.php.
//
// Each item:
//   label    — display text
//   icon     — Heroicons outline name (24px inline SVG)
//   url      — path relative to FF_BASE_PATH (e.g. '/dashboard')
//              sidebar.php prepends FF_BASE_PATH when building href
//   module   — permission module slug; null = visible to all logged-in users
//   badge    — badge key passed to sidebar_badge_count(); null = no badge
//
// Separator items:
//   separator => true
//   label     — section heading text
//   module    — if set, the section heading is only shown when the user
//               has view access to at least one item in the section
// ============================================================

return [

    // ----------------------------------------------------------
    // Main navigation
    // ----------------------------------------------------------
    [
        'label'  => 'Dashboard',
        'icon'   => 'home',
        'url'    => '/dashboard',
        'module' => null,
        'badge'  => null,
    ],
    [
        'label'  => 'Customers',
        'icon'   => 'user-group',
        'url'    => '/customers',
        'module' => 'customers',
        'badge'  => null,
    ],
    [
        'label'  => 'Equipment',
        'icon'   => 'truck',
        'url'    => '/equipment',
        'module' => 'equipment',
        'badge'  => null,
    ],
    [
        'label'  => 'Leases',
        'icon'   => 'document-text',
        'url'    => '/leases',
        'module' => 'leases',
        'badge'  => null,
    ],
    [
        'label'  => 'Reservations',
        'icon'   => 'calendar',
        'url'    => '/reservations',
        'module' => 'reservations',
        'badge'  => null,
    ],
    [
        'label'  => 'Invoices',
        'icon'   => 'banknotes',
        'url'    => '/invoices',
        'module' => 'invoices',
        'badge'  => 'overdue_invoices',
    ],
    [
        'label'  => 'Payments',
        'icon'   => 'credit-card',
        'url'    => '/payments',
        'module' => 'payments',
        'badge'  => null,
    ],
    [
        'label'  => 'Rates',
        'icon'   => 'currency-dollar',
        'url'    => '/rates',
        'module' => 'rates',
        'badge'  => null,
    ],
    [
        'label'  => 'Maintenance',
        'icon'   => 'wrench-screwdriver',
        'url'    => '/maintenance',
        'module' => 'maintenance',
        'badge'  => null,
    ],
    [
        'label'  => 'Compliance',
        'icon'   => 'shield-check',
        'url'    => '/compliance',
        'module' => 'compliance',
        'badge'  => 'compliance_alerts',
    ],
    [
        'label'  => 'Documents',
        'icon'   => 'folder-open',
        'url'    => '/documents',
        'module' => null,            // visible to all logged-in users
        'badge'  => null,
    ],
    [
        'label'  => 'Reports',
        'icon'   => 'chart-bar',
        'url'    => '/reports',
        'module' => 'reports',
        'badge'  => null,
    ],
    [
        'label'  => 'Analytics',
        'icon'   => 'chart-pie',
        'url'    => '/analytics',
        'module' => 'analytics',
        'badge'  => null,
    ],

    // ----------------------------------------------------------
    // Admin section — super_admin and manager only
    // ----------------------------------------------------------
    [
        'separator' => true,
        'label'     => 'Admin',
        'module'    => 'users',      // section shown if user can view 'users'
    ],
    [
        'label'  => 'Users',
        'icon'   => 'users',
        'url'    => '/users',
        'module' => 'users',
        'badge'  => null,
    ],
    [
        'label'  => 'Audit Log',
        'icon'   => 'clipboard-document-list',
        'url'    => '/audit',
        'module' => 'audit',
        'badge'  => null,
    ],
    [
        'label'  => 'Settings',
        'icon'   => 'cog-6-tooth',
        'url'    => '/settings',
        'module' => 'settings',
        'badge'  => null,
    ],

    // ----------------------------------------------------------
    // Accounting section — accountant role and above (Phase 13+)
    // Each item is gated by its own module permission.
    // ----------------------------------------------------------
    [
        'separator' => true,
        'label'     => 'Accounting',
        'module'    => 'journal_entries', // section shown if user can view any accounting module
    ],
    [
        'label'  => 'Accounting Dashboard',
        'icon'   => 'calculator',
        'url'    => '/accounting/dashboard',
        'module' => 'journal_entries',
        'badge'  => null,
    ],
    [
        'label'  => 'Chart of Accounts',
        'icon'   => 'list-bullet',
        'url'    => '/accounting/chart-of-accounts',
        'module' => 'chart_of_accounts',
        'badge'  => null,
    ],
    [
        'label'  => 'Journal Entries',
        'icon'   => 'pencil-square',
        'url'    => '/accounting/journal-entries',
        'module' => 'journal_entries',
        'badge'  => null,
    ],
    [
        'label'  => 'Accounts Payable',
        'icon'   => 'inbox-arrow-down',
        'url'    => '/accounting/accounts-payable',
        'module' => 'accounts_payable',
        'badge'  => null,
    ],
    [
        'label'  => 'Bank Accounts',
        'icon'   => 'building-library',
        'url'    => '/accounting/bank-accounts',
        'module' => 'bank_accounts',
        'badge'  => null,
    ],
    [
        'label'  => 'Fixed Assets',
        'icon'   => 'cube',
        'url'    => '/accounting/fixed-assets',
        'module' => 'fixed_assets',
        'badge'  => null,
    ],
    [
        'label'  => 'Tax',
        'icon'   => 'receipt-percent',
        'url'    => '/accounting/tax',
        'module' => 'tax_management',
        'badge'  => null,
    ],
    [
        'label'  => 'Financial Reports',
        'icon'   => 'document-chart-bar',
        'url'    => '/accounting/reports',
        'module' => 'financial_reports',
        'badge'  => null,
    ],
    [
        'label'  => 'Budget',
        'icon'   => 'banknotes',
        'url'    => '/accounting/budgets',
        'module' => 'budgets',
        'badge'  => null,
    ],
];
