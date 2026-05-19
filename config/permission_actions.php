<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Per-Module Action Vocabulary + Descriptions
//
// S-PERM-EXPAND — sibling of config/permissions.php.
//
// config/permissions.php holds the role-default 5-action map for every
// (role, module) pair (view/create/edit/delete/export). This file
// extends the vocabulary for modules that need additional verbs like
// 'post' (post a draft JE), 'approve' (two-eyes AJE workflow), 'lock'
// (close an accounting period), 'submit' (file a tax return), or the
// QuickBooks integration controls (force_resync, clear_queue, etc.).
//
// Resolution order in API + UI:
//   1. module_actions[$module]              if explicitly declared
//   2. module_actions['_default']           otherwise
//
// The user_permission_overrides table has no vocabulary constraint
// (action is varchar(50)), so any declared action can flow through
// the override path verbatim. Role-default values for an extended
// action must be defined explicitly in config/permissions.php inside
// that role's module entry — auth.php's can() resolves to false for
// any (module, action) pair that has no role default and no override
// (no implicit defaults; see D-PERM-EXPAND-4).
//
// @decisions
//   D-PERM-EXPAND-2: Per-module action sets defined as data, not
//     hardcoded in UI or API. The UI matrix renders only the actions
//     declared for that module — no empty cells for non-applicable
//     actions.
//   D-PERM-EXPAND-4: Every declared action must have a role default
//     defined in config/permissions.php for every system role,
//     otherwise can() returns false for everyone except super_admin
//     (who short-circuits to true).
// ============================================================

return [

    // ============================================================
    // module_actions — per-module action vocabulary
    //
    // Keys are module slugs (must exist in config/permissions.php),
    // values are ordered lists of action strings. Order matters: the
    // admin UI renders columns left-to-right in this order.
    //
    // The reserved key '_default' provides the fallback action set
    // for any module not explicitly listed here (5-action CRUD).
    // ============================================================
    'module_actions' => [

        // Default 5-action CRUD set — applies to any module that
        // doesn't declare its own action vocabulary below.
        '_default' => ['view', 'create', 'edit', 'delete', 'export'],

        // Journal Entries — extended for the two-eyes post/approve
        // workflow. Today the JE post endpoint gates on 'edit'; future
        // sessions will migrate to the dedicated 'post' verb. 'approve'
        // is reserved for the AJE two-eyes workflow.
        'journal_entries' => [
            'view', 'create', 'edit', 'delete', 'export',
            'post', 'approve',
        ],

        // Period Management — extended with explicit close/reopen
        // verbs. Today periods/close gates on 'edit'; future migration
        // to 'lock'. 'unlock' is super_admin-only by role default.
        'period_management' => [
            'view', 'create', 'edit', 'delete',
            'lock', 'unlock',
        ],

        // Tax Management — extended with 'submit' for the
        // mark-as-filed action on tax returns.
        'tax_management' => [
            'view', 'create', 'edit', 'delete', 'export',
            'submit',
        ],

        // QuickBooks — reserved key for Phase QBO. Defines the
        // sensitive integration-control vocabulary in advance so
        // per-user overrides can grant access before any
        // require_permission() call sites exist in code. The
        // bulk group_apply macros never grant these extended QBO
        // actions — they must be granted individually per user.
        'quickbooks' => [
            'view',
            'force_resync',
            'force_full_resync',
            'clear_queue',
            'disconnect',
            'edit_credentials',
            'view_raw_payloads',
        ],
    ],

    // ============================================================
    // action_descriptions — short human-readable tooltips
    //
    // Surfaced by the admin permissions UI on column-header hover.
    // Every action declared in module_actions above must have an
    // entry here so the UI never falls back to a raw verb string.
    // ============================================================
    'action_descriptions' => [
        // Standard CRUD
        'view'              => 'View pages and read data',
        'create'            => 'Create new records',
        'edit'              => 'Modify existing records',
        'delete'            => 'Soft-delete records',
        'export'            => 'Export data to CSV / PDF',

        // Accounting workflow extended actions
        'post'              => 'Post draft JEs to the General Ledger',
        'approve'           => 'Approve adjusting JEs (two-eyes workflow)',
        'lock'              => 'Close accounting periods (open → closed)',
        'unlock'            => 'Re-open closed accounting periods',
        'submit'            => 'Submit tax filings (mark as filed)',

        // QuickBooks integration controls
        'force_resync'      => 'Manually trigger entity re-sync to QBO',
        'force_full_resync' => 'Manually trigger full entity-type re-sync',
        'clear_queue'       => 'Clear the QBO sync queue (emergency tool)',
        'disconnect'        => 'Disconnect the QBO integration',
        'edit_credentials'  => 'Modify QBO OAuth credentials',
        'view_raw_payloads' => 'View unmasked QBO API payloads',
    ],
];
