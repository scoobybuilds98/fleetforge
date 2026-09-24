<?php
declare(strict_types=1);

/**
 * app/admin/settings/notifications.php
 *
 * Settings → Notifications (S-ATTENTION-INBOX). Super admin only.
 *
 * One row per "Needs attention" kind (lib/Attention/KindRegistry.php):
 *   - who sees it (roles; super admins always see everything),
 *   - how urgent it is (fixed kinds only — kinds that decide per item, like
 *     unit documents becoming urgent once expired, show their rule instead),
 *   - WhatsApp: instantly / in the morning summary / never.
 * Plus the escalation window: an urgent item nobody has taken after this
 * many hours goes to the owner (super admins), once.
 *
 * Only values that differ from a kind's defaults are stored (setting
 * notifications.kind_overrides), so improving a default later still reaches
 * every kind nobody customised.
 *
 * Rendered inside app/admin/settings/index.php (tab "notifications");
 * saves via api/v1/attention/settings.php.
 *
 * @session S-ATTENTION-INBOX
 */

use FleetForge\Attention\KindRegistry;

// Standalone-safe guard (mirrors customer_notifications.php): only bootstrap
// when hit directly; when included by index.php these are already set.
if (!isset($csrfToken)) {
    require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
    require_once FF_ROOT . '/includes/auth.php';
    require_auth();
    if (!is_super_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$nsRoles = [];
foreach (db_select("SELECT slug, name FROM user_roles WHERE slug <> 'super_admin' ORDER BY id") as $r) {
    $nsRoles[] = ['slug' => (string) $r['slug'], 'name' => (string) $r['name']];
}

$nsKinds = [];
foreach (KindRegistry::all() as $key => $k) {
    $nsKinds[] = [
        'key'              => $key,
        'label'            => $k->label(),
        'description'      => $k->description(),
        'area'             => $k->area(),
        'roles'            => KindRegistry::rolesFor($k),
        'dynamic'          => $k->dynamicPriority(),
        'rule'             => $k->priorityRule(),
        'priority'         => $k->dynamicPriority() ? null : KindRegistry::priorityFor($k, null),
        'whatsapp'         => KindRegistry::whatsappFor($k),
        // Customer requests follow Portal Requests routing; roles here add to it.
        'routed'           => $key === 'customer_request',
    ];
}
$nsAreas    = KindRegistry::AREAS;
$nsEscalate = (int) settings_get('notifications.escalate_after_hours', '24');
?>

<div x-data="FF_NotifSettings(<?= e(json_encode([
        'kinds' => $nsKinds, 'roles' => $nsRoles, 'areas' => $nsAreas, 'escalate' => $nsEscalate,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="font-weight:600;">How staff notifications work</div>
        <div class="card-body" style="font-size:0.875rem;color:var(--text-secondary);display:grid;gap:8px;max-width:78ch;">
            <p style="margin:0;"><b style="color:var(--text-primary)">Needs attention</b> is one shared item per problem: an overdue customer, an expiring document, a credit application. It stays until the problem is fixed, then closes by itself. Anyone can take it, snooze it or mark it done, and everyone sees who did. The number on the bell counts only these.</p>
            <p style="margin:0;"><b style="color:var(--text-primary)">Updates</b> are everything else that happened (leases out and back, invoices sent, payments in). They never add to the bell's number.</p>
            <p style="margin:0;">Super admins see every kind below. An urgent item nobody takes goes to them after the escalation time.</p>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="font-weight:600;">Escalation</div>
        <div class="card-body" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:0.875rem;">
            <label for="ns-escalate">Escalate an urgent item nobody has taken after</label>
            <input id="ns-escalate" type="number" min="1" max="168" class="form-control form-control-sm" style="width:90px" x-model.number="escalate">
            <span>hours</span>
        </div>
    </div>

    <template x-for="(areaLabel, area) in areas" :key="area">
        <div class="card" style="margin-bottom:20px;" x-show="kinds.some(k => k.area === area)">
            <div class="card-header" style="font-weight:600;" x-text="areaLabel"></div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table att-settings-table">
                        <thead>
                            <tr><th>What</th><th>Who sees it</th><th>Priority</th><th>WhatsApp</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="k in kinds.filter(k => k.area === area)" :key="k.key">
                                <tr>
                                    <td>
                                        <div style="font-weight:600;" x-text="k.label"></div>
                                        <div class="att-kind-desc" x-text="k.description"></div>
                                    </td>
                                    <td>
                                        <div class="att-roles">
                                            <template x-for="r in roles" :key="r.slug">
                                                <label><input type="checkbox" :value="r.slug" x-model="k.roles"> <span x-text="r.name"></span></label>
                                            </template>
                                        </div>
                                        <div class="att-kind-desc" x-show="k.routed">Plus whoever Portal &amp; Requests routing names for that request type.</div>
                                        <div class="att-kind-desc" x-show="!k.routed && k.roles.length === 0">Super admins only.</div>
                                    </td>
                                    <td>
                                        <template x-if="k.dynamic">
                                            <div class="att-rule" x-text="k.rule"></div>
                                        </template>
                                        <template x-if="!k.dynamic">
                                            <select class="form-select form-control-sm" style="width:auto" x-model="k.priority" :aria-label="'Priority for ' + k.label">
                                                <option value="urgent">Urgent</option>
                                                <option value="todo">To do</option>
                                            </select>
                                        </template>
                                    </td>
                                    <td>
                                        <select class="form-select form-control-sm" style="width:auto" x-model="k.whatsapp" :aria-label="'WhatsApp for ' + k.label">
                                            <option value="now">Right away</option>
                                            <option value="summary">Morning summary</option>
                                            <option value="off">Never</option>
                                        </select>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>

    <div style="display:flex;align-items:center;gap:12px;">
        <button type="button" class="btn btn-primary" :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save notification settings'"></button>
        <span class="text-sm" :class="error ? 'text-danger' : 'text-secondary'" x-text="error || status"></span>
    </div>
</div>

<script>
/**
 * FF_NotifSettings — Settings → Notifications (S-ATTENTION-INBOX).
 * Posts every kind's roles/priority/WhatsApp; the server stores only the
 * values that differ from each kind's defaults.
 */
function FF_NotifSettings(init) {
    return {
        kinds: init.kinds,
        roles: init.roles,
        areas: init.areas,
        escalate: init.escalate,
        saving: false,
        status: '',
        error: '',

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            this.status = '';
            const kinds = {};
            this.kinds.forEach(k => {
                kinds[k.key] = { roles: k.roles, whatsapp: k.whatsapp };
                if (!k.dynamic) kinds[k.key].priority = k.priority;
            });
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/attention/settings.php'), {
                    kinds, escalate_after_hours: this.escalate,
                });
                if (res?.success) {
                    this.status = 'Saved. Changes apply right away.';
                    if (window.FF_Toast) FF_Toast.success('Notification settings saved', '');
                } else {
                    this.error = res?.error?.message || 'Could not save.';
                }
            } catch {
                this.error = 'Network error. Try again.';
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>
