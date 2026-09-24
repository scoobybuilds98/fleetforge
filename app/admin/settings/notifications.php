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
 * S-ATTENTION-WHATSAPP adds the WhatsApp card at the top: connection
 * (phone number ID, encrypted token + app secret, webhook verify token,
 * template names), the two message templates to create in Meta, "Send me a
 * test", and the delivery log (api/v1/attention/whatsapp_*.php).
 *
 * Rendered inside app/admin/settings/index.php (tab "notifications");
 * saves via api/v1/attention/settings.php.
 *
 * @session S-ATTENTION-INBOX
 */

use FleetForge\Attention\KindRegistry;
use FleetForge\Notifications\WhatsApp\WhatsAppClient;

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

// WhatsApp connection (secrets never leave the server — only "is one saved").
$nsWa = [
    'enabled'           => (string) settings_get('whatsapp.enabled', '0') === '1',
    'status'            => WhatsAppClient::status(),
    'phone_number_id'   => (string) settings_get('whatsapp.phone_number_id', ''),
    'verify_token'      => (string) settings_get('whatsapp.verify_token', ''),
    'api_version'       => (string) settings_get('whatsapp.api_version', 'v23.0'),
    'template_alert'    => (string) settings_get('whatsapp.template_alert', 'fleetforge_alert'),
    'template_summary'  => (string) settings_get('whatsapp.template_summary', 'fleetforge_summary'),
    'template_language' => (string) settings_get('whatsapp.template_language', 'en'),
    'has_access_token'  => WhatsAppClient::secret('whatsapp.access_token') !== '',
    'has_app_secret'    => WhatsAppClient::secret('whatsapp.app_secret') !== '',
    'webhook_url'       => base_url('api/v1/webhooks/whatsapp.php'),
    'my_phone'          => (string) (db_row('SELECT phone_e164 FROM users WHERE id = ?', [current_user_id()])['phone_e164'] ?? ''),
    'opted_in'          => db_count("SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL AND whatsapp_mode <> 'off' AND phone_e164 IS NOT NULL AND phone_e164 <> ''"),
];
$nsTemplates = [
    [
        'name'   => 'fleetforge_alert',
        'use'    => 'Urgent items, escalations and updates people tick (4 variables)',
        'body'   => "FleetForge alert ({{1}}): {{2}}\n\nDetails: {{3}}\n\nOpen it here: {{4}}\n\nYou get these because WhatsApp alerts are on in your FleetForge profile.",
        'sample' => '{{1}} Urgent · {{2}} Credit application to review: Coastline Freight Ltd · {{3}} Signed by Dana Lee · Submitted Thu 25 Sep · {{4}} ' . base_url('notifications'),
    ],
    [
        'name'   => 'fleetforge_summary',
        'use'    => 'The morning summary (5 variables)',
        'body'   => "Good morning from FleetForge. Your list has {{1}} urgent and {{2}} to-do items.\n\nMost urgent: {{3}}\n\nYesterday: {{4}}\n\nSee the full list: {{5}}\n\nYou get this summary because it's on in your FleetForge profile.",
        'sample' => '{{1}} 2 · {{2}} 14 · {{3}} CVI expired: unit STL2021 · {{4}} 4 leases out, 3 leases back, 2 payments received · {{5}} ' . base_url('notifications'),
    ],
];
?>

<!-- ── WhatsApp (S-ATTENTION-WHATSAPP) ─────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;" x-data="FF_WhatsAppSettings(<?= e(json_encode($nsWa, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        WhatsApp
        <span class="badge" :class="wa.status === 'on' ? 'badge-success' : (wa.status === 'incomplete' ? 'badge-warning' : 'badge-neutral')"
              x-text="wa.status === 'on' ? 'On' : (wa.status === 'incomplete' ? 'On, but not fully set up' : 'Off')"></span>
        <span class="text-secondary" style="font-weight:400;font-size:0.8125rem;" x-text="wa.opted_in + (wa.opted_in === 1 ? ' person has' : ' people have') + ' turned it on in their profile'"></span>
    </div>
    <div class="card-body" style="display:grid;gap:16px;font-size:0.875rem;">
        <p class="text-secondary" style="margin:0;max-width:80ch;">
            Staff get <b>urgent items right away</b> and <b>one morning summary</b> on WhatsApp, only if they turn it on with their own number (Profile → Notifications).
            Messages go out from your WhatsApp Business number through Meta's official API, using two message templates Meta approves once.
            Costs about $0.004 USD per message.
        </p>

        <details>
            <summary style="cursor:pointer;font-weight:600;">How to connect (about a day, mostly waiting for Meta)</summary>
            <ol style="margin:10px 0 0;padding-left:20px;display:grid;gap:8px;max-width:80ch;">
                <li>In <b>Meta Business Suite → WhatsApp Manager</b>, add a phone number for FleetForge (one that isn't used in the WhatsApp app) and verify your business.</li>
                <li>Create the two <b>message templates</b> below: category <b>Utility</b>, language <b>English</b>, body text exactly as shown (use the sample values when Meta asks).</li>
                <li>In <b>Meta for Developers → your app → WhatsApp → API setup</b>, copy the <b>Phone number ID</b>. Create a <b>System User</b> with a permanent token (permissions <code>whatsapp_business_messaging</code>, <code>whatsapp_business_management</code>) and paste it below.</li>
                <li>Copy the <b>App secret</b> (App settings → Basic). Under <b>WhatsApp → Configuration</b>, set the webhook: Callback URL and Verify token from below, then subscribe to <b>messages</b>. This powers the delivered / read / failed status in the log.</li>
                <li>Turn WhatsApp on, save, and click <b>Send me a test</b> (your own number must be in your profile).</li>
            </ol>
            <div style="display:grid;gap:12px;margin-top:14px;">
                <?php foreach ($nsTemplates as $t): ?>
                <div style="border:1px solid var(--border-color);border-radius:10px;padding:12px;display:grid;gap:6px;">
                    <div><b><code><?= e($t['name']) ?></code></b> <span class="text-secondary">— <?= e($t['use']) ?></span></div>
                    <pre style="margin:0;white-space:pre-wrap;font-size:0.8125rem;background:var(--bg-surface-2);padding:10px;border-radius:8px;"><?= e($t['body']) ?></pre>
                    <div class="text-secondary" style="font-size:0.75rem;">Samples: <?= e($t['sample']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </details>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px 18px;">
            <label class="form-check" style="display:flex;align-items:center;gap:8px;grid-column:1/-1;">
                <input type="checkbox" x-model="wa.enabled"> <span><b>Send staff WhatsApp messages</b></span>
            </label>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-phone-id">Phone number ID</label>
                <input id="wa-phone-id" class="form-control" inputmode="numeric" x-model.trim="wa.phone_number_id" placeholder="e.g. 106540352242922">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-token">Access token</label>
                <input id="wa-token" type="password" class="form-control" autocomplete="off" x-model="secrets.access_token"
                       :placeholder="wa.has_access_token ? 'Saved (leave blank to keep)' : 'Paste the permanent token'">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-secret">App secret</label>
                <input id="wa-secret" type="password" class="form-control" autocomplete="off" x-model="secrets.app_secret"
                       :placeholder="wa.has_app_secret ? 'Saved (leave blank to keep)' : 'Paste the app secret'">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-verify">Webhook verify token</label>
                <div style="display:flex;gap:6px;">
                    <input id="wa-verify" class="form-control" x-model.trim="wa.verify_token" placeholder="Any random text">
                    <button type="button" class="btn btn-secondary btn-sm" @click="genVerify()">Generate</button>
                </div>
            </div>
            <div class="form-group" style="margin:0;grid-column:1/-1;">
                <label class="form-label" for="wa-hook">Webhook callback URL (paste in Meta)</label>
                <div style="display:flex;gap:6px;">
                    <input id="wa-hook" class="form-control" readonly :value="wa.webhook_url">
                    <button type="button" class="btn btn-secondary btn-sm" @click="copy(wa.webhook_url)">Copy</button>
                </div>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-t-alert">Alert template name</label>
                <input id="wa-t-alert" class="form-control" x-model.trim="wa.template_alert">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-t-summary">Summary template name</label>
                <input id="wa-t-summary" class="form-control" x-model.trim="wa.template_summary">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-lang">Template language</label>
                <input id="wa-lang" class="form-control" x-model.trim="wa.template_language" placeholder="en">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="wa-ver">Graph API version</label>
                <input id="wa-ver" class="form-control" x-model.trim="wa.api_version" placeholder="v23.0">
            </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
            <button type="button" class="btn btn-primary" :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save WhatsApp'"></button>
            <button type="button" class="btn btn-secondary" :disabled="testing || wa.status !== 'on'" @click="test()"
                    :title="wa.status !== 'on' ? 'Turn it on and save the connection first' : ''" x-text="testing ? 'Sending…' : 'Send me a test'"></button>
            <span class="text-sm" :class="error ? 'text-danger' : 'text-secondary'" x-text="error || status"></span>
        </div>
        <p class="text-secondary" style="margin:0;font-size:0.8125rem;" x-show="!wa.my_phone">Your own number isn't in your profile yet, so the test has nowhere to go: <a href="<?= e(base_url('profile')) ?>#notifications">Profile → Notifications</a>.</p>

        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <b>Delivery log</b>
                <button type="button" class="btn btn-ghost btn-sm" @click="loadLog()">Refresh</button>
                <span class="text-secondary" style="font-size:0.8125rem;" x-text="logSummary()"></span>
            </div>
            <div class="table-responsive">
                <table class="table" style="font-size:0.8125rem;">
                    <thead><tr><th>When</th><th>To</th><th>What</th><th>Status</th></tr></thead>
                    <tbody>
                        <template x-for="r in log" :key="r.id">
                            <tr>
                                <td style="white-space:nowrap;" x-text="r.when"></td>
                                <td style="white-space:nowrap;" x-text="r.who + ' ' + r.to"></td>
                                <td><span x-text="r.preview"></span></td>
                                <td>
                                    <span class="badge" :class="{'badge-success': ['sent','delivered','read'].includes(r.status), 'badge-danger': r.status === 'failed', 'badge-neutral': ['queued','sending','skipped'].includes(r.status)}" x-text="r.status"></span>
                                    <div class="text-secondary" x-show="r.error" x-text="r.error" style="margin-top:3px;max-width:44ch;"></div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="logLoaded && log.length === 0"><td colspan="4" class="text-secondary">Nothing sent yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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
 * FF_WhatsAppSettings — the WhatsApp card (S-ATTENTION-WHATSAPP). Secrets are
 * write-only: sent when typed, never read back.
 */
function FF_WhatsAppSettings(init) {
    return {
        wa: init,
        secrets: { access_token: '', app_secret: '' },
        saving: false,
        testing: false,
        status: '',
        error: '',
        log: [],
        counts: {},
        logLoaded: false,

        init() {
            this.loadLog();
        },

        genVerify() {
            const a = new Uint8Array(18);
            (window.crypto || window.msCrypto).getRandomValues(a);
            this.wa.verify_token = 'ff_' + Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
        },

        async copy(text) {
            try { await navigator.clipboard.writeText(text); this.status = 'Copied.'; }
            catch { this.status = 'Select the URL and copy it.'; }
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            this.status = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/attention/whatsapp_settings.php'), {
                    enabled: this.wa.enabled,
                    phone_number_id: this.wa.phone_number_id,
                    verify_token: this.wa.verify_token,
                    api_version: this.wa.api_version,
                    template_alert: this.wa.template_alert,
                    template_summary: this.wa.template_summary,
                    template_language: this.wa.template_language,
                    access_token: this.secrets.access_token,
                    app_secret: this.secrets.app_secret,
                }, { quiet: true });   // errors show inline
                if (res?.success) {
                    this.wa.status = res.data.status;
                    this.wa.has_access_token = res.data.has_access_token;
                    this.wa.has_app_secret = res.data.has_app_secret;
                    this.secrets = { access_token: '', app_secret: '' };
                    this.status = res.data.status === 'on' ? 'Saved. WhatsApp is on.'
                        : (res.data.status === 'incomplete' ? 'Saved. Add the phone number ID and token to finish.' : 'Saved. WhatsApp is off.');
                } else {
                    this.error = res?.error?.message || 'Could not save.';
                }
            } catch {
                this.error = 'Network error. Try again.';
            } finally {
                this.saving = false;
            }
        },

        async test() {
            if (this.testing) return;
            this.testing = true;
            this.error = '';
            this.status = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/attention/whatsapp_test.php'), {}, { quiet: true });
                if (res?.success) {
                    this.status = 'Sent to ' + res.data.to + '. Check your WhatsApp.';
                } else {
                    this.error = res?.error?.message || 'WhatsApp refused the message.';
                }
            } catch {
                this.error = 'Network error. Try again.';
            } finally {
                this.testing = false;
                this.loadLog();
            }
        },

        async loadLog() {
            try {
                const res = await FF_Api.get(FF_Api.url('/api/v1/attention/whatsapp_log.php'));
                if (res?.success) {
                    this.log = res.data.rows || [];
                    this.counts = res.data.counts || {};
                }
            } catch { /* keep */ }
            this.logLoaded = true;
        },

        logSummary() {
            const c = this.counts || {};
            const sent = (c.sent || 0) + (c.delivered || 0) + (c.read || 0);
            return 'Last 7 days: ' + sent + ' sent, ' + (c.failed || 0) + ' failed, ' + (c.queued || 0) + ' waiting';
        },
    };
}

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
                }, { quiet: true });   // errors show inline
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
