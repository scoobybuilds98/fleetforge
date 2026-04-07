<?php
declare(strict_types=1);

/**
 * app/admin/users/permissions.php
 *
 * PERM-1 — Per-user permission override matrix admin page.
 *
 * super_admin only. Lists every (module, action) pair from
 * config/permissions.php in a toggle matrix for a single target user.
 * Each cell shows:
 *   - the role baseline (true/false, displayed as a dim dot)
 *   - the current override (allow / deny / none)
 *   - the effective resolved value
 * Clicking a cell cycles through: none → allow → deny → none
 *
 * Data flow:
 *   - Initial load: inline-rendered PHP pulls the full matrix once
 *     (same code path as api/v1/users/permissions/index.php, but
 *     inline so we don't need a second round-trip on first render)
 *   - Every cell click POSTs to api/v1/users/permissions/update.php
 *     and updates the local Alpine store on success
 *   - "Reset all" button POSTs to api/v1/users/permissions/reset.php
 *
 * Security:
 *   - Page requires is_super_admin() — other roles get 403
 *   - Target user must not be super_admin (API also enforces, but
 *     we disable the UI in that case with a friendly message)
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           api/v1/users/permissions/{index,update,reset}.php
 * @session  PERM-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// Super admin only — no exceptions.
if (!is_super_admin()) {
    http_response_code(403);
    $errorFile = FF_ROOT . '/app/errors/403.php';
    if (file_exists($errorFile)) {
        require $errorFile;
    } else {
        echo '<h1>403 Forbidden</h1><p>Only super_admin may manage permission overrides.</p>';
    }
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
];

$actions = ['view', 'create', 'edit', 'delete', 'export'];

$matrix = [];
foreach ($moduleSlugs as $slug) {
    $row = [
        'slug'    => $slug,
        'label'   => $labels[$slug] ?? ucwords(str_replace('_', ' ', $slug)),
        'actions' => [],
    ];
    foreach ($actions as $action) {
        $roleVal = (bool) ($roleMatrix[$slug][$action] ?? false);
        $ovr     = $overrideMap[$slug][$action] ?? null;
        $eff     = $ovr === null ? $roleVal : (bool) $ovr;
        $row['actions'][$action] = [
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
            Permission overrides cannot be applied to super_admin users.
            The matrix below is shown for reference only and is read-only.
        </div>
    </div>
</div>
<?php endif; ?>

<div x-data="permissionsMatrix()" x-init="init()">

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
        <table class="table table-sm perm-matrix">
            <thead>
                <tr>
                    <th style="min-width:180px;">Module</th>
                    <?php foreach ($actions as $action): ?>
                    <th style="text-align:center;text-transform:capitalize;"><?= e($action) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in modules" :key="row.slug">
                    <tr>
                        <td style="font-weight:500;" x-text="row.label"></td>
                        <template x-for="action in actions" :key="action">
                            <td style="text-align:center;">
                                <button type="button"
                                        class="perm-cell"
                                        :class="cellClass(row.slug, action)"
                                        @click="cycleCell(row.slug, action)"
                                        :disabled="<?= $isSuperAdminTarget ? 'true' : 'saving' ?>"
                                        :title="cellTooltip(row.slug, action)">
                                    <span x-text="cellLabel(row.slug, action)"></span>
                                </button>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
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
<div x-show="reasonModal.open"
     class="modal-backdrop"
     style="display:none;"
     :style="reasonModal.open ? 'display:flex;' : 'display:none;'"
     @keydown.escape.window="reasonModal.open && cancelReason()">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">
                <span x-text="reasonModal.intent === 1 ? 'Grant permission' : 'Revoke permission'"></span>
            </h3>
            <button type="button" class="modal-close" @click="cancelReason()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:0.875rem;color:var(--text-secondary);">
                <span x-text="reasonModal.intent === 1 ? 'Explicitly grant' : 'Explicitly deny'"></span>
                <strong x-text="reasonModal.label"></strong> →
                <strong x-text="reasonModal.action"></strong>
                for <strong><?= e($target['name']) ?></strong>.
            </p>
            <div class="form-group">
                <label class="form-label">Reason <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" rows="3"
                          x-model="reasonModal.reason"
                          maxlength="1000"
                          placeholder="Why is this override needed?"></textarea>
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
                    :disabled="saving">
                <span x-show="!saving" x-text="reasonModal.intent === 1 ? 'Grant' : 'Deny'"></span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Reset confirmation modal
     ══════════════════════════════════════════════════════════════ -->
<div x-show="resetModalOpen"
     class="modal-backdrop"
     style="display:none;"
     :style="resetModalOpen ? 'display:flex;' : 'display:none;'"
     @keydown.escape.window="resetModalOpen && (resetModalOpen = false)">
    <div class="modal" @click.stop>
        <div class="modal-header">
            <h3 class="modal-title">Reset all overrides?</h3>
            <button type="button" class="modal-close" @click="resetModalOpen = false">×</button>
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

/* Permission matrix cells ────────────────────────────────── */
.perm-matrix td { padding: 8px 6px; vertical-align: middle; }
.perm-matrix th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; }

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
</style>

<script>
function permissionsMatrix() {
    return {
        /* ── State ─────────────────────────────────────────── */
        modules: <?= json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        actions: <?= json_encode($actions) ?>,
        labels:  <?= json_encode($labels) ?>,

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

        resetModalOpen: false,

        init() {
            // no-op — matrix is rendered server-side
        },

        /* ── Derived state ─────────────────────────────────── */
        get overrideCount() {
            let n = 0;
            for (const row of this.modules) {
                for (const a of this.actions) {
                    if (row.actions[a].override !== null) n++;
                }
            }
            return n;
        },

        get overrideList() {
            const out = [];
            for (const row of this.modules) {
                for (const a of this.actions) {
                    const cell = row.actions[a];
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
            if (cell.override === 1) return `Override: ALLOW (role ${roleTxt})`;
            if (cell.override === 0) return `Override: DENY (role ${roleTxt})`;
            return `Role ${roleTxt} by default`;
        },

        _cell(moduleSlug, action) {
            const row = this.modules.find(r => r.slug === moduleSlug);
            return row.actions[action];
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
            await this.sendUpdate(
                this.reasonModal.module,
                this.reasonModal.action,
                this.reasonModal.intent,
                this.reasonModal.reason.trim() || null
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

                // Update local state in place
                const row  = this.modules.find(r => r.slug === moduleSlug);
                const cell = row.actions[action];
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
                // Clear all overrides in-place
                for (const row of this.modules) {
                    for (const a of this.actions) {
                        row.actions[a].override  = null;
                        row.actions[a].reason    = null;
                        row.actions[a].effective = row.actions[a].role;
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
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
