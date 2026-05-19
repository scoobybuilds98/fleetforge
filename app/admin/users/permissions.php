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
    "SELECT u.id, u.name, u.email, u.role_id,
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
    'customers'         => 'Customers',
    'equipment'         => 'Equipment',
    'leases'            => 'Leases',
    'reservations'      => 'Reservations',
    'invoices'          => 'Invoices',
    'payments'          => 'Payments',
    'rates'             => 'Rates',
    'maintenance'       => 'Maintenance',
    'compliance'        => 'Compliance',
    'reports'           => 'Reports',
    'analytics'         => 'Analytics',
    'users'             => 'Users',
    'settings'          => 'Settings',
    'audit'             => 'Audit Log',
    'ai'                => 'AI Tools',
    'chart_of_accounts' => 'Chart of Accounts',
    'journal_entries'   => 'Journal Entries',
    'accounts_payable'  => 'Accounts Payable',
    'bank_accounts'     => 'Bank Accounts',
    'fixed_assets'      => 'Fixed Assets',
    'tax_management'    => 'Tax Management',
    'financial_reports' => 'Financial Reports',
    'budgets'           => 'Budgets',
    'period_management' => 'Period Management',
    // S-PERM-EXPAND: QBO module reserved for Phase QBO.
    'quickbooks'        => 'QuickBooks',
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

$pageTitle = 'Permissions — ' . $target['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('users/show') ?>?id=<?= $targetId ?>" class="btn btn-secondary btn-sm">← Back to user</a>
    <h1 class="page-header-title">Permissions — <?= e($target['name']) ?></h1>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto;">
        <span class="badge badge-neutral badge-pill"><?= e($target['role_name']) ?></span>
    </div>
</div>

<?php if ($isSuperAdminTarget): ?>
<div class="toast toast-warning" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z"/>
        </svg>
    </span>
    <div class="toast-body">
        <div class="toast-title">super_admin always has full access</div>
        <div class="toast-message">
            Permission overrides and bulk macros cannot be applied to super_admin users.
            The matrix below is shown for reference only and is read-only.
        </div>
    </div>
</div>
<?php endif; ?>

<div x-data="permissionsMatrix()" x-init="init()">

<?php if (!$isSuperAdminTarget): ?>
<!-- ── Bulk Operations by Module Group (S-PERM-EXPAND) ────────────── -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-weight:600;">Bulk Operations by Module Group</span>
        <span class="text-muted" style="font-size:0.8125rem;">
            Apply a macro to every module in a group at once. Requires a reason.
        </span>
    </div>
    <div class="card-body" style="padding:0;">
        <template x-for="group in groups" :key="group.key">
            <div class="perm-group-row">
                <div class="perm-group-info">
                    <div class="perm-group-label" x-text="group.label"></div>
                    <div class="perm-group-desc text-muted" x-text="group.description"></div>
                    <div class="perm-group-modules text-muted">
                        <span x-text="group.modules.length"></span> module<span x-show="group.modules.length !== 1">s</span>:
                        <span x-text="group.modules.join(', ')"></span>
                    </div>
                </div>
                <div class="perm-group-actions">
                    <button type="button" class="btn btn-sm btn-secondary"
                            @click="openGroupMacro(group, 'grant_view')"
                            :disabled="saving">Grant View</button>
                    <button type="button" class="btn btn-sm btn-secondary"
                            @click="openGroupMacro(group, 'grant_read_write')"
                            :disabled="saving">Grant Read+Write</button>
                    <button type="button" class="btn btn-sm btn-danger"
                            @click="openGroupMacro(group, 'deny_all')"
                            :disabled="saving">Deny All</button>
                    <button type="button" class="btn btn-sm btn-secondary"
                            @click="openGroupMacro(group, 'clear')"
                            :disabled="saving">Clear</button>
                </div>
            </div>
        </template>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;" class="user-show-layout">

<!-- ── Left: Permission matrix ────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">Permission Matrix</span>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="text-muted" style="font-size:0.8125rem;">
                <span x-text="overrideCount"></span> override<span x-show="overrideCount !== 1">s</span> set
            </span>
            <?php if (!$isSuperAdminTarget): ?>
            <button class="btn btn-secondary btn-sm"
                    @click="confirmReset()"
                    :disabled="overrideCount === 0 || saving">
                Reset all overrides
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <!-- S-PERM-EXPAND: per-module variable-column matrix.
             Each module renders as a row with its own action columns
             above each cell button (verbs vary per module — see
             config/permission_actions.php for the vocabulary). -->
        <div class="perm-matrix-list">
            <template x-for="row in modules" :key="row.slug">
                <div class="perm-matrix-row">
                    <div class="perm-matrix-row-header">
                        <span class="perm-matrix-row-label" x-text="row.label"></span>
                        <span class="perm-matrix-row-slug text-muted" x-text="row.slug"></span>
                    </div>
                    <div class="perm-matrix-row-cells">
                        <template x-for="action in row.actions" :key="action">
                            <div class="perm-matrix-cell-col">
                                <div class="perm-matrix-cell-header"
                                     :title="actionDescription(action)"
                                     x-text="action"></div>
                                <button type="button"
                                        class="perm-cell"
                                        :class="cellClass(row.slug, action)"
                                        @click="cycleCell(row.slug, action)"
                                        :disabled="<?= $isSuperAdminTarget ? 'true' : 'saving' ?>"
                                        :title="cellTooltip(row.slug, action)">
                                    <span x-text="cellLabel(row.slug, action)"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- ── Right column: Legend + summary ─────────────────────────────────── -->
<div style="display:flex;flex-direction:column;gap:12px;">

    <!-- Legend -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Legend</div>
        <div class="card-body" style="padding:16px;font-size:0.8125rem;">
            <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="perm-cell perm-role-on"><span>●</span></span>
                    <span>Role grants (default)</span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="perm-cell perm-role-off"><span>○</span></span>
                    <span>Role denies (default)</span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="perm-cell perm-grant"><span>✓</span></span>
                    <span>Override: <strong>Allow</strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="perm-cell perm-deny"><span>✕</span></span>
                    <span>Override: <strong>Deny</strong></span>
                </div>
            </div>
            <hr style="border:none;border-top:1px solid var(--border-color);margin:12px 0;">
            <div class="text-muted" style="font-size:0.75rem;line-height:1.5;">
                Click a cell to cycle: <br>
                <strong>Default → Allow → Deny → Default</strong><br>
                Overrides always win over the role baseline.
            </div>
        </div>
    </div>

    <!-- Override summary -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Active Overrides</div>
        <div class="card-body" style="padding:0;">
            <div x-show="overrideList.length === 0" class="empty-state" style="padding:20px;">
                <div class="text-muted" style="font-size:0.8125rem;">
                    No overrides set. This user uses the role defaults.
                </div>
            </div>
            <ul x-show="overrideList.length > 0" class="perm-override-list">
                <template x-for="ovr in overrideList" :key="ovr.module + ':' + ovr.action">
                    <li>
                        <div class="perm-ovr-head">
                            <span :class="ovr.granted === 1 ? 'badge badge-success' : 'badge badge-danger'"
                                  x-text="ovr.granted === 1 ? 'Allow' : 'Deny'"></span>
                            <span class="perm-ovr-target">
                                <span x-text="ovr.label"></span>
                                <span class="text-muted">·</span>
                                <span x-text="ovr.action"></span>
                            </span>
                        </div>
                        <div x-show="ovr.reason" class="perm-ovr-reason text-muted" x-text="ovr.reason"></div>
                    </li>
                </template>
            </ul>
        </div>
    </div>

</div><!-- /right column -->

</div><!-- /grid -->

<!-- ══════════════════════════════════════════════════════════════
     Reason modal — shown when cycling a cell to allow/deny
     ══════════════════════════════════════════════════════════════ -->
<!-- S-PERM-EXPAND-D'1: use modal-overlay (flex-centered) not modal-backdrop
     (no centering); preserve dark backdrop via inline styles. Same fix as
     COMPLIANCE-FIX-1. -->
<div x-show="reasonModal.open"
     x-cloak
     class="modal-overlay"
     @click.self="cancelReason()"
     @keydown.escape.window="reasonModal.open && cancelReason()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">
                <span x-text="reasonModal.intent === 1 ? 'Grant permission' : 'Revoke permission'"></span>
            </h3>
            <button type="button" class="modal-close-btn" aria-label="Close" @click="cancelReason()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:0.875rem;color:var(--text-secondary);">
                <span x-text="reasonModal.intent === 1 ? 'Explicitly grant' : 'Explicitly deny'"></span>
                <strong x-text="reasonModal.label"></strong> →
                <strong x-text="reasonModal.action"></strong>
                for <strong><?= e($target['name']) ?></strong>.
            </p>
            <!-- S-PERM-EXPAND-D'2: reason is now REQUIRED for grant/deny
                 overrides. Existing PERM-1 workflow change — every new
                 grant/deny now carries an audit reason. Clearing an
                 override (cycling deny → none) skips this modal entirely
                 and submits with reason=null since it reverts to defaults. -->
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3"
                          x-model="reasonModal.reason"
                          maxlength="1000"
                          placeholder="Why is this override needed? (e.g., 'Temporary access for Q1 close')"></textarea>
                <small class="form-text text-muted">
                    Required for audit trail.
                </small>
            </div>
            <div x-show="reasonModal.error"
                 x-text="reasonModal.error"
                 style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="cancelReason()">Cancel</button>
            <button type="button"
                    class="btn btn-primary"
                    :class="reasonModal.intent === 1 ? '' : 'btn-danger'"
                    @click="confirmReason()"
                    :disabled="saving || !(reasonModal.reason || '').trim()">
                <span x-show="!saving" x-text="reasonModal.intent === 1 ? 'Grant' : 'Deny'"></span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Group macro reason modal (S-PERM-EXPAND)
     ══════════════════════════════════════════════════════════════ -->
<div x-show="groupMacroModal.open"
     x-cloak
     class="modal-overlay"
     @click.self="cancelGroupMacro()"
     @keydown.escape.window="groupMacroModal.open && cancelGroupMacro()"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">
                Bulk: <span x-text="groupMacroModal.macroLabel"></span> · <span x-text="groupMacroModal.groupLabel"></span>
            </h3>
            <button type="button" class="modal-close-btn" aria-label="Close" @click="cancelGroupMacro()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:0.875rem;color:var(--text-secondary);"
               x-text="groupMacroModal.intro"></p>
            <div x-show="groupMacroModal.qboWarning"
                 class="toast toast-warning"
                 style="position:relative;margin-bottom:12px;animation:none;">
                <div class="toast-body">
                    <div class="toast-title">Extended QBO actions are not included</div>
                    <div class="toast-message">
                        Only <code>quickbooks.view</code> will be granted by this macro.
                        force_resync, clear_queue, disconnect, edit_credentials, and view_raw_payloads
                        must be granted individually in the matrix below.
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" rows="3"
                          x-model="groupMacroModal.reason"
                          maxlength="1000"
                          placeholder="Why is this bulk change needed?"></textarea>
                <small class="form-text text-muted">
                    Required. Stored on every override row + audit_log entry.
                </small>
            </div>
            <div x-show="groupMacroModal.error"
                 x-text="groupMacroModal.error"
                 style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="cancelGroupMacro()">Cancel</button>
            <button type="button"
                    class="btn"
                    :class="groupMacroModal.macro === 'deny_all' ? 'btn-danger' : 'btn-primary'"
                    @click="confirmGroupMacro()"
                    :disabled="saving || !(groupMacroModal.reason || '').trim()">
                <span x-show="!saving" x-text="groupMacroModal.submitLabel"></span>
                <span x-show="saving">Applying…</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Reset confirmation modal
     ══════════════════════════════════════════════════════════════ -->
<div x-show="resetModalOpen"
     x-cloak
     class="modal-overlay"
     @click.self="resetModalOpen = false"
     @keydown.escape.window="resetModalOpen && (resetModalOpen = false)"
     style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">Reset all overrides?</h3>
            <button type="button" class="modal-close-btn" aria-label="Close" @click="resetModalOpen = false">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0;font-size:0.875rem;">
                This will remove all <strong x-text="overrideCount"></strong>
                permission override<span x-show="overrideCount !== 1">s</span> for
                <strong><?= e($target['name']) ?></strong> and revert them to the
                <strong><?= e($target['role_name']) ?></strong> defaults.
                This action cannot be undone.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="resetModalOpen = false">Cancel</button>
            <button type="button" class="btn btn-danger" @click="resetAll()" :disabled="saving">
                <span x-show="!saving">Reset all</span>
                <span x-show="saving">Resetting…</span>
            </button>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<style>
@media (max-width: 768px) {
    .user-show-layout { grid-template-columns: 1fr !important; }
}

/* S-PERM-EXPAND: per-module variable-column matrix layout ─── */
.perm-matrix-list { padding: 0; }
.perm-matrix-row {
    display: grid;
    grid-template-columns: 220px 1fr;
    align-items: start;
    gap: 16px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
}
.perm-matrix-row:last-child { border-bottom: none; }

.perm-matrix-row-header {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-top: 18px; /* visually align with cell button below the verb label */
}
.perm-matrix-row-label { font-weight: 600; font-size: 0.875rem; }
.perm-matrix-row-slug { font-size: 0.7rem; font-family: var(--font-mono, monospace); }

.perm-matrix-row-cells {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
}
.perm-matrix-cell-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    min-width: 60px;
}
.perm-matrix-cell-header {
    font-size: 0.7rem;
    text-transform: capitalize;
    color: var(--text-secondary);
    line-height: 1.1;
    text-align: center;
    cursor: help;
    word-break: break-word;
    max-width: 80px;
}

/* Permission matrix cells ────────────────────────────────── */
.perm-cell {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 600;
    border: 1px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 120ms ease;
}
.perm-cell:hover:not(:disabled) {
    transform: scale(1.08);
    border-color: var(--accent-primary);
}
.perm-cell:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

/* State: default, role grants */
.perm-cell.perm-role-on {
    background: var(--bg-secondary);
    color: var(--accent-primary);
    border-color: var(--border-color);
}
/* State: default, role denies */
.perm-cell.perm-role-off {
    background: transparent;
    color: var(--text-muted);
    border-color: var(--border-color);
    opacity: 0.55;
}
/* State: override = allow */
.perm-cell.perm-grant {
    background: rgba(34, 197, 94, 0.14);
    color: var(--color-success, #22c55e);
    border-color: var(--color-success, #22c55e);
}
/* State: override = deny */
.perm-cell.perm-deny {
    background: rgba(239, 68, 68, 0.14);
    color: var(--color-danger, #ef4444);
    border-color: var(--color-danger, #ef4444);
}

/* Override summary list */
.perm-override-list { list-style: none; margin: 0; padding: 0; }
.perm-override-list li {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.8125rem;
}
.perm-override-list li:last-child { border-bottom: none; }
.perm-ovr-head { display: flex; gap: 8px; align-items: center; }
.perm-ovr-target { font-weight: 500; }
.perm-ovr-reason {
    font-size: 0.75rem;
    margin-top: 4px;
    font-style: italic;
    padding-left: 2px;
}

/* S-PERM-EXPAND: Group macro card rows ────────────────────── */
.perm-group-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
    align-items: center;
}
.perm-group-row:last-child { border-bottom: none; }

.perm-group-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.perm-group-label { font-weight: 600; font-size: 0.875rem; }
.perm-group-desc  { font-size: 0.8125rem; line-height: 1.35; }
.perm-group-modules { font-size: 0.75rem; font-family: var(--font-mono, monospace); }

.perm-group-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-end;
}

@media (max-width: 768px) {
    .perm-group-row { grid-template-columns: 1fr; }
    .perm-group-actions { justify-content: flex-start; }
}
</style>

<script>
function permissionsMatrix() {
    return {
        /* ── State ─────────────────────────────────────────── */
        modules:             <?= json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        groups:              <?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        labels:              <?= json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        actionDescriptions:  <?= json_encode($actionDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        saving: false,

        reasonModal: {
            open:   false,
            module: null,
            action: null,
            label:  '',
            intent: null,   // 1 = grant, 0 = deny
            reason: '',
            error:  null,
        },

        // S-PERM-EXPAND: separate modal state for group macros so
        // a partially-filled group macro can't be confused with a
        // partially-filled cell-level reason prompt.
        groupMacroModal: {
            open:       false,
            group:      null,    // group key
            groupLabel: '',
            macro:      null,    // 'grant_view' | 'grant_read_write' | 'deny_all' | 'clear'
            macroLabel: '',      // human-readable button label
            submitLabel:'Apply', // primary button text
            intro:      '',      // body sentence shown above the reason input
            qboWarning: false,   // show the "extended QBO actions excluded" note
            reason:     '',
            error:      null,
        },

        resetModalOpen: false,

        init() {
            // no-op — matrix is rendered server-side; groups/labels/
            // actionDescriptions are injected at first render.
        },

        /* ── Derived state ─────────────────────────────────── */
        get overrideCount() {
            let n = 0;
            for (const row of this.modules) {
                for (const a of row.actions) {
                    if (row.permissions[a].override !== null) n++;
                }
            }
            return n;
        },

        get overrideList() {
            const out = [];
            for (const row of this.modules) {
                for (const a of row.actions) {
                    const cell = row.permissions[a];
                    if (cell.override !== null) {
                        out.push({
                            module:  row.slug,
                            label:   row.label,
                            action:  a,
                            granted: cell.override,
                            reason:  cell.reason || '',
                        });
                    }
                }
            }
            return out;
        },

        /* ── Cell class / label / tooltip ──────────────────── */
        cellClass(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            if (cell.override === 1) return 'perm-grant';
            if (cell.override === 0) return 'perm-deny';
            return cell.role ? 'perm-role-on' : 'perm-role-off';
        },

        cellLabel(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            if (cell.override === 1) return '✓';
            if (cell.override === 0) return '✕';
            return cell.role ? '●' : '○';
        },

        cellTooltip(moduleSlug, action) {
            const cell = this._cell(moduleSlug, action);
            const roleTxt = cell.role ? 'grants' : 'denies';
            const desc    = this.actionDescription(action);
            const head    = cell.override === 1 ? `Override: ALLOW (role ${roleTxt})`
                          : cell.override === 0 ? `Override: DENY (role ${roleTxt})`
                          : `Role ${roleTxt} by default`;
            return desc ? `${head}\n${desc}` : head;
        },

        actionDescription(action) {
            return this.actionDescriptions[action] || '';
        },

        _cell(moduleSlug, action) {
            const row = this.modules.find(r => r.slug === moduleSlug);
            return row.permissions[action];
        },

        /* ── Click handler: cycle none → allow → deny → none ─ */
        cycleCell(moduleSlug, action) {
            if (this.saving) return;
            const cell = this._cell(moduleSlug, action);
            const row  = this.modules.find(r => r.slug === moduleSlug);

            let nextIntent;
            if (cell.override === null)  nextIntent = 1;      // none → allow
            else if (cell.override === 1) nextIntent = 0;      // allow → deny
            else                          nextIntent = null;   // deny → none

            if (nextIntent === null) {
                // Clear override: no confirmation modal, fire directly.
                this.sendUpdate(moduleSlug, action, null, null);
                return;
            }

            // Open reason modal for allow/deny
            this.reasonModal = {
                open:   true,
                module: moduleSlug,
                action: action,
                label:  row.label,
                intent: nextIntent,
                reason: '',
                error:  null,
            };
        },

        cancelReason() {
            this.reasonModal.open = false;
        },

        async confirmReason() {
            // S-PERM-EXPAND-D'2: client-side validation prevents the API call
            // when reason is empty. Server enforces the same rule (update.php
            // returns 422 when granted !== null && reason is empty) as
            // defense in depth.
            const reason = (this.reasonModal.reason || '').trim();
            if (!reason) {
                this.reasonModal.error = 'A reason is required for grant/deny override changes.';
                return;
            }
            await this.sendUpdate(
                this.reasonModal.module,
                this.reasonModal.action,
                this.reasonModal.intent,
                reason
            );
        },

        /* ── API: update one cell ──────────────────────────── */
        async sendUpdate(moduleSlug, action, granted, reason) {
            this.saving = true;
            try {
                const res = await FF_Api.post(
                    FF_Api.url('/api/v1/users/permissions/update.php'),
                    {
                        user_id: <?= $targetId ?>,
                        module:  moduleSlug,
                        action:  action,
                        granted: granted,
                        reason:  reason,
                    }
                );

                if (!res.success) {
                    const msg = (res.error && res.error.message) || 'Save failed.';
                    if (this.reasonModal.open) {
                        this.reasonModal.error = msg;
                    } else {
                        FF_Toast.error('Error', msg);
                    }
                    return;
                }

                // Update local state in place (S-PERM-EXPAND: row.permissions[action])
                const row  = this.modules.find(r => r.slug === moduleSlug);
                const cell = row.permissions[action];
                cell.override  = granted;
                cell.reason    = reason;
                cell.effective = (granted === null) ? cell.role : Boolean(granted);

                this.reasonModal.open = false;
                FF_Toast.success(
                    'Saved',
                    granted === null
                        ? 'Override cleared.'
                        : (granted === 1 ? 'Permission granted.' : 'Permission denied.')
                );
            } catch (err) {
                const msg = 'Network error. Please try again.';
                if (this.reasonModal.open) {
                    this.reasonModal.error = msg;
                } else {
                    FF_Toast.error('Error', msg);
                }
            } finally {
                this.saving = false;
            }
        },

        /* ── Reset all ─────────────────────────────────────── */
        confirmReset() {
            if (this.overrideCount === 0) return;
            this.resetModalOpen = true;
        },

        async resetAll() {
            this.saving = true;
            try {
                const res = await FF_Api.post(
                    FF_Api.url('/api/v1/users/permissions/reset.php'),
                    { user_id: <?= $targetId ?> }
                );
                if (!res.success) {
                    FF_Toast.error('Error', (res.error && res.error.message) || 'Reset failed.');
                    return;
                }
                // Clear all overrides in-place (S-PERM-EXPAND: row.permissions[a])
                for (const row of this.modules) {
                    for (const a of row.actions) {
                        row.permissions[a].override  = null;
                        row.permissions[a].reason    = null;
                        row.permissions[a].effective = row.permissions[a].role;
                    }
                }
                this.resetModalOpen = false;
                FF_Toast.success(
                    'Reset',
                    `Cleared ${res.data.cleared_count} override${res.data.cleared_count === 1 ? '' : 's'}.`
                );
            } catch (err) {
                FF_Toast.error('Error', 'Network error. Please try again.');
            } finally {
                this.saving = false;
            }
        },

        /* ── Group macros (S-PERM-EXPAND) ──────────────────── */
        _macroLabel(macro) {
            return {
                grant_view:       'Grant View',
                grant_read_write: 'Grant Read+Write',
                deny_all:         'Deny All',
                clear:            'Clear Overrides',
            }[macro] || macro;
        },

        _macroSubmitLabel(macro) {
            return {
                grant_view:       'Grant View',
                grant_read_write: 'Grant Read+Write',
                deny_all:         'Deny All',
                clear:            'Clear',
            }[macro] || 'Apply';
        },

        _macroIntro(group, macro) {
            const target = '<?= addslashes(e($target['name'])) ?>';
            const n = group.modules.length;
            const noun = n === 1 ? 'module' : 'modules';
            switch (macro) {
                case 'grant_view':
                    return `Explicitly grant the view action on ${n} ${noun} in group "${group.label}" for ${target}.`;
                case 'grant_read_write':
                    return `Explicitly grant view + create + edit on ${n} ${noun} in group "${group.label}" for ${target}. ` +
                           `Delete and extended verbs are not granted by this macro.`;
                case 'deny_all':
                    return `Explicitly deny every action on every module in group "${group.label}" for ${target}. ` +
                           `Existing role defaults will be overridden by deny rows.`;
                case 'clear':
                    return `Remove every override on the ${n} ${noun} in group "${group.label}" for ${target}. ` +
                           `The user will revert to their role defaults across this group.`;
                default:
                    return '';
            }
        },

        openGroupMacro(group, macro) {
            if (this.saving) return;
            this.groupMacroModal = {
                open:       true,
                group:      group.key,
                groupLabel: group.label,
                macro:      macro,
                macroLabel: this._macroLabel(macro),
                submitLabel:this._macroSubmitLabel(macro),
                intro:      this._macroIntro(group, macro),
                qboWarning: group.key === 'quickbooks' && (macro === 'grant_view' || macro === 'grant_read_write'),
                reason:     '',
                error:      null,
            };
        },

        cancelGroupMacro() {
            this.groupMacroModal.open = false;
        },

        async confirmGroupMacro() {
            const reason = (this.groupMacroModal.reason || '').trim();
            if (!reason) {
                this.groupMacroModal.error = 'A reason is required for bulk macro operations.';
                return;
            }
            this.saving = true;
            try {
                const res = await FF_Api.post(
                    FF_Api.url('/api/v1/users/permissions/group_apply.php'),
                    {
                        user_id: <?= $targetId ?>,
                        group:   this.groupMacroModal.group,
                        macro:   this.groupMacroModal.macro,
                        reason:  reason,
                    }
                );
                if (!res.success) {
                    this.groupMacroModal.error = (res.error && res.error.message) || 'Apply failed.';
                    return;
                }

                // Re-fetch the matrix from authoritative state. Local patching
                // is fragile when N modules × M actions changed at once — the
                // server already knows the new shape (D-PERM-EXPAND-3).
                const refreshed = await FF_Api.get(
                    FF_Api.url('/api/v1/users/permissions/index.php?user_id=<?= $targetId ?>')
                );
                if (refreshed.success && refreshed.data && refreshed.data.modules) {
                    this.modules = refreshed.data.modules;
                }

                this.groupMacroModal.open = false;
                const applied = res.data.applied_count || 0;
                const cleared = res.data.cleared_count || 0;
                let msg;
                if (this.groupMacroModal.macro === 'clear' || cleared > 0) {
                    msg = `Cleared ${cleared} override${cleared === 1 ? '' : 's'}.`;
                } else {
                    msg = `Applied ${applied} override${applied === 1 ? '' : 's'}.`;
                }
                FF_Toast.success('Bulk macro complete', msg);

                if (res.data.warnings && res.data.warnings.length > 0) {
                    res.data.warnings.forEach(w => FF_Toast.warning('Heads up', w));
                }
            } catch (err) {
                this.groupMacroModal.error = 'Network error. Please try again.';
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
