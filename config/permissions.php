<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Role Permission Matrix
//
// Source of truth for all role → module → action permissions.
// Loaded into session on login (see includes/auth.php).
// Also used to seed the user_permissions table.
//
// DB columns: can_view, can_create, can_edit, can_delete, can_export
// Spec matrix: V=view  C=create  E=edit  D=delete  S=settings
//
// NOTE: 'S' in the spec matrix appears only for super_admin and
// conceptually means "full module control including settings".
// It maps to 'export' here (the 5th DB column). Only super_admin
// has export=true in this base config. If specific roles need
// export access on specific modules, update here and re-seed.
//
// S-PERM-EXPAND: extended-action vocabulary lives in
// config/permission_actions.php. Modules that declare extended
// actions there must list those actions as explicit keys in every
// role's matrix entry below (D-PERM-EXPAND-4 — no implicit defaults).
// The auth.php can() check returns false for any (module, action)
// pair without a role default and without an override, so missing
// keys silently lock everyone out except super_admin (who
// short-circuits to true).
// ============================================================

// Action key order: view, create, edit, delete, export
// Helper closure to build a permissions row
$p = static function (bool $v, bool $c, bool $e, bool $d, bool $x): array {
    return [
        'view'   => $v,
        'create' => $c,
        'edit'   => $e,
        'delete' => $d,
        'export' => $x,
    ];
};

// Shorthand constants for common patterns (improves readability below)
//   ALL  = full access (VCEDS)
//   VCED = view/create/edit/delete, no export
//   VCE  = view/create/edit only
//   VC   = view/create only
//   VE   = view/edit only
//   V    = view only
//   NONE = no access

$ALL  = $p(true,  true,  true,  true,  true);
$VCED = $p(true,  true,  true,  true,  false);
$VCE  = $p(true,  true,  true,  false, false);
$VC   = $p(true,  true,  false, false, false);
$VE   = $p(false, false, true,  false, false); // dispatcher compliance: VE
$V    = $p(true,  false, false, false, false);
$NONE = $p(false, false, false, false, false);

// S-PERM-EXPAND: QBO shorthand for the 7-action 'quickbooks' module.
// The QBO action vocabulary is NOT CRUD-shaped — it's (view + 6
// integration controls), so the 5-action $p() helper above doesn't
// fit. These literal maps keep the per-role lines below tidy.
$QBO_ALL  = [
    'view'              => true,
    'force_resync'      => true,
    'force_full_resync' => true,
    'clear_queue'       => true,
    'disconnect'        => true,
    'edit_credentials'  => true,
    'view_raw_payloads' => true,
];
$QBO_NONE = [
    'view'              => false,
    'force_resync'      => false,
    'force_full_resync' => false,
    'clear_queue'       => false,
    'disconnect'        => false,
    'edit_credentials'  => false,
    'view_raw_payloads' => false,
];

// ============================================================
// PERMISSION MATRIX
// Ref: FLEETFORGE_CLAUDE_CODE_REFERENCE.md §12
//      FLEETFORGE_ACCOUNTING_SPEC.md — Roles & permissions
// ============================================================

return [

    // ----------------------------------------------------------
    // super_admin — full access to everything
    // can() short-circuits to true for this role, so the matrix
    // here is mostly informational (powers the admin UI display).
    // ----------------------------------------------------------
    'super_admin' => [
        // Core modules
        'customers'     => $ALL,
        'equipment'     => $ALL,
        'leases'        => $ALL,
        'reservations'  => $ALL,
        'invoices'      => $ALL + ['approve' => true],   // S-BATCH-APPROVAL
        'payments'      => $ALL,
        'rates'         => $ALL,
        'maintenance'   => $ALL,
        'inspections'   => $ALL,
        'compliance'    => $ALL,
        'reports'       => $ALL,
        'analytics'     => $ALL,
        'users'         => $ALL,
        'settings'      => $ALL,
        // Settings tab permissions — super_admin gets full access to all tabs
        'settings_general'      => $ALL,
        'settings_design'       => $ALL,
        'settings_users'        => $ALL,
        'settings_portal'       => $ALL,
        'settings_audit'        => $ALL,
        'settings_system'       => $ALL,
        'settings_integrations' => $ALL,
        'settings_intelligence' => $ALL,
        'settings_customer_notifications' => $ALL,
        'audit'         => $ALL,
        'ai'            => $ALL,
        // Accounting modules (Phase 13+)
        'chart_of_accounts'  => $ALL,
        'journal_entries'    => $ALL + ['post' => true, 'approve' => true],
        'accounts_payable'   => $ALL,
        'bank_accounts'      => $ALL,
        'fixed_assets'       => $ALL,
        'tax_management'     => $ALL + ['submit' => true],
        'financial_reports'  => $ALL,
        'budgets'            => $ALL,
        'period_management'  => $ALL + ['lock' => true, 'unlock' => true],
        // S-PERM-CLEANUP: accounting_settings — manages GL mappings, tax settings,
        // fiscal year config. Real module key used by code at
        // api/v1/accounting/settings/{index,update}.php (5 require_permission call
        // sites total, actions view/edit). Was missing from config pre-cleanup,
        // causing silent 403 for non-super_admin (D-PERM-CLEANUP-2).
        'accounting_settings' => $ALL,
        // S-PERM-EXPAND: QuickBooks integration (Phase QBO).
        'quickbooks'         => $QBO_ALL,
    ],

    // ----------------------------------------------------------
    // manager — broad access, no export, limited admin modules
    // ----------------------------------------------------------
    'manager' => [
        // Core modules
        'customers'     => $VCED,
        'equipment'     => $VCED,
        'leases'        => $VCED,
        'reservations'  => $VCED,
        'invoices'      => $VCED + ['approve' => true],  // S-BATCH-APPROVAL
        'payments'      => $VCED,
        'rates'         => $VCE,
        'maintenance'   => $VCED,
        'inspections'   => $VCED,
        'compliance'    => $VCED,
        'reports'       => $VCE,
        'analytics'     => $VCE,
        // S-PERM-USERS-SUPERADMIN-ONLY — Users module restricted to super_admin only
        'users'         => $NONE,
        'settings'      => $V,
        // Manager: General tab only by default; superadmin can grant others
        'settings_general'      => $V,
        'settings_design'       => $NONE,
        'settings_users'        => $NONE,
        'settings_portal'       => $NONE,
        'settings_audit'        => $NONE,
        'settings_system'       => $NONE,
        'settings_integrations' => $NONE,
        'settings_intelligence' => $NONE,
        'settings_customer_notifications' => $NONE,
        'audit'         => $V,
        'ai'            => $VCE,
        // Accounting modules
        'chart_of_accounts'  => $V,
        'journal_entries'    => $V    + ['post' => false, 'approve' => false],
        'accounts_payable'   => $V,
        'bank_accounts'      => $V,
        'fixed_assets'       => $V,
        'tax_management'     => $V    + ['submit' => false],
        'financial_reports'  => $VCE,
        'budgets'            => $VCE,
        'period_management'  => $V    + ['lock' => false, 'unlock' => false],
        // S-PERM-CLEANUP: managers do not manage accounting settings — settings
        // are accountant domain (D-PERM-CLEANUP-2). Subject to CPA review.
        'accounting_settings' => $NONE,
        // S-PERM-EXPAND: managers do not get QBO access by default — must be
        // granted per-user via override.
        'quickbooks'         => $QBO_NONE,
    ],

    // ----------------------------------------------------------
    // dispatcher — operational access only, no financial amounts
    // Note: dispatcher can VIEW invoices (status/dates) but the
    // API strips dollar fields when can('payments','view')=false.
    // ----------------------------------------------------------
    'dispatcher' => [
        // Core modules
        'customers'     => $VC,
        'equipment'     => $VCE,
        'leases'        => $VCE,
        'reservations'  => $VCED,
        'invoices'      => $V + ['approve' => false],    // S-BATCH-APPROVAL: dispatchers don't approve billing
        'payments'      => $NONE,
        'rates'         => $NONE,
        'maintenance'   => $VCE,
        'inspections'   => $VCE,
        'compliance'    => $p(true, false, true, false, false), // VE
        'reports'       => $NONE,
        'analytics'     => $NONE,
        'users'         => $NONE,
        'settings'      => $NONE,
        'settings_general'      => $NONE,
        'settings_design'       => $NONE,
        'settings_users'        => $NONE,
        'settings_portal'       => $NONE,
        'settings_audit'        => $NONE,
        'settings_system'       => $NONE,
        'settings_integrations' => $NONE,
        'settings_intelligence' => $NONE,
        'settings_customer_notifications' => $NONE,
        'audit'         => $NONE,
        'ai'            => $V,
        // Accounting modules — no access
        'chart_of_accounts'  => $NONE,
        'journal_entries'    => $NONE + ['post' => false, 'approve' => false],
        'accounts_payable'   => $NONE,
        'bank_accounts'      => $NONE,
        'fixed_assets'       => $NONE,
        'tax_management'     => $NONE + ['submit' => false],
        'financial_reports'  => $NONE,
        'budgets'            => $NONE,
        'period_management'  => $NONE + ['lock' => false, 'unlock' => false],
        // S-PERM-CLEANUP: dispatchers do not manage accounting settings (D-PERM-CLEANUP-2).
        'accounting_settings' => $NONE,
        'quickbooks'         => $QBO_NONE,
    ],

    // ----------------------------------------------------------
    // accountant — full financial access, limited operational
    // ----------------------------------------------------------
    'accountant' => [
        // Core modules
        'customers'     => $V,
        'equipment'     => $V,
        'leases'        => $V,
        'reservations'  => $V,
        // S-BATCH-APPROVAL: batch-run approval is deliberately scoped to
        // MANAGER + SUPER_ADMIN (operator decision). Accountants keep full
        // invoice CRUD but do not sign off batch runs — flip this to true if
        // you want them approving billing as well.
        'invoices'      => $VCED + ['approve' => false],
        'payments'      => $VCED,
        'rates'         => $V,
        'maintenance'   => $V,
        'inspections'   => $V,
        'compliance'    => $V,
        'reports'       => $VCE,
        'analytics'     => $V,
        'users'         => $NONE,
        'settings'      => $NONE,
        'settings_general'      => $NONE,
        'settings_design'       => $NONE,
        'settings_users'        => $NONE,
        'settings_portal'       => $NONE,
        'settings_audit'        => $NONE,
        'settings_system'       => $NONE,
        'settings_integrations' => $NONE,
        'settings_intelligence' => $NONE,
        'settings_customer_notifications' => $NONE,
        'audit'         => $V,
        'ai'            => $VCE,
        // Accounting modules
        'chart_of_accounts'  => $VCE,
        // S-PERM-EXPAND: accountant gets post + approve on JEs (matches
        // the existing two-eyes AJE workflow already gated on 'edit').
        'journal_entries'    => $VCED + ['post' => true, 'approve' => true],
        'accounts_payable'   => $VCED,
        'bank_accounts'      => $VCED,
        'fixed_assets'       => $VCED,
        // S-PERM-EXPAND: accountant submits tax filings.
        'tax_management'     => $VCED + ['submit' => true],
        'financial_reports'  => $VCE,
        'budgets'            => $VCED,
        // S-PERM-EXPAND: accountant can lock (close) periods but not
        // unlock — reopening a closed period requires super_admin.
        'period_management'  => $VCE  + ['lock' => true, 'unlock' => false],
        // S-PERM-CLEANUP: accountant gets view + edit on accounting_settings —
        // they manage GL mappings, tax settings, fiscal year config (D-PERM-CLEANUP-2).
        // Inline $p() pattern matches dispatcher.compliance precedent; the $VE
        // shortcut at the top of this file is misleadingly named (it's actually
        // edit-only, no view) so we inline the literal pattern for clarity.
        'accounting_settings' => $p(true, false, true, false, false), // view + edit
        'quickbooks'         => $QBO_NONE,
    ],

    // ----------------------------------------------------------
    // read_only — view everything except user/settings management
    // ----------------------------------------------------------
    'read_only' => [
        // Core modules
        'customers'     => $V,
        'equipment'     => $V,
        'leases'        => $V,
        'reservations'  => $V,
        'invoices'      => $V + ['approve' => false],    // S-BATCH-APPROVAL
        'payments'      => $V,
        'rates'         => $V,
        'maintenance'   => $V,
        'inspections'   => $V,
        'compliance'    => $V,
        'reports'       => $V,
        'analytics'     => $V,
        'users'         => $NONE,
        'settings'      => $NONE,
        'settings_general'      => $NONE,
        'settings_design'       => $NONE,
        'settings_users'        => $NONE,
        'settings_portal'       => $NONE,
        'settings_audit'        => $NONE,
        'settings_system'       => $NONE,
        'settings_integrations' => $NONE,
        'settings_intelligence' => $NONE,
        'settings_customer_notifications' => $NONE,
        'audit'         => $V,
        'ai'            => $V,
        // Accounting modules
        'chart_of_accounts'  => $V,
        'journal_entries'    => $V + ['post' => false, 'approve' => false],
        'accounts_payable'   => $V,
        'bank_accounts'      => $V,
        'fixed_assets'       => $V,
        'tax_management'     => $V + ['submit' => false],
        'financial_reports'  => $V,
        'budgets'            => $V,
        'period_management'  => $V + ['lock' => false, 'unlock' => false],
        // S-PERM-CLEANUP: read_only sees accounting_settings (consistency with
        // their accounting-view pattern; D-PERM-CLEANUP-2).
        'accounting_settings' => $V,
        'quickbooks'         => $QBO_NONE,
    ],
];
