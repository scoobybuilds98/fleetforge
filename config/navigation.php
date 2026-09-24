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
//   accent   — optional primary|info|success|warning|danger|purple; tints the
//              item's icon on hover/active (S-SHELL-REDESIGN). Default primary.
//
// Separator items:
//   separator => true
//   label     — section heading text
//   module    — if set, the section heading is only shown when the user
//               has view access to at least one item in the section
// ============================================================

return [

    // ----------------------------------------------------------
    // Main navigation — grouped into labelled sections (S-SHELL-REDESIGN).
    // WHY sections: 25 flat items were hard to scan; each group matches
    // how the office thinks about the work. 'accent' colours the item's
    // icon on hover/active with the same colour as that module's hero.
    // ----------------------------------------------------------
    [
        'label'  => 'Dashboard',
        'icon'   => 'home',
        'accent' => 'primary',
        'url'    => '/dashboard',
        'module' => null,
        'badge'  => null,
    ],
    [
        'separator' => true,
        'label'     => 'Rentals',
    ],
    [
        'label'  => 'Customers',
        'icon'   => 'user-group',
        'accent' => 'purple',
        'url'    => '/customers',
        'module' => 'customers',
        'badge'  => null,
    ],
    [
        'label'  => 'Leases',
        'icon'   => 'document-text',
        'accent' => 'warning',
        'url'    => '/leases',
        'module' => 'leases',
        'badge'  => null,
    ],
    [
        'label'  => 'Reservations',
        'icon'   => 'calendar',
        'accent' => 'info',
        'url'    => '/reservations',
        'module' => 'reservations',
        'badge'  => null,
    ],
    [
        'label'        => 'Credit Applications',
        'icon'         => 'clipboard-document-check',
        'accent'       => 'info',
        'url'          => '/credit_applications',
        'match_prefix' => '/credit_applications',
        'module'       => 'customers', // sub-resource of customers (D-CCA-PERM)
        'badge'        => 'pending_credit_apps',
    ],
    [
        'label'  => 'Service Requests',
        'icon'   => 'envelope-open',
        'accent' => 'primary',
        'url'    => '/requests',
        'module' => 'customers',
        'badge'  => null,
    ],
    [
        'separator' => true,
        'label'     => 'Billing',
    ],
    [
        // S-BILLING-MODULE: the monthly billing cycle — readiness, readings,
        // the workbench (was Batch Invoicing under Invoices), review,
        // approvals, delivery, close. First in the section: it is where a
        // month's billing starts. '/billing' prefix-matches every billing
        // page (cycle, run, approval, settings), so they all highlight it.
        // Badge = open billing exceptions + runs waiting for approval.
        'label'        => 'Monthly Billing',
        'icon'         => 'calendar-days',
        'accent'       => 'primary',
        'url'          => '/billing',
        'match_prefix' => '/billing',
        'module'       => 'invoices',
        'badge'        => 'billing_attention',
    ],
    [
        // Invoices was a collapsible GROUP (S-BATCH-INVOICING). Its "Batch
        // Invoicing" child moved to Monthly Billing (S-BILLING-MODULE) —
        // /invoices/batch redirects to /billing/run — and Credit Notes became
        // its own Billing item below Rates (S-NAV-CREDIT-NOTES), so Invoices
        // is a plain link again.
        'label'        => 'Invoices',
        'icon'         => 'banknotes',
        'accent'       => 'primary',
        'url'          => '/invoices',
        'match_prefix' => '/invoices',
        'module'       => 'invoices',
        'badge'        => 'overdue_invoices',
    ],
    [
        'label'  => 'Payments',
        'icon'   => 'credit-card',
        'accent' => 'success',
        'url'    => '/payments',
        'module' => 'payments',
        'badge'  => null,
    ],
    [
        'label'  => 'Rates',
        'icon'   => 'currency-dollar',
        'accent' => 'primary',
        'url'    => '/rates',
        'module' => 'rates',
        'badge'  => null,
    ],
    [
        // S-NAV-CREDIT-NOTES (operator: "bring credit notes from under
        // invoices to under the billing section, below rates … its own
        // item"). Was a child of the Invoices group. Credit notes stay in the
        // invoices permission scope (§12 matrix — every credit_notes page/API
        // gates on invoices:*). '/credit_notes' shares no prefix with any
        // other item, so highlighting stays exact.
        'label'  => 'Credit Notes',
        'icon'   => 'receipt-percent',
        'accent' => 'primary',
        'url'    => '/credit_notes',
        'module' => 'invoices',
        'badge'  => null,
    ],
    [
        'separator' => true,
        'label'     => 'Fleet',
    ],
    [
        'label'  => 'Equipment',
        'icon'   => 'truck',
        'accent' => 'success',
        'url'    => '/equipment',
        'module' => 'equipment',
        'badge'  => null,
    ],
    [
        'label'  => 'Samsara Tracking',
        'icon'   => 'map',
        'accent' => 'info',
        'url'    => '/tracking',
        'module' => 'equipment',  // WHY: same permission as equipment — anyone who can view equipment can track
        'badge'  => null,
    ],
    [
        'label'  => 'Yards',
        'icon'   => 'map-pin',
        'accent' => 'success',
        'url'    => '/yards',
        'module' => 'reservations',
        'badge'  => null,
    ],
    [
        'label'  => 'Compliance',
        'icon'   => 'shield-check',
        'accent' => 'warning',
        'url'    => '/compliance',
        'module' => 'compliance',
        'badge'  => 'compliance_alerts',
    ],
    [
        'separator' => true,
        'label'     => 'Fleet care',
    ],
    [
        'label'  => 'Maintenance',
        'icon'   => 'wrench-screwdriver',
        'accent' => 'warning',
        'url'    => '/maintenance_work_orders',
        'module' => 'maintenance',
        'badge'  => null,
    ],
    [
        'label'  => 'Inspections',
        'icon'   => 'clipboard-document-check',
        'accent' => 'info',
        'url'    => '/inspections',
        'module' => 'inspections',
        'badge'  => null,
    ],
    [
        'label'  => 'Damage Claims',
        'icon'   => 'exclamation-triangle',
        'accent' => 'danger',
        'url'    => '/damage_claims',
        'module' => 'maintenance',    // same permission group as maintenance (§12 matrix)
        'badge'  => 'open_damage_claims',
    ],
    [
        'label'  => 'Mileage Logs',
        'icon'   => 'chart-bar-square',
        'accent' => 'info',
        'url'    => '/mileage_logs',
        'module' => 'maintenance',    // same permission group as maintenance (§12 matrix)
        'badge'  => null,
    ],
    [
        'label'  => 'Vendors',
        'icon'   => 'building-storefront',
        'accent' => 'warning',
        'url'    => '/vendors',
        'module' => 'maintenance',    // same permission group as maintenance (§12 matrix)
        'badge'  => null,
    ],
    [
        'separator' => true,
        'label'     => 'Insights',
    ],
    [
        'label'  => 'Reports',
        'icon'   => 'chart-bar',
        'accent' => 'purple',
        'url'    => '/reports',
        'module' => 'reports',
        'badge'  => null,
    ],
    [
        'label'  => 'Analytics',
        'icon'   => 'chart-pie',
        'accent' => 'info',
        'url'    => '/analytics',
        'module' => 'analytics',
        'badge'  => null,
    ],
    [
        'label'  => 'AI Assistant',
        'icon'   => 'sparkles',
        'accent' => 'purple',
        'url'    => '/ai',
        'module' => 'ai',
        'badge'  => null,
    ],
    [
        'label'  => 'Documents',
        'icon'   => 'folder-open',
        'accent' => 'primary',
        'url'    => '/documents',
        'module' => null,            // visible to all logged-in users
        'badge'  => null,
    ],
    [
        'separator' => true,
        'label'     => 'Learn',
    ],
    [
        'label'  => 'SOP',
        'icon'   => 'clipboard-document-check',
        'accent' => 'primary',
        'url'    => '/sop',
        'module' => null,   // S-SOP-MODULE: every staff user; the live month-end checklist is gated in-page (can view payments)
        'badge'  => null,
    ],
    [
        'label'  => 'Training',
        'icon'   => 'academic-cap',
        'accent' => 'success',
        'url'    => '/training',
        'module' => null,   // S-TRAINING-MODULE: every staff user; team report gated in-page to super_admin
        'badge'  => null,
    ],
    [
        'label'  => 'Help Center',
        'icon'   => 'book-open',
        'accent' => 'info',
        'url'    => '/help',
        'module' => null,   // visible to all authenticated users
        'badge'  => null,
    ],

    // ----------------------------------------------------------
    // QuickBooks section — accountant role + super_admin (Phase QBO)
    //
    // STRUCTURE: SEPARATE top-level nav parent placed ABOVE the
    // Accounting group, NOT nested inside it. QBO and the FF
    // accounting module run permanently in parallel (D-QBO-CORE-3);
    // grouping QBO under Accounting would imply the wrong mental
    // model (it is not a sub-feature of FF accounting).
    //
    // Module slug = 'quickbooks' (declared in config/permissions.php
    // by S-PERM-EXPAND; 7-action vocabulary in
    // config/permission_actions.php). super_admin + accountant both
    // get view; manager + dispatcher + operator + read_only do not.
    //
    // Settings child is gated more tightly: the parent visibility
    // check uses 'view' (per sidebar.php standard behaviour), but
    // the Settings child page itself enforces edit_credentials
    // server-side at require_permission(). The child still renders
    // in the sidebar for users with 'view' only — clicking it
    // triggers a 403 instead of hiding the link (matches the
    // S-SIDEBAR-LOCK-ALL-RESTRICTED render-locked-items semantic).
    // ----------------------------------------------------------
    [
        'separator' => true,
        'label'     => 'QuickBooks',
        'module'    => 'quickbooks',
    ],
    [
        'label'        => 'QuickBooks',
        'icon'         => 'quickbooks',
        'accent'       => 'success',
        'url'          => '/quickbooks/dashboard',
        'match_prefix' => '/quickbooks',
        'module'       => 'quickbooks',
        'badge'        => null,
        'children' => [
            [
                'label'  => 'Dashboard',
                'icon'   => 'home',
                'url'    => '/quickbooks/dashboard',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Sync Queue',
                'icon'   => 'inbox-arrow-down',
                'url'    => '/quickbooks/sync_queue',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Sync Log',
                'icon'   => 'clipboard-document-list',
                'url'    => '/quickbooks/sync_log',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Drift',
                'icon'   => 'exclamation-triangle',
                'url'    => '/quickbooks/drift',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Manual Sync',
                'icon'   => 'arrow-path',
                'url'    => '/quickbooks/manual_sync',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Customers',
                'icon'   => 'users',
                'url'    => '/quickbooks/customers',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Vendors',
                'icon'   => 'building-storefront',
                'url'    => '/quickbooks/vendors',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Accounts',
                'icon'   => 'book-open',
                'url'    => '/quickbooks/accounts',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Tax Codes',
                'icon'   => 'receipt-percent',
                'url'    => '/quickbooks/tax_codes',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Bank Accounts',
                'icon'   => 'building-library',
                'url'    => '/quickbooks/bank_accounts',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Items',
                'icon'   => 'cube',
                'url'    => '/quickbooks/items',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Invoices',
                'icon'   => 'document-text',
                'url'    => '/quickbooks/invoices',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Credit Memos',
                'icon'   => 'receipt-percent',
                'url'    => '/quickbooks/credit_memos',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Refund Receipts',
                'icon'   => 'banknotes',
                'url'    => '/quickbooks/refund_receipts',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Bills',
                'icon'   => 'clipboard-document',
                'url'    => '/quickbooks/bills',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Bill Payments',
                'icon'   => 'currency-dollar',
                'url'    => '/quickbooks/bill_payments',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Payments',
                'icon'   => 'banknotes',
                'url'    => '/quickbooks/payments',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Journal Entries',
                'icon'   => 'document-text',
                'url'    => '/quickbooks/journal_entries',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
            [
                'label'  => 'Settings',
                'icon'   => 'cog-6-tooth',
                'url'    => '/quickbooks/settings',
                'module' => 'quickbooks',
                'badge'  => null,
            ],
        ],
    ],

    // ----------------------------------------------------------
    // Accounting section — accountant role and above (Phase 13+)
    //
    // STRUCTURE: This mirrors the grouped accounting topnav bar
    // (see includes/partials/accounting-nav.php). The sidebar
    // shows a single "Accounting" parent with 8 children — the 6
    // functional groups + Periods + Settings. Each child uses
    // `match_prefix` (a list of URL prefixes) so the correct child
    // stays highlighted no matter which sub-page the user visits
    // within that group (e.g. the "General Ledger" child stays
    // active on Chart of Accounts, Journal Entries, Ledger AND
    // Trial Balance).
    //
    // Clicking a group child navigates to that group's primary
    // landing page; the in-page topnav then exposes the other
    // pages in the same group.
    // ----------------------------------------------------------
    [
        'separator' => true,
        'label'     => 'Accounting',
        'module'    => 'journal_entries', // section shown if user can view any accounting module
    ],
    [
        'label'        => 'Accounting',
        'icon'         => 'calculator',
        'accent'       => 'primary',
        'url'          => '/accounting/dashboard',
        'match_prefix' => '/accounting',   // highlighted on ANY /accounting/* page
        'module'       => 'journal_entries',
        'badge'        => null,
        'children' => [
            [
                'label'  => 'Dashboard',
                'icon'   => 'home',
                'url'    => '/accounting/dashboard',
                'module' => 'journal_entries',
                'badge'  => null,
            ],
            [
                'label'        => 'General Ledger',
                'icon'         => 'book-open',
                'url'          => '/accounting/ledger',
                'match_prefix' => [
                    '/accounting/ledger',
                    '/accounting/chart-of-accounts',
                    '/accounting/journal-entries',
                ],
                'module' => 'journal_entries',
                'badge'  => null,
            ],
            [
                // S036 — Phase B Reports group. Trial Balance moved out of
                // General Ledger to join the rest of the financial statements.
                'label'        => 'Reports',
                'icon'         => 'chart-bar',
                'url'          => '/accounting/reports/profit-loss',
                'match_prefix' => [
                    '/accounting/reports/profit-loss',
                    '/accounting/reports/balance-sheet',
                    '/accounting/reports/cash-flow',
                    '/accounting/reports/asset-schedule',
                    '/accounting/reports/trial-balance',
                ],
                'module' => 'journal_entries',
                'badge'  => null,
            ],
            [
                // S036 — Phase B Budgets module.
                'label'        => 'Budgets',
                'icon'         => 'document-text',
                'url'          => '/accounting/budgets',
                'match_prefix' => ['/accounting/budgets'],
                'module'       => 'journal_entries',
                'badge'        => null,
            ],
            [
                // S037-FX — Phase B FX Revaluation engine (ASPE 1651 temporal method).
                'label'        => 'FX Revaluation',
                'icon'         => 'currency-dollar',
                'url'          => '/accounting/fx-revaluations',
                'match_prefix' => ['/accounting/fx-revaluations'],
                'module'       => 'journal_entries',
                'badge'        => null,
            ],
            [
                // S037-YE — Phase B Year-End Close workflow.
                'label'        => 'Year-End',
                'icon'         => 'calendar-days',
                'url'          => '/accounting/year-end',
                'match_prefix' => ['/accounting/year-end'],
                'module'       => 'journal_entries',
                'badge'        => null,
            ],
            [
                // S037-REC — Phase B Recurring JE templates + cron.
                'label'        => 'Recurring JEs',
                'icon'         => 'arrow-path',
                'url'          => '/accounting/recurring-entries',
                'match_prefix' => ['/accounting/recurring-entries'],
                'module'       => 'journal_entries',
                'badge'        => null,
            ],
            [
                'label'        => 'Receivables',
                'icon'         => 'inbox-arrow-down',
                'url'          => '/accounting/ar-aging',
                'match_prefix' => [
                    '/accounting/ar-aging',
                    '/accounting/statements',
                    '/accounting/collections',
                    '/accounting/deposits',
                ],
                'module' => 'journal_entries',
                'badge'  => null,
            ],
            [
                'label'        => 'Payables',
                'icon'         => 'document-text',
                'url'          => '/accounting/bills',
                'match_prefix' => [
                    '/accounting/bills',
                    '/accounting/ap-aging',
                    '/accounting/vendor-credits',
                ],
                'module' => 'accounts_payable',
                'badge'  => null,
            ],
            [
                'label'        => 'Banking',
                'icon'         => 'building-library',
                'url'          => '/accounting/bank-accounts',
                'match_prefix' => [
                    '/accounting/bank-accounts',
                    '/accounting/bank-reconciliation',
                ],
                'module' => 'bank_accounts',
                'badge'  => null,
            ],
            [
                'label'        => 'Fixed Assets',
                'icon'         => 'truck',
                'url'          => '/accounting/fixed-assets',
                'match_prefix' => [
                    '/accounting/fixed-assets',
                    '/accounting/depreciation',
                    '/accounting/capex',
                ],
                'module' => 'fixed_assets',
                'badge'  => null,
            ],
            [
                // S035 — Tax Management. Single child for GST/HST + PST
                // filing periods, calculation, mark-filed, and remittance
                // posting. match_prefix uses /accounting/tax so both
                // index.php and show.php highlight the parent correctly.
                'label'        => 'Tax',
                'icon'         => 'receipt-percent',
                'url'          => '/accounting/tax',
                'match_prefix' => ['/accounting/tax'],
                'module'       => 'tax_management',
                'badge'        => null,
            ],
            [
                'label'  => 'Periods',
                'icon'   => 'calendar-days',
                'url'    => '/accounting/periods',
                'module' => 'period_management',
                'badge'  => null,
            ],
            [
                'label'  => 'Settings',
                'icon'   => 'cog-6-tooth',
                'url'    => '/accounting/settings',
                'module' => 'journal_entries',
                'badge'  => null,
            ],
        ],
    ],

    // ----------------------------------------------------------
    // Admin section — super_admin and manager only.
    // Moved to bottom of sidebar (operator request 2026-05-21) so the
    // primary app modules (Accounting + QuickBooks + Fleet ops) sit
    // higher and Admin/Users/Audit/Settings sit at the natural
    // "infrastructure-y" position at the foot of the rail.
    // ----------------------------------------------------------
    [
        'separator' => true,
        'label'     => 'Admin',
        'module'    => 'users',      // section shown if user can view 'users'
    ],
    [
        'label'  => 'Users',
        'icon'   => 'users',
        'accent' => 'purple',
        'url'    => '/users',
        'module' => 'users',
        'badge'  => null,
    ],
    [
        'label'  => 'Audit Log',
        'icon'   => 'clipboard-document-list',
        'accent' => 'info',
        'url'    => '/audit',
        'module' => 'audit',
        'badge'  => null,
    ],
    [
        'label'  => 'Settings',
        'icon'   => 'cog-6-tooth',
        'accent' => 'primary',
        'url'    => '/settings',
        'module' => 'settings',
        'badge'  => null,
    ],
];
