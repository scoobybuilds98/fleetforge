<?php
declare(strict_types=1);

/**
 * app/admin/profile/index.php
 *
 * My Profile page — allows any authenticated user to view and edit their own
 * profile details, change their password, and review their login history.
 *
 * Layout:
 *   Left column (wide)  — Tabbed card:
 *     Tab 1 (Profile)       — view/edit name, email, phone, timezone with D19 optimistic lock
 *     Tab 2 (Login History) — last 20 login attempts from audit_log (server-rendered)
 *   Right column (~280px) — two stacked cards:
 *     Card 1 — Change Password (Alpine.js, posts to api/v1/users/change_password.php)
 *     Card 2 — Account Info (read-only: role, status, member since, last login)
 *
 * Permission: require_auth() only — no module permission needed.
 *
 * D5:  deleted_at IS NULL guard.
 * D7:  dirname(__DIR__, 3) for project root.
 * D19: optimistic lock (updated_at) on profile edit.
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, api/v1/users/update.php,
 *           api/v1/users/change_password.php
 * @session  S017-B
 */

// dirname(__DIR__, 3): app/admin/profile/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

// WHY: any logged-in user can view/edit their own profile
require_auth();

// ── Load current user data ────────────────────────────────────────────────────
$me = db_row(
    "SELECT u.id, u.name, u.email, u.status, u.phone, u.timezone,
            u.theme_preference, u.last_login_at, u.last_login_ip,
            u.display_font_size, u.display_density,
            u.mfa_enabled, u.mfa_required, u.mfa_enabled_at,
            u.notification_preferences,
            u.phone_e164, u.whatsapp_mode, u.whatsapp_summary_hour, u.whatsapp_quiet_start,
            u.whatsapp_quiet_end, u.whatsapp_updates,
            u.created_at, u.updated_at,
            ur.id AS role_id, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [current_user_id()]
);

if (!$me) {
    // WHY: should never happen for a valid session, but redirect defensively
    header('Location: ' . base_url('auth/logout'));
    exit;
}

// ── Load last 20 login history entries from audit_log ────────────────────────
$loginHistory = db_select(
    "SELECT created_at, ip_address, user_agent, notes
     FROM audit_log
     WHERE action = 'login' AND user_id = ?
     ORDER BY created_at DESC
     LIMIT 20",
    [current_user_id()]
);

// ── MFA status ────────────────────────────────────────────────────────────────
use FleetForge\Auth\MfaService;
$mfaEnabled       = (bool) ($me['mfa_enabled'] ?? false);
$mfaRequired      = (bool) ($me['mfa_required'] ?? false);
$unusedBackupCount = $mfaEnabled ? MfaService::countUnusedBackupCodes(current_user_id()) : 0;

// ── Status badge helper ───────────────────────────────────────────────────────
$statusBadgeClass = match($me['status']) {
    'active'    => 'badge-success',
    'inactive'  => 'badge-neutral',
    'invited'   => 'badge-info',
    'suspended' => 'badge-danger',
    'locked'    => 'badge-danger',
    default     => 'badge-neutral',
};

// ── S-ATTENTION-INBOX: My notifications tab data ─────────────────────────────
// Update categories a person can switch off for themselves (stored as the
// opted-OUT list in users.notification_preferences, NULL = everything).
// Needs attention isn't switchable per person: it's the team's shared list,
// and which kinds each role sees is set in Settings → Notifications.
$myNotifCategories = [
    'leases' => 'Leases', 'invoices' => 'Invoices', 'payments' => 'Payments',
    'customers' => 'Customers', 'equipment' => 'Equipment', 'maintenance' => 'Maintenance',
    'damage' => 'Damage claims', 'reservations' => 'Reservations', 'samsara' => 'GPS / Samsara',
    'accounting' => 'Accounting', 'quickbooks' => 'QuickBooks', 'system' => 'System',
];
$myOptedOut = json_decode((string) ($me['notification_preferences'] ?? ''), true);
$myOptedOut = is_array($myOptedOut) ? array_values(array_intersect($myOptedOut, array_keys($myNotifCategories))) : [];
$myAttentionKinds = [];
foreach (\FleetForge\Attention\KindRegistry::kindsForRole((string) $me['role_slug']) as $_k) {
    $myAttentionKinds[] = \FleetForge\Attention\KindRegistry::get($_k)?->label() ?? $_k;
}
unset($_k);

// S-ATTENTION-WHATSAPP: this person's WhatsApp choices (their own opt-in).
$myWhatsApp = [
    'connected'    => \FleetForge\Notifications\WhatsApp\WhatsAppClient::configured(),
    'phone'        => (string) ($me['phone_e164'] ?? ''),
    'mode'         => (string) ($me['whatsapp_mode'] ?? 'off'),
    'summary_hour' => $me['whatsapp_summary_hour'] === null ? 7 : (int) $me['whatsapp_summary_hour'],
    'quiet_start'  => $me['whatsapp_quiet_start'] === null ? 21 : (int) $me['whatsapp_quiet_start'],
    'quiet_end'    => $me['whatsapp_quiet_end'] === null ? 7 : (int) $me['whatsapp_quiet_end'],
    'updates'      => json_decode((string) ($me['whatsapp_updates'] ?? ''), true) ?: [],
    'choices'      => \FleetForge\Notifications\WhatsApp\WhatsAppDeliveries::UPDATE_CHOICES,
];

$pageTitle = 'My Profile';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">My Profile</h1>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;" class="profile-layout">

<!-- ══════════════════════════════════════════════════════════════
     LEFT COLUMN — tabbed card (Profile + Login History)
     ══════════════════════════════════════════════════════════════ -->
<div x-data="profilePage()">

    <!-- ── Tab bar ──────────────────────────────────────────────── -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': tab === 'profile' }"
                @click="tab = 'profile'"
                :aria-selected="tab === 'profile'" role="tab">
            Profile
        </button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'display' }"
                @click="tab = 'display'"
                :aria-selected="tab === 'display'" role="tab">
            Display
        </button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'notifications' }"
                @click="tab = 'notifications'"
                :aria-selected="tab === 'notifications'" role="tab">
            Notifications
        </button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'login_history' }"
                @click="tab = 'login_history'"
                :aria-selected="tab === 'login_history'" role="tab">
            Login History
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB 1 — Profile (view + edit)
         ══════════════════════════════════════════════════════ -->
    <template x-if="tab === 'profile'">
        <div class="card ff-tab-animated">

            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:600;">Profile Details</span>
                <button class="btn btn-secondary btn-sm"
                        x-show="!editMode"
                        @click="showEdit()">Edit</button>
            </div>

            <!-- Error banner (edit mode) -->
            <template x-if="editError">
                <div class="toast toast-danger"
                     style="position:relative;margin:0 16px 0;animation:none;">
                    <span class="toast-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                    </span>
                    <div class="toast-body"><div class="toast-message" x-text="editError"></div></div>
                </div>
            </template>

            <!-- VIEW MODE -->
            <div x-show="!editMode" class="card-body">
                <dl class="detail-grid">
                    <dt>Name</dt>
                    <dd><?= e($me['name']) ?></dd>

                    <dt>Email</dt>
                    <dd><a href="mailto:<?= e($me['email']) ?>"><?= e($me['email']) ?></a></dd>

                    <dt>Phone</dt>
                    <dd><?= $me['phone'] ? e($me['phone']) : '—' ?></dd>

                    <dt>Timezone</dt>
                    <dd><?= $me['timezone'] ? e($me['timezone']) : '—' ?></dd>
                </dl>
            </div>

            <!-- EDIT MODE -->
            <div x-show="editMode" class="card-body">
                <form @submit.prevent="saveProfile()">

                    <!-- D19 optimistic lock token -->
                    <input type="hidden" x-ref="updatedAt" value="<?= e($me['updated_at']) ?>">

                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" class="form-control" x-model="form.name"
                               maxlength="255" placeholder="Jane Smith">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" class="form-control" x-model="form.email"
                               maxlength="255">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" x-model="form.phone"
                               maxlength="50" placeholder="+1 604 555 0123">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Timezone</label>
                        <input type="text" class="form-control" x-model="form.timezone"
                               maxlength="100" placeholder="America/Vancouver">
                    </div>

                    <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                        <button type="submit" class="btn btn-primary"
                                :disabled="saving">
                            <span x-show="!saving">Save Changes</span>
                            <span x-show="saving">Saving…</span>
                        </button>
                        <button type="button" class="btn btn-secondary"
                                @click="cancelEdit()">Cancel</button>
                    </div>
                </form>
            </div>

        </div>
    </template>

    <!-- ══════════════════════════════════════════════════════
         TAB 2 — Display Settings (PERM-1 Feature 2)
         Lets the user set their personal main-content font size
         and density. Changes apply immediately to the live page
         (via FF_Display) and persist to the DB.
         ══════════════════════════════════════════════════════ -->
    <template x-if="tab === 'display'">
        <div class="card ff-tab-animated" x-data="displaySettingsTab()">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:600;">Display Settings</span>
                <button class="btn btn-secondary btn-sm"
                        @click="reset()"
                        :disabled="saving || (fontSize === 100 && density === 'comfortable')">
                    Reset to default
                </button>
            </div>
            <div class="card-body">

                <p style="margin:0 0 18px;font-size:0.875rem;color:var(--text-secondary);">
                    These settings affect the <strong>main content area only</strong>.
                    The sidebar, top bar, and modals stay at their default size for layout consistency.
                </p>

                <!-- ── Font size ──────────────────────────────────── -->
                <div class="form-group">
                    <label class="form-label" for="display-font-size-slider">
                        Text size
                        <span class="text-muted" style="font-weight:400;margin-left:6px;"
                              x-text="fontSize + '%'"></span>
                    </label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button"
                                class="btn btn-secondary btn-sm"
                                @click="decFont()"
                                :disabled="saving || fontSize <= 70"
                                aria-label="Decrease text size">
                            A−
                        </button>
                        <input type="range"
                               id="display-font-size-slider"
                               min="70" max="130" step="5"
                               x-model.number="fontSize"
                               @change="applyFont()"
                               :disabled="saving"
                               style="flex:1;">
                        <button type="button"
                                class="btn btn-secondary btn-sm"
                                @click="incFont()"
                                :disabled="saving || fontSize >= 130"
                                aria-label="Increase text size">
                            A+
                        </button>
                    </div>
                    <div class="text-muted" style="font-size:0.75rem;margin-top:6px;">
                        70% – 130%, in 5% steps
                    </div>
                </div>

                <!-- ── Density ────────────────────────────────────── -->
                <div class="form-group">
                    <label class="form-label">Density</label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="display-density-option">
                            <input type="radio" name="display-density" value="compact"
                                   x-model="density" @change="applyDensity()"
                                   :disabled="saving">
                            <span class="display-density-label">
                                <strong>Compact</strong>
                                <span class="text-muted">Tighter padding, more content per screen.</span>
                            </span>
                        </label>
                        <label class="display-density-option">
                            <input type="radio" name="display-density" value="comfortable"
                                   x-model="density" @change="applyDensity()"
                                   :disabled="saving">
                            <span class="display-density-label">
                                <strong>Comfortable</strong>
                                <span class="text-muted">Balanced spacing — the default.</span>
                            </span>
                        </label>
                        <label class="display-density-option">
                            <input type="radio" name="display-density" value="spacious"
                                   x-model="density" @change="applyDensity()"
                                   :disabled="saving">
                            <span class="display-density-label">
                                <strong>Spacious</strong>
                                <span class="text-muted">Looser padding, easier to scan.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- ── Live preview ───────────────────────────────── -->
                <div class="form-group" style="margin-top:8px;">
                    <label class="form-label">Live preview</label>
                    <div class="card" style="margin:0;">
                        <div class="card-header">
                            <span style="font-weight:600;">Preview Card</span>
                        </div>
                        <div class="card-body">
                            <p style="margin:0 0 10px;">
                                This block updates in real time as you change the
                                settings above. Use the topbar quick controls to
                                make changes from any page.
                            </p>
                            <div class="table-responsive">
<table class="table table-sm">
                                <thead>
                                    <tr><th>Item</th><th>Quantity</th><th>Total</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Sample row 1</td><td>3</td><td>$120.00</td></tr>
                                    <tr><td>Sample row 2</td><td>1</td><td>$45.00</td></tr>
                                </tbody>
                            </table>
</div>
                        </div>
                    </div>
                </div>

                <div x-show="saveError"
                     x-text="saveError"
                     style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;"></div>
            </div>
        </div>
    </template>

    <!-- ══════════════════════════════════════════════════════
         TAB — My notifications (S-ATTENTION-INBOX)
         ══════════════════════════════════════════════════════ -->
    <template x-if="tab === 'notifications'">
        <div class="card ff-tab-animated" x-data="FF_MyNotifications(<?= e(json_encode(['categories' => $myNotifCategories, 'opted_out' => $myOptedOut, 'wa' => $myWhatsApp])) ?>)">
            <div class="card-header"><span style="font-weight:600;">My notifications</span></div>
            <div class="card-body" style="display:grid;gap:22px;font-size:0.875rem;">
                <section>
                    <h3 style="font-size:0.9375rem;margin:0 0 4px;">Needs attention</h3>
                    <p class="text-secondary" style="margin:0 0 8px;">
                        The team's shared list: one item per problem, until it's fixed. It's what the number on the bell counts.
                        <?= e((string) $me['role_name']) ?> accounts see:
                    </p>
                    <?php if ($myAttentionKinds): ?>
                    <div class="att-pills">
                        <?php foreach ($myAttentionKinds as $_label): ?><span class="att-pill"><?= e($_label) ?></span><?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-secondary" style="margin:0;">Nothing yet. Only items you're named on (for example a customer request routed to you).</p>
                    <?php endif; ?>
                    <p class="text-secondary" style="margin:8px 0 0;font-size:0.8125rem;">Your super admin chooses this for each role in Settings → Notifications.</p>
                </section>
                <section>
                    <h3 style="font-size:0.9375rem;margin:0 0 4px;">Updates</h3>
                    <p class="text-secondary" style="margin:0 0 10px;">Things that happened, for your information. They never add to the bell's number. Untick what you don't want.</p>
                    <div class="att-roles">
                        <template x-for="(label, slug) in categories" :key="slug">
                            <label><input type="checkbox" :checked="!optedOut.includes(slug)" @change="toggle(slug, $event.target.checked)"> <span x-text="label"></span></label>
                        </template>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:12px;">
                        <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save'"></button>
                        <span class="text-sm" :class="error ? 'text-danger' : 'text-secondary'" x-text="error || status"></span>
                    </div>
                </section>
                <section>
                    <h3 style="font-size:0.9375rem;margin:0 0 4px;">WhatsApp</h3>
                    <p class="text-secondary" style="margin:0 0 10px;">Get urgent items and a morning summary on your phone. Only you can turn this on for your number.</p>
                    <p class="text-secondary" style="margin:0 0 10px;font-size:0.8125rem;" x-show="!wa.connected">WhatsApp isn't connected yet (your super admin sets it up in Settings → Notifications). You can save your choices now; they apply once it's on.</p>
                    <div style="display:grid;gap:12px;max-width:560px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="wa-my-phone">WhatsApp number</label>
                            <input id="wa-my-phone" class="form-control" inputmode="tel" autocomplete="tel" placeholder="+1 604 555 0142" x-model.trim="wa.phone">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <span class="form-label">Send me on WhatsApp</span>
                            <div class="att-filters" style="padding:0;">
                                <button type="button" class="att-chip" :aria-pressed="wa.mode === 'off'" @click="wa.mode = 'off'">Nothing</button>
                                <button type="button" class="att-chip" :aria-pressed="wa.mode === 'summary'" @click="wa.mode = 'summary'">Morning summary only</button>
                                <button type="button" class="att-chip" :aria-pressed="wa.mode === 'urgent'" @click="wa.mode = 'urgent'">Urgent items + morning summary</button>
                            </div>
                        </div>
                        <template x-if="wa.mode !== 'off'">
                            <div style="display:grid;gap:12px;">
                                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
                                    <label class="form-group" style="margin:0;">
                                        <span class="form-label">Morning summary at</span>
                                        <select class="form-select form-control-sm" x-model.number="wa.summary_hour">
                                            <template x-for="h in hours" :key="'s' + h"><option :value="h" x-text="hourLabel(h)" :selected="h === wa.summary_hour"></option></template>
                                        </select>
                                    </label>
                                    <label class="form-group" style="margin:0;">
                                        <span class="form-label">Quiet from</span>
                                        <select class="form-select form-control-sm" x-model.number="wa.quiet_start">
                                            <template x-for="h in hours" :key="'q' + h"><option :value="h" x-text="hourLabel(h)" :selected="h === wa.quiet_start"></option></template>
                                        </select>
                                    </label>
                                    <label class="form-group" style="margin:0;">
                                        <span class="form-label">until</span>
                                        <select class="form-select form-control-sm" x-model.number="wa.quiet_end">
                                            <template x-for="h in hours" :key="'e' + h"><option :value="h" x-text="hourLabel(h)" :selected="h === wa.quiet_end"></option></template>
                                        </select>
                                    </label>
                                </div>
                                <p class="text-secondary" style="margin:0;font-size:0.8125rem;">Anything due in quiet hours waits until they end. Times are in your profile's time zone.</p>
                                <div>
                                    <span class="form-label">Also send me right away</span>
                                    <div class="att-roles">
                                        <template x-for="(label, type) in wa.choices" :key="type">
                                            <label><input type="checkbox" :checked="wa.updates.includes(type)" @change="toggleUpdate(type, $event.target.checked)"> <span x-text="label"></span></label>
                                        </template>
                                    </div>
                                </div>
                                <p class="text-secondary" style="margin:0;font-size:0.8125rem;">By turning this on you agree to receive FleetForge work messages on WhatsApp at this number. Turn it off any time here.</p>
                            </div>
                        </template>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
                            <button type="button" class="btn btn-primary btn-sm" :disabled="waSaving" @click="saveWhatsApp()" x-text="waSaving ? 'Saving…' : 'Save WhatsApp'"></button>
                            <button type="button" class="btn btn-secondary btn-sm" x-show="wa.connected && wa.mode !== 'off'" :disabled="waSaving" @click="summaryNow()">Send me today's summary</button>
                            <span class="text-sm" :class="waError ? 'text-danger' : 'text-secondary'" x-text="waError || waStatus"></span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </template>

    <!-- ══════════════════════════════════════════════════════
         TAB 3 — Login History (server-rendered, read-only)
         ══════════════════════════════════════════════════════ -->
    <template x-if="tab === 'login_history'">
        <div class="card ff-tab-animated">
            <div class="card-header">
                <span style="font-weight:600;">Login History</span>
                <span class="text-muted" style="font-size:0.8125rem;margin-left:8px;">Last 20 attempts</span>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($loginHistory)): ?>
                <div class="empty-state" style="padding:32px;">
                    <div class="empty-state-title">No login history</div>
                    <div class="empty-state-text">No login attempts have been recorded yet.</div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
<table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>IP Address</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loginHistory as $entry): ?>
                        <?php
                            // WHY: check notes for failure keywords to determine badge
                            $notes  = strtolower($entry['notes'] ?? '');
                            $failed = str_contains($notes, 'fail') || str_contains($notes, 'lock') || str_contains($notes, 'invalid');
                        ?>
                        <tr>
                            <td class="font-mono" style="font-size:0.8125rem;white-space:nowrap;">
                                <?= e(format_datetime($entry['created_at'])) ?>
                            </td>
                            <td class="font-mono" style="font-size:0.8125rem;">
                                <?= $entry['ip_address'] ? e($entry['ip_address']) : '—' ?>
                            </td>
                            <td>
                                <?php if ($failed): ?>
                                    <span class="badge badge-danger">Failed</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Success</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
</div>
                <?php endif; ?>
            </div>
        </div>
    </template>

</div><!-- /left column -->

<!-- ══════════════════════════════════════════════════════════════
     RIGHT COLUMN — Change Password + Account Info
     ══════════════════════════════════════════════════════════════ -->
<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- ── Card 1: Change Password ──────────────────────────────── -->
    <div class="card" x-data="changePassword()">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Change Password</div>
        <div class="card-body" style="padding:16px;">

            <!-- Success message -->
            <template x-if="successMsg">
                <div class="toast toast-success"
                     style="position:relative;margin-bottom:12px;animation:none;">
                    <div class="toast-body">
                        <div class="toast-message" x-text="successMsg"></div>
                    </div>
                </div>
            </template>

            <!-- Error message -->
            <template x-if="errorMsg">
                <div class="toast toast-danger"
                     style="position:relative;margin-bottom:12px;animation:none;">
                    <div class="toast-body">
                        <div class="toast-message" x-text="errorMsg"></div>
                    </div>
                </div>
            </template>

            <form @submit.prevent="submit()">
                <div class="form-group">
                    <label class="form-label">Current Password <span class="required">*</span></label>
                    <input type="password" class="form-control" x-model="form.current_password"
                           autocomplete="current-password">
                </div>

                <div class="form-group">
                    <label class="form-label">New Password <span class="required">*</span></label>
                    <input type="password" class="form-control" x-model="form.new_password"
                           autocomplete="new-password" placeholder="Min. 10 characters">
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm Password <span class="required">*</span></label>
                    <input type="password" class="form-control" x-model="form.confirm_password"
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-full"
                        :disabled="saving">
                    <span x-show="!saving">Change Password</span>
                    <span x-show="saving">Saving…</span>
                </button>
            </form>
        </div>
    </div>

    <!-- ── Card: Two-Factor Authentication (MFA) ───────────────────── -->
    <div class="card" x-data="mfaCard()">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Two-Factor Authentication</div>
        <div class="card-body" style="padding:16px;">

            <?php if ($mfaEnabled): ?>
            <!-- MFA is ON -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success,#10b981)" style="width:18px;height:18px;flex-shrink:0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
                </svg>
                <span style="font-size:0.8125rem;color:var(--color-success,#10b981);font-weight:600;">Enabled</span>
            </div>
            <div style="font-size:0.75rem;color:var(--text-tertiary);margin-bottom:12px;">
                <?php if ($me['mfa_enabled_at']): ?>
                Enabled <?= e(format_datetime($me['mfa_enabled_at'])) ?>.<br>
                <?php endif; ?>
                Backup codes remaining: <strong><?= $unusedBackupCount ?></strong> of <?= (int) settings_get('security.mfa.backup_code_count', 10) ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:8px;">
                <button class="btn btn-secondary btn-sm w-full"
                        @click="regenerateCodes()"
                        :disabled="loading">
                    <span x-show="!loading">Regenerate Backup Codes</span>
                    <span x-show="loading">Loading…</span>
                </button>
                <?php if (!$mfaRequired): ?>
                <button class="btn btn-danger btn-sm w-full"
                        @click="disableMfa()"
                        :disabled="loading">
                    Disable MFA
                </button>
                <?php else: ?>
                <button class="btn btn-secondary btn-sm w-full" disabled
                        title="Your role requires MFA — contact a super_admin to disable it">
                    Disable MFA (role requires)
                </button>
                <?php endif; ?>
            </div>

            <!-- Backup codes display (after regeneration) -->
            <div x-show="backupCodes.length > 0" style="margin-top:16px;padding:12px;background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:var(--radius-md);">
                <div style="font-size:0.75rem;font-weight:600;color:var(--color-warning,#f59e0b);margin-bottom:8px;">Save these codes — shown once only</div>
                <template x-for="(code, i) in backupCodes" :key="i">
                    <div style="font-family:monospace;font-size:0.875rem;padding:2px 0;" x-text="(i+1) + '. ' + code"></div>
                </template>
                <button class="btn btn-secondary btn-sm" style="margin-top:8px;"
                        @click="copyCodes()">
                    <span x-show="!copied">Copy all</span>
                    <span x-show="copied">Copied!</span>
                </button>
            </div>

            <?php else: ?>
            <!-- MFA is OFF -->
            <?php if ($mfaRequired): ?>
            <div class="toast toast-warning" style="position:relative;animation:none;margin-bottom:12px;">
                <div class="toast-body">
                    <div class="toast-message" style="font-size:0.8125rem;">
                        <strong>Your role requires MFA.</strong> Set it up to ensure continued access.
                    </div>
                </div>
            </div>
            <?php else: ?>
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin-bottom:12px;">
                Not enabled. Adding a second factor significantly protects your account.
            </p>
            <?php endif; ?>
            <a href="<?= e(base_url('account/mfa_setup')) ?>" class="btn btn-primary btn-sm w-full">
                Set Up Two-Factor Authentication
            </a>
            <?php endif; ?>

            <!-- Error/success feedback -->
            <div x-show="feedbackMsg" x-text="feedbackMsg"
                 :class="feedbackOk ? 'toast toast-success' : 'toast toast-danger'"
                 style="position:relative;animation:none;margin-top:10px;"></div>

        </div>
    </div>

    <!-- ── Card 2: Account Info (read-only) ─────────────────────── -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Account Info</div>
        <div class="card-body" style="padding:16px;">
            <dl style="margin:0;display:grid;gap:10px;">
                <div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;">Role</div>
                    <span class="badge badge-neutral badge-pill"><?= e($me['role_name']) ?></span>
                </div>

                <div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;">Status</div>
                    <span class="badge <?= e($statusBadgeClass) ?>">
                        <?= e(ucfirst($me['status'])) ?>
                    </span>
                </div>

                <div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;">Member Since</div>
                    <div style="font-size:0.875rem;"><?= e(format_datetime($me['created_at'])) ?></div>
                </div>

                <div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;">Last Login</div>
                    <?php if ($me['last_login_at']): ?>
                    <div style="font-size:0.875rem;"><?= e(format_datetime($me['last_login_at'])) ?></div>
                    <?php if ($me['last_login_ip']): ?>
                    <div class="font-mono text-muted" style="font-size:0.75rem;"><?= e($me['last_login_ip']) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div style="font-size:0.875rem;color:var(--text-secondary);">—</div>
                    <?php endif; ?>
                </div>
            </dl>
        </div>
    </div>

</div><!-- /right column -->

</div><!-- /grid -->

<style>
@media (max-width: 768px) {
    .profile-layout { grid-template-columns: 1fr !important; }
}

/* PERM-1 — display density radio cards on profile page */
.display-density-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
}
.display-density-option:hover {
    border-color: var(--color-primary);
    background: var(--bg-surface-hover);
}
.display-density-option input[type="radio"] {
    margin-top: 2px;
    flex-shrink: 0;
}
.display-density-label {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.875rem;
}
.display-density-label .text-muted {
    font-size: 0.75rem;
}
</style>

<script>
// ── mfaCard Alpine component (S-PROD-1A) ──────────────────────────────────────
function mfaCard() {
    return {
        loading:     false,
        backupCodes: [],
        copied:      false,
        feedbackMsg: '',
        feedbackOk:  true,

        init() {},

        async regenerateCodes() {
            if (!confirm('This will invalidate your current backup codes. Continue?')) return;
            this.loading     = true;
            this.backupCodes = [];
            this.feedbackMsg = '';
            try {
                const r = await fetch(window.FF_BASE_PATH + '/api/v1/account/mfa/regenerate_codes.php', {
                    method:  'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });
                const d = await r.json();
                if (d.success && d.data?.backup_codes) {
                    this.backupCodes = d.data.backup_codes;
                    this.feedbackOk  = true;
                    this.feedbackMsg = 'New backup codes generated. Save them now.';
                } else {
                    this.feedbackOk  = false;
                    this.feedbackMsg = d.error?.message ?? 'Failed to regenerate codes.';
                }
            } catch (e) {
                this.feedbackOk  = false;
                this.feedbackMsg = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async disableMfa() {
            if (!confirm('Disable two-factor authentication? Your account will be less secure.')) return;
            this.loading     = true;
            this.feedbackMsg = '';
            try {
                const r = await fetch(window.FF_BASE_PATH + '/api/v1/account/mfa/disable.php', {
                    method:  'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });
                const d = await r.json();
                if (d.success) {
                    window.location.reload();
                } else {
                    this.feedbackOk  = false;
                    this.feedbackMsg = d.error?.message ?? 'Failed to disable MFA.';
                }
            } catch (e) {
                this.feedbackOk  = false;
                this.feedbackMsg = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async copyCodes() {
            const text = this.backupCodes.map((c, i) => (i+1) + '. ' + c).join('\n');
            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => this.copied = false, 3000);
            } catch (e) {
                alert('Copy failed — please select and copy the codes manually.');
            }
        },
    };
}

// ── profilePage Alpine component ──────────────────────────────────────────────
/**
 * FF_MyNotifications — the profile's Notifications tab (S-ATTENTION-INBOX):
 * which update categories this person receives. Saves the opted-OUT list.
 */
function FF_MyNotifications(init) {
    return {
        categories: init.categories,
        optedOut: init.opted_out,
        saving: false,
        status: '',
        error: '',
        // S-ATTENTION-WHATSAPP
        wa: init.wa,
        hours: Array.from({ length: 24 }, (_, i) => i),
        waSaving: false,
        waStatus: '',
        waError: '',

        hourLabel(h) {
            const ampm = h < 12 ? 'am' : 'pm';
            const hh = h % 12 === 0 ? 12 : h % 12;
            return hh + ':00 ' + ampm;
        },

        toggleUpdate(type, on) {
            this.wa.updates = on ? [...new Set([...this.wa.updates, type])] : this.wa.updates.filter(t => t !== type);
        },

        async saveWhatsApp() {
            if (this.waSaving) return;
            this.waSaving = true;
            this.waError = '';
            this.waStatus = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/account/whatsapp.php'), {
                    phone: this.wa.phone, mode: this.wa.mode, summary_hour: this.wa.summary_hour,
                    quiet_start: this.wa.quiet_start, quiet_end: this.wa.quiet_end, updates: this.wa.updates,
                }, { quiet: true });   // errors show inline
                if (res?.success) {
                    this.wa.phone = res.data.phone || '';
                    this.waStatus = res.data.mode === 'off' ? 'Saved. WhatsApp is off for you.' : 'Saved.';
                } else {
                    this.waError = res?.error?.message || 'Could not save.';
                }
            } catch {
                this.waError = 'Network error. Try again.';
            } finally {
                this.waSaving = false;
            }
        },

        async summaryNow() {
            this.waError = '';
            this.waStatus = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/account/whatsapp.php'), { action: 'summary_now' }, { quiet: true });
                if (res?.success) this.waStatus = 'Queued. It arrives within a minute (unless it\u2019s your quiet time).';
                else this.waError = res?.error?.message || 'Could not queue it.';
            } catch {
                this.waError = 'Network error. Try again.';
            }
        },

        toggle(slug, on) {
            this.optedOut = on ? this.optedOut.filter(s => s !== slug) : [...new Set([...this.optedOut, slug])];
            this.status = '';
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/account/notification_preferences.php'), { opted_out: this.optedOut }, { quiet: true });
                if (res?.success) {
                    this.optedOut = res.data.opted_out || [];
                    this.status = 'Saved.';
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

function profilePage() {
    return {
        tab:       'profile',
        editMode:  false,
        saving:    false,
        editError: null,
        form: {
            name:     <?= json_encode($me['name']) ?>,
            email:    <?= json_encode($me['email']) ?>,
            phone:    <?= json_encode($me['phone'] ?? '') ?>,
            timezone: <?= json_encode($me['timezone'] ?? '') ?>,
        },

        showEdit() {
            this.editMode  = true;
            this.editError = null;
        },

        cancelEdit() {
            this.editMode  = false;
            this.editError = null;
            // WHY: reset form to original server values on cancel
            this.form = {
                name:     <?= json_encode($me['name']) ?>,
                email:    <?= json_encode($me['email']) ?>,
                phone:    <?= json_encode($me['phone'] ?? '') ?>,
                timezone: <?= json_encode($me['timezone'] ?? '') ?>,
            };
        },

        init() {
            const _tabs = ['profile','display','notifications','login_history'];
            const _initTab = FF_TabHash.init(_tabs, 'profile');
            this.tab = _initTab;
            FF_TabHash.write(_initTab);
            FF_TabHash.watchUnload(() => this.tab);
            let _prevTab = _initTab;
            this.$watch('tab', (tab) => {
                FF_TabHash.onSwitch(_prevTab, tab);
                _prevTab = tab;
            });
        },

        async saveProfile() {
            this.editError = null;

            if (!this.form.name.trim()) {
                this.editError = 'Name is required.';
                return;
            }
            if (!this.form.email.trim()) {
                this.editError = 'Email is required.';
                return;
            }

            const payload = {
                id:         <?= (int)$me['id'] ?>,
                updated_at: this.$refs.updatedAt.value,  // D19 optimistic lock
                name:       this.form.name.trim(),
                email:      this.form.email.trim(),
                phone:      this.form.phone.trim() || null,
                timezone:   this.form.timezone.trim() || null,
                // WHY: send current role_id unchanged so update endpoint doesn't complain
                role_id:    <?= (int)$me['role_id'] ?>,
            };

            this.saving = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/users/update.php') ?>', payload);
                window.location.reload();
            } catch (err) {
                this.editError = err?.data?.message ?? 'Save failed. Please try again.';
                this.saving = false;
            }
        },
    };
}

// ── displaySettingsTab Alpine component (PERM-1 Feature 2) ───────────────────
// Seeded from the server-rendered user values; every change calls
// FF_Display (from app.js) which writes to the DB AND updates the DOM
// (including the topbar quick controls via window.FF_DISPLAY).
function displaySettingsTab() {
    return {
        fontSize:  <?= (int) ($me['display_font_size'] ?? 100) ?>,
        density:   <?= json_encode($me['display_density'] ?? 'comfortable') ?>,
        saving:    false,
        saveError: null,

        init() {
            // Keep local state in sync if topbar changes it while tab is open
            this._onChange = () => {
                if (window.FF_DISPLAY) {
                    this.fontSize = window.FF_DISPLAY.font_size;
                    this.density  = window.FF_DISPLAY.density;
                }
            };
        },

        async applyFont() {
            this.saving = true;
            this.saveError = null;
            const res = await FF_Display.setFontSize(this.fontSize);
            if (res && res.success === false) this.saveError = 'Could not save font size.';
            this.saving = false;
        },

        async applyDensity() {
            this.saving = true;
            this.saveError = null;
            const res = await FF_Display.setDensity(this.density);
            if (res && res.success === false) this.saveError = 'Could not save density.';
            this.saving = false;
        },

        async incFont() {
            if (this.fontSize >= 130) return;
            this.fontSize = Math.min(130, this.fontSize + 5);
            await this.applyFont();
        },

        async decFont() {
            if (this.fontSize <= 70) return;
            this.fontSize = Math.max(70, this.fontSize - 5);
            await this.applyFont();
        },

        async reset() {
            this.saving = true;
            this.saveError = null;
            this.fontSize = 100;
            this.density  = 'comfortable';
            const res = await FF_Display.setBoth(100, 'comfortable');
            if (res && res.success === false) this.saveError = 'Could not reset display settings.';
            this.saving = false;
        },
    };
}

// ── changePassword Alpine component ──────────────────────────────────────────
function changePassword() {
    return {
        saving:     false,
        successMsg: null,
        errorMsg:   null,
        form: {
            current_password:  '',
            new_password:      '',
            confirm_password:  '',
        },

        async submit() {
            this.successMsg = null;
            this.errorMsg   = null;

            // WHY: client-side pre-validation for immediate feedback
            if (!this.form.current_password) {
                this.errorMsg = 'Current password is required.';
                return;
            }
            if (this.form.new_password.length < 10) {
                this.errorMsg = 'New password must be at least 10 characters.';
                return;
            }
            if (this.form.new_password !== this.form.confirm_password) {
                this.errorMsg = 'Passwords do not match.';
                return;
            }

            this.saving = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/users/change_password.php') ?>', {
                    current_password: this.form.current_password,
                    new_password:     this.form.new_password,
                    confirm_password: this.form.confirm_password,
                });
                this.successMsg = res?.data?.message ?? 'Password changed successfully.';
                this.form = { current_password: '', new_password: '', confirm_password: '' };
            } catch (err) {
                this.errorMsg = err?.data?.message ?? 'Failed to change password.';
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
