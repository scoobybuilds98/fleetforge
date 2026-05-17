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

// ============================================================
// PERMISSION MATRIX
// Ref: FLEETFORGE_CLAUDE_CODE_REFERENCE.md §12
//      FLEETFORGE_ACCOUNTING_SPEC.md — Roles & permissions
// ============================================================

return [

    // ----------------------------------------------------------
    // super_admin — full access to everything
    // ----------------------------------------------------------
    'super_admin' => [
        // Core modules
        'customers'     => $ALL,
        'equipment'     => $ALL,
        'leases'        => $ALL,
        'reservations'  => $ALL,
        'invoices'      => $ALL,
        'payments'      => $ALL,
        'rates'         => $ALL,
        'maintenance'   => $ALL,
        'inspections'   => $ALL,
        'compliance'    => $ALL,
        'reports'       => $ALL,
        'analytics'     => $ALL,
        'users'         => $ALL,
        'settings'      => $ALL,
        'audit'         => $ALL,
        'ai'            => $ALL,
        // Accounting modules (Phase 13+)
        'chart_of_accounts'  => $ALL,
        'journal_entries'    => $ALL,
        'accounts_payable'   => $ALL,
        'bank_accounts'      => $ALL,
        'fixed_assets'       => $ALL,
        'tax_management'     => $ALL,
        'financial_reports'  => $ALL,
        'budgets'            => $ALL,
        'period_management'  => $ALL,
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
        'invoices'      => $VCED,
        'payments'      => $VCED,
        'rates'         => $VCE,
        'maintenance'   => $VCED,
        'inspections'   => $VCED,
        'compliance'    => $VCED,
        'reports'       => $VCE,
        'analytics'     => $VCE,
        'users'         => $V,
        'settings'      => $V,
        'audit'         => $V,
        'ai'            => $VCE,
        // Accounting modules
        'chart_of_accounts'  => $V,
        'journal_entries'    => $V,
        'accounts_payable'   => $V,
        'bank_accounts'      => $V,
        'fixed_assets'       => $V,
        'tax_management'     => $V,
        'financial_reports'  => $VCE,
        'budgets'            => $VCE,
        'period_management'  => $V,
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
        'invoices'      => $V,       // status + dates only — no amounts (enforced in API)
        'payments'      => $NONE,
        'rates'         => $NONE,
        'maintenance'   => $VCE,
        'inspections'   => $VCE,
        'compliance'    => $p(true, false, true, false, false), // VE
        'reports'       => $NONE,
        'analytics'     => $NONE,
        'users'         => $NONE,
        'settings'      => $NONE,
        'audit'         => $NONE,
        'ai'            => $V,
        // Accounting modules — no access
        'chart_of_accounts'  => $NONE,
        'journal_entries'    => $NONE,
        'accounts_payable'   => $NONE,
        'bank_accounts'      => $NONE,
        'fixed_assets'       => $NONE,
        'tax_management'     => $NONE,
        'financial_reports'  => $NONE,
        'budgets'            => $NONE,
        'period_management'  => $NONE,
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
        'invoices'      => $VCED,
        'payments'      => $VCED,
        'rates'         => $V,
        'maintenance'   => $V,
        'inspections'   => $V,
        'compliance'    => $V,
        'reports'       => $VCE,
        'analytics'     => $V,
        'users'         => $NONE,
        'settings'      => $NONE,
        'audit'         => $V,
        'ai'            => $VCE,
        // Accounting modules
        'chart_of_accounts'  => $VCE,
        'journal_entries'    => $VCED,
        'accounts_payable'   => $VCED,
        'bank_accounts'      => $VCED,
        'fixed_assets'       => $VCED,
        'tax_management'     => $VCED,
        'financial_reports'  => $VCE,
        'budgets'            => $VCED,
        'period_management'  => $VCE,
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
        'invoices'      => $V,
        'payments'      => $V,
        'rates'         => $V,
        'maintenance'   => $V,
        'inspections'   => $V,
        'compliance'    => $V,
        'reports'       => $V,
        'analytics'     => $V,
        'users'         => $NONE,
        'settings'      => $NONE,
        'audit'         => $V,
        'ai'            => $V,
        // Accounting modules
        'chart_of_accounts'  => $V,
        'journal_entries'    => $V,
        'accounts_payable'   => $V,
        'bank_accounts'      => $V,
        'fixed_assets'       => $V,
        'tax_management'     => $V,
        'financial_reports'  => $V,
        'budgets'            => $V,
        'period_management'  => $V,
    ],
];
