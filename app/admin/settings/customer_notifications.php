<?php
declare(strict_types=1);

/**
 * app/admin/settings/customer_notifications.php
 *
 * Settings → Customer Emails tab. The operator control centre for every
 * customer-facing reminder email (config/customer_notifications.php): a global
 * section (master switch, sending window, reply-to / BCC, footer, portal
 * opt-out), one card per reminder type (enable, channels, timing, per-document
 * toggles, subject override, "send me a sample"), a per-reminder audience
 * selector (All / Only selected / All except — with a customer picker), a global
 * do-not-email suppression list, and a delivery log.
 *
 * Rendered inside app/admin/settings/index.php (inherits $canEdit, $csrfToken).
 * Reads current state through CustomerReminders (registry fallback); saves via
 * the API endpoints under /api/v1/admin/customer_notifications/*.
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

use FleetForge\Notifications\CustomerReminders;

// Standalone-safe guard (mirrors system.php / backup.php): only bootstrap when
// hit directly; when included by index.php these are already set.
if (!isset($canEdit)) {
    require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
    require_once FF_ROOT . '/includes/auth.php';
    require_auth();
    require_permission('settings_customer_notifications', 'view');
    $canEdit   = can('settings_customer_notifications', 'edit');
    $csrfToken = generate_csrf_token();
}

// ── Assemble current state for the Alpine model ──────────────────────────────
$cnRegistry   = CustomerReminders::registry();
$cnTypes      = [];
$cnCategories = [];          // ordered category => [keys]
$cnDedupLabel = [];          // dedup_type => human label (for the delivery log)

foreach ($cnRegistry as $key => $meta) {
    if (!CustomerReminders::available($key)) {
        continue;            // e.g. reservations table absent
    }
    $cfg = CustomerReminders::config($key);
    $cat = $cfg['category'];
    $cnCategories[$cat][] = $key;
    $cnDedupLabel[$cfg['dedup_type']] = $cfg['label'];
    $cnTypes[$key] = [
        'key' => $key, 'label' => $cfg['label'], 'category' => $cat,
        'description' => $cfg['description'], 'timing' => $cfg['timing'],
        'supports_docs' => $cfg['supports_docs'],
        'enabled' => $cfg['enabled'], 'channels' => $cfg['channels'],
        'lead_days' => $cfg['lead_days'], 'offset_days' => $cfg['offset_days'],
        'repeat_days' => $cfg['repeat_days'], 'max_count' => $cfg['max_count'],
        'send_day' => $cfg['send_day'], 'audience_mode' => $cfg['audience_mode'],
        'subject' => $cfg['subject'], 'docs' => (object) $cfg['docs'],
    ];
}

$cnGlobal = [
    'master_enabled'        => CustomerReminders::masterEnabled(),
    'respect_portal_optout' => CustomerReminders::respectPortalOptOut(),
    'reply_to'              => CustomerReminders::replyTo(),
    'bcc'                   => (string) CustomerReminders::global('bcc', ''),
    'send_hour'             => CustomerReminders::sendHour(),
    'send_days'             => CustomerReminders::sendDays(),
    'footer_note'           => CustomerReminders::footerNote(),
    'cron_enabled'          => (string) settings_get('cron.customer_reminders_enabled', '1') === '1',
];

// Audience + global suppression (one query; typically small).
$cnAudience   = [];
$cnSuppressed = [];
foreach (array_keys($cnTypes) as $k) {
    $cnAudience[$k] = ['include' => [], 'exclude' => []];
}
try {
    $audRows = db_select(
        "SELECT a.reminder_key, a.mode, a.customer_id, c.company_name
           FROM customer_notification_audience a
           JOIN customers c ON c.id = a.customer_id AND c.deleted_at IS NULL
          ORDER BY c.company_name ASC",
        []
    );
    foreach ($audRows as $r) {
        $entry = ['customer_id' => (int) $r['customer_id'], 'company_name' => (string) $r['company_name']];
        if ((string) $r['reminder_key'] === '*') {
            if ((string) $r['mode'] === 'exclude') { $cnSuppressed[] = $entry; }
        } elseif (isset($cnAudience[$r['reminder_key']])) {
            $cnAudience[$r['reminder_key']][(string) $r['mode'] === 'include' ? 'include' : 'exclude'][] = $entry;
        }
    }
} catch (\Throwable $e) {
    // audience table absent (pre-migration) — leave empty
}

// Recent delivery log (customer reminders only).
$cnLog = [];
try {
    $cnLog = db_select(
        "SELECT created_at, channel, recipient, subject, notification_type, status, error_message
           FROM notification_log
          WHERE notification_type LIKE 'customer_%'
          ORDER BY created_at DESC
          LIMIT 50",
        []
    );
} catch (\Throwable $e) {
    $cnLog = [];
}

$cnState = [
    'canEdit'     => (bool) $canEdit,
    'global'      => $cnGlobal,
    'types'       => (object) $cnTypes,
    'audience'    => (object) $cnAudience,
    'suppressed'  => $cnSuppressed,
];

$cnCategoryOrder = array_keys($cnCategories);
?>
<div x-data="customerNotifications()" class="cn-root">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:8px;">
        <div>
            <h2 style="font-size:1.125rem;font-weight:600;margin:0 0 4px;">Customer Emails &amp; Reminders</h2>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0;max-width:70ch;">
                Automated emails sent to your customers. Every reminder ships <strong>off</strong> — turn on
                only what you want. Three switches must all be on for a reminder to send: the master switch
                below, the reminder's own toggle, and the Scheduled Jobs dispatcher.
            </p>
        </div>
        <div x-show="dirty" x-cloak style="flex-shrink:0;">
            <span style="font-size:0.75rem;color:var(--color-warning,#b45309);">Unsaved changes</span>
        </div>
    </div>

    <!-- ── GLOBAL SETTINGS ─────────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="font-weight:600;">Global settings</div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px 24px;">

                <div class="form-group" style="margin-bottom:0;">
                    <div class="form-check">
                        <input type="checkbox" id="cn_master" x-model="global.master_enabled" :disabled="!canEdit" @change="dirty=true">
                        <label for="cn_master" style="margin-left:6px;font-weight:600;">Send customer emails (master switch)</label>
                    </div>
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0 22px;">Turn off to instantly pause every customer reminder.</p>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="form-check">
                        <input type="checkbox" id="cn_cron" x-model="global.cron_enabled" :disabled="!canEdit" @change="dirty=true">
                        <label for="cn_cron" style="margin-left:6px;font-weight:600;">Run the reminder dispatcher</label>
                    </div>
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0 22px;">Scheduled Jobs cron that actually sends the scheduled reminders.</p>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="form-check">
                        <input type="checkbox" id="cn_optout" x-model="global.respect_portal_optout" :disabled="!canEdit" @change="dirty=true">
                        <label for="cn_optout" style="margin-left:6px;">Honor portal opt-outs</label>
                    </div>
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0 22px;">Skip customers who opted out in their portal preferences.</p>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="cn_send_hour">Send hour (0–23, your timezone)</label>
                    <input type="number" min="0" max="23" id="cn_send_hour" class="form-control" x-model.number="global.send_hour" :disabled="!canEdit" @input="dirty=true">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Sending days</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px 12px;">
                        <template x-for="d in weekdays" :key="d.v">
                            <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;">
                                <input type="checkbox" :value="d.v" :checked="global.send_days.includes(d.v)" @change="toggleDay(d.v)" :disabled="!canEdit">
                                <span x-text="d.l"></span>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="cn_reply">Reply-to address</label>
                    <input type="email" id="cn_reply" class="form-control" x-model="global.reply_to" placeholder="replies@yourcompany.com" :disabled="!canEdit" @input="dirty=true">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="cn_bcc">BCC (send yourself a copy)</label>
                    <input type="text" id="cn_bcc" class="form-control" x-model="global.bcc" placeholder="ops@yourcompany.com" :disabled="!canEdit" @input="dirty=true">
                    <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0;">Comma-separated. BCC'd on every customer reminder.</p>
                </div>

                <div class="form-group" style="margin-bottom:0;grid-column:1/-1;">
                    <label class="form-label" for="cn_footer">Email footer note (optional)</label>
                    <textarea id="cn_footer" class="form-control" rows="2" x-model="global.footer_note" :disabled="!canEdit" @input="dirty=true" placeholder="e.g. Questions? Call us at 555-0100."></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- ── GLOBAL DO-NOT-EMAIL SUPPRESSION ─────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="font-weight:600;">Do-not-email list</div>
        <div class="card-body">
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 12px;">
                Customers here never receive <em>any</em> reminder, regardless of the settings below.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <template x-for="c in suppressed" :key="c.customer_id">
                    <span class="cn-chip">
                        <span x-text="c.company_name"></span>
                        <button type="button" class="cn-chip-x" @click="removeSuppress(c.customer_id)" x-show="canEdit" aria-label="Remove">✕</button>
                    </span>
                </template>
                <span x-show="suppressed.length === 0" style="font-size:0.8125rem;color:var(--text-muted);">No customers suppressed.</span>
            </div>
            <?php if ($canEdit): ?>
            <div style="max-width:420px;">
                <?php
                $pickerName      = 'cn_suppress_picker';
                $pickerConfig    = [
                    'endpoint'    => '/api/v1/customers/index.php',
                    'searchParam' => 'search',
                    'resultKey'   => 'items',
                    'perPage'     => 10,
                    'placeholder' => 'Add a customer to the do-not-email list…',
                    'mapResult'   => "r => ({ id: r.id, label: r.company_name + (r.contact_name ? ' (' + r.contact_name + ')' : ''), sublabel: [r.city, r.province].filter(Boolean).join(', '), raw: r })",
                ];
                $pickerOnPicked  = "addSuppress(\$event.detail); clear()";
                $pickerOnCleared = '';
                require FF_ROOT . '/includes/partials/record-picker.php';
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── PER-REMINDER CARDS ──────────────────────────────────────────────── -->
    <?php foreach ($cnCategoryOrder as $cat): ?>
    <div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin:20px 0 10px;">
        <?= e($cat) ?>
    </div>

    <?php foreach ($cnCategories[$cat] as $key): $t = $cnTypes[$key]; ?>
    <div class="card" style="margin-bottom:16px;" x-data="{ k: '<?= e($key) ?>' }">
        <div class="card-body">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                <div style="flex:1;min-width:0;">
                    <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" x-model="types[k].enabled" :disabled="!canEdit" @change="dirty=true">
                        <span style="font-size:1rem;font-weight:600;"><?= e($t['label']) ?></span>
                        <span class="badge" :class="types[k].enabled ? 'badge-success' : 'badge-neutral'"
                              style="font-size:0.68rem;" x-text="types[k].enabled ? 'On' : 'Off'"></span>
                    </label>
                    <p class="text-muted" style="font-size:0.78rem;margin:6px 0 0;"><?= e($t['description']) ?></p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" style="flex-shrink:0;" @click="testSend(k)" :disabled="testing===k">
                    <span x-show="testing!==k">Send me a sample</span>
                    <span x-show="testing===k" x-cloak>Sending…</span>
                </button>
            </div>

            <!-- Details, shown when enabled -->
            <div x-show="types[k].enabled" x-cloak style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-default);">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px 24px;">

                    <!-- Channels -->
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Channels</label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px 14px;">
                            <template x-for="ch in channelOptions" :key="ch.v">
                                <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;">
                                    <input type="checkbox" :checked="types[k].channels.includes(ch.v)" @change="toggleChannel(k, ch.v)" :disabled="!canEdit">
                                    <span x-text="ch.l"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    <!-- Timing: before (lead) -->
                    <?php if ($t['timing'] === 'before'): ?>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Days before</label>
                        <input type="number" min="0" max="365" class="form-control" x-model.number="types[k].lead_days" :disabled="!canEdit" @input="dirty=true">
                        <p class="text-muted" style="font-size:0.72rem;margin:4px 0 0;">Send this many days before the date.</p>
                    </div>
                    <?php endif; ?>

                    <!-- Timing: after (overdue cadence) -->
                    <?php if ($t['timing'] === 'after'): ?>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">First reminder (days past due)</label>
                        <input type="number" min="0" max="365" class="form-control" x-model.number="types[k].offset_days" :disabled="!canEdit" @input="dirty=true">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Repeat every (days)</label>
                        <input type="number" min="1" max="365" class="form-control" x-model.number="types[k].repeat_days" :disabled="!canEdit" @input="dirty=true">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Max reminders</label>
                        <input type="number" min="1" max="20" class="form-control" x-model.number="types[k].max_count" :disabled="!canEdit" @input="dirty=true">
                    </div>
                    <?php endif; ?>

                    <!-- Timing: scheduled (statement day) -->
                    <?php if ($t['timing'] === 'scheduled'): ?>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Day of month</label>
                        <input type="number" min="1" max="28" class="form-control" x-model.number="types[k].send_day" :disabled="!canEdit" @input="dirty=true">
                    </div>
                    <?php endif; ?>

                    <!-- Subject override -->
                    <div class="form-group" style="margin-bottom:0;grid-column:1/-1;">
                        <label class="form-label">Subject line (leave blank for the default)</label>
                        <input type="text" class="form-control" x-model="types[k].subject" maxlength="300" :disabled="!canEdit" @input="dirty=true" placeholder="Use the standard subject">
                    </div>
                </div>

                <!-- Per-document toggles (compliance) -->
                <?php if ($t['supports_docs']): ?>
                <div style="margin-top:14px;">
                    <label class="form-label">Documents to include</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px 16px;">
                        <?php foreach (array_keys((array) $t['docs']) as $docSlug): ?>
                        <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;text-transform:uppercase;">
                            <input type="checkbox" x-model="types[k].docs['<?= e($docSlug) ?>']" :disabled="!canEdit" @change="dirty=true">
                            <span><?= e($docSlug) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted" style="font-size:0.72rem;margin:6px 0 0;">Untick a document to stop emailing customers about it (e.g. insurance).</p>
                </div>
                <?php endif; ?>

                <!-- Audience -->
                <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border-default);">
                    <label class="form-label">Which customers?</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:10px;">
                        <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:5px;">
                            <input type="radio" value="all" x-model="types[k].audience_mode" :disabled="!canEdit" @change="dirty=true"> All customers
                        </label>
                        <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:5px;">
                            <input type="radio" value="selected" x-model="types[k].audience_mode" :disabled="!canEdit" @change="dirty=true"> Only selected
                        </label>
                        <label style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:5px;">
                            <input type="radio" value="all_except" x-model="types[k].audience_mode" :disabled="!canEdit" @change="dirty=true"> All except selected
                        </label>
                    </div>

                    <div x-show="types[k].audience_mode !== 'all'" x-cloak>
                        <p class="text-muted" style="font-size:0.72rem;margin:0 0 8px;">
                            <span x-show="types[k].audience_mode === 'selected'">Only these customers will receive this reminder:</span>
                            <span x-show="types[k].audience_mode === 'all_except'">Everyone receives this reminder except:</span>
                        </p>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                            <template x-for="c in currentAudienceList(k)" :key="c.customer_id">
                                <span class="cn-chip">
                                    <span x-text="c.company_name"></span>
                                    <button type="button" class="cn-chip-x" @click="removeAudience(k, c.customer_id)" x-show="canEdit" aria-label="Remove">✕</button>
                                </span>
                            </template>
                            <span x-show="currentAudienceList(k).length === 0" style="font-size:0.8125rem;color:var(--text-muted);">No customers selected yet.</span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div style="max-width:420px;">
                            <?php
                            $pickerName      = 'cn_aud_picker_' . $key;
                            $pickerConfig    = [
                                'endpoint'    => '/api/v1/customers/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'placeholder' => 'Add a customer…',
                                'mapResult'   => "r => ({ id: r.id, label: r.company_name + (r.contact_name ? ' (' + r.contact_name + ')' : ''), sublabel: [r.city, r.province].filter(Boolean).join(', '), raw: r })",
                            ];
                            $pickerOnPicked  = "addAudience('" . e($key) . "', \$event.detail); clear()";
                            $pickerOnCleared = '';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- ── SAVE BAR ────────────────────────────────────────────────────────── -->
    <?php if ($canEdit): ?>
    <div style="position:sticky;bottom:0;background:var(--surface-1,var(--bg-primary,#fff));padding:14px 0;margin-top:8px;border-top:1px solid var(--border-default);display:flex;align-items:center;gap:12px;">
        <button type="button" class="btn btn-primary" @click="save()" :disabled="saving">
            <span x-show="!saving">Save Customer Email Settings</span>
            <span x-show="saving" x-cloak>Saving…</span>
        </button>
        <span x-show="savedMsg" x-cloak x-text="savedMsg" style="font-size:0.8125rem;color:var(--color-success,#15803d);"></span>
    </div>
    <?php endif; ?>

    <!-- ── DELIVERY LOG ────────────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px;">
        <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
            Recent deliveries
            <span class="badge badge-info" style="font-size:0.68rem;">Last <?= count($cnLog) ?></span>
        </div>
        <div class="card-body" style="overflow-x:auto;">
            <?php if (empty($cnLog)): ?>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0;">No customer reminder emails have been sent yet.</p>
            <?php else: ?>
            <table class="data-table" style="width:100%;font-size:0.8125rem;">
                <thead><tr>
                    <th style="text-align:left;">When</th>
                    <th style="text-align:left;">Reminder</th>
                    <th style="text-align:left;">Channel</th>
                    <th style="text-align:left;">Recipient</th>
                    <th style="text-align:left;">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($cnLog as $row):
                    $label = $cnDedupLabel[$row['notification_type']] ?? $row['notification_type'];
                    $st = (string) $row['status'];
                    $stClass = match ($st) { 'sent','delivered' => 'badge-success', 'failed','bounced' => 'badge-danger', 'skipped' => 'badge-neutral', default => 'badge-info' };
                ?>
                    <tr>
                        <td style="white-space:nowrap;color:var(--text-muted);"><?= e(format_datetime($row['created_at'])) ?></td>
                        <td><?= e($label) ?></td>
                        <td><?= e($row['channel']) ?></td>
                        <td style="font-family:monospace;"><?= e($row['recipient']) ?></td>
                        <td><span class="badge <?= $stClass ?>" style="font-size:0.68rem;"><?= e($st) ?></span>
                            <?php if (!empty($row['error_message'])): ?>
                            <span title="<?= e($row['error_message']) ?>" style="cursor:help;color:var(--text-muted);">ⓘ</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.cn-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 8px 3px 10px;background:var(--surface-2,#f3f4f6);border:1px solid var(--border-default,#e5e7eb);border-radius:14px;font-size:0.78rem;}
.cn-chip-x{border:0;background:none;cursor:pointer;color:var(--text-muted,#6b7280);font-size:0.72rem;line-height:1;padding:0;}
.cn-chip-x:hover{color:var(--color-danger,#dc2626);}
</style>

<script>
function customerNotifications() {
    return {
        canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        global: <?= json_encode($cnState['global'], JSON_UNESCAPED_SLASHES) ?>,
        types: <?= json_encode($cnState['types'], JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
        audience: <?= json_encode($cnState['audience'], JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
        suppressed: <?= json_encode($cnState['suppressed'], JSON_UNESCAPED_SLASHES) ?>,
        weekdays: [{v:'mon',l:'Mon'},{v:'tue',l:'Tue'},{v:'wed',l:'Wed'},{v:'thu',l:'Thu'},{v:'fri',l:'Fri'},{v:'sat',l:'Sat'},{v:'sun',l:'Sun'}],
        channelOptions: [{v:'email',l:'Email'},{v:'in_app',l:'Portal notification'},{v:'sms',l:'SMS'}],
        dirty: false, saving: false, savedMsg: '', testing: null,

        init() {
            // Ensure every type has arrays/objects Alpine can bind to.
            Object.values(this.types).forEach(t => {
                if (!Array.isArray(t.channels)) t.channels = ['email'];
                if (!t.docs || typeof t.docs !== 'object') t.docs = {};
            });
            if (!Array.isArray(this.global.send_days)) this.global.send_days = [];
        },

        toggleDay(d) {
            const i = this.global.send_days.indexOf(d);
            if (i === -1) this.global.send_days.push(d); else this.global.send_days.splice(i, 1);
            this.dirty = true;
        },
        toggleChannel(k, ch) {
            const arr = this.types[k].channels;
            const i = arr.indexOf(ch);
            if (i === -1) arr.push(ch); else arr.splice(i, 1);
            if (arr.length === 0) arr.push('email'); // never allow zero channels
            this.dirty = true;
        },
        currentAudienceList(k) {
            const mode = this.types[k].audience_mode;
            if (!this.audience[k]) return [];
            return mode === 'selected' ? this.audience[k].include : this.audience[k].exclude;
        },

        async save() {
            this.saving = true; this.savedMsg = '';
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/save.php'), {
                global: this.global, types: this.types,
            });
            this.saving = false;
            if (r.success) {
                this.dirty = false; this.savedMsg = 'Saved.';
                FF_Toast && FF_Toast.success('Saved', 'Customer email settings updated.');
                setTimeout(() => { this.savedMsg = ''; }, 4000);
            } else {
                FF_Toast && FF_Toast.error('Save failed', (r.error && r.error.message) || 'Could not save.');
            }
        },

        async addAudience(k, detail) {
            const mode = this.types[k].audience_mode === 'selected' ? 'include' : 'exclude';
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/audience.php'), {
                action: 'add', reminder_key: k, customer_id: detail.id, mode,
            });
            if (r.success) {
                if (!this.audience[k]) this.audience[k] = { include: [], exclude: [] };
                const list = this.audience[k][mode];
                if (!list.some(c => c.customer_id === r.data.customer_id)) {
                    list.push({ customer_id: r.data.customer_id, company_name: r.data.company_name });
                }
            } else {
                FF_Toast && FF_Toast.error('Failed', (r.error && r.error.message) || 'Could not add customer.');
            }
        },
        async removeAudience(k, cid) {
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/audience.php'), {
                action: 'remove', reminder_key: k, customer_id: cid,
            });
            if (r.success) {
                ['include', 'exclude'].forEach(m => {
                    if (this.audience[k]) this.audience[k][m] = this.audience[k][m].filter(c => c.customer_id !== cid);
                });
            }
        },
        async addSuppress(detail) {
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/audience.php'), {
                action: 'add', reminder_key: '*', customer_id: detail.id, mode: 'exclude',
            });
            if (r.success && !this.suppressed.some(c => c.customer_id === r.data.customer_id)) {
                this.suppressed.push({ customer_id: r.data.customer_id, company_name: r.data.company_name });
            }
        },
        async removeSuppress(cid) {
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/audience.php'), {
                action: 'remove', reminder_key: '*', customer_id: cid,
            });
            if (r.success) this.suppressed = this.suppressed.filter(c => c.customer_id !== cid);
        },

        async testSend(k) {
            this.testing = k;
            const r = await FF_Api.post(FF_Api.url('/api/v1/admin/customer_notifications/test_send.php'), { reminder_key: k });
            this.testing = null;
            if (r.success) {
                FF_Toast && FF_Toast.success('Sample sent', 'Check ' + r.data.sent_to + ' (dev mode logs to logs/mail.log).');
            } else {
                FF_Toast && FF_Toast.error('Test failed', (r.error && r.error.message) || 'Could not send sample.');
            }
        },
    };
}
</script>
