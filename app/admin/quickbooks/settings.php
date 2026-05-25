<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/settings.php
 *
 * Settings → QuickBooks. Three cards:
 *   1. Connection Status — environment badge + connection badge +
 *      timestamps + Connect / Disconnect / Test Connection buttons.
 *   1.5 Company Detection — read-only display of auto-detected QBO
 *      company settings (multi_currency_enabled, home_currency,
 *      company_country). Populated by CompanyInfoSync at OAuth connect
 *      and token refresh time (D-QBO-FIXPACK-11).
 *   2. API Credentials — environment + client_id + client_secret +
 *      webhook_verifier_token + sandbox_redirect_uri. Sensitive values
 *      always rendered masked to last 4 chars; full values never
 *      reach the HTML.
 *   3. Master Controls — sync_enabled + dry_run_mode + payments_enabled
 *      kill-switches. super_admin only.
 *
 * Permission gate:
 *   - require_permission('quickbooks', 'view') — page-level access
 *   - can('quickbooks', 'edit_credentials') — Card 2 + Disconnect /
 *     Connect actions
 *   - is_super_admin()                       — Card 3 visibility
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1, §5.2, §5.5
 * Session:  S-QBO-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$canEditCredentials = can('quickbooks', 'edit_credentials');
$canDisconnect      = can('quickbooks', 'disconnect');
$isSuperAdmin       = is_super_admin();

// ── Load all quickbooks.* settings into $qbo flat map ──────────
// WHY: Single round-trip then array lookup beats 18 separate
// settings_get() calls. Strip 'quickbooks.' prefix for tidier
// key access in this file and the Alpine init blob.
$qbo = [];
foreach (db_select("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'quickbooks.%'", []) as $row) {
    $qbo[substr($row['key'], 11)] = $row['value'];
}

/**
 * ff_qbo_mask — render a sensitive value as "••••••••XXXX" where XXXX
 * is the last 4 chars, or "Not configured" if empty/null. Used for
 * client_id / client_secret / webhook_verifier_token display.
 *
 * Never returns the full value. The function is local to this file
 * by intent — generalising it to includes/functions.php is deferred
 * until a second caller actually needs it (avoid premature reuse).
 *
 * @param  string|null $value
 * @return string
 */
function ff_qbo_mask(?string $value): string
{
    $value = (string) ($value ?? '');
    if ($value === '') {
        return 'Not configured';
    }
    if (strlen($value) <= 4) {
        // Short values get fully masked — last 4 = whole string would
        // leak the entire credential, defeating the purpose.
        return str_repeat('•', 8);
    }
    return str_repeat('•', 8) . substr($value, -4);
}

/**
 * ff_qbo_format_ts — format an ISO timestamp string for display,
 * or return "—" if empty/null.
 *
 * @param  string|null $value
 * @return string
 */
function ff_qbo_format_ts(?string $value): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return e($value);
    }
    return date('Y-m-d H:i:s', $ts);
}

// ── Refresh-token expiry countdown (UI banner) ─────────────────
// WHY: Spec §5.3 — the pinger cron alerts at T-14d, but the UI
// also surfaces the warning so an operator who lands on this page
// at any time sees it immediately.
$refreshExpiresAt = $qbo['refresh_token_expires_at'] ?? '';
$refreshExpiresInDays = null;
if (!empty($refreshExpiresAt)) {
    $expTs = strtotime($refreshExpiresAt);
    if ($expTs !== false) {
        $refreshExpiresInDays = (int) floor(($expTs - time()) / 86400);
    }
}

$connectionStatus = $qbo['connection_status'] ?? 'disconnected';
$environment      = $qbo['environment'] ?? 'sandbox';

$pageTitle = 'QuickBooks Settings';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Settings</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks Settings</h1>
</div>

<div x-data="qboSettings()" x-init="init()" style="max-width:880px;">

    <!-- ── Flash message (top of page) ───────────────────────── -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:16px;"
         x-text="flash.message"></div>

    <!-- ============================================================
         CARD 1 — Connection Status
         ============================================================ -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 12px;">Connection Status</h3>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <?php
            $statusBadgeClass = match ($connectionStatus) {
                'connected'    => 'badge badge-success',
                'expired'      => 'badge badge-danger',
                'error'        => 'badge badge-danger',
                default        => 'badge badge-neutral',
            };
            ?>
            <span class="<?= $statusBadgeClass ?>" style="text-transform:capitalize;"><?= e($connectionStatus) ?></span>
            <span class="badge badge-info" style="text-transform:capitalize;"><?= e($environment) ?></span>
        </div>

        <?php if ($connectionStatus === 'connected'): ?>
            <dl class="dl-grid" style="margin-bottom:14px;">
                <dt>Realm ID</dt>
                <dd><code><?= e($qbo['realm_id'] ?? '') ?></code></dd>

                <dt>Last connected</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['last_connected_at'] ?? null)) ?></dd>

                <dt>Last token refresh</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['last_token_refresh_at'] ?? null)) ?></dd>

                <dt>Access token expires</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['access_token_expires_at'] ?? null)) ?></dd>

                <dt>Refresh token expires</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['refresh_token_expires_at'] ?? null)) ?></dd>
            </dl>
        <?php endif; ?>

        <?php if ($connectionStatus === 'error' && !empty($qbo['connection_error'])): ?>
            <div class="alert alert-danger" style="margin-bottom:14px;">
                <strong>Connection error:</strong> <?= e($qbo['connection_error']) ?>
            </div>
        <?php endif; ?>

        <?php if ($connectionStatus === 'expired'): ?>
            <div class="alert alert-danger" style="margin-bottom:14px;">
                <strong>Refresh token expired.</strong> Re-authorization required — click Connect to QuickBooks below.
            </div>
        <?php endif; ?>

        <?php if ($connectionStatus === 'connected' && $refreshExpiresInDays !== null && $refreshExpiresInDays <= 14): ?>
            <div class="alert alert-warning" style="margin-bottom:14px;">
                <strong>Refresh token expires in <?= e((string) max($refreshExpiresInDays, 0)) ?> day(s).</strong>
                Consider re-authorizing soon — refresh tokens that lapse force a full re-connect.
            </div>
        <?php endif; ?>

        <!-- ── Action buttons ─────────────────────────────────── -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (in_array($connectionStatus, ['disconnected', 'expired', 'error'], true)): ?>
                <a href="<?= base_url('oauth/qbo/init.php') ?>"
                   class="btn btn-primary btn-sm <?= $canEditCredentials ? '' : 'is-disabled' ?>"
                   <?= $canEditCredentials ? '' : 'aria-disabled="true" onclick="event.preventDefault()"' ?>>
                    Connect to QuickBooks
                </a>
            <?php endif; ?>

            <?php if ($connectionStatus === 'connected'): ?>
                <button class="btn btn-secondary btn-sm" @click="testConnection()" :disabled="testing">
                    <span x-show="!testing">Test Connection</span>
                    <span x-show="testing" x-cloak>Testing…</span>
                </button>
                <button class="btn btn-danger btn-sm" @click="disconnect()" :disabled="disconnecting || !<?= $canDisconnect ? 'true' : 'false' ?>">
                    <span x-show="!disconnecting">Disconnect</span>
                    <span x-show="disconnecting" x-cloak>Disconnecting…</span>
                </button>
            <?php endif; ?>
        </div>

        <!-- ── Test connection result panel ───────────────────── -->
        <div x-show="testResult" x-cloak style="margin-top:14px;">
            <div :class="testResult && testResult.success ? 'alert alert-success' : 'alert alert-danger'">
                <template x-if="testResult && testResult.success">
                    <div>
                        <strong>Connected.</strong> Company: <span x-text="testResult.company_name"></span>
                        — Realm ID <code x-text="testResult.realm_id"></code>
                    </div>
                </template>
                <template x-if="testResult && !testResult.success">
                    <div>
                        <strong>Test failed:</strong> <span x-text="testResult.error"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CARD 1.5 — Company Detection (D-QBO-FIXPACK-11)
         Read-only display of QBO company settings auto-detected at
         OAuth connect time and at each token refresh. These values
         gate CurrencyRef emission across all Pushers (D-QBO-FIXPACK-12).
         ============================================================ -->
    <?php
    $multiCurrEnabled = ($qbo['multi_currency_enabled'] ?? '0') === '1';
    $homeCurrency     = $qbo['home_currency']    ?? '';
    $companyCountry   = $qbo['company_country']  ?? '';
    // Find when multi_currency_enabled was last updated (proxy for "last detected")
    $lastDetectedRow  = db_row(
        "SELECT updated_at FROM settings WHERE `key` = 'quickbooks.multi_currency_enabled'",
        []
    );
    $lastDetected = $lastDetectedRow ? ff_qbo_format_ts($lastDetectedRow['updated_at']) : '—';
    ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Company Detection</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Auto-detected from QuickBooks on connect and token refresh — not editable here.
            These settings gate <code>CurrencyRef</code> emission in all Pushers.
            Re-connect QBO or wait for the next token rotation to refresh.
        </p>
        <dl class="dl-grid">
            <dt>Multi-currency enabled</dt>
            <dd>
                <?php if ($multiCurrEnabled): ?>
                    <span class="badge badge-success">Yes</span>
                    <span class="text-secondary text-sm" style="margin-left:6px;">CurrencyRef will be emitted on entity pushes</span>
                <?php else: ?>
                    <span class="badge badge-neutral">No</span>
                    <span class="text-secondary text-sm" style="margin-left:6px;">CurrencyRef omitted (QBO error 6000 prevention)</span>
                <?php endif; ?>
            </dd>

            <dt>Home currency</dt>
            <dd><?= $homeCurrency !== '' ? e($homeCurrency) : '<span class="text-secondary">Not detected yet</span>' ?></dd>

            <dt>Company country</dt>
            <dd><?= $companyCountry !== '' ? e($companyCountry) : '<span class="text-secondary">Not detected yet</span>' ?></dd>

            <dt>Last detected</dt>
            <dd><?= e($lastDetected) ?></dd>
        </dl>
    </div>

    <!-- ============================================================
         CARD 2 — API Credentials
         ============================================================ -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">API Credentials</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Intuit Developer credentials. Sensitive values are masked — type a new value to replace; leave blank to keep the current value.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
                <label class="form-label">Environment</label>
                <select class="form-select" x-model="creds.environment" <?= $canEditCredentials ? '' : 'disabled' ?>>
                    <option value="sandbox">Sandbox</option>
                    <option value="production">Production</option>
                </select>
            </div>
            <div>
                <label class="form-label">Sandbox Redirect URI</label>
                <input type="text" class="form-input" x-model="creds.sandbox_redirect_uri"
                       placeholder="https://your-ngrok-tunnel.ngrok.io/fleetforge/oauth/qbo/callback.php"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
                <p class="text-secondary text-sm" style="margin:2px 0 0;">Your ngrok callback URL for local dev.</p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
            <div>
                <label class="form-label">Client ID</label>
                <input type="text" class="form-input" x-model="creds.client_id"
                       :placeholder="placeholders.client_id"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
            </div>
            <div>
                <label class="form-label">Client Secret</label>
                <input type="text" class="form-input" x-model="creds.client_secret"
                       :placeholder="placeholders.client_secret"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="form-label">Webhook Verifier Token</label>
            <input type="text" class="form-input" x-model="creds.webhook_verifier_token"
                   :placeholder="placeholders.webhook_verifier_token"
                   <?= $canEditCredentials ? '' : 'disabled' ?>>
            <p class="text-secondary text-sm" style="margin:2px 0 0;">From Intuit webhook config — used to verify inbound payment webhooks (HMAC-SHA256).</p>
        </div>

        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="saveCredentials()"
                    :disabled="savingCreds || !<?= $canEditCredentials ? 'true' : 'false' ?>">
                <span x-show="!savingCreds">Save Credentials</span>
                <span x-show="savingCreds" x-cloak>Saving…</span>
            </button>
            <?php if (!$canEditCredentials): ?>
                <span class="text-secondary text-sm" style="margin-left:10px;">View-only — edit_credentials permission required.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         CARD 3 — Master Controls (super_admin only)
         ============================================================ -->
    <?php if ($isSuperAdmin): ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Master Controls</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Kill-switches that govern QBO behaviour globally. Visible to super_admin only.
        </p>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.sync_enabled" style="margin-top:3px;">
                <span>
                    <strong>Master Sync Kill-Switch</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Keep OFF until production cutover (S-QBO-30). While OFF, no FF-side change pushes to QBO and no QBO-originated event is processed.</p>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.dry_run_mode" style="margin-top:3px;">
                <span>
                    <strong>Dry Run Mode</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Pushes logged but not sent to QBO. Used during production cutover validation.</p>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.payments_enabled" style="margin-top:3px;">
                <span>
                    <strong>QBO Payments</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Enables the "Pay Online" button in the customer portal once QBO Payments hosted page is configured (S-QBO-15).</p>
                </span>
            </label>
        </div>

        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="saveMasters()" :disabled="savingMasters">
                <span x-show="!savingMasters">Save Master Controls</span>
                <span x-show="savingMasters" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function qboSettings() {
    return {
        // ── State ─────────────────────────────────────────────
        flash:           { message: '', type: 'success' },
        testing:         false,
        disconnecting:   false,
        savingCreds:     false,
        savingMasters:   false,
        testResult:      null,

        // The form initial values: environment + sandbox_redirect_uri
        // are NON-sensitive so they're seeded from the server-rendered
        // values directly. The sensitive fields start blank — the
        // operator types a new value to replace, blank=keep-existing.
        creds: {
            environment:            '<?= e($qbo['environment'] ?? 'sandbox') ?>',
            client_id:              '',
            client_secret:          '',
            webhook_verifier_token: '',
            sandbox_redirect_uri:   '<?= e($qbo['sandbox_redirect_uri'] ?? '') ?>',
        },

        // Sensitive-field placeholders show the masked existing value
        // so operators see "we already have something stored" without
        // ever sending the full token to the browser.
        placeholders: {
            client_id:              '<?= e(ff_qbo_mask($qbo['client_id'] ?? null)) ?>',
            client_secret:          '<?= e(ff_qbo_mask($qbo['client_secret'] ?? null)) ?>',
            webhook_verifier_token: '<?= e(ff_qbo_mask($qbo['webhook_verifier_token'] ?? null)) ?>',
        },

        masters: {
            sync_enabled:     <?= ($qbo['sync_enabled'] ?? '0') === '1' ? 'true' : 'false' ?>,
            dry_run_mode:     <?= ($qbo['dry_run_mode'] ?? '0') === '1' ? 'true' : 'false' ?>,
            payments_enabled: <?= ($qbo['payments_enabled'] ?? '0') === '1' ? 'true' : 'false' ?>,
        },

        // ── Lifecycle ─────────────────────────────────────────
        init() {
            // Surface query-string flash messages (e.g. from OAuth
            // callback redirect). Cleared after read.
            const params = new URLSearchParams(window.location.search);
            if (params.has('flash_success')) {
                this.flash = { message: params.get('flash_success'), type: 'success' };
            } else if (params.has('flash_error')) {
                this.flash = { message: params.get('flash_error'), type: 'error' };
            }
        },

        // ── Actions ───────────────────────────────────────────
        async testConnection() {
            this.testing    = true;
            this.testResult = null;
            try {
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/test_connection.php'), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const data = await r.json();
                this.testResult = data;
            } catch (e) {
                this.testResult = { success: false, error: e.message || 'Network error' };
            } finally {
                this.testing = false;
            }
        },

        async disconnect() {
            if (!confirm('Disconnect from QuickBooks? Pending sync queue items will remain queued but will not process until re-connected.')) {
                return;
            }
            this.disconnecting = true;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/disconnect.php'), {});
                if (r.success) {
                    this.flash = { message: 'Disconnected from QuickBooks.', type: 'success' };
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Disconnect failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.disconnecting = false;
            }
        },

        async saveCredentials() {
            this.savingCreds = true;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_credentials.php'), this.creds);
                if (r.success) {
                    this.flash = { message: 'Credentials saved.', type: 'success' };
                    // Blank the typed-in sensitive fields so they re-mask
                    this.creds.client_id              = '';
                    this.creds.client_secret          = '';
                    this.creds.webhook_verifier_token = '';
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Save failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingCreds = false;
            }
        },

        async saveMasters() {
            this.savingMasters = true;
            try {
                const payload = {
                    sync_enabled:     this.masters.sync_enabled     ? '1' : '0',
                    dry_run_mode:     this.masters.dry_run_mode     ? '1' : '0',
                    payments_enabled: this.masters.payments_enabled ? '1' : '0',
                };
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_master_controls.php'), payload);
                if (r.success) {
                    this.flash = { message: 'Master controls saved.', type: 'success' };
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Save failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingMasters = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
